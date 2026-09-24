<?php

namespace App\Services\Import;

use App\Enums\PartOfSpeech;
use App\Models\Example;
use App\Models\ExampleWord;
use App\Models\Noun;
use App\Models\Phrase;
use App\Models\Verb;
use App\Models\VerbPreposition;
use App\Models\Adjective;
use App\Models\Word;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сохраняет одно слово со всеми связанными данными в одной транзакции.
 *
 * Принимает массив WiktionaryEntry (один элемент = обычное слово,
 * несколько = омонимы).
 *
 * @param WiktionaryEntry[] $entries
 */
class WordSaver
{
    public function __construct() {}

    // -------------------------------------------------------------------------
    // Точка входа
    // -------------------------------------------------------------------------

    /**
     * Сохраняет все омонимы одного слова.
     * Возвращает количество реально сохранённых записей.
     */
    public function save(int $frequencyRank, array $entries): int
    {
        $saved = 0;

        foreach ($entries as $entry) {
            $ok = $this->saveEntry($frequencyRank, $entry);
            if ($ok) $saved++;
        }

        return $saved;
    }

    // -------------------------------------------------------------------------
    // Сохранение одного омонима
    // -------------------------------------------------------------------------

    private function saveEntry(int $frequencyRank, WiktionaryEntry $entry): bool
    {
        try {
            DB::transaction(function () use ($frequencyRank, $entry) {

                // ── Шаг 1: words ────────────────────────────────────────────
                $externalId = $this->nextExternalId();

                $word = Word::create([
                    'external_id'     => $externalId,
                    'word'            => $entry->word,
                    'frequency_rank'  => $frequencyRank,
                    'part_of_speech'  => $entry->partOfSpeech->value,
                    'homonym_index'   => $entry->homonymIndex,
                    // translation_* заполнит Этап 2
                ]);

                Log::channel('import')->info('Word saved', [
                    'word'         => $entry->word,
                    'external_id'  => $externalId,
                    'pos'          => $entry->partOfSpeech->name,
                    'homonym'      => $entry->homonymIndex,
                ]);

                // ── Шаг 2: грамматические таблицы ───────────────────────────
                $this->saveGrammar($externalId, $entry);

                // ── Шаг 3: примеры ──────────────────────────────────────────
                $this->saveExamples($externalId, $entry->examples);

                // ── Шаг 4: идиомы ───────────────────────────────────────────
                $this->saveIdioms($entry->idioms);
            });

            return true;

        } catch (\Throwable $e) {
            Log::channel('import')->error('WordSaver transaction failed', [
                'word'  => $entry->word,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Грамматика
    // -------------------------------------------------------------------------

    private function saveGrammar(int $externalId, WiktionaryEntry $entry): void
    {
        match ($entry->partOfSpeech) {
            PartOfSpeech::Noun      => $this->saveNoun($externalId, $entry),
            PartOfSpeech::Verb      => $this->saveVerb($externalId, $entry),
            PartOfSpeech::Adjective => $this->saveAdjective($externalId, $entry),
            default                 => null,
        };
    }

    private function saveNoun(int $externalId, WiktionaryEntry $entry): void
    {
        if ($entry->gender === null) {
            Log::channel('import')->debug('Noun without gender, skipping noun table', [
                'word' => $entry->word,
            ]);
            return;
        }

        Noun::create([
            'external_word_id' => $externalId,
            'gender'           => $entry->gender,
            'plural'           => $entry->plural ?? '',
        ]);
    }

    private function saveVerb(int $externalId, WiktionaryEntry $entry): void
    {
        Verb::create([
            'external_word_id' => $externalId,
            'is_regular'       => $entry->isRegular,
            'preterite'        => $entry->preterite,
            'participle_ii'    => $entry->participleII,
            'aux_verb'         => $entry->auxVerb,
        ]);

        foreach ($entry->prepositions as $prep) {
            VerbPreposition::create([
                'external_word_id' => $externalId,
                'preposition'      => $prep['preposition'],
                'noun_case'        => $prep['case'],
                // translation_* заполнит Этап 2
            ]);
        }
    }

    private function saveAdjective(int $externalId, WiktionaryEntry $entry): void
    {
        Adjective::create([
            'external_word_id' => $externalId,
            'comparative'      => $entry->comparative,
            'superlative'      => $entry->superlative,
        ]);
    }

    // -------------------------------------------------------------------------
    // Примеры
    // -------------------------------------------------------------------------

    /**
     * @param string[] $sentences
     */
    private function saveExamples(int $wordExternalId, array $sentences): void
    {
        if (empty($sentences)) {
            Log::channel('import')->debug('No examples from Wiktionary', [
                'word_external_id' => $wordExternalId,
            ]);
            return;
        }

        foreach ($sentences as $sentence) {
            $this->saveOneExample($wordExternalId, $sentence, isAiGenerated: false);
        }
    }

    public function saveOneExample(
        int    $wordExternalId,
        string $sentence,
        bool   $isAiGenerated = false,
    ): void {
        DB::transaction(function () use ($wordExternalId, $sentence, $isAiGenerated) {
            $translations = ['en' => null, 'ru' => null, 'uk' => null];

            $existing = Example::where('sentence', $sentence)->first();

            if ($existing !== null) {
                $exampleExternalId = $existing->external_id;
            } else {
                $externalId = $this->nextExampleExternalId();

                $example = Example::create([
                    'external_id'    => $externalId,
                    'sentence'       => $sentence,
                    'is_ai_generated' => $isAiGenerated,
                    'translation_en' => $translations['en'],
                    'translation_ru' => $translations['ru'],
                    'translation_uk' => $translations['uk'],
                ]);

                $exampleExternalId = $example->external_id;

                Log::channel('import')->debug('Example saved', [
                    'external_id'    => $externalId,
                    'is_ai_generated' => $isAiGenerated,
                    'sentence'       => mb_substr($sentence, 0, 60),
                ]);
            }

            ExampleWord::firstOrCreate([
                'external_word_id'    => $wordExternalId,
                'external_example_id' => $exampleExternalId,
            ]);
        });
    }

    /**
     * Saves an example sentence with pre-existing translations (from Tatoeba).
     * Unlike saveOneExample(), does NOT call ExampleTranslator —
     * translations are already provided.
     *
     * @param array{en: string|null, ru: string|null, uk: string|null} $translations
     */
    public function saveOneExampleWithTranslations(
        int    $wordExternalId,
        string $sentence,
        array  $translations,
        bool   $isAiGenerated = false,
    ): void {
        DB::transaction(function () use ($wordExternalId, $sentence, $translations, $isAiGenerated) {
            $existing = Example::where('sentence', $sentence)->first();

            if ($existing !== null) {
                $exampleExternalId = $existing->external_id;
            } else {
                $externalId = $this->nextExampleExternalId();

                $example = Example::create([
                    'external_id'     => $externalId,
                    'sentence'        => $sentence,
                    'is_ai_generated' => $isAiGenerated,
                    'translation_en'  => $translations['en'] ?? null,
                    'translation_ru'  => $translations['ru'] ?? null,
                    'translation_uk'  => $translations['uk'] ?? null,
                ]);

                $exampleExternalId = $example->external_id;

                Log::channel('import')->debug('Example saved (with translations)', [
                    'external_id'    => $externalId,
                    'is_ai_generated' => $isAiGenerated,
                    'has_en'         => !empty($translations['en']),
                    'has_ru'         => !empty($translations['ru']),
                    'has_uk'         => !empty($translations['uk']),
                    'sentence'       => mb_substr($sentence, 0, 60),
                ]);
            }

            ExampleWord::firstOrCreate([
                'external_word_id'    => $wordExternalId,
                'external_example_id' => $exampleExternalId,
            ]);
        });
    }

    // -------------------------------------------------------------------------
    // Идиомы
    // -------------------------------------------------------------------------

    /**
     * @param array<array{phrase: string, meaning_de: string}> $idioms
     */
    private function saveIdioms(array $idioms): void
    {
        if (empty($idioms)) return;

        foreach ($idioms as $idiom) {
            $this->saveOneIdiom($idiom['phrase'], $idiom['meaning_de']);
        }
    }

    private function saveOneIdiom(string $phrase, string $meaningDe): void
    {
        DB::transaction(function () use ($phrase, $meaningDe) {
            $exists = Phrase::whereRaw('phrase = ?', [$phrase])->exists();

            if ($exists) {
                Log::channel('import')->debug('Phrase already exists, skipped', [
                    'phrase' => mb_substr($phrase, 0, 60),
                ]);
                return;
            }

            $externalId = $this->nextPhraseExternalId();

            Phrase::create([
                'external_id' => $externalId,
                'phrase'      => $phrase,
                'meaning_de'  => $meaningDe,
            ]);

            Log::channel('import')->debug('Phrase saved', [
                'external_id' => $externalId,
                'phrase'      => mb_substr($phrase, 0, 60),
            ]);
        });
    }

    // -------------------------------------------------------------------------
    // Генерация external_id
    // -------------------------------------------------------------------------

    /**
     * Генерирует следующий external_id для таблицы words.
     * Блокировка строки через lockForUpdate() внутри транзакции
     * защищает от race condition при параллельном запуске.
     */
    private function nextExternalId(): int
    {
        $max = DB::table('words')
            ->lockForUpdate()
            ->orderByDesc('external_id')
            ->limit(1)
            ->value('external_id');

        return ($max ?? 0) + 1;
    }

    private function nextExampleExternalId(): int
    {
        $max = DB::table('examples')
            ->lockForUpdate()
            ->orderByDesc('external_id')
            ->limit(1)
            ->value('external_id');

        return ($max ?? 0) + 1;
    }

    private function nextPhraseExternalId(): int
    {
        $max = DB::table('phrases')
            ->lockForUpdate()
            ->orderByDesc('external_id')
            ->limit(1)
            ->value('external_id');

        return ($max ?? 0) + 1;
    }
}
