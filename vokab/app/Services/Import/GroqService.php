<?php

namespace App\Services\Import;

use App\Services\Import\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Groq API client (free tier).
 *
 * Free tier: ~30 requests/min, ~14,400 requests/day, 500k tokens/day
 * (Llama 3.1 8B Instant tier). Open-source models (Llama, Qwen3,
 * Mixtral, GPT-OSS), not proprietary — quality for structured JSON
 * tasks should be verified against Gemini/Mistral output before
 * relying on it for anything quality-sensitive.
 *
 * "Flash" tier -> a fast small model, "Pro" tier -> a larger one.
 * Adjust model names in .env as Groq's model lineup changes.
 *
 * Config (.env):
 *   GROQ_API_KEY=your-key
 *   GROQ_FLASH_MODEL=llama-3.1-8b-instant
 *   GROQ_PRO_MODEL=llama-3.3-70b-versatile
 */
class GroqService implements AIProviderInterface
{
    private string $apiKey;
    private string $flashModel;
    private string $proModel;

    private const string BASE_URL = 'https://api.groq.com/openai/v1/chat/completions';
    private const int    TIMEOUT     = 120;
    private const int    RETRY_MAX   = 2;
    private const int    RETRY_DELAY = 2_000_000;

    // Free tier ~30 req/min — pace conservatively
    private const int MIN_REQUEST_INTERVAL_US = 2_100_000;

    public function __construct()
    {
        $this->apiKey     = config('import.groq_api_key');
        $this->flashModel = config('import.groq_flash_model', 'llama-3.1-8b-instant');
        $this->proModel   = config('import.groq_pro_model', 'llama-3.3-70b-versatile');
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
        Cache::put('groq_last_request_at', microtime(true), 600);

        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int) $m[1]; break; }
        }

        if ($status === 429) {
            Log::channel('import')->error('Groq: RATE LIMIT HIT (429)', ['model' => $model]);
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
