# Auditoría de Nómina — hallazgos

**Fecha:** 2026-08-06 · **Alcance:** lo que EXISTE hoy, sin modificar nada.
**Estado:** sólo lectura. Ningún archivo de código fue tocado. La implementación se decide después.

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
