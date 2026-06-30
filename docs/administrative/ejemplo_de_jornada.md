# Ejemplo de Jornada y Sincronización Operativa de Apertura y Cierre

Este documento define el flujo integral de apertura, operación y cierre de la sucursal, integrando control de asistencia, rutinas, bolsa de trabajo y módulos de descanso.

## 1. Ejemplo de Sinergia Laboral (Paso a Paso)

Imaginemos un día normal de operación con apertura a las **09:00 AM** y cierre a las **05:00 PM**.

### Fase 1: Llegada y Apertura Temprana (08:50 AM)
- **Llegada:** Francisco (Encargado) llega. Su app lo autodetecta por GPS (o presiona manual). Su primera tarea obligatoria: Abrir la puerta principal.
- **Ingreso General:** Los empleados (ej. Ana) que ya estaban afuera (y que presionaron "Ya llegué" para guardar su Timestamp cronológico) ingresan con él.
- **Preparación (10 min antes):**
  - *Francisco:* Inicia Rutina de Apertura (prender luces, caja principal, servidor).
  - *Empleados:* Suben a cocina a guardar comida, pertenencias, etc. Bajan listos.

### Fase 2: Pase de Lista y Arranque de Operaciones (09:00 AM)
- **Pase de Lista:** Los empleados se reportan con Francisco. Él ejecuta el **Checklist de Uniforme y Estado** en su app.
- **Fichaje de Entrada:** Al ser aprobados en el pase de lista, se registra el fichaje oficial de todos.
- **Bolsa de Trabajo Inicial:** Se liberan las tareas de apertura física en la Bolsa (levantar cortinas, sacar rampa). Todos apoyan.
- **Arranque de Rutinas:** Terminada la apertura, se detonan las rutinas individuales por puesto. Se inyectan también las tareas pendientes del día anterior, validadas por el supervisor.
- **Elección de Comida:** Se habilita la selección de horario de comida. **Regla de oro:** El orden de elección es estrictamente cronológico, basado en quién presionó "Ya llegué" primero.

### Fase 3: Jornada, Descansos y Contingencias
- **Descanso Activo (Mini-módulo):** Si pasan más de 2 horas desde la apertura y antes de comer, Ana puede solicitar sus 5 minutos de descanso. Para ello, toma de la bolsa una tarea etiquetada como **"Tarea Sentado"** (ej. armar moños, revisar facturas). Lo mismo aplica post-comida.
- **Contingencia por Abandono:** Si alguien se retira, sus tareas regresan a la evaluación del supervisor, quien decide: ¿Van a la Bolsa de Trabajo general o se le guardan como "Pendiente" (afectando su productividad para el día siguiente)?

### Fase 4: Protocolo de Cierre (04:50 PM - 05:00 PM)
- **Alerta de Cierre:** 10 minutos antes, suena la alerta.
- **Cierre de Cortina:** Se bajan cortinas. Las tareas generales caen a la Bolsa para que todos apoyen.
- **Rutina de Limpieza Interna:** A puerta cerrada, se activan rutinas de rellenado, basura, baños, limpieza de pasillos y chequeo de inmueble.
- **Alerta de Retirada:** 10 minutos previos a la salida, suena alerta de fin de turno.
- **Filtro de Salida:** Si alguien tiene tareas pendientes, el sistema le bloquea la salida. Debe ir con el supervisor para justificarlas.
- **Pase de Lista de Salida:** Francisco verifica que nadie haya huido. Selecciona a sus escoltas de cierre.
- **Cierre Físico:** Francisco pone candados. Al finalizar, se genera y envía la **Bitácora de Cierre**.

---

## 2. Hallazgos y Análisis de Productividad

1. **El valor del Timestamp "Ya llegué":** Esta función no solo mide la puntualidad real (antes de entrar), sino que ahora es la "moneda de cambio" para elegir los mejores horarios de comida. Es un gran incentivo de gamificación para llegar temprano.
2. **Descansos Productivos:** El concepto de "Tareas Sentado" transforma el tiempo muerto o de fatiga física en productividad administrativa o manual ligera.
3. **El Cierre como "Trabajo en Equipo":** Depositar las tareas de cierre en la Bolsa de Trabajo acelera el proceso al permitir que los empleados que terminaron sus rutinas apoyen a los demás.
4. **Justificación de Pendientes:** Bloquear la salida si hay pendientes evita la procrastinación crónica y transfiere la responsabilidad de reasignar al supervisor.

---

## 3. Módulos Estratégicos (Para Implementación Técnica)

- **Módulo de Pase de Lista Dual:** Vista exclusiva para el Encargado (Apertura y Cierre).
- **Elección Cronológica de Comida:** Interfaz de desbloqueo secuencial.
- **Módulo de Descansos Activos:** Temporizador de 5 minutos atado a tareas sentados.
- **Filtro de Salida (Auditoría):** Bloqueo en el Reloj Checador si hay tareas pendientes.
- **Bitácora de Cierre Automática:** Compilador de desempeño y tareas enviadas al Administrador.
