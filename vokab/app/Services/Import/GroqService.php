<?php

namespace App\Services\Import;

use App\Services\Import\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Groq API client.
 *
 * Free plan limits for openai/gpt-oss-20b and -120b (see console Settings -> Limits):
 * 30 requests/min, 1K requests/day, 8K tokens/min, 200K tokens/day.
 * The per-minute token limit is the practical bottleneck, so 429 responses with a
 * short wait are waited out; long waits (daily limit) abort the run.
 *
 * "Flash" tier -> openai/gpt-oss-20b, "Pro" tier -> openai/gpt-oss-120b.
 * Both are reasoning models: reasoning_effort and max_completion_tokens are set
 * explicitly, otherwise reasoning can use up the whole budget and leave the
 * content empty (json_validate_failed).
 *
 * Config (.env):
 *   GROQ_API_KEY=your-key
 *   GROQ_FLASH_MODEL=openai/gpt-oss-20b
 *   GROQ_PRO_MODEL=openai/gpt-oss-120b
 *   GROQ_REASONING_EFFORT=low
 *   GROQ_MAX_TOKENS=8192
 */
class GroqService implements AIProviderInterface
{
    private string $apiKey;
    private string $flashModel;
    private string $proModel;
    private string $reasoningEffort;
    private int    $maxTokens;

    private const string BASE_URL = 'https://api.groq.com/openai/v1/chat/completions';
    private const int    TIMEOUT     = 120;
    private const int    RETRY_MAX   = 2;
    private const int    RETRY_DELAY = 2_000_000;

    // 429 handling: wait out short per-minute limits, abort on long (daily) ones
    private const int RATE_LIMIT_MAX_WAIT_S = 70;
    private const int RATE_LIMIT_RETRIES    = 3;

    // Free tier ~30 req/min — pace conservatively
    private const int MIN_REQUEST_INTERVAL_US = 2_100_000;

    public function __construct()
    {
        $this->apiKey          = config('import.groq_api_key');
        $this->flashModel      = config('import.groq_flash_model', 'openai/gpt-oss-20b');
        $this->proModel        = config('import.groq_pro_model', 'openai/gpt-oss-120b');
        $this->reasoningEffort = (string) config('import.groq_reasoning_effort', 'low');
        $this->maxTokens       = (int) config('import.groq_max_tokens', 8192);
    }

    public function askFlashJson(string $systemPrompt, string $userPrompt): ?array
    {
        return $this->askJson($this->flashModel, $systemPrompt, $userPrompt);
    }

    public function askProJson(string $systemPrompt, string $userPrompt): ?array
    {
        return $this->askJson($this->proModel, $systemPrompt, $userPrompt);
    }

    private function askJson(string $model, string $systemPrompt, string $userPrompt): ?array
    {
        $attempt = 0;

        while ($attempt < self::RETRY_MAX) {
            $attempt++;
            $this->pace();

            $raw = $this->sendRequest($model, $systemPrompt, $userPrompt);

            if ($raw === null) {
                Log::channel('import')->warning('Groq: no response', [
                    'model' => $model, 'attempt' => $attempt,
                ]);
                if ($attempt < self::RETRY_MAX) usleep(self::RETRY_DELAY);
                continue;
            }

            $parsed = $this->parseJson($raw);
            if ($parsed !== null) return $parsed;

            Log::channel('import')->warning('Groq: invalid JSON, retrying', [
                'model' => $model, 'raw' => mb_substr($raw, 0, 200),
            ]);
        }

        Log::channel('import')->error('Groq: failed after retries', ['model' => $model]);
        return null;
    }

    private function pace(): void
    {
        $lastRequestAt = (float) Cache::get('groq_last_request_at', 0.0);
        $elapsedUs = (microtime(true) - $lastRequestAt) * 1_000_000;

        if ($elapsedUs < self::MIN_REQUEST_INTERVAL_US) {
            usleep((int) (self::MIN_REQUEST_INTERVAL_US - $elapsedUs));
        }
    }

    private function sendRequest(string $model, string $systemPrompt, string $userPrompt, int $rateLimitAttempt = 0): ?string
    {
        $body = [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            'response_format'       => ['type' => 'json_object'],
            'temperature'           => 0.1,
            // gpt-oss otherwise spends the whole budget on reasoning and returns empty content
            'max_completion_tokens' => $this->maxTokens,
        ];

        if ($this->reasoningEffort !== '') {
            $body['reasoning_effort'] = $this->reasoningEffort;
        }

        $payload = json_encode($body);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Length: ' . strlen($payload),
                ]),
                'content'       => $payload,
                'timeout'       => self::TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents(self::BASE_URL, false, $context);
        Cache::put('groq_last_request_at', microtime(true), 600);

        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int) $m[1]; break; }
        }

        if ($status === 429) {
            $wait = $this->rateLimitWaitSeconds($http_response_header ?? [], $result ?: null);

            Log::channel('import')->error('Groq: RATE LIMIT HIT (429)', [
                'model'  => $model,
                'wait_s' => $wait,
                'body'   => $result ? mb_substr($result, 0, 300) : null,
            ]);

            if ($wait !== null
                && $wait <= self::RATE_LIMIT_MAX_WAIT_S
                && $rateLimitAttempt < self::RATE_LIMIT_RETRIES
            ) {
                sleep((int) ceil($wait) + 1);
                return $this->sendRequest($model, $systemPrompt, $userPrompt, $rateLimitAttempt + 1);
            }

            throw new AIRateLimitException('Groq rate limit (429) reached');
        }

        if ($result === false || $status !== 200) {
            Log::channel('import')->error('Groq: HTTP request failed', [
                'status' => $status, 'body' => $result ? mb_substr($result, 0, 300) : null,
            ]);
            return null;
        }

        $data = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) return null;

        return $data['choices'][0]['message']['content'] ?? null;
    }

    /**
     * Seconds to wait before retrying, from the Retry-After header or the
     * "try again in 7.66s / 1m23s / 450ms" text in the error body.
     * Returns null if neither is present (caller then aborts as before).
     */
    private function rateLimitWaitSeconds(array $headers, ?string $body): ?float
    {
        foreach ($headers as $h) {
            if (preg_match('/^retry-after:\s*(\d+(?:\.\d+)?)/i', $h, $m)) {
                return (float) $m[1];
            }
        }

        if (
            $body !== null
            && preg_match('/try again in\s+(?:(\d+)h)?(?:(\d+)m(?!s))?(?:(\d+(?:\.\d+)?)(ms|s))?/i', $body, $m)
        ) {
            $seconds = (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60;
            if (($m[3] ?? '') !== '') {
                $seconds += strtolower($m[4]) === 'ms' ? (float) $m[3] / 1000 : (float) $m[3];
            }
            return $seconds > 0 ? $seconds : null;
        }

        return null;
    }

    private function parseJson(string $raw): ?array
    {
        $clean = trim($raw);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```$/', '', $clean);
        $clean = trim($clean);

        $start = strpos($clean, '{'); $end = strrpos($clean, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        }

        return null;
    }
}
