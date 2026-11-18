<?php

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
        // API guests must receive JSON 401 responses instead of a redirect to a missing web login route.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
        // CORS harus di posisi paling awal (sebelum middleware lain)
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        // Security headers untuk semua response
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Daftarkan alias middleware Spatie Permission
        $middleware->alias([
            'role'       => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'effective_access' => \App\Http\Middleware\EnsureEffectiveAccess::class,
            'class.lecturer' => \App\Http\Middleware\EnsureClassLecturer::class,
            'graduated.restrict' => \App\Http\Middleware\RestrictGraduatedStudent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
