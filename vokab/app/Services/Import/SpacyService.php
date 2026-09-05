<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;

/**
 * Обёртка над локальным spaCy FastAPI сервисом.
 *
 * Конфиг в .env:
 *   SPACY_URL=http://lemmatizer:8000
 */
class SpacyService
{
    private string $baseUrl;

    private const TIMEOUT = 10;

    // Минимальная длина слова для обработки
    private const MIN_LENGTH = 2;

    // Символы которые точно мусор
    private const JUNK_PATTERN = '/[\d\/\\\\@#$%^&*<>]/u';

    public function __construct()
    {
        $this->baseUrl = rtrim(config('import.spacy_url', 'http://lemmatizer:8000'), '/');
    }

    // -------------------------------------------------------------------------
    // Публичный API (совместим с HunspellService)
    // -------------------------------------------------------------------------

    /**
     * Анализирует одно слово.
     * Возвращает SpacyResult совместимый с HunspellResult по интерфейсу.
     */
    public function analyze(string $word): SpacyResult
    {
        // Быстрые фильтры без обращения к сервису
        if ($this->isObviousJunk($word)) {
            return SpacyResult::junk($word);
        }

        $response = $this->post('/lemmatize', ['word' => $word]);

        if ($response === null) {
            Log::channel('import')->warning('SpacyService: lemmatize failed', [
                'word' => $word,
            ]);
            // Fallback — возвращаем слово как есть
            return SpacyResult::unknown($word);
        }

        return new SpacyResult(
            original:      $word,
            lemma:         $response['lemma'],
            isKnown:       true,
            isProperNoun:  $response['is_proper_noun'] ?? false,
        );
    }

    /**
     * Разбивает предложение на леммы всех слов.
     * Используется в Этапе 2 для перекрёстной прошивки примеров.
     *
     * @return string[]
     */
    public function lemmatizeSentence(string $sentence): array
    {
        $response = $this->post('/lemmatize-sentence', ['sentence' => $sentence]);

        if ($response === null) {
            Log::channel('import')->warning('SpacyService: lemmatize-sentence failed', [
                'sentence' => mb_substr($sentence, 0, 60),
            ]);
            return [];
        }

        return $response['lemmas'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Внутренняя логика
    // -------------------------------------------------------------------------

    private function isObviousJunk(string $word): bool
    {
        // Слишком короткое
        if (mb_strlen($word) < self::MIN_LENGTH) return true;

        // Содержит явный мусор
        if (preg_match(self::JUNK_PATTERN, $word)) return true;

        // Только заглавные буквы длиннее 2 символов — аббревиатура
        if (mb_strlen($word) > 2 && preg_match('/^[A-ZÄÖÜ]+$/u', $word)) return true;

        return false;
    }

    private function post(string $endpoint, array $payload): ?array
    {
        $json    = json_encode($payload);
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($json),
                ]),
                'content' => $json,
                'timeout' => self::TIMEOUT,
            ],
        ]);

        $result = @file_get_contents($this->baseUrl . $endpoint, false, $context);

        if ($result === false) {
            return null;
        }

        $decoded = json_decode($result, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}


/**
 * Value object — результат анализа одного слова.
 * Совместим с HunspellResult по полям.
 */
readonly class SpacyResult
{
    public function __construct(
        public string  $original,
        public ?string $lemma,
        public bool    $isKnown,
        public bool    $isProperNoun = false,
    ) {}

    public static function junk(string $original): self
    {
        return new self($original, null, false, false);
    }

    public static function unknown(string $original): self
    {
        return new self($original, $original, true, false);
    }

    public function isInflected(): bool
    {
        return $this->isKnown && $this->lemma !== $this->original;
    }
}
