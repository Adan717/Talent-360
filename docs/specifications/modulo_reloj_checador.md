# ESPECIFICACIONES TÉCNICAS: MÓDULO DE RELOJ CHECADOR (ASISTENCIA)

**Objetivo:** Programar un módulo de control de asistencia avanzado (Reloj Checador) con validaciones biométricas (Selfie), geolocalización (GPS/Wi-Fi), y máquinas de estado estrictas para roles de apertura y operación diaria.

## 1. SISTEMA DE SEMÁFORO (Diseño UI/UX)
La interfaz del botón central y notificaciones debe regirse por este código de colores:
*   🔵 **Azul:** Estado inactivo, mensajes informativos o botones en "Sala de Espera".
*   🟢 **Verde:** Fichajes exitosos y flujos activos.
*   🟡 **Amarillo (Ámbar):** Alertas preventivas, retardos menores o reportes de auditoría.
*   🔴 **Rojo:** Penalizaciones severas, bloqueos del sistema o requerimiento de anulación gerencial (Override).

---

## 2. LÓGICA DE APERTURA (Rol: Encargado Titular y Secundario)
*El encargado debe abrir la sucursal antes de que los empleados puedan fichar su entrada.*

*   **Fase Pre-Apertura (1 hr antes):** La pantalla muestra una cuenta regresiva.
    *   **Botón de Alarma:** Permite al usuario programar una notificación Push local para avisarle cuándo debe salir de su casa.
    *   **Botón de Emergencia (Aviso de Ausencia):** Si el Encargado Titular no puede abrir (enfermedad/accidente), presiona este botón rojo. El sistema registra su "Falta/Incidencia" y dispara una alerta delegando automáticamente la responsabilidad de apertura al **Segundo Encargado**.
*   **Contingencia GPS:** Para abrir, el sistema valida el GPS (Ej. radio de 50m). Si el GPS es inestable, el sistema aprueba la ubicación si el dispositivo está conectado a la **Red Wi-Fi oficial** de la tienda.
*   **Apertura Forzosa (Golpe de Estado):** Si el Titular está ausente o no trae celular pero no avisó su emergencia, el Segundo Encargado tiene un botón de "Apertura Forzosa". Al pulsarlo, asume el mando justificando la acción (ej. "Titular sin celular"). Esto dispara una alerta gerencial a la Plataforma.
*   **Fichaje de Apertura (Pase de Lista Humano):** El encargado pulsa `[Abrir Sucursal]` 🔵. El sistema NO los ingresa en automático ciegamente. En su lugar, lanza la pantalla de **Pase de Lista Visual**.
    *   *Anti-Fraude GPS:* El encargado ve una lista de los empleados que pulsaron "Ya llegué" y debe palomearlos viéndolos físicamente. Tiene un botón de "Seleccionar a Todos" para agilizar si ve a la multitud junta.
    *   *Modo Kiosco de Entrada:* Desde ahí mismo puede buscar manualmente a un empleado que haya olvidado su celular para darle entrada. Estos fichajes reciben un tag interno `[Sin Dispositivo]` para futuras auditorías de RH.
    *   Al confirmar la lista, la tienda se abre y esos seleccionados obtienen su registro formal.
*   **Retardo y Regla de Amnistía Condicionada:** Si la tienda se abre tarde por una contingencia, el sistema lanza el Pase de Lista. Sin embargo, el "Perdón" es individual.
    *   *Regla de Tolerancia:* Un empleado tiene una tolerancia estricta de **10 minutos antes y 10 minutos después** de su hora de entrada oficial.
    *   *Opción 1 (Estricta):* El sistema perdona (Amnistía) **EXCLUSIVAMENTE a aquellos que hayan marcado "Ya llegué" DENTRO de su ventana de tolerancia**.
    *   Si un empleado marca "Ya llegué" después del minuto 11 de su tolerancia, el gerente igual puede validarlo en el Pase de Lista para que pase a trabajar, pero el sistema **SÍ le aplica su retardo automático**.
*   **Inicio de Jornada Real (Payroll):** La jornada laboral de un empleado que llega temprano NO inicia cuando el gerente por fin abre la tienda. Inicia en el **milisegundo en que presionó "Ya Llegué"** (si lo hizo dentro de su rango). El Pase de Lista del gerente solo "libera" ese registro para nómina, asegurando que se le pague su tiempo completo esperando afuera.
*   **Castigo al Titular:** Si el Titular (quien usó el Botón de Emergencia) llega a trabajar más tarde, el sistema lo trata como empleado normal pero le exige justificación y genera un **Acta Administrativa por Retardo Severo** en su expediente.

---

## 3. LÓGICA DE FICAJE DIARIO (Rol: Colaboradores)

### A. La Sala de Espera y Fichaje Masivo Automático
*   Si el colaborador llega antes de que la tienda sea abierta, no puede fichar entrada. 
*   Sin embargo, si está en el radio del GPS, se activa el botón 🔵 `[ 👋 Ya Llegué ]`. Al pulsarlo, entra en un estado de "Sala de Espera Virtual".
*   **Trigger Automático Cruzado (WebSockets):** En el milisegundo en que el Gerente confirma el **Pase de Lista** desde su propio dispositivo, el Backend dispara un evento WebSockets (`StoreOpened`). El celular del empleado suscrito a este canal recibe el evento en tiempo real y **cambia su pantalla en automático** a "Activo" (registrando su Entrada Oficial), haciendo vibrar el teléfono 🟢. No necesitan interactuar con su pantalla tras la apertura.
    *   *Protección de Base de Datos:* El backend guarda la `hora_llegada_virtual` (cuando el empleado apretó el botón en la calle) y la `hora_apertura_gerente`. La nómina y retardos se calculan sobre la llegada virtual, asegurando que esta actualización de WebSockets no afecte los beneficios de amnistía.
    *   *Manejo de Rechazos:* Si el gerente **no palomea** al empleado en el Pase de Lista, el evento de WebSockets le avisa al celular del empleado que su lugar fue rechazado. Su pantalla pasará a pedirle que registre su entrada manualmente, sometiéndose al cálculo de retardo tradicional.

### B. Gestión de Retardos (Fuera de la Amnistía)
*   **Retardo Menor:** Fichaje posterior a la hora límite (Ej. 15 min tarde). El sistema emite Alerta Amarilla 🟡 y guarda el retardo para cálculo de nómina.
*   **Retardo Extremo (Bloqueo):** Si el empleado intenta entrar pasando el límite máximo (Ej. 60 min tarde), el botón se pone Rojo 🔴. Se le **bloquea el acceso a la jornada**. Para poder fichar, un Gerente debe autorizar su entrada remotamente mediante una anulación (Override).

### C. Gestión de Horario de Comida
*   **Aviso:** 5 minutos antes, notificación 🟡: *"Prepárate para tu comida"*.
*   **Intercambio P2P (Lunch Swap):** El empleado puede solicitar intercambiar su horario de comida, pero la API **solo debe mostrar en la lista a empleados con su mismo Rol/Jerarquía** para no descuidar áreas operativas.
*   **Regla de Recuperación (Abuso de Tiempo):** Si un empleado tiene 60 minutos de comida y se toma 75 minutos, el botón parpadea en Rojo 🔴 al regresar. El sistema calcula los 15 minutos excedentes y los **suma automáticamente a su Hora de Salida Oficial**, obligándolo a reponer el tiempo exacto.

### D. Fichaje de Salida (Doble Llave)
*   El empleado solicita su salida y se toma la selfie 🔴.
*   La salida queda en estado `pending_approval`. Se envía una notificación al Supervisor. Hasta que el Supervisor autoriza la salida, el estatus del empleado pasa a "Jornada Finalizada" 🔵.

---

## 4. HERRAMIENTAS DE CONTINGENCIA Y AUDITORÍA (Supervisor)

*   **Modo Kiosco (Batería Muerta):** Si el celular de un empleado falla, el Supervisor puede abrir la PWA en modo Kiosco, buscar el nombre del empleado y registrar su entrada/salida usando la cámara del supervisor.
*   **Sistema de Auditoría (Reporte Confidencial):** Cualquier empleado tiene un botón amarillo 🟡 para reportar anomalías de forma anónima. Debe seleccionar al empleado infractor de un Dropdown y elegir una causa:
    1. Abandono de Trabajo (Fuga sin checar).
    2. Inactividad Laboral (Holgazanería).
    3. Emergencia Médica.
    4. Condiciones Inapropiadas (Estado inconveniente/Agresividad).
*   **Reacción Gerencial (Remote Kill-Switch):** Al recibir el reporte, el Supervisor tiene botones rápidos en su panel para **Pausar** o **Cerrar** el reloj del infractor en ese instante, frenando el robo de tiempo y acudiendo a investigar.
*   **CRON de Cierre Sincronizado (Turno Huérfano):** Proceso automatizado nocturno. En el minuto exacto en que la sucursal cierra físicamente sus operaciones, el sistema busca a todos los empleados con jornada `Activa` (aquellos que olvidaron checar salida) y les **Fuerza un Cierre Automático**. Se envía un reporte rojo 🔴 al gerente alertando de estos turnos huérfanos para auditar sus pagos.

---

## 5. TABLAS DINÁMICAS E HISTORIAL DIARIO (Dashboard UI)

*   **Matriz Semanal:** El sistema compila automáticamente las horas trabajadas, ausencias, retardos y pausas de cada colaborador, sumándolas en tiempo real de Lunes a Domingo.
*   **Algoritmo de Evaluación Semanal:** Al final de la semana, un motor pondera el historial y emite etiquetas visuales de rendimiento: Excelente 🟢, Regular 🟡, En Riesgo 🟠, o Baja Sugerida 🔴.
*   **Descansos Ley Silla:** El sistema trackea y grafica de manera segregada las "Pausas Activas Cortas" versus el "Horario de Comida" extendido.

---

## 6. HERRAMIENTAS DE PRUEBAS (Entorno de Desarrollo)

*   **Simulador / Máquina del Tiempo:** Un deslizador manual que permite viajar a cualquier hora del día (Ej. saltar directo a las 14:00 para forzar la hora de comida).
*   **Toggle de Tiempo Real:** Un interruptor seguro en el encabezado que desactiva el simulador y ancla el sistema a la hora biológica real del dispositivo del usuario. Al activarlo, el deslizador manual y las funciones de simulación acelerada se bloquean automáticamente para prevenir colisiones lógicas.
*   **El Fantasma (Pruebas E2E):** Un botón de automatización que inyecta secuencias preprogramadas simulando el comportamiento humano durante una semana completa a super velocidad.

---

## 7. SISTEMA DE EVALUACIÓN 360 (Clima Laboral)

*   **Trigger de Salida (Aduana):** Las evaluaciones se programan periódicamente desde la Plataforma (CMS). El día de la evaluación, el Celular bloquea el botón de "Solicitar Salida". Para poder checar su salida, el empleado está obligado a completar una breve evaluación sobre sus compañeros.
*   **Falsa Privacidad Frontal:** La UI le garantiza al empleado que su evaluación es anónima para sus compañeros, promoviendo respuestas honestas.
*   **Transparencia Trasera:** El CMS permite a la gerencia ver exactamente quién evaluó a quién.
*   **Criterios Dinámicos y Estrellas:** Evalúan mediante un sistema rápido de 1 a 5 estrellas sobre criterios variables (Ej. Trabajo en equipo, Limpieza, Actitud). Se permite un comentario de texto para justificar evaluaciones muy bajas (1 o 2 estrellas).
*   **Alcance:** La evaluación puede ser bidireccional, es decir, el equipo operativo también puede evaluar el desempeño de sus Jefes (Encargados) para medir el liderazgo.
