<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAnalyticsPurchaseSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('analytics.purchase_webhook_secret');
        if (strlen($secret) < 32) {
            return response()->json(['ok' => false, 'error' => 'Purchase analytics webhook is not configured.'], 503);
        }

        $timestamp = $request->header('X-SMM-Timestamp');
        $provided = $request->header('X-SMM-Signature');
        if (! is_string($timestamp) || strlen($timestamp) > 12 || ! ctype_digit($timestamp) || ! is_string($provided)) {
            return response()->json(['ok' => false, 'error' => 'Missing webhook signature.'], 401);
        }

        $tolerance = max(30, (int) config('analytics.purchase_webhook_tolerance_seconds', 300));
        if (abs(now()->timestamp - (int) $timestamp) > $tolerance) {
            return response()->json(['ok' => false, 'error' => 'Expired webhook signature.'], 401);
        }

        $provided = str_starts_with($provided, 'sha256=') ? substr($provided, 7) : $provided;
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        if (! preg_match('/^[a-f0-9]{64}$/i', $provided) || ! hash_equals($expected, strtolower($provided))) {
            return response()->json(['ok' => false, 'error' => 'Invalid webhook signature.'], 401);
        }

        return $next($request);
    }
}
