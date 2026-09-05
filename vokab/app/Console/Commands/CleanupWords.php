<?php

namespace App\Console\Commands;

use App\Services\Import\CleanupService;
use App\Services\Import\SpacyService;
use App\Services\Import\VertexAIService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:cleanup')]
#[Description('Постобработка базы: удаление мусора, мерж дублей')]
class CleanupWords extends Command
{
    public function handle(): int
    {
        $this->info('Запуск очистки базы...');
        $this->info('Порядок: имена собственные → дубли регистра → служебные омонимы → дубли частей речи');
        $this->info('');

        $service = new CleanupService(
            spacy:  app(SpacyService::class),
            vertex: app(VertexAIService::class),
        );

        $service->run();

        $this->info('');
        $this->info('Готово. Подробности: storage/logs/import/');

        return Command::SUCCESS;
    }
}
