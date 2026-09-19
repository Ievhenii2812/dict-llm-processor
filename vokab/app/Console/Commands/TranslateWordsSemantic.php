<?php

namespace App\Console\Commands;

use App\Services\Import\AIRateLimitException;
use App\Services\Import\WordSemanticTranslatorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:translate-words')]
#[Description('Translate non-homonym words with meanings, gloss via Gemini Flash (AI Studio)')]
class TranslateWordsSemantic extends Command
{
    public function handle(): int
    {
        $this->info('Translating words (semantic, multi-meaning) via Gemini Flash (AI Studio)...');

        $service = app(WordSemanticTranslatorService::class);

        try {
            $service->run();
        } catch (AIRateLimitException) {
            $this->newLine();
            $this->warn('AI Studio daily/rate limit reached — stopping cleanly.');
            $this->info('Progress so far is saved. Simply re-run this command later');
            $this->info('(tomorrow, or once the rate limit resets) to continue from where it left off.');
            $this->info('Details: storage/logs/import/');

            return Command::SUCCESS; // not a failure — just paused, safe to re-run
        }

        $this->info('Done. Details: storage/logs/import/');

        return Command::SUCCESS;
    }
}
