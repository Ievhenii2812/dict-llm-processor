<?php

namespace App\Services\Import;

use App\Enums\PartOfSpeech;
use App\Models\Word;
use Illuminate\Support\Facades\Log;
use App\Services\Import\Contracts\AIProviderInterface;

class WordSemanticTranslatorService
{
    private int $processed    = 0;
    private int $translated   = 0;
    private int $mergedForms  = 0;
    private int $deletedCompositional = 0;
    private int $translatedViaNllb    = 0;
    private int $retried      = 0;

    private const int BATCH_SIZE = 25; // lighter prompt now, larger batch is safe

    private const int COMPOSITIONAL_FREQUENCY_THRESHOLD       = 200;
    private const int MIXED_COMPOSITIONAL_FREQUENCY_THRESHOLD = 50;
    private const int MIN_SPLIT_SIZE = 4; // below this, give up and let next run retry

    // Lightweight prompt: translation + gloss ONLY, no segmentation.
    // Used only for words that already passed the local morphology checks.
    private const string SYSTEM_PROMPT = <<<'PROMPT'
You are a German-English-Russian-Ukrainian dictionary assistant.
For each German word, provide up to 5 common meanings per language
(comma-separated, ordered by frequency) and a short (5-12 word) gloss
explaining the core sense — important for polysemous words.

Respond ONLY with valid JSON, no markdown. Keyed by the exact input word.

Example:
{
  "lassen": {
    "en": "let, allow, leave, have done, cause",
    "ru": "позволять, оставлять, поручать, заставлять",
    "uk": "дозволяти, залишати, доручати, змушувати",
    "gloss_en": "to permit an action or have someone else do it",
    "gloss_ru": "разрешить действие или поручить его выполнение",
    "gloss_uk": "дозволити дію або доручити її виконання"
  }
}
PROMPT;

    public function __construct(
        private readonly AIProviderInterface     $ai,
        private readonly SpacyService            $spacy,
        private readonly CompoundSplitterService $splitter,
        private readonly ExampleTranslator       $translator,
    ) {}

    public function run(): void
    {
        Log::channel('import')->info('=== WordSemanticTranslatorService started ===');

        $total = Word::whereNull('translation_en')
            ->whereNull('homonym_index')
            ->count();

        Log::channel('import')->info('Non-homonym words to process', ['count' => $total]);

        Word::whereNull('translation_en')
            ->whereNull('homonym_index')
            ->chunkById(self::BATCH_SIZE, function ($words) {
                $this->processBatch($words->all());
            });

        Log::channel('import')->info('=== WordSemanticTranslatorService finished ===', [
            'processed'              => $this->processed,
            'translated_via_gemini'  => $this->translated,
            'translated_via_nllb'    => $this->translatedViaNllb,
            'merged_forms'           => $this->mergedForms,
            'deleted_compositional'  => $this->deletedCompositional,
            'retried'                => $this->retried,
        ]);
    }

    private function processBatch(array $words): void
    {
        // Step 1: local morphology pass (free, no API calls at all)
        $needsGeminiTranslation = [];

        foreach ($words as $word) {
            $handled = $this->handleLocally($word);

            if (!$handled) {
                $needsGeminiTranslation[] = $word;
            }

            $this->processed++;
        }

        // Step 2: only words that survived local checks go to Gemini,
        // and only for translation — no segmentation needed anymore
        if (!empty($needsGeminiTranslation)) {
            $this->translateBatchViaGemini($needsGeminiTranslation);
        }

        if ($this->processed % 2000 === 0) {
            Log::channel('import')->info('WordSemanticTranslator progress', [
                'processed'             => $this->processed,
                'translated_via_gemini' => $this->translated,
                'translated_via_nllb'   => $this->translatedViaNllb,
                'merged_forms'          => $this->mergedForms,
                'deleted_compositional' => $this->deletedCompositional,
            ]);
        }
    }

    /**
     * Attempts to fully resolve a word using only local tools
     * (spaCy lemma check, CharSplit compound segmentation, NLLB translation).
     *
     * @return bool true if the word was fully handled (merged/deleted/translated),
     *              false if it still needs Gemini for semantic translation
     */
    private function handleLocally(Word $word): bool
    {
        // 1a. Lemma check via spaCy — catches wordforms that slipped through
        // the original Hunspell/spaCy pass during initial import.
        $analysis = $this->spacy->analyze($word->word);

        if ($analysis->isKnown && $analysis->isInflected()) {
            $this->handleWordform($word, $analysis->lemma);
            return true;
        }

        // 1b. Compound check — only meaningful for nouns
        if ($word->part_of_speech === PartOfSpeech::Noun) {
            $split = $this->splitter->split($word->word);

            if ($split['is_compound'] && count($split['segments']) >= 2) {
                return $this->handleCompound($word, $split['segments']);
            }
        }

        return false; // not a wordform, not a (deletable/translatable) compound
    }

    private function handleWordform(Word $word, string $correctLemma): void
    {
        if (empty($correctLemma) || $correctLemma === $word->word) {
            return;
        }

        $existing = Word::where('word', $correctLemma)
            ->whereNull('homonym_index')
            ->where('id', '!=', $word->id)
            ->first();

        if ($existing !== null) {
            $existing->increment('frequency_rank', $word->frequency_rank ?? 0);
            $this->reattachExamples($word->external_id, $existing->external_id);
            $word->delete();
        } else {
            $word->update(['word' => $correctLemma]);
        }

        $this->mergedForms++;
    }

    /**
     * Same deterministic rules as before, now fed by CharSplit segments
     * instead of Gemini-reported segments. Both components must exist
     * as separate lemmas in our own DB before we ever delete anything.
     *
     * @return bool true if handled (deleted or translated via NLLB)
     */
    private function handleCompound(Word $word, array $rawSegments): bool
    {
        // Lemmatize each raw segment first — this is the missing step.
        $lemmatizedSegments = array_map(function (string $segment) {
            $analysis = $this->spacy->analyze($segment);

            // Use spaCy's lemma if it found one, otherwise fall back to
            // the raw CharSplit segment as-is (e.g. Fugen-s remnants spaCy
            // doesn't recognize as a word at all — nothing better to try).
            return $analysis->isKnown ? $analysis->lemma : $segment;
        }, $rawSegments);

        $partWords = collect($lemmatizedSegments)->map(
            fn($s) => Word::where('word', $s)->whereNull('homonym_index')->first()
        );

        $allPartsExist = $partWords->every(fn($w) => $w !== null);

        if (!$allPartsExist) {
            return false; // still can't verify — fall back to Gemini translation
        }

        $allNouns = $partWords->every(
            fn($w) => $w->part_of_speech === PartOfSpeech::Noun
        );

        $threshold = $allNouns
            ? self::COMPOSITIONAL_FREQUENCY_THRESHOLD
            : self::MIXED_COMPOSITIONAL_FREQUENCY_THRESHOLD;

        if (($word->frequency_rank ?? 0) <= $threshold) {
            Log::channel('import')->debug('Compositional word deleted', [
                'word'                => $word->word,
                'raw_segments'        => $rawSegments,
                'lemmatized_segments' => $lemmatizedSegments,
            ]);
            $word->delete();
            $this->deletedCompositional++;
            return true;
        }

        // Frequent enough to keep — translate via NLLB.
        $translations = $this->translator->translateAll($word->word);

        if ($translations['en'] === null && $translations['ru'] === null && $translations['uk'] === null) {
            return false;
        }

        $word->update([
            'translation_en' => $translations['en'],
            'translation_ru' => $translations['ru'],
            'translation_uk' => $translations['uk'],
        ]);

        $this->translatedViaNllb++;
        return true;
    }

    private function saveTranslation(Word $word, array $data): void
    {
        $en = !empty($data['en']) ? $data['en'] : null;
        $ru = !empty($data['ru']) ? $data['ru'] : null;
        $uk = !empty($data['uk']) ? $data['uk'] : null;

        if ($en === null && $ru === null && $uk === null) return;

        $word->update([
            'translation_en' => $en,
            'translation_ru' => $ru,
            'translation_uk' => $uk,
            'gloss_en'       => $data['gloss_en'] ?? null,
            'gloss_ru'       => $data['gloss_ru'] ?? null,
            'gloss_uk'       => $data['gloss_uk'] ?? null,
        ]);

        $this->translated++;
    }

    private function reattachExamples(int $fromExternalId, int $toExternalId): void
    {
        $exampleIds = \Illuminate\Support\Facades\DB::table('example_word')
            ->where('external_word_id', $fromExternalId)
            ->whereNotIn('external_example_id', function ($query) use ($toExternalId) {
                $query->select('external_example_id')
                    ->from('example_word')
                    ->where('external_word_id', $toExternalId);
            })
            ->pluck('external_example_id');

        foreach ($exampleIds as $exampleId) {
            \App\Models\ExampleWord::firstOrCreate([
                'external_word_id'    => $toExternalId,
                'external_example_id' => $exampleId,
            ]);
        }
    }

    private function translateBatchViaGemini(array $words, int $depth = 0): void
    {
        if (empty($words)) return;

        $wordList   = array_map(fn($w) => $w->word, $words);
        $userPrompt = "Provide dictionary entries for these " . count($wordList) . " German words:\n"
            . implode("\n", $wordList);

        $result = $this->ai->askFlashJson(self::SYSTEM_PROMPT, $userPrompt);

        if ($result === null) {
            $this->retryOrSplit($words, $depth, reason: 'empty_response');
            return;
        }

        // Find words genuinely missing from the response
        $wordMap = collect($words)->keyBy('word');
        $missing = [];

        foreach ($wordMap as $wordText => $word) {
            if (!isset($result[$wordText])) {
                $missing[] = $word;
            } else {
                $this->saveTranslation($word, $result[$wordText]);
            }
        }

        if (!empty($missing)) {
            Log::channel('import')->warning('WordSemanticTranslator: words missing from batch response', [
                'missing_count' => count($missing),
                'batch_size'    => count($words),
            ]);
            $this->retryOrSplit($missing, $depth, reason: 'missing_from_response');
        }
    }

    private function retryOrSplit(array $words, int $depth, string $reason): void
    {
        // Small remainder (e.g. 1-4 words missing from a batch response) —
        // just try it once as its own request, no further splitting logic needed.
        if (count($words) <= self::MIN_SPLIT_SIZE) {
            if ($depth === 0) {
                $this->translateBatchViaGemini($words, depth: 1);
                return;
            }

            Log::channel('import')->error('WordSemanticTranslator: giving up on small failing batch, deferring to next run', [
                'words'  => array_map(fn($w) => $w->word, $words),
                'reason' => $reason,
            ]);
            $this->retried += count($words);
            return;
        }

        if ($depth === 0) {
            Log::channel('import')->warning('WordSemanticTranslator: batch failed, retrying once as-is', [
                'batch_size' => count($words),
                'reason'     => $reason,
            ]);
            $this->translateBatchViaGemini($words, depth: 1);
            return;
        }

        $half       = intdiv(count($words), 2);
        $firstHalf  = array_slice($words, 0, $half);
        $secondHalf = array_slice($words, $half);

        Log::channel('import')->warning('WordSemanticTranslator: splitting batch in half after repeated failure', [
            'original_size' => count($words),
            'reason'        => $reason,
        ]);

        $this->translateBatchViaGemini($firstHalf, depth: 0);
        $this->translateBatchViaGemini($secondHalf, depth: 0);
    }
}
