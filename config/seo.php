<?php

/* Site index eligibility policy. These rules do not establish a Google penalty. */

return [
    // Режим індексації профілів:
    //   all_public — усі активні, опубліковані профілі, видимі в каталозі
    //                (типово, за рішенням власника 22.09.2026).
    //                Чернетки, приховані та карантинні записи не відкриваємо.
    //   reviews_dossier_ai — лише профілі з відгуком, досьє або AI-аналізом;
    //   reviews_contacts_dossier — старий режим «є контакти або відгуки».
    'profile_indexing_mode' => env('SEO_PROFILE_INDEXING_MODE', 'all_public'),

    'strict_profile_indexing' => (bool) env('SEO_STRICT_PROFILE_INDEXING', false),

    // Existing threshold; length alone is not a guarantee of useful content.
    'strict_min_description' => (int) env('SEO_STRICT_MIN_DESCRIPTION', 500),
    // Explicit indexing approval; keep the dated cohort record with the change.
    'reviewed_profile_ids' => require __DIR__.'/seo_profile_ids.php',
];
