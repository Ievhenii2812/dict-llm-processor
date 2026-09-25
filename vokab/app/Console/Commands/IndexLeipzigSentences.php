<?php

namespace App\Console\Commands;

use App\Services\Import\LeipzigSentencesIndexer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:index-leipzig-sentences')]
#[Description('One-time: index a Leipzig sentences dump into SQLite FTS5')]
class IndexLeipzigSentences extends Command
{
    public function handle(): int
    {
        $this->info('Indexing Leipzig sentences dump...');
        $this->info('Expected files at paths set in LEIPZIG_SENTENCES_NEWS_FILE / LEIPZIG_SENTENCES_WEB_FILE (.env)');

        $indexer = new LeipzigSentencesIndexer();
        $indexer->run();

        $this->info('Done. Index: ' . config('import.leipzig_sentences_index'));

        return Command::SUCCESS;
    }
}
