<?php

namespace App\Services\Import;

use App\Services\Import\Concerns\ChainsModels;
use App\Services\Import\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Gemini API client (Google AI Studio).
 *
 * Quotas are per model, so each tier accepts a comma-separated model list that
 * is tried in order (see ChainsModels):
 *   GEMINI_FLASH_MODEL=gemini-3.8-flash,gemini-3.7-flash,gemini-3.6-flash
 *   GEMINI_PRO_MODEL=gemini-3.1-pro-preview
 * A single name works exactly as before.
 *
 * HTTP 503 (model overloaded) is not retried on the same model and does not
 * return null: the model is parked briefly and the next one is used, instead of
 * the caller re-sending the same batch (and burning quota) on an overloaded model.
 */
class GeminiAIStudioService implements AIProviderInterface
{
    use ChainsModels;

    private string $apiKey;

    /** @var string[] */
    private array $proModels;

    /** @var string[] */
    private array $flashModels;

    private const string BASE_URL    = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const int    TIMEOUT     = 120;
    private const int    RETRY_MAX   = 2;
    private const int    RETRY_DELAY = 5_000_000;
    private const int    MIN_REQUEST_INTERVAL_US = 4_500_000;

    public function __construct()
    {
        $this->apiKey     = config('import.gemini_api_key');
        $this->proModels   = self::parseModelList((string) config('import.gemini_pro_model'), 'gemini-3.1-pro-preview');
        $this->flashModels = self::parseModelList((string) config('import.gemini_flash_model'), 'gemini-3.6-flash');
    }

    public function askProJson(string $systemPrompt, string $userPrompt): ?array
    {
        return $this->askWithFallback(
            $this->proModels,
            fn (string $model) => $this->askJsonOnModel($model, $systemPrompt, $userPrompt)
        );
    }

    public function askFlashJson(string $systemPrompt, string $userPrompt): ?array
    {
        return $this->askWithFallback(
            $this->flashModels,
            fn (string $model) => $this->askJsonOnModel($model, $systemPrompt, $userPrompt)
        );
    }

    private function askJsonOnModel(string $model, string $systemPrompt, string $userPrompt): ?array
    {
        $attempt = 0;

        while ($attempt < self::RETRY_MAX) {
            $attempt++;

            $this->pace();

            $raw = $this->sendRequest($model, $systemPrompt, $userPrompt);

            if ($raw === null) {
                Log::channel('import')->warning('AIStudio: no response', [
                    'model' => $model, 'attempt' => $attempt,
                ]);
                if ($attempt < self::RETRY_MAX) usleep(self::RETRY_DELAY);
                continue;
            }

            $parsed = $this->parseJson($raw);
            if ($parsed !== null) return $parsed;

            Log::channel('import')->warning('AIStudio: invalid JSON, retrying', [
                'model' => $model, 'raw' => mb_substr($raw, 0, 200),
            ]);
        }

        Log::channel('import')->error('AIStudio: failed after retries', ['model' => $model]);
        return null;
    }

    private function pace(): void
    {
        $lastRequestAt = (float) Cache::get('gemini_last_request_at', 0.0);
        $elapsedUs = (microtime(true) - $lastRequestAt) * 1_000_000;

        if ($elapsedUs < self::MIN_REQUEST_INTERVAL_US) {
            usleep((int) (self::MIN_REQUEST_INTERVAL_US - $elapsedUs));
        }
    }

    private function sendRequest(string $model, string $systemPrompt, string $userPrompt): ?string
    {
        $url = self::BASE_URL . "/{$model}:generateContent?key={$this->apiKey}";

        $payload = json_encode([
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature'      => 0.1,
                'maxOutputTokens'  => 16384,
            ],
        ]);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($payload),
                ]),
                'content'       => $payload,
                'timeout'       => self::TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        Cache::put('gemini_last_request_at', microtime(true), 600);

        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int) $m[1]; break; }
        }

        if ($status === 429) {
            throw $this->rateLimitFailure($model, $result ?: null);
        }

        if ($status === 503) {
            throw new AIModelUnavailableException("{$model} is overloaded (HTTP 503)");
        }

        if ($status === 404 || $status === 403) {
            throw new AIModelUnavailableException("{$model} not available for this key (HTTP {$status})", permanent: true);
        }

        if ($result === false || $status !== 200) {
            Log::channel('import')->error('AIStudio: HTTP request failed', [
                'status' => $status, 'body' => $result ? mb_substr($result, 0, 300) : null,
            ]);
            return null;
        }

        $data = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) return null;

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    /**
     * A per-minute limit with a short retry hint only parks the model for a
     * moment; anything else (daily quota, unknown body) drops it for the run.
     * The body is logged so the real error format can be checked in the logs.
     */
    private function rateLimitFailure(string $model, ?string $body): \RuntimeException
    {
        Log::channel('import')->warning('AIStudio: HTTP 429', [
            'model' => $model,
            'body'  => $body ? mb_substr($body, 0, 300) : null,
        ]);

        if (
            $body !== null
            && !preg_match('/PerDay/i', $body)
            && preg_match('/PerMinute/i', $body)
            && preg_match('/"retryDelay"\s*:\s*"(\d+(?:\.\d+)?)s"/', $body, $m)
            && (float) $m[1] <= 90
        ) {
            return new AIModelUnavailableException(
                "{$model} per-minute limit (HTTP 429)",
                retryAfterSeconds: (int) ceil((float) $m[1]) + 1
            );
        }

        return new AIRateLimitException("{$model} rate limit (HTTP 429)");
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

        $start = strpos($clean, '['); $end = strrpos($clean, ']');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);
            if (json_last_error() === JSON_ERROR_NONE) return ['results' => $decoded];
        }

        return null;
    }
}
