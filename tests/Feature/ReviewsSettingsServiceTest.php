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

    public function test_known_prompt_pages_default_to_enabled(): void
    {
        $settings = app(ReviewsSettingsService::class);

        foreach (array_keys(ReviewsSettingsService::PROMPT_PAGES) as $page) {
            $this->assertTrue($settings->isPromptEnabledFor($page), "{$page} should default to enabled.");
        }
    }

    public function test_an_unknown_page_is_never_enabled(): void
    {
        $this->assertFalse(app(ReviewsSettingsService::class)->isPromptEnabledFor('some_random_page'));
    }

    public function test_disabling_one_page_does_not_affect_the_others(): void
    {
        $settings = app(ReviewsSettingsService::class);

        $settings->setPromptEnabledFor('dashboard', false);

        $this->assertFalse($settings->isPromptEnabledFor('dashboard'));
        $this->assertTrue($settings->isPromptEnabledFor('ticket_reply'));
        $this->assertTrue($settings->isPromptEnabledFor('order_status'));
        $this->assertTrue($settings->isPromptEnabledFor('refill'));
    }

    public function test_setting_an_unknown_page_is_a_no_op(): void
    {
        $settings = app(ReviewsSettingsService::class);

        $settings->setPromptEnabledFor('not_a_real_page', true);

        $this->assertFalse($settings->isPromptEnabledFor('not_a_real_page'));
    }
}
