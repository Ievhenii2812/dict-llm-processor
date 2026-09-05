<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;

/**
 * Translates text via the local "translator" container (CTranslate2 + NLLB-200).
 *
 * Unlike the previous Helsinki-based setup, NLLB translates directly
 * between any pair without pivoting through English — de->ru and de->uk
 * are single-step translations, avoiding cascading errors.
 *
 * Config (.env):
 *   TRANSLATOR_URL=http://vokab_translator:8000
 */
class ExampleTranslator
{
    private string $baseUrl;

    private const int TIMEOUT     = 120;
    private const int RETRY_MAX   = 2;
    private const int RETRY_DELAY = 2_000_000;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('import.translator_url', 'http://vokab_translator:8000'), '/');
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Translate a German sentence directly to en/ru/uk (three independent calls).
     *
     * @return array{en: string|null, ru: string|null, uk: string|null}
     */
    public function translateAll(string $germanText): array
    {
        return [
            'en' => $this->translateOne($germanText, 'de-en'),
            'ru' => $this->translateOne($germanText, 'de-ru'),
            'uk' => $this->translateOne($germanText, 'de-uk'),
        ];
    }

    /**
     * Batch version — translates a list of German sentences to all three
     * languages, one batch HTTP call per direction (3 calls total, not 3*N).
     *
     * @param  string[] $germanTexts
     * @return array<array{en: string|null, ru: string|null, uk: string|null}>
     */
    public function translateAllBatch(array $germanTexts): array
    {
        if (empty($germanTexts)) return [];

        $en = $this->translateBatch($germanTexts, 'de-en');
        $ru = $this->translateBatch($germanTexts, 'de-ru');
        $uk = $this->translateBatch($germanTexts, 'de-uk');

        $results = [];
        foreach ($germanTexts as $i => $text) {
            $results[] = [
                'en' => $en[$i] ?? null,
                'ru' => $ru[$i] ?? null,
                'uk' => $uk[$i] ?? null,
            ];
        }

        return $results;
    }

    /**
     * Translate a batch of texts in a single direction (de-en, de-ru, de-uk).
     *
     * @param  string[] $texts
     * @return array<string|null>
     */
    public function translateBatch(array $texts, string $direction): array
    {
        if (empty($texts)) return [];

        $attempt = 0;

        while ($attempt < self::RETRY_MAX) {
            $attempt++;

            $response = $this->post('/translate', [
                'direction' => $direction,
                'sentences' => $texts,
            ]);

            if ($response === null) {
                Log::channel('import')->warning('Translator: batch HTTP error', [
                    'direction' => $direction,
                    'count'     => count($texts),
                    'attempt'   => $attempt,
                ]);

                if ($attempt < self::RETRY_MAX) {
                    usleep(self::RETRY_DELAY);
                }
                continue;
            }

            $translations = $response['translations'] ?? [];

            if (count($translations) !== count($texts)) {
                Log::channel('import')->warning('Translator: batch size mismatch', [
                    'direction' => $direction,
                    'expected'  => count($texts),
                    'got'       => count($translations),
                ]);
                return $this->translateOneByOne($texts, $direction);
            }

            return $translations;
        }

        Log::channel('import')->error('Translator: batch failed after retries', [
            'direction' => $direction,
            'count'     => count($texts),
        ]);

        return array_fill(0, count($texts), null);
    }

    // -------------------------------------------------------------------------
    // Internal logic
    // -------------------------------------------------------------------------

    private function translateOne(string $text, string $direction): ?string
    {
        $result = $this->translateBatch([$text], $direction);
        return $result[0] ?? null;
    }

    private function translateOneByOne(array $texts, string $direction): array
    {
        $results = [];
        foreach ($texts as $text) {
            $response = $this->post('/translate', [
                'direction' => $direction,
                'sentences' => [$text],
            ]);
            $results[] = $response['translations'][0] ?? null;
        }
        return $results;
    }

    private function post(string $endpoint, array $payload): ?array
    {
        $json    = json_encode($payload);
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($json),
                ]),
                'content' => $json,
                'timeout' => self::TIMEOUT,
            ],
        ]);

        $result = @file_get_contents($this->baseUrl . $endpoint, false, $context);

        if ($result === false) return null;

        $decoded = json_decode($result, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
