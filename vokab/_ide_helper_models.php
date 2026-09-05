<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $id
 * @property int $external_word_id
 * @property string|null $comparative
 * @property string|null $superlative
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjective newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjective newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjective query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjective whereComparative($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjective whereExternalWordId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjective whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Adjective whereSuperlative($value)
 */
	class Adjective extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $external_id
 * @property string $sentence
 * @property bool $is_ai_generated
 * @property string|null $translation_en
 * @property string|null $translation_ru
 * @property string|null $translation_uk
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Word> $words
 * @property-read int|null $words_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Example newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Example newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Example query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Example whereExternalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Example whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Example whereIsAiGenerated($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Example whereSentence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Example whereTranslationEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Example whereTranslationRu($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Example whereTranslationUk($value)
 */
	class Example extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $external_word_id
 * @property int $external_example_id
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExampleWord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExampleWord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExampleWord query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExampleWord whereExternalExampleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExampleWord whereExternalWordId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExampleWord whereId($value)
 */
	class ExampleWord extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportState newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportState newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportState query()
 */
	class ImportState extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $external_word_id
 * @property string $gender
 * @property string $plural
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Noun newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Noun newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Noun query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Noun whereExternalWordId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Noun whereGender($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Noun whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Noun wherePlural($value)
 */
	class Noun extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $external_id
 * @property string $phrase
 * @property string|null $meaning_de
 * @property string|null $meaning_en
 * @property string|null $meaning_ru
 * @property string|null $meaning_uk
 * @property string|null $translation_en
 * @property string|null $translation_ru
 * @property string|null $translation_uk
 * @property string|null $situation_question
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phrase newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phrase newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phrase query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phrase whereExternalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phrase whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phrase whereMeaningDe($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phrase whereMeaningEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phrase whereMeaningRu($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phrase whereMeaningUk($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phrase wherePhrase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phrase whereSituationQuestion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phrase whereTranslationEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phrase whereTranslationRu($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Phrase whereTranslationUk($value)
 */
	class Phrase extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUuid($value)
 */
	class User extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $external_word_id
 * @property bool $is_regular
 * @property string|null $preterite
 * @property string|null $participle_ii
 * @property string $aux_verb
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\VerbPreposition> $prepositions
 * @property-read int|null $prepositions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Verb newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Verb newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Verb query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Verb whereAuxVerb($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Verb whereExternalWordId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Verb whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Verb whereIsRegular($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Verb whereParticipleIi($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Verb wherePreterite($value)
 */
	class Verb extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $external_word_id
 * @property string $preposition
 * @property string $noun_case
 * @property string|null $translation_en
 * @property string|null $translation_ru
 * @property string|null $translation_uk
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VerbPreposition newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VerbPreposition newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VerbPreposition query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VerbPreposition whereExternalWordId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VerbPreposition whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VerbPreposition whereNounCase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VerbPreposition wherePreposition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VerbPreposition whereTranslationEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VerbPreposition whereTranslationRu($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VerbPreposition whereTranslationUk($value)
 */
	class VerbPreposition extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $external_id
 * @property string $word
 * @property int|null $frequency_rank
 * @property \App\Enums\PartOfSpeech $part_of_speech
 * @property int|null $homonym_index
 * @property string|null $translation_en
 * @property string|null $translation_ru
 * @property string|null $translation_uk
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Adjective|null $adjective
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Example> $examples
 * @property-read int|null $examples_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WordGroup> $groups
 * @property-read int|null $groups_count
 * @property-read \App\Models\Noun|null $noun
 * @property-read \App\Models\Verb|null $verb
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Word newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Word newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Word query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Word whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Word whereExternalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Word whereFrequencyRank($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Word whereHomonymIndex($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Word whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Word wherePartOfSpeech($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Word whereTranslationEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Word whereTranslationRu($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Word whereTranslationUk($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Word whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Word whereWord($value)
 */
	class Word extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $external_id
 * @property string $group_code
 * @property string $name_en
 * @property string $name_ru
 * @property string $name_uk
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WordGroup newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WordGroup newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WordGroup query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WordGroup whereExternalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WordGroup whereGroupCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WordGroup whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WordGroup whereNameEn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WordGroup whereNameRu($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WordGroup whereNameUk($value)
 */
	class WordGroup extends \Eloquent {}
}

