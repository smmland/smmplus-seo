<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

// smm.plus's own customer-facing API credentials (https://smm.plus/api) - a single first-party
// key, unlike GatewayUpstream's list of multiple wholesale suppliers, so it lives here as one
// settings row rather than a table. Same encrypted-at-rest, never-pre-filled pattern as
// AiSettingsService's provider API keys.
class CatalogSettingsService
{
    private const KEY_API_KEY = 'catalog_api_key';
    private const KEY_HOST = 'catalog_api_host';
    private const KEY_CURRENCY_SYMBOL = 'catalog_currency_symbol';

    private const DEFAULT_CURRENCY_SYMBOL = '$';

    public function hasApiKey(): bool
    {
        return $this->get(self::KEY_API_KEY) !== null;
    }

    public function getApiKey(): ?string
    {
        $encrypted = $this->get(self::KEY_API_KEY);

        if ($encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            // Undecryptable (e.g. APP_KEY rotated since it was saved) - treat as "no key set"
            // rather than fatally erroring the sync or the settings page.
            return null;
        }
    }

    // Blank/null leaves the currently-stored key untouched - the form field is never pre-filled
    // with the real secret, so "didn't type anything" must mean "keep it", not "clear it".
    public function setApiKey(?string $key): void
    {
        if ($key === null || $key === '') {
            return;
        }

        $this->set(self::KEY_API_KEY, Crypt::encryptString($key));
    }

    public function clearApiKey(): void
    {
        Setting::query()->where('key', self::KEY_API_KEY)->delete();
    }

    // Defaults to the same host the HTML scraper (ServiceCatalogService/SettingsService) already
    // points at - smm.plus's own customer API lives on that same domain - but is independently
    // overridable in case the two ever need to diverge.
    public function getHost(SettingsService $sitemapSettings): string
    {
        $stored = $this->get(self::KEY_HOST);

        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        return (string) (parse_url($sitemapSettings->getSourceSitemapUrl(), PHP_URL_HOST) ?: '');
    }

    public function setHost(?string $host): void
    {
        $this->set(self::KEY_HOST, trim((string) $host));
    }

    // Purely a display-formatting choice for the public API's rate_formatted convenience field -
    // smm.plus's action=services response carries no currency at all, so this is never presented
    // as sourced data, just how the admin wants the raw rate string rendered.
    public function getCurrencySymbol(): string
    {
        return $this->get(self::KEY_CURRENCY_SYMBOL) ?? self::DEFAULT_CURRENCY_SYMBOL;
    }

    public function setCurrencySymbol(string $symbol): void
    {
        $this->set(self::KEY_CURRENCY_SYMBOL, $symbol);
    }

    private function get(string $key): ?string
    {
        return Setting::query()->find($key)?->value;
    }

    private function set(string $key, string $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
