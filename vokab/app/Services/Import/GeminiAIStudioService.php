<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Gemini API client via Google AI Studio (free tier, simple API key auth).
 *
 * Free tier limits (Flash, subject to change): ~15 requests/minute,
 * ~1500 requests/day. We pace requests conservatively and stop cleanly
 * on a confirmed 429 rather than retry-looping into more 429s.
 *
 * Config (.env):
 *   GEMINI_API_KEY=your-ai-studio-key
 *   GEMINI_PRO_MODEL=gemini-2.5-pro
 *   GEMINI_FLASH_MODEL=gemini-2.5-flash
 */
class GeminiAIStudioService
{
    private string $apiKey;
    private string $proModel;
    private string $flashModel;
    private float  $lastRequestAt = 0.0;

    private const string BASE_URL    = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const int    TIMEOUT     = 120;
    private const int    RETRY_MAX   = 2;
    private const int    RETRY_DELAY = 5_000_000;

    // Pace requests to stay comfortably under ~15 req/min free tier limit
    private const int MIN_REQUEST_INTERVAL_US = 4_500_000; // 4.5s between requests

    public function __construct()
    {
        $this->apiKey     = config('import.gemini_api_key');
        $this->proModel   = config('import.gemini_pro_model', 'gemini-2.5-pro');
        $this->flashModel = config('import.gemini_flash_model', 'gemini-2.5-flash');
    }

    public function askProJson(string $systemPrompt, string $userPrompt): ?array
    {
        return $this->askJson($this->proModel, $systemPrompt, $userPrompt);
    }

    public function askFlashJson(string $systemPrompt, string $userPrompt): ?array
    {
        return $this->askJson($this->flashModel, $systemPrompt, $userPrompt);
    }

    private function askJson(string $model, string $systemPrompt, string $userPrompt): ?array
    {
        $attempt = 0;

        while ($attempt < self::RETRY_MAX) {
            $attempt++;

            $this->pace();

            $raw = $this->sendRequest($model, $systemPrompt, $userPrompt);

            if ($raw === null) {
                Log::channel('import')->warning('AIStudio: no response', [
                    'model'   => $model,
                    'attempt' => $attempt,
                ]);
                if ($attempt < self::RETRY_MAX) usleep(self::RETRY_DELAY);
                continue;
            }

            $parsed = $this->parseJson($raw);
            if ($parsed !== null) return $parsed;

            Log::channel('import')->warning('AIStudio: invalid JSON, retrying', [
                'model' => $model,
                'raw'   => mb_substr($raw, 0, 200),
            ]);
        }

        Log::channel('import')->error('AIStudio: failed after retries', ['model' => $model]);
        return null;
    }

    /**
     * Enforces a minimum delay between consecutive requests to stay
     * under the free tier RPM limit, instead of relying purely on
     * reactive 429 handling.
     */
    private function pace(): void
    {
        $lastRequestAt = (float) Cache::get('gemini_last_request_at', 0.0);
        $now = microtime(true);
        $elapsedUs = ($now - $lastRequestAt) * 1_000_000;

        if ($elapsedUs < self::MIN_REQUEST_INTERVAL_US) {
            $sleepUs = (int) (self::MIN_REQUEST_INTERVAL_US - $elapsedUs);
            usleep($sleepUs);
        }
    }

    private function sendRequest(string $model, string $systemPrompt, string $userPrompt): ?string
    {
        $url = self::BASE_URL . "/{$model}:generateContent?key={$this->apiKey}";

        $payload = json_encode([
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $userPrompt]]],
            ],
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
                'ignore_errors' => true, // so we can read the body on 429/4xx too
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        Cache::put('gemini_last_request_at', microtime(true), 600);

        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $status = (int) $m[1];
                break;
            }
        }

        if ($status === 429) {
            Log::channel('import')->error('AIStudio: RATE LIMIT HIT (429) — stopping is recommended', [
                'model' => $model,
            ]);
            throw new GeminiRateLimitException('AI Studio rate limit (429) reached');
        }

        if ($result === false || $status !== 200) {
            Log::channel('import')->error('AIStudio: HTTP request failed', [
                'url'    => $url,
                'status' => $status,
                'body'   => $result ? mb_substr($result, 0, 300) : null,
            ]);
            return null;
        }

        $data = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) return null;

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    private function parseJson(string $raw): ?array
    {
        $clean = trim($raw);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```$/', '', $clean);
        $clean = trim($clean);

        $start = strpos($clean, '{');
        $end   = strrpos($clean, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        }

        $start = strpos($clean, '[');
        $end   = strrpos($clean, ']');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);
            if (json_last_error() === JSON_ERROR_NONE) return ['results' => $decoded];
        }

        return null;
    }
}