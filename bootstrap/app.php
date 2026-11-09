<?php

use App\Http\Middleware\EnsurePlatformOperator;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'operator' => EnsurePlatformOperator::class,
        ]);

        // Global, so the health check, error pages, Livewire requests and /csp-report carry the headers too.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The studio's JSON endpoints (design/*, and the public share-link routes) answer JSON to a client that asks for it.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || (($request->is('design/*') || $request->is('s/*')) && $request->expectsJson()),
        );

        // A missing or invisible row is the same plain 404 for everyone: no model name, no hint that it exists elsewhere.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() && ($request->is('design/*') || $request->is('s/*'))) {
                return response()->json(['message' => 'Not found.'], 404);
            }
        });
    })->create();
