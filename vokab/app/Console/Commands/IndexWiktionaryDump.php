<?php

namespace App\Console\Commands;

use App\Services\Import\WiktionaryDumpIndexer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('import:index-dump')]
#[Description('Одноразовый: индексировать дамп Викисловаря в SQLite')]
class IndexWiktionaryDump extends Command
{
    public function handle(): int
    {
        $this->info('Индексирование дампа Викисловаря...');
        $this->info('Это займёт 15-20 минут. Не прерывайте процесс.');
        $this->info('');

        $indexer = new WiktionaryDumpIndexer();
        $indexer->run();

        $this->info('');
        $this->info('Готово. Индекс: ' . config('import.wiktionary_index'));
        $this->info('Теперь можно запускать import:run');

        return Command::SUCCESS;
    }
}
