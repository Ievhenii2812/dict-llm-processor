<?php

namespace App\Services\Import;

use App\Models\Example;
use App\Models\ExampleWord;
use App\Models\Word;
use Illuminate\Support\Facades\Log;

/**
 * Шаг 2.1 — Перекрёстная прошивка примеров.
 *
 * Берёт каждое предложение из examples, разбивает на леммы через Hunspell,
 * ищет совпадения в words и дописывает связи в example_word.
 *
 * Идемпотентна: повторный запуск не создаёт дублей (syncWithoutDetaching).
 */
class CrosslinkService
{
    private int $processedExamples = 0;
    private int $newLinks          = 0;

    public function __construct(
        private readonly SpacyService $hunspell,
    ) {}

    public function run(): void
    {
        Log::channel('import')->info('=== Crosslink started ===');

        // Загружаем все external_id слов в память для быстрого поиска
        // При 25k словах это ~400 КБ — приемлемо
        $wordMap = Word::pluck('external_id', 'word')->toArray();
        // $wordMap = ['arbeiten' => 5, 'Haus' => 12, ...]

        Log::channel('import')->info('Word map loaded', ['count' => count($wordMap)]);

        Example::chunk(500, function ($examples) use ($wordMap) {
            foreach ($examples as $example) {
                $this->processExample($example, $wordMap);
            }
        });

        Log::channel('import')->info('=== Crosslink finished ===', [
            'examples_processed' => $this->processedExamples,
            'new_links'          => $this->newLinks,
        ]);
    }

    private function processExample(Example $example, array $wordMap): void
    {
        $lemmas = $this->hunspell->lemmatizeSentence($example->sentence);

        $linkedIds = [];

        foreach ($lemmas as $lemma) {
            if (isset($wordMap[$lemma])) {
                $linkedIds[] = $wordMap[$lemma];
            }
        }

        if (empty($linkedIds)) {
            $this->processedExamples++;
            return;
        }

        // Получаем уже существующие связи для этого примера
        $existingIds = ExampleWord::where('external_example_id', $example->external_id)
            ->pluck('external_word_id')
            ->toArray();

        $newIds = array_diff($linkedIds, $existingIds);

        foreach ($newIds as $wordExternalId) {
            ExampleWord::create([
                'external_word_id'    => $wordExternalId,
                'external_example_id' => $example->external_id,
            ]);
            $this->newLinks++;
        }

        $this->processedExamples++;

        if ($this->processedExamples % 1000 === 0) {
            Log::channel('import')->info('Crosslink progress', [
                'examples' => $this->processedExamples,
                'links'    => $this->newLinks,
            ]);
        }
    }
}
