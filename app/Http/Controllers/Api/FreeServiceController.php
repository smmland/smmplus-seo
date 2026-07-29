<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GatewayService;
use App\Services\GatewayRateLimiter;
use App\Services\GatewaySettingsService;
use App\Support\GatewayTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FreeServiceController extends Controller
{
    public function __construct(
        private readonly GatewayRateLimiter $limiter,
        private readonly GatewaySettingsService $settings,
    ) {}

    public function order(Request $request)
    {
        $serviceName = trim((string) $request->input('service', ''));
        $link = trim((string) $request->input('link', ''));
        $requestedQty = $request->input('quantity');

        if ($serviceName === '') {
            return response()->json(['ok' => false, 'error' => 'Service name is required'], 400);
        }

        $service = GatewayService::query()
            ->where('slug', $serviceName)
            ->where('is_active', true)
            ->whereHas('upstream', fn ($q) => $q->where('is_active', true))
            ->with('upstream')
            ->first();

        if (! $service) {
            return response()->json([
                'ok' => false,
                'error' => 'Invalid service name',
                'available_services' => GatewayService::query()
                    ->where('is_active', true)
                    ->whereHas('upstream', fn ($q) => $q->where('is_active', true))
                    ->orderBy('slug')
                    ->pluck('slug'),
            ], 400);
        }

        if ($link === '') {
            return response()->json(['ok' => false, 'error' => 'Link is required'], 400);
        }

        $qty = is_numeric($requestedQty) ? (int) $requestedQty : $service->min_quantity;
        $qty = max($service->min_quantity, min($service->max_quantity, $qty));

        $ip = $this->resolveClientIp($request);
        $target = GatewayTarget::normalize($link);

        $globalIpKey = 'free_service:global:daily:ip:'.md5($ip);
        $globalTargetKey = 'free_service:global:daily:target:'.md5($target);

        if ($this->limiter->get($globalIpKey) >= $this->settings->getGlobalDailyIpLimit()) {
            return response()->json([
                'ok' => false,
                'error' => 'The usage limit for free services has been reached. Please try again later.',
                'retry_after_seconds' => $this->limiter->secondsRemaining($globalIpKey),
            ]);
        }

        if ($this->limiter->get($globalTargetKey) >= $this->settings->getGlobalDailyTargetLimit()) {
            return response()->json([
                'ok' => false,
                'error' => 'You have reached the usage limit for this free service. Please try again later.',
                'retry_after_seconds' => $this->limiter->secondsRemaining($globalTargetKey),
            ]);
        }

        $ipKey = "free_service:{$service->slug}:ip:".md5($ip);
        $targetKey = "free_service:{$service->slug}:target:".md5($target);

        $ipRemaining = $service->ip_limit - $this->limiter->get($ipKey);
        $targetRemaining = $service->target_limit - $this->limiter->get($targetKey);

        if ($ipRemaining <= 0) {
            return response()->json([
                'ok' => false,
                'error' => 'You have reached the allowed limit for this free service. Please try again later.',
                'service_name' => $service->slug,
                'limit_seconds' => $service->limit_seconds,
                'retry_after_seconds' => $this->limiter->secondsRemaining($ipKey),
            ]);
        }

        if ($targetRemaining <= 0) {
            return response()->json([
                'ok' => false,
                'error' => 'You have reached the allowed limit for this free service. Please try again later.',
                'service_name' => $service->slug,
                'limit_seconds' => $service->limit_seconds,
                'retry_after_seconds' => $this->limiter->secondsRemaining($targetKey),
            ]);
        }

        $allowedQty = min($qty, $ipRemaining, $targetRemaining);

        if ($allowedQty < $service->min_quantity) {
            return response()->json([
                'ok' => false,
                'error' => 'Remaining limit is lower than minimum quantity.',
                'requested_quantity' => $qty,
                'allowed_quantity' => $allowedQty,
                'min' => $service->min_quantity,
                'max' => $service->max_quantity,
            ]);
        }

        $qty = $allowedQty;

        try {
            $response = Http::asForm()->timeout(30)->post($service->upstream->base_url, [
                'key' => $service->upstream->api_key,
                'action' => 'add',
                'service' => $service->upstream_service_id,
                'link' => $link,
                'quantity' => $qty,
            ]);
        } catch (ConnectionException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        $data = $response->json();

        if ($response->status() >= 400 || ! is_array($data)) {
            return response()->json([
                'ok' => false,
                'error' => 'Invalid API response',
                'raw' => $response->body(),
            ], 500);
        }

        if (isset($data['error'])) {
            return response()->json(['ok' => false, 'error' => $data['error']]);
        }

        $this->limiter->incrementWithTtl($ipKey, $qty, $service->limit_seconds);
        $this->limiter->incrementWithTtl($targetKey, $qty, $service->limit_seconds);
        $this->limiter->incrementWithTtl($globalIpKey, 1, $this->settings->getGlobalDailySeconds());
        $this->limiter->incrementWithTtl($globalTargetKey, 1, $this->settings->getGlobalDailySeconds());

        return response()->json([
            'ok' => true,
            'service_name' => $service->slug,
            'service_id' => $service->upstream_service_id,
            'quantity' => $qty,
            'min' => $service->min_quantity,
            'max' => $service->max_quantity,
            'ip_limit' => $service->ip_limit,
            'target_limit' => $service->target_limit,
            'limit_seconds' => $service->limit_seconds,
            'data' => $data,
        ]);
    }

    private function resolveClientIp(Request $request): string
    {
        if ($request->headers->get('CF-Connecting-IP')) {
            return $request->headers->get('CF-Connecting-IP');
        }

        if ($request->headers->get('X-Forwarded-For')) {
            return trim(explode(',', $request->headers->get('X-Forwarded-For'))[0]);
        }

        return $request->ip() ?? 'unknown';
    }
}
