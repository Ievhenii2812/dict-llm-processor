<?php

namespace App\Console\Commands;

use App\Models\Example;
use App\Models\ImportState;
use App\Models\Phrase;
use App\Models\Word;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('import:status')]
#[Description('Показать текущий прогресс импорта')]
class ImportStatus extends Command
{
    public function handle(): int
    {
        $this->info('');
        $this->info('=== СТАТУС ИМПОРТА ===');
        $this->info('');

        $lastLine = ImportState::where('key', 'leipzig_last_physical_line')->value('value') ?? 0;
        $this->info("Файл Лейпцига: обработано до строки {$lastLine}");
        $this->info('');

        $totalWords        = Word::count();
        $translatedWords   = Word::whereNotNull('translation_en')->count();
        $untranslatedWords = $totalWords - $translatedWords;

        $this->table(
            ['', 'Кол-во'],
            [
                ['Всего слов',         $totalWords],
                ['Переведено',         $translatedWords],
                ['Ожидают перевода',   $untranslatedWords],
            ]
        );

        $this->info('');

        $totalExamples = Example::count();
        $aiExamples    = Example::where('is_ai_generated', true)->count();
        $realExamples  = $totalExamples - $aiExamples;

        $poorWords = DB::table('words')
            ->leftJoin('example_word', 'words.external_id', '=', 'example_word.external_word_id')
            ->select(DB::raw('COUNT(DISTINCT words.id) as cnt'))
            ->groupBy('words.id')
            ->havingRaw('COUNT(example_word.id) < 5')
            ->get()->count();

        $this->table(
            ['', 'Кол-во'],
            [
                ['Всего примеров',        $totalExamples],
                ['Из корпуса',            $realExamples],
                ['ИИ-генерированные',     $aiExamples],
                ['Слов с < 5 примерами',  $poorWords],
            ]
        );

        $this->info('');

        $totalPhrases      = Phrase::count();
        $translatedPhrases = Phrase::whereNotNull('meaning_en')->count();

        $this->table(
            ['', 'Кол-во'],
            [
                ['Всего идиом',       $totalPhrases],
                ['Обработано',        $translatedPhrases],
                ['Ожидают обработки', $totalPhrases - $translatedPhrases],
            ]
        );

        $this->info('');

        $categorized = DB::table('group_word')->distinct('external_word_id')->count();

        $this->table(
            ['', 'Кол-во'],
            [
                ['Слов с категорией',  $categorized],
                ['Слов без категории', $totalWords - $categorized],
            ]
        );

        $this->info('');

        return Command::SUCCESS;
    }
}
