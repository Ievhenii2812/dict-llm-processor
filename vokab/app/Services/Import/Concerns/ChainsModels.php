<?php

namespace App\Services\Import\Concerns;

use App\Services\Import\AIModelUnavailableException;
use App\Services\Import\AIRateLimitException;
use Illuminate\Support\Facades\Log;

/**
 * Ordered model fallback for providers whose quotas are per model
 * (Gemini AI Studio, Groq).
 *
 * A tier (flash / pro) is configured as a comma-separated list in .env, e.g.
 *   GEMINI_FLASH_MODEL=gemini-3.8-flash,gemini-3.7-flash,gemini-3.6-flash
 * Models are tried in that order for every request:
 *
 *  - AIRateLimitException (quota used up)   -> model is dropped for the rest of
 *    the process, the next model takes over;
 *  - AIModelUnavailableException, transient  -> model is parked for a short
 *    cooldown (overload, per-minute limit);
 *  - AIModelUnavailableException, permanent  -> model is dropped for the rest of
 *    the process (404/403, decommissioned);
 *  - null result (bad JSON, other HTTP error) is returned as is, so the callers'
 *    own retry/split logic keeps working.
 *
 * When no model is usable the trait waits out short cooldowns for a few rounds
 * and then throws AIRateLimitException, which the commands already treat as
 * "stop cleanly, re-run later".
 *
 * State lives in the service instance only, so every artisan run starts fresh:
 * a model that ran out of quota yesterday costs one rejected request today.
 */
trait ChainsModels
{
    /** @var array<string, string> model => why it was dropped for this process */
    private array $droppedModels = [];

    /** @var array<string, int> model => unix time until which it is parked */
    private array $parkedUntil = [];

    private ?string $activeModel = null;

    private const int CHAIN_ROUNDS       = 4;
    private const int CHAIN_MAX_SLEEP_S  = 30;

    /**
     * "a, b ,c" => ['a', 'b', 'c']; an empty value falls back to $default.
     *
     * @return string[]
     */
    protected static function parseModelList(string $value, string $default): array
    {
        $models = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            fn (string $m) => $m !== ''
        )));

        return $models !== [] ? $models : [$default];
    }

    /**
     * @param string[]                $models  in priority order
     * @param callable(string): ?array $attempt runs one request on the given model;
     *                                          may throw AIRateLimitException or
     *                                          AIModelUnavailableException
     *
     * @throws AIRateLimitException when no model in the chain can serve the request now
     */
    protected function askWithFallback(array $models, callable $attempt): ?array
    {
        for ($round = 1; $round <= self::CHAIN_ROUNDS; $round++) {
            $waitable = false;

            foreach ($models as $model) {
                if (isset($this->droppedModels[$model])) {
                    continue;
                }

                if (($this->parkedUntil[$model] ?? 0) > $this->aiNow()) {
                    $waitable = true;
                    continue;
                }

                try {
                    $result = $attempt($model);
                } catch (AIRateLimitException $e) {
                    $this->droppedModels[$model] = 'quota exhausted';
                    $this->aiLog('warning', 'AI: quota exhausted, falling back to next model', [
                        'model' => $model, 'detail' => $e->getMessage(),
                    ]);
                    continue;
                } catch (AIModelUnavailableException $e) {
                    if ($e->permanent) {
                        $this->droppedModels[$model] = $e->getMessage();
                        $this->aiLog('error', 'AI: model unusable, skipping it for this run', [
                            'model' => $model, 'detail' => $e->getMessage(),
                        ]);
                        continue;
                    }

                    $this->parkedUntil[$model] = $this->aiNow() + $e->retryAfterSeconds;
                    $waitable = true;
                    $this->aiLog('warning', 'AI: model unavailable, parking it', [
                        'model' => $model, 'cooldown_s' => $e->retryAfterSeconds, 'detail' => $e->getMessage(),
                    ]);
                    continue;
                }

                $this->noteActiveModel($model);

                return $result;
            }

            if (!$waitable) {
                break;
            }

            if ($round < self::CHAIN_ROUNDS) {
                $this->aiSleep($this->secondsUntilNextModel($models));
            }
        }

        $message = 'No AI model available right now — ' . $this->describeChain($models);
        $this->aiLog('error', 'AI: all models in the chain are exhausted or unavailable', [
            'chain' => $models, 'state' => $message,
        ]);

        throw new AIRateLimitException($message);
    }

    private function noteActiveModel(string $model): void
    {
        if ($this->activeModel === $model) {
            return;
        }

        $this->aiLog('info', 'AI: using model', ['model' => $model, 'previous' => $this->activeModel]);
        $this->activeModel = $model;
    }

    /** Seconds until the earliest parked model becomes usable again (1..CHAIN_MAX_SLEEP_S). */
    private function secondsUntilNextModel(array $models): int
    {
        $now      = $this->aiNow();
        $earliest = null;

        foreach ($models as $model) {
            if (isset($this->droppedModels[$model]) || !isset($this->parkedUntil[$model])) {
                continue;
            }
            $left     = $this->parkedUntil[$model] - $now;
            $earliest = $earliest === null ? $left : min($earliest, $left);
        }

        return max(1, min($earliest ?? 1, self::CHAIN_MAX_SLEEP_S));
    }

    private function describeChain(array $models): string
    {
        $now   = $this->aiNow();
        $parts = [];

        foreach ($models as $model) {
            if (isset($this->droppedModels[$model])) {
                $parts[] = "{$model} ({$this->droppedModels[$model]})";
            } elseif (($this->parkedUntil[$model] ?? 0) > $now) {
                $parts[] = "{$model} (overloaded)";
            } else {
                $parts[] = "{$model} (unavailable)";
            }
        }

        return implode(', ', $parts);
    }

    // Overridable seams (logging, clock, sleep) so the chain can be unit-tested without the framework.

    protected function aiLog(string $level, string $message, array $context = []): void
    {
        Log::channel('import')->{$level}($message, $context);
    }

    protected function aiNow(): int
    {
        return time();
    }

    protected function aiSleep(int $seconds): void
    {
        sleep($seconds);
    }
}
