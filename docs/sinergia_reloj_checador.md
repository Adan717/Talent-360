# Escenario Práctico: Sinergia de Trabajo en el Reloj Checador (Talent360)

Este documento ilustra un día operativo típico utilizando las funciones del Reloj Checador de Talent360, integrando el funcionamiento del **Smart Dial (botón central contextual e inteligente)** y diferenciando los flujos por puesto de trabajo.

---

## 👥 Personajes del Escenario

1. **Francisco (Supervisor Principal - ID 11)**: Portador de llaves principal (`🔑`). Su rol jerárquico es Supervisor.
2. **María (Supervisora Suplente - ID 12)**: Portadora de llaves suplente (`🔑🔑`). Su rol jerárquico es Supervisora.
3. **Pedro (Ayudante Integral - ID 13)**: Empleado regular. Su rol jerárquico es Ayudante Integral.
4. **Ana (Asesora de Ventas - ID 14)**: Empleada regular. Su rol jerárquico es Asesora de Ventas.

---

## ⏰ Secuencia Operativa Paso a Paso

### Fase 1: La Llegada a la Sucursal (08:15 AM - 08:30 AM)

#### Opción A: Flujo Exitoso (Francisco Abre a Tiempo)
1. **Llegada**: Francisco llega a la sucursal a las 08:15 AM. Abre la app en su móvil.
2. **Detección GPS**: Al estar dentro del perímetro (`isWithinPerimeter`), el gran botón central redondo de su checador se muestra de **color morado/lila brillante con el icono de la tiendita (`🏪`)**.
3. **Apertura**: Francisco presiona el botón morado. Registra su **Entrada Laboral** y marca la sucursal como abierta (`opened`).
4. **Desbloqueo General**: Pedro y Ana (que esperan afuera) ven cómo sus botones grises cambian automáticamente a **Verde Esmeralda con el icono de huella (`👋`)**. Presionan el botón para registrar su entrada a las 08:21 AM.

---

#### Opción B: Incidencia de Apertura (Francisco no puede asistir)
1. **El Problema**: Francisco amanece enfermo en su casa a las 08:15 AM.
2. **Detección GPS (Fuera de rango)**: Francisco abre su app. Como está en su domicilio (`!isWithinPerimeter`), el gran botón redondo central cambia a **Color Naranja/Ámbar** con el **icono de Alerta (`⚠️`)** y la etiqueta **"Reportar Incidencia"**. No hay botones secundarios.
3. **Reporte de Ausencia**: Francisco oprime el gran botón naranja de alerta, abre el modal y reporta "No asistiré por causa médica". Al confirmar:
   - Se desactivan sus llaves virtuales y su dial vuelve a gris de sucursal cerrada.
   - El sistema cede automáticamente la estafeta de apertura a **María** (Supervisora Suplente).
   - En el móvil de Pedro y Ana (en la puerta) aparece el aviso superior: `⏳ Esperando apertura por: María`.
4. **María toma el Control**:
   - En la app de María (aún en camino) el botón redondo central se muestra **naranja con el icono de Alerta (`⚠️`)**.
   - María llega a la tienda a las 08:25 AM. Al ingresar al perímetro GPS, su botón redondo central cambia a **Morado/Lila (`🏪`)**. Lo presiona y abre la sucursal con éxito.

---

#### Opción C: Francisco se Demora en el Tráfico (ETA)
1. **El Reporte**: Francisco se encuentra atrapado en el tráfico a las 08:15 AM. Abre su app, ve el botón **Naranja de Alerta (`⚠️`)** y lo oprime.
2. **Especificación de ETA**: Elige la opción "Llegaré Tarde" e ingresa su hora estimada de llegada para las **08:45 AM**.
3. **Ceder Estafeta por Retraso**: Como su ETA (08:45 AM) excede la hora de entrada oficial (08:30 AM), el sistema transfiere de inmediato las llaves de apertura a María para asegurar la entrada del equipo.
4. **Reporte de Tienda Cerrada**: Si María tampoco llega a tiempo y la tienda sigue cerrada a las 08:30 AM:
   - Pedro y Ana presionan el botón de sucursal cerrada en sus apps para reportar "Tienda Cerrada".
   - Al llegar Francisco a las 08:45 AM y abrir la tienda, Pedro y Ana fichan y el sistema les otorga una **Amnistía automática de retraso**.

---

### Fase 2: Inicio de Jornada y Rutinas (08:30 AM - 09:00 AM)

1. **Pase de Lista**: Tras la apertura, María recibe en su checador la notificación de *"Pase de lista pendiente"*. Marca la asistencia de Pedro y Ana.
2. **Ejecución de Rutinas**: Pedro (Ayudante) abre su pestaña de **Tareas** en el checador móvil y completa su rutina operativa diaria (encender computadoras, validar cajas) en el **TaskRunner**.

---

### Fase 3: Coordinación de Comida (11:00 AM - 01:00 PM)

1. **Reservación**: Ana reserva el bloque de **02:00 PM a 03:00 PM** (aforo libre). Pedro reserva la misma hora (permitido al tener puestos diferentes: Asesora vs. Ayudante).
2. **El Intercambio Jerárquico (Swap)**:
   - María (Supervisora) desea cambiar su comida de *01:00 PM - 02:00 PM*.
   - Presiona **"Intercambiar Horario"** en su modal de comida. El selector **únicamente le muestra a Francisco** (mismo nivel de puesto).
   - Pedro y Ana están ocultos al pertenecer a otra jerarquía operativa, previniendo dejar el piso de ventas desatendido.
3. **Liberación Reactiva de Comedor**:
   - Si por la tarde Ana reporta inasistencia urgente desde su app (fuera de GPS, usando su botón **Naranja `⚠️`**), el sistema **cancela inmediatamente su reserva de comida**, liberando el cupo para sus compañeros en activo.

---

### Fase 4: Descansos y Salida (Jornada de Tarde)

1. **Fichaje de Descanso**: Pedro oprime el botón de descanso (`Coffee`). Su estado cambia a "Break" con un temporizador regresivo de 15 minutos. Al concluir, realiza su marcación de huella para volver a laborar.
2. **Salida**: Al finalizar el turno, todos registran su salida con su huella en el botón central de salida (Rojo Alerta `bg-rose-500` con icono `🚪`), concluyendo su jornada en Talent360.
