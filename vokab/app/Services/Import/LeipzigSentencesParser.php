<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;

/**
 * Searches the Leipzig sentences SQLite FTS5 index for examples
 * containing a given German word. German-only — no translations
 * (unlike TatoebaParser); translation happens on save via NLLB.
 */
class LeipzigSentencesParser
{
    private ?\SQLite3 $db = null;

    private const int MAX_RESULTS = 10;

    public function __construct()
    {
        $indexPath = config('import.leipzig_sentences_index');

        if (!file_exists($indexPath)) {
            Log::channel('import')->error('LeipzigSentencesParser: index not found', [
                'path' => $indexPath,
                'hint' => 'Run: php artisan import:index-leipzig-sentences',
            ]);
            return;
        }

        $this->db = new \SQLite3($indexPath, SQLITE3_OPEN_READONLY);
        $this->db->exec('PRAGMA cache_size = 5000');
    }

    public function __destruct()
    {
        if ($this->db) $this->db->close();
    }

    public function isAvailable(): bool
    {
        return $this->db !== null;
    }

    /**
     * @return string[] German sentences containing the word
     */
    public function findExamples(string $lemma): array
    {
        if ($this->db === null) return [];

        $query = '"' . str_replace('"', '', $lemma) . '"';

        $stmt = $this->db->prepare('
            SELECT s.sentence
            FROM sentences s
            JOIN sentences_fts fts ON s.id = fts.rowid
            WHERE sentences_fts MATCH :query
            ORDER BY length(s.sentence) ASC
            LIMIT :limit
        ');
        $stmt->bindValue(':query', $query);
        $stmt->bindValue(':limit', self::MAX_RESULTS);

        $result    = $stmt->execute();
        $sentences = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $sentences[] = $row['sentence'];
        }

        return $sentences;
    }
}
