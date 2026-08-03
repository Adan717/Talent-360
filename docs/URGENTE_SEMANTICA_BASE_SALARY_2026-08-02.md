# ⚠️ Urgente: el sueldo capturado significa tres cosas distintas según el módulo

**Fecha:** 2026-08-02
**Para:** decisión del responsable de producto, antes de tocar ninguna fórmula.
**Por qué urge:** es lo único encontrado que puede estar pagando mal **hoy**.

---

## El hallazgo

El campo donde se captura el sueldo (`employees.base_salary`) **no declara su periodicidad** —el
formulario sólo dice `"Ej. 12000"`— y tres módulos del producto lo interpretan de forma distinta:

| Módulo | Qué hace | Lo interpreta como |
|---|---|---|
| **Nómina** (`ClockService:1556`) | `diario = base / 6` | **semanal** |
| **Costo por tarea** (4 sitios) | `base / 480`, con comentario *"Salario Base Diario"* | **diario** |
| **Facturación** (`FacturacionManager:619`) | `Sueldo Quincenal = base / 2` | **mensual** |

Los tres no pueden ser correctos a la vez.

---

## Los números reales (tenant de pruebas, 4 colaboradores)

| Colaborador | Capturado | Diario **hoy** | Bruto semanal **hoy** | Diario **si fuera mensual** | Bruto semanal **si fuera mensual** |
|---|---|---|---|---|---|
| Marisol Herrera | 18 000 | 3 000.00 | **21 000.00** | 600.00 | 4 200.00 |
| Francisco Vega | 14 000 | 2 333.33 | **16 333.33** | 466.67 | 3 266.67 |
| Adán Cuéllar | 9 000 | 1 500.00 | **10 500.00** | 300.00 | 2 100.00 |
| Jose Ramírez | 8 500 | 1 416.67 | **9 916.67** | 283.33 | 1 983.33 |

**El factor es 5× exacto en los cuatro casos.**

Y esto es lo que la nómina pagó de verdad esta semana:

| Colaborador | Faltas | Deducciones | **Neto pagado** |
|---|---|---|---|
| Marisol Herrera | 4 | 15 000.00 | **6 000.00** |
| Francisco Vega | 5 | 13 611.11 | **2 722.22** |
| Adán Cuéllar | 5 | 8 750.00 | **1 750.00** |
| Jose Ramírez | 5 | 8 263.89 | **1 652.78** |

---

## La pregunta que decide todo

**¿18 000 es el sueldo mensual de Marisol, o el semanal?**

Si es **mensual** —lo habitual en México para una gerente de sucursal— entonces hoy el sistema le
está calculando **21 000 pesos brutos por semana**, unos 91 000 al mes. Cinco veces de más.

Hay un indicio que apunta con fuerza en esa dirección: el colaborador de menor sueldo (8 500)
daría un **diario de 283.33** si se interpreta como mensual, que es exactamente el orden del
salario mínimo vigente. Interpretado como semanal daría **1 416.67 diarios** — cinco veces el
mínimo, para un puesto de apoyo. El primero es un sueldo real; el segundo no lo es.

---

## Por qué no se corrige sin decidir esto primero

Cambiar la fórmula recalcula la nómina **de todos, hacia atrás**. Si ya se emitieron recibos con
la interpretación actual, aparecen diferencias en documentos ya entregados.

Esto no es una decisión técnica: toca recibos de nómina, percepción del cliente y posiblemente
obligaciones fiscales.

---

## Propuesta: normalizar a salario DIARIO

De las opciones evaluadas, la que elimina la ambigüedad en el origen:

1. **Al capturar**, la interfaz pregunta explícitamente: *"¿Cuánto gana al mes?"* (y ofrece
   semanal/quincenal si hace falta).
2. **El backend almacena siempre el valor diario**, convirtiendo según lo que se eligió.
3. **Todos los módulos consumen el diario.** Cero interpretación, cero ambigüedad.

Ventaja adicional: el **salario diario** es la unidad que usa la propia Ley Federal del Trabajo
—para IMSS, aguinaldo, prima vacacional e indemnizaciones—. Es la unidad natural del dominio, no
una elección arbitraria.

**Para los datos ya capturados** hace falta una migración explícita que registre con qué
periodicidad se capturó cada uno. No se puede inferir mirando el número.

---

## Lo que hace falta antes de tocar la fórmula

Un **test de regresión financiera**: para un colaborador con historial, verificar exactamente qué
recibos cambian y en cuánto. No basta con que "los tests pasen" — hay que poder responder
*"estos N recibos cambian, por estas cantidades"* antes de aplicar nada.

---

## Nota sobre el costo por tarea

`task_cost` está inflado **6×** respecto a la semántica de la propia nómina (divide el sueldo
completo entre los minutos de un solo día, cuando el comentario del código dice que debe dividir
el **diario**).

**No ha pagado nada mal**: se verificó que ningún cálculo de nómina lo consume. Pero es un campo
que hoy **miente en silencio**, y el día que alguien lo conecte a un tablero de costos arrastrará
datos falsos desde el origen. Recomendación: marcarlo como no confiable hasta recalcularlo, o
dejar de escribirlo mientras tanto.
