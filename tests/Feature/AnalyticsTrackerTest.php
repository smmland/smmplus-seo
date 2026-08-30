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

    public function test_previous_sri_pinned_tracker_remains_available_during_deployment(): void
    {
        $response = $this->get('/analytics/tracker.js?v=1.29.0');
        $response->assertOk();

        $contents = file_get_contents($response->baseResponse->getFile()->getPathname());
        $hash = base64_encode(hash('sha384', $contents, true));

        $this->assertSame('mkqDT/47AOyPj1Nf6I77WYCGrjtXDyGs/rHArqg+40DmBIge7ugx/474uFYhZyAr', $hash);
        $this->assertStringNotContainsString('context: function', $contents);
    }
}
