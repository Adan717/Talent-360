# La evidencia de comedor deja de ser un archivo público (2026-08-08)

## El agujero, demostrado

La foto que el reloj obliga a tomar al entrar y salir de comer (§23) se guardaba en
`public_path('uploads/meal-evidence/{tenant}/')` — es decir, dentro de la carpeta que nginx
sirve como estáticos. **No era teoría:**

```
GET http://<servidor>:8002/uploads/meal-evidence/1/meal_meal_start_1_20260722050347_nlNdye.jpg
→ HTTP 200 · image/jpeg
```

Sin sesión, sin token, sin nada. Tres agravantes:

1. **El nombre era medio adivinable**: `meal_{tipo}_{user_id}_{YmdHis}_{6 al azar}`. Sabiendo
   el id de una persona y el día, el espacio a probar se reduce a los 6 caracteres.
2. **La purga dejaba huérfanos.** `meal-evidence:purge` (retención de 90 días, §23 ARCO)
   borraba la FILA y el archivo sólo si `path` coincidía. En el servidor de pruebas había un
   biométrico de julio **sin registro en la base**: nada lo nombraba, nada lo iba a limpiar
   nunca, y seguía siendo público.
3. **Nadie mostraba esa foto.** No hay ni un consumidor de la URL en el frontend: la
   evidencia se capturaba, se publicaba en internet y no la miraba nadie.

## El arreglo

Mismo patrón que el Archivo Digital:

- **Disco privado**: `storage/app/private/meal-evidence/{tenant}/{uuid}.{ext}`. El nombre es
  un uuid: no dice de quién es la cara ni de cuándo.
- **Una sola puerta**: `GET /api/v1/clock/meal-evidence/{uuid}`, tras `auth:sanctum` + rol +
  `tenant.active`, y el controlador decide: la ve **el dueño de la cara** o **un mando de su
  misma empresa** (para eso existe la evidencia). Un compañero recibe 403; alguien de otra
  empresa, 404.
- **La purga** ahora borra del disco privado (y conserva el camino viejo para las filas que
  todavía no se hayan migrado, para no volver a dejar huérfanos).
- **`meal-evidence:privatizar`**: saca de la carpeta pública lo ya escrito. **Mueve, no
  borra** — son datos personales y destruirlos es decisión del dueño de la empresa, no de una
  migración. Los huérfanos (archivo sin fila) van a `meal-evidence/huerfanos/`. Tiene
  `--dry-run`.

Red: `EvidenciaComedorPrivadaTest` (9 casos, incluidos el 403 del compañero, el 404 de otra
empresa, el 401 sin sesión, que el uuid inventado no filtre nada y que la purga no deje
archivos atrás). `OrgCycleRatingsMealPhotoTest` se actualizó: comprobaba el archivo en
`public/`, que era justo el agujero.

## Cómo se aplica en un servidor ya desplegado

```bash
docker exec <backend> php artisan meal-evidence:privatizar --dry-run   # informa
docker exec <backend> php artisan meal-evidence:privatizar             # mueve
```

Y comprobar después que la URL vieja ya no responde 200.

## Lo que NO se tocó, y por qué

- **Avatares** (`AuthController::uploadAvatar` → `public_path('uploads/avatars')`). También
  son públicos, pero son fotos de perfil que la persona sube a propósito y que la aplicación
  muestra a sus compañeros en 35 lugares del frontend. Privatizarlos exige un endpoint
  autenticado y cambiar todos esos `<img src>` a blobs — es otra ronda, y la sensibilidad no
  se compara con una foto de vigilancia que el sistema obliga a tomar. **Queda anotado.**
- **Foto de fichaje** (`time_entries.photo_url`, §67). Hoy está **dormida**: ningún flujo del
  frontend la produce y no hay ni una fila con valor. Pero `PurgeClockPhotos` sigue leyendo
  `public_path(...)`, así que **si esa función se enciende, nace con el mismo agujero**. Antes
  de activarla hay que mandarla al disco privado con este mismo patrón.
