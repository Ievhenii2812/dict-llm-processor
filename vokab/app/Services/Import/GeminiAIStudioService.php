<?php

namespace App\Services\Import;

use App\Services\Import\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GeminiAIStudioService implements AIProviderInterface
{
    private string $apiKey;
    private string $proModel;
    private string $flashModel;

    private const string BASE_URL    = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const int    TIMEOUT     = 120;
    private const int    RETRY_MAX   = 2;
    private const int    RETRY_DELAY = 5_000_000;
    private const int    MIN_REQUEST_INTERVAL_US = 4_500_000;

    public function __construct()
    {
        $this->apiKey     = config('import.gemini_api_key');
        $this->proModel   = config('import.gemini_pro_model', 'gemini-3.1-pro');
        $this->flashModel = config('import.gemini_flash_model', 'gemini-3.6-flash');
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
            Log::channel('import')->error('AIStudio: RATE LIMIT HIT (429)', ['model' => $model]);
            throw new AIRateLimitException('AI Studio rate limit (429) reached');
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
