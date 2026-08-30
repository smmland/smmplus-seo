<?php

namespace App\Services;

use App\Models\GatewayUpstream;
use App\Models\Setting;

// Which existing "API Server" (Free Service Gateway > API Servers, GatewayUpstream - name/base
// URL/encrypted key, already used the same way by Telegram Settings' auto-views upstream picker)
// CatalogSyncService should call for smm.plus's own customer API - reuses that list instead of
// asking the admin to type a second copy of a key/URL they've likely already saved there (e.g.
// an "SMM Plus Main" row with base_url https://smm.plus/api/v2).
class CatalogSettingsService
{
    private const KEY_UPSTREAM_ID = 'catalog_upstream_id';
    private const KEY_CURRENCY_SYMBOL = 'catalog_currency_symbol';

    private const DEFAULT_CURRENCY_SYMBOL = '$';

    public function getUpstreamId(): ?int
    {
        $stored = $this->get(self::KEY_UPSTREAM_ID);

        return $stored !== null ? (int) $stored : null;
    }

    public function setUpstreamId(?int $id): void
    {
        if ($id === null) {
            Setting::query()->where('key', self::KEY_UPSTREAM_ID)->delete();

            return;
        }

        $this->set(self::KEY_UPSTREAM_ID, (string) $id);
    }

    // Null when nothing's selected, or the selected row was deleted/deactivated since - the
    // caller (CatalogSyncService) treats either the same way: "not configured yet".
    public function getUpstream(): ?GatewayUpstream
    {
        $id = $this->getUpstreamId();

        if ($id === null) {
            return null;
        }

        return GatewayUpstream::query()->where('is_active', true)->find($id);
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
