<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;

/**
 * Searches the Tatoeba SQLite index for example sentences containing a word.
 *
 * Uses SQLite FTS5 for fast full-text search on German sentences.
 */
class TatoebaParser
{
    private ?\SQLite3 $db = null;

    private const int MAX_RESULTS = 10; // Fetch more than needed, WordSaver picks best 5

    public function __construct()
    {
        $indexPath = storage_path('app/private/tatoeba.sqlite');

        if (!file_exists($indexPath)) {
            Log::channel('import')->error('TatoebaParser: index not found', [
                'path' => $indexPath,
                'hint' => 'Run: php artisan import:index-tatoeba',
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

    /**
     * Find example sentences containing the given German word/lemma.
     *
     * @return array<array{de: string, en: string|null, ru: string|null, uk: string|null}>
     */
    public function findExamples(string $lemma): array
    {
        if ($this->db === null) return [];

        // FTS5 query: match sentences containing the exact word
        // NEAR syntax for whole-word matching
        $query = '"' . str_replace('"', '', $lemma) . '"';

        $stmt = $this->db->prepare('
            SELECT s.de_sentence, s.en_sentence, s.ru_sentence, s.uk_sentence
            FROM sentences s
            JOIN sentences_fts fts ON s.id = fts.rowid
            WHERE sentences_fts MATCH :query
            ORDER BY
                -- Prefer sentences with all three translations
                (CASE WHEN s.en_sentence IS NOT NULL THEN 1 ELSE 0 END +
                 CASE WHEN s.ru_sentence IS NOT NULL THEN 1 ELSE 0 END +
                 CASE WHEN s.uk_sentence IS NOT NULL THEN 1 ELSE 0 END) DESC,
                -- Prefer shorter sentences (easier to understand)
                length(s.de_sentence) ASC
            LIMIT :limit
        ');

        $stmt->bindValue(':query', $query);
        $stmt->bindValue(':limit', self::MAX_RESULTS);

        $result  = $stmt->execute();
        $examples = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $examples[] = [
                'de' => $row['de_sentence'],
                'en' => $row['en_sentence'],
                'ru' => $row['ru_sentence'],
                'uk' => $row['uk_sentence'],
            ];
        }

        return $examples;
    }

    public function isAvailable(): bool
    {
        return $this->db !== null;
    }
}
