<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;

/**
 * Одноразовый индексатор дампа Викисловаря.
 *
 * Читает XML.bz2 дамп и сохраняет wikitext немецких статей
 * в SQLite базу для мгновенного доступа без сети.
 *
 * Структура SQLite:
 *   CREATE TABLE articles (title TEXT PRIMARY KEY, wikitext TEXT)
 *
 * Запуск: php artisan import:index-dump
 * Время:  ~15-20 минут на файл 2 ГБ
 * Размер SQLite: ~800 МБ - 1 ГБ
 */
class WiktionaryDumpIndexer
{
    private const CHUNK_SIZE  = 65536; // 64 КБ
    private const BATCH_SIZE  = 500;   // Записей за одну транзакцию

    private \SQLite3 $db;
    private string   $dumpPath;
    private string   $indexPath;

    public function __construct()
    {
        $this->dumpPath  = config('import.wiktionary_dump');
        $this->indexPath = config('import.wiktionary_index');
    }

    public function run(): void
    {
        Log::channel('import')->info('=== WiktionaryDumpIndexer started ===', [
            'dump'  => $this->dumpPath,
            'index' => $this->indexPath,
        ]);

        if (!file_exists($this->dumpPath)) {
            throw new \RuntimeException("Dump file not found: {$this->dumpPath}");
        }

        $this->initDb();

        $handle = $this->openDump();
        $count  = $this->parseAndIndex($handle);
        fclose($handle);

        $this->db->close();

        Log::channel('import')->info('=== WiktionaryDumpIndexer finished ===', [
            'articles_indexed' => $count,
        ]);
    }

    // -------------------------------------------------------------------------
    // Инициализация SQLite
    // -------------------------------------------------------------------------

    private function initDb(): void
    {
        if (file_exists($this->indexPath)) {
            unlink($this->indexPath);
        }

        $this->db = new \SQLite3($this->indexPath);

        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA synchronous = NORMAL');
        $this->db->exec('PRAGMA cache_size = 10000');

        $this->db->exec('
            CREATE TABLE articles (
                title    TEXT PRIMARY KEY,
                wikitext TEXT NOT NULL
            )
        ');
    }

    // -------------------------------------------------------------------------
    // Открытие дампа
    // -------------------------------------------------------------------------

    private function openDump(): mixed
    {
        $ext    = pathinfo($this->dumpPath, PATHINFO_EXTENSION);
        $path   = $ext === 'bz2'
            ? 'compress.bzip2://' . $this->dumpPath
            : $this->dumpPath;

        $handle = fopen($path, 'r');

        if (!$handle) {
            throw new \RuntimeException("Cannot open dump: {$this->dumpPath}");
        }

        return $handle;
    }

    // -------------------------------------------------------------------------
    // Парсинг XML и сохранение в SQLite
    // -------------------------------------------------------------------------

    private function parseAndIndex(mixed $handle): int
    {
        $parser = xml_parser_create('UTF-8');
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);

        // Состояние парсера
        $state        = 'idle';
        $currentTitle = '';
        $currentText  = '';
        $inText       = false;
        $count        = 0;
        $batch        = [];

        $stmt = $this->db->prepare('
            INSERT OR REPLACE INTO articles (title, wikitext) VALUES (:title, :wikitext)
        ');

        $flushBatch = function () use (&$batch, $stmt) {
            if (empty($batch)) return;
            $this->db->exec('BEGIN');
            foreach ($batch as [$title, $wikitext]) {
                $stmt->bindValue(':title',    $title);
                $stmt->bindValue(':wikitext', $wikitext);
                $stmt->execute();
                $stmt->reset();
            }
            $this->db->exec('COMMIT');
            $batch = [];
        };

        xml_set_element_handler(
            $parser,
            // Открывающий тег
            function ($p, $name, $attrs) use (&$state, &$currentTitle, &$currentText, &$inText) {
                if ($name === 'page') {
                    $state        = 'page';
                    $currentTitle = '';
                    $currentText  = '';
                    $inText       = false;
                }
                if ($name === 'title' && $state === 'page') {
                    $state = 'title';
                }
                if ($name === 'text') {
                    $inText = true;
                }
            },
            // Закрывающий тег
            function ($p, $name) use (
                &$state, &$inText, &$currentTitle, &$currentText,
                &$count, &$batch, $flushBatch
            ) {
                if ($name === 'title') {
                    $state = 'page';
                }
                if ($name === 'text') {
                    $inText = false;
                }
                if ($name === 'page') {
                    if (!empty($currentTitle) && !empty($currentText)) {
                        // Сохраняем только немецкие статьи
                        if ($this->isGermanEntry($currentTitle, $currentText)) {
                            $batch[] = [$currentTitle, $currentText];
                            $count++;

                            if (count($batch) >= self::BATCH_SIZE) {
                                $flushBatch();
                            }

                            if ($count % 5000 === 0) {
                                Log::channel('import')->info('Indexing progress', [
                                    'articles' => $count,
                                ]);
                            }
                        }
                    }
                    $currentTitle = '';
                    $currentText  = '';
                }
            }
        );

        xml_set_character_data_handler(
            $parser,
            function ($p, $data) use (&$state, &$inText, &$currentTitle, &$currentText) {
                if ($state === 'title') {
                    $currentTitle .= $data;
                }
                if ($inText) {
                    $currentText .= $data;
                }
            }
        );

        while (!feof($handle)) {
            $chunk = fread($handle, self::CHUNK_SIZE);
            if ($chunk === false) break;
            xml_parse($parser, $chunk, feof($handle));
        }

        // Flush остатка
        $flushBatch();

        xml_parser_free($parser);

        return $count;
    }

    // -------------------------------------------------------------------------
    // Фильтр немецких статей
    // -------------------------------------------------------------------------

    private function isGermanEntry(string $title, string $wikitext): bool
    {
        // Пропускаем служебные страницы Викисловаря
        if (str_contains($title, ':')) return false;

        // Проверяем наличие немецкой секции
        return str_contains($wikitext, '{{Sprache|Deutsch}}')
            || str_contains($wikitext, '== Deutsch ==');
    }
}
