<?php

namespace App\Console\Commands;

use App\Services\Import\GarbageCleanupService;
use App\Services\Import\VertexAIService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:cleanup-garbage')]
#[Description('Remove garbage words (proper nouns, abbreviations, codes) via Gemini Flash')]
class CleanupGarbage extends Command
{
    public function handle(): int
    {
        $this->info('Starting garbage cleanup via Gemini Flash...');

        $service = new GarbageCleanupService(app(VertexAIService::class));
        $service->run();

        $this->info('Done. Details: storage/logs/import/');

        return Command::SUCCESS;
    }
}
