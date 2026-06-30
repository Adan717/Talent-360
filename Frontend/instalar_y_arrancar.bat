@echo off
echo ==============================================================
echo  INICIANDO ENTORNO DE DECORARTE 360 - MOTOR VISUAL
echo ==============================================================
echo.
echo 1. Instalando dependencias (esto puede tardar unos minutos)...
call npm install
echo.
echo 2. Iniciando el servidor de la Matrix QA...
start http://localhost:5174
call npm run dev
