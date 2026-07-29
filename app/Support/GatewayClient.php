<?php

namespace App\Support;

use Illuminate\Http\Request;

class GatewayClient
{
    public static function resolveIp(Request $request): string
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
