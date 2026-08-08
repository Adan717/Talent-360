# Auditoría de Nómina — hallazgos

**Fecha:** 2026-08-06 · **Alcance:** lo que EXISTE hoy, sin modificar nada.
**Estado:** ~~sólo lectura~~ → **Ronda 1 de implementación CERRADA (2026-08-06, mismo día).**
Ver la sección "Ronda 1" al final: N1, N3, N8 y N9 corregidos; N2 en su mitad de pantalla.
Quedan N4/N5 (decisión de producto del jefe — preguntas redactadas), N6, N2-real y N7.

Todo lo de aquí está verificado contra el código y, donde se podía, contra las **8 nóminas reales**
que ya existen en la instancia de pruebas.

---

## El módulo, en una página

| Pieza | Dónde | Tamaño |
|---|---|---|
| Cálculo | `ClockService::calculatePayrollForEmployee` | **424 líneas, un solo método** |
| Proceso nocturno | `CalculateWeeklyPayrollCommand` | semanal, por tenant |
| Firma del colaborador | `EmployeePayrollController::approvePayrollWeekly` | |
| Autorización de la empresa | `PayrollController::approvePayroll` | |
| Timbrado CFDI | `BillingController::timbrarNomina` → `FacturapiBillingProvider` | 335 líneas |
| Pantallas | `FacturacionManager` 810 líneas, `NominaColaborador` 441, `ReportesManager` 410 | |
| Pruebas | 19 archivos rozan nómina · **0 tocan el timbrado** | |

**Flujo real:** el proceso nocturno deja un borrador semanal → el colaborador lo firma de
conformidad → la empresa lo autoriza → alguien pulsa "timbrar".

---

## Hallazgos

### N1 · CRÍTICO — El timbrado finge éxito cuando falla

`BillingController::timbrarNomina`. Si el proveedor fiscal responde con error y el mensaje
contiene la palabra `key`, el sistema **devuelve éxito inventado**:

```php
'message' => 'Nómina timbrada exitosamente (Modo Simulador SAT)',
'uuid'    => 'SAT-CFDI-UUID-' . strtoupper(uniqid()),
```

Tres problemas encadenados:

1. La respuesta de éxito **no se distingue** de un timbre real: mismo `success: true`, mismo
   campo `uuid`. Quien opera cree que timbró.
2. **Ese UUID no se guarda en ninguna tabla.** No hay registro de qué se timbró, ni forma de
   auditarlo después. (Busqué una tabla de recibos fiscales: no existe.)
3. El disparador es `str_contains(strtolower($res['error']), 'key')` — **cualquier** error que
   mencione "key" (una llave duplicada, un campo faltante llamado key) produce un éxito falso.

*Es la familia "la pantalla miente", pero ante el SAT.*

### N2 · CRÍTICO — Las retenciones que se muestran son inventadas

`FacturacionManager.tsx:620-621`, calculado en el navegador:

```
➖ Retención ISR:  base × 0.08
➖ Retención IMSS: base × 0.035
➕ Sueldo Quincenal: base / 2
```

El ISR es una **tarifa progresiva** publicada por el SAT y el IMSS depende del salario base de
cotización y de las cuotas por rama; ninguno es un porcentaje plano. **No existe ninguna tabla de
ISR ni de IMSS en el backend** — lo verifiqué. Son números decorativos en la pantalla desde la
que se timbra.

### N3 · CRÍTICO — La nómina se calcula sobre la semana EN CURSO y cuenta los días futuros como faltas

El proceso nocturno usa `Carbon::now()` y `approvePayrollWeekly` usa `getCurrentWeekRange()`: la
semana **actual**. Los días que todavía no ocurren no tienen asistencia, así que se cuentan como
faltas.

**Evidencia en datos reales** (hoy es jueves 6; la semana va del 3 al 9 de agosto):

| Colaborador | Sueldo | Faltas | Deducciones | Neto |
|---|---|---|---|---|
| Marisol Herrera | 18 000 | 6 | 21 000 | **0.00** |
| Jose Ramirez | 8 500 | 6 | 9 916.67 | **0.00** |
| Adán Cuéllar | 9 000 | 6 | 10 500 | **0.00** |

Y lo que lo vuelve crítico: **el colaborador puede firmar esa nómina a media semana**, y una vez
firmada el proceso nocturno **ya no la toca** ("lo firmado es inmutable para el batch"). Firmar el
miércoles congela un neto de cero.

### N4 · GRAVE — Un mismo retardo se cobra hasta tres veces

Un retardo entra por tres puertas distintas del mismo cálculo:

1. **Por minuto**: `deductionLates = lateMinutes × tarifa × multiplicador del puesto`.
2. **Convertido en falta**: cada 3 retardos = 1 falta (`absencesFromLates`), y cada falta descuenta
   **un día completo** de salario.
3. **Y esa falta baja el séptimo día**: `restDayProportion = (6 − faltas) / 6`, que se descuenta
   aparte.

Nada en el código impide la acumulación: los tres se suman en `totalDeductions`.

### N5 · GRAVE — Los descuentos superan el sueldo, y el propio curso de la plataforma dice que eso es ilegal

`netPay = max(0, gross − deducciones) + bonos`. El `max(0)` evita el número negativo, pero **no
limita el descuento**: tres de las ocho nóminas reales quedaron en **cero pesos**.

El artículo 110 de la LFT limita los descuentos al salario y el 107 **prohíbe las multas**. Y esto
no es interpretación mía: el curso de LFT que la propia Academia le enseña a los colaboradores
tiene esta pregunta, con esta respuesta correcta:

> *"¿Está permitido descontar dinero del salario base del trabajador como multa por llegar tarde?"*
> → **"No, el Artículo 107 prohíbe estrictamente imponer multas al salario del trabajador."**

Mientras tanto, el sistema trae `late_penalty_per_minute = 2.00` **por defecto**: descuenta 2 pesos
por cada minuto de retardo. **El producto hace por defecto lo que su propio curso llama ilegal.**

### N6 · GRAVE — El bruto siempre son 7 días, sin importar el periodo

```php
$grossPay = ($dailySalary * 7) + $holidayBonusPay;
```

Y el proporcional del séptimo día usa `6` fijo. Pero el periodo llega por parámetros
(`start_date`/`end_date`) y las faltas **sí** se cuentan sobre el periodo que se pida. Pedir una
quincena descuenta 15 días de faltas contra el sueldo de 7.

El proceso nocturno **sí** tiene candado (se salta a las empresas que no pagan semanal), pero la
pantalla y el endpoint **no**: ahí el periodo es libre.

### N7 · MEDIO — No existe ningún concepto de ley

Los busqué uno por uno en el servicio y en el controlador: **no hay aguinaldo, vacaciones, prima
vacacional, antigüedad, finiquito, ISR ni IMSS**. Lo que se calcula es sueldo bruto y neto, bono de
cumplimiento, faltas, retardos y deducciones.

`hire_date` ya es obligatoria desde esta semana, pero **nadie la usa todavía** para antigüedad.

*Esto no es un defecto que arreglar: es funcionalidad que no existe. Decidirlo es de producto.*

### N8 · MEDIO — Facturas de ejemplo con nombres y RFC inventados

`BillingController::getInvoices`, sin credenciales, devuelve tres facturas ficticias —"JUAN PEREZ
LOPEZ", RFC `PELJ8001011A0`, 12 500 pesos— marcadas como válidas. Un dueño podría creer que ya
tiene timbres emitidos.

### N9 · MENOR — Código muerto que toca dinero

`ClockService::calculateLatePenalty` (línea 1506) calcula un descuento con **2 pesos por minuto
escrito a mano** y el comentario "Simulación". **No lo llama nadie** — lo verifiqué. Es una
segunda implementación de una regla que en el cálculo real sí es configurable.

### N10 · MENOR — 424 líneas en un solo método para lo que más importa

`calculatePayrollForEmployee` mezcla política laboral, asistencia, comidas, descansos de Ley
Silla, festivos, bonos, rendimiento de tareas y armado de la respuesta. Es el código que decide
cuánto se le paga a una persona y el más difícil de revisar de todo el proyecto.

---

## Lo que SÍ está bien

No todo son hallazgos. Estas cosas están bien resueltas y conviene no romperlas al arreglar lo
anterior:

- **Lo firmado es inmutable** para el proceso nocturno, y firmar dos veces no pisa la fecha de la
  primera firma (se corrigió en H22).
- **Quien autoriza no puede ser el propio colaborador**, y la empresa no puede autorizar algo que
  el colaborador no ha firmado.
- **El día de descanso se normaliza** quitando acentos antes de buscarlo: un "miércoles" con acento
  del formulario de RRHH ya no rompe el cálculo.
- **Justificantes y contingencias** congelan el retardo y no fabrican faltas.
- **Un colaborador sin cuenta no genera faltas**: no se penaliza a quien no puede fichar.
- **La periodicidad del salario** se captura explícitamente y no se adivina con fórmulas.

---

## Orden sugerido para el plan de implementación

No se tocó nada; esto es sólo la propuesta de por dónde empezar cuando se decida.

1. **N3** (semana en curso) y **N1** (timbre falso): los dos que producen daño hoy, con datos
   reales de por medio.
2. **N5** y **N4**: el límite legal a los descuentos y la triple penalización. Requiere decisión
   de producto sobre la política, no sólo código.
3. **N6**: que el bruto respete el periodo.
4. **N2** y **N7**: ISR/IMSS y conceptos de ley — es construir, no arreglar, y probablemente
   necesita asesoría contable.
5. **N8**, **N9**, **N10**: limpieza.

---

## Ronda 1 de implementación (2026-08-06) — N1 + N3 + N8 + N9 + N2-pantalla

Verificada con suite completa (1162/1163, 1 skip preexistente), pruebas nuevas dirigidas
(`PayrollSemanaCerradaTest`, `TimbradoNominaTest` — el timbrado tenía CERO pruebas) y
recorrido en vivo del flujo entero en el entorno local (batch → firma → autorización →
timbrado) con datos reales sembrados.

### N3 — cerrado (la raíz, en el cálculo compartido)

- **Una falta sólo existe en un día que YA TERMINÓ** (zona horaria del tenant). El guard vive
  en `calculatePayrollForEmployee`, así que cubre a TODOS los callers (batch, firma, vistas
  admin y colaborador, exportes, ticket). Un jueves ya no hay 6 faltas ni netos $0.
- **El periodo operativo es la última semana CERRADA**, en todo el flujo:
  el batch nocturno la calcula (recalcula el draft cada noche — absorbe justificantes
  tardíos — hasta que el trabajador firma); la FIRMA del colaborador aplica siempre a esa
  semana (el servidor decide el periodo, el cliente ya no manda fechas); el default del panel
  admin (`getPeriodDates`) apunta ahí; `getPayrollData` ahora responde `{period, employees}`.
- **Murió la doble definición de semana**: `getCurrentWeekRange()` (lunes-domingo fijo) se
  reemplazó por `PayrollWeekService` en los 3 endpoints del colaborador — antes la firma y el
  batch podían crear filas DISTINTAS para la misma semana en tenants con inicio ≠ lunes.
- `days_details[*].day_over` distingue "falta" de "día que aún no ocurre"; las 3 pantallas
  pintaban "Falta Registrada" en días futuros y ya no.
- La pantalla del colaborador separa **vista en vivo** (semana en curso, estimado) de
  **firma** (semana cerrada, con su neto y su desglose de días). El candado de la firma ya no
  exige "todos los días aprobados": una FALTA no tiene botón de firma diaria y bloqueaba la
  firma semanal PARA SIEMPRE — ahora las faltas no bloquean; un día en aclaración sí.
- De pasada: `$baseSalary` quedaba indefinida para expedientes con `salario_diario`
  (`salary.base` = 0 en pantalla), e `is_approved` no incluía `approved_by_admin` (tras
  autorizar la empresa, al colaborador le reaparecía el botón de firmar y recibía 409).

### N1 — cerrado (el timbre ya no miente y queda registrado)

- **La rama del "Modo Simulador SAT" se borró.** Un fallo del proveedor se propaga tal cual
  (502) y no sella nada. El caso exacto del bug (error con la palabra "key") tiene prueba.
- **Sólo se timbra una nómina AUTORIZADA por la empresa** (`approved_by_admin`) y **el monto
  sale de la fila** (`net_pay`), no del cliente — antes `net_salary` venía del navegador y se
  podía timbrar cualquier cifra sobre cualquier periodo.
- **El folio queda sellado en la fila** (migración: `cfdi_uuid`, `cfdi_receipt_id`,
  `timbrada_at` en `weekly_payrolls`) y re-timbrar da 409 con el folio original.
- El RFC/CURP ahora salen del EXPEDIENTE (la pantalla leía usuarios, donde esas columnas no
  existen, y siempre mandaba el genérico). El contrato del endpoint cambió a id de employee.
- El provider ya no inventa `sandbox-uuid-…` cuando Facturapi no trae UUID: null honesto.
- FacturacionManager: fuera las quincenas hardcodeadas de junio/julio y el `net_salary` del
  cliente; las filas salen de `/admin/payroll` (ids de EXPEDIENTE — antes iteraba usuarios y
  los ids no correspondían a las nóminas), sólo lo autorizado es seleccionable, y el error
  real del proveedor se muestra por fila.
- ReportesManager: el modal que decía "¡Nómina Timbrada! Se han generado 3 facturas XML"
  (número inventado, y el endpoint sólo APRUEBA) ahora reporta el resultado real de la
  autorización y aclara que el timbrado es el paso siguiente en Facturación.

### N8 — cerrado. N9 — cerrado. N2 — mitad de pantalla

- N8: `getInvoices` ya no fabrica facturas de "JUAN PEREZ LOPEZ"; el historial dice la verdad
  (vacío o el error del proveedor).
- N9: `calculateLatePenalty` (código muerto con $2/min a mano) borrado.
- N2-pantalla: la columna de ISR 8% / IMSS 3.5% / "sueldo quincenal" inventados se borró; se
  muestra el neto autorizado y las deducciones reales. **N2-real (tablas de ISR/IMSS) sigue
  pendiente y es construcción con asesoría contable, no un arreglo.**

### Datos viejos (patrón conocido: el arreglo deja datos rotos atrás)

Los drafts de la semana en curso con faltas fantasma **se auto-reparan**: al cerrar la semana
el batch los recalcula (updateOrCreate sobre la misma fila). Lo único que NO se auto-repara
es una nómina FIRMADA con neto congelado en $0 — **al desplegar a la V2 hay que revisar**:
`SELECT id, employee_id, start_date, net_pay, status FROM weekly_payrolls WHERE net_pay = 0
AND status != 'draft';` y decidir (borrar la fila o revertirla a draft; es tenant de pruebas,
nadie ha cobrado).

### Pendiente tras esta ronda

- **N4/N5**: decisión de producto del jefe (preguntas redactadas — ver
  `DECISIONES_PENDIENTES_N4_N5.md` en esta carpeta). → **RESUELTO EN RONDA 2 (abajo).**
- **N6**: el bruto sigue siendo `daily × 7` fijo; con la ronda 1 el DEFAULT de todas las
  pantallas es una semana de 7 días, pero un `start_date/end_date` explícito de quincena
  sigue descontando 15 días de faltas contra un bruto de 7. Siguiente ronda.
  → **CANDADO EN RONDA 2 (abajo).**
- **N2-real y N7**: construir (ISR/IMSS, aguinaldo, vacaciones, prima, antigüedad) — con
  contador y decisión de producto.
- **N10**: las 424 líneas (ahora ~440) de `calculatePayrollForEmployee` — refactor de limpieza.

---

## Ronda 2 de implementación (2026-08-07) — N4 + N5 (opción A del jefe) + candado N6

**El jefe respondió el mismo día: opción A, "sin dudar".** Sus palabras: la A "es la única
que podemos defender ante un inspector, ante un colaborador y ante nuestros propios cursos".
Sus cuatro instrucciones, implementadas con tres ajustes de forma (la tabla real es
`lft_settings`, no `company_payroll_settings`; el criterio de datos viejos; y la semántica
por-retardo del cobro único).

### N5 — cerrado: la multa por minuto ya no viene de fábrica

- **Default $0** en los tres lugares donde nacía el 2.00: el default de columna (migración
  `2026_08_07_100000`), el auto-create de `calculatePayrollForEmployee` y el
  `firstOrCreate` de `LftSettingController`.
- **Datos viejos** (el patrón de siempre): la migración baja a $0 SOLO los `2.00` exactos —
  el default recibido en silencio. Un valor capturado a propósito (1.50, 3.00…) se respeta:
  ésa sí fue decisión de la empresa.
- **Activarlo avisa y queda documentado**: el guardado devuelve el aviso del art. 107 y
  sella `late_penalty_set_by` / `late_penalty_set_at` (se limpian al volver a $0). El panel
  LFT ahora TIENE el campo (antes era API-only) con la leyenda legal permanente cuando > 0.
- Con el por-minuto en $0, las deducciones quedan topadas estructuralmente por los días del
  periodo: **el neto $0 sólo es alcanzable por la vía legal** (no trabajar los días).

### N4 — cerrado: un retardo se cobra UNA sola vez

- Los retardos que la acumulación convierte en falta (3→1, reglamento interior) **se
  consumen**: descuentan el día y bajan el séptimo — pero ya NO cobran además por minuto.
  Si una empresa activa el por-minuto, sólo cobra el **residuo** cronológico que no alcanzó
  a formar falta (4 retardos con 3→1: cobran los minutos del 4º, no los 40 totales).
- La falta acumulada conserva el efecto de una falta real (día + séptimo): criterio
  ratificado explícitamente por el jefe.
- `PayrollLftTest` — que documentaba el TRIPLE cobro como comportamiento esperado ($90 de
  minutos encima de la falta) — se actualizó al cobro único con la referencia a esta
  decisión. Test nuevo: `MultaPorMinutoLegalTest` (escenario de aceptación del jefe, cobro
  único con residuo, aviso+sello del art. 107, y el criterio de migración de datos).

### N6 — candado (la quincena ya no se calcula mal; simplemente no se calcula)

- `getPeriodDates` admin: un periodo explícito que no sea de exactamente 7 días → **422**
  con explicación, en vez de descontar 15 días de faltas contra un bruto de 7. Mismo
  criterio que el candado de periodicidad del batch: mejor rechazar y decirlo. El N6
  "real" (bruto que respete periodos arbitrarios) queda absorbido por el trabajo
  calendarizado del ciclo quincenal/mensual (nómina-periodicidad).

---

## Ronda 3 de implementación (2026-08-07) — el ciclo quincenal/mensual (#17)

Aprobada por el jefe el mismo día ("Arranca"), con sus dos reglas: **séptimo día
proporcional por semana natural dentro del periodo**, y **cambio de periodicidad sólo
hacia adelante** (los recibos generados no se tocan). Criterio de cierre que él fijó: el
primer recibo quincenal timbrado con el 04 en la V2.

### Lo construido

- **Servicio de periodos** (`PayrollWeekService::periodRangeFor` / `lastClosedPeriodFor`):
  semanal → semana configurable; quincenal → quincenas NATURALES 1-15 / 16-fin (13 a 16
  días según el mes — febrero probado); mensual → mes calendario. La regla N3 generalizada:
  nunca se opera un periodo en curso.
- **Cálculo period-aware** en `calculatePayrollForEmployee`, con guard `>7 días`: los
  rangos de hasta 7 días conservan la fórmula semanal histórica BYTE A BYTE (cero regresión
  — la familia semanal completa pasó sin tocar un número). Para periodos largos: bruto =
  diario × días reales del periodo; séptimo(s) por semana natural del tenant (misma fórmula
  base 6, evaluada semana por semana; cada descanso DENTRO del periodo se liquida
  proporcional a las faltas de SU semana); la falta acumulada por retardos pertenece a la
  semana del retardo que completó el trío. `incidents.rest_days_in_period` expone cuántos
  descansos liquida el periodo.
- **Batch**: el candado de periodicidad se volvió despachador — cada tenant genera su
  último periodo CERRADO. **Guard de traslape**: un recibo firmado/autorizado de otra
  periodicidad que cubra días del periodo bloquea la generación de ese empleado (sin doble
  pago; es la regla "hacia adelante" del jefe hecha código). ponytail: corre diario
  recalculando el cerrado hasta la firma (absorbe justificantes tardíos); si el costo
  molesta, el upgrade es agendar sólo los días de cierre.
- **Firma y admin**: `closedPeriodRange`/`currentPeriodRange` por periodicidad; el candado
  N6 evolucionó — un rango explícito debe SER un periodo real del tenant (su semana, una
  quincena natural o un mes). CFDI: `working_days` y `salary_rate` con los días REALES del
  recibo (quincena de febrero = 13), código del SAT por periodicidad (02/04/05, ya existía).
- **Pantalla nueva** `NominaSettingsPanel` (Configuración → "Nómina & Periodicidad"):
  elegir semanal/quincenal/mensual (con su código SAT visible), día de inicio de semana y
  día de pago; aviso de "periodicidad no confirmada" (la suposición semanal etiquetada) y
  la regla de cambio hacia adelante explicada. El backend ya lo aceptaba desde 2026-08-03;
  faltaba la pantalla.
- Textos de las 3 pantallas de nómina: "semana" → "periodo" donde aplica; el renglón del
  séptimo muestra N descansos en periodos largos.

### Verificación

- `CicloQuincenalTest` (9 pruebas): quincenas naturales y febrero, último periodo cerrado,
  bruto por días reales con séptimos por semana, falta acumulada a la semana del 3er
  retardo, batch quincenal, firma en la quincena cerrada, candado por periodicidad, guard
  de traslape, y CFDI 04 con 16 días reales y monto server-side. `PeriodicidadNominaTest`
  actualizado: el candado que omitía a los quincenales ahora afirma que reciben SU quincena.
- En vivo (local): pantalla de configuración guardando quincenal confirmada; batch
  reconoce el tenant quincenal, genera la quincena 16-31 jul ($8,000 brutos = 500×16;
  falta del miércoles baja sólo el séptimo de SU semana → neto $7,416.67) y **omite con
  aviso al empleado cuya semana firmada traslapa** — el guard en acción.

### Nota para el cierre del criterio del jefe

El timbre real con 04 en la V2 depende de UNA credencial: `FACTURAPI_KEY` en el `.env` del
servidor (hoy el proveedor rechaza honesto — ya no hay modo simulador que finja el timbre).
El payload que sale lleva 04 + días reales + monto de la nómina autorizada (probado con el
proveedor simulado en tests). En cuanto haya llave, el primer recibo quincenal sale sin
tocar código.
