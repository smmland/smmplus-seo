<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class LoginSecuritySettingsService
{
    private const KEY_ENABLED = 'login_recaptcha_enabled';

    private const KEY_SITE_KEY = 'login_recaptcha_site_key';

    private const KEY_SECRET_KEY = 'login_recaptcha_secret_key';

    private const KEY_FAILURE_THRESHOLD = 'login_recaptcha_failure_threshold';

    private const KEY_FAILURE_WINDOW_MINUTES = 'login_recaptcha_failure_window_minutes';

    private const DEFAULT_FAILURE_THRESHOLD = 3;

    private const DEFAULT_FAILURE_WINDOW_MINUTES = 30;

    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function isRecaptchaEnabled(): bool
    {
        return (bool) (int) ($this->get(self::KEY_ENABLED) ?? '0');
    }

    public function isRecaptchaReady(): bool
    {
        return $this->isRecaptchaEnabled()
            && filled($this->getRecaptchaSiteKey())
            && filled($this->getRecaptchaSecretKey());
    }

    public function getRecaptchaSiteKey(): ?string
    {
        return $this->get(self::KEY_SITE_KEY) ?: null;
    }

    public function hasRecaptchaSecretKey(): bool
    {
        return filled($this->getRecaptchaSecretKey());
    }

    public function getRecaptchaSecretKey(): ?string
    {
        $encrypted = $this->get(self::KEY_SECRET_KEY);

        if (! $encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    public function getFailureThreshold(): int
    {
        return max(1, (int) ($this->get(self::KEY_FAILURE_THRESHOLD) ?? self::DEFAULT_FAILURE_THRESHOLD));
    }

    public function getFailureWindowMinutes(): int
    {
        return max(1, (int) ($this->get(self::KEY_FAILURE_WINDOW_MINUTES) ?? self::DEFAULT_FAILURE_WINDOW_MINUTES));
    }

    public function setRecaptchaSettings(
        bool $enabled,
        ?string $siteKey,
        ?string $secretKey,
        int $failureThreshold,
        int $failureWindowMinutes,
    ): void {
        $this->set(self::KEY_ENABLED, $enabled ? '1' : '0');
        $this->set(self::KEY_SITE_KEY, trim((string) $siteKey));
        $this->set(self::KEY_FAILURE_THRESHOLD, (string) max(1, $failureThreshold));
        $this->set(self::KEY_FAILURE_WINDOW_MINUTES, (string) max(1, $failureWindowMinutes));

        // Blank means "keep the saved secret", matching the other secret fields in this panel.
        if ($secretKey !== null && trim($secretKey) !== '') {
            $this->set(self::KEY_SECRET_KEY, Crypt::encryptString(trim($secretKey)));
        }
    }

    public function requiresCaptcha(?string $email, string $ip): bool
    {
        if (! $this->isRecaptchaReady()) {
            return false;
        }

        $threshold = $this->getFailureThreshold();

        return RateLimiter::attempts($this->ipKey($ip)) >= $threshold
            || ($this->normalizeEmail($email) !== '' && RateLimiter::attempts($this->emailKey($email)) >= $threshold);
    }

    public function recordFailure(?string $email, string $ip): void
    {
        $decaySeconds = $this->getFailureWindowMinutes() * 60;

        RateLimiter::hit($this->ipKey($ip), $decaySeconds);

        if ($this->normalizeEmail($email) !== '') {
            RateLimiter::hit($this->emailKey($email), $decaySeconds);
        }
    }

    public function clearFailures(?string $email, string $ip): void
    {
        RateLimiter::clear($this->ipKey($ip));

        if ($this->normalizeEmail($email) !== '') {
            RateLimiter::clear($this->emailKey($email));
        }
    }

    public function verifyRecaptcha(?string $token, string $ip): bool
    {
        $secret = $this->getRecaptchaSecretKey();

        if (! $this->isRecaptchaReady() || ! $secret || blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post(self::VERIFY_URL, [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]);

            return $response->successful() && $response->json('success') === true;
        } catch (Throwable) {
            // Fail closed: a CAPTCHA that could not be verified must never bypass the challenge.
            return false;
        }
    }

    private function ipKey(string $ip): string
    {
        return 'panel-login:failed:ip:'.hash('sha256', $ip);
    }

    private function emailKey(?string $email): string
    {
        return 'panel-login:failed:email:'.hash('sha256', $this->normalizeEmail($email));
    }

    private function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    private function get(string $key): ?string
    {
        try {
            return Setting::query()->find($key)?->value;
        } catch (Throwable) {
            // Keep the login page usable before the settings table exists or during a DB update.
            return null;
        }
    }

    private function set(string $key, string $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
