<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;
use Google\Auth\Credentials\ServiceAccountCredentials;

/**
 * Vertex AI REST client authenticated via service account JSON.
 *
 * Uses google/auth (lightweight, pure PHP) for OAuth2 authentication.
 * No Context Caching — our request volume is below Vertex AI minimum.
 */
class VertexAIService
{
    private string  $project;
    private string  $location;
    private string  $proModel;
    private string  $flashModel;
    private ?string $accessToken    = null;
    private int     $tokenExpiresAt = 0;

    private const string SCOPE       = 'https://www.googleapis.com/auth/cloud-platform';
    private const int    TIMEOUT     = 120;
    private const int    RETRY_MAX   = 2;
    private const int    RETRY_DELAY = 3_000_000;

    public function __construct()
    {
        $this->project    = config('import.google_cloud_project');
        $this->location   = config('import.google_cloud_location', 'us-east5');
        $this->proModel   = config('import.gemini_pro_model', 'gemini-2.5-pro');
        $this->flashModel = config('import.gemini_flash_model', 'gemini-2.5-flash');
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Ask Gemini Pro (complex tasks: categorization, idioms, POS duplicates).
     */
    public function askProJson(string $systemPrompt, string $userPrompt): ?array
    {
        return $this->askJson($this->proModel, $systemPrompt, $userPrompt);
    }

    /**
     * Ask Gemini Flash (bulk tasks: garbage cleanup, example generation).
     */
    public function askFlashJson(string $systemPrompt, string $userPrompt): ?array
    {
        return $this->askJson($this->flashModel, $systemPrompt, $userPrompt);
    }

    // -------------------------------------------------------------------------
    // Core request logic
    // -------------------------------------------------------------------------

    private function askJson(string $model, string $systemPrompt, string $userPrompt): ?array
    {
        $attempt = 0;

        while ($attempt < self::RETRY_MAX) {
            $attempt++;

            $raw = $this->sendRequest($model, $systemPrompt, $userPrompt);

            if ($raw === null) {
                Log::channel('import')->warning('VertexAI: no response', [
                    'model'   => $model,
                    'attempt' => $attempt,
                    'prompt'  => mb_substr($userPrompt, 0, 80),
                ]);

                if ($attempt < self::RETRY_MAX) {
                    usleep(self::RETRY_DELAY);
                }
                continue;
            }

            $parsed = $this->parseJson($raw);

            if ($parsed !== null) {
                return $parsed;
            }

            Log::channel('import')->warning('VertexAI: invalid JSON, retrying', [
                'model'   => $model,
                'attempt' => $attempt,
                'raw'     => mb_substr($raw, 0, 200),
            ]);
        }

        Log::channel('import')->error('VertexAI: failed after retries', [
            'model'  => $model,
            'prompt' => mb_substr($userPrompt, 0, 80),
        ]);

        return null;
    }

    // -------------------------------------------------------------------------
    // HTTP request
    // -------------------------------------------------------------------------

    private function sendRequest(string $model, string $systemPrompt, string $userPrompt): ?string
    {
        $token = $this->getAccessToken();
        if ($token === null) return null;

        $url = sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent',
            $this->location,
            $this->project,
            $this->location,
            $model,
        );

        $payload = json_encode([
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
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
                'method'  => 'POST',
                'header'  => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                    'Content-Length: ' . strlen($payload),
                ]),
                'content' => $payload,
                'timeout' => self::TIMEOUT,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $status = '';
            foreach ($http_response_header ?? [] as $h) {
                if (str_starts_with($h, 'HTTP/')) {
                    $status = $h;
                    break;
                }
            }

            Log::channel('import')->error('VertexAI: HTTP request failed', [
                'url'    => $url,
                'status' => $status,
            ]);
            return null;
        }

        $data = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    private function getAccessToken(): ?string
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiresAt - 60) {
            return $this->accessToken;
        }

        $credentialsPath = config('import.google_credentials_path');

        if (!file_exists($credentialsPath)) {
            Log::channel('import')->error('VertexAI: service account JSON not found', [
                'path' => $credentialsPath,
            ]);
            return null;
        }

        try {
            $jsonKey     = json_decode(file_get_contents($credentialsPath), true);
            $credentials = new ServiceAccountCredentials(self::SCOPE, $jsonKey);
            $token       = $credentials->fetchAuthToken();

            if (empty($token['access_token'])) {
                Log::channel('import')->error('VertexAI: empty access token');
                return null;
            }

            $this->accessToken    = $token['access_token'];
            $this->tokenExpiresAt = time() + ($token['expires_in'] ?? 3600);

            return $this->accessToken;

        } catch (\Throwable $e) {
            Log::channel('import')->error('VertexAI: auth failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // JSON parsing
    // -------------------------------------------------------------------------

    private function parseJson(string $raw): ?array
    {
        $clean = trim($raw);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```$/', '', $clean);
        $clean = trim($clean);

        // Try object first {
        $start = strpos($clean, '{');
        $end   = strrpos($clean, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        }

        // Try array [
        $start = strpos($clean, '[');
        $end   = strrpos($clean, ']');

        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Wrap array in object for consistent handling
                return ['results' => $decoded];
            }
        }

        return null;
    }
}
