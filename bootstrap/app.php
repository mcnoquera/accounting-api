<?php

use App\Exceptions\DomainRuleException;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a hosting proxy (Render, Fly, etc.) so request()->ip() in the audit log is the real client.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();
        $exceptions->shouldRenderJsonWhen($isApi);

        // Consistent error envelope: { success: false, message, errors }
        $exceptions->render(fn (ValidationException $e, Request $r) => $isApi($r)
            ? ApiResponse::error($e->getMessage(), 422, $e->errors()) : null);
        $exceptions->render(fn (DomainRuleException $e, Request $r) => $isApi($r)
            ? ApiResponse::error($e->getMessage(), 422, $e->errors) : null);
        $exceptions->render(fn (AuthenticationException $e, Request $r) => $isApi($r)
            ? ApiResponse::error('Unauthenticated.', 401) : null);
        $exceptions->render(fn (AccessDeniedHttpException|AuthorizationException $e, Request $r) => $isApi($r)
            ? ApiResponse::error('You do not have permission to do that.', 403) : null);
        $exceptions->render(fn (NotFoundHttpException|ModelNotFoundException $e, Request $r) => $isApi($r)
            ? ApiResponse::error('Resource not found.', 404) : null);
    })->create();
