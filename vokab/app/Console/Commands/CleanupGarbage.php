<?php

namespace App\Console\Commands;

use App\Services\Import\GarbageCleanupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:cleanup-garbage')]
#[Description('Remove garbage words (proper nouns, abbreviations, codes) via the configured AI provider')]
class CleanupGarbage extends Command
{
    public function handle(): int
    {
        $this->info('Starting garbage cleanup via ' . config('import.ai_provider') . '...');

        $service = app(GarbageCleanupService::class);
        $service->run();

        $this->info('Done. Details: storage/logs/import/');

        return Command::SUCCESS;
    }
}
