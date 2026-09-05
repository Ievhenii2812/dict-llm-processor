<?php

namespace App\Services\Import;

use App\Models\Word;
use Illuminate\Support\Facades\Log;

/**
 * Identifies and removes garbage words using Gemini Flash via Vertex AI.
 *
 * Batch size 200: simple classification task (no generation),
 * model checks each word independently — safe at high batch sizes.
 *
 * Validation: if response contains fewer results than sent,
 * missing words are retried individually.
 *
 * Idempotent: safe to restart — deleted words won't reappear.
 */
class GarbageCleanupService
{
    private int $checked = 0;
    private int $removed = 0;
    private int $retried = 0;

    private const int BATCH_SIZE = 100;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
You are a German dictionary quality control assistant.
Identify words that should NOT be in a German vocabulary learning dictionary.

Mark as garbage=true:
- Proper nouns: names of people, cities, countries, brands, organizations (Bayern, Angela, BMW, Berlin)
- Abbreviations and codes: EU, bzw, ca, usw, GmbH, AG, Nr, Str
- Technical codes that are not real German words
- Clearly malformed or misspelled words
- Single characters or meaningless strings

Mark as garbage=false:
- Real German words: nouns, verbs, adjectives, adverbs, prepositions, conjunctions
- Valid compound words (Bundesregierung, Arbeitslosigkeit, Krankenhaus)
- Common words even if simple (und, der, sein, groß, gut)

You MUST return a result for EVERY word in the input. No omissions.

Respond ONLY with this exact JSON format, no other fields:
{"results": [{"word": "Bayern", "garbage": true}, {"word": "arbeiten", "garbage": false}]}
PROMPT;

    public function __construct(
        private readonly VertexAIService $vertex,
    ) {}

    public function run(): void
    {
        Log::channel('import')->info('=== GarbageCleanupService started ===');

        $total = Word::count();
        Log::channel('import')->info('Total words to check', ['count' => $total]);

        Word::chunk(self::BATCH_SIZE, function ($words) {
            $this->processBatch($words->all());
        });

        Log::channel('import')->info('=== GarbageCleanupService finished ===', [
            'checked' => $this->checked,
            'removed' => $this->removed,
            'retried' => $this->retried,
        ]);
    }

    private function processBatch(array $words): void
    {
        $wordList   = array_map(fn($w) => $w->word, $words);
        $userPrompt = "Check these " . count($wordList) . " words:\n" . implode("\n", $wordList);

        $result = $this->vertex->askFlashJson(self::SYSTEM_PROMPT, $userPrompt);

        if ($result === null || empty($result['results'])) {
            Log::channel('import')->warning('GarbageCleanup: no result, retrying individually', [
                'batch_size' => count($words),
            ]);
            // Retry entire batch individually
            foreach ($words as $word) {
                $this->processSingle($word);
            }
            $this->checked += count($words);
            return;
        }

        // Build lookup: word → garbage bool
        $garbage = [];
        foreach ($result['results'] as $item) {
            if (!empty($item['word'])) {
                $garbage[$item['word']] = (bool) ($item['garbage'] ?? false);
            }
        }

        // Validate — find missing words
        $wordMap = collect($words)->keyBy('word');
        $missing = [];

        foreach ($wordMap as $wordText => $wordModel) {
            if (!array_key_exists($wordText, $garbage)) {
                $missing[] = $wordModel;
            }
        }

        // Retry missing words individually
        if (!empty($missing)) {
            Log::channel('import')->warning('GarbageCleanup: missing words in response, retrying', [
                'missing_count' => count($missing),
                'batch_size'    => count($words),
            ]);

            foreach ($missing as $wordModel) {
                $this->processSingle($wordModel);
                $this->retried++;
            }
        }

        // Apply garbage decisions
        foreach ($garbage as $wordText => $isGarbage) {
            if ($isGarbage && isset($wordMap[$wordText])) {
                $wordMap[$wordText]->delete();
                $this->removed++;

                Log::channel('import')->debug('Garbage word removed', [
                    'word' => $wordText,
                ]);
            }
        }

        $this->checked += count($words);

        if ($this->checked % 10000 === 0) {
            Log::channel('import')->info('Garbage cleanup progress', [
                'checked' => $this->checked,
                'removed' => $this->removed,
                'retried' => $this->retried,
            ]);
        }
    }

    private function processSingle(Word $word): void
    {
        $userPrompt = "Check this word:\n{$word->word}";
        $result     = $this->vertex->askFlashJson(self::SYSTEM_PROMPT, $userPrompt);

        if ($result === null || empty($result['results'])) {
            return;
        }

        $item = $result['results'][0] ?? null;

        if ($item !== null && !empty($item['garbage'])) {
            $word->delete();
            $this->removed++;
        }
    }
}
