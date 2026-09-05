<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExampleFillerService
{
    private int $wordsProcessed        = 0;
    private int $examplesFromTatoeba   = 0;
    private int $examplesFromLeipzig   = 0;
    private int $examplesFromGemini    = 0;

    private const int MIN_EXAMPLES      = 5;
    private const int PROCESS_BATCH_SIZE = 15; // words per outer batch (Tatoeba + Leipzig collection)
    private const int GEMINI_BATCH_SIZE  = 25;

    private const string GEMINI_SYSTEM = <<<'PROMPT'
You are a German language teacher creating example sentences for B1/B2 vocabulary study.

For each word, generate the requested number of natural, clear German sentences
suitable for language learners.

Respond ONLY with valid JSON, no markdown. Keys are the words themselves,
values are arrays of sentence strings.

Example:
{
  "arbeiten": ["Ich arbeite jeden Tag.", "Sie arbeitet im Büro."],
  "Haus": ["Das Haus ist groß.", "Wir kaufen ein neues Haus."]
}
PROMPT;

    public function __construct(
        private readonly TatoebaParser          $tatoeba,
        private readonly LeipzigSentencesParser $leipzig,
        private readonly ExampleTranslator      $translator,
        private readonly WordSaver              $saver,
        private readonly VertexAIService        $vertex,
    ) {}

    public function run(): void
    {
        Log::channel('import')->info('=== ExampleFillerService started ===');

        $poorWords = DB::table('words')
            ->leftJoin('example_word', 'words.external_id', '=', 'example_word.external_word_id')
            ->select('words.id', 'words.external_id', 'words.word', 'words.homonym_index',
                DB::raw('COUNT(example_word.id) as example_count'))
            ->groupBy('words.id', 'words.external_id', 'words.word', 'words.homonym_index')
            ->havingRaw('COUNT(example_word.id) < ?', [self::MIN_EXAMPLES])
            ->get();

        Log::channel('import')->info('Words needing examples', ['count' => $poorWords->count()]);

        $stillNeeded = [];

        foreach ($poorWords->chunk(self::PROCESS_BATCH_SIZE) as $chunk) {
            $remainingList = $this->processTatoebaAndLeipzigBatch($chunk);
            $stillNeeded = array_merge($stillNeeded, $remainingList);
        }

        Log::channel('import')->info('Tatoeba + Leipzig pass complete', [
            'still_need_gemini' => count($stillNeeded),
        ]);

        foreach (array_chunk($stillNeeded, self::GEMINI_BATCH_SIZE) as $chunk) {
            $this->fillBatchWithGemini($chunk);
        }

        Log::channel('import')->info('=== ExampleFillerService finished ===', [
            'words_processed'       => $this->wordsProcessed,
            'examples_from_tatoeba' => $this->examplesFromTatoeba,
            'examples_from_leipzig' => $this->examplesFromLeipzig,
            'examples_from_gemini'  => $this->examplesFromGemini,
        ]);
    }

    /**
     * Processes one batch of words: Tatoeba first (already batched internally
     * — one call per word, but cheap/local), then collects ALL Leipzig
     * candidates across the whole batch and translates them in ONE set of
     * 3 HTTP calls (de-en/de-ru/de-uk) instead of 3 calls per word.
     *
     * @return array<array{external_id: int, word: string, needed: int}>
     */
    private function processTatoebaAndLeipzigBatch($chunk): array
    {
        // Step 1: Tatoeba (translations already included, no extra HTTP needed)
        $afterTatoeba = [];

        foreach ($chunk as $row) {
            $needed = self::MIN_EXAMPLES - $row->example_count;
            $added  = $this->tryTatoeba($row->external_id, $row->word, $needed);
            $remaining = $needed - $added;

            $afterTatoeba[] = [
                'external_id'   => $row->external_id,
                'word'          => $row->word,
                'needed'        => $remaining,
                'is_homonym'    => $row->homonym_index !== null,
            ];
        }

        // Step 2: collect Leipzig candidate sentences across ALL words in this batch
        $leipzigCandidates = []; // flat list of sentences to translate
        $sentenceOwners    = []; // parallel: which word index + within-word position each sentence belongs to
        $wordSentences     = []; // word index => [sentences]

        foreach ($afterTatoeba as $i => $item) {
            if ($item['needed'] <= 0 || $item['is_homonym'] || !$this->leipzig->isAvailable()) {
                $wordSentences[$i] = [];
                continue;
            }

            $candidates = $this->leipzig->findExamples($item['word']);
            $candidates = array_slice($candidates, 0, $item['needed']);
            $candidates = array_filter($candidates, fn($s) => mb_strlen($s) <= 500);

            $wordSentences[$i] = array_values($candidates);

            foreach ($wordSentences[$i] as $sentence) {
                $leipzigCandidates[] = $sentence;
                $sentenceOwners[]    = $i;
            }
        }

        // Step 3: ONE batched translation call for the whole collected pool
        $translations = [];
        if (!empty($leipzigCandidates)) {
            $translations = $this->translator->translateAllBatch($leipzigCandidates);
        }

        // Step 4: distribute translated sentences back to their words and save
        $stillNeeded = [];
        $cursor = 0;

        foreach ($afterTatoeba as $i => $item) {
            $sentences = $wordSentences[$i];
            $addedLeipzig = 0;

            foreach ($sentences as $sentence) {
                $t = $translations[$cursor] ?? ['en' => null, 'ru' => null, 'uk' => null];
                $cursor++;

                $this->saver->saveOneExampleWithTranslations(
                    wordExternalId: $item['external_id'],
                    sentence:       $sentence,
                    translations:   $t,
                    isAiGenerated:  false,
                );
                $addedLeipzig++;
                $this->examplesFromLeipzig++;
            }

            $remaining = $item['needed'] - $addedLeipzig;

            if ($remaining > 0) {
                $stillNeeded[] = [
                    'external_id' => $item['external_id'],
                    'word'        => $item['word'],
                    'needed'      => $remaining,
                ];
            }

            $this->wordsProcessed++;
        }

        if ($this->wordsProcessed % 2000 === 0) {
            Log::channel('import')->info('Fill-examples progress', [
                'processed' => $this->wordsProcessed,
                'tatoeba'   => $this->examplesFromTatoeba,
                'leipzig'   => $this->examplesFromLeipzig,
            ]);
        }

        return $stillNeeded;
    }

    private function tryTatoeba(int $externalId, string $word, int $needed): int
    {
        if ($needed <= 0 || !$this->tatoeba->isAvailable()) return 0;

        $added = 0;
        $tatoebaExamples = $this->tatoeba->findExamples($word);

        foreach (array_slice($tatoebaExamples, 0, $needed) as $ex) {
            if (mb_strlen($ex['de']) > 500) continue;

            $this->saver->saveOneExampleWithTranslations(
                wordExternalId: $externalId,
                sentence:       $ex['de'],
                translations:   ['en' => $ex['en'], 'ru' => $ex['ru'], 'uk' => $ex['uk']],
                isAiGenerated:  false,
            );
            $added++;
            $this->examplesFromTatoeba++;
        }

        return $added;
    }

    /**
     * @param array<array{external_id: int, word: string, needed: int}> $chunk
     */
    private function fillBatchWithGemini(array $chunk): void
    {
        $requestLines = array_map(
            fn($item) => "{$item['word']}: {$item['needed']} sentences",
            $chunk
        );

        $userPrompt = "Generate B1/B2 example sentences for these German words:\n"
            . implode("\n", $requestLines);

        $result = $this->vertex->askFlashJson(self::GEMINI_SYSTEM, $userPrompt);

        if ($result === null) {
            Log::channel('import')->warning('ExampleFiller: Gemini batch failed, retrying individually', [
                'batch_size' => count($chunk),
            ]);
            foreach ($chunk as $item) {
                $this->fillSingleWithGemini($item['external_id'], $item['word'], $item['needed']);
            }
            return;
        }

        foreach ($chunk as $item) {
            $sentences = $result[$item['word']] ?? null;

            if (empty($sentences) || !is_array($sentences)) {
                $this->fillSingleWithGemini($item['external_id'], $item['word'], $item['needed']);
                continue;
            }

            foreach (array_slice($sentences, 0, $item['needed']) as $sentence) {
                if (empty($sentence) || !is_string($sentence)) continue;
                if (mb_strlen($sentence) > 500) continue;

                $this->saver->saveOneExample(
                    wordExternalId: $item['external_id'],
                    sentence:       $sentence,
                    isAiGenerated:  true,
                );
                $this->examplesFromGemini++;
            }
        }
    }

    private function fillSingleWithGemini(int $externalId, string $word, int $needed): void
    {
        $prompt = "Generate {$needed} B1/B2 German example sentences using the word \"{$word}\".\n"
            . "Respond with: {\"{$word}\": [\"sentence1\", \"sentence2\"]}";

        $result = $this->vertex->askFlashJson(self::GEMINI_SYSTEM, $prompt);
        if ($result === null) return;

        $sentences = $result[$word] ?? null;
        if (empty($sentences) || !is_array($sentences)) return;

        foreach (array_slice($sentences, 0, $needed) as $sentence) {
            if (empty($sentence) || !is_string($sentence)) continue;
            if (mb_strlen($sentence) > 500) continue;

            $this->saver->saveOneExample(
                wordExternalId: $externalId,
                sentence:       $sentence,
                isAiGenerated:  true,
            );
            $this->examplesFromGemini++;
        }
    }
}
