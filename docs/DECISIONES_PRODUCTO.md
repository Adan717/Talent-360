# Decisiones de producto — bitácora viva

Las decisiones que NO se pueden tomar leyendo el código: las toma el dueño. Aquí quedan con su
fecha, su razón y lo que implican para el trabajo. Si una decisión cambia, se edita aquí, no se
comenta en otro lado.

Última actualización: **2026-08-11**.

---

## ⛔ BLOQUEANTES antes de producción

### B1 — No existe ningún respaldo automático

**Actualización 2026-08-13: el respaldo automático YA EXISTE y la restauración se probó de
verdad** (cron diario en el servidor + copia interina en la máquina de Adán; ver
`docs/RESPALDO_Y_RESTAURACION.md`). **Lo único que sigue pendiente del dueño es el destino
definitivo en la nube** — la copia fuera del servidor depende hoy de que la máquina de Adán
esté encendida.

Hoy el único respaldo es el botón manual del panel (que hasta el 2026-08-11 ni siquiera
funcionaba: reventaba con 500 por la tabla `companies`). Si mañana una empresa pierde datos, o
alguien corre el comando equivocado, **no hay de dónde recuperarlos**.

No bloquea ningún otro trabajo, pero **no se sale a producción con clientes reales sin esto
resuelto**. Se anota aquí para que no se olvide.

---

## ✅ Decididas

### D1 — Neto vivo vs neto firmado: manda el FIRMADO (2026-08-11)

El problema: al calcular la nómina se guarda un `net_pay` —el que se firma y se timbra—, pero la
pantalla lo recalcula al vuelo con los datos de asistencia de ahora. Si algo cambia después de la
firma (se justifica un retardo, se aprueba una eventualidad), los dos números divergen y ambos
parecen ciertos.

**Diseño acordado** (no es "la pantalla lee el guardado"):

1. **`payroll_run` con máquina de estados**: `abierto → calculado → autorizado → timbrado →
   cerrado`. Es el mismo patrón que ya se usa en el ATS.
2. **Snapshot de los INSUMOS, no sólo del resultado**: asistencia, tabulador, políticas y versión
   del motor, congelados al calcular. El cálculo tiene que ser reproducible y determinista.
3. **La pantalla nunca calcula.** Periodo abierto → lee el último cálculo. Periodo cerrado → lee el
   snapshot. El recálculo se degrada de "el número" a un **job de conciliación** que corre aparte y
   levanta alertas.
4. **El candado vive en asistencia**: al editar una fecha dentro de un periodo ya timbrado, el
   sistema **guarda el cambio** (es la verdad operativa) y crea un **`payroll_adjustment`** con
   origen, periodo destino, monto, motivo y autorizador. Quedan dos verdades separadas que ya no se
   pelean: **devengado** (lo que pasó) vs **pagado/timbrado** (el instrumento legal).
5. **Excepción documentada** con umbral de materialidad → cancelación + sustitución del CFDI.

### D2 — Inducción del ATS: es un curso de Academia, post-contratación (2026-08-11) ✅ IMPLEMENTADA 2026-08-13

> Bloque 5 del plan: la contratación cuenta y anuncia los cursos de inducción que le aplican al
> contratado (o avisa si no hay ninguno), con prueba de punta a punta; y el tablero de
> reclutamiento ya dice "Evaluación de Postulación" donde decía "Inducción". La condición 2 (el
> cuestionario de postulación aterrizando en la pantalla de revisión) ya existía: el expediente
> del candidato muestra su calificación.

Había dos sistemas de inducción que no se conocían: Academia 360 (cursos, examen calificado en el
servidor, certificado con folio) y una mini-inducción propia del portal de empleos (video + test de
una pregunta cuya respuesta se tiraba a la basura).

**Decisión: opción (a) — la inducción es de Academia y ocurre YA CONTRATADO**, sin matices, con
tres condiciones:

1. **Arreglar el vocabulario antes de la conversación con el cliente.** Son tres cosas distintas y
   el cliente las va a llamar igual:
   - **evaluación de postulación** — filtro, pre-contratación;
   - **inducción** — onboarding, post-contratación por definición;
   - **verificación de constancia** — acceso a sitio.
   Con esos tres nombres separados, la decisión se toma sola.
2. **El cuestionario de postulación sólo si aterriza en la pantalla de revisión.** Si no llega a
   una pantalla donde RRHH lo lea, no se hace.
3. **La inscripción a Academia se dispara desde la transición de contratación.** Ése es el trabajo
   real que sale de esta decisión, y probablemente el único que vale.

Nota de presentación: **la opción (b) no se ofrece como opción de menú.** Se presenta con precio y
con la nota de que crea un segundo sistema de certificación — una opción sin costo enfrente siempre
parece razonable.

### D3 — Retención del chat: 7 días por defecto, configurable por empresa, tope 30 (2026-08-11)

Hoy son 7 días fijos en el código, sólo sobre `team_chat_messages`, y **ninguna pantalla lo
advierte**.

Al implementar: (a) la pantalla tiene que decir el plazo; (b) hace falta el índice
`(tenant_id, created_at)` en `team_chat_messages` —hoy sólo tiene la llave primaria, así que cada
lectura y cada purga recorren la tabla entera—; (c) decidir si la retención aplica también a
`internal_messages` (mensajes privados y de megáfono), que **hoy no se purgan nunca**.

Por volumen no hay problema de rendimiento: 30 días de chat de una empresa de 50 personas son unos
miles de filas.

**Actualización 2026-08-13 (bloque 2): (a) y (b) HECHOS — con una corrección de fondo: la tabla
que se purgaba (`team_chat_messages`) estaba MUERTA** (nada la lee ni escribe; el chat vivo es
`internal_messages`), así que la retención, el índice y el aviso se construyeron sobre la viva.
Extra de la ronda del consejo: 📌 conserva indefinidamente un mensaje citado en un incidente.
**(c) sigue PENDIENTE del dueño**: los privados y el megáfono hoy NO se purgan nunca — hay que
decidir si la retención les aplica.

### D4 — Cuentas viejas con `password123`: ~~se quedan~~ **REVERTIDA por el consejo (2026-08-13)**

El argumento original ("rotarlas dejaría fuera a quien está trabajando hoy") era un dilema falso:
**forzar el cambio en el siguiente inicio de sesión no deja fuera a nadie**, porque la persona ya
conoce su contraseña actual. Implementado el 2026-08-13 (bloque 1 del plan): la cuenta marcada
entra con su contraseña de siempre y lo único que puede hacer es elegir una nueva. Las **altas
nuevas** ya nacían con contraseña aleatoria (ronda del 2026-08-08/11).

### D5 — Clave de seguridad del Simulador: se quita (2026-08-11) ✅ HECHA 2026-08-13

Era la cadena literal `"Master"` comparada en el navegador. No protegía nada: el control real es el
rol, y el módulo ya está excluido para supervisores y empleados. Quitarla no amplía el acceso, deja
de aparentar que había una cerradura.

### D6 — El Plan IA del Monitor debe poder APLICARSE (2026-08-11) — ~~PENDIENTE~~ **REVERTIDA Y CONGELADA (2026-08-24)**

La propuesta era darle a la IA un endpoint de reasignacion y un boton por sugerencia. **El dueno
decidio que NO**, tras el consejo del 2026-08-24: que una IA reasigne turnos y tareas escribiendo
en la base es demasiado riesgo para esta fase.

**El Plan IA se queda como "Proponer" (solo lectura).** Un humano lee la sugerencia y actua; el
sistema no mueve a nadie por su cuenta.

Congelado en codigo, no solo aqui: `DecisionesCongeladasV2Test` truena si aparece una ruta de
escritura que APLIQUE el plan. Sugerirlo sigue disponible — lo congelado es aplicar, no proponer.

### D8 — Matriz de permisos: sin pantalla en la V2 (2026-08-24)

**No se construye UI.** El endpoint se queda como esta: `role:admin`, indelegable — otorgar
permisos es la llave que se queda con el dueno. La matriz funciona por base de datos y con eso
basta para esta fase. Fijado por prueba: un supervisor recibe 403 en lectura y en escritura.

### D9 — Ley Silla: la bandera roja del Monitor se ABORTA (2026-08-24)

El plan original incluia levantar una bandera en el Monitor cuando alguien pasara del limite sin
descansar. **Abortada**, por dos razones independientes y basta cualquiera de las dos:

1. **Nadie tiene asignado atenderla.** Una alerta que nadie mira no protege a la empresa: prueba
   que sabia y no actuo. Es una metrica autolesiva.
2. **Obligaba a un segundo reloj.** Los minutos de pie se calculan en el navegador; la bandera
   exigia calcularlos tambien en el servidor — dos verdades para el mismo derecho, tres dias
   despues de haber eliminado tres relojes duplicados de la Ley Silla.

**Lo que ya existe se queda tal cual**: el limite se lee de `leySillaConfig` (120 por defecto), el
dial avisa, el contador se reinicia con `break_end`/`meal_end`/`silla_end`, y el servidor NUNCA
bloquea el reposo. Avisa, no vigila.

### D7 — Sí se mandan correos a los interesados en vacantes (2026-08-11)

⚠️ **Antes hace falta una decisión de infraestructura**: el correo está configurado como
`MAIL_MAILER=log`, o sea que **hoy nada sale, todo se escribe en un archivo**. Afecta también al
correo de invitación de RRHH. Falta elegir proveedor (SMTP propio, Resend, SES…) y poner las
credenciales en el servidor.

---

## 🔎 Abiertas

### A1 — Reportes IA: asistente por voz/texto ✅ CONSTRUIDO (2026-08-13)

El dueño entregó la llave de OpenAI el 2026-08-13 y el asistente se construyó al diseño del
bloque 6 del plan (ver ahí el detalle). Por texto; la voz no existe (el navegador podría
dictar al input, pero no se prometió nada). Nómina excluida hasta la fase A del bloque 4.

### A2 — Tolerancia por puesto ✅ CERRADA (2026-08-13)

**Resuelta en el bloque 2.** El hallazgo que cambió el diseño: además del control muerto de la
ficha (`job_roles.tiempoTolerancia`), existía `role_clock_policies.config.tolerancia_retardo_mins`
(matriz §65 de la línea del jefe) que **el dial ya obedecía y el servidor ignoraba** — la mentira
H-RRHH seguía viva por puesto. En vez de cablear una segunda fuente, el servidor ahora juzga con
ésa: puesto (política de la Matriz) > empresa (LFT). El control muerto de la ficha se retiró.
Medido antes de conectar: la tabla estaba vacía en la V2 y todo en 10 — ningún cambio retroactivo.
El texto de abajo queda como historia de la decisión.

#### (histórico)

**Corrección al balance del 2026-08-11:** de los dos controles del editor de puestos, el
**Multiplicador Retardo SÍ está conectado** (`ClockService.php:1836` lo lee y multiplica el
descuento; hoy multiplica $0 porque el cargo por minuto viene en cero, que es lo que manda el art.
107 LFT). El que **sí está muerto** es **"Minutos de Tolerancia" del puesto**: nadie lo lee jamás;
la tolerancia real sale de `lft_settings.late_tolerance_minutes`, que es de toda la empresa.

Conectarlo significa decidir la precedencia: **el puesto manda si está configurado, y si no, el de
la empresa**. Hoy todos los puestos están en 10, igual que la empresa, así que conectarlo **no
cambia ninguna nómina existente** — es el momento bueno para hacerlo.

### A3 — Academia: avisos pendientes, BLOQUEADOS

Faltan el aviso al "encargado de área" a la 2ª reprobada y el recordatorio de inducción pendiente.
**No se pueden construir** porque no existe la figura de "encargado de área" (sólo organigrama) y
`hire_date` es opcional, así que no hay desde cuándo contar.

### A4 — Subida de CV e identificación en el portal de empleos

Función nueva. Cuando se haga: storage privado con nombre uuid + endpoint autenticado, nunca
`public_path()`.

### A5 — Vinculación social real (Google/Apple) en el portal

Trabajo aparte sobre `/login/social`. Lo que había era teatro y ya se quitó.

---

### D10 — Timbrado CFDI y sellos digitales: APAGADOS (2026-08-26)

**El timbrado CFDI nativo queda desactivado**, y con él la carga de sellos digitales (CSD).

**Por qué.** El circuito está construido a medias a propósito: el sistema **no calcula ISR, ni
IMSS, ni subsidio al empleo** —el propio reporte de nómina lo declara— y el payload viaja con RFC
genérico, CURP de relleno, banco y clase de riesgo fijos. Con una llave real eso **no falla:
timbra**, y lo que sale es un documento fiscal presentado ante el SAT a nombre del cliente con
datos falsos. Un CFDI mal emitido no se corrige: se cancela y se explica.

**Y el CSD.** Es el sello con el que se firma ante el SAT a nombre de una empresa — el equivalente
digital de su firma. Con el timbrado apagado, recibirlo sería custodiar la firma fiscal de un
cliente **para no usarla nunca**: puro riesgo sin beneficio.

**Cómo quedó apagado**

- El cortafuegos vive en `FacturapiBillingProvider::createPayrollReceipt` y en `uploadCsd`, que es
  donde se dispara la llamada al PAC: cualquier vía futura choca con él.
- **En el código, NO en el `.env`.** El riesgo concreto era que alguien pusiera una `FACTURAPI_KEY`
  y se encendiera solo; una bandera de entorno la apagaría la misma persona que puso la llave.
- Los endpoints responden **503 explicado**, no 500: un apagado deliberado no debe parecer una
  avería, o alguien va a "arreglarlo".
- La pantalla lo lee del servidor y sustituye el formulario del CSD por la explicación.
- **El código se conserva íntegro** y las 7 pruebas del circuito **no se borraron**: se saltan
  atadas al interruptor, así que vuelven solas el día que se rescate.

**Estado del servidor al apagarlo (2026-08-26):** las 4 empresas sin sello, y cero archivos `.cer`,
`.key`, `.pfx` o `.p12` en el servidor, dentro y fuera del contenedor. No hubo nada que purgar.

**Para rescatarlo** hay que cerrar antes: el cálculo de retenciones (ISR/IMSS/subsidio), los datos
inventados del payload, y la decisión legal de custodiar sellos ajenos con su cláusula de contrato.

---

## ⏳ Esperando algo del dueño

- **Timbrado de nómina**: la `FACTURAPI_KEY` y arreglar la salida TLS del servidor hacia Facturapi.
- **Wizard / catálogo del giro**: la revisión del giro restaurante. Ese número decide si se sigue
  con oficina, retail y taller.
- **`deploy_to_hetzner.py`**: asegurarse de que el jefe **no despliegue con su copia vieja** del
  script — la versión vieja ejecuta `tenant:purge-test-tenants --force`, que borraba toda empresa
  con id > 1.
