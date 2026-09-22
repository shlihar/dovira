<?php

/* Site index eligibility policy. These rules do not establish a Google penalty. */

return [
    // Режим індексації профілів:
    //   reviews_dossier_ai — індексуємо, якщо є відгук, досьє або AI-аналіз (типово);
    //   all_public         — усі публічні (так було до 22.09.2026, саме через це
    //                        в sitemap потрапляли 34 тис. сторінок, з них 86%
    //                        без власного контенту);
    //   reviews_contacts_dossier — старий режим «є контакти або відгуки».
    'profile_indexing_mode' => env('SEO_PROFILE_INDEXING_MODE', 'reviews_dossier_ai'),

    'strict_profile_indexing' => (bool) env('SEO_STRICT_PROFILE_INDEXING', false),

    // Existing threshold; length alone is not a guarantee of useful content.
    'strict_min_description' => (int) env('SEO_STRICT_MIN_DESCRIPTION', 500),
    // Explicit indexing approval; keep the dated cohort record with the change.
    'reviewed_profile_ids' => require __DIR__.'/seo_profile_ids.php',
];
