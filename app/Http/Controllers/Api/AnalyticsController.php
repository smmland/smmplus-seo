<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Support\GatewayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AnalyticsController extends Controller
{
    private const ALLOWED_EVENTS = [
        'page_view',
        'engagement',
        'internal_click',
        'outbound_click',
        'conversion',
        'web_vital',
        'video',
        'js_error',
    ];

    public function store(Request $request): JsonResponse
    {
        // The tracker deliberately uses text/plain so sendBeacon does not require a CORS
        // preflight while the page is unloading. Decode that body just like normal JSON.
        $payload = $request->all();
        if (! isset($payload['events'])) {
            $payload = json_decode($request->getContent(), true) ?: [];
        }

        $validator = Validator::make($payload, [
            'site_id' => ['nullable', 'string', 'in:smm-plus'],
            'events' => ['required', 'array', 'min:1', 'max:25'],
            'events.*.event_id' => ['required', 'uuid'],
            'events.*.visitor_id' => ['required', 'uuid'],
            'events.*.session_id' => ['required', 'uuid'],
            'events.*.event_name' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_EVENTS)],
            'events.*.page_path' => ['required', 'string', 'max:500', 'starts_with:/'],
            'events.*.page_title' => ['nullable', 'string', 'max:255'],
            'events.*.page_type' => ['nullable', 'string', 'max:50'],
            'events.*.is_landing' => ['nullable', 'boolean'],
            'events.*.language' => ['nullable', 'string', 'max:12'],
            'events.*.referrer_host' => ['nullable', 'string', 'max:255'],
            'events.*.source' => ['nullable', 'string', 'max:100'],
            'events.*.medium' => ['nullable', 'string', 'max:100'],
            'events.*.campaign' => ['nullable', 'string', 'max:255'],
            'events.*.device_type' => ['nullable', 'string', 'in:mobile,tablet,desktop'],
            'events.*.viewport_width' => ['nullable', 'integer', 'between:0,65535'],
            'events.*.duration_ms' => ['nullable', 'integer', 'between:0,86400000'],
            'events.*.scroll_depth' => ['nullable', 'integer', 'between:0,100'],
            'events.*.metric_value' => ['nullable', 'numeric', 'between:0,10000000'],
            'events.*.target' => ['nullable', 'string', 'max:500'],
            'events.*.metadata' => ['nullable', 'array'],
            'events.*.occurred_at' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'error' => 'Invalid analytics payload',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $siteId = $validated['site_id'] ?? 'smm-plus';
        $now = now();
        $ip = GatewayClient::resolveIp($request);
        $dailyClientHash = hash_hmac('sha256', $ip.'|'.$now->toDateString(), (string) config('app.key'));
        $countryCode = $this->countryCode($request);

        $rows = collect($validated['events'])->map(function (array $event) use ($siteId, $now, $dailyClientHash, $countryCode) {
            $occurredAt = Carbon::parse($event['occurred_at']);
            if ($occurredAt->isAfter($now->copy()->addMinutes(5)) || $occurredAt->isBefore($now->copy()->subDays(2))) {
                $occurredAt = $now;
            }

            return [
                'event_id' => $event['event_id'],
                'site_id' => $siteId,
                'visitor_id' => $event['visitor_id'],
                'session_id' => $event['session_id'],
                'event_name' => $event['event_name'],
                'page_path' => $event['page_path'],
                'page_title' => $event['page_title'] ?? null,
                'page_type' => $event['page_type'] ?? null,
                'is_landing' => (bool) ($event['is_landing'] ?? false),
                'language' => isset($event['language']) ? Str::lower($event['language']) : null,
                'referrer_host' => $event['referrer_host'] ?? null,
                'source' => $event['source'] ?? null,
                'medium' => $event['medium'] ?? null,
                'campaign' => $event['campaign'] ?? null,
                'device_type' => $event['device_type'] ?? null,
                'viewport_width' => $event['viewport_width'] ?? null,
                'country_code' => $countryCode,
                'duration_ms' => $event['duration_ms'] ?? null,
                'scroll_depth' => $event['scroll_depth'] ?? null,
                'metric_value' => $event['metric_value'] ?? null,
                'target' => $event['target'] ?? null,
                'metadata' => isset($event['metadata']) ? json_encode($event['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'daily_client_hash' => $dailyClientHash,
                'occurred_at' => $occurredAt,
                'created_at' => $now,
            ];
        })->all();

        $inserted = AnalyticsEvent::query()->insertOrIgnore($rows);

        return response()->json(['ok' => true, 'accepted' => $inserted]);
    }

    private function countryCode(Request $request): ?string
    {
        $value = strtoupper((string) $request->headers->get('CF-IPCountry'));

        return preg_match('/^[A-Z]{2}$/', $value) && ! in_array($value, ['XX', 'T1'], true)
            ? $value
            : null;
    }
}
