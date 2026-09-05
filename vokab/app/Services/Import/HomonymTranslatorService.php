<?php

namespace App\Services\Import;

use App\Models\Word;
use Illuminate\Support\Facades\Log;

class HomonymTranslatorService
{
    private int $processed = 0;
    private int $translated = 0;
    private int $skippedNoExample = 0;
    private int $retried = 0;

    private const int BATCH_SIZE = 30;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
You are a German dictionary assistant specialized in disambiguating homonyms.

Each entry gives you a German word, its grammatical info, and an example
sentence showing how it is used. Translate the word to English, Russian,
and Ukrainian — using ONLY the meaning shown in that specific sentence context.

Respond with a flat JSON object where each key is the entry number (as string)
and the value is an object with en/ru/uk translations (1-3 words each,
not a full sentence translation).

If a translation is genuinely impossible for a language, use null for that field.
You MUST return an entry for EVERY numbered item. No omissions.

Example:
Input entries:
1. Bank (Noun, die) — context: "Er sitzt auf der Bank im Park."
2. Bank (Noun, die) — context: "Er geht zur Bank, um Geld abzuheben."

Response:
{
  "1": {"en": "bench", "ru": "скамейка", "uk": "лавка"},
  "2": {"en": "bank", "ru": "банк", "uk": "банк"}
}
PROMPT;

    public function __construct(
        private readonly GeminiAIStudioService $ai,
    ) {}

    public function run(): void
    {
        Log::channel('import')->info('=== HomonymTranslatorService started ===');

        $total = Word::whereNotNull('homonym_index')
            ->whereNull('translation_en')
            ->count();

        Log::channel('import')->info('Homonyms to translate', ['count' => $total]);

        Word::whereNotNull('homonym_index')
            ->whereNull('translation_en')
            ->with('examples')
            ->chunkById(self::BATCH_SIZE, function ($words) {
                $this->processBatch($words->all());
            });

        Log::channel('import')->info('=== HomonymTranslatorService finished ===', [
            'processed'          => $this->processed,
            'translated'         => $this->translated,
            'skipped_no_example' => $this->skippedNoExample,
            'retried'            => $this->retried,
        ]);
    }

    private function processBatch(array $words): void
    {
        $entries = [];
        $entryMap = [];
        $number = 1;

        foreach ($words as $word) {
            $example = $word->examples->first();

            if ($example === null) {
                $this->skippedNoExample++;
                $this->processed++;
                continue;
            }

            $posName    = $word->part_of_speech->name ?? 'Unknown';
            $genderInfo = $word->noun ? " ({$this->genderLabel($word->noun->gender)})" : '';

            $entries[] = "{$number}. {$word->word} ({$posName}{$genderInfo}) — context: \"{$example->sentence}\"";
            $entryMap[$number] = $word;
            $number++;
        }

        if (empty($entries)) return;

        $userPrompt = "Translate these German homonyms using their context:\n" . implode("\n", $entries);
        $result     = $this->ai->askFlashJson(self::SYSTEM_PROMPT, $userPrompt);

        if ($result === null) {
            Log::channel('import')->warning('HomonymTranslator: no result, retrying individually', [
                'batch_size' => count($entryMap),
            ]);
            foreach ($entryMap as $word) {
                $this->processSingle($word);
                $this->retried++;
            }
            $this->processed += count($entryMap);
            return;
        }

        $missing = [];
        foreach ($entryMap as $num => $word) {
            $key = (string) $num;
            if (!isset($result[$key]) || empty($result[$key]['en'] ?? null)) {
                $missing[] = $word;
            }
        }

        if (!empty($missing)) {
            Log::channel('import')->warning('HomonymTranslator: missing/empty translations, retrying', [
                'missing_count' => count($missing),
            ]);
            foreach ($missing as $word) {
                $this->processSingle($word);
                $this->retried++;
            }
        }

        foreach ($entryMap as $num => $word) {
            $key  = (string) $num;
            $data = $result[$key] ?? null;
            if ($data !== null) {
                $this->saveTranslation($word, $data);
            }
            $this->processed++;
        }

        if ($this->processed % 500 === 0) {
            Log::channel('import')->info('HomonymTranslator progress', [
                'processed'  => $this->processed,
                'translated' => $this->translated,
                'retried'    => $this->retried,
            ]);
        }
    }

    private function processSingle(Word $word): void
    {
        $example = $word->examples->first() ?? $word->examples()->first();
        if ($example === null) {
            $this->skippedNoExample++;
            return;
        }

        $posName    = $word->part_of_speech->name ?? 'Unknown';
        $genderInfo = $word->noun ? " ({$this->genderLabel($word->noun->gender)})" : '';

        $userPrompt = "Translate this German homonym using its context:\n"
            . "1. {$word->word} ({$posName}{$genderInfo}) — context: \"{$example->sentence}\"";

        $result = $this->ai->askFlashJson(self::SYSTEM_PROMPT, $userPrompt);
        if ($result === null) return;

        $data = $result['1'] ?? null;
        if ($data !== null && !empty($data['en'] ?? null)) {
            $this->saveTranslation($word, $data);
        }
    }

    private function saveTranslation(Word $word, array $data): void
    {
        $en = !empty($data['en']) ? $data['en'] : null;
        $ru = !empty($data['ru']) ? $data['ru'] : null;
        $uk = !empty($data['uk']) ? $data['uk'] : null;

        if ($en === null && $ru === null && $uk === null) return;

        $word->update(['translation_en' => $en, 'translation_ru' => $ru, 'translation_uk' => $uk]);
        $this->translated++;
    }

    private function genderLabel(?string $gender): string
    {
        return match ($gender) {
            'm'     => 'der',
            'f'     => 'die',
            'n'     => 'das',
            default => '',
        };
    }
}
