<?php

namespace App\Services;

use App\Models\CatalogService;
use Illuminate\Support\Facades\Http;

/**
 * Syncs the cached retail catalog (catalog_services) from smm.plus's own customer-facing API -
 * POST {upstream base_url} with action=services - the real, currently-billed price/min/max shown
 * to real customers, per the documented contract at https://smm.plus/api. The base URL and key
 * come from an existing Free Service Gateway > API Server (GatewayUpstream, e.g. an "SMM Plus
 * Main" row with base_url https://smm.plus/api/v2) chosen on the SEO Settings page, rather than a
 * second copy of the same credentials typed here. Distinct from ServiceCatalogService, which
 * scrapes the HTML /services page for name/description only and has no pricing at all.
 */
class CatalogSyncService
{
    private const FIELDS = ['name', 'type', 'category', 'rate', 'min', 'max', 'refill', 'cancel'];

    public function __construct(private readonly CatalogSettingsService $settings) {}

    /**
     * @return array{ok: bool, error?: string, total?: int, added?: int, changed?: int, unavailable?: int}
     */
    public function sync(): array
    {
        $upstream = $this->settings->getUpstream();

        if (! $upstream) {
            return ['ok' => false, 'error' => 'No API server selected yet - pick one on SEO > Settings (managed on Free Service Gateway > API Servers).'];
        }

        try {
            $response = Http::asForm()->timeout(30)->post($upstream->base_url, [
                'key' => $upstream->api_key,
                'action' => 'services',
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Fetch error: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'HTTP '.$response->status().' from '.$upstream->base_url];
        }

        $data = $response->json();

        if (is_array($data) && isset($data['error'])) {
            return ['ok' => false, 'error' => (string) $data['error']];
        }

        if (! is_array($data) || ! array_is_list($data)) {
            return ['ok' => false, 'error' => 'Unexpected response shape from smm.plus\'s API (expected a JSON array of services).'];
        }

        if (count($data) === 0) {
            // Never seen a genuinely empty catalog from a live panel - far more likely a
            // transient upstream glitch, so this leaves the existing cache untouched rather than
            // marking every service unavailable off one suspicious response.
            return ['ok' => false, 'error' => 'smm.plus returned zero services - leaving the cached catalog untouched.'];
        }

        $touchedIds = [];
        $added = 0;
        $changed = 0;
        $now = now();

        foreach ($data as $entry) {
            if (! is_array($entry) || ! isset($entry['service'])) {
                continue;
            }

            $serviceId = (string) $entry['service'];
            $touchedIds[] = $serviceId;

            $row = CatalogService::query()->firstOrNew(['service_id' => $serviceId]);
            $isNew = ! $row->exists;
            $before = $row->exists ? $row->only(self::FIELDS) : null;

            $row->name = (string) ($entry['name'] ?? '');
            $row->type = isset($entry['type']) ? (string) $entry['type'] : null;
            $row->category = isset($entry['category']) ? (string) $entry['category'] : null;
            $row->rate = isset($entry['rate']) ? (string) $entry['rate'] : null;
            $row->min = isset($entry['min']) ? (int) $entry['min'] : null;
            $row->max = isset($entry['max']) ? (int) $entry['max'] : null;
            $row->refill = (bool) ($entry['refill'] ?? false);
            $row->cancel = (bool) ($entry['cancel'] ?? false);
            $row->available = true;
            $row->synced_at = $now;
            $row->save();

            if ($isNew) {
                $added++;
            } elseif ($before !== $row->only(self::FIELDS)) {
                $changed++;
            }
        }

        $unavailable = CatalogService::query()
            ->where('available', true)
            ->whereNotIn('service_id', $touchedIds)
            ->update(['available' => false]);

        return [
            'ok' => true,
            'total' => count($touchedIds),
            'added' => $added,
            'changed' => $changed,
            'unavailable' => $unavailable,
        ];
    }
}
