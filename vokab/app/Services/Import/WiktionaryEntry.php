<?php

namespace App\Services\Import;

use App\Enums\PartOfSpeech;

class WiktionaryEntry
{
    public string       $word;
    public PartOfSpeech $partOfSpeech;
    public ?int         $homonymIndex;

    public ?string $gender      = null;
    public ?string $plural      = null;

    public bool    $isRegular    = true;
    public ?string $preterite    = null;
    public ?string $participleII = null;
    public string  $auxVerb      = 'haben';
    public array   $prepositions = [];

    public ?string $comparative = null;
    public ?string $superlative = null;

    public array $examples = [];
    public array $idioms   = [];

    public function __construct(
        string       $word,
        PartOfSpeech $partOfSpeech,
        ?int         $homonymIndex,
    ) {
        $this->word         = $word;
        $this->partOfSpeech = $partOfSpeech;
        $this->homonymIndex = $homonymIndex;
    }
}
