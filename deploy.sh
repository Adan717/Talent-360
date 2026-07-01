#!/bin/bash
echo "--- INICIANDO DESPLIEGUE AUTOMATIZADO TALENT360 ---"

# 1. Traer últimos cambios de Git
echo "Descargando últimos cambios de Git..."
git pull origin main

# 2. Instalar dependencias de Composer dentro de Docker
echo "Instalando dependencias de Composer..."
docker exec talent360-backend composer install --no-dev --optimize-autoloader --no-interaction

# 3. Aplicar permisos robustos en el host
echo "Aplicando permisos a directorios..."
chmod -R 755 Backend
chmod -R 777 Backend/storage Backend/bootstrap/cache

# 4. Recrear contenedores para refrescar inodos de volumen
echo "Refrescando inodos de volumen de Docker..."
docker compose up -d --force-recreate

# 5. Volver a asegurar permisos tras recreación
chmod -R 777 Backend/storage Backend/bootstrap/cache

# 6. Ejecutar migraciones en base de datos
echo "Ejecutando migraciones de base de datos..."
docker exec talent360-backend php artisan migrate --force

# 7. Ejecutar seeder de DecorArte
echo "Sembrando estructura de DecorArte..."
docker exec talent360-backend php scripts_utilidad/seed_decorarte_final.php

# 8. Limpiar caché de Laravel
echo "Limpiando cachés de Laravel..."
docker exec talent360-backend php artisan config:clear
docker exec talent360-backend php artisan cache:clear

echo "--- DESPLIEGUE COMPLETADO Y CONFIGURADO CON ÉXITO ---"
