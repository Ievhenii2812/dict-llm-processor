<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;

/**
 * Local, free German compound word splitter (CharSplit, MIT license)
 * via the lemmatizer container. Replaces Gemini for morphological
 * segmentation entirely — this was the single most expensive/fragile
 * part of the old WordSemanticTranslatorService prompt (large JSON
 * responses, frequent truncation).
 */
class CompoundSplitterService
{
    private string $baseUrl;

    private const int TIMEOUT = 10;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('import.spacy_url', 'http://vokab_lemmatizer:8000'), '/');
    }

    /**
     * @return array{is_compound: bool, segments: string[]}
     */
    public function split(string $word): array
    {
        $result = $this->post('/compound-split', ['word' => $word]);

        if ($result === null) {
            Log::channel('import')->warning('CompoundSplitter: request failed', ['word' => $word]);
            return ['is_compound' => false, 'segments' => [$word]];
        }

        return $result;
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