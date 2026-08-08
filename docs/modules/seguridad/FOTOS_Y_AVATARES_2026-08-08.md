# Fotos y avatares: cierre de la ronda de datos personales (2026-08-08)

Continuación de `docs/modules/reloj_checador/EVIDENCIA_COMEDOR_PRIVADA_2026-08-08.md`. Al
mapear el resto de las fotos aparecieron tres cosas que no estaban en el plan, dos de ellas
más graves que la que veníamos siguiendo.

## 1. Borrado arbitrario de archivos del servidor (§67) — lo más grave

**No era una trampa dormida: era explotable hoy.**

`POST /clock/punch` acepta `details` como arreglo libre. `ClockService` tomaba
`details['photo_url']` **tal cual lo mandara el cliente** y lo guardaba en
`time_entries.photo_url`. Y `clock-photos:purge` —**programado solo, cada día a las 03:15**
(`bootstrap/app.php:55`)— hacía:

```php
$path = public_path(ltrim($entry->photo_url, '/'));
if (file_exists($path)) { @unlink($path); }
```

Con `photo_url = "../.env"` eso resuelve a `/var/www/.env`. Cualquier colaborador con sesión
podía marcar un fichaje y **dejar programado el borrado del `.env` del servidor** —o de un
expediente del storage privado— para cuando venciera la retención. Sin `.env` no hay APP_KEY
ni credenciales de base: la aplicación no levanta.

**Dos cerrojos:**
- `TimeEntryController::punch` sólo acepta un `photo_url` con la forma que produciría el
  servidor (`/uploads/clock-photos/{tenant}/{archivo}.{jpg|png|webp}`), sin `..`, sin rutas
  absolutas y sin barras invertidas. Lo demás se descarta.
- `PurgeClockPhotos` comprueba con `realpath` que el archivo esté **dentro** de su carpeta
  antes de borrar, y avisa en rojo si encuentra una referencia que apunte fuera.

`FotoFichajeNoBorraArchivosTest` (5 casos). **Comprobado que la prueba falla sin el arreglo**:
con el guard desactivado, `'../.env'` sí llega a la base. Incluye un caso de control que
verifica que una ruta legítima SÍ se guarda — sin él, las pruebas pasarían aunque el guard
tirara todo.

## 2. El nombre de cada empleado viajaba a un tercero

El avatar de respaldo se pedía como
`https://api.dicebear.com/7.x/avataaars/svg?seed={NOMBRE REAL}` desde **8 lugares** (7 en el
frontend, 1 armado por el backend en `getMonitorData`). Como **no hay ni un avatar subido en
ninguna instancia**, ése es el camino que se ejecuta siempre: cada carga del monitor, del
organigrama o de RRHH le mandaba a dicebear la plantilla completa de la empresa —nombre por
nombre— junto con la IP y el Referer.

Ahora la semilla es el id interno (`t360-{id}`), que fuera de la base no identifica a nadie.
El fallback quedó centralizado en `Frontend/src/lib/avatar.ts`. Verificado en el navegador:
las URLs salen como `seed=t360-2`, sin nombres. El dibujo de cada quien cambia una vez; el
estilo es el mismo.

**Pendiente para el dueño:** dejar de pedirle las caras a un tercero. Un círculo con las
iniciales se dibuja en el propio navegador, no gasta una petición externa por cara y —esto
importa— **funciona con el reloj en modo offline**, donde hoy salen imágenes rotas.

## 3. 500 garantizado para cualquier admin de plataforma

`platform_users` **no tiene columna `avatar`** (comprobado contra el esquema vivo), pero
`updateProfile` y `uploadAvatar` la escribían ahí. Cualquier admin de plataforma que guardara
su perfil recibía `SQLSTATE[42703]`. Es exactamente el caso que el propio código documenta
haber quitado para `phone` unas líneas más arriba, y aquí seguía vivo. Ahora la columna no se
escribe para esas cuentas, y subir foto responde 422 explicándolo en vez de contestar
"subido con éxito" sin guardar nada.

## 4. El nombre del archivo de avatar era enumerable

Era `avatar_{user_id}_{time()}.jpg`: id secuencial y una ventana de tiempo pequeña, o sea que
un desconocido podía barrer las caras de la plantilla probando nombres. Ahora es un uuid.

**Techo asumido a propósito:** el avatar sigue siendo público (URL de capacidad: quien tenga
el enlace, lo ve). Un `<img src>` no puede mandar el Bearer, y la cookie de sesión es `Secure`
— inservible mientras el despliegue siga en HTTP plano. Cuando esté en HTTPS conviene pasarlo
a URL firmada temporal, como la evidencia de comedor. Hoy no hay ni un avatar subido, así que
no hay dato expuesto.

`AvatarSinFugaTest` (4 casos).
