<?php

namespace Tests\Feature;

use Tests\TestCase;

class AnalyticsTrackerTest extends TestCase
{
    public function test_tracker_is_served_through_laravel(): void
    {
        $response = $this->get('/analytics/tracker.js');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Cross-Origin-Resource-Policy', 'cross-origin');

        $contents = file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertIsString($contents);
        $this->assertStringContainsString('window.smmAnalytics', $contents);
        $this->assertStringContainsString('context: function', $contents);
        $this->assertStringContainsString('Amounts/statuses must never be reported by the browser', $contents);
    }
}
