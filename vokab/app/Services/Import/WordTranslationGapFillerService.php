<?php

namespace App\Services\Import;

use App\Models\Word;
use App\Services\Import\GeminiAIStudioService;
use Illuminate\Support\Facades\Log;

/**
 * Fills translation gaps for words that already went through
 * WordSemanticTranslatorService but ended up with a partial result
 * (typically translation_en filled, translation_ru/uk missing) —
 * a known side effect of JSON truncation on large Gemini responses.
 *
 * Two separate fill strategies, matching what each field actually needs:
 *
 *   - translation_ru/uk: re-queried from Gemini Flash with a lightweight
 *     translate-only prompt (no morphology/segmentation — the word
 *     already passed that stage). Cannot use NLLB here: these are
 *     comma-separated MULTIPLE meanings (polysemy), and NLLB would
 *     mistranslate the whole list as one phrase.
 *
 *   - gloss_ru/uk: filled via NLLB from the existing gloss_en — a gloss
 *     is a single coherent sentence, not a meaning list, so a plain
 *     translation is accurate and this is free/instant instead of
 *     spending another Gemini call.
 *
 * Idempotent: only processes words with translation_en set but
 * translation_ru or translation_uk still null.
 */
class WordTranslationGapFillerService
{
    private int $processed      = 0;
    private int $translationsFixed = 0;
    private int $glossesFixed      = 0;

    private const int BATCH_SIZE = 20;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
You are a German-Russian-Ukrainian dictionary assistant. For each German
word you are given its already-known English translation(s) for context.
Provide the equivalent Russian and Ukrainian translations — same number
and order of meanings as the English list, comma-separated.

Respond ONLY with valid JSON, no markdown. Keyed by the exact input word.

Example:
{
  "lassen": {"ru": "позволять, оставлять, поручать, заставлять", "uk": "дозволяти, залишати, доручати, змушувати"}
}
PROMPT;

    public function __construct(
        private readonly GeminiAIStudioService $ai,
        private readonly ExampleTranslator     $translator,
    ) {}

    public function run(): void
    {
        Log::channel('import')->info('=== WordTranslationGapFillerService started ===');

        $query = Word::whereNotNull('translation_en')
            ->where(function ($q) {
                $q->whereNull('translation_ru')->orWhereNull('translation_uk');
            });

        $total = $query->count();
        Log::channel('import')->info('Words with translation gaps', ['count' => $total]);

        $query->chunkById(self::BATCH_SIZE, function ($words) {
            $this->fillTranslationGaps($words->all());
        });

        // Glosses handled separately via NLLB — cheap, no need to batch by Gemini limits
        $this->fillGlossGaps();

        Log::channel('import')->info('=== WordTranslationGapFillerService finished ===', [
            'processed'          => $this->processed,
            'translations_fixed' => $this->translationsFixed,
            'glosses_fixed'      => $this->glossesFixed,
        ]);
    }

    private function fillTranslationGaps(array $words): void
    {
        // Only ask Gemini about words actually missing ru or uk
        $needy = array_filter($words, fn($w) => $w->translation_ru === null || $w->translation_uk === null);

        if (empty($needy)) {
            $this->processed += count($words);
            return;
        }

        $lines = array_map(fn($w) => "{$w->word}: {$w->translation_en}", $needy);
        $userPrompt = "Provide Russian and Ukrainian translations for these German words "
            . "(English given for context):\n" . implode("\n", $lines);

        $result = $this->ai->askFlashJson(self::SYSTEM_PROMPT, $userPrompt);

        if ($result === null) {
            Log::channel('import')->warning('GapFiller: no result for batch', ['size' => count($needy)]);
            $this->processed += count($words);
            return;
        }

        foreach ($needy as $word) {
            $data = $result[$word->word] ?? null;
            if ($data === null) continue;

            $updates = [];
            if ($word->translation_ru === null && !empty($data['ru'])) {
                $updates['translation_ru'] = $data['ru'];
            }
            if ($word->translation_uk === null && !empty($data['uk'])) {
                $updates['translation_uk'] = $data['uk'];
            }

            if (!empty($updates)) {
                $word->update($updates);
                $this->translationsFixed++;
            }
        }

        $this->processed += count($words);
    }

    private function fillGlossGaps(): void
    {
        Word::whereNotNull('gloss_en')
            ->where(function ($q) {
                $q->whereNull('gloss_ru')->orWhereNull('gloss_uk');
            })
            ->chunkById(50, function ($words) {
                $glosses = $words->pluck('gloss_en')->toArray();

                $ru = $this->translateEnglishBatch($glosses, 'ru');
                $uk = $this->translateEnglishBatch($glosses, 'uk');

                foreach ($words as $i => $word) {
                    $updates = [];
                    if ($word->gloss_ru === null && !empty($ru[$i])) {
                        $updates['gloss_ru'] = $ru[$i];
                    }
                    if ($word->gloss_uk === null && !empty($uk[$i])) {
                        $updates['gloss_uk'] = $uk[$i];
                    }
                    if (!empty($updates)) {
                        $word->update($updates);
                        $this->glossesFixed++;
                    }
                }
            });
    }

    /**
     * NLLB direction codes are keyed like "de-ru", but our translator
     * container also understands English as a source since NLLB
     * supports arbitrary FLORES-200 pairs — reuse translateBatch()
     * with an "en-xx" direction instead of "de-xx".
     *
     * @return array<string|null>
     */
    private function translateEnglishBatch(array $texts, string $targetLang): array
    {
        return $this->translator->translateBatch($texts, "en-{$targetLang}");
    }
}