<?php

namespace App\Console\Commands;

use App\Models\ImportState;
use App\Services\Import\ImportOrchestrator;
use App\Services\Import\SpacyService;
use App\Services\Import\WiktionaryDumpParser;
use App\Services\Import\WordSaver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:run {--limit=25 : Количество новых слов за сессию} {--reset : Сбросить прогресс и начать с начала файла} {--source=news : Источник: news, web}')]
#[Description('Запустить сессию импорта слов из Лейпцигского корпуса')]
class RunImport extends Command
{
    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $source   = $this->option('source');
        $filePath = match ($source) {
            'web'   => config('import.leipzig_web_file'),
            default => config('import.leipzig_file'),
        };

        if ($this->option('reset')) {
            $key = match ($source) {
                'web'   => 'leipzig_last_physical_line_web',
                default => 'leipzig_last_physical_line_news',
            };
            ImportState::where('key', $key)->delete();
        }

        $this->info("Запуск импорта: лимит {$limit} слов");

        $orchestrator = new ImportOrchestrator(
            hunspell:     app(SpacyService::class),
            wiktionary:   app(WiktionaryDumpParser::class),
            saver:        app(WordSaver::class),
            filePath:     $filePath,
            sessionLimit: $limit,
            source:       $source,
        );

        $orchestrator->run();

        $this->info('Сессия завершена. Подробности: storage/logs/import/');

        return Command::SUCCESS;
    }
}
