# Nómina: el sueldo no declara su periodicidad y el ciclo sólo sabe pagar por semana

**Fecha:** 2026-08-02
**Naturaleza:** decisión de producto, no corrección puntual.
**Urgencia:** no bloquea el entorno de pruebas. **Sí conviene resolverlo antes de facturarle a un
cliente real**, porque cambia recibos.

---

## Resumen en tres líneas

1. El sueldo se captura sin decir si es semanal, quincenal o mensual.
2. La nómina lo toma **siempre como semanal**; si una empresa captura mensual, calcula **5× de más**.
3. El producto se vende **multi-empresa** y cada empresa paga distinto, así que no existe una
   respuesta única: hace falta que la periodicidad sea configurable.

---

## Lo que se encontró

### a) El mismo campo, tres interpretaciones

`employees.base_salary` no declara periodicidad —el formulario sólo muestra `"Ej. 12000"`— y tres
módulos lo leen de forma distinta:

| Módulo | Qué hace | Lo interpreta como |
|---|---|---|
| **Nómina** (`ClockService:1556`) | `diario = base / 6` | **semanal** |
| **Costo por tarea** (4 sitios) | `base / 480`, con comentario *"Salario Base Diario"* | **diario** |
| **Facturación** (`FacturacionManager:619`) | `Sueldo Quincenal = base / 2` | **mensual** |

### b) No existe configuración de periodicidad

`GET/PUT /company/payroll-settings` sólo permite ajustar tres cosas, y las tres son semanales:
día de inicio de semana, día de pago y hora de cálculo (todas expresadas en días 0–6).

No hay ningún campo donde una empresa declare si paga por semana, quincena o mes.

### c) El ciclo completo es semanal por diseño

No es sólo el campo del sueldo. Es la tabla (`weekly_payrolls`), el comando nocturno
(`payroll:calculate-weekly`) y la fórmula del bruto (`diario × 7`).

**Una empresa que pague quincenal o mensual recibe hoy 4 o 5 recibos semanales al mes.**

### d) El timbrado CFDI va fijo a "quincenal" — riesgo de cumplimiento

`BillingController:216` manda `'periodicity' => '04' // Quincenal` **hardcodeado**, para todas las
empresas, mientras el cálculo es semanal.

Esto no es sólo una inconsistencia técnica. **La periodicidad declarada en un CFDI de nómina debe
corresponder con la forma real en que la empresa paga.** Si una empresa paga por semana y sus
comprobantes se timbran como quincenales, lo que queda ante el SAT es una declaración que no
coincide con la realidad — y el comprobante ya está emitido y sellado, no es algo que se corrija
después con un cambio de código.

Conviene que lo revise quien lleve la parte fiscal antes de timbrar para un cliente real; aquí
sólo se señala el riesgo, no se resuelve.

---

## Los números (4 colaboradores reales del entorno de pruebas)

| Colaborador | Capturado | Bruto semanal **hoy** | Bruto semanal **si el sueldo fuera mensual** |
|---|---|---|---|
| Marisol Herrera | 18 000 | **21 000.00** | 4 200.00 |
| Francisco Vega | 14 000 | **16 333.33** | 3 266.67 |
| Adán Cuéllar | 9 000 | **10 500.00** | 2 100.00 |
| Jose Ramírez | 8 500 | **9 916.67** | 1 983.33 |

**El factor es 5× exacto en los cuatro casos.**

Y lo que la nómina pagó de verdad esta semana:

| Colaborador | Faltas | Deducciones | **Neto pagado** |
|---|---|---|---|
| Marisol Herrera | 4 | 15 000.00 | **6 000.00** |
| Francisco Vega | 5 | 13 611.11 | **2 722.22** |
| Adán Cuéllar | 5 | 8 750.00 | **1 750.00** |
| Jose Ramírez | 5 | 8 263.89 | **1 652.78** |

### Cómo leer estos números

A una gerente con 18 000 capturados, el sistema le calcula **21 000 brutos por semana** — unos
91 000 al mes.

El indicio más claro está en el sueldo más bajo: 8 500 da un diario de **283.33** si se interpreta
como mensual —el orden del salario mínimo vigente— frente a **1 416.67** si se interpreta como
semanal, cinco veces el mínimo para un puesto de apoyo. El primero es un sueldo real; el segundo
no lo es.

Dicho esto: **en un producto multi-empresa esto no se resuelve eligiendo una interpretación.** Una
empresa puede capturar mensual y otra semanal, y ambas tienen razón.

---

## Propuesta

**1. Guardar el salario en DIARIO.**
Al capturar, la interfaz pregunta la periodicidad (mensual / quincenal / semanal) y el backend
convierte y almacena el diario. Todos los módulos consumen esa única unidad.

El salario diario es además la unidad que usa la Ley Federal del Trabajo para IMSS, aguinaldo,
prima vacacional e indemnizaciones. No es una elección de ingeniería: es la unidad del dominio.

**2. Periodicidad de pago por empresa**, en la configuración de nómina, y que el ciclo de cálculo
y el timbrado la respeten en lugar de asumir semanal y timbrar quincenal.

**3. Migración explícita para lo ya capturado.** No se puede inferir la periodicidad mirando el
número: hay que registrar con cuál se capturó cada sueldo existente.

### Cómo se migra sin depender de la memoria de nadie

Preguntarle a cada dueño con qué periodicidad capturó cada sueldo no escala: con 50 empresas de
200 colaboradores serían 10 000 preguntas. La estrategia depende de en qué momento se haga:

**Durante el piloto (pocas empresas) — confirmación obligatoria.**
Al migrar, cada empresa queda marcada como "periodicidad sin confirmar", y **el administrador
tiene que confirmarla antes de poder generar su siguiente nómina**. Son pocos clientes, la
respuesta la da quien sí la sabe, y el sistema no calcula nada con una suposición.

**Para el despliegue masivo — etiquetar y permitir corrección.**
Se etiqueta todo lo histórico con la periodicidad que el sistema venía asumiendo de facto
(semanal), que es lo único cierto que se sabe de esos datos, y cada empresa puede corregirla caso
por caso. Evita bloquear a cientos de clientes por un dato que la mayoría no va a revisar.

Lo que **no** debe hacerse en ninguno de los dos casos es adivinar la periodicidad a partir del
importe.

---

## Qué hace falta antes de tocar la fórmula

Un **informe de impacto**, no un test de los que corren en integración continua.

En concreto: un comando que tome los recibos ya emitidos, los recalcule con la fórmula nueva y
**exporte una tabla comparativa** —colaborador, periodo, importe anterior, importe nuevo,
diferencia—. Eso no lo revisa un desarrollador: lo revisa quien lleve la nómina, y su visto bueno
es lo que autoriza la migración.

La suite de pruebas sigue haciendo falta, pero no basta: si el cálculo actual está mal, un test
que exija *"que dé lo mismo que antes"* estaría fijando el error.

---

## Nota: el costo por tarea

`task_cost` está inflado **6×** por la misma raíz: divide el sueldo completo entre los minutos de
un solo día, cuando el comentario del propio código dice que debe dividir el **diario**.

**No ha pagado nada mal:** se verificó que ningún cálculo de nómina lo consume. Pero es un campo
que miente en silencio, y el día que se conecte a un tablero de costos arrastrará datos falsos
desde el origen. Conviene marcarlo como no confiable —o dejar de escribirlo— hasta recalcularlo
con la unidad correcta.

---

## Historial de este documento

Nació como *"URGENTE: base_salary significa tres cosas distintas"*, planteando la pregunta
*"¿el sueldo es mensual o semanal?"*.

Esa pregunta estaba mal planteada: **el producto es multi-empresa y cada cliente paga con una
periodicidad distinta**. Con ese dato, el problema dejó de ser un campo ambiguo que hay que
definir y pasó a ser una capacidad que falta —periodicidad configurable— sobre un ciclo que hoy
sólo sabe operar por semana. La urgencia bajó (el entorno es de pruebas); el alcance subió.
