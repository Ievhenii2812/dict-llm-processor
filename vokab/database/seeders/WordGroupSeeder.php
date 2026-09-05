<?php

namespace Database\Seeders;

use App\Models\WordGroup;
use Illuminate\Database\Seeder;

class WordGroupSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            [
                'external_id' => 1,
                'group_code'  => 'arbeit_beruf',
                'name_en'     => 'Work & Career',
                'name_ru'     => 'Работа и карьера',
                'name_uk'     => 'Робота та кар\'єра',
            ],
            [
                'external_id' => 2,
                'group_code'  => 'umwelt_natur',
                'name_en'     => 'Environment & Nature',
                'name_ru'     => 'Природа и экология',
                'name_uk'     => 'Природа та екологія',
            ],
            [
                'external_id' => 3,
                'group_code'  => 'gesundheit_medizin',
                'name_en'     => 'Health & Medicine',
                'name_ru'     => 'Здоровье и медицина',
                'name_uk'     => 'Здоров\'я та медицина',
            ],
            [
                'external_id' => 4,
                'group_code'  => 'reisen_verkehr',
                'name_en'     => 'Travel & Transport',
                'name_ru'     => 'Путешествия и транспорт',
                'name_uk'     => 'Подорожі та транспорт',
            ],
            [
                'external_id' => 5,
                'group_code'  => 'bildung_studium',
                'name_en'     => 'Education & Study',
                'name_ru'     => 'Образование и учёба',
                'name_uk'     => 'Освіта та навчання',
            ],
            [
                'external_id' => 6,
                'group_code'  => 'gesellschaft_politik',
                'name_en'     => 'Society & Politics',
                'name_ru'     => 'Общество и политика',
                'name_uk'     => 'Суспільство та політика',
            ],
            [
                'external_id' => 7,
                'group_code'  => 'freizeit_unterhaltung',
                'name_en'     => 'Leisure & Entertainment',
                'name_ru'     => 'Досуг и развлечения',
                'name_uk'     => 'Дозвілля та розваги',
            ],
            [
                'external_id' => 8,
                'group_code'  => 'beziehungen_familie',
                'name_en'     => 'Relationships & Family',
                'name_ru'     => 'Отношения и семья',
                'name_uk'     => 'Стосунки та сім\'я',
            ],
            [
                'external_id' => 9,
                'group_code'  => 'konsum_geld',
                'name_en'     => 'Shopping & Money',
                'name_ru'     => 'Покупки и деньги',
                'name_uk'     => 'Покупки та гроші',
            ],
            [
                'external_id' => 10,
                'group_code'  => 'wohnen_alltag',
                'name_en'     => 'Home & Everyday Life',
                'name_ru'     => 'Дом и повседневная жизнь',
                'name_uk'     => 'Дім та повсякденне життя',
            ],
        ];

        foreach ($groups as $group) {
            WordGroup::updateOrCreate(
                ['group_code' => $group['group_code']],
                $group
            );
        }
    }
}
