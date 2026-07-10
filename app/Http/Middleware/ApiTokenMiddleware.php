<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Minimal bearer-token auth for the admin API. No Sanctum/Passport needed for a
 * single-admin control panel: login issues a random token, stored hashed, checked here.
 */
class ApiTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $user = User::query()->where('api_token', hash('sha256', $token))->first();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
