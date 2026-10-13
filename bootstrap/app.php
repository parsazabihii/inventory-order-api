<?php

use App\Http\Middleware\ActiveUser;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['role' => RoleMiddleware::class, 'active' => ActiveUser::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (QueryException $error, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            if ((string) $error->getCode() === '23505') {
                return response()->json(['message' => 'A record with this unique value already exists.'], 409);
            }
            if (in_array((string) $error->getCode(), ['23503', '23001'], true)) {
                return response()->json(['message' => 'A referenced record changed or is still in use.'], 409);
            }
            if (in_array((string) $error->getCode(), ['40001', '40P01'], true)) {
                return response()->json(['message' => 'Concurrent update conflict. Retry with the same idempotency key.'], 503);
            }

            return response()->json(['message' => 'Database operation unavailable. Please try again later.'], 503);
        });
    })->create();
