<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            Route::middleware('lms-api')
                ->group(__DIR__.'/../routes/lms-api.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            'api/frontend/*',
            'api/signup',
            'contact/send',
        ]);

        $middleware->group('lms-api', [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\ResolveTenant::class,
            \App\Http\Middleware\ResolveTenantFromSession::class,
        ]);

        // Fail-closed gate applied to the tenant-scoped route subset only.
        $middleware->alias([
            'tenant.required' => \App\Http\Middleware\RequireTenant::class,
            'tenant.primary' => \App\Http\Middleware\BindPrimaryTenant::class,
            'plan.chat' => \App\Http\Middleware\EnsureChatEnabled::class,
            'subscription.gate' => \App\Http\Middleware\EnsureSubscriptionActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
