<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Settings for automatically ordering views on every Telegram post once it's actually sent
 * (TelegramPostViewsService), via one of the same upstream SMM providers the Free Service Gateway
 * already talks to. Deliberately its own settings namespace, entirely separate from GatewayService
 * (the public-facing free-service catalog) - the admin picks the upstream and types the service id
 * directly here rather than adding a GatewayService row, specifically so this never becomes
 * orderable through the public /api/free-service/order endpoint the way a catalog entry would be.
 */
class TelegramAutoViewsSettingsService
{
    private const KEY_ENABLED = 'telegram_auto_views_enabled';
    private const KEY_UPSTREAM_ID = 'telegram_auto_views_upstream_id';
    private const KEY_SERVICE_ID = 'telegram_auto_views_service_id';
    private const KEY_QUANTITY = 'telegram_auto_views_quantity';

    private const DEFAULT_ENABLED = false;
    private const DEFAULT_QUANTITY = 1000;

    public function isEnabled(): bool
    {
        $stored = $this->get(self::KEY_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_ENABLED;
    }

    public function getUpstreamId(): ?int
    {
        $stored = $this->get(self::KEY_UPSTREAM_ID);

        return $stored ? (int) $stored : null;
    }

    public function getServiceId(): ?string
    {
        return $this->get(self::KEY_SERVICE_ID) ?: null;
    }

    public function getQuantity(): int
    {
        $stored = $this->get(self::KEY_QUANTITY);

        return $stored !== null ? max(1, (int) $stored) : self::DEFAULT_QUANTITY;
    }

    public function setSettings(bool $enabled, ?int $upstreamId, ?string $serviceId, int $quantity): void
    {
        $this->set(self::KEY_ENABLED, $enabled ? '1' : '0');
        $this->set(self::KEY_UPSTREAM_ID, $upstreamId !== null ? (string) $upstreamId : '');
        $this->set(self::KEY_SERVICE_ID, trim((string) $serviceId));
        $this->set(self::KEY_QUANTITY, (string) max(1, $quantity));
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
