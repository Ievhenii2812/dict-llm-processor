<?php

return [
    'leipzig_file'                => env('LEIPZIG_FILE', storage_path('app/private/deu_news_2025_1M-words.txt')),
    'leipzig_web_file'            => env('LEIPZIG_WEB_FILE', storage_path('app/private/deu-de_web-public_2019_1M-words.txt')),
    'translator_url'              => env('TRANSLATOR_URL', 'http://vokab_translator:8000'),
    'google_credentials_path'     => env('GOOGLE_APPLICATION_CREDENTIALS'),
    'google_cloud_project'        => env('GOOGLE_CLOUD_PROJECT'),
    'google_cloud_location'       => env('GOOGLE_CLOUD_LOCATION', 'us-east5'),
    'gemini_pro_model'            => env('GEMINI_PRO_MODEL', 'gemini-3.1-pro'),
    'gemini_flash_model'          => env('GEMINI_FLASH_MODEL', 'gemini-3.6-flash'),
    'gemini_api_key'              => env('GEMINI_API_KEY'),
    'spacy_url'                   => env('SPACY_URL', 'http://lemmatizer:8000'),
    'wiktionary_dump'             => env('WIKTIONARY_DUMP', storage_path('app/private/dewiktionary-latest-pages-articles.xml.bz2')),
    'wiktionary_index'            => env('WIKTIONARY_INDEX', storage_path('app/private/wiktionary.index')),
    'leipzig_sentences_news_file' => env('LEIPZIG_SENTENCES_NEWS_FILE', storage_path('app/private/deu_news_2025_1M-sentences.txt')),
    'leipzig_sentences_web_file'  => env('LEIPZIG_SENTENCES_WEB_FILE', storage_path('app/private/deu-de_web-public_2019_1M-sentences.txt')),
    'leipzig_sentences_index'     => storage_path('app/private/leipzig_sentences.sqlite'),
];
