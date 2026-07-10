<?php

return [
    // Source of truth: the main site's own sitemap that we mirror and categorize.
    'source_sitemap_url' => env('SOURCE_SITEMAP_URL', 'https://smm.plus/sitemap.xml'),

    // Fallback used until an admin overrides it from the panel (persisted in the settings table).
    'default_sync_interval_hours' => (int) env('DEFAULT_SYNC_INTERVAL_HOURS', 6),
];
