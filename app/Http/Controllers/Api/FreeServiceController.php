<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GatewayRequestLog;
use App\Models\GatewayService;
use App\Services\GatewayRateLimiter;
use App\Services\GatewaySettingsService;
use App\Support\GatewayClient;
use App\Support\GatewayTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
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
        $ip = GatewayClient::resolveIp($request);
        $origin = $request->headers->get('Origin');

        $serviceName = trim((string) $request->input('service', ''));
        $link = trim((string) $request->input('link', ''));
        $requestedQty = $request->input('quantity');

        if ($serviceName === '') {
            return $this->respond(
                ['ok' => false, 'error' => 'Service name is required'], 400,
                $ip, $origin, GatewayRequestLog::STATUS_MISSING_SERVICE,
            );
        }

        $service = GatewayService::query()
            ->where('slug', $serviceName)
            ->where('is_active', true)
            ->whereHas('upstream', fn ($q) => $q->where('is_active', true))
            ->with('upstream')
            ->first();

        if (! $service) {
            return $this->respond([
                'ok' => false,
                'error' => 'Invalid service name',
                'available_services' => GatewayService::query()
                    ->where('is_active', true)
                    ->whereHas('upstream', fn ($q) => $q->where('is_active', true))
                    ->orderBy('slug')
                    ->pluck('slug'),
            ], 400, $ip, $origin, GatewayRequestLog::STATUS_INVALID_SERVICE, serviceSlug: $serviceName);
        }

        if ($link === '') {
            return $this->respond(
                ['ok' => false, 'error' => 'Link is required'], 400,
                $ip, $origin, GatewayRequestLog::STATUS_MISSING_LINK, serviceSlug: $service->slug,
            );
        }

        $originalQty = is_numeric($requestedQty) ? (int) $requestedQty : $service->min_quantity;
        $qty = max($service->min_quantity, min($service->max_quantity, $originalQty));

        $target = GatewayTarget::normalize($link);

        $globalIpKey = 'free_service:global:daily:ip:'.md5($ip);
        $globalTargetKey = 'free_service:global:daily:target:'.md5($target);

        if ($this->limiter->get($globalIpKey) >= $this->settings->getGlobalDailyIpLimit()) {
            return $this->respond([
                'ok' => false,
                'error' => 'The usage limit for free services has been reached. Please try again later.',
                'retry_after_seconds' => $this->limiter->secondsRemaining($globalIpKey),
            ], 200, $ip, $origin, GatewayRequestLog::STATUS_GLOBAL_IP_LIMIT, serviceSlug: $service->slug, target: $target, link: $link, qtyRequested: $qty);
        }

        if ($this->limiter->get($globalTargetKey) >= $this->settings->getGlobalDailyTargetLimit()) {
            return $this->respond([
                'ok' => false,
                'error' => 'You have reached the usage limit for this free service. Please try again later.',
                'retry_after_seconds' => $this->limiter->secondsRemaining($globalTargetKey),
            ], 200, $ip, $origin, GatewayRequestLog::STATUS_GLOBAL_TARGET_LIMIT, serviceSlug: $service->slug, target: $target, link: $link, qtyRequested: $qty);
        }

        $ipKey = "free_service:{$service->slug}:ip:".md5($ip);
        $targetKey = "free_service:{$service->slug}:target:".md5($target);

        $ipRemaining = $service->ip_limit - $this->limiter->get($ipKey);
        $targetRemaining = $service->target_limit - $this->limiter->get($targetKey);

        if ($ipRemaining <= 0) {
            return $this->respond([
                'ok' => false,
                'error' => 'You have reached the allowed limit for this free service. Please try again later.',
                'service_name' => $service->slug,
                'limit_seconds' => $service->limit_seconds,
                'retry_after_seconds' => $this->limiter->secondsRemaining($ipKey),
            ], 200, $ip, $origin, GatewayRequestLog::STATUS_IP_LIMIT, serviceSlug: $service->slug, target: $target, link: $link, qtyRequested: $qty);
        }

        if ($targetRemaining <= 0) {
            return $this->respond([
                'ok' => false,
                'error' => 'You have reached the allowed limit for this free service. Please try again later.',
                'service_name' => $service->slug,
                'limit_seconds' => $service->limit_seconds,
                'retry_after_seconds' => $this->limiter->secondsRemaining($targetKey),
            ], 200, $ip, $origin, GatewayRequestLog::STATUS_TARGET_LIMIT, serviceSlug: $service->slug, target: $target, link: $link, qtyRequested: $qty);
        }

        $allowedQty = min($qty, $ipRemaining, $targetRemaining);

        if ($allowedQty < $service->min_quantity) {
            return $this->respond([
                'ok' => false,
                'error' => 'Remaining limit is lower than minimum quantity.',
                'requested_quantity' => $qty,
                'allowed_quantity' => $allowedQty,
                'min' => $service->min_quantity,
                'max' => $service->max_quantity,
            ], 200, $ip, $origin, GatewayRequestLog::STATUS_BELOW_MIN_QUANTITY, serviceSlug: $service->slug, target: $target, link: $link, qtyRequested: $qty, qtyAllowed: $allowedQty);
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
            return $this->respond(
                ['ok' => false, 'error' => $e->getMessage()], 500,
                $ip, $origin, GatewayRequestLog::STATUS_UPSTREAM_ERROR,
                serviceSlug: $service->slug, target: $target, link: $link, qtyRequested: $qty, upstreamId: $service->gateway_upstream_id,
            );
        }

        $data = $response->json();

        if ($response->status() >= 400 || ! is_array($data)) {
            return $this->respond([
                'ok' => false,
                'error' => 'Invalid API response',
                'raw' => $response->body(),
            ], 500, $ip, $origin, GatewayRequestLog::STATUS_UPSTREAM_ERROR, serviceSlug: $service->slug, target: $target, link: $link, qtyRequested: $qty, upstreamId: $service->gateway_upstream_id);
        }

        if (isset($data['error'])) {
            return $this->respond(
                ['ok' => false, 'error' => $data['error']], 200,
                $ip, $origin, GatewayRequestLog::STATUS_UPSTREAM_ERROR,
                serviceSlug: $service->slug, target: $target, link: $link, qtyRequested: $qty, upstreamId: $service->gateway_upstream_id,
            );
        }

        $this->limiter->incrementWithTtl($ipKey, $qty, $service->limit_seconds);
        $this->limiter->incrementWithTtl($targetKey, $qty, $service->limit_seconds);
        $this->limiter->incrementWithTtl($globalIpKey, 1, $this->settings->getGlobalDailySeconds());
        $this->limiter->incrementWithTtl($globalTargetKey, 1, $this->settings->getGlobalDailySeconds());

        return $this->respond([
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
        ], 200, $ip, $origin, GatewayRequestLog::STATUS_SUCCESS, serviceSlug: $service->slug, target: $target, link: $link, qtyRequested: $qty, qtyAllowed: $qty, upstreamId: $service->gateway_upstream_id);
    }

    private function respond(
        array $payload,
        int $httpStatus,
        string $ip,
        ?string $origin,
        string $logStatus,
        ?string $serviceSlug = null,
        ?string $target = null,
        ?string $link = null,
        ?int $qtyRequested = null,
        ?int $qtyAllowed = null,
        ?int $upstreamId = null,
    ): JsonResponse {
        GatewayRequestLog::create([
            'ip' => $ip,
            'origin' => $origin,
            'service_slug' => $serviceSlug,
            'gateway_upstream_id' => $upstreamId,
            'target' => $target,
            'link' => $link,
            'quantity_requested' => $qtyRequested,
            'quantity_allowed' => $qtyAllowed,
            'status' => $logStatus,
        ]);

        return response()->json($payload, $httpStatus);
    }
}
