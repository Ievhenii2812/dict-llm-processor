<?php

namespace App\Services\Import;

use App\Enums\PartOfSpeech;
use App\Models\ExampleWord;
use App\Models\Word;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Import\Contracts\AIProviderInterface;

/**
 * Post-import database cleanup.
 *
 * Execution order matters:
 *   1. Proper nouns (Problem 2) — remove garbage first
 *   2. Case duplicates (Problem 3) — merge der/Der
 *   3. Redundant homonyms (Problem 4) — merge service parts of speech
 *   4. POS duplicates (Problem 1) — Gemini Pro decides
 */
class CleanupService
{
    private int $deleted = 0;
    private int $merged  = 0;

    // Parts of speech where ALL homonyms matter
    private const array IMPORTANT_POS = [
        PartOfSpeech::Noun->value,
        PartOfSpeech::Verb->value,
        PartOfSpeech::Adjective->value,
        PartOfSpeech::Adverb->value,
        PartOfSpeech::Preposition->value,
    ];

    public function __construct(
        private readonly SpacyService        $spacy,
        private readonly AIProviderInterface $vertex,
    ) {}

    public function run(): void
    {
        Log::channel('import')->info('=== CleanupService started ===');

        $this->removeProperNouns();
        $this->mergeCaseDuplicates();
        $this->mergeRedundantHomonyms();
        $this->mergePartOfSpeechDuplicates();

        Log::channel('import')->info('=== CleanupService finished ===', [
            'deleted' => $this->deleted,
            'merged'  => $this->merged,
        ]);
    }

    // -------------------------------------------------------------------------
    // Problem 2: Proper nouns
    // -------------------------------------------------------------------------

    private function removeProperNouns(): void
    {
        Log::channel('import')->info('Cleanup: removing proper nouns...');

        $count = 0;

        // Only check words starting with uppercase — candidates for proper nouns
        Word::whereRaw("word ~ '^[A-ZÄÖÜ]'")->chunk(100, function ($words) use (&$count) {
            foreach ($words as $word) {
                $result = $this->spacy->analyze($word->word);

                if ($result->isProperNoun) {
                    $this->deleteWord($word);
                    $count++;
                }
            }
        });

        Log::channel('import')->info('Proper nouns removed', ['count' => $count]);
        $this->deleted += $count;
    }

    // -------------------------------------------------------------------------
    // Problem 3: Case duplicates (der/Der)
    // -------------------------------------------------------------------------

    private function mergeCaseDuplicates(): void
    {
        Log::channel('import')->info('Cleanup: merging case duplicates...');

        $count = 0;

        $duplicates = DB::table('words')
            ->selectRaw('LOWER(word) as lower_word, COUNT(*) as cnt')
            ->groupByRaw('LOWER(word)')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $row) {
            $words = Word::whereRaw('LOWER(word) = ?', [$row->lower_word])
                ->orderBy('frequency_rank', 'desc')
                ->get();

            if ($words->count() < 2) continue;

            $keeper = $this->chooseKeeper($words);
            $others = $words->where('id', '!=', $keeper->id);

            foreach ($others as $duplicate) {
                $keeper->increment('frequency_rank', $duplicate->frequency_rank ?? 0);
                $this->reattachExamples($duplicate->external_id, $keeper->external_id);
                $this->deleteWord($duplicate);
                $count++;
            }
        }

        Log::channel('import')->info('Case duplicates merged', ['count' => $count]);
        $this->merged += $count;
    }

    private function chooseKeeper($words): Word
    {
        // Prefer noun (always uppercase in German)
        $noun = $words->firstWhere('part_of_speech', PartOfSpeech::Noun->value);
        if ($noun) return $noun;

        // Otherwise prefer lowercase form
        $lowercase = $words->first(fn($w) => $w->word === mb_strtolower($w->word));
        return $lowercase ?? $words->first();
    }

    // -------------------------------------------------------------------------
    // Problem 4: Redundant homonyms for service parts of speech
    // -------------------------------------------------------------------------

    private function mergeRedundantHomonyms(): void
    {
        Log::channel('import')->info('Cleanup: merging redundant homonyms...');

        $count = 0;

        $candidates = DB::table('words')
            ->select('word')
            ->whereNotNull('homonym_index')
            ->groupBy('word')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get()
            ->pluck('word');

        foreach ($candidates as $wordText) {
            $homonyms = Word::where('word', $wordText)->get();

            // Skip if ANY homonym is an important part of speech
            $allServile = $homonyms->every(
                fn($w) => !in_array($w->part_of_speech, self::IMPORTANT_POS)
            );

            if (!$allServile) continue;

            $keeper = $homonyms->sortByDesc('frequency_rank')->first();
            $others = $homonyms->where('id', '!=', $keeper->id);

            $translations = $this->mergeTranslations($homonyms);

            foreach ($others as $homonym) {
                $keeper->increment('frequency_rank', $homonym->frequency_rank ?? 0);
                $this->reattachExamples($homonym->external_id, $keeper->external_id);
                $this->deleteWord($homonym);
                $count++;
            }

            $keeper->update([
                'homonym_index'  => null,
                'translation_en' => $translations['en'],
                'translation_ru' => $translations['ru'],
                'translation_uk' => $translations['uk'],
            ]);
        }

        Log::channel('import')->info('Redundant homonyms merged', ['count' => $count]);
        $this->merged += $count;
    }

    private function mergeTranslations($words): array
    {
        return [
            'en' => $words->pluck('translation_en')->filter()->unique()->implode(', ') ?: null,
            'ru' => $words->pluck('translation_ru')->filter()->unique()->implode(', ') ?: null,
            'uk' => $words->pluck('translation_uk')->filter()->unique()->implode(', ') ?: null,
        ];
    }

    // -------------------------------------------------------------------------
    // Problem 1: POS duplicates (Rolle x4) — Gemini Pro decides
    // -------------------------------------------------------------------------

    private function mergePartOfSpeechDuplicates(): void
    {
        Log::channel('import')->info('Cleanup: checking POS duplicates via Gemini Pro...');

        $count = 0;

        $candidates = DB::table('words as w1')
            ->join('words as w2', function ($join) {
                $join->on('w1.word', '=', 'w2.word')
                    ->on('w1.part_of_speech', '=', 'w2.part_of_speech')
                    ->on('w1.id', '<', 'w2.id');
            })
            ->select('w1.word')
            ->distinct()
            ->get()
            ->pluck('word');

        foreach ($candidates as $wordText) {
            $count += $this->resolvePosDuplicates($wordText);
        }

        Log::channel('import')->info('POS duplicates resolved', ['count' => $count]);
        $this->merged += $count;
    }

    private function resolvePosDuplicates(string $wordText): int
    {
        $merged = 0;

        $byPos = Word::where('word', $wordText)->get()->groupBy('part_of_speech');

        foreach ($byPos as $pos => $homonyms) {
            if ($homonyms->count() < 2) continue;

            $posName  = PartOfSpeech::from($pos)->name;
            $count    = $homonyms->count();

            $system = 'You are a German linguistics expert. Respond only with valid JSON, no markdown.';
            $prompt = <<<PROMPT
The German word "{$wordText}" appears {$count} times in our dictionary
with the same part of speech: {$posName}.

Are these duplicate entries (same word, same meaning) that should be merged,
or are they genuinely different homonyms?

Respond with:
{
  "decision": "merge" or "keep",
  "reason": "brief explanation in English"
}
PROMPT;

            $result   = $this->vertex->askProJson($system, $prompt);
            $decision = $result['decision'] ?? 'keep';

            if ($decision === 'merge') {
                $keeper = $homonyms->sortByDesc('frequency_rank')->first();
                $others = $homonyms->where('id', '!=', $keeper->id);

                foreach ($others as $duplicate) {
                    $keeper->increment('frequency_rank', $duplicate->frequency_rank ?? 0);
                    $this->reattachExamples($duplicate->external_id, $keeper->external_id);
                    $this->deleteWord($duplicate);
                    $merged++;
                }

                Log::channel('import')->debug('POS duplicates merged by Gemini', [
                    'word'   => $wordText,
                    'pos'    => $posName,
                    'reason' => $result['reason'] ?? '',
                ]);
            }
        }

        return $merged;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function reattachExamples(int $fromExternalId, int $toExternalId): void
    {
        $exampleIds = DB::table('example_word')
            ->where('external_word_id', $fromExternalId)
            ->whereNotIn('external_example_id', function ($query) use ($toExternalId) {
                $query->select('external_example_id')
                    ->from('example_word')
                    ->where('external_word_id', $toExternalId);
            })
            ->pluck('external_example_id');

        foreach ($exampleIds as $exampleId) {
            ExampleWord::firstOrCreate([
                'external_word_id'    => $toExternalId,
                'external_example_id' => $exampleId,
            ]);
        }
    }

    private function deleteWord(Word $word): void
    {
        $word->delete();

        Log::channel('import')->debug('Word deleted', [
            'word'        => $word->word,
            'external_id' => $word->external_id,
        ]);
    }
}
