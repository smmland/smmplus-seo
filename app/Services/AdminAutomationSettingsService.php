<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

class AdminAutomationSettingsService
{
    private const KEY_PANEL_URL = 'admin_automation_panel_url';
    private const KEY_USERNAME = 'admin_automation_username';
    private const KEY_PASSWORD = 'admin_automation_password';
    private const KEY_SERVICE_URL = 'admin_automation_service_url';
    private const KEY_SERVICE_TOKEN = 'admin_automation_service_token';

    // The panel this feature is currently being built and tested against, per the task - swap to
    // the real smmplus panel URL once this is verified there.
    private const DEFAULT_PANEL_URL = 'https://smmto.com';

    public function getPanelUrl(): string
    {
        return $this->get(self::KEY_PANEL_URL) ?? self::DEFAULT_PANEL_URL;
    }

    public function getUsername(): ?string
    {
        return $this->get(self::KEY_USERNAME);
    }

    public function getPassword(): ?string
    {
        return $this->getEncrypted(self::KEY_PASSWORD);
    }

    public function hasPassword(): bool
    {
        return $this->get(self::KEY_PASSWORD) !== null;
    }

    public function getServiceUrl(): ?string
    {
        return $this->get(self::KEY_SERVICE_URL);
    }

    public function getServiceToken(): ?string
    {
        return $this->getEncrypted(self::KEY_SERVICE_TOKEN);
    }

    public function hasServiceToken(): bool
    {
        return $this->get(self::KEY_SERVICE_TOKEN) !== null;
    }

    /**
     * Password and service token are only overwritten when a non-empty value is supplied, so the
     * settings form can display them blank (never round-tripping the secret to the browser) while
     * "save without touching this field" still works.
     */
    public function setSettings(
        string $panelUrl,
        ?string $username,
        ?string $password,
        ?string $serviceUrl,
        ?string $serviceToken,
    ): void {
        $this->set(self::KEY_PANEL_URL, rtrim($panelUrl, '/'));
        $this->set(self::KEY_USERNAME, $username);
        $this->set(self::KEY_SERVICE_URL, $serviceUrl ? rtrim($serviceUrl, '/') : null);

        if ($password !== null && $password !== '') {
            $this->setEncrypted(self::KEY_PASSWORD, $password);
        }

        if ($serviceToken !== null && $serviceToken !== '') {
            $this->setEncrypted(self::KEY_SERVICE_TOKEN, $serviceToken);
        }
    }

    private function get(string $key): ?string
    {
        return Setting::query()->find($key)?->value;
    }

    private function getEncrypted(string $key): ?string
    {
        $stored = $this->get($key);

        return $stored !== null ? Crypt::decryptString($stored) : null;
    }

    private function set(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            Setting::query()->where('key', $key)->delete();

            return;
        }

        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    private function setEncrypted(string $key, string $value): void
    {
        $this->set($key, Crypt::encryptString($value));
    }
}
