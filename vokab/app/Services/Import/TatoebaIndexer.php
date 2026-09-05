<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;

/**
 * One-time indexer for Tatoeba sentence pair files.
 *
 * Reads TSV files (deu-eng.tsv, deu-rus.tsv, deu-ukr.tsv) and builds
 * a SQLite database for fast full-text search by German word/lemma.
 *
 * TSV format (Tatoeba sentence pairs):
 *   de_id TAB de_sentence TAB en_id TAB en_sentence
 *
 * Output SQLite schema:
 *   CREATE TABLE sentences (
 *     de_sentence TEXT,
 *     en_sentence TEXT,
 *     ru_sentence TEXT,
 *     uk_sentence TEXT
 *   )
 *   CREATE VIRTUAL TABLE sentences_fts USING fts5(de_sentence, content=sentences)
 *
 * Run: php artisan import:index-tatoeba
 * Time: ~5-10 minutes
 * Size: ~200-400 MB SQLite file
 */
class TatoebaIndexer
{
    private const int BATCH_SIZE = 1000;

    private \SQLite3 $db;
    private string   $indexPath;

    private string $engFile;
    private string $rusFile;
    private string $ukrFile;

    public function __construct()
    {
        $base            = storage_path('app/private/tatoeba');
        $this->engFile   = $base . '/deu-eng.tsv';
        $this->rusFile   = $base . '/deu-rus.tsv';
        $this->ukrFile   = $base . '/deu-ukr.tsv';
        $this->indexPath = storage_path('app/private/tatoeba.sqlite');
    }

    public function run(): void
    {
        Log::channel('import')->info('=== TatoebaIndexer started ===');

        $this->validateFiles();
        $this->initDb();

        // Step 1: Load all three pair files into memory maps
        // Key: German sentence → [en, ru, uk]
        Log::channel('import')->info('Loading English pairs...');
        $enMap = $this->loadPairFile($this->engFile);

        Log::channel('import')->info('Loading Russian pairs...', ['en_count' => count($enMap)]);
        $ruMap = $this->loadPairFile($this->rusFile);

        Log::channel('import')->info('Loading Ukrainian pairs...', ['ru_count' => count($ruMap)]);
        $ukMap = $this->loadPairFile($this->ukrFile);

        // Step 2: Merge into unified records
        Log::channel('import')->info('Merging and indexing...');
        $count = $this->mergeAndInsert($enMap, $ruMap, $ukMap);

        // Step 3: Build FTS index
        Log::channel('import')->info('Building full-text search index...');
        $this->db->exec('INSERT INTO sentences_fts(sentences_fts) VALUES("rebuild")');

        $this->db->close();

        Log::channel('import')->info('=== TatoebaIndexer finished ===', [
            'sentences_indexed' => $count,
            'index_path'        => $this->indexPath,
        ]);
    }

    // -------------------------------------------------------------------------
    // File loading
    // -------------------------------------------------------------------------

    /**
     * Load a TSV pair file into a map: German sentence → translation.
     * Tatoeba TSV format: de_id\tde_sentence\ttr_id\ttr_sentence
     *
     * @return array<string, string>  German → translation
     */
    private function loadPairFile(string $path): array
    {
        $map    = [];
        $handle = fopen($path, 'r');

        if (!$handle) {
            Log::channel('import')->warning('TatoebaIndexer: file not found', ['path' => $path]);
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $line  = rtrim($line);
            $parts = explode("\t", $line);

            // Expected: de_id, de_sentence, tr_id, tr_sentence (4 columns)
            if (count($parts) < 4) continue;

            $deSentence = trim($parts[1]);
            $trSentence = trim($parts[3]);

            if (empty($deSentence) || empty($trSentence)) continue;

            // Use German sentence as key — if duplicate, keep first occurrence
            if (!isset($map[$deSentence])) {
                $map[$deSentence] = $trSentence;
            }
        }

        fclose($handle);

        return $map;
    }

    // -------------------------------------------------------------------------
    // Merge and insert
    // -------------------------------------------------------------------------

    /**
     * Merge three maps into unified SQLite records.
     * German sentence is the anchor; ru/uk may be null if not in their map.
     */
    private function mergeAndInsert(array $enMap, array $ruMap, array $ukMap): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO sentences (de_sentence, en_sentence, ru_sentence, uk_sentence)
            VALUES (:de, :en, :ru, :uk)
        ');

        $count = 0;
        $batch = 0;

        $this->db->exec('BEGIN');

        // English map is the largest — use it as the base
        foreach ($enMap as $deSentence => $enSentence) {
            $stmt->bindValue(':de', $deSentence);
            $stmt->bindValue(':en', $enSentence);
            $stmt->bindValue(':ru', $ruMap[$deSentence] ?? null);
            $stmt->bindValue(':uk', $ukMap[$deSentence] ?? null);
            $stmt->execute();
            $stmt->reset();

            $count++;
            $batch++;

            if ($batch >= self::BATCH_SIZE) {
                $this->db->exec('COMMIT');
                $this->db->exec('BEGIN');
                $batch = 0;

                if ($count % 50000 === 0) {
                    Log::channel('import')->info('Tatoeba index progress', ['count' => $count]);
                }
            }
        }

        // Add Russian-only sentences not in English map
        foreach ($ruMap as $deSentence => $ruSentence) {
            if (isset($enMap[$deSentence])) continue; // Already inserted

            $stmt->bindValue(':de', $deSentence);
            $stmt->bindValue(':en', null);
            $stmt->bindValue(':ru', $ruSentence);
            $stmt->bindValue(':uk', $ukMap[$deSentence] ?? null);
            $stmt->execute();
            $stmt->reset();

            $count++;
            $batch++;

            if ($batch >= self::BATCH_SIZE) {
                $this->db->exec('COMMIT');
                $this->db->exec('BEGIN');
                $batch = 0;
            }
        }

        // Add Ukrainian-only sentences
        foreach ($ukMap as $deSentence => $ukSentence) {
            if (isset($enMap[$deSentence]) || isset($ruMap[$deSentence])) continue;

            $stmt->bindValue(':de', $deSentence);
            $stmt->bindValue(':en', null);
            $stmt->bindValue(':ru', null);
            $stmt->bindValue(':uk', $ukSentence);
            $stmt->execute();
            $stmt->reset();

            $count++;
        }

        $this->db->exec('COMMIT');

        return $count;
    }

    // -------------------------------------------------------------------------
    // SQLite setup
    // -------------------------------------------------------------------------

    private function initDb(): void
    {
        if (file_exists($this->indexPath)) {
            unlink($this->indexPath);
        }

        $this->db = new \SQLite3($this->indexPath);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA synchronous = NORMAL');
        $this->db->exec('PRAGMA cache_size = 20000');

        // Main table
        $this->db->exec('
            CREATE TABLE sentences (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                de_sentence TEXT NOT NULL,
                en_sentence TEXT,
                ru_sentence TEXT,
                uk_sentence TEXT
            )
        ');

        // Full-text search index on German sentences
        $this->db->exec('
            CREATE VIRTUAL TABLE sentences_fts
            USING fts5(de_sentence, content=sentences, content_rowid=id)
        ');
    }

    private function validateFiles(): void
    {
        foreach ([$this->engFile, $this->rusFile, $this->ukrFile] as $file) {
            if (!file_exists($file)) {
                throw new \RuntimeException("Tatoeba file not found: {$file}");
            }
        }
    }
}
