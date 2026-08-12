<?php

namespace Tests\Feature;

use App\Services\ReviewsSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewsSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_by_default(): void
    {
        $this->assertTrue(app(ReviewsSettingsService::class)->isEnabled());
    }

    public function test_disabling_and_reenabling_is_read_back_correctly(): void
    {
        $settings = app(ReviewsSettingsService::class);

        $settings->setEnabled(false);
        $this->assertFalse($settings->isEnabled());

        $settings->setEnabled(true);
        $this->assertTrue($settings->isEnabled());
    }
}
