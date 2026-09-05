<?php

namespace App\Enums;

enum PartOfSpeech: int
{
    case Noun        = 1;
    case Verb        = 2;
    case Adjective   = 3;
    case Adverb      = 4;
    case Preposition = 5;
    case Conjunction = 6;
    case Pronoun     = 7;
    case Article     = 8;
    case Other       = 9;

    /**
     * Сопоставление строк из Wiktionary с Enum.
     * Ключи — фрагменты из поля «Wortart» немецкого Викисловаря.
     */
    public static function fromWiktionary(string $label): self
    {
        $label = mb_strtolower(trim($label));

        return match(true) {
            str_contains($label, 'substantiv')  => self::Noun,
            str_contains($label, 'verb')        => self::Verb,
            str_contains($label, 'adjektiv')    => self::Adjective,
            str_contains($label, 'adverb')      => self::Adverb,
            str_contains($label, 'präposition') => self::Preposition,
            str_contains($label, 'konjunktion') => self::Conjunction,
            str_contains($label, 'pronomen')    => self::Pronoun,
            str_contains($label, 'artikel')     => self::Article,
            default                             => self::Other,
        };
    }

    /**
     * Нужна ли грамматическая таблица для данной части речи?
     */
    public function hasGrammarTable(): bool
    {
        return in_array($this, [self::Noun, self::Verb, self::Adjective]);
    }
}
