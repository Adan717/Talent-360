# Plan de Visualización y Flujo Cronológico del Dialer (Reloj Checador)

Este documento detalla la estructura lógica, el catálogo visual de botones, la persistencia en base de datos y la secuencia cronológica que sigue el **Botón Dialer** del Reloj Checador, tanto para la versión **Gratuita (Basic)** como para la versión **Profesional (Pro)**.

---

## 1. Secuencia Cronológica de una Jornada Laboral (Flujo de Estados)

El siguiente diagrama de flujo ilustra el recorrido cronológico de un colaborador durante un día de trabajo típico, indicando las transiciones del Dialer:

```mermaid
graph TD
    Start([Inicio del Día]) --> StateRest{¿Es Descanso?}
    
    %% Día de descanso
    StateRest -- Sí --> RestBtn[🌴 DÍA DE DESCANSO] --> EndDay([Fin de Jornada])
    
    %% Inicio de jornada normal
    StateRest -- No --> PreShift{Hora Actual}
    
    %% Reporte Incidencia
    PreShift -- "Antes de Tolerance limit (p. ej. 8:10 AM)" --> IncBtn["⚠️ Reportar Ausencia/Retardo"]
    IncBtn --> |Touch| SaveInc[Persiste Incidencia en DB] --> EndDay
    
    %% Ya estoy aquí
    PreShift -- "08:15 AM - 08:30 AM" --> ProxBtn["📍 Ya llegué (Cercanía)"]
    ProxBtn --> |Touch| SaveProx[Registra Llegada Pasiva]
    
    %% Apertura de Tienda
    SaveProx --> OpenCheck{¿Es Encargado de Apertura?}
    OpenCheck -- Sí (Tienda cerrada) --> OpenBtn["🗝️ Abrir Tienda"]
    OpenCheck -- No (Tienda cerrada) --> WaitBtn["⏳ Esperando Apertura"]
    
    WaitBtn -- "T_start + 15 min" --> ClosedReportBtn["🚨 Notificar Tienda Cerrada"]
    ClosedReportBtn --> |Touch| SendAlertAdmin[Alerta a Admin] --> WaitBtn
    
    %% Entrada oficial
    OpenBtn --> |Touch| CheckInBtn["🟢 Registrar Entrada"]
    WaitBtn --> |Abren Tienda| CheckInBtn
    
    CheckInBtn --> |Touch| ActiveState[Jornada Activa]
    
    %% Jornada Activa
    ActiveState --> MealCheck{¿Comida?}
    
    %% Comida
    MealCheck --> MealStartBtn["🍔 Iniciar Horario de Comida"]
    MealStartBtn --> |Touch| MealEndBtn["🏃 Regresar de Comida"]
    MealEndBtn --> |Touch| PostMealCheck{¿Plan Pro?}
    
    %% Ley Silla (Pro)
    PostMealCheck -- Sí (Pro) --> LeySillaBtn["🧘 Descanso Ley Silla"]
    LeySillaBtn --> |Touch| ReturnBreakBtn["🏃 Regresar de Descanso"]
    ReturnBreakBtn --> |Touch| ActiveState
    PostMealCheck -- No (Free) --> ActiveState
    
    %% Salida Anticipada / Entrega Llaves
    ActiveState --> EndShiftCheck{Hora Fin Turno}
    
    EndShiftCheck -- "Antes de hora de salida" --> EarlyExit["🚪 Salida Anticipada (Botón Secundario)"]
    EarlyExit -- Pro --> QRVal[Escanear QR Supervisor] --> CheckOutBtn["🚪 Registrar Salida"]
    EarlyExit -- Free --> ReasonSelect[Selección de Causa] --> CheckOutBtn
    
    EndShiftCheck -- "Hora de salida normal" --> HandoverCheck{¿Es Encargado?}
    HandoverCheck -- Sí (Pro) --> HandoverBtn["🗝️ Entrega de Turno"]
    HandoverBtn --> |Touch| CheckOutBtn
    HandoverCheck -- No --> CheckOutBtn
    
    CheckOutBtn --> |Touch| FinalState[🏁 Jornada Finalizada] --> EndDay
```

---

## 2. Catálogo Visual de Estados del Dialer

A continuación se muestra cómo se renderiza el botón en cada etapa cronológica de la jornada laboral, detallando su diseño, textos y comportamiento:

| Estado / Evento | Vista Gráfica (Botón del Dialer) | Comportamiento del Touch (Acción) | Datos Guardados en DB | Consecución (Siguiente Botón) |
| :--- | :--- | :--- | :--- | :--- |
| **1. Día de Descanso** | <div style="background-color:#E2E8F0; color:#64748B; border:4px double #CBD5E1; border-radius:50%; width:160px; height:160px; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif; text-align:center; padding:10px;"><span style="font-size:24px;">🌴</span><span style="font-weight:900; font-size:10px; margin-top:5px; text-transform:uppercase;">Día de descanso</span><span style="font-size:9px; color:#94A3B8; margin-top:2px;">No laborable</span></div> | **Deshabilitado**. Bloquea cualquier registro. | Ninguno. | Permanece inactivo todo el día. |
| **2. Reportar Incidencia** (Ausencia/Retardo) | <div style="background-color:#FFF; color:#D97706; border:4px double #F59E0B; border-radius:50%; width:160px; height:160px; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif; text-align:center; padding:10px; box-shadow: 0 0 15px rgba(245,158,11,0.2);"><span style="font-size:24px; animation: pulse 1.5s infinite;">⚠️</span><span style="font-weight:900; font-size:9px; margin-top:5px; text-transform:uppercase;">Ausencia/Retardo</span><span style="font-size:8px; color:#B45309; margin-top:2px; font-weight:bold;">Tolerancia pre-turno</span></div> | Abre formulario para seleccionar causa (ej. Tránsito, Enfermedad) y notificar al supervisor. | Tabla `audit_logs` con tipo `absence_reported` o `delay_reported`. | **Ausencia Registrada** o habilita entrada normal. |
| **3. Cercanía / Ya estoy aquí** (Ya estoy en la zona) | <div style="background-color:#FFF; color:#059669; border:4px double #10B981; border-radius:50%; width:160px; height:160px; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif; text-align:center; padding:10px; box-shadow: 0 0 15px rgba(16,185,129,0.2);"><span style="font-size:24px; animation: bounce 1s infinite;">📍</span><span style="font-weight:900; font-size:9px; margin-top:5px; text-transform:uppercase;">Ya llegué</span><span style="font-size:8px; color:#047857; margin-top:2px; font-weight:bold;">Asegurar puntualidad</span></div> | Registra presencia puntual pasiva en la sucursal antes del inicio físico del turno. | Log local pasivo y tabla `time_entries` tipo `proximity_check`. | **Abrir Tienda** (si es encargado) o **Esperando Apertura**. |
| **4. Esperando Apertura** | <div style="background-color:#F1F5F9; color:#94A3B8; border:4px double #E2E8F0; border-radius:50%; width:160px; height:160px; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif; text-align:center; padding:10px;"><span style="font-size:24px;">⏳</span><span style="font-weight:900; font-size:9px; margin-top:5px; text-transform:uppercase;">Esperando Apertura</span><span style="font-size:8px; color:#64748B; margin-top:2px;">Por encargado</span></div> | **Deshabilitado**. Muestra el nombre de quién tiene las llaves. | Ninguno. | Cambia a **Registrar Entrada** en cuanto el encargado abra. |
| **5. Reportar Tienda Cerrada** | <div style="background-color:#FFF; color:#EA580C; border:4px double #F97316; border-radius:50%; width:160px; height:160px; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif; text-align:center; padding:10px; box-shadow: 0 0 15px rgba(249,115,22,0.25);"><span style="font-size:24px;">🚨</span><span style="font-weight:900; font-size:9px; margin-top:5px; text-transform:uppercase;">Tienda Cerrada</span><span style="font-size:8px; color:#C2410C; margin-top:2px; font-weight:bold;">Notificar al Admin</span></div> | Envía alerta de que no han abierto la sucursal y la jornada ya inició. | Alerta guardada en `internal_messages` y amnistía automática. | **Esperando Apertura** (bloqueado para evitar spam). |
| **6. Abrir Tienda** | <div style="background-color:#FFF; color:#7C3AED; border:4px double #8B5CF6; border-radius:50%; width:160px; height:160px; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif; text-align:center; padding:10px; box-shadow: 0 0 20px rgba(139,92,246,0.3);"><span style="font-size:24px;">🗝️</span><span style="font-weight:900; font-size:9px; margin-top:5px; text-transform:uppercase;">Abrir Tienda</span><span style="font-size:8px; color:#6D28D9; margin-top:2px; font-weight:bold;">Apertura física</span></div> | Abre lista de verificación de apertura de tienda y pase de lista de sucursal. | Cambia estado de sucursal a `open` en `store_logs` y `time_entries`. | **Registrar Entrada** (para comenzar turno). |
| **7. Registrar Entrada** | <div style="background-color:#FFF; color:#059669; border:4px double #10B981; border-radius:50%; width:160px; height:160px; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif; text-align:center; padding:10px;"><span style="font-size:24px;">🟢</span><span style="font-weight:900; font-size:9px; margin-top:5px; text-transform:uppercase;">Registrar Entrada</span><span style="font-size:8px; color:#047857; margin-top:2px; font-weight:bold;">Iniciar turno</span></div> | Registra el inicio oficial de la jornada laboral en el sistema. | Tabla `time_entries` tipo `check_in` con marca de tiempo. | **Iniciar Horario de Comida** (Jornada Activa). |
| **8. Iniciar Comida** | <div style="background-color:#FFF; color:#D97706; border:4px double #F59E0B; border-radius:50%; width:160px; height:160px; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif; text-align:center; padding:10px;"><span style="font-size:24px;">🍔</span><span style="font-weight:900; font-size:9px; margin-top:5px; text-transform:uppercase;">Iniciar Comida</span><span style="font-size:8px; color:#B45309; margin-top:2px; font-weight:bold;">Salida a almuerzo</span></div> | Envía al colaborador a descanso de comida. | Tabla `time_entries` tipo `meal_start`. | **Regresar de Comida** (Estado: Comida). |
| **9. Regresar de Comida** | <div style="background-color:#FFF; color:#059669; border:4px double #10B981; border-radius:50%; width:160px; height:160px; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif; text-align:center; padding:10px;"><span style="font-size:24px;">🏃</span><span style="font-weight:900; font-size:9px; margin-top:5px; text-transform:uppercase;">Regresar de comida</span><span style="font-size:8px; color:#047857; margin-top:2px; font-weight:bold;">Fin de comida</span></div> | Registra el reingreso a labores físicas en piso. | Tabla `time_entries` tipo `meal_end`. | **Descanso Ley Silla** (en Pro) o **Registrar Salida**. |
| **10. Descanso Ley Silla** | <div style="background-color:#FFF; color:#9333EA; border:4px double #A855F7; border-radius:50%; width:160px; height:160px; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif; text-align:center; padding:10px; box-shadow: 0 0 15px rgba(168,85,247,0.25);"><span style="font-size:24px;">🧘</span><span style="font-weight:900; font-size:9px; margin-top:5px; text-transform:uppercase;">Descanso Ley Silla</span><span style="font-size:8px; color:#7E22CE; margin-top:2px; font-weight:bold;">Descanso de pie</span></div> | Habilita el periodo de descanso corto obligatorio por Ley Silla. | Tabla `time_entries` tipo `break_start`. | **Regresar de Descanso** (Estado: Descanso). |
| **11. Regresar de Descanso** | <div style="background-color:#FFF; color:#4F46E5; border:4px double #6366F1; border-radius:50%; width:160px; height:160px; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif; text-align:center; padding:10px;"><span style="font-size:24px;">🏃</span><span style="font-weight:900; font-size:9px; margin-top:5px; text-transform:uppercase;">Regresar de descanso</span><span style="font-size:8px; color:#4338CA; margin-top:2px; font-weight:bold;">Retornar a puesto</span></div> | Registra el fin del descanso y retorno al puesto. | Tabla `time_entries` tipo `break_end`. | **Registrar Salida** / **Entrega de Turno**. |
| **12. Entrega de Turno** | <div style="background-color:#FFF; color:#0891B2; border:4px double #06B6D4; border-radius:50%; width:160px; height:160px; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif; text-align:center; padding:10px; box-shadow: 0 0 15px rgba(6,182,212,0.25);"><span style="font-size:24px;">🗝️</span><span style="font-weight:900; font-size:9px; margin-top:5px; text-transform:uppercase;">Entrega de Turno</span><span style="font-size:8px; color:#0E7490; margin-top:2px; font-weight:bold;">Transferir llaves</span></div> | Abre menú para delegar llaves de sucursal al encargado suplente de mañana. | Tabla `key_transfers` y bitácora de entrega. | **Registrar Salida**. |
| **13. Registrar Salida** | <div style="background-color:#FFF; color:#E11D48; border:4px double #F43F5E; border-radius:50%; width:160px; height:160px; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif; text-align:center; padding:10px; box-shadow: 0 0 15px rgba(244,63,94,0.2);"><span style="font-size:24px;">🚪</span><span style="font-weight:900; font-size:9px; margin-top:5px; text-transform:uppercase;">Registrar Salida</span><span style="font-size:8px; color:#BE123C; margin-top:2px; font-weight:bold;">Terminar jornada</span></div> | Finaliza el turno y cierra labores. Valida tareas pendientes y evaluación de clima. | Tabla `time_entries` tipo `check_out`. | **Jornada Finalizada**. |
| **14. Jornada Finalizada** | <div style="background-color:#F1F5F9; color:#94A3B8; border:4px double #CBD5E1; border-radius:50%; width:160px; height:160px; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:sans-serif; text-align:center; padding:10px;"><span style="font-size:24px;">🏁</span><span style="font-weight:900; font-size:9px; margin-top:5px; text-transform:uppercase;">Jornada Finalizada</span><span style="font-size:8px; color:#64748B; margin-top:2px;">Turno concluido</span></div> | **Deshabilitado**. Bloquea cualquier marcaje adicional. | Ninguno. | Permanece inactivo hasta el próximo turno. |

---

## 3. Ejemplo Práctico de Jornada Laboral Completa (Conexión de Eventos)

A continuación se detalla cómo avanza un colaborador a través de esta secuencia en un día laboral normal de **8:30 AM a 5:30 PM**:

### Paso 1: Amanecer y Trayecto (07:00 AM)
*   **Situación**: El colaborador va de camino a la sucursal.
*   **Estado Dialer**: `⚠️ Reportar Ausencia/Retardo`. Si surge una eventualidad, tiene hasta las **08:10 AM** (20 minutos antes del turno) para notificar desde la app. Si es el encargado, la hora límite se recorre automáticamente a las **07:45 AM** (45 minutos de traslado).

### Paso 2: Llegada a la Geocerca (08:20 AM)
*   **Situación**: Entra en el rango de GPS de la tienda.
*   **Estado Dialer**: Cambia a `📍 Ya estoy aquí`. Al oprimirlo, se registra su presencia pasiva ("espera en puerta") en base de datos.
*   **Efecto**: Si el encargado llega tarde a abrir la tienda física, el colaborador no es penalizado con retardo porque su llegada puntual ya quedó guardada.

### Paso 3: Apertura de Sucursal (08:25 AM)
*   **Situación**: El encargado con llaves llega a la sucursal.
*   **Estado Dialer**:
    *   *Encargado*: Ve el botón `🗝️ Abrir Tienda`. Al presionarlo, completa el stepper de checklists y abre la tienda físicamente.
    *   *Colaborador común*: Muestra `⏳ Esperando Apertura` de forma bloqueada hasta que el encargado confirme la apertura.

### Paso 4: Marcaje de Entrada Oficial (08:30 AM)
*   **Situación**: Tienda abierta y hora de inicio de turno.
*   **Estado Dialer**: Cambia a `🟢 Registrar Entrada`.
*   **Acción**: Al oprimirlo, guarda el check-in oficial en base de datos.

### Paso 5: Almuerzo / Comida (01:00 PM)
*   **Situación**: Es hora de comer.
*   **Estado Dialer**: `🍔 Iniciar Horario de Comida`.
*   **Acción**: Guarda `meal_start` en base de datos y cambia el estado visual a `🏃 Regresar de Comida`. Tras comer, oprime este último para marcar el retorno (`meal_end`).

### Paso 6: Descanso Ley Silla (03:30 PM)
*   **Situación**: El colaborador necesita descansar las piernas (Disponible en Pro).
*   **Estado Dialer**: Muestra `🧘 Descanso Ley Silla`.
*   **Acción**: Al oprimirlo, inicia el descanso de Ley Silla (`break_start`) y luego oprime `🏃 Regresar de Descanso` (`break_end`) para volver al trabajo.

### Paso 7: Cierre y Entrega de Turno (05:25 PM)
*   **Situación**: Se acerca la hora de salida (5:30 PM).
*   **Estado Dialer**:
    *   *Encargado*: Muestra `🗝️ Entrega de Turno` para delegar las llaves de la sucursal a través de la app al responsable del día siguiente.
    *   *Colaborador común*: Cambia directamente a `🚪 Registrar Salida`.

### Paso 8: Salida Oficial (05:30 PM)
*   **Estado Dialer**: `🚪 Registrar Salida`.
*   **Acción**: Al oprimirlo, se ejecuta el check-out oficial y el dialer se apaga mostrando `🏁 Jornada Finalizada`.
