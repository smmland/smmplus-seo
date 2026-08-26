<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AnalyticsPurchaseController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (! $request->isJson()) {
            return response()->json(['ok' => false, 'error' => 'Content-Type must be application/json.'], 415);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['ok' => false, 'error' => 'Invalid JSON payload.'], 422);
        }

        $validator = Validator::make($payload, [
            'site_id' => ['nullable', 'string', 'in:smm-plus'],
            'event_id' => ['required', 'uuid'],
            'order_id' => ['required', 'string', 'max:128'],
            'status' => ['required', 'string', 'in:paid,partially_refunded,refunded,cancelled'],
            'gross_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.999999'],
            'refunded_amount' => ['nullable', 'numeric', 'min:0', 'lte:gross_amount'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'visitor_id' => ['nullable', 'uuid'],
            'session_id' => ['nullable', 'uuid'],
            'paid_at' => ['required', 'date'],
            'updated_at' => ['required', 'date'],
        ]);

        // Validator's root whitelist is expressed separately so unknown financial fields cannot
        // silently enter the contract and later be mistaken for trusted data.
        $allowed = ['site_id', 'event_id', 'order_id', 'status', 'gross_amount', 'refunded_amount', 'currency', 'visitor_id', 'session_id', 'paid_at', 'updated_at'];
        $validator->after(function ($validator) use ($payload, $allowed): void {
            if (array_diff(array_keys($payload), $allowed) !== []) {
                $validator->errors()->add('payload', 'Unknown webhook fields are not allowed.');
            }

            if (! isset($payload['status'], $payload['gross_amount'])) {
                return;
            }

            $gross = (float) $payload['gross_amount'];
            $refunded = (float) ($payload['refunded_amount'] ?? 0);
            if ($payload['status'] === 'partially_refunded' && ($refunded <= 0 || $refunded >= $gross)) {
                $validator->errors()->add('refunded_amount', 'A partial refund must be greater than zero and less than gross amount.');
            }
            if ($payload['status'] === 'refunded' && abs($refunded - $gross) > 0.000001) {
                $validator->errors()->add('refunded_amount', 'A full refund must equal gross amount.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => 'Invalid purchase payload.', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $siteId = $data['site_id'] ?? 'smm-plus';
        $sourceUpdatedAt = Carbon::parse($data['updated_at']);
        $paidAt = Carbon::parse($data['paid_at']);

        if ($sourceUpdatedAt->isBefore($paidAt)) {
            return response()->json(['ok' => false, 'error' => 'updated_at cannot be before paid_at.'], 422);
        }

        if ($sourceUpdatedAt->isAfter(now()->addMinutes(5)) || $paidAt->isAfter(now()->addMinutes(5))) {
            return response()->json(['ok' => false, 'error' => 'Purchase timestamps cannot be in the future.'], 422);
        }

        $result = DB::transaction(function () use ($data, $siteId, $sourceUpdatedAt, $paidAt) {
            $eventInserted = DB::table('analytics_purchase_events')->insertOrIgnore([
                'event_id' => $data['event_id'],
                'created_at' => now(),
            ]);
            if ($eventInserted === 0) {
                return ['purchase' => null, 'duplicate' => true, 'stale' => false];
            }

            $purchase = AnalyticsPurchase::query()
                ->where('site_id', $siteId)
                ->where('external_order_id', $data['order_id'])
                ->lockForUpdate()
                ->first();

            // A delayed retry of an older status must never overwrite a newer refund/update.
            if ($purchase && $purchase->source_updated_at->gt($sourceUpdatedAt)) {
                return ['purchase' => null, 'duplicate' => false, 'stale' => true];
            }

            $attribution = $this->resolveAttribution($siteId, $data['session_id'] ?? null);
            $values = [
                'last_event_id' => $data['event_id'],
                'status' => $data['status'],
                'gross_amount' => $data['gross_amount'],
                'refunded_amount' => $data['refunded_amount'] ?? 0,
                'currency' => strtoupper($data['currency']),
                'paid_at' => $paidAt,
                'source_updated_at' => $sourceUpdatedAt,
            ];

            if (($data['visitor_id'] ?? $attribution['visitor_id']) !== null) {
                $values['visitor_id'] = $data['visitor_id'] ?? $attribution['visitor_id'];
            }
            if (($data['session_id'] ?? null) !== null) {
                $values['session_id'] = $data['session_id'];
            }

            // Preserve the first known acquisition snapshot when a later refund webhook does not
            // have a browser session attached.
            foreach ($attribution as $key => $value) {
                if ($key !== 'visitor_id' && $value !== null) {
                    $values[$key] = $value;
                }
            }

            if ($purchase) {
                $purchase->update($values);

                return ['purchase' => $purchase, 'duplicate' => false, 'stale' => false];
            }

            $purchase = AnalyticsPurchase::query()->create($values + [
                'site_id' => $siteId,
                'external_order_id' => $data['order_id'],
                'user_state' => 'unknown',
            ]);

            return ['purchase' => $purchase, 'duplicate' => false, 'stale' => false];
        });

        return response()->json([
            'ok' => true,
            'accepted' => $result['purchase'] ? 1 : 0,
            'duplicate' => $result['duplicate'],
            'stale' => $result['stale'],
        ]);
    }

    private function resolveAttribution(string $siteId, ?string $sessionId): array
    {
        $event = $sessionId ? AnalyticsEvent::query()
            ->where('site_id', $siteId)
            ->where('session_id', $sessionId)
            ->where('event_name', 'page_view')
            ->orderByDesc('is_landing')
            ->orderBy('occurred_at')
            ->first() : null;

        return [
            'visitor_id' => $event?->visitor_id,
            'landing_page' => $event?->page_path,
            'language' => $event?->language,
            'source' => $event?->source,
            'medium' => $event?->medium,
            'campaign' => $event?->campaign,
            'device_type' => $event?->device_type,
            'user_state' => $event?->user_state,
            'country_code' => $event?->country_code,
        ];
    }
}
