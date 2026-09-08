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

---

## Orden sugerido

1. **Plan A** primero (rápido, visible, bajo riesgo). Empezar por A1 (legal) y A2 (botones).
2. **Plan C3** (cerrar el webhook) cuanto antes: es seguridad.
3. **Plan C** completo, luego **Plan B**. B y C son independientes entre sí.

Cada plan se despliega y verifica por separado. No juntar todo en un despliegue.
