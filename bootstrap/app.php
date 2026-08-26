<?php

use App\Http\Middleware\ApiTokenMiddleware;
use App\Http\Middleware\HandleAnalyticsCors;
use App\Http\Middleware\HandleGatewayCors;
use App\Http\Middleware\VerifyAnalyticsPurchaseSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.token' => ApiTokenMiddleware::class,
            'analytics.cors' => HandleAnalyticsCors::class,
            'analytics.purchase.signature' => VerifyAnalyticsPurchaseSignature::class,
            'gateway.cors' => HandleGatewayCors::class,
        ]);

        // Laravel's default global CORS middleware falls back to a wildcard allowed-origin
        // policy without a published config/cors.php, which would silently override the
        // origin allowlist HandleGatewayCors enforces below. Nothing else in this app needs
        // browser-based cross-origin access, so drop it rather than let it clobber that.
        $middleware->remove(\Illuminate\Http\Middleware\HandleCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
