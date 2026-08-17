<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\LoginSecuritySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LoginSecuritySettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recaptcha_is_disabled_by_default(): void
    {
        $settings = app(LoginSecuritySettingsService::class);

        $this->assertFalse($settings->isRecaptchaEnabled());
        $this->assertFalse($settings->isRecaptchaReady());
        $this->assertFalse($settings->requiresCaptcha('admin@example.com', '203.0.113.10'));
    }

    public function test_it_requires_recaptcha_after_the_configured_number_of_failures(): void
    {
        $settings = app(LoginSecuritySettingsService::class);
        $settings->setRecaptchaSettings(true, 'site-key', 'secret-key', 2, 30);

        $settings->recordFailure('admin@example.com', '203.0.113.10');
        $this->assertFalse($settings->requiresCaptcha('admin@example.com', '203.0.113.10'));

        $settings->recordFailure('admin@example.com', '203.0.113.10');
        $this->assertTrue($settings->requiresCaptcha('admin@example.com', '203.0.113.10'));

        $settings->clearFailures('admin@example.com', '203.0.113.10');
        $this->assertFalse($settings->requiresCaptcha('admin@example.com', '203.0.113.10'));
    }

    public function test_email_counter_triggers_challenge_across_different_ips(): void
    {
        $settings = app(LoginSecuritySettingsService::class);
        $settings->setRecaptchaSettings(true, 'site-key', 'secret-key', 1, 30);

        $settings->recordFailure('Admin@Example.com', '203.0.113.10');

        $this->assertTrue($settings->requiresCaptcha('admin@example.com', '203.0.113.99'));
    }

    public function test_secret_is_encrypted_and_google_response_is_verified(): void
    {
        Http::fake([
            'www.google.com/recaptcha/api/siteverify' => Http::response(['success' => true]),
        ]);

        $settings = app(LoginSecuritySettingsService::class);
        $settings->setRecaptchaSettings(true, 'site-key', 'secret-key', 1, 30);

        $this->assertNotSame('secret-key', Setting::query()->find('login_recaptcha_secret_key')->value);
        $this->assertTrue($settings->verifyRecaptcha('captcha-token', '203.0.113.10'));

        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://www.google.com/recaptcha/api/siteverify'
            && $request['secret'] === 'secret-key'
            && $request['response'] === 'captcha-token'
            && $request['remoteip'] === '203.0.113.10'
        );
    }

    public function test_verification_fails_closed_when_google_rejects_the_token(): void
    {
        Http::fake([
            'www.google.com/recaptcha/api/siteverify' => Http::response(['success' => false]),
        ]);

        $settings = app(LoginSecuritySettingsService::class);
        $settings->setRecaptchaSettings(true, 'site-key', 'secret-key', 1, 30);

        $this->assertFalse($settings->verifyRecaptcha('bad-token', '203.0.113.10'));
    }
}
