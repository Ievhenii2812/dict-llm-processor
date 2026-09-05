<?php

namespace App\Services\Import;

use App\Enums\PartOfSpeech;
use App\Models\GroupWord;
use App\Models\Word;
use App\Models\WordGroup;
use Illuminate\Support\Facades\Log;

/**
 * Categorizes German words into thematic groups using Gemini Pro via Vertex AI.
 *
 * Uses Pro model for accurate semantic understanding of word context.
 * CEFR level assignment is handled separately by CefrAssignmentService (Flash).
 *
 * Batch size: 40 words (Pro model, balance between cost and quality)
 * Idempotent: only processes words without a thematic group
 * Validation: missing words in response are retried individually
 */
class CategorizationService
{
    private int   $processed   = 0;
    private int   $categorized = 0;
    private int   $retried     = 0;
    private array $groupCache  = [];

    private const int BATCH_SIZE = 40;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
You are a German vocabulary categorization assistant.
Assign each word to exactly one thematic group, or null if the word is general vocabulary.

THEMATIC GROUPS:
- arbeit_beruf: work, career, employment, professions, workplace, business, economy
- umwelt_natur: nature, environment, ecology, animals, plants, weather, geography, seasons
- gesundheit_medizin: health, medicine, body parts, illness, treatment, hospital, fitness, nutrition
- reisen_verkehr: travel, transport, vehicles, tourism, directions, traffic
- bildung_studium: education, school, university, learning, science, research, knowledge
- gesellschaft_politik: society, politics, law, government, social issues, culture, history
- freizeit_unterhaltung: leisure, entertainment, sports, hobbies, art, music, games, cinema
- beziehungen_familie: relationships, family, emotions, social interactions, love, friendship
- konsum_geld: shopping, money, finance, products, prices, banking, trade
- wohnen_alltag: home, daily life, food, cooking, household items, routine, furniture

RULES:
- Assign null for function words (articles, prepositions, conjunctions, pronouns)
- Assign null if word is too general to fit one category clearly
- You MUST return a result for EVERY word in the input — no omissions
- Respond ONLY with valid JSON, no markdown

RESPONSE FORMAT:
{
  "results": [
    {"word": "Arzt", "group_code": "gesundheit_medizin"},
    {"word": "und", "group_code": null},
    {"word": "Bundesregierung", "group_code": "gesellschaft_politik"}
  ]
}
PROMPT;

    public function __construct(
        private readonly VertexAIService $vertex,
    ) {}

    public function run(): void
    {
        Log::channel('import')->info('=== CategorizationService started ===');

        $this->groupCache = WordGroup::pluck('external_id', 'group_code')->toArray();

        $total = Word::whereDoesntHave('groups')->count();
        Log::channel('import')->info('Words without category', ['count' => $total]);

        Word::whereDoesntHave('groups')
            ->chunk(self::BATCH_SIZE, function ($words) {
                $this->processBatch($words->all());
            });

        Log::channel('import')->info('=== CategorizationService finished ===', [
            'processed'   => $this->processed,
            'categorized' => $this->categorized,
            'retried'     => $this->retried,
        ]);
    }

    private function processBatch(array $words): void
    {
        $wordList = array_map(function ($word) {
            $posName = $word->part_of_speech instanceof PartOfSpeech
                ? $word->part_of_speech->name
                : 'Unknown';
            return "{$word->word} ({$posName})";
        }, $words);

        $userPrompt = "Categorize these " . count($wordList) . " German words:\n"
            . implode("\n", $wordList);

        $result = $this->vertex->askProJson(self::SYSTEM_PROMPT, $userPrompt);

        if ($result === null || empty($result['results'])) {
            Log::channel('import')->warning('CategorizationService: no result, retrying individually', [
                'batch_size' => count($words),
            ]);
            foreach ($words as $word) {
                $this->processSingle($word);
                $this->retried++;
            }
            $this->processed += count($words);
            return;
        }

        // Build lookup: word → group_code
        $assignments = [];
        foreach ($result['results'] as $item) {
            if (!empty($item['word'])) {
                $wordKey = trim(preg_replace('/\s*\(.+\)$/', '', $item['word']));
                $assignments[$wordKey] = $item['group_code'] ?? null;
            }
        }

        // Validate — find missing words
        $wordMap = collect($words)->keyBy('word');
        $missing = [];

        foreach ($wordMap as $wordText => $wordModel) {
            if (!array_key_exists($wordText, $assignments)) {
                $missing[] = $wordModel;
            }
        }

        if (!empty($missing)) {
            Log::channel('import')->warning('CategorizationService: missing words, retrying', [
                'missing_count' => count($missing),
            ]);
            foreach ($missing as $wordModel) {
                $this->processSingle($wordModel);
                $this->retried++;
            }
        }

        // Apply assignments
        foreach ($wordMap as $wordText => $word) {
            $groupCode = $assignments[$wordText] ?? null;
            if ($groupCode !== null && isset($this->groupCache[$groupCode])) {
                GroupWord::firstOrCreate([
                    'external_word_id'       => $word->external_id,
                    'external_word_group_id' => $this->groupCache[$groupCode],
                ]);
                $this->categorized++;
            }
            $this->processed++;
        }

        if ($this->processed % 1000 === 0) {
            Log::channel('import')->info('Categorization progress', [
                'processed'   => $this->processed,
                'categorized' => $this->categorized,
                'retried'     => $this->retried,
            ]);
        }
    }

    private function processSingle(Word $word): void
    {
        $posName    = $word->part_of_speech instanceof PartOfSpeech
            ? $word->part_of_speech->name
            : 'Unknown';
        $userPrompt = "Categorize this German word:\n{$word->word} ({$posName})";
        $result     = $this->vertex->askProJson(self::SYSTEM_PROMPT, $userPrompt);

        if ($result === null || empty($result['results'])) {
            return;
        }

        $groupCode = $result['results'][0]['group_code'] ?? null;

        if ($groupCode !== null && isset($this->groupCache[$groupCode])) {
            GroupWord::firstOrCreate([
                'external_word_id'       => $word->external_id,
                'external_word_group_id' => $this->groupCache[$groupCode],
            ]);
            $this->categorized++;
        }
    }
}
