<?php

namespace App\Console\Commands;

use App\Services\Import\ExampleFillerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:fill-examples')]
#[Description('Fill words with < 5 examples: Tatoeba first, AI provider fallback')]
class FillExamples extends Command
{
    public function handle(): int
    {
        $this->info('Filling examples: Tatoeba → ' . config('import.ai_provider') . ' fallback...');

        $service = app(ExampleFillerService::class);

        $service->run();

        $this->info('Done. Details: storage/logs/import/');

        return Command::SUCCESS;
    }
}
