<?php

namespace App\Console\Commands;

use App\Services\Import\TatoebaIndexer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:index-tatoeba')]
#[Description('One-time: index Tatoeba sentence pairs into SQLite for fast search')]
class IndexTatoebaData extends Command
{
    public function handle(): int
    {
        $this->info('Indexing Tatoeba sentence pairs...');
        $this->info('Expected files in storage/app/private/tatoeba/:');
        $this->info('  deu-eng.tsv, deu-rus.tsv, deu-ukr.tsv');
        $this->info('');
        $this->info('This takes ~5-10 minutes. Do not interrupt.');

        $indexer = new TatoebaIndexer();
        $indexer->run();

        $this->info('');
        $this->info('Done. Index: ' . storage_path('app/private/tatoeba.sqlite'));

        return Command::SUCCESS;
    }
}
