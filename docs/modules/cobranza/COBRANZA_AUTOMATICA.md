# Cobranza automática — que dejar de pagar tenga consecuencia

**Fecha:** 2026-09-05 · **Renglón:** que dejar de pagar tenga consecuencia automática.

---

## 1. Lo que había antes (verificado en el código, no supuesto)

Las dos afirmaciones del encargo son ciertas, y hay una tercera:

1. **Pasar de "no pagó" a "suspendida" era 100% manual.** El único código que apaga una empresa
   es `PlatformAdminController::toggleTenantStatus`, detrás de
   `POST /platform/tenants/{id}/toggle-status`, que exige un `is_active` en la petición. Es el
   interruptor del panel de plataforma: alguien entra y lo aprieta. No existía ninguna tarea
   agendada, job ni webhook que lo hiciera solo.

2. **El estado "pago vencido" (`past_due`) no lo escribía NADIE.** En todo el backend la cadena
   `past_due` aparecía exactamente una vez: en el comentario de la migración
   `2026_06_24_125522_add_subscription_fields_to_tenants_table.php`
   (`// trial, active, past_due, cancelled`). El único otro lugar del repo era una rama de color
   ámbar en `SaaSPlatformAdmin.tsx`. Era decoración de una situación que no podía ocurrir.

3. **(Confirmado también) el vencimiento del periodo de prueba tampoco hace nada.** Las tres
   empresas con `trial_ends_at` ya vencido están en `active` y en plan `enterprise`;
   `TenantModuleMiddleware` deja pasar a `enterprise` antes de mirar la prueba, y
   `Tenant::isTrialActive()` devuelve `false` en cuanto el estado es `active`. Ninguna ruta
   escribe un estado nuevo cuando la prueba caduca.

**Un cuarto hallazgo, colateral:** el interruptor manual apagaba y encendía empresas enteras
**sin dejar ningún rastro** — ni quién, ni cuándo, ni por qué. Ya escribe en `saas_audit_logs`
(evento `cobranza_interruptor_manual`), igual que el barrido automático, para que las dos vías
se lean juntas en `/platform/security-logs`.

---

## 2. Las reglas, en un solo sitio

`Backend/app/Support/EstadoDeCobranza.php`. Ahí viven los cuatro estados y el orden exacto en
que se evalúan las transiciones. **El orden es la seguridad:**

| # | Condición | Decisión | ¿Escribe? |
|---|-----------|----------|-----------|
| 1 | `id === 1` o `subdomain === 'talent360'` | `INTOCABLE` | nunca |
| 2 | `billing_exempt = true` | `EXENTA` | nunca |
| 3 | `is_active = false` | `YA_SUSPENDIDA` | nunca (ni re-suspende ni reactiva) |
| 4 | `current_period_end` vacío o ilegible | `SIN_FECHA_DE_CORTE` | nunca |
| 5 | hoy ≤ fecha de corte | `AL_CORRIENTE` | `past_due` → `active` si venía en mora |
| 6 | días de mora > días de gracia | `SUSPENDER` | `past_due` + `is_active=false` |
| 7 | días de mora ≥ día de aviso | `AVISAR` | `past_due` + marca de aviso (1 vez por ciclo) |
| 8 | resto | `EN_GRACIA` | `past_due` |

**El apagón no lo decide `subscription_status`, lo decide `is_active`** — es lo que mira
`CheckTenantActive`. Por eso la suspensión automática deja el estado en `past_due` y **no** en
`cancelled`: la deuda sigue viva, no es una baja. (El interruptor manual sí escribe `cancelled`;
se dejó como estaba para no cambiar su comportamiento de paso. Ver §7.)

### Los números

- **Gracia: 5 días.** No son 7. El encargo asumía 7, pero los Términos y Condiciones que el
  cliente ya acepta (`Frontend/src/components/LegalModal.tsx`, punto 3) prometen textualmente
  *"un periodo de gracia de 5 días naturales"*. Poner 7 en el código habría dejado a la pantalla
  y al backend diciendo cosas distintas sobre el mismo trato. El día 5 **todavía es gracia**: se
  suspende al pasarla (día 6), que es cuando el plazo del contrato transcurrió.
- **Aviso: al tercer día** de mora, una sola vez por ciclo de cobro.
- Ambos son configurables en `system_settings` global (`tenant_id` NULL) con las llaves
  `billing_grace_days` y `billing_warning_day`, pero el default vive en la clase y en el
  contrato, no en dos lados sueltos.

La cuenta se hace en la zona horaria de la aplicación, **no** en la del tenant: la fecha de corte
es un hecho de calendario del cobro (la escribe la pasarela de pago), no un turno de trabajo.

---

## 3. Los comandos

### `suscripciones:revisar-vencidas`

```
php artisan suscripciones:revisar-vencidas                              # SIMULACRO (default)
php artisan suscripciones:revisar-vencidas --aplicar --sin-suspender    # lo que corre agendado
php artisan suscripciones:revisar-vencidas --aplicar                    # incluye el apagón
php artisan suscripciones:revisar-vencidas --dias-de-gracia=15          # "¿qué pasaría si...?"
```

Simulacro por defecto, como todo comando que escribe en este repo. Imprime cada empresa
agrupada por decisión, con el porqué.

### `suscripciones:exentar` — la vía de exención

```
php artisan suscripciones:exentar 4 --motivo="Piloto sin cobro" --aplicar
php artisan suscripciones:exentar 4 --quitar --aplicar
```

Marca a una empresa como cortesía / piloto / socio: el barrido deja de mirarla pase lo que pase
con su fecha de corte. Exige `--motivo` (una exención que nadie puede explicar en seis meses no
sirve), simula por defecto y deja rastro en la bitácora. El panel muestra la exención y su motivo
en el detalle de la empresa.

**Por qué NO se marcó exentas a las 4 empresas actuales.** Decir "a este cliente no se le cobra"
es una decisión comercial del dueño, y escribirla en producción desde una migración sería
inventar un trato que nadie acordó. Además **no hace falta para su seguridad**: ninguna de las 4
tiene `current_period_end`, y sin fecha de corte el barrido no las toca nunca (regla 4, con
prueba candado). La exención queda lista y documentada para cuando empiecen a tener fecha de
corte y el dueño decida a quién no se le cobra.

---

## 4. Qué corre solo, y qué no

Agendado en `Backend/bootstrap/app.php`, diario a las 06:00:

```php
$schedule->command('suscripciones:revisar-vencidas --aplicar --sin-suspender')
    ->dailyAt('06:00')->withoutOverlapping();
```

**Corre `--aplicar --sin-suspender`, no el simulacro pelado y no el modo completo.** Las tres
opciones se consideraron:

- *Simulacro agendado*: no escribe nada, así que no sirve de nada. Sería código muerto —
  exactamente lo que le pasó a `shifts:close-orphans`, que estuvo meses sin agendar y su alerta
  antifraude nunca se disparó.
- *Modo completo agendado*: suspender deja a **una empresa entera sin reloj checador**. Encender
  eso solo, contra 4 clientes reales que fichan asistencia todos los días, es de las cosas que no
  se prenden sin que el dueño lo sepa.
- *`--sin-suspender`* (elegido): aplica todo lo que **no apaga a nadie** —marcar la mora, avisar
  dentro de la gracia, y escalar en la bitácora a quien la agotó con un `🔴 LISTA PARA
  SUSPENDER`— y deja el apagón como acto humano deliberado. Es "nada bloquea, todo avisa"
  aplicado a la cobranza.

Para suspender de verdad: `--aplicar` sin `--sin-suspender`, o el interruptor del panel. Las dos
vías dejan el mismo rastro.

**El aviso no se manda por correo.** El proveedor de correo sigue bloqueado por decisión del
dueño (bloque 3 del plan de trabajo). El aviso queda en `saas_audit_logs`, que es lo que el
backend sí respalda hoy; cuando haya proveedor, el enganche va en
`RevisarSuscripcionesVencidas::aplicar()` y no antes. La pantalla no promete un correo que no
sale.

---

## 5. Lo que la pantalla decía y ya no dice

`SaaSPlatformAdmin.tsx` inventaba el estado de cobranza en el navegador:

- Calculaba **"⚠️ Prueba Expirada"** con la sola presencia de `trial_ends_at`, y ponía ese
  cálculo **antes** de mirar `subscription_status`. Resultado real hoy: las 3 empresas vivas que
  están en `active` con un `trial_ends_at` viejo del alta (2026-08-12, 2026-08-13, 2026-09-03) se
  veían como **pruebas expiradas** en vez de "✓ Suscrito". Además arrastraba la insignia
  **"📢 Publicidad Pendiente"** (que es cosa del plan freemium) a empresas enterprise al
  corriente.
- Tenía color ámbar para `past_due`, un estado que nunca ocurría.
- Mostraba la clave cruda en inglés (`active`, `trial`) como "Estado de Facturación".
- **Callaba** que no había fecha de corte: la fila "Próximo Cobro / Fin Ciclo" simplemente
  desaparecía, que es justo el dato que explica por qué la cobranza no revisa a nadie.

Ahora hay una sola función, `estadoDeCobranza()`, que lee el estado del backend; `trial_ends_at`
sólo se consulta cuando la empresa está de verdad en prueba; el ámbar de `past_due` por fin
significa algo porque el barrido lo escribe; y cuando no hay fecha de corte la pantalla lo dice:
**"Sin fecha de corte: la cobranza automática no la revisa"**.

---

## 6. Pruebas

`Backend/tests/Feature/CobranzaAutomaticaTest.php` — 15 pruebas, 81 aserciones, verdes.

La **prueba candado** es `test_sin_fecha_de_corte_nunca_se_suspende`: reproduce las 4 empresas
vivas de la V2 (ninguna con `current_period_end`, tres con `trial_ends_at` vencido y estado
`active`), corre el barrido **con todo el poder** y exige que ninguna quede suspendida, que
ningún estado cambie y que no se escriba una sola línea de bitácora. Si alguien hace que el
barrido deduzca mora de la falta de dato —o del periodo de prueba vencido— esa prueba se pone
roja antes de que apague el reloj checador de tres clientes reales.

Las demás cubren: gracia (dentro, en el último día, y pasada), exenta, inquilino principal por
las dos vías del guardarraíl, simulacro que no escribe, idempotencia de la suspensión y del
aviso, el modo agendado que escala una sola vez sin apagar, el pago que saca de la mora, la
empresa suspendida que **no** se reactiva sola, la gracia configurable, y que la tarea aparece en
`schedule:list` (sin eso, todo lo anterior es código muerto).

---

## 7. Lo que NO se construyó, y por qué

### Modo lectura para la empresa suspendida — **espera una decisión del dueño**

**La suspensión de hoy sigue siendo el apagón que ya existía**, sin cambios: `CheckTenantActive`
responde 403 a toda la API y el login falla (probado en `TenantSuspensionTest`). Una empresa
suspendida **no puede fichar asistencia ni consultar su registro**.

No se construyó el modo lectura —que la empresa suspendida siga viendo o registrando su
asistencia— porque **depende de una decisión abierta del dueño: si el reloj checador sigue
registrando asistencia mientras la empresa no paga.** No es una decisión técnica. De un lado, el
registro de asistencia es la base de la nómina y de obligaciones laborales que no se suspenden
porque un proveedor no cobró; del otro, dejar el producto funcionando quita toda presión de pago.
Hasta que se decida, la suspensión apaga, y por eso el barrido agendado **no suspende solo**.

### El estado que escribe el interruptor manual

El interruptor manual sigue escribiendo `cancelled` al suspender (y `active` al reactivar),
mientras el barrido automático escribe `past_due`. Son dos verdades para el mismo hecho: una
suspensión por falta de pago no es una baja. **No se cambió** porque tocar el comportamiento del
interruptor no era el encargo y el panel se apoya en esos valores en varios lugares. Queda
anotado como decisión chica para el dueño: unificar el manual a `past_due` cuando el motivo sea
falta de pago.

---

## 8. Archivos

- `Backend/app/Support/EstadoDeCobranza.php` — estados y transiciones, un solo sitio.
- `Backend/app/Console/Commands/RevisarSuscripcionesVencidas.php` — el barrido.
- `Backend/app/Console/Commands/ExentarDeCobro.php` — la vía de exención.
- `Backend/database/migrations/2026_09_05_100000_add_billing_dunning_fields_to_tenants_table.php`
- `Backend/bootstrap/app.php` — la agenda.
- `Backend/app/Http/Controllers/PlatformAdminController.php` — bitácora del interruptor manual y
  campos de cobranza en la API del panel.
- `Frontend/src/components/SaaSPlatformAdmin.tsx` — `estadoDeCobranza()`.
- `Backend/tests/Feature/CobranzaAutomaticaTest.php`
