<?php

declare(strict_types=1);

use BugBoard\Laravel\Facades\BugBoard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/**
 * Report every unhandled exception (Laravel 11/12).
 *
 * For Laravel 10, put the same `$exceptions->report(...)` body inside
 * `register()` in app/Exceptions/Handler.php via `$this->reportable(...)`.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->report(function (\Throwable $e) {
            // Filter first — a public app throws these constantly. Reporting them
            // burns your quota and buries real bugs.
            $ignore = [
                \Illuminate\Auth\AuthenticationException::class,
                \Illuminate\Validation\ValidationException::class,
                \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
                \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
            ];

            foreach ($ignore as $class) {
                if ($e instanceof $class) {
                    return;
                }
            }

            // Severity from the class — infrastructure failures outrank the rest.
            $method = match (true) {
                $e instanceof \PDOException,
                $e instanceof \Illuminate\Database\QueryException => 'criticalHigh',
                $e instanceof \Illuminate\Http\Client\ConnectionException => 'major',
                default => 'moderate',
            };

            // Dedup note: a QueryException's getMessage() contains SQL + bound
            // values, which vary per request and create a new card each time. For
            // noisy types, prefer a stable synthetic title:
            //   BugBoard::criticalHigh('Database query failed: ' . $e::class, $e, ['database']);
            BugBoard::{$method}($e->getMessage() ?: $e::class, $e, ['unhandled']);
        });
    })
    ->create();
