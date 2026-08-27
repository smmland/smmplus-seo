<?php

namespace Tests\Feature;

use App\Services\TelegramAutoViewsSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramAutoViewsSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_when_nothing_saved_yet(): void
    {
        $settings = app(TelegramAutoViewsSettingsService::class);

        $this->assertFalse($settings->isEnabled());
        $this->assertNull($settings->getUpstreamId());
        $this->assertNull($settings->getServiceId());
        $this->assertSame(500, $settings->getTarget());
        $this->assertSame(30, $settings->getLookbackDays());
        $this->assertSame(12, $settings->getCooldownHours());
        $this->assertSame(20, $settings->getMaxPostsPerRun());
    }

    public function test_saves_and_reads_back_all_fields(): void
    {
        $settings = app(TelegramAutoViewsSettingsService::class);

        $settings->setSettings(true, 3, '4512', 500, 14, 6, 10);

        $this->assertTrue($settings->isEnabled());
        $this->assertSame(3, $settings->getUpstreamId());
        $this->assertSame('4512', $settings->getServiceId());
        $this->assertSame(500, $settings->getTarget());
        $this->assertSame(14, $settings->getLookbackDays());
        $this->assertSame(6, $settings->getCooldownHours());
        $this->assertSame(10, $settings->getMaxPostsPerRun());
    }

    public function test_disabling_after_enabling_is_read_back_correctly(): void
    {
        $settings = app(TelegramAutoViewsSettingsService::class);

        $settings->setSettings(true, 3, '4512', 500);
        $settings->setSettings(false, 3, '4512', 500);

        $this->assertFalse($settings->isEnabled());
    }

    public function test_clearing_the_upstream_and_service_id_reads_back_as_null(): void
    {
        $settings = app(TelegramAutoViewsSettingsService::class);

        $settings->setSettings(true, 3, '4512', 500);
        $settings->setSettings(true, null, null, 500);

        $this->assertNull($settings->getUpstreamId());
        $this->assertNull($settings->getServiceId());
    }
}
