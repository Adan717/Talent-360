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
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            // §65: capacidades delegables por puesto, en paralelo a `role:`.
            'permission' => \App\Http\Middleware\PermissionMiddleware::class,
            'tenant.active' => \App\Http\Middleware\CheckTenantActive::class,
            'tenant.module' => \App\Http\Middleware\TenantModuleMiddleware::class,
            'device.security' => \App\Http\Middleware\DeviceSecurityMiddleware::class,
        ]);

        // §43: el token de auth puede llegar en la cookie httpOnly `talent_auth_token`
        // (protección XSS); este middleware la copia al header Authorization antes de que
        // Sanctum evalúe el token. Se antepone a todo el grupo `api`.
        $middleware->prependToGroup('api', \App\Http\Middleware\AuthTokenFromCookie::class);

        // La cookie del token NO se cifra: su valor ya es un token opaco aleatorio de
        // Sanctum, y dejarla sin cifrar evita conflictos entre el grupo `api` (que no
        // corre EncryptCookies) y el `web` (que sí). La protección real es el flag httpOnly.
        $middleware->encryptCookies(except: [\App\Http\Middleware\AuthTokenFromCookie::COOKIE_NAME]);
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        $schedule->command('chat:clean-old-messages')->daily();
        // Sección 2 #2: cada noche a las 00:30, marcar tareas inconclusas de días
        // anteriores como pendientes de validación gerencial.
        $schedule->command('tasks:flag-unfinished')->dailyAt('00:30');
        // Sección 2 #1: pre-nómina semanal. Corre cada noche a las 23:00 recalculando
        // la semana en curso de cada tenant (según su día de inicio configurado); al
        // cerrar la semana queda el draft final listo para revisar.
        $schedule->command('payroll:calculate-weekly')->dailyAt('23:00');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
