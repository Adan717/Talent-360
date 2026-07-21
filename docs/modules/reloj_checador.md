# Módulo: Reloj Checador (Marcaciones, Comedor y Control de Sucursal)

El módulo de **Reloj Checador** es la puerta de entrada operativa de cada sucursal. Permite gestionar de manera inteligente la apertura del local, el registro de asistencia del personal mediante geolocalización (GPS) y la coordinación del comedor.

---

## 1. Archivos Clave del Módulo
- **Componente Visual**: [RelojVisual.tsx](file:///c:/Users/Servidor/Desktop/Talent360/Frontend/src/components/reloj/RelojVisual.tsx) (Dial circular principal de marcación, estados del botón, barra de progreso y modals de comedor).
- **Lógica de Estado (Engine)**: [useClockEngine.tsx](file:///c:/Users/Servidor/Desktop/Talent360/Frontend/src/components/reloj/useClockEngine.tsx) (React Context, marcas de asistencia, geofencing, lógica de llaves de apertura y coordinación de comedores).

---

## 2. Funcionalidades Detalladas

### A. Botón Central Contextual e Inteligente (Smart Dial)
El dial de marcación principal cambia de aspecto y función adaptándose a la hora, al puesto del empleado, a su ubicación GPS (`isWithinPerimeter`) y al estado de la sucursal:
- **Tienda Cerrada / Fuera de Horario**: Botón gris translúcido con candado (`🔒`).
- **Apertura Disponible (Dentro de GPS)**: Botón morado/lila brillante con tienda (`🏪`) (exclusivo para supervisores con llaves).
- **Espera de Encargado**: Botón gris deshabilitado con reloj (`⏳`) para empleados regulares.
- **Fichaje de Entrada (Dentro de GPS)**: Botón verde esmeralda con huella dactilar (`👋`) para marcar ingreso una vez abierta la sucursal.
- **Incidencia / Fuera de Perímetro**: Si el empleado está fuera de rango GPS (`!isWithinPerimeter`) durante su ventana de entrada, el dial se tiñe de **naranja/ámbar** y cambia a un **icono de alerta (`⚠️`)** con la etiqueta *"Reportar Incidencia"*.

### B. Control del Comedor y Aforos (Utensils)
- Representado por el icono amarillo de cubiertos (`Utensils`) en la barra de progreso.
- **Reservación de Bloques**: Cuadrícula de reserva para apartar franjas de almuerzo (ej: 02:00 PM - 03:00 PM) con un límite de aforo parametrizable.
- **Validación Anti-Conflictos**: Impide que empleados clave del mismo puesto coincidan en la comida, garantizando que el piso de ventas nunca se quede vacío.

### C. Intercambio de Comida Jerárquico (Swaps)
- Permite a los empleados ceder o intercambiar sus turnos de almuerzo.
- **Restricción Jerárquica**: Los swaps se limitan estrictamente a compañeros con el **mismo nivel de puesto / rol de trabajo** (coincidencia de `job_role_id`). Un supervisor solo puede intercambiar con otro supervisor; un asesor de ventas con un asesor, etc.

### D. Pase de Lista
- Modal que se le presenta al encargado tras abrir la sucursal para auditar rápidamente quiénes se encuentran laborando en el turno actual.

### E. Distintivos de Llaves Físicas
- Visualización de indicadores de llaves en el checador y paneles de RH:
  - **Encargado Principal**: Un icono de llave (`🔑`).
  - **Encargado Suplente**: Dos iconos de llaves (`🔑🔑`).

### F. Reportes de Ausencia / Retardo Adaptativos
Cuando se oprime el botón central de alerta (`⚠️`), la secuencia y disponibilidad varía según el puesto y el horario:
- **Ventana de Incidencia Anticipada (07:00 AM a Hora Límite)**:
  - **Supervisor/Responsable de Apertura**: El botón aparece a partir de las 07:00 AM y se mantiene hasta 1 hora antes de la apertura oficial (ej. si la apertura es a las 08:30 AM, el límite es a las 07:30 AM). Esto garantiza un margen mínimo de 60 minutos para alertar y delegar responsabilidades al suplente.
  - **Colaborador Regular**: El botón de incidencia anticipada aparece hasta 30 minutos antes de su hora de entrada oficial.
- **Tratamiento según el Puesto**:
  - **Supervisor (Francisco/María)**:
    - *Llegaré Tarde*: Si la ETA excede la hora de apertura oficial (08:30 AM), el sistema realiza un **traspaso automático de llaves y estafeta** al suplente inmediato en la jerarquía.
    - *No asistiré*: Cede de forma inmediata e irrevocable las llaves al suplente y publica alerta urgente en bitácora Matrix.
  - **Colaborador Regular (Asesor/Ayudante)**:
    - *Llegaré Tarde*: Registra su ETA y motivo para informar al supervisor en su panel (no cancela sus comidas).
    - *No asistiré*: Bloquea su checador y realiza una **liberación reactiva del comedor**, cancelando sus reservas para liberar el aforo para sus compañeros en activo.

### G. Reporte de Tienda Cerrada (Amnistía)
- Permite a los colaboradores reportar que la tienda sigue cerrada si el encargado se demoró.
- Al registrarse la apertura posterior, el sistema otorga una amnistía de retardo a todos los empleados afectados.

---

## 3. Escenario Práctico de Sinergia (Flujo de Trabajo Completo)

A continuación se detalla cómo interactúan estas funciones en la práctica a lo largo de un día:

### Paso 1: Llegada e Incidencia de Apertura (08:15 AM - 08:30 AM)
- **Caso Normal**: El supervisor principal (Francisco) llega, ve su botón **morado/lila**, lo oprime para registrar su entrada y abre la sucursal. Los botones de los empleados en la puerta cambian a **verde activo con huella** para fichar.
- **Caso Incidencia (Falta de Encargado)**: Francisco enferma y se encuentra en su casa. Al estar fuera de rango GPS, su dial central se muestra **naranja con el icono de alerta (`⚠️`)**. Francisco lo oprime y reporta su falta:
  - Su estafeta de apertura se transfiere automáticamente a la suplente (María), habilitando el botón morado de apertura en su celular.
  - Los empleados en la puerta ven la alerta de espera: `⏳ Esperando apertura por: María`.
- **Caso Incidencia (Retraso de Empleado)**: Ana (Asesora) se retrasa en el transporte. Abre su app, ve su botón **naranja de alerta (`⚠️`)** y reporta un retraso con su ETA a las **09:15 AM**. El supervisor recibe la alerta informativa y sabe que cuenta con ella.

### Paso 2: Pase de Lista y Rutinas (08:35 AM)
- María abre la sucursal a las 08:25 AM. Realiza el **Pase de Lista** de asistencia rápida.
- Los empleados reciben su **Rutina de Apertura** en el TaskRunner del celular (limpieza, encendido de terminales) para completarla de manera estructurada.

### Paso 3: Reservación e Intercambio de Comida (11:00 AM - 02:00 PM)
- Los empleados apartan sus bloques horarios de comida en la cuadrícula (ej: Ana de *02:00 PM a 03:00 PM*).
- Si María (Supervisora) necesita cambiar su horario, presiona **"Intercambiar Horario"**. El selector **solo le permite elegir a Francisco** (mismo nivel de puesto).
- Si un empleado reporta inasistencia posterior, el sistema **libera reactivamente su espacio de almuerzo** al instante.
