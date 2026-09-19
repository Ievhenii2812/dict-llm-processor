<?php

namespace App\Console\Commands;

use App\Services\Import\AIRateLimitException;
use App\Services\Import\WordTranslationGapFillerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:fill-translation-gaps')]
#[Description('Fill partial translation/gloss gaps left by truncated Gemini responses')]
class FillTranslationGaps extends Command
{
    public function handle(): int
    {
        $this->info('Filling translation gaps (ru/uk translations via AI Studio, glosses via NLLB)...');

        $service = app(WordTranslationGapFillerService::class);

        try {
            $service->run();
        } catch (AIRateLimitException) {
            $this->newLine();
            $this->warn('AI Studio daily/rate limit reached — stopping cleanly.');
            $this->info('Progress so far is saved. Re-run this command later to continue.');
            $this->info('Details: storage/logs/import/');

            return Command::SUCCESS;
        }

        $this->info('Done. Details: storage/logs/import/');

        return Command::SUCCESS;
    }
}
