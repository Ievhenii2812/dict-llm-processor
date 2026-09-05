<?php

namespace App\Console\Commands;

use App\Services\Import\HomonymTranslatorService;
use App\Services\Import\VertexAIService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:translate-homonyms')]
#[Description('Translate homonym words via Gemini Flash using sentence context for disambiguation')]
class TranslateHomonyms extends Command
{
    public function handle(): int
    {
        $this->info('Translating homonyms via Gemini Flash (context-aware)...');

        $service = new HomonymTranslatorService(app(VertexAIService::class));
        $service->run();

        $this->info('Done. Details: storage/logs/import/');

        return Command::SUCCESS;
    }
}
