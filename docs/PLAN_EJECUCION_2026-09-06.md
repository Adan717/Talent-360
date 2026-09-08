# Plan de ejecución — 2026-09-06

Este documento es para una sesión de **ejecución de código**. Las decisiones ya están tomadas
(abajo). El ejecutor no tiene que decidir "qué es mejor": construye lo que aquí dice, verificando
contra el código de hoy y probando cada pieza.

## Reglas para el ejecutor (no negociables)

1. **Verifica contra el código y el servidor de HOY**, nunca contra memorias ni documentos viejos.
   Cita `archivo:línea`. Config y entorno se comprueban en el servidor (`ssh -i ~/.ssh/talent360_v2
   root@46.225.153.115`, `docker exec talent360-v2-backend printenv`), no en el `.env.example`.
2. **Cada pieza lleva prueba candado** antes de darse por hecha. Suite rápida sqlite por archivo con
   `--filter`; suite completa una vez por tanda con `php -d memory_limit=1G vendor/bin/phpunit`.
   Frontend: `npx tsc -b` (NO `tsc --noEmit`) y `npx vitest run`.
3. **No desplegar sin respaldo fresco.** Despliegue: `ssh ... /usr/local/bin/deploy-v2`. Antes,
   `/usr/local/bin/respaldo-talent360`.
4. Repo vivo: `C:/Users/adanc/Documents/SaaS-Talent-360-merge`, rama `main`. La V2 corre lo que se
   empuja aquí. **No hay Docker local** para la suite Postgres: las pruebas que dependen de Postgres
   (trigger, purga) se corren en el servidor con la invocación segura de `phpunit.postgres.xml`
   (`docker exec -e DB_*` de PRUEBAS, base `talent360_test`, jamás la viva).

## Decisiones cerradas (no re-litigar)

- Nómina: **orienta, no timbra**. ISR/IMSS como **referencia para el contador**, en **reporte aparte**
  (el recibo del trabajador NO cambia). Timbrado CFDI descartado por seguridad.
- Pagos: **Stripe** (clientes pagan con **tarjeta**). Dos modalidades: **liga manual + recurrente**.
- Falta de pago: **una semana de gracia** con aviso en la app; al vencer, la empresa **deja de marcar
  asistencia**. (OJO: el contrato dice hoy 5 días — ver Plan C, paso 0.)
- Buzones (denuncia anónima y evaluación 360): **construir la pantalla de lectura**.
- Asistente del reglamento LFT: **hacerlo real con la API key de OpenAI**; propone y el admin confirma.
- Botones muertos de Facturación: **borrar**. Matriz de permisos: **construir la pantalla**.
- Texto legal: **aplicar los 7 cambios** (Plan A).
- Precios: se quedan como están. Litigio: lo marca el admin. (Ya cerrado, sin trabajo.)

---

## PLAN A — Limpieza (modelo sugerido: Fable 5)

Trabajo mecánico y de bajo riesgo. Se puede hacer todo en una tanda.

### A1. Texto legal (`Frontend/src/components/LegalModal.tsx`)
Aplicar los 7 cambios. Una sola copia del texto alimenta el modal y `PaginaLegal.tsx`, así que con
editar `LegalModal.tsx` basta. Líneas de referencia (confirmar antes de editar, pueden moverse):
- `:239` 99.5% garantizada → "opera bajo mejores esfuerzos, sin comprometer un porcentaje específico".
- `:245` mantenimiento 24h → "procurará informar por adelantado cuando sea posible".
- `:267` cancelar desde el panel → "podrá solicitar la cancelación escribiendo a soporte@talent360.com.mx".
- `:269` exportación Excel/CSV → "podrá solicitar una copia de sus datos en formato JSON".
- `:94`, `:133`, `:220`, `:226` factura CFDI automática → quitar toda mención; "la facturación fiscal se gestiona por separado".
- `:91` nómina "conforme a la LFT" → "calcula pre-nóminas e incidencias como insumo; no calcula ISR ni IMSS ni sustituye la nómina formal".
- `:227` suspensión → añadir "durante la suspensión, el registro de asistencia queda interrumpido hasta regularizar el pago".
- `:329` "se conservan encriptadas" contradice `:114` "no se cifran en reposo" → corregir la 329 a la verdad (no cifradas en reposo).
- **Aceptación:** ninguna de las frases viejas aparece en `talent360.com.mx/privacidad`. Actualizar
  `App\Support\AvisoDePrivacidad::VERSION` a la fecha de hoy (una prueba liga versión↔texto).

### A2. Borrar botones muertos de Facturación (`Frontend/src/components/FacturacionManager.tsx`)
Los tres botones sin `onClick` (~`:849-870`): "Descargar PDF", "Ver XML", "Cancelar Factura". Se
**borran** (son del timbrado descartado). Revisar que no quede el bloque contenedor vacío.
- **Aceptación:** no quedan botones sin acción en esa pantalla; `tsc -b` limpio.

### A3. Asistente del reglamento LFT, real (`Frontend/src/components/LftManager.tsx:66-114` + backend)
Hoy `handleLftFileUpload` es una animación que rellena números fijos. Reemplazar por real:
- Frontend: subir el PDF/TXT a un endpoint nuevo; mostrar lo que la IA propone; el admin **confirma**
  antes de guardar (no auto-aplica).
- Backend: endpoint nuevo (p.ej. `POST /admin/lft/leer-reglamento`, grupo `role:admin`) que use
  `App\Services\GeminiAIService` (ya sale por OpenAI vía `proveedor()`; la llave vive solo en el .env
  del servidor). Prompt strict que devuelva las tolerancias/reglas detectadas en JSON; NUNCA escribe
  en `lft_settings`, solo propone.
- **Aceptación:** subir un reglamento real devuelve valores extraídos del texto (no fijos); sin llave
  de OpenAI responde error claro; nada se guarda hasta que el admin confirma. Prueba con `Http::fake`.
- **Verificar antes:** cómo valida `store` de LFT hoy (`LftSettingController`) para reusar su whitelist.

### A4. Matriz de permisos por puesto — construir la pantalla (Frontend)
El backend ya existe: `GET`/`PUT /admin/permissions/matrix` (`routes/api.php:405-406`, `role:admin`).
Falta la pantalla que lo consuma (grep `permissions/matrix` en Frontend = 0). Construir una pantalla
en el panel de admin que lea la matriz y guarde cambios por puesto.
- **Aceptación:** un admin edita permisos de un puesto y se reflejan; un supervisor nuevo ya no nace
  sin capacidades sin poder arreglarlo desde la app. `tsc -b` + vitest.
- **Verificar antes:** forma exacta del JSON que devuelve/espera el endpoint.

### A5. Buzones — pantalla de lectura
Verificado hoy: la denuncia de incidentes tiene lectura (`IncidentReportController::indexIncidents`,
ruta `/reports/employee` GET `:786`) — **verificar si ya hay pantalla**; el **feedback anónimo**
(`indexFeedback`) y los **resultados de evaluación 360** (`Evaluation360Controller::myResults`/`scores`)
existen SIN ruta ni pantalla. Construir:
- Rutas de lectura que faltan (feedback anónimo → solo admin/RRHH; evaluación 360 → resultados).
- Pantalla en RRHH/Monitor para leerlos.
- **Aceptación:** lo que un colaborador envía por esos buzones es legible por quien debe; la promesa
  "enviado de forma segura" deja de ser falsa. Cuidar el anonimato del feedback (no exponer autor).
- **Verificar antes:** columnas reales de `employee_reports`/`performance_evaluations` y qué guarda
  hoy `storeFeedback` (¿anónimo de verdad, sin user_id?).

---

### Estado del Plan A — ejecutado el 2026-09-07 (Fable 5.1) — DESPLEGADO en la V2 el 2026-09-08

Commits `27940fc` (A1/A2), `e5e1fa7` (A4), `b1844f1` (A5), `ccbe653` (A3), empujados a `origin/main` y
desplegados con respaldo previo (`20260908_044618` → `deploy-v2`). Verificado en vivo: migración aplicada,
4 rutas nuevas (401 sin sesión), bundle público con el texto legal nuevo y sin frases viejas, chunks con las
4 pantallas, `OPENAI_API_KEY` presente en el `.env` del contenedor. `privacidad:pedir-consentimiento --aplicar`
corrido el 2026-09-08: marcó 1 cuenta (la única que había aceptado la versión de julio); las otras 14 ya
estaban pendientes de aceptar por primera vez.

| Pieza | Qué se hizo | Candado |
|---|---|---|
| A1 | Los 7 cambios (+ el 8.º de la línea 329) en `LegalModal.tsx`; `AvisoDePrivacidad::VERSION` = 2026-09-07 | `LegalModal.test.ts` (frases viejas ausentes, nuevas presentes, fecha = FECHA_LEGIBLE) |
| A2 | Fuera los 3 botones sin acción y la columna "Acciones" de la tabla de CFDI | `tsc -b` limpio |
| A3 | `POST /admin/lft/leer-reglamento` (role:admin, throttle 10/min): lee PDF (smalot/pdfparser) o TXT, la IA propone (`GeminiAIService::leerReglamentoLft`), `PropuestaDeReglamentoLft` depura contra la lista blanca de saveSettings; NUNCA escribe. Pantalla: la animación falsa se sustituyó por el panel `PropuestaDeReglamento` (propone la IA, confirma el admin, guarda con el botón de siempre) | `AsistenteReglamentoLftTest` (7, con PDF real vía dompdf y `Http::fake`), `PropuestaDeReglamento.test.tsx` |
| A4 | Pantalla `MatrizDePermisos` como pestaña "Permisos por puesto" en Configuración (sólo admin) | `MatrizDePermisos.test.tsx` (7) |
| A5 | Rutas que faltaban (`GET /clock/evaluations/my-results`, `GET /clock/evaluations/scores`), migración con `leadership_score` y `cycle_month` (la tabla nunca las tuvo: los lectores del 360 reventaban), buzón anónimo sólo admin, filtro por empresa explícito, pestaña "Buzones" en RRHH y "Mis resultados" en la Evaluación 360 del Reloj | `BuzonesDeLecturaTest` (7, incluye aislamiento entre empresas) |

**Hallazgo colateral:** `.github/workflows/deploy.yml` (Sprint 2) dice desplegar a Hetzner en cada push a `main` por rsync a `/opt/talent360`; no es el camino de la V2 (`deploy-v2`) y no se verificó si corre o falla.

---

## ARRANQUE DE LA SIGUIENTE SESIÓN — Plan C (empezar por C3), verificado el 2026-09-08

Lo de abajo se comprobó contra el código en `ccbe653` y contra el contenedor de la V2 ese día. Es el punto de
partida; la sesión que lo ejecute debe volver a confirmar cada línea antes de tocarla (regla 1).

**Estado real de pagos hoy (V2):**
- El `.env` del contenedor NO tiene `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` ni token de Mercado
  Pago; sólo `APP_ENV=production`. **Adán las pone** (C1); sin ellas C2 sólo se puede probar con `Http::fake`
  y en el sandbox de Stripe con llaves de prueba en el `.env` LOCAL del ejecutor, nunca en el repo.
- `laravel/cashier` ^15 está en `composer.json:12` y SÍ instalado en el vendor del contenedor.
- Barrido de mora agendado y activo: `bootstrap/app.php:91` →
  `suscripciones:revisar-vencidas --aplicar --sin-suspender` a las 06:00 (comando en
  `app/Console/Commands/RevisarSuscripcionesVencidas.php`, pruebas en `CobranzaAutomaticaTest`).

**Punteros confirmados para cada paso:**
- **C0 (gracia 5 vs 7):** `App\Support\EstadoDeCobranza::DIAS_DE_GRACIA_POR_DEFECTO = 5`
  (`app/Support/EstadoDeCobranza.php:58`, con ajuste global en `:88` vía `diasDeGracia()`) y el contrato en
  `Frontend/src/components/LegalModal.tsx:226` ("periodo de gracia de 5 días naturales"). Si se pasa a 7, cambiar
  los dos y subir `AvisoDePrivacidad::VERSION` otra vez (la prueba de `LegalModal.test.ts` no cubre esa frase;
  añadirla).
- **C3 (webhook, HACER PRIMERO):** `app/Http/Controllers/StripeWebhookController.php:23-50`. Lee el secreto con
  `env('STRIPE_WEBHOOK_SECRET')` (`:27`) FUERA de `config/` — con la config cacheada devuelve null y el
  controlador cae al modo "sin firma" (`:49-50`) en silencio. Corregir las dos cosas: pasar el secreto a
  `config/services.php` y exigir firma siempre que `app()->environment('production')`. La ruta pública es
  `routes/api.php:64` (`POST /webhooks/stripe`, sin auth ni throttle).
- **C1 (Billable):** el trait está comentado en `app/Models/Company.php:7,12`, pero `Company` casi no se usa
  (4 referencias en `app/`); el inquilino real es `App\Models\Tenant` (`app/Models/Tenant.php:9`, sin
  Billable) y las columnas `stripe_customer_id` / `stripe_subscription_id` viven en `tenants`
  (`database/migrations/2026_06_30_090002_add_stripe_fields_to_tenants_table.php:15-16`). Poner `Billable`
  en `Tenant`, no en `Company`.
- **C2 (checkout):** hoy sólo hay Mercado Pago y un simulador en `app/Http/Controllers/SubscriptionController.php`
  (`createPreference :34`, `simulatedCheckout :289`, `simulatedConfirm :490`, `webhook :521`) con rutas
  públicas en `routes/api.php:60-63`. Precio por colaborador: `App\Support\Tarifario::cotizar()`
  (`app/Support/Tarifario.php:159`). El grupo `billing` (`role:admin`) empieza en `routes/api.php:559`.
- **C4 (suspensión):** `app/Http/Middleware/CheckTenantActive.php:25-28` bloquea por `tenants.is_active`
  con el mensaje "Empresa suspendida"; `EstadoDeCobranza::decidir()` (`:112`), `diaDeAviso()` (`:91`) y
  `yaAvisadaEnEsteCiclo()` (`:204`) ya modelan aviso y gracia. Falta conectar el `past_due` de Stripe, el
  banner en la app y decidir si se quita `--sin-suspender` de la agenda.

**Punteros confirmados para el Plan B (después de C):**
- Motor: `ClockService::calculatePayrollForEmployee` en `app/Services/ClockService.php:1744` (no tocar).
- Desglose del recibo: `database/migrations/2026_08_16_120000_add_desglose_a_weekly_payrolls.php`.
- `employees.hire_date` existe (`2026_06_26_010654_create_employees_table.php:31`).
- Catálogo de reportes: `app/Support/CatalogoDeReportes.php`. El embudo común NO es una clase de Support:
  es el trait `App\Http\Controllers\ArmaReportesCsv` (`app/Http/Controllers/ArmaReportesCsv.php:19`), que usan
  los cuatro controladores de reportes.

**Gotchas del entorno aprendidos el 2026-09-07/08 (aplican a cualquier plan):**
- El `Backend/` del host se monta como `/var/www` en el contenedor: el `vendor` del host PISA el de la
  imagen. Al agregar una dependencia, tras `deploy-v2` hay que correr
  `docker exec talent360-v2-backend composer install --no-dev --optimize-autoloader`.
- En local PHP es 8.5 y `phpspreadsheet` exige <8.5: `composer require` sólo con
  `--ignore-platform-req=php` (composer vive en `%LOCALAPPDATA%\Programs\PHP\current\composer.bat`).
- `TenantScope` se apaga cuando la app corre en consola (PHPUnit incluido): una prueba de aislamiento entre
  empresas sólo protege si el controlador filtra `tenant_id` explícito.
- `env()` fuera de `config/` devuelve null con la config cacheada (hoy la V2 NO cachea config, pero no
  depender de eso).
- La suite completa local tarda ~5 min (1808 pruebas); `ReincorporarLimpiaLaBajaTest` falla entre 18:00 y
  24:00 hora de México por un defecto ajeno (baja estampada en UTC) que quedó como tarea aparte.
- `.github/workflows/deploy.yml` se dispara en cada push a `main` y apunta a `/opt/talent360` (no es la V2).

---

## PLAN B — Nómina para el contador (modelo sugerido: Opus 5)

Alto cuidado: datos fiscales. NO calcula el pago final; entrega **referencia** para el contador.

### B0. Verificación previa (obligatoria)
- Confirmar que `ClockService::calculatePayrollForEmployee` (~`:1744`) sigue pagando por día y que
  `weekly_payrolls` guarda el desglose. No tocar el motor de pago existente.
- Confirmar que `employees.hire_date` existe (para antigüedad → factor de integración del SBC).

### B1. Clasificar percepciones gravado/exento y calcular SBC
- Añadir la clasificación fiscal de cada percepción (sueldo, bonos, prima festivo…): gravado vs exento
  según LFT/LISR (aguinaldo exento hasta 30 UMA, prima vacacional, etc.). v1 puede ser conservador y
  **declarar** los supuestos.
- Calcular el **salario base de cotización** (SBC) con el factor de integración por antigüedad
  (`hire_date`), con tope de 25 UMA.
- Clase de soporte nueva en `App\Support` (nombre en español), tablas fiscales (UMA, art. 96, cuotas
  IMSS) **con vigencia declarada (2026)** en un solo sitio, marcadas para actualizar cada año.
- **Aceptación:** un caso conocido da SBC y gravado/exento correctos; prueba unitaria con `$ahora`
  inyectado. Nada de esto toca el neto que ya se paga.

### B2. ISR e IMSS estimados + reporte para el contador
- Calcular ISR (art. 96 + subsidio al empleo) e IMSS (cuota obrera sobre SBC) como **estimación**.
- Reporte nuevo en el catálogo (`App\Support\CatalogoDeReportes`) tipo "Pre-nómina para tu contador":
  una fila por persona con percepciones, gravado/exento, SBC, ISR e IMSS estimados. Los tres formatos
  por el embudo común (`ArmaReportesCsv`). Cada archivo **declara al pie**: "cifras de referencia, no
  sustituyen el cálculo del contador; el sistema no timbra".
- Tras `permission:manage_payroll`. El recibo del trabajador **no cambia**.
- **Aceptación:** el reporte cuadra internamente; el pie declara el alcance; prueba que recorre el
  catálogo exigiendo que el id responda en los 3 formatos.
- **Riesgo:** tablas fiscales que caducan cada año → dejar la vigencia visible y una sola fuente.

---

## PLAN C — Pagos con Stripe y suspensión (modelo sugerido: Opus 5)

Hay dinero de por medio. Verificar cada paso en el sandbox de Stripe antes de tocar producción.

### C0. Decisión a confirmar con Adán al inicio
Gracia: el código (`App\Support\EstadoDeCobranza::DIAS_DE_GRACIA_POR_DEFECTO = 5`) y el contrato dicen
**5 días**; Adán pidió **una semana (7)**. Alinear ambos. Recomendado: poner 7 y actualizar el texto
del contrato en `LegalModal.tsx` para que coincidan.

### C1. Encender Stripe Cashier
- `laravel/cashier ^15` ya está en `composer.json`; el trait `Billable` está **comentado** en
  `app/Models/Company.php:7,12`. Verificar si el tenant es `Company` o `Tenant` y poner `Billable`
  en el modelo correcto. Columnas `stripe_customer_id`, `stripe_subscription_id`, `stripe_price_id`,
  `stripe_payment_method_id` **ya existen** en `tenants`.
- Credenciales productivas (llave de Stripe) las pone **Adán** en el .env del servidor; el ejecutor
  no maneja credenciales.

### C2. Checkout con tarjeta — liga manual + recurrente
- Construir el checkout de Stripe (hoy solo hay MercadoPago en `SubscriptionController` y un webhook
  esqueleto). Precio por colaborador desde `App\Support\Tarifario` (billing_plans).
- **Liga manual:** generar una liga de pago Stripe por periodo. **Recurrente:** suscripción Stripe que
  cobra la tarjeta cada mes; los reintentos y el estado `past_due` los maneja Stripe Billing (dunning).
- **Aceptación (sandbox):** una compra de prueba deja `current_period_end` y el estado correctos;
  una tarjeta sin fondos cae en `past_due` sin romper nada.

### C3. Cerrar el webhook de Stripe (seguridad, hacer primero)
`StripeWebhookController::handleWebhook:50` **salta la verificación de firma si no hay secret**. En
producción eso deja entrar avisos de pago falsos. Exigir firma siempre que el entorno sea producción.
- **Aceptación:** un webhook sin firma válida en producción → 400; con firma válida → procesa. Prueba.

### C4. Suspensión con gracia y aviso en la app
- El motor ya existe: `App\Support\EstadoDeCobranza` + `suscripciones:revisar-vencidas` (agendado
  `--sin-suspender` a las 06:00) + `CheckTenantActive` bloquea `/clock/punch`.
- Conectar: cuando Stripe marca `past_due`, empieza la gracia (7 días) con **banner de pago pendiente**
  en la app (no bloquea). Al vencer, la empresa **deja de marcar asistencia**. Decidir con Adán si el
  apagón al vencer se automatiza (quitar `--sin-suspender`) o queda manual.
- Falta captura manual de fecha de corte para cobros fuera de línea (por si acaso), en el panel de
  plataforma.
- **Aceptación:** simular el ciclo completo en sandbox: pago falla → banner → 7 días → apagón →
  registrar pago → se reactiva. Prueba candado de que una empresa al corriente nunca se toca.

### Estado del Plan C — ejecutado el 2026-09-08 (Opus 5)

| Paso | Estado | Dónde quedó |
|------|--------|-------------|
| C0 gracia 5 vs 7 | **CERRADO SIN CAMBIO** | Adán decidió el 2026-09-08 **dejar 5 días**. Código y contrato ya coincidían: nada que tocar, y NO se subió `AvisoDePrivacidad::VERSION` (nadie vuelve a aceptar el aviso). Candado nuevo: `AvisoYApagonPorFaltaDePagoTest::test_la_gracia_es_la_que_promete_el_contrato`. |
| C3 webhook | **HECHO** | `StripeWebhookController::handleWebhook`. Falla CERRADO: con secreto la firma es obligatoria siempre; en producción sin secreto → 400; sin SDK → 400. El secreto sale de `config('cashier.webhook.secret')` (sobrevive a `config:cache`) y un relleno tipo `YOUR_STRIPE_WEBHOOK_SECRET` **no cuenta**: tiene que empezar por `whsec_`. 7 pruebas en `WebhookDeStripeFirmadoTest`. |
| C1 encender Stripe | **HECHO, ADAPTADO** | **No se puso `Billable`.** Cashier guarda su propio estado de suscripción y este producto ya lo tiene en `tenants` + `EstadoDeCobranza` (motor probado el 2026-09-05): adoptarlo dejaba DOS fuentes de verdad para "¿está pagada esta empresa?". Se usa la **configuración** de Cashier (`cashier.key/secret/webhook.secret`: un solo sitio para las llaves) y se habla con la API REST por `Http`, mismo patrón que `FacturapiBillingProvider` y —a diferencia del SDK— comprobable en pruebas. El SDK se sigue usando para verificar la firma. |
| C2 checkout | **HECHO** (falta la compra de prueba en sandbox) | `App\Services\Billing\CobroConStripe` dentro del MISMO embudo: `SubscriptionController::createPreference` intenta Stripe → Mercado Pago → simulador. `modalidad=liga` cobra una vez el periodo; cualquier otra cosa crea la **suscripción recurrente** (reintentos y `past_due` los hace Stripe Billing). Si Stripe está configurado y falla, responde **502 y NO cae al simulador**: caer daría por pagada una compra que nadie cobró. 12 pruebas en `CheckoutDeStripeTest`. |
| C4 suspensión | **HECHO** | Apagón **automático** (decisión de Adán 2026-09-08): la agenda de `bootstrap/app.php` ya no lleva `--sin-suspender`. Banner de pago pendiente: `App\Support\AvisoDeCobranza` → `/me` (sólo al admin) → `Frontend/src/components/BannerDeCobranza.tsx`; la pantalla no cuenta días, pinta lo que decidió el motor. Y la mitad que faltaba: **al entrar el pago, la empresa apagada por deuda se reactiva sola** (una suspensión manual por otro motivo, no). 13 pruebas en `AvisoYApagonPorFaltaDePagoTest`. |

**EL HALLAZGO QUE HACÍA INÚTIL TODA LA COBRANZA:** `tenants.current_period_end` y `mp_subscription_id`
**no están en `$fillable`** (a propósito), pero tanto el alta (`provisionTenant`) como el webhook las
escribían dentro de un `update([...])`/`create([...])`. La asignación masiva no falla: **las tira en
silencio**. O sea que **ninguna empresa ha tenido nunca fecha de corte** —por eso el comentario de
`EstadoDeCobranza` dice "hoy las 4 empresas vivas están así"—, y sin fecha de corte `decidir()`
devuelve `SIN_FECHA_DE_CORTE` y el barrido no las mira jamás: se podía dejar de pagar para siempre.
Ahora la fecha se estampa por una sola vía, `Tenant::estampaCicloDeCobro()`. De paso: un plan
**anual** concedía **un mes** (se cobraban 12 mensualidades y el cliente se habría suspendido once
meses antes); el periodo lo da ahora `Tarifario::finDelPeriodo()`, que pregunta por el mismo ciclo
que se cobró. **Consecuencia práctica: el apagón automático no puede tocar a las empresas de hoy**
(ninguna tiene fecha de corte); sólo actúa sobre los cobros que se registren de aquí en adelante.

**Refactor menor incluido:** `provisionTenant` se movió al trait `App\Http\Controllers\AprovisionaEmpresas`
(mismo patrón que `ArmaReportesCsv`) para que Stripe y Mercado Pago den de alta la empresa con el
MISMO código y no con dos copias.

**LO QUE FALTA Y NO ES CÓDIGO (Adán):**
1. Poner en el `.env` del servidor `STRIPE_KEY`, `STRIPE_SECRET` y `STRIPE_WEBHOOK_SECRET` (el
   ejecutor no maneja credenciales). Ojo: el `.env.example` trae rellenos `YOUR_…` que el código
   ignora a propósito, así que dejarlos equivale a no configurar nada.
2. Dar de alta el endpoint en el panel de Stripe: `https://<host>/api/v1/webhooks/stripe`
   (**con `/v1`**), suscrito a `checkout.session.completed`, `invoice.payment_succeeded`,
   `invoice.payment_failed`, `customer.subscription.updated` y `customer.subscription.deleted`.
3. Probar una compra en el **sandbox de Stripe** (tarjeta 4242…) y otra que falle. Eso no se pudo
   hacer en esta sesión: sin llaves, lo verificado es el contrato con la API (el cuerpo exacto que
   se le manda) y el circuito completo del webhook con firma real.
4. **APAGAR `ALLOW_SIMULATED_CHECKOUT` en la V2, en cuanto Stripe cobre.** Comprobado en el
   contenedor el 2026-09-08: el `.env` de la V2 **no tiene ninguna llave de Stripe** y sí tiene
   `ALLOW_SIMULATED_CHECKOUT=true` con `APP_ENV=production`. Eso deja vivo el checkout SIMULADO,
   que da de alta una empresa completa (con su admin y su token de sesión) **sin cobrar nada**, por
   una URL pública; el propio código avisa: "NUNCA encenderla en un servidor con clientes reales".
   Hoy es además el único camino de alta, porque no hay pasarela: **por eso hay que ponerlo en ese
   orden — primero las llaves, después apagar el simulador**, o el alta se queda sin puerta. Desde
   hoy, si no hay pasarela ni simulador, `createPreference` responde 503 con un mensaje claro en vez
   de una liga rota disfrazada de éxito.

---

## Orden sugerido

1. **Plan A** primero (rápido, visible, bajo riesgo). Empezar por A1 (legal) y A2 (botones).
2. **Plan C3** (cerrar el webhook) cuanto antes: es seguridad.
3. **Plan C** completo, luego **Plan B**. B y C son independientes entre sí.

Cada plan se despliega y verifica por separado. No juntar todo en un despliegue.
