<?php

namespace App\Services\Import;

use App\Enums\CefrLevel;
use App\Models\Word;
use Illuminate\Support\Facades\Log;
use App\Services\Import\Contracts\AIProviderInterface;

/**
 * Assigns CEFR levels (A1-C2) to German words using Gemini Flash via Vertex AI.
 *
 * Uses flat JSON response format {"word": "level"} instead of
 * {"results": [{"word": "...", "cefr_level": "..."}]} —
 * reduces output tokens by ~3x, prevents response truncation.
 *
 * Batch size: 100 words
 * Idempotent: only processes words where cefr_level IS NULL
 * Validation: missing words retried individually
 */
class CefrAssignmentService
{
    private int $processed = 0;
    private int $assigned  = 0;
    private int $retried   = 0;

    private const int BATCH_SIZE = 100;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
You are a German language level classification assistant.
Assign a CEFR level to each German word based on when it is typically learned.

CEFR LEVELS:
- A1: absolute basics (sein, haben, Haus, gut, ja)
- A2: everyday topics (essen, kaufen, Straße, krank)
- B1: broader topics (Arbeit, Reise, Meinung, unregelmäßige Verben)
- B2: abstract/nuanced (Politik, Wirtschaft, abstrakte Begriffe)
- C1: advanced/specialized (Fachvokabular, seltene Wörter, formell)
- C2: near-native (archaisch, hochspezialisiert, akademisch)

Respond ONLY with a flat JSON object where keys are words and values are CEFR levels.
No nested objects, no arrays, no extra fields.

Example:
{
  "Haus": "A1",
  "arbeiten": "A2",
  "Bundesregierung": "B2",
  "Kontraktualismus": "C2"
}
PROMPT;

    public function __construct(
        private readonly AIProviderInterface $vertex,
    ) {}

    public function run(): void
    {
        Log::channel('import')->info('=== CefrAssignmentService started ===');

        $total = Word::whereNull('cefr_level')->count();
        Log::channel('import')->info('Words without CEFR level', ['count' => $total]);

        Word::whereNull('cefr_level')
            ->chunkById(self::BATCH_SIZE, function ($words) {
                $this->processBatch($words->all());
            });

        Log::channel('import')->info('=== CefrAssignmentService finished ===', [
            'processed' => $this->processed,
            'assigned'  => $this->assigned,
            'retried'   => $this->retried,
        ]);
    }

    private function processBatch(array $words): void
    {
        $wordList   = array_map(fn($w) => $w->word, $words);
        $userPrompt = "Assign CEFR levels to these " . count($wordList) . " German words:\n"
            . implode("\n", $wordList);

        $result = $this->vertex->askFlashJson(self::SYSTEM_PROMPT, $userPrompt);

        if ($result === null) {
            Log::channel('import')->warning('CefrAssignment: no result, retrying individually', [
                'batch_size' => count($words),
            ]);
            foreach ($words as $word) {
                $this->processSingle($word);
                $this->retried++;
            }
            $this->processed += count($words);
            return;
        }

        // Flat format: {"word": "level", ...}
        // Validate — find missing words
        $wordMap = collect($words)->keyBy('word');
        $missing = [];

        foreach ($wordMap as $wordText => $wordModel) {
            if (!array_key_exists($wordText, $result)) {
                $missing[] = $wordModel;
            }
        }

        if (!empty($missing)) {
            Log::channel('import')->warning('CefrAssignment: missing words, retrying', [
                'missing_count' => count($missing),
            ]);
            foreach ($missing as $wordModel) {
                $this->processSingle($wordModel);
                $this->retried++;
            }
        }

        // Apply assignments
        foreach ($wordMap as $wordText => $word) {
            $cefrString = $result[$wordText] ?? null;
            if (!empty($cefrString)) {
                $cefrEnum = $this->parseCefrLevel((string) $cefrString);
                if ($cefrEnum !== null) {
                    $word->update(['cefr_level' => $cefrEnum->value]);
                    $this->assigned++;
                }
            }
            $this->processed++;
        }

        if ($this->processed % 5000 === 0) {
            Log::channel('import')->info('CefrAssignment progress', [
                'processed' => $this->processed,
                'assigned'  => $this->assigned,
                'retried'   => $this->retried,
            ]);
        }
    }

    private function processSingle(Word $word): void
    {
        $userPrompt = "Assign CEFR level to this German word:\n{$word->word}";
        $result     = $this->vertex->askFlashJson(self::SYSTEM_PROMPT, $userPrompt);

        if ($result === null) return;

        // Try flat format first
        $cefrString = $result[$word->word] ?? null;

        // Fallback: maybe model returned {"results": [...]} anyway
        if ($cefrString === null && !empty($result['results'][0]['cefr_level'])) {
            $cefrString = $result['results'][0]['cefr_level'];
        }

        if (!empty($cefrString)) {
            $cefrEnum = $this->parseCefrLevel((string) $cefrString);
            if ($cefrEnum !== null) {
                $word->update(['cefr_level' => $cefrEnum->value]);
                $this->assigned++;
            }
        }
    }

    private function parseCefrLevel(string $level): ?CefrLevel
    {
        return match (strtoupper(trim($level))) {
            'A1'    => CefrLevel::A1,
            'A2'    => CefrLevel::A2,
            'B1'    => CefrLevel::B1,
            'B2'    => CefrLevel::B2,
            'C1'    => CefrLevel::C1,
            'C2'    => CefrLevel::C2,
            default => null,
        };
    }
}
