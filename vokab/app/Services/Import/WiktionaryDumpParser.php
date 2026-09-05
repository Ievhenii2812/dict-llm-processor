<?php

namespace App\Services\Import;

use App\Enums\PartOfSpeech;
use Illuminate\Support\Facades\Log;
use App\Services\Import\WiktionaryEntry;

/**
 * Читает wikitext из локального SQLite индекса дампа Викисловаря.
 * Интерфейс идентичен WiktionaryParser — метод fetch() возвращает WiktionaryEntry[].
 *
 * Конфиг в .env:
 *   WIKTIONARY_INDEX=  (по умолчанию storage/app/private/wiktionary.index)
 */
class WiktionaryDumpParser
{
    private ?\SQLite3 $db = null;

    public function __construct()
    {
        $indexPath = config('import.wiktionary_index');

        if (!file_exists($indexPath)) {
            Log::channel('import')->error('WiktionaryDumpParser: index not found', [
                'path' => $indexPath,
                'hint' => 'Run: php artisan import:index-dump',
            ]);
            return;
        }

        $this->db = new \SQLite3($indexPath, SQLITE3_OPEN_READONLY);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA cache_size = 5000');
    }

    public function __destruct()
    {
        if ($this->db) $this->db->close();
    }

    // -------------------------------------------------------------------------
    // Публичный API (идентичен WiktionaryParser)
    // -------------------------------------------------------------------------

    /**
     * @return WiktionaryEntry[]
     */
    public function fetch(string $lemma): array
    {
        $wikitext = $this->fetchWikitext($lemma);

        if ($wikitext === null) {
            Log::channel('import')->debug('Dump: article not found', ['word' => $lemma]);
            return [];
        }

        return $this->parse($lemma, $wikitext);
    }

    // -------------------------------------------------------------------------
    // Чтение из SQLite
    // -------------------------------------------------------------------------

    private function fetchWikitext(string $lemma): ?string
    {
        if ($this->db === null) return null;

        $stmt = $this->db->prepare('
            SELECT wikitext FROM articles WHERE title = :title LIMIT 1
        ');
        $stmt->bindValue(':title', $lemma);
        $result = $stmt->execute();
        $row    = $result->fetchArray(SQLITE3_ASSOC);

        return $row ? $row['wikitext'] : null;
    }

    // -------------------------------------------------------------------------
    // Парсинг wikitext (идентичен WiktionaryParser)
    // -------------------------------------------------------------------------

    private function parse(string $lemma, string $wikitext): array
    {
        $german = $this->extractGermanSection($wikitext);

        if ($german === null) return [];

        $blocks       = $this->splitIntoBlocks($german);
        $entries      = [];
        $homonymIndex = 1;

        foreach ($blocks as $block) {
            $pos = $this->detectPartOfSpeech($block);
            if ($pos === null) continue;

            $entry = new WiktionaryEntry(
                word:         $lemma,
                partOfSpeech: $pos,
                homonymIndex: count($blocks) > 1 ? $homonymIndex : null,
            );

            $this->parseGrammar($entry, $block);
            $entry->examples = $this->parseExamples($block);
            $entry->idioms   = $this->parseIdioms($block);

            $entries[]    = $entry;
            $homonymIndex++;
        }

        return $entries;
    }

    private function extractGermanSection(string $wikitext): ?string
    {
        if (!preg_match(
            '/^==\s*(?:[^=]*\({{Sprache\|Deutsch}}\)|Deutsch)\s*==/m',
            $wikitext, $m, PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $start = $m[0][1] + strlen($m[0][0]);
        $rest  = substr($wikitext, $start);

        if (preg_match(
            '/^==\s*(?:[^=]*\({{Sprache\|(?!Deutsch)[^}]+}}\)|(?!Deutsch)\w+)\s*==/m',
            $rest, $n, PREG_OFFSET_CAPTURE
        )) {
            return substr($rest, 0, $n[0][1]);
        }

        return $rest;
    }

    private function splitIntoBlocks(string $section): array
    {
        $parts = preg_split('/(?=^=== )/m', $section, -1, PREG_SPLIT_NO_EMPTY);
        return array_filter($parts, fn($p) => str_starts_with(trim($p), '==='));
    }

    private function detectPartOfSpeech(string $block): ?PartOfSpeech
    {
        if (!preg_match('/^=== (.+?) ===/m', $block, $m)) return null;

        $label = trim($m[1]);
        $skip  = ['Eigenname', 'Abkürzung', 'Präfix', 'Suffix', 'Affix', 'Symbol', 'Zeichen'];

        foreach ($skip as $s) {
            if (str_contains($label, $s)) return null;
        }

        return PartOfSpeech::fromWiktionary($label);
    }

    private function parseGrammar(WiktionaryEntry $entry, string $block): void
    {
        match ($entry->partOfSpeech) {
            PartOfSpeech::Noun      => $this->parseNounGrammar($entry, $block),
            PartOfSpeech::Verb      => $this->parseVerbGrammar($entry, $block),
            PartOfSpeech::Adjective => $this->parseAdjectiveGrammar($entry, $block),
            default                 => null,
        };
    }

    private function parseNounGrammar(WiktionaryEntry $entry, string $block): void
    {
        if (preg_match('/\|Genus\s*=\s*([mfn])/i', $block, $m)) {
            $entry->gender = $m[1];
        }
        if (preg_match('/\|Nominativ Plural\s*=\s*([^\|}\n]+)/i', $block, $m)) {
            $plural = trim($m[1]);
            if (!empty($plural) && $plural !== '—' && $plural !== '-') {
                $entry->plural = $plural;
            }
        }
    }

    private function parseVerbGrammar(WiktionaryEntry $entry, string $block): void
    {
        if (preg_match('/\|Präteritum_ich\s*=\s*([^\|}\n]+)/i', $block, $m)) {
            $entry->preterite = trim($m[1]);
        }
        if (preg_match('/\|Partizip II\s*=\s*([^\|}\n]+)/i', $block, $m)) {
            $entry->participleII = trim($m[1]);
        }
        if (preg_match('/\|Hilfsverb\s*=\s*(haben|sein)/i', $block, $m)) {
            $entry->auxVerb = strtolower(trim($m[1]));
        }
        if (!empty($entry->preterite) && !str_ends_with($entry->preterite, 'te')) {
            $entry->isRegular = false;
        }
        $entry->prepositions = $this->parseVerbPrepositions($block);
    }

    private function parseAdjectiveGrammar(WiktionaryEntry $entry, string $block): void
    {
        if (preg_match('/\|Komparativ\s*=\s*([^\|}\n]+)/i', $block, $m)) {
            $entry->comparative = trim($m[1]);
        }
        if (preg_match('/\|Superlativ\s*=\s*([^\|}\n]+)/i', $block, $m)) {
            $entry->superlative = trim($m[1]);
        }
    }

    private function parseVerbPrepositions(string $block): array
    {
        $results = [];
        preg_match_all(
            '/\b(auf|an|für|über|mit|von|zu|nach|vor|bei|in|um|gegen|durch|ohne)\s*\+?\s*\(?(Akk|Dat|Gen)\.?\)?/iu',
            $block, $matches, PREG_SET_ORDER
        );
        foreach ($matches as $m) {
            $results[] = [
                'preposition' => strtolower($m[1]),
                'case'        => ucfirst(strtolower($m[2])),
            ];
        }
        return array_unique($results, SORT_REGULAR);
    }

    private function parseExamples(string $block): array
    {
        if (!preg_match('/\{\{Beispiele\}\}(.*?)(?=\{\{|\Z)/s', $block, $m)) return [];

        $examples = [];
        preg_match_all('/^:\[?\d*\]?\s*(.+)$/m', $m[1], $matches);

        foreach ($matches[1] as $raw) {
            $clean = $this->cleanWikitext($raw);
            if (!empty($clean) && mb_strlen($clean) <= 500) {
                $examples[] = $clean;
            }
        }
        return array_values(array_unique($examples));
    }

    private function parseIdioms(string $block): array
    {
        if (!preg_match('/\{\{Redewendungen\}\}(.*?)(?=\{\{|\Z)/s', $block, $m)) return [];

        $idioms = [];
        preg_match_all('/^:\[?\[?([^\]|\n]+)\]?\]?\s*[–—-]\s*(.+)$/mu', $m[1], $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $phrase    = $this->cleanWikitext($m[1]);
            $meaningDe = $this->cleanWikitext($m[2]);
            if (!empty($phrase) && !empty($meaningDe)) {
                $idioms[] = ['phrase' => $phrase, 'meaning_de' => $meaningDe];
            }
        }
        return $idioms;
    }

    private function cleanWikitext(string $text): string
    {
        $text = preg_replace('/\{\{[^}]*\}\}/u', '', $text);
        $text = preg_replace('/\[\[(?:[^|\]]*\|)?([^\]]+)\]\]/u', '$1', $text);
        $text = preg_replace('/\[https?:\/\/\S+\s+([^\]]+)\]/u', '$1', $text);
        $text = preg_replace("/['\[\]]/u", '', $text);
        $text = preg_replace('/<ref[^>]*>.*?<\/ref>/su', '', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
