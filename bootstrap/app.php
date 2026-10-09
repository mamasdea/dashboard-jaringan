<?php

use App\Enums\AksiAktivitas;
use App\Support\PencatatAktivitas;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Percobaan mengakses sesuatu di luar hak akses dicatat di log aktivitas.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            if ($response->getStatusCode() === Response::HTTP_FORBIDDEN && $request->user() !== null) {
                rescue(fn () => app(PencatatAktivitas::class)->catat(AksiAktivitas::AksesDitolak, rincian: [
                    'metode' => $request->method(),
                    'jalur' => '/'.ltrim($request->path(), '/'),
                    'dari' => $request->headers->get('referer'),
                ]));
            }

            return $response;
        });
    })->create();
