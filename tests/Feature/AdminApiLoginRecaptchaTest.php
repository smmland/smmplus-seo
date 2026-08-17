<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LoginSecuritySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminApiLoginRecaptchaTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_login_requires_valid_recaptcha_after_failed_attempt_threshold(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ]);

        app(LoginSecuritySettingsService::class)
            ->setRecaptchaSettings(true, 'site-key', 'secret-key', 1, 30);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'incorrect-password',
            ])
            ->assertUnprocessable();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ])
            ->assertUnprocessable()
            ->assertJson([
                'recaptcha_required' => true,
                'recaptcha_site_key' => 'site-key',
            ]);

        Http::fake([
            'www.google.com/recaptcha/api/siteverify' => Http::response(['success' => true]),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password',
                'recaptcha_token' => 'valid-token',
            ])
            ->assertOk()
            ->assertJsonStructure(['accessToken']);
    }

    public function test_api_login_works_normally_while_recaptcha_is_disabled(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()->assertJsonStructure(['accessToken']);
    }
}
