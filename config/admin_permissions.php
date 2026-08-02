<?php

return [
    'roles' => [
        'admin' => ['*'],
        'moderator' => [
            'dashboard.view',
            'profiles.view', 'profiles.update',
            'reviews.view', 'reviews.update',
            'categories.view',
            'claims.view', 'claims.update',
            'reports.view', 'reports.update',
            'notifications.view',
            'audit.view',
            'analytics.view',
        ],
        'editor' => [
            'dashboard.view',
            'profiles.view', 'profiles.create', 'profiles.update',
            'categories.view', 'categories.create', 'categories.update',
            'cms.view', 'cms.create', 'cms.update',
            'pages.view', 'pages.create', 'pages.update',
            'settings.view', 'settings.update',
        ],
        'support' => [
            'dashboard.view',
            'users.view', 'users.update',
            'notifications.view', 'notifications.create', 'notifications.update',
            'emails.view', 'emails.create',
            'claims.view',
            'reports.view',
        ],
        'user' => [],
    ],
];
