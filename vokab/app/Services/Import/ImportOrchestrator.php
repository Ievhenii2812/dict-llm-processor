<?php

namespace App\Services\Import;

use App\Models\ImportState;
use App\Models\Word;
use Illuminate\Support\Facades\Log;

/**
 * Главный цикл импорта (Этап 1).
 *
 * Читает файл Лейпцига построчно, начиная с сохранённой позиции.
 * Останавливается после записи $sessionLimit валидных слов.
 *
 * Запуск: php artisan import:run --limit=25
 */
class ImportOrchestrator
{
    private int $validCount  = 0;   // сколько новых слов записано за сессию
    private int $skipCount   = 0;   // сколько строк пропущено
    private int $currentLine = 0;   // текущая физическая строка файла

    public function __construct(
        private readonly SpacyService          $hunspell,
        private readonly WiktionaryDumpParser  $wiktionary,  // подключим в следующей сессии
        private readonly WordSaver             $saver,       // подключим в следующей сессии
        private readonly string                $filePath,
        private readonly int                   $sessionLimit,
        private readonly string                $source = 'news',
    ) {}

    // -------------------------------------------------------------------------
    // Точка входа
    // -------------------------------------------------------------------------

    public function run(): void
    {
        Log::channel('import')->info('=== Import session started ===', [
            'limit'    => $this->sessionLimit,
            'file'     => $this->filePath,
        ]);

        if (!file_exists($this->filePath)) {
            Log::channel('import')->error('Leipzig file not found', ['path' => $this->filePath]);
            throw new \RuntimeException("Leipzig file not found: {$this->filePath}");
        }

        $startLine = $this->loadProgress();

        Log::channel('import')->info('Resuming from line', ['line' => $startLine]);

        $file = new \SplFileObject($this->filePath, 'r');
        $file->setFlags(\SplFileObject::DROP_NEW_LINE | \SplFileObject::SKIP_EMPTY);

        // Перемотка к сохранённой позиции
        if ($startLine > 0) {
            $file->seek($startLine);
        }

        while (!$file->eof() && $this->validCount < $this->sessionLimit) {
            $this->currentLine = $file->key();
            $line = $file->current();
            $file->next();

            if (empty($line)) {
                continue;
            }

            $this->processLine($line);
        }

        $this->logSessionSummary();
    }

    // -------------------------------------------------------------------------
    // Обработка одной строки
    // -------------------------------------------------------------------------

    private function processLine(string $line): void
    {
        // ── Шаг 1.1: Разбор строки Лейпцигского файла ──────────────────────
        $parsed = $this->parseLine($line);

        if ($parsed === null) {
            $this->skip('bad_line_format', $line);
            return;
        }

        ['word' => $rawWord, 'frequency_rank' => $frequencyRank] = $parsed;

        // ── Шаг 1.2: spaCy ───────────────────────────────────────────────
        $result = $this->hunspell->analyze($rawWord);

        if (!$result->isKnown) {
            $this->skip('hunspell_unknown', $rawWord);
            return;
        }

        // Фильтр имён собственных
        if ($result->isProperNoun) {
            $this->skip('proper_noun', $rawWord);
            return;
        }

        $lemma = $result->lemma;

        // Базовые фильтры мусора
        if ($this->isJunk($lemma)) {
            $this->skip('junk_filter', $lemma);
            return;
        }

        // ── Если слово уже есть в базе: суммируем частотность и идём дальше ─
        $existing = Word::where('word', $lemma)->first();

        if ($existing !== null) {
            $existing->increment('frequency_rank', $frequencyRank);
            $this->saveProgress();

            Log::channel('import')->debug('Frequency updated', [
                'word'  => $lemma,
                'added' => $frequencyRank,
            ]);

            return;
        }

        // ── Шаг 1.3 + 1.4: Wiktionary → сохранение ─────────────────────────
        // WiktionaryDumpParser и WordSaver подключаются в следующих сессиях.
        // Здесь — точка расширения.
        $this->processNewWord($lemma, $frequencyRank);
    }

    protected function processNewWord(string $lemma, int $frequencyRank): void
    {
        // ── Шаг 1.3: Запрос к Wiktionary ────────────────────────────────────
        $entries = $this->wiktionary->fetch($lemma);

        if (empty($entries)) {
            $this->skip('wiktionary_not_found', $lemma);
            return;
        }

        // ── Шаг 1.4: Сохранение ─────────────────────────────────────────────
        $saved = $this->saver->save($frequencyRank, $entries);

        if ($saved === 0) {
            $this->skip('saver_failed', $lemma);
            return;
        }

        $this->validCount += $saved;
        $this->saveProgress();

        Log::channel('import')->info('Word processed', [
            'word'    => $lemma,
            'entries' => $saved,
            'session_total' => $this->validCount,
        ]);
    }

    // -------------------------------------------------------------------------
    // Вспомогательные методы
    // -------------------------------------------------------------------------

    /**
     * Разбор строки Лейпцигского файла.
     * Формат: <rank>\t<word>\t<count>
     * или:    <word>\t<count>
     * Возвращает null если строка не распознана.
     */
    private function parseLine(string $line): ?array
    {
        $parts = explode("\t", $line);

        // Формат с тремя колонками: rank \t word \t count
        if (count($parts) === 3 && is_numeric($parts[0]) && is_numeric($parts[2])) {
            return [
                'word'           => trim($parts[1]),
                'frequency_rank' => (int) $parts[2],
            ];
        }

        // Формат с двумя колонками: word \t count
        if (count($parts) === 2 && is_numeric($parts[1])) {
            return [
                'word'           => trim($parts[0]),
                'frequency_rank' => (int) $parts[1],
            ];
        }

        return null;
    }

    /**
     * Фильтры мусора на уровне леммы.
     * Hunspell уже отфильтровал неизвестные слова.
     * Здесь — дополнительные эвристики.
     */
    private function isJunk(string $lemma): bool
    {
        // Числа и числа с буквами (2024, 5G, 3D...)
        if (preg_match('/^\d/', $lemma)) return true;

        // Слишком короткие (одна буква)
        if (mb_strlen($lemma) < 2) return true;

        // Содержит спецсимволы (URL, e-mail, коды...)
        if (preg_match('/[\/\\\\@#$%^&*<>]/', $lemma)) return true;

        // Аббревиатуры: только заглавные буквы (EU, NATO, SPD...)
        // Исключение: двухбуквенные могут быть нормальными словами
        if (mb_strlen($lemma) > 2 && preg_match('/^[A-ZÄÖÜ]+$/', $lemma)) return true;

        return false;
    }

    private function skip(string $reason, string $word): void
    {
        $this->skipCount++;
        $this->saveProgress();

        Log::channel('import')->debug('Skipped', [
            'reason' => $reason,
            'word'   => $word,
            'line'   => $this->currentLine,
        ]);
    }

    private function getStateKey(): string
    {
        return match ($this->source) {
            'web'   => 'leipzig_last_physical_line_web',
            default => 'leipzig_last_physical_line_news',
        };
    }

    private function saveProgress(): void
    {
        ImportState::updateOrCreate(
            ['key' => $this->getStateKey()],
            ['value' => $this->currentLine]
        );
    }

    private function loadProgress(): int
    {
        return ImportState::where('key', $this->getStateKey())->value('value') ?? 0;
    }

    private function logSessionSummary(): void
    {
        Log::channel('import')->info('=== Import session finished ===', [
            'words_saved'  => $this->validCount,
            'lines_skipped' => $this->skipCount,
            'stopped_at_line' => $this->currentLine,
        ]);
    }
}
