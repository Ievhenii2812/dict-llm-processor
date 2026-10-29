<?php

namespace App\Console\Commands;

use App\Services\Import\AIRateLimitException;
use App\Services\Import\WordSemanticTranslatorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:translate-words')]
#[Description('Translate non-homonym words with meanings, gloss via the configured AI provider')]
class TranslateWordsSemantic extends Command
{
    public function handle(): int
    {
        $this->info('Translating words (semantic, multi-meaning) via ' . config('import.ai_provider') . '...');

        $service = app(WordSemanticTranslatorService::class);

        try {
            $service->run();
        } catch (AIRateLimitException) {
            $this->newLine();
            $this->warn('AI provider rate limit reached — stopping cleanly.');
            $this->info('Progress so far is saved. Simply re-run this command later');
            $this->info('(tomorrow, or once the rate limit resets) to continue from where it left off.');
            $this->info('Details: storage/logs/import/');

            return Command::SUCCESS; // not a failure — just paused, safe to re-run
        }

        $this->info('Done. Details: storage/logs/import/');

        return Command::SUCCESS;
    }
}
