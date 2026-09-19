<?php

namespace App\Console\Commands;

use App\Services\Import\CategorizationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:categorize')]
#[Description('Categorize words into thematic groups via Gemini Pro (Vertex AI)')]
class CategorizeWords extends Command
{
    public function handle(): int
    {
        $this->info('Starting word categorization via Gemini Pro...');

        $service = app(CategorizationService::class);
        $service->run();

        $this->info('Done. Details: storage/logs/import/');

        return Command::SUCCESS;
    }
}
