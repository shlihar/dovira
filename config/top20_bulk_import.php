<?php

return [
    'http_timeout' => 15,
    'media_timeout' => 20,
    'media_disk' => env('TOP20_BULK_IMPORT_MEDIA_DISK', 'public'),
    'media_directory' => env('TOP20_BULK_IMPORT_MEDIA_DIRECTORY', 'catalog-media'),
    'media_max_bytes' => 6 * 1024 * 1024,
    'review_avatar_max_bytes' => 2 * 1024 * 1024,
    'max_reviews_per_profile' => (int) env('TOP20_BULK_IMPORT_MAX_REVIEWS_PER_PROFILE', 100),
    'download_review_avatars' => (bool) env('TOP20_BULK_IMPORT_DOWNLOAD_REVIEW_AVATARS', true),
    'max_listing_pages' => (int) env('TOP20_BULK_IMPORT_MAX_LISTING_PAGES', 250),
    'profile_fetch_concurrency' => (int) env('TOP20_BULK_IMPORT_PROFILE_FETCH_CONCURRENCY', 4),
    'review_fetch_concurrency' => (int) env('TOP20_BULK_IMPORT_REVIEW_FETCH_CONCURRENCY', 8),
    'queue_connection' => env('TOP20_BULK_IMPORT_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
    'priority_queue' => env('TOP20_BULK_IMPORT_PRIORITY_QUEUE', 'top20-imports-priority'),
    'queue' => env('TOP20_BULK_IMPORT_QUEUE', 'top20-imports'),
    'cities' => [
        'od' => [
            'name' => 'Одеса',
            'region' => 'Одеська область',
        ],
        'kyiv' => [
            'name' => 'Київ',
            'region' => 'місто Київ',
        ],
        'lviv' => [
            'name' => 'Львів',
            'region' => 'Львівська область',
        ],
        'dnepr' => [
            'name' => 'Дніпро',
            'region' => 'Дніпропетровська область',
        ],
        'harkov' => [
            'name' => 'Харків',
            'region' => 'Харківська область',
        ],
    ],
    'categories' => [
        'legal-services' => [
            'label' => 'Юридичні послуги',
            'category_slug' => 'catalog-yurydychni-poslugy',
            'subcategory_slug' => 'advokaty',
            'listing_urls' => [
                'od' => 'https://top20.ua/od/biznes-poslugi/advokatski-poslugi/',
            ],
        ],
        'medicine-health' => [
            'label' => 'МЕДИЦИНА ТА ЗДОРОВ\'Я',
            'category_slug' => 'catalog-medytsyna-ta-zdorov-ya',
            'listing_urls' => [],
        ],
        'construction-repair' => [
            'label' => 'БУДІВНИЦТВО ТА РЕМОНТ',
            'category_slug' => 'catalog-budivnytstvo-ta-remont',
            'listing_urls' => [],
        ],
        'education' => [
            'label' => 'ОСВІТА',
            'category_slug' => 'catalog-osvita',
            'listing_urls' => [],
        ],
        'weddings-events' => [
            'label' => 'ВЕСІЛЛЯ ТА ПОДІЇ',
            'category_slug' => 'catalog-vesillya-ta-podiyi',
            'listing_urls' => [],
        ],
        'beauty-care' => [
            'label' => 'КРАСА ТА ДОГЛЯД',
            'category_slug' => 'catalog-krasa-ta-doglyad',
            'listing_urls' => [],
        ],
        'animals' => [
            'label' => 'ТВАРИНИ',
            'category_slug' => 'catalog-tvaryny',
            'listing_urls' => [],
        ],
        'real-estate' => [
            'label' => 'НЕРУХОМІСТЬ',
            'category_slug' => 'catalog-neruhomist',
            'listing_urls' => [],
        ],
        'it-business-services' => [
            'label' => 'IT ТА БІЗНЕС-ПОСЛУГИ',
            'category_slug' => 'catalog-it-ta-biznes-poslugy',
            'listing_urls' => [],
        ],
        'bloggers' => [
            'label' => 'БЛОГЕРИ',
            'category_slug' => 'catalog-blogery',
            'listing_urls' => [],
        ],
    ],
];
