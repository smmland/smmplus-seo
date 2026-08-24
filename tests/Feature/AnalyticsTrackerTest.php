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
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $contents = file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertIsString($contents);
        $this->assertStringContainsString('window.smmAnalytics', $contents);
    }
}
