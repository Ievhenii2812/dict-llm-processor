<?php

namespace App\Console\Commands;

use App\Services\Import\PhraseTranslatorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:translate-phrases')]
#[Description('Translate idiom meanings via the local translator (NLLB), find analogues via the configured AI provider')]
class TranslatePhrases extends Command
{
    public function handle(): int
    {
        $this->info('Translating phrases: NLLB meanings + ' . config('import.ai_provider') . ' analogues...');

        $service = app(PhraseTranslatorService::class);

        $service->run();

        $this->info('Done. Details: storage/logs/import/');

        return Command::SUCCESS;
    }
}
