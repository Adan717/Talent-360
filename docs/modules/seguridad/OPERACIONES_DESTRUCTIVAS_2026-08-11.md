# Lo que destruye datos — ronda 2026-08-11

Auditoría de todo lo que borra: respaldo/restauración, purgas, resets de QA y borrado de empresa.
Cuatro lentes en paralelo + un refutador por lente. **31 hallazgos declarados, 1 tumbado, 30
confirmados** (con solapes; ~18 defectos distintos). Ninguna de estas operaciones tenía prueba.

Los refutadores corrigieron dos afirmaciones de partida —incluida una mía— y esas correcciones
importan más que los hallazgos que confirmaron. Están anotadas abajo.

## Lo más grave: el despliegue borraba empresas

`deploy_to_hetzner.py:87` ejecutaba, en cada despliegue y dentro de un paso rotulado literalmente
**"(non-destructive)"**:

```
php artisan tenant:purge-test-tenants --force
```

Y ese comando consideraba "de prueba" a **toda empresa cuyo id fuera mayor que 1**. No existe
ninguna bandera `is_demo` ni `is_test` en la tabla `tenants` —se revisaron todas las migraciones—,
así que la heurística no tenía forma de distinguir una empresa de pruebas de una real. El borrado
era **físico** (query builder, ignorando el borrado lógico que la tabla sí tiene) e incluía
`time_entries` y `weekly_payrolls`: los fichajes y los recibos de nómina.

Cerrado por los dos extremos:

- Fuera la línea del script de despliegue. Un despliegue nunca borra datos de clientes.
- El comando ahora **exige los ids a mano** (`--tenants=2,3`), lista las empresas si no se los
  dan, se niega a tocar la empresa 1, y tiene gate de entorno (`ALLOW_QA_RESET`) como el resto.

## Explotable hoy: cualquier colaborador podía borrar la jornada de su empresa

`POST /sync/reset_day` estaba registrado en el grupo `role:empleado,employee,admin,supervisor,
platform_admin`. Es decir: **cualquier persona con sesión de colaborador**. Borra `time_entries`,
`store_logs`, `contingencies` y `audit_logs` de su empresa para la fecha que se le pase —fecha
libre, sin validar, así que también días pasados— y sólo `time_entries` se archiva: la bitácora
de auditoría, donde viven retardos y sanciones, se iba sin copia.

Su única cerradura era el gate de entorno. **Comprobado con una prueba: sin el arreglo, un
`empleado` recibe 200.** Ahora vive en el grupo de `platform_admin`, junto a las demás operaciones
destructivas.

De paso, dos incoherencias del mismo gate: `ALLOW_QA_RESET` tenía default **permisivo (`true`) en
las rutas** y restrictivo (`false`) en los controladores — la puerta decía una cosa y la cerradura
otra. Unificado en `false`.

## Eliminado: `/sync/init` hacía TRUNCATE CASCADE de toda la instancia

`initDb` truncaba `employees`, `job_roles`, `permissions` y `role_permissions` **sin filtrar por
empresa**. En Postgres, Laravel compila el truncate como `TRUNCATE ... RESTART IDENTITY CASCADE`
(y la aplicación nunca llama a `disableCascadeTruncate`), lo que vacía además **toda tabla que
referencie a la truncada**, sin importar su `ON DELETE`: recibos de nómina **firmados**
(`weekly_payrolls`), el **Archivo Digital** (`employee_documents`), las aprobaciones diarias y las
aperturas — de **todas las empresas de la instancia**.

No tenía un solo llamador en el frontend. Se borró el método y su ruta.

## `purgeArchive`: botón muerto que, de revivir, borraba el archivo de todos

Dos defectos acoplados:

1. La guardia comparaba `$user->system_role`, que **no es una columna ni un accessor**: es un campo
   que el frontend se inventa al iniciar sesión. En el servidor vale siempre `null`, así que el
   endpoint respondía **403 a todo el mundo**, incluido el platform_admin legítimo. El botón
   "Purgar Archivo" nunca pudo funcionar.
2. Debajo, sin `tenant_id` el DELETE iba contra `archived_time_entries` **entera** — y ése es
   justo el caso del platform_admin, que no tiene empresa propia.

La guardia se borró (el middleware `role:platform_admin` de la ruta ya hace ese control) y la
empresa es ahora obligatoria: sin ella, 422.

## El Simulador prometía no borrar nada y borraba el día real

`resetGlobalSimulation()` posteaba a `/sync/reset_day` con la fecha de hoy siempre que el modo
sandbox estuviera apagado — y su valor por defecto es apagado. Se llama desde los dos botones del
panel, cuyos diálogos afirman *"Los datos de la sesión anterior NO se borran"* y *"No afecta
fichajes reales"*, y cuyo comentario de código dice *"nunca borra datos"*. `reset_day` no
distingue simulación de realidad. Encima, el error se tragaba en un `console.error`, así que
cuando el gate respondía 403 la pantalla igual decía "sesión iniciada".

Se quitó esa llamada: el trabajo de esa función es reiniciar el estado **visual**. Para borrar
datos de prueba ya existe un botón propio y correctamente acotado (`/sync/reset` con `session_id`,
que sólo toca filas de simulación).

## El módulo de Respaldos: muerto, y con una trampa armada

**Nunca funcionó en producción.** La lista de tablas empezaba por `companies`, que **no tiene
columna `tenant_id`** (se indexa por id == tenant_id): el `where('tenant_id', ...)` reventaba con
un 500 en Postgres. Exportar, reponer y "subir a Drive": los tres, siempre.

Y ese 500 era lo único que impedía el desastre — porque el arreglo evidente (quitar `companies`)
**armaba el destructor**:

- `import` borraba con DELETE las filas de la empresa en sus 13 tablas y sólo después reinsertaba.
- `employees` **no estaba en el respaldo**. Sus llaves foráneas hacia `users` y `job_roles` son
  **`ON DELETE SET NULL`** — no CASCADE, como yo había supuesto al abrir la ronda; el refutador lo
  comprobó contra `pg_constraint` del Postgres real y la corrección importa: los expedientes **no
  se borraban, se desconectaban**. Cada uno quedaba con `user_id` y `job_role_id` en NULL, de forma
  permanente: toda la plantilla sin cuenta de acceso y sin puesto, con el expediente entero
  (CURP, RFC, NSS, sueldo) intacto pero inservible para operar.
- Ese mismo DELETE de `users` arrastraba **por CASCADE** una docena de tablas que tampoco están en
  el respaldo: bitácora de auditoría, chat, eventualidades, evaluaciones, denuncias, traspasos de
  llaves, monedero. Se perdían y no volvían.
- El "apagado" de llaves foráneas era inerte: `SET CONSTRAINTS ALL DEFERRED` no hace nada sobre
  restricciones que no son DEFERRABLE, que son todas aquí.

Para dimensionarlo: **80 tablas del producto llevan `tenant_id`; el respaldo cubría 13.**

### Qué se hizo

- **Reponer ya no borra.** Cada fila del archivo se escribe sobre la suya (por `id`, o por
  `(tenant_id, key)` en `system_settings`, que no tiene `id`) o se inserta si ya no está. Ninguna
  llave foránea se dispara, así que desaparecen de golpe la desconexión de expedientes y la
  pérdida por cascada. Lo creado después del respaldo sobrevive — y la pantalla lo dice, en vez de
  llamarlo "restaurar el estado".
- **Fuera `companies`** (sólo guarda los 4 campos de la pantalla de bienvenida) y **dentro
  `employees`, `permissions` y `role_permissions`**: un respaldo sin los expedientes ni las
  capacidades no respaldaba lo que importa.
- **El archivo deja de sacar secretos del servidor.** El export usaba `DB::table(...)->get()`, que
  no pasa por el modelo y por tanto ignora su `$hidden`: el JSON llevaba en claro el **hash de la
  contraseña** de cada persona, el **secreto de 2FA**, la **llave biométrica**, el **token del
  checador** y los ids de Google/Apple/Samsung. Ahora esas columnas no viajan. Como reponer sólo
  escribe las columnas que trae el archivo, una restauración **nunca pisa la contraseña de nadie**.
- **Confirmación antes de reponer**: elegir el archivo en el explorador disparaba la operación
  directamente. Ahora se muestra de qué empresa y de qué fecha es el respaldo y se pregunta.
- **Fuera la ficción de Google Drive.** "Vincular Cuenta de Google" no llamaba a ningún servidor:
  era un `setTimeout` que pintaba "Conectado", una cuenta de correo escrita a mano y dos archivos
  con tamaños inventados. "Respaldar en Google Drive" llamaba a un endpoint que armaba el JSON, lo
  **descartaba** y respondía "Copia de seguridad subida a Google Drive con éxito" con un `md5()`
  disfrazado de identificador de archivo. Todo ello vendido como función del **Plan Profesional**.
  Se eliminaron la columna, el endpoint y su ruta.
- **Textos honestos**: decía "archivo JSON **cifrado**" y sólo va **firmado**; y ahora la pantalla
  enumera qué incluye el respaldo y qué no (los archivos subidos y los recibos de nómina no).

## Pruebas

`PurgasNoBorranDeMasTest` (5) y `RespaldoNoDestruyeTest` (7), más dos casos nuevos en
`RoleMiddlewareTest`. **11 de 14 verificados fallando sin su arreglo**; el resto son controles.
La demostración que más importa: sin el arreglo, la prueba de reponer falla con *"sin cuenta, esa
persona no puede entrar ni fichar"* — el desastre ocurre de verdad, no es una hipótesis.

## Correcciones a lo que se dio por sabido

- **La FK de `employees` es SET NULL, no CASCADE.** Yo abrí la ronda afirmando lo segundo. El daño
  es igual de grave pero de otra forma: no se pierde el expediente, se pierde el vínculo — y eso
  es más difícil de diagnosticar y de reparar a mano.
- **El borrado de empresa del panel tampoco es lógico**: `deleteTenant` también hace barrido
  físico y termina en `forceDelete()`. La diferencia con el comando es que exige platform_admin y
  actúa sobre una empresa elegida.

## No corregido — anotado

| Qué | Por qué |
|---|---|
| **El chat interno se borra a los 7 días**, incluidos los mensajes privados del jefe, y ninguna pantalla lo advierte | Es una decisión de retención: hay que decidir el plazo y decirlo en pantalla, no cambiarlo a ciegas |
| **Al borrar una empresa, sus archivos se quedan en disco para siempre** (expedientes, evidencias, manuales) | Necesita recorrer el storage al borrar; ronda propia |
| **Se reutiliza el id de una empresa borrada**: la siguiente empresa hereda documentos y histórico que apunten a ese id | Mismo origen que lo anterior |
| `shifts:close-orphans` **nunca corre**: el código lo llama "el CRON de huérfanos" pero no está agendado | Decidir si debe correr y con qué periodicidad |
| La "Clave de Seguridad" del panel destructivo del Simulador está **escrita en el bundle de JavaScript** | Es una barrera de UI, no de seguridad; el control real es el rol. Decidir si se quita o se mueve al servidor |
| **No existe ningún respaldo automático del servidor**: el único es el botón manual | Es la pregunta de fondo de toda esta ronda y es del dueño: si hoy una empresa pierde datos, no hay de dónde recuperarlos |
