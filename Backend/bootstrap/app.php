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

        // Una API pura siempre responde JSON: sin este forzado, una petición sin
        // "Accept: application/json" que falla la autenticación termina en 500
        // ("Route [login] not defined") en vez de 401. Ver ForceJsonResponse.
        $middleware->prependToGroup('api', \App\Http\Middleware\ForceJsonResponse::class);

        // Bloque 1 (2026-08-13): una cuenta marcada con must_change_password sólo puede
        // cambiar su contraseña o salir. En el grupo entero, no ruta por ruta.
        $middleware->appendToGroup('api', \App\Http\Middleware\ForcePasswordChange::class);

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
        // (2026-08-22) shifts:close-orphans NUNCA estuvo agendado: el cierre automático de la
        // jornada de quien olvida checar salida —y su alerta de auditoría 'orphan_shift'— era
        // código muerto. La jornada quedaba "activa" para siempre y el día se pagaba completo sin
        // que nadie se enterara. Cada hora (no una vez al día) porque el comando ya se protege
        // solo: no cierra nada antes de la hora de cierre de CADA sucursal, en su propia zona
        // horaria, así que una sola corrida diaria dejaría fuera a las tiendas de horario largo.
        $schedule->command('shifts:close-orphans')->hourly()->withoutOverlapping();
        // Sección 2 #1 + N3: pre-nómina semanal. Corre cada noche a las 23:00 calculando
        // la última semana CERRADA de cada tenant (según su día de inicio configurado) —
        // nunca la corriente, que contaba días futuros como faltas y dejaba netos en $0.
        // El draft se recalcula cada noche (absorbe justificantes/contingencias tardíos)
        // hasta que el trabajador lo firma; lo firmado es inmutable para el batch.
        $schedule->command('payroll:calculate-weekly')->dailyAt('23:00');
        // §67.D / §23: purga de fotos (datos personales sensibles) a los 90 días. El
        // comando de comedor existía pero nunca se había agendado; se programan ambos.
        $schedule->command('meal-evidence:purge')->dailyAt('03:00');
        $schedule->command('clock-photos:purge')->dailyAt('03:15');
        // Bloque 6: si el asistente de reportes falla demasiado, que lo diga la bitácora
        // del Monitor — no esperar a que un cliente se queje en marzo.
        $schedule->command('reportes:alerta-fallos-asistente')->dailyAt('07:00');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
