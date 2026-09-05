<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;

class LeipzigSentencesIndexer
{
    private const int BATCH_SIZE = 1000;
    private const int MAX_LENGTH = 200;
    private const int MIN_LENGTH = 10;

    private \SQLite3 $db;
    private array     $dumpPaths;
    private string    $indexPath;

    public function __construct()
    {
        $this->dumpPaths = array_filter([
            config('import.leipzig_sentences_news_file'),
            config('import.leipzig_sentences_web_file'),
        ]);
        $this->indexPath = config('import.leipzig_sentences_index');
    }

    public function run(): void
    {
        Log::channel('import')->info('=== LeipzigSentencesIndexer started ===', [
            'files' => $this->dumpPaths,
        ]);

        foreach ($this->dumpPaths as $path) {
            if (!file_exists($path)) {
                throw new \RuntimeException("Leipzig sentences file not found: {$path}");
            }
        }

        $this->initDb();

        $stmt  = $this->db->prepare('INSERT INTO sentences (sentence) VALUES (:sentence)');
        $count = 0;
        $seen  = [];

        $this->db->exec('BEGIN');

        foreach ($this->dumpPaths as $path) {
            Log::channel('import')->info('Indexing file', ['path' => $path]);

            $handle = fopen($path, 'r');
            if (!$handle) continue;

            $batch = 0;

            while (($line = fgets($handle)) !== false) {
                $line  = rtrim($line);
                $parts = explode("\t", $line, 2);

                if (count($parts) < 2) continue;

                $sentence = $this->clean($parts[1]);
                if ($sentence === null) continue;

                // Dedupe across both files (news + web may overlap)
                $hash = md5($sentence);
                if (isset($seen[$hash])) continue;
                $seen[$hash] = true;

                $stmt->bindValue(':sentence', $sentence);
                $stmt->execute();
                $stmt->reset();

                $count++;
                $batch++;

                if ($batch >= self::BATCH_SIZE) {
                    $this->db->exec('COMMIT');
                    $this->db->exec('BEGIN');
                    $batch = 0;

                    if ($count % 100000 === 0) {
                        Log::channel('import')->info('Leipzig sentences indexing progress', ['count' => $count]);
                    }
                }
            }

            fclose($handle);
        }

        $this->db->exec('COMMIT');

        Log::channel('import')->info('Building FTS index...');
        $this->db->exec('INSERT INTO sentences_fts(sentences_fts) VALUES("rebuild")');

        $this->db->close();

        Log::channel('import')->info('=== LeipzigSentencesIndexer finished ===', ['sentences' => $count]);
    }

    private function clean(string $sentence): ?string
    {
        $sentence = preg_replace('/<ref[^>]*>.*?<\/ref>/su', '', $sentence);
        $sentence = preg_replace('/<[^>]+>/', '', $sentence);
        $sentence = trim($sentence);

        $len = mb_strlen($sentence);
        if ($len < self::MIN_LENGTH || $len > self::MAX_LENGTH) return null;

        return $sentence;
    }

    private function initDb(): void
    {
        if (file_exists($this->indexPath)) unlink($this->indexPath);

        $this->db = new \SQLite3($this->indexPath);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA synchronous = NORMAL');
        $this->db->exec('PRAGMA cache_size = 20000');

        $this->db->exec('CREATE TABLE sentences (id INTEGER PRIMARY KEY AUTOINCREMENT, sentence TEXT NOT NULL)');
        $this->db->exec('CREATE VIRTUAL TABLE sentences_fts USING fts5(sentence, content=sentences, content_rowid=id)');
    }
}
