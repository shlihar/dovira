<?php

use App\Services\AiEnrichment\Providers\LocalHeuristicProfileEnrichmentProvider;
use App\Services\AiEnrichment\Providers\OpenAiProfileEnrichmentProvider;

return [
    /*
    |--------------------------------------------------------------------------
    | Profile Enrichment Provider
    |--------------------------------------------------------------------------
    |
    | The local provider is deterministic and safe for MVP imports. Real search
    | and AI providers can replace it without changing the Filament workflow.
    |
    */

    'provider' => env('AI_ENRICHMENT_PROVIDER', 'local'),

    'providers' => [
        'local' => LocalHeuristicProfileEnrichmentProvider::class,
        'openai' => OpenAiProfileEnrichmentProvider::class,
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'endpoint' => env('AI_ENRICHMENT_OPENAI_ENDPOINT', 'https://api.openai.com/v1/responses'),
        'model' => env('AI_ENRICHMENT_OPENAI_MODEL', 'gpt-4.1-mini'),
        'timeout' => env('AI_ENRICHMENT_OPENAI_TIMEOUT', 15),
        'logo_search_timeout' => env('AI_ENRICHMENT_OPENAI_LOGO_SEARCH_TIMEOUT', 12),
        'review_search_timeout' => env('AI_ENRICHMENT_OPENAI_REVIEW_SEARCH_TIMEOUT', 10),
        'review_search_passes' => env('AI_ENRICHMENT_OPENAI_REVIEW_SEARCH_PASSES', 2),
        'review_search_fallback_only' => env('AI_ENRICHMENT_OPENAI_REVIEW_SEARCH_FALLBACK_ONLY', true),
        'secondary_search_budget_seconds' => env('AI_ENRICHMENT_SECONDARY_SEARCH_BUDGET_SECONDS', 8),
        'retries' => env('AI_ENRICHMENT_OPENAI_RETRIES', 1),
        'retry_sleep_ms' => env('AI_ENRICHMENT_OPENAI_RETRY_SLEEP_MS', 750),
        'web_search' => env('AI_ENRICHMENT_OPENAI_WEB_SEARCH', false),
        'web_search_tool' => env('AI_ENRICHMENT_OPENAI_WEB_SEARCH_TOOL', 'web_search_preview'),
        'prefer_local_for_structured_rows' => env('AI_ENRICHMENT_OPENAI_PREFER_LOCAL_FOR_STRUCTURED_ROWS', true),
    ],

    'google_maps' => [
        'enabled' => env('AI_ENRICHMENT_GOOGLE_MAPS_REVIEWS', false),
        // Modes:
        // - api_full: search + place details with reviews
        // - api_budget: constrained queries + place details with reviews
        // - links_only: only external Google Maps place links (no review text extraction from Places details)
        'mode' => env('AI_ENRICHMENT_GOOGLE_MAPS_MODE', 'api_budget'),
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
        'search_endpoint' => env('AI_ENRICHMENT_GOOGLE_MAPS_SEARCH_ENDPOINT', env('AI_ENRICHMENT_GOOGLE_MAPS_ENDPOINT', 'https://places.googleapis.com/v1/places:searchText')),
        'details_endpoint' => env('AI_ENRICHMENT_GOOGLE_MAPS_DETAILS_ENDPOINT', 'https://places.googleapis.com/v1'),
        'language_code' => env('AI_ENRICHMENT_GOOGLE_MAPS_LANGUAGE', 'uk'),
        'timeout' => env('AI_ENRICHMENT_GOOGLE_MAPS_TIMEOUT', 4),
        'max_result_count' => env('AI_ENRICHMENT_GOOGLE_MAPS_MAX_RESULT_COUNT', 8),
        'secondary_search_budget_seconds' => env('AI_ENRICHMENT_GOOGLE_MAPS_SECONDARY_SEARCH_BUDGET_SECONDS', 8),
        'use_for_logo' => env('AI_ENRICHMENT_GOOGLE_MAPS_USE_FOR_LOGO', false),
    ],

    'top20' => [
        'enabled' => env('AI_ENRICHMENT_TOP20_ENABLED', true),
        'timeout' => env('AI_ENRICHMENT_TOP20_TIMEOUT', 12),
        'max_reviews' => env('AI_ENRICHMENT_TOP20_MAX_REVIEWS', 1000),
    ],

    'review_directories' => [
        'enabled' => env('AI_ENRICHMENT_REVIEW_DIRECTORIES_ENABLED', true),
        'timeout' => env('AI_ENRICHMENT_REVIEW_DIRECTORIES_TIMEOUT', 12),
        'max_reviews' => env('AI_ENRICHMENT_REVIEW_DIRECTORIES_MAX_REVIEWS', 1000),
        'discovery_enabled' => env('AI_ENRICHMENT_REVIEW_DIRECTORIES_DISCOVERY_ENABLED', true),
        'discovery_timeout' => env('AI_ENRICHMENT_REVIEW_DIRECTORIES_DISCOVERY_TIMEOUT', 6),
        'discovery_max_urls' => env('AI_ENRICHMENT_REVIEW_DIRECTORIES_DISCOVERY_MAX_URLS', 12),
        'discovery_queries_per_host' => env('AI_ENRICHMENT_REVIEW_DIRECTORIES_DISCOVERY_QUERIES_PER_HOST', 3),
        'hosts' => [
            'vidhuk.ua',
            'ua.realreviews.io',
            'list.in.ua',
            'trustpilot.com',
            '2gis.ua',
            '2gis.com',
        ],
    ],
    // AI-досьє профілю: веб-пошук по реєстрах/судах/ЗМІ, сильніша модель.
    'dossier' => [
        'model' => env('AI_DOSSIER_OPENAI_MODEL', 'gpt-5.1'),
        'timeout' => (int) env('AI_DOSSIER_TIMEOUT', 600),
        'reasoning_effort' => env('AI_DOSSIER_REASONING_EFFORT', 'medium'),
        // Для gpt-5 покоління тип інструмента — 'web_search'; сервіс сам
        // відкотиться на 'web_search_preview', якщо API відхилить.
        'web_search_tool' => env('AI_DOSSIER_WEB_SEARCH_TOOL', 'web_search'),
    ],

    'review_analysis' => [
        'enabled' => env('AI_REVIEW_ANALYSIS_ENABLED', true),
        'model' => env('AI_REVIEW_ANALYSIS_OPENAI_MODEL', 'gpt-4.1'),
        'timeout' => env('AI_REVIEW_ANALYSIS_TIMEOUT', 20),
        'queue' => env('AI_REVIEW_ANALYSIS_QUEUE', 'ai-review-analysis'),
    ],

    'connection' => env('AI_ENRICHMENT_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
    'queue' => env('AI_ENRICHMENT_QUEUE', 'ai-enrichment'),
    'priority_queue' => env('AI_ENRICHMENT_PRIORITY_QUEUE', 'ai-enrichment-priority'),

    'worker' => [
        'auto_start' => env('AI_ENRICHMENT_AUTO_START_WORKER', env('APP_ENV', 'production') === 'local'),
        'processes' => env('AI_ENRICHMENT_QUEUE_WORKER_PROCESSES', 1),
        'sleep' => env('AI_ENRICHMENT_QUEUE_WORKER_SLEEP', 1),
        'tries' => env('AI_ENRICHMENT_QUEUE_WORKER_TRIES', 3),
        'backoff' => env('AI_ENRICHMENT_QUEUE_WORKER_BACKOFF', 5),
        'timeout' => env('AI_ENRICHMENT_QUEUE_WORKER_TIMEOUT', 1800),
        'max_time' => env('AI_ENRICHMENT_QUEUE_WORKER_MAX_TIME', 3600),
        'release_stale_reserved_after_seconds' => env('AI_ENRICHMENT_RELEASE_STALE_RESERVED_AFTER_SECONDS', 1800),
        'restore_batch_limit' => env('AI_ENRICHMENT_RESTORE_BATCH_LIMIT', 25),
        'restore_task_limit' => env('AI_ENRICHMENT_RESTORE_TASK_LIMIT', 100),
        'pid_file' => storage_path('app/ai-enrichment-queue-worker.pid'),
        'log_file' => storage_path('logs/ai-enrichment-queue-worker.log'),
    ],
];
