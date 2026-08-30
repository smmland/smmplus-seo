<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsPurchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsPurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-purchase-webhook-secret-at-least-32-bytes';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('analytics.purchase_webhook_secret', $this->secret);
        Carbon::setTestNow('2026-08-26 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'site_id' => 'smm-plus',
            'event_id' => (string) Str::uuid(),
            'order_id' => 'ORD-1001',
            'status' => 'paid',
            'gross_amount' => '24.50',
            'refunded_amount' => '0',
            'currency' => 'usd',
            'paid_at' => now()->subMinute()->toIso8601String(),
            'updated_at' => now()->subMinute()->toIso8601String(),
        ], $overrides);
    }

    private function send(array $payload, ?string $secret = null, ?int $timestamp = null)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp ??= now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? $this->secret);

        return $this->call('POST', '/api/analytics/purchases', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SMM_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SMM_SIGNATURE' => 'sha256='.$signature,
        ], $body);
    }

    public function test_unsigned_browser_requests_cannot_submit_revenue(): void
    {
        $this->postJson('/api/analytics/purchases', $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('analytics_purchases', 0);
    }

    public function test_wrong_and_expired_signatures_are_rejected(): void
    {
        $this->send($this->payload(), 'wrong-secret')->assertUnauthorized();
        $this->send($this->payload(), timestamp: now()->subMinutes(10)->timestamp)->assertUnauthorized();
        $this->assertDatabaseCount('analytics_purchases', 0);
    }

    public function test_a_signed_paid_order_is_stored_and_attributed_to_its_analytics_session(): void
    {
        $visitorId = (string) Str::uuid();
        $sessionId = (string) Str::uuid();
        AnalyticsEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'site_id' => 'smm-plus',
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'event_name' => 'page_view',
            'page_path' => '/en/telegram-members',
            'page_title' => 'Telegram members',
            'page_type' => 'service',
            'is_landing' => true,
            'language' => 'en',
            'source' => 'google',
            'medium' => 'organic',
            'device_type' => 'mobile',
            'user_state' => 'authenticated',
            'country_code' => 'US',
            'occurred_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
        ]);

        $this->send($this->payload(['visitor_id' => $visitorId, 'session_id' => $sessionId]))
            ->assertOk()
            ->assertJson(['ok' => true, 'accepted' => 1, 'duplicate' => false, 'stale' => false]);

        $purchase = AnalyticsPurchase::query()->firstOrFail();
        $this->assertSame('USD', $purchase->currency);
        $this->assertSame('24.500000', $purchase->gross_amount);
        $this->assertSame('/en/telegram-members', $purchase->landing_page);
        $this->assertSame('google', $purchase->source);
        $this->assertSame('authenticated', $purchase->user_state);
    }

    public function test_replayed_event_ids_and_repeated_orders_do_not_double_count(): void
    {
        $payload = $this->payload();
        $this->send($payload)->assertOk()->assertJson(['accepted' => 1]);
        $this->send($payload)->assertOk()->assertJson(['accepted' => 0, 'duplicate' => true]);

        $this->send($this->payload([
            'event_id' => (string) Str::uuid(),
            'order_id' => $payload['order_id'],
            'updated_at' => now()->toIso8601String(),
        ]))->assertOk()->assertJson(['accepted' => 1]);

        $this->assertDatabaseCount('analytics_purchases', 1);
        $this->assertDatabaseCount('analytics_purchase_events', 2);
    }

    public function test_refunds_update_the_existing_order_and_stale_updates_cannot_undo_them(): void
    {
        $this->send($this->payload())->assertOk();
        $this->send($this->payload([
            'event_id' => (string) Str::uuid(),
            'status' => 'partially_refunded',
            'refunded_amount' => '4.50',
            'updated_at' => now()->toIso8601String(),
        ]))->assertOk();

        $this->send($this->payload([
            'event_id' => (string) Str::uuid(),
            'status' => 'paid',
            'refunded_amount' => '0',
            'updated_at' => now()->subMinute()->toIso8601String(),
        ]))->assertOk()->assertJson(['accepted' => 0, 'stale' => true]);

        $purchase = AnalyticsPurchase::query()->firstOrFail();
        $this->assertSame('partially_refunded', $purchase->status);
        $this->assertSame('4.500000', $purchase->refunded_amount);
        $this->assertDatabaseCount('analytics_purchases', 1);
    }

    public function test_refund_amounts_and_unknown_fields_are_strictly_validated(): void
    {
        $this->send($this->payload([
            'status' => 'refunded',
            'refunded_amount' => '5.00',
        ]))->assertUnprocessable();

        $this->send($this->payload(['customer_email' => 'must-not-be-stored@example.com']))
            ->assertUnprocessable();

        $this->assertDatabaseCount('analytics_purchases', 0);
    }
}
