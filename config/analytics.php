<?php

return [
    /*
    | The main ordering backend signs purchase and refund webhooks with this secret.
    | It must never be exposed to tracker.js or any browser-rendered template.
    */
    'purchase_webhook_secret' => env('ANALYTICS_PURCHASE_WEBHOOK_SECRET'),

    // Signed requests outside this window are rejected to limit replay attempts.
    'purchase_webhook_tolerance_seconds' => (int) env('ANALYTICS_PURCHASE_WEBHOOK_TOLERANCE', 300),
];
