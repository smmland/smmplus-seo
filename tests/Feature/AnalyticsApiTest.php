<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'event_name' => 'page_view',
            'page_path' => '/en/blog/example',
            'page_title' => 'Example article',
            'page_type' => 'blog_post',
            'language' => 'en',
            'source' => 'google',
            'medium' => 'organic',
            'device_type' => 'mobile',
            'viewport_width' => 390,
            'is_landing' => true,
            'occurred_at' => now()->toIso8601String(),
        ], $overrides);
    }

    private function collect(array $events, array $headers = [])
    {
        return $this->call(
            'POST',
            '/api/analytics/collect',
            [],
            [],
            [],
            array_merge([
                'HTTP_ORIGIN' => 'https://smm.plus',
                'HTTP_CF_CONNECTING_IP' => '8.8.8.8',
                'HTTP_CF_IPCOUNTRY' => 'FR',
                'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
            ], $headers),
            json_encode(['site_id' => 'smm-plus', 'events' => $events]),
        );
    }

    public function test_it_collects_a_valid_text_plain_beacon_payload(): void
    {
        $response = $this->collect([$this->event()]);

        $response->assertOk()->assertJson(['ok' => true, 'accepted' => 1]);
        $event = AnalyticsEvent::query()->firstOrFail();
        $this->assertSame('/en/blog/example', $event->page_path);
        $this->assertSame('FR', $event->country_code);
        $this->assertNotSame('8.8.8.8', $event->daily_client_hash);
        $this->assertTrue($event->is_landing);
    }

    public function test_duplicate_event_ids_are_idempotent(): void
    {
        $event = $this->event();

        $this->collect([$event])->assertOk();
        $this->collect([$event])->assertOk()->assertJson(['accepted' => 0]);

        $this->assertSame(1, AnalyticsEvent::query()->count());
    }

    public function test_a_disallowed_origin_is_rejected(): void
    {
        $response = $this->collect([$this->event()], ['HTTP_ORIGIN' => 'https://evil.example']);

        $response->assertForbidden();
        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_event_names_are_allowlisted(): void
    {
        $response = $this->collect([$this->event(['event_name' => 'arbitrary_database_noise'])]);

        $response->assertUnprocessable();
        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_raw_ip_is_not_stored_anywhere_in_the_event(): void
    {
        $this->collect([$this->event()])->assertOk();

        $row = AnalyticsEvent::query()->firstOrFail()->toArray();
        $this->assertArrayNotHasKey('ip', $row);
        $this->assertStringNotContainsString('8.8.8.8', json_encode($row));
    }
}
