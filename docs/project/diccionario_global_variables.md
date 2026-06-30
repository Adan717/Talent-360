# DICCIONARIO GLOBAL DE VARIABLES Y DATOS

Este documento sirve como "Plano Arquitectónico" para la transición entre los simuladores (Frontend) y la Base de Datos oficial (Backend / PostgreSQL). Define cada propiedad, su tipo de dato SQL recomendado y las reglas de negocio que activa en todo el ecosistema de Talent 360.

---

## Módulo Origen: Reclutamiento y Puestos (RRHH)
**Entidad Principal:** `CollaboratorProfile`
**Uso:** Define los privilegios, horarios y comportamientos de cada empleado en todas las demás aplicaciones (Ej. Reloj Checador, Academia).

### 1. Variables de Identidad
| Variable | Tipo (TS) | Tipo (SQL) | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | `number` | `SERIAL PRIMARY KEY` | Identificador único e inmutable del empleado. |
| `nombre` | `string` | `VARCHAR(150)` | Nombre completo o nombre de pila para visualización en UI. |
| `puesto` | `string` | `VARCHAR(100)` | Cargo oficial. Usado para agrupar roles en listas y reportes. |
| `area` | `string` | `VARCHAR(100)` | Departamento físico (Ej. "Administración", "Cajas", "Piso", "Producción"). |

### 2. Variables de Jornada (Time-Tracking)
| Variable | Tipo (TS) | Tipo (SQL) | Descripción |
| :--- | :--- | :--- | :--- |
| `horaEntrada` | `string` | `TIME` | Formato `HH:mm`. Define cuándo empieza el turno. |
| `horaSalida` | `string` | `TIME` | Formato `HH:mm`. Define cuándo termina el turno. |
| `minutosComida` | `number` | `INTEGER` | Tiempo otorgado para colación (Ej. 30 o 60). |
| `diaDescanso` | `string` | `VARCHAR(20)` | Día base libre a la semana. En este día se bloquean interfaces operativas. |

### 3. Variables de Permisos y Reglas de Negocio (Switches Dinámicos)
Estas variables se administran en el Panel de RRHH y apagan/encienden funciones en el celular del empleado.

| Variable | Tipo (TS) | Tipo (SQL) | ¿Qué hace si es TRUE? |
| :--- | :--- | :--- | :--- |
| `esAperturador` | `boolean` | `BOOLEAN` | Permite oprimir el botón "Abrir Sucursal" por las mañanas. |
| `jerarquiaLlaves` | `number` | `INTEGER` | Rango de Failsafe (1 = Mayor prioridad). Si el 1 no llega, el sistema transfiere el permiso de apertura al 2. (0 o Null si no aplica). |
| `tiempoTolerancia` | `number` | `INTEGER` | Define cuántos minutos de gracia tiene el empleado tras la `horaEntrada` antes de ser marcado como "Retardo". |
| `requiereJustificante` | `boolean` | `BOOLEAN` | Si el empleado incurre en Retardo, la app lo encierra en un Modal que no le deja registrar entrada hasta escribir el motivo. |
| `puedeEmitirAvisos` | `boolean` | `BOOLEAN` | En el `diaDescanso`, habilita un panel para enviar mensajes masivos urgentes a toda la matriz. (Uso Gerencial). |
| `aplicaLeySilla` | `boolean` | `BOOLEAN` | Habilita el módulo para registrar pausas cortas garantizadas por la nueva normativa. (Normalmente solo operativos). |
| `evaluacion360Activa` | `boolean` | `BOOLEAN` | Obliga al empleado a llenar un termómetro de estado de ánimo / feedback antes de poder registrar su Salida del turno. |
