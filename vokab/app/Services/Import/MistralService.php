<?php

namespace App\Services\Import;

use App\Services\Import\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Mistral AI client (La Plateforme, free tier).
 *
 * Free tier: ~1 request/sec, 500k tokens/min, 1 billion tokens/month —
 * no hard daily request cap, unlike Gemini AI Studio. Better suited
 * for sustained batch processing.
 *
 * "Flash" tier -> mistral-small-latest, "Pro" tier -> mistral-medium-latest
 * (adjust model names in .env if Mistral renames/deprecates them).
 *
 * Config (.env):
 *   MISTRAL_API_KEY=your-key
 *   MISTRAL_SMALL_MODEL=mistral-small-latest
 *   MISTRAL_MEDIUM_MODEL=mistral-medium-latest
 */
class MistralService implements AIProviderInterface
{
    private string $apiKey;
    private string $smallModel;
    private string $mediumModel;

    private const string BASE_URL = 'https://api.mistral.ai/v1/chat/completions';
    private const int    TIMEOUT     = 120;
    private const int    RETRY_MAX   = 2;
    private const int    RETRY_DELAY = 3_000_000;

    // Free tier is ~1 req/sec — pace conservatively to stay under it
    private const int MIN_REQUEST_INTERVAL_US = 1_200_000;

    public function __construct()
    {
        $this->apiKey      = config('import.mistral_api_key');
        $this->smallModel  = config('import.mistral_small_model', 'mistral-small-latest');
        $this->mediumModel = config('import.mistral_medium_model', 'mistral-medium-latest');
    }

    public function askFlashJson(string $systemPrompt, string $userPrompt): ?array
    {
        return $this->askJson($this->smallModel, $systemPrompt, $userPrompt);
    }

    public function askProJson(string $systemPrompt, string $userPrompt): ?array
    {
        return $this->askJson($this->mediumModel, $systemPrompt, $userPrompt);
    }

    private function askJson(string $model, string $systemPrompt, string $userPrompt): ?array
    {
        $attempt = 0;

        while ($attempt < self::RETRY_MAX) {
            $attempt++;
            $this->pace();

            $raw = $this->sendRequest($model, $systemPrompt, $userPrompt);

            if ($raw === null) {
                Log::channel('import')->warning('Mistral: no response', [
                    'model' => $model, 'attempt' => $attempt,
                ]);
                if ($attempt < self::RETRY_MAX) usleep(self::RETRY_DELAY);
                continue;
            }

            $parsed = $this->parseJson($raw);
            if ($parsed !== null) return $parsed;

            Log::channel('import')->warning('Mistral: invalid JSON, retrying', [
                'model' => $model, 'raw' => mb_substr($raw, 0, 200),
            ]);
        }

        Log::channel('import')->error('Mistral: failed after retries', ['model' => $model]);
        return null;
    }

    private function pace(): void
    {
        $lastRequestAt = (float) Cache::get('mistral_last_request_at', 0.0);
        $elapsedUs = (microtime(true) - $lastRequestAt) * 1_000_000;

        if ($elapsedUs < self::MIN_REQUEST_INTERVAL_US) {
            usleep((int) (self::MIN_REQUEST_INTERVAL_US - $elapsedUs));
        }
    }

    private function sendRequest(string $model, string $systemPrompt, string $userPrompt): ?string
    {
        $payload = json_encode([
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature'     => 0.1,
        ]);

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
        Cache::put('mistral_last_request_at', microtime(true), 600);

        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int) $m[1]; break; }
        }

        if ($status === 429) {
            Log::channel('import')->error('Mistral: RATE LIMIT HIT (429)', ['model' => $model]);
            throw new AIRateLimitException('Mistral rate limit (429) reached');
        }

        if ($result === false || $status !== 200) {
            Log::channel('import')->error('Mistral: HTTP request failed', [
                'status' => $status, 'body' => $result ? mb_substr($result, 0, 300) : null,
            ]);
            return null;
        }

        $data = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) return null;

        return $data['choices'][0]['message']['content'] ?? null;
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
