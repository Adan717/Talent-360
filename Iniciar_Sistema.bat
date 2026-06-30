@echo off
title Iniciar Talent 360 (Frontend + Backend)
color 0B
echo ===================================================
echo     TALENT 360 - INICIADOR LOCAL
echo ===================================================
echo.

:: Iniciar el Backend (Laravel) en una nueva ventana
echo [1/2] Iniciando Servidor Backend (Laravel)...
start "Talent 360 Backend" cmd /k "cd /d %~dp0Backend && php artisan serve --host=0.0.0.0 --port=8000"

:: Esperar 2 segundos para dar tiempo a que inicie el backend
timeout /t 2 /nobreak >nul

:: Iniciar el Frontend (Vite/React) en una nueva ventana
echo [2/2] Iniciando Motor Visual (Frontend)...
start "Talent 360 Frontend" cmd /k "cd /d %~dp0Frontend && npm run dev -- --open"

echo.
echo ¡Sistema iniciado correctamente!
echo Las ventanas del servidor se ejecutaran de fondo.
echo Cerrando este inicializador...
timeout /t 3 /nobreak >nul
exit
