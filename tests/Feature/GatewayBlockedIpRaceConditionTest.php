<?php

namespace Tests\Feature;

use App\Models\GatewayBlockedIp;
use App\Services\GatewaySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Reproduces a real production crash: two near-simultaneous block attempts for the same IP
// (e.g. two rapid abusive requests landing in the same millisecond) both read "no existing row"
// from firstOrNew() before either has saved, then both try to INSERT - the `ip` column is
// unique, so the second insert throws UniqueConstraintViolationException. That used to bubble up
// uncaught as a 500. Simulated here via a `saving` listener that inserts a "concurrent" row for
// the same IP right as our own save is about to run, forcing a real unique-constraint failure on
// the very first attempt so the retry path in GatewayBlockedIp::upsertByIp() is exercised for
// real rather than mocked.
class GatewayBlockedIpRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        GatewayBlockedIp::flushEventListeners();

        parent::tearDown();
    }

    private function simulateConcurrentInsertOnFirstSave(string $ip, array $concurrentAttributes): void
    {
        $fired = false;

        GatewayBlockedIp::saving(function (GatewayBlockedIp $record) use ($ip, $concurrentAttributes, &$fired) {
            if ($fired || $record->exists || $record->ip !== $ip) {
                return;
            }

            $fired = true;

            GatewayBlockedIp::withoutEvents(function () use ($ip, $concurrentAttributes) {
                GatewayBlockedIp::query()->create(array_merge(['ip' => $ip], $concurrentAttributes));
            });
        });
    }

    public function test_block_with_escalation_recovers_from_a_concurrent_duplicate_insert(): void
    {
        $this->simulateConcurrentInsertOnFirstSave('203.0.113.5', [
            'is_active' => true, 'offense_count' => 1, 'note' => 'concurrent request won the race',
        ]);

        $record = GatewayBlockedIp::blockWithEscalation('203.0.113.5', 'Auto-blocked: test', app(GatewaySettingsService::class));

        $this->assertSame(1, GatewayBlockedIp::query()->where('ip', '203.0.113.5')->count(), 'The race must not leave two rows for the same IP.');
        $this->assertSame(2, $record->offense_count, 'The retry should escalate on top of the concurrent insert it lost the race to, not silently drop its own offense.');
        $this->assertTrue($record->is_active);
    }

    public function test_block_for_duration_recovers_from_a_concurrent_duplicate_insert(): void
    {
        $this->simulateConcurrentInsertOnFirstSave('203.0.113.9', [
            'is_active' => true, 'note' => 'concurrent request won the race',
        ]);

        $record = GatewayBlockedIp::blockForDuration('203.0.113.9', 'Tor exit node', 3);

        $this->assertSame(1, GatewayBlockedIp::query()->where('ip', '203.0.113.9')->count());
        $this->assertTrue($record->is_active);
        $this->assertNotNull($record->blocked_until);
    }
}
