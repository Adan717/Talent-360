# Archivo Digital — plan de construcción (2026-08-08)

**Estado:** plan listo, decisiones tomadas. La sesión que ejecute esto debe poder escribir
código casi sin preguntar nada. **Arrancar con `/ponytail`.**
**Estimación:** 3 días efectivos (backend 1, frontend 1, pruebas+deploy+e2e 1).

---

## Diagnóstico (verificado contra el código, 2026-08-08)

El censo decía "583 líneas, 7 marcadores sin terminar". La realidad es peor y más simple:
**`GestorDocumentos.tsx` es un mockup de frontend al 100%. No existe NI UNA línea de
backend**: ni controller, ni rutas, ni tablas, ni storage. En detalle:

| Mentira | Dónde | Daño |
|---|---|---|
| Expedientes FABRICADOS por empleado (Solicitud, Acta, INE "validados" con fechas/tamaños falsos) | `GestorDocumentos.tsx:100-118` | El dueño ve documentos "validados" que no existen |
| Upload simulado: barra de progreso con `setInterval`, el archivo **jamás sale del navegador** | `:120-159` | **Pérdida de datos**: subes el INE real, se pierde al refrescar |
| Visor de PDF falso: sello "SAT", "QR SECURE", texto legal inventado (art. 47 LFT, "firma TalentSha256") | `:452-519` | Teatro que aparenta validez legal |
| 3 manuales corporativos hardcodeados; "vincular a curso" solo muta estado local + `alert` | `:43-71, :161-170` | Nada persiste |
| Estados validado/pendiente/rechazado sin ningún flujo detrás | `:15` | Estatus decorativos |

La única llamada real al API es la lista de cursos de Academia (con mock de fallback).
Es el mismo patrón de los certificados de Academia pre-R5 (`window.print()` sin nada
detrás). **Esto no se audita: se construye**, con la pantalla ya diseñada como guía.

---

## Decisiones YA tomadas (no re-discutir en la sesión de código)

- **D1 — Storage PRIVADO.** `storage/app/expedientes/{tenant_id}/{employee_id}/{uuid}.{ext}`
  vía `Storage::disk('local')`. **NUNCA `public_path()`** — a diferencia de las fotos del
  reloj (ver "Hallazgo colateral"). Descarga EXCLUSIVAMENTE por endpoint autenticado que
  valida tenant + rol y hace `Storage::download()`/stream. El FE consume con el patrón
  axios-blob que ya usa `handlePrintTicket` en FacturacionManager (responseType blob →
  createObjectURL). Persistencia en V2 verificada: el compose monta `./Backend:/var/www`
  del host y `git reset --hard` del deploy no toca `storage/` (untracked/ignorado).
- **D2 — Dos tablas, un controller.**
  - `employee_documents`: id, tenant_id (FK), employee_id (FK), doc_type (string — uno de
    la checklist o 'otro'), original_name, path, mime, size_bytes, status
    ('pendiente'|'validado'|'rechazado'), rejection_reason nullable, uploaded_by (FK users),
    validated_by nullable (FK users), validated_at nullable, timestamps, softDeletes.
    Índice (tenant_id, employee_id).
  - `company_documents`: id, tenant_id, name, category, original_name, path, mime,
    size_bytes, linked_course_id nullable (FK courses, nullOnDelete), uploaded_by,
    timestamps, softDeletes.
  - `DocumentosController` único (sección expedientes + sección corporativos). Modelos
    `EmployeeDocument` y `CompanyDocument` con `Tenantable`.
- **D3 — Acceso v1: SOLO admin/supervisor** (decisión del usuario 2026-08-08). Rutas en el
  grupo `role:admin,supervisor` + `tenant.module:documentos`. El colaborador NO ve su
  expediente en v1 — extensión v2 si el dogfooding lo pide. `employee_id` viene del
  cliente ⇒ SIEMPRE `withoutGlobalScopes()->where('tenant_id', $tenantId)` (ids globales,
  lección repetida).
- **D4 — Checklist FIJA de 6 requeridos** (decisión del usuario): Solicitud de empleo,
  Acta de nacimiento, INE, CURP, RFC, Comprobante de domicilio. Constante en el
  controller; el GET del expediente devuelve `checklist` = los 6 con su documento (o
  null = FALTANTE honesto). Sin configuración por puesto ni por tenant (YAGNI; el mock
  inventaba un doc por puesto — eso muere).
- **D5 — Flujo de estados.** Subir → 'pendiente'. Validar/rechazar: admin/supervisor,
  con `rejection_reason` obligatorio al rechazar; sella validated_by/validated_at.
  Idempotente y sin poder validar lo borrado. Nada nace "validado".
- **D6 — Límites en el trust boundary (no se negocian):** `mimes:pdf,jpg,jpeg,png`,
  `max:10240` (10 MB), validación SERVER-side; el nombre en disco es uuid (jamás el
  original — path traversal); `original_name` solo se guarda como texto para mostrar.
- **D7 — Visor honesto.** Muere el PDF falso del SAT completo. v1: botón Ver/Descargar
  que baja el blob real (pdf/img en iframe/objectURL en modal simple, resto descarga).
- **D8 — Manuales corporativos: mismo patrón.** Upload real + categoría (texto libre con
  sugerencias), `linked_course_id` PERSISTIDO al vincular (el modal ya existe). Mostrar
  el manual dentro del curso de Academia queda FUERA de v1 (anotado como v2).
- **D9 — Sin purga automática** de expedientes (documentos contractuales; se conservan).
  softDeletes + quién subió/borró es suficiente rastro en v1. Al borrar (soft), el
  archivo físico se CONSERVA (recuperable); una purga real sería comando aparte, v2.
- **D10 — Módulo/gating.** Id de módulo FE: `'documentos'` (ya existe en App.tsx, color
  amarillo). Middleware `tenant.module:documentos` en las rutas nuevas — verificar que el
  FeatureAccessService reconozca esa llave (si no existe en el mapa de módulos, agregarla
  ahí, no inventar otra).

## Endpoints (contrato exacto)

Grupo: `auth:sanctum` + `role:admin,supervisor` + `tenant.active` + `tenant.module:documentos`.

| Método y ruta | Hace | Respuesta |
|---|---|---|
| GET `/admin/documentos/expedientes` | Resumen por empleado activo: subidos/validados/faltantes (para el grid de carpetas) | `{employees: [{employee_id, name, role, subidos, validados, faltantes}]}` |
| GET `/admin/documentos/expedientes/{employeeId}` | Expediente: checklist de 6 + extras | `{checklist: [{doc_type, doc|null}], extras: [doc...]}` |
| POST `/admin/documentos/expedientes/{employeeId}` | Subir (multipart: `file`, `doc_type`) — reemplaza al mismo doc_type marca al anterior soft-deleted | `{success, doc}` |
| GET `/admin/documentos/descargar/{docId}?scope=empleado\|corporativo` | Stream autenticado del archivo | binario |
| POST `/admin/documentos/{docId}/validar` | body `{accion: 'validar'\|'rechazar', motivo?}` | `{success, doc}` |
| DELETE `/admin/documentos/{docId}` | Soft-delete | `{success}` |
| GET `/admin/documentos/corporativos` | Lista manuales | `{docs: [...]}` |
| POST `/admin/documentos/corporativos` | Subir manual (multipart + `category`) | `{success, doc}` |
| POST `/admin/documentos/corporativos/{id}/vincular` | body `{course_id\|null}` — persiste el vínculo | `{success, doc}` |

## Frontend (reescritura de GestorDocumentos.tsx)

- BORRAR: fabricación de docs por empleado (:100-118), upload simulado (:120-159), visor
  SAT falso (:452-519), manuales hardcodeados (:43-71), cursos mock de fallback (:88-92).
- Grid de carpetas desde el endpoint de resumen (contadores REALES; "X faltantes" en rojo
  honesto, no "validados" inventados).
- Expediente: checklist de 6 con estados reales (FALTANTE / pendiente / validado /
  rechazado+motivo), upload real (`FormData` + `onUploadProgress` de axios — progreso
  REAL, no timer), botones validar/rechazar (con motivo), ver/descargar por blob.
- Corporativos: lista real, upload real, vincular persistiendo course_id.
- Mantener el diseño visual existente (tarjetas, tabs, dock móvil): solo se le cambia el
  motor, no la carrocería.

## Pruebas (archivo nuevo `ArchivoDigitalTest`, mínimo estos casos)

1. Subir a un empleado de MI tenant → 200, archivo en disco privado, status 'pendiente',
   fila con uuid (no el nombre original) en `path`.
2. Subir a un empleado de OTRO tenant → 404 y `Storage::assertMissing`.
3. Un `empleado` (rol) no puede ni listar ni descargar → 403.
4. Descargar mi doc → 200 con el contenido; descargar doc de otro tenant → 404.
5. Tipo/tamaño inválidos (exe, >10MB) → 422 y nada en disco (`Storage::fake`).
6. Validar → sella validated_by/at; rechazar sin motivo → 422; con motivo → guarda.
7. Reemplazo del mismo doc_type: el viejo queda soft-deleted, checklist muestra el nuevo.
8. Checklist: empleado sin docs → 6 faltantes; con INE subido → 5 faltantes.
9. Corporativo: vincular curso persiste `linked_course_id`; curso borrado → null (FK).
10. GET resumen no incluye empleados de otro tenant.

Usar `Storage::fake('local')` en todos. 0 pruebas existen hoy — este archivo es la red.

## Cierre de la ronda

1. Suites: `--filter=ArchivoDigital` → familia nómina/reloj (nada debe moverse — módulo
   nuevo) → sqlite completa → **Postgres DENTRO del contenedor**.
2. Build FE + verificación en navegador local (tenant 1, admin.verif): subir un PDF real,
   refrescar (persiste), validar, descargar, rechazar con motivo, corporativo + vínculo.
3. Commit "Archivo Digital: construccion real (el modulo era un mockup sin backend)" +
   push ambos remotos + deploy-v2 (respaldo antes, migrate lo hace el script).
4. E2E en V2: subir un documento a un empleado del tenant 2, verificar que sobrevive un
   `docker compose restart backend` y una corrida de deploy-v2 (D1/D10).
5. Actualizar `docs/CENSO_MODULOS_2026-08-06.md` (Archivo Digital: de "7 marcadores" a
   "construido en ronda 2026-08"), memoria y bitácora.

## Guardarraíles

- NO tocar el wizard (terreno del jefe), NO tocar Nómina/Reloj/Tareas/Academia.
- NO usar `public_path()` para nada de este módulo.
- No inventar config por puesto, ni versionado de documentos, ni OCR, ni firmas: v2.
- El diseño visual del FE se conserva; solo se reemplaza el motor.

## Hallazgo colateral (NO resolver en esta ronda, no perderlo)

**Las fotos de fichaje viven en `public_path()`** (`PurgeClockPhotos.php:32`) — archivos
biométricos servidos como estáticos públicos, sin autenticación, adivinables por URL. La
purga a 90 días (§23 ARCO) no quita que estén públicos mientras existen. Merece su propia
mini-ronda: moverlas a storage privado + endpoint autenticado (mismo patrón D1 que este
plan). Anotado también para el Monitor/Reportes que las muestran.

## Definition of done

Un admin del tenant de prueba sube el INE real de un empleado, lo ve como 'pendiente', lo
valida, lo descarga íntegro, **sobrevive refresh y deploy**, un empleado raso no puede
tocarlo ni por URL directa, y la checklist dice la verdad (faltantes = faltantes). Las dos
suites en verde y desplegado en la V2.
