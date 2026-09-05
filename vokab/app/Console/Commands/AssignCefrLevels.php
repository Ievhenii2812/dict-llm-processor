<?php

namespace App\Console\Commands;

use App\Services\Import\CefrAssignmentService;
use App\Services\Import\VertexAIService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:assign-cefr')]
#[Description('Assign CEFR levels (A1-C2) to words via Gemini Flash (Vertex AI)')]
class AssignCefrLevels extends Command
{
    public function handle(): int
    {
        $this->info('Assigning CEFR levels via Gemini Flash...');

        $service = new CefrAssignmentService(app(VertexAIService::class));
        $service->run();

        $this->info('Done. Details: storage/logs/import/');

        return Command::SUCCESS;
    }
}
