<?php

namespace App\Console\Commands;

use App\Services\Import\SpacyService;
use App\Services\Import\CrosslinkService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:crosslink')]
#[Description('Шаг 2.1 — Перекрёстная прошивка примеров со словами')]
class CrosslinkExamples extends Command
{
    public function handle(): int
    {
        $this->info('Запуск перекрёстной прошивки...');

        $service = new CrosslinkService(app(SpacyService::class));
        $service->run();

        $this->info('Готово. Подробности: storage/logs/import/');

        return Command::SUCCESS;
    }
}
