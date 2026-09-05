<?php

namespace App\Services\Import;

use App\Models\Phrase;
use Illuminate\Support\Facades\Log;

class PhraseTranslatorService
{
    private int $processed = 0;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
You are an expert in German idioms, phraseology, and multilingual literary equivalents.
Respond ONLY with valid JSON, no markdown.
PROMPT;

    public function __construct(
        private readonly ExampleTranslator     $translator,
        private readonly GeminiAIStudioService $ai,
    ) {}

    public function run(): void
    {
        Log::channel('import')->info('=== PhraseTranslatorService started ===');

        Phrase::whereNull('meaning_en')
            ->chunkById(20, function ($phrases) {
                foreach ($phrases as $phrase) {
                    $this->processPhrase($phrase);
                }
            });

        Log::channel('import')->info('=== PhraseTranslatorService finished ===', [
            'processed' => $this->processed,
        ]);
    }

    private function processPhrase(Phrase $phrase): void
    {
        $updates = [];

        if (!empty($phrase->meaning_de)) {
            $translated = $this->translator->translateAll($phrase->meaning_de);

            $updates['meaning_en'] = $translated['en'];
            $updates['meaning_ru'] = $translated['ru'];
            $updates['meaning_uk'] = $translated['uk'];
        }

        $analogues = $this->findAnalogues($phrase->phrase, $phrase->meaning_de ?? '');

        if ($analogues !== null) {
            $updates['translation_en'] = $analogues['en'] ?? null;
            $updates['translation_ru'] = $analogues['ru'] ?? null;
            $updates['translation_uk'] = $analogues['uk'] ?? null;
        }

        if (!empty($updates)) {
            $phrase->update($updates);
        }

        $this->processed++;

        if ($this->processed % 20 === 0) {
            Log::channel('import')->info('PhraseTranslator progress', ['processed' => $this->processed]);
        }
    }

    private function findAnalogues(string $phraseText, string $meaningDe): ?array
    {
        $prompt = <<<PROMPT
German idiom: "{$phraseText}"
German meaning: "{$meaningDe}"

Find natural literary equivalent idioms or expressions in English, Russian, and Ukrainian
that convey the same meaning. If no natural equivalent exists in a language, return null for that language.
Do NOT translate literally — find expressions that native speakers actually use.

Respond with:
{
  "en": "English equivalent idiom or null",
  "ru": "Russian equivalent idiom or null",
  "uk": "Ukrainian equivalent idiom or null"
}
PROMPT;

        $result = $this->ai->askProJson(self::SYSTEM_PROMPT, $prompt);

        if ($result === null) {
            Log::channel('import')->warning('PhraseTranslator: no analogues from AI Studio', [
                'phrase' => mb_substr($phraseText, 0, 60),
            ]);
            return null;
        }

        return $result;
    }
}
