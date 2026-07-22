# Especificación Operativa: Lógica del Dial (Reloj Checador) - Talent360

Este documento contiene la especificación funcional y la matriz cronológica completa para el funcionamiento del Dialer principal y los botones secundarios del **Reloj Checador PWA** en el ecosistema Talent360.

---

## 1. Definición de Ventanas y Horarios del Sistema

El sistema opera bajo las siguientes ventanas horarias estrictas:

| Concepto | Horario | Minutos desde 00:00 | Comportamiento del Reloj Checador |
| :--- | :---: | :---: | :--- |
| **Horario Tienda (Jornada del Día)** | 07:00 - 18:00 | 420 - 1080 | Único horario funcional del reloj. Fuera de este rango, el reloj se bloquea globalmente. |
| **Horario Apertura (Uso de Llaves)** | 08:00 - 08:40 | 480 - 520 | Ventana obligatoria para que el Encargado realice la apertura física de la tienda. |
| **Jornada Laboral Oficial** | 08:30 - 17:00 | 510 - 1020 | Horario en el que corre el turno de trabajo regulado por LFT. |
| **Horario Atención al Cliente** | 08:30 - 16:00 | 510 - 960 | Horario comercial de cara al cliente. |
| **Horario de Comida** | 10:30 - 13:30 | 630 - 810 | Ventana oficial en la que se permite iniciar y tomar el descanso de alimentos. |
| **Horario Cierre** | 17:00 - 18:00 | 1020 - 1080 | Ventana obligatoria para realizar checklist de seguridad y cierre seguro. |

---

## 2. Descripción Detallada de Reglas y Mecánicas por Estado

Para comprender la operación de las matrices del Dialer de Talent360, a continuación se detallan las reglas y lógicas que rigen cada uno de los 23 estados del sistema:

### 2.1 Fichaje Bloqueado (Estado 1)
* **Condición de Activación**: Se activa cuando un colaborador acumula 3 o más retardos o incidencias de puntualidad injustificadas en el periodo.
* **Comportamiento del Dial**: El botón central se bloquea mostrando **Fichaje Bloqueado**.
* **Acción de Desbloqueo**: El botón secundario se convierte en `🎓 Ir a la Academia`. El colaborador es redirigido obligatoriamente a cursar y aprobar el "Módulo de Puntualidad y LFT" en la plataforma LMS. Una vez completado, el sistema desbloquea automáticamente el dial.

### 2.2 Día Feriado (Estado 2) y Día Descanso (Estado 3)
* **Condición de Activación**: Días marcados como feriados obligatorios por la Ley Federal del Trabajo (LFT) o el día de descanso asignado en el perfil del empleado.
* **Comportamiento del Dial**: Bloqueado por defecto con el texto **Día Feriado** o **Día Descanso**.
* **Acción de Desbloqueo**: Si la operación requiere cobertura, el supervisor puede habilitar horas extras. El botón secundario cambia a `⚡ Laborar Horas Extras`, lo cual desbloquea el dial y registra el tiempo laborado con el cálculo automático de prima por jornada extraordinaria según la LFT.

### 2.3 Reportar Falta (Estado 4)
* **Condición de Activación**: Ventana inicial matutina (07:00 AM - 07:45 AM), exclusiva para encargados de llaves.
* **Comportamiento del Dial**: Habilita la opción de auto-reportar una eventualidad antes de salir de casa.
* **Acción**: Si el encargado reporta **Ausencia**, el sistema inicia el protocolo de suplencia de inmediato. Si reporta **Retardo**, se le exige ingresar una hora estimada de llegada (**ETA**).

### 2.4 Llamar Suplente (Estado 5)
* **Condición de Activación**: Gatillada si el Titular de Llaves reporta Ausencia, o si su ETA reportada es posterior a las **08:45 AM** (tolerancia máxima de apertura).
* **Comportamiento del Dial**: El dial del Titular se bloquea con el texto **Llamar Suplente**.
* **Acción**: El botón secundario cambia a `📞 Marcar a Suplente` para contactar a Mateo (Suplente de llaves) y ceder la estafeta. Al realizar el enlace telefónico, el sistema transfiere digitalmente las tareas y el checklist de apertura al suplente.

### 2.5 En Camino (Estado 6)
* **Condición de Activación**: Periodo de traslado del colaborador (07:45 AM - 08:00 AM para el encargado, o previo al check-in regular de empleados comunes).
* **Comportamiento del Dial**: Muestra **En Camino** para indicar que el usuario está en ruta.
* **Acción**: El botón secundario `⚠️ Reportar Incidencia` está activo. Si ocurre un incidente vial (tráfico pesado, ponchadura, etc.), el usuario lo presiona para justificar de forma anticipada posibles demoras.

### 2.6 Ya Llegué y Mensajería a Encargado (Estado 7)
* **Condición de Activación**: Colaboradores que llegan a la puerta de la sucursal (GPS <= 15m) mientras esta sigue físicamente cerrada.
* **Comportamiento del Dial**: Cambia a **Ya Llegué**. Al presionarlo, registra la geolocalización y congela el registro de puntualidad del colaborador, otorgándole una **Amnistía de Puntualidad** automática si la apertura está demorada.
* **Mensajería Defensiva**: Para los empleados comunes, no se permite realizar llamadas de voz. El botón secundario muestra `💬 Enviar Mensaje`. Al oprimirlo, envía una notificación push al encargado en camino avisando: *"Sofía López está esperando en puerta"*, registrando un reporte de presencia para el administrador.

### 2.7 Abrir Tienda (Estado 8)
* **Condición de Activación**: Exclusivo para el encargado de llaves (Carlos) cuando está físicamente en puerta (GPS <= 15m) dentro de la ventana de apertura (08:00 AM - 08:40 AM).
* **Comportamiento del Dial**: Cambia a **Abrir Tienda** con pulsación luminosa.
* **Acción**: Al oprimir el dial, se registra la apertura oficial de la sucursal, realiza el auto check-in del encargado y desbloquea en cascada el acceso para los demás empleados.
* **Auto Check-In**: Todos los empleados en puerta que marcaron previamente **Ya Llegué** son ingresados al sistema en automático sin tener que pulsar su botón de entrada.
* **Pase de Lista Obligatorio**: Inmediatamente tras la apertura, se despliega en la pantalla del encargado un formulario para calificar uno a uno a los presentes mediante estrellas (1 a 5) en *Presentación (Uniforme)*, *Imagen (Aseo)* y *Energía (Actitud)*.

### 2.8 Apertura Emergencia (Estado 9)
* **Condición de Activación**: Se activa para el Suplente de Llaves (Mateo) si el Titular no abrió a las 08:15 AM y le cedió la estafeta, estando el suplente a $\le 15$m de la tienda.
* **Comportamiento del Dial**: Muestra **Apertura Emergencia**.
* **Acción**: Al presionarlo, el sistema exige la co-validación presencial mediante PIN y firma de **2 testigos** (colaboradores presentes en puerta). Al validarse, se realiza el registro de apertura de emergencia y se transfiere el Pase de Lista al suplente.

### 2.9 Declarar Evento Offline (Estado 10)
* **Condición de Activación**: Pérdida total de conexión a internet o energía eléctrica en la tienda.
* **Comportamiento del Dial**: Muestra **Declarar Evento**.
* **Acción**: Utiliza almacenamiento local persistente (Offline-First en el cliente). Permite registrar asistencia con firma digital offline que se sincroniza automáticamente al restablecerse la red.

### 2.10 Esperando Apertura (Estado 11)
* **Condición de Activación**: Empleado común presente en sucursal antes de que el encargado registre la apertura del local.
* **Comportamiento del Dial**: Muestra **Esperando Apertura** de forma informativa (bloqueado para entrada).
* **Acción**: El botón secundario `💬 Enviar Mensaje` permite enviar reportes de asistencia en puerta.

### 2.11 Fichar Entrada (Estado 12)
* **Condición de Activación**: Tienda abierta en sistema y colaborador dentro del rango de geocerca (GPS <= 15m) dentro de la tolerancia de su hora de entrada (antes de las 08:45 AM).
* **Comportamiento del Dial**: Activo como **Fichar Entrada**.
* **Acción**: Registra el check-in ordinario en el servidor.

### 2.12 Acceso Bloqueado (Estado 13)
* **Condición de Activación**: El colaborador llega a la sucursal después de la tolerancia oficial (> 08:45 AM) sin haber reportado retardo justificado en tránsito.
* **Comportamiento del Dial**: Se bloquea mostrando **Acceso Bloqueado**.
* **Acción**: Requiere desbloqueo del supervisor (escanear código QR o ingresar PIN presencial) para poder realizar el check-in tardío, asignándole la sanción correspondiente.

### 2.13 Fichar Reingreso (Estado 14)
* **Condición de Activación**: Empleado que regresa a la tienda tras una salida autorizada por comisión temporal (banco, entregas, etc.).
* **Comportamiento del Dial**: Muestra **Fichar Reingreso**.
* **Acción**: Cierra la bitácora de la comisión temporal y reanuda el turno ordinario.

### 2.14 Contingencia Activa (Estado 15)
* **Condición de Activación**: Declaración de emergencia sanitaria o ambiental corporativa.
* **Comportamiento del Dial**: Muestra **Contingencia Activa** (informativo).
* **Acción**: Despliega avisos, checklist de seguridad higiénica o protocolos especiales obligatorios antes de permitir el fichaje.

### 2.15 Jornada Activa y Botón de Pánico (Estado 16)
* **Condición de Activación**: Fichaje de entrada realizado con éxito y turno en curso.
* **Comportamiento del Dial**: Muestra **Jornada Activa**. En lugar de mostrar la hora actual, despliega dinámicamente un cronómetro con el **tiempo de trabajo transcurrido** (HH:MM:SS) acumulado por el empleado.
* **Botón de Pánico**: El botón secundario muestra `🚨 Botón de Pánico`. Si ocurre un asalto, accidente o falla de acceso, se presiona para enviar una señal silenciosa inmediata al administrador y RRHH.

### 2.16 Apartar Turno (Estado 16b)
* **Condición de Activación**: Se activa automáticamente a las **10:10 AM** (20 minutos antes de iniciar la ventana de comida).
* **Comportamiento del Dial**: Cambia a **Apartar Turno**.
* **Acción**: Abre un modal interactivo que sigue una **cola de selección secuencial** (uno a uno). El orden de selección se rige según el horario de entrada (el que llegó primero escoge primero) o de manera aleatoria según la configuración del módulo en Talent360. Una vez que el primero selecciona su slot (ej. 11:15 AM - 12:00 PM), se habilita el dialer del siguiente colaborador de la fila para reservar.

### 2.17 Tomar Comida (Estado 17)
* **Condición de Activación**: 5 minutos antes del inicio del slot de comida reservado por el empleado.
* **Comportamiento del Dial**: Cambia a **Tomar Comida**. El botón secundario muestra `🔄 Intercambiar Comida` para solicitar permuta con algún compañero.
* **Acción de Evidencia**: Exige subir una fotografía en tiempo real que demuestre que el área del comedor se encuentra completamente limpia y ordenada antes de comer.

### 2.18 Comiendo y Pausa de Reloj (Estado 18)
* **Condición de Activación**: Evidencia de limpieza inicial subida correctamente.
* **Comportamiento del Dial**: Muestra **Comiendo** y despliega un cronómetro regresivo de 45 minutos.
* **Pausa de Jornada**: Durante este estado, **el acumulador de tiempo de jornada activa se congela por completo**, evitando registrar tiempo de descanso como tiempo trabajado.

### 2.19 Terminar Comida y Alarma de Retorno (Estado 18b)
* **Condición de Activación**: 5 minutos antes de que expire el tiempo de comida (minuto 40 del descanso).
* **Comportamiento del Dial**: El celular emite una alarma sonora y el dialer cambia a **Terminar Comida**.
* **Acción**: Al presionarlo, el sistema exige tomar y subir una fotografía del comedor limpio al finalizar. Al validarse, se reanuda el cronómetro de la jornada laboral, cambia el dial de vuelta a **Jornada Activa** y el sistema le envía una notificación al siguiente colaborador en la fila de espera indicándole que su turno ha iniciado.

### 2.20 Descanso Activo / Ley Silla (Estado 19)
* **Condición de Activación**: Cuando el empleado acumula 2 horas continuas de trabajo de pie (`consecutiveMinutes: 120` configurado en el sistema) o regresa de su turno de alimentos.
* **Comportamiento del Dial**: El botón cambia a **Descanso Activo**.
* **Acción y Aprobación**: Requiere la validación digital del supervisor (ingreso de PIN/QR) para sentarse. Al activarse, inicia un descanso de 15 minutos en el dial, y el sistema despliega un panel con **Tareas Sentadas** (capacitación LMS, auditoría de inventario digital, chat de atención ATS) para mantener la productividad ergonómica del colaborador sin forzarlo a estar parado.

### 2.21 Terminar Descanso (Estado 20)
* **Condición de Activación**: Cumplimiento de los 15 minutos de descanso activo o salida manual anticipada.
* **Comportamiento del Dial**: Muestra **Terminar Descanso**.
* **Acción**: Regresa al colaborador a labores ordinarias de pie, reanudando el conteo de tiempo consecutivo de bipedestación.

### 2.22 Entregar Turno (Estado 21)
* **Condición de Activación**: 15 minutos antes de la hora de cierre oficial (16:45 PM - 17:00 PM), exclusivo para el encargado de llaves.
* **Comportamiento del Dial**: Muestra **Entregar Turno**.
* **Acción**: El botón secundario `🗝️ Delegar Llaves` abre el flujo de conciliación de valores, arqueo de caja de la sucursal y firmas digitales de entrega de turno.

### 2.23 Fichar Salida y Checklist de Seguridad (Estado 22)
* **Condición de Activación**: Ventana oficial de salida (17:00 PM - 18:00 PM).
* **Comportamiento del Dial**: Cambia a **Fichar Salida**.
* **Acción**: Al oprimirlo, se despliega un **Checklist de Cierre Seguro** de 3 puntos obligatorios:
  1. Luces, pantallas y aire acondicionado apagados.
  2. Caja fuerte y valores resguardados bajo llave.
  3. Cortinas metálicas cerradas y alarma electrónica armada.
  *Una vez marcadas las tres casillas, el sistema guarda el check-out de salida y cierra la sucursal en el sistema.*

### 2.24 Fin Jornada y Evaluación de Clima (Estado 23)
* **Condición de Activación**: Check-out registrado con éxito.
* **Comportamiento del Dial**: Muestra **Fin Jornada** (bloqueado para más acciones hoy).
* **Acción**: El botón secundario cambia a `⭐ Evaluar Clima`, desplegando una breve encuesta interactiva diaria sobre el clima laboral y feedback operativo del turno.

---

## 3. Matrices de Estados del Dial por Rol y Horario

### A. Para Encargado de Llaves (Carlos Ramírez - Recorrido: 45 min, Entrada: 08:30 AM)

| # | Estado Interno | Horario / Condición | Texto Principal | Botón Secundario Visible | Comportamiento del Botón Secundario | Acción al Presionar Dial |
| :---: | :--- | :--- | :--- | :--- | :--- | :--- |
| **1** | `inactive` | Retardos acumulados >= 3 | **Fichaje Bloqueado** | `🎓 Ir a la Academia` | Abre módulo LMS para realizar curso. | Bloqueado (Ir a Academia) |
| **2** | `inactive` | Día Feriado Oficial LFT | **Día Feriado** | `⚡ Laborar Horas Extras` | Desbloquea dial con tasa LFT triple. | Bloqueado (Salvo Overtime) |
| **3** | `inactive` | Día de Descanso | **Día Descanso** | `⚡ Laborar Horas Extras` | Desbloquea dial para horas extra de ley. | Bloqueado (Salvo Overtime) |
| **4** | `inactive` | 07:00 AM - 07:45 AM | **Reportar Falta** | `📝 Ver Historial` | Muestra justificaciones previas. | Abre modal de reporte (Falta/Retardo) |
| **5** | `inactive` | Reportó Ausencia o Retardo > 08:45 AM | **Llamar Suplente** | `📞 Marcar a Suplente` | Llama al suplente vía telefónica. | Abre marcador / Log de traspaso |
| **6** | `inactive` | 07:45 AM - 08:00 AM | **En Camino** | `⚠️ Reportar Incidencia` | Reporta accidente/percance vial. | Abre modal de incidencias |
| **7** | `inactive` | 08:00 AM - 08:30 AM (En puerta) | **Ya Llegué** | `📞 Contactar Suplente` | Llama al suplente por si se requiere apoyo. | Registra llegada en puerta |
| **8** | `inactive` | 08:00 AM - 08:40 AM (GPS <= 15m) | **Abrir Tienda** | `📞 Llamar Encargado` | Llama al segundo encargado asignado. | Abre sucursal + Pase de Lista |
| **9** | `inactive` | Apertura vencida sin encargado | **Apertura Emergencia** | `👥 Co-Validación` | Abre modal de PIN para dos testigos. | Solicita PINs de testigos |
| **10**| `contingency` | Sin internet / luz en tienda | **Declarar Evento** | `⚡ Declarar Contingencia` | Genera reporte de falla eléctrica. | Fichar con firma criptográfica offline |
| **11**| `inactive` | Tienda Cerrada (Empleado común) | **Esperando Apertura** | `📞 Llamar Encargado` | Permite contactar al encargado asignado. | Deshabilitado |
| **12**| `inactive` | Tienda Abierta (Horario normal) | **Fichar Entrada** | `🚨 Botón de Pánico` | Abre reporte silencioso a RRHH y directivos. | Fichar Entrada (check_in) |
| **13**| `inactive` | Tolerancia vencida (>08:45 AM) | **Acceso Bloqueado** | `🔑 Solicitud Desbloqueo` | Exige escanear código QR de supervisor. | Bloqueado sin código de supervisor |
| **14**| `active` | Salida por comisión autorizada | **Fichar Reingreso** | `🚶 Salida Temporal` | Registra salida para trámites bancarios. | Fichar regreso de comisión |
| **15**| `active` | Contingencia ambiental activa | **Contingencia Activa** | `ℹ️ Detalles` | Muestra declaratoria y medidas sanitarias. | Informativo (Jornada activa) |
| **16**| `active` | En turno (antes de las 10:10 AM) | **Jornada Activa** | `🚨 Botón de Pánico` | Activa protocolo de pánico. | Muestra tiempo de trabajo transcurrido |
| **16b**| `active` | 10:10 AM - 10:30 AM (Cola comida) | **Apartar Turno** | `📝 Historial` | Muestra reservas del comedor. | Abre modal de reserva secuencial |
| **17**| `active` | 5 min antes de su slot reservado | **Tomar Comida** | `🔄 Intercambiar Comida` | Abre modal para intercambiar slots de comida. | Valida limpieza + Iniciar Comida |
| **18**| `meal` | En descanso de comida | **Comiendo** | `🍔 Extensión` | Solicita prórroga de comida justificada. | Cuenta regresiva (Pausa jornada laboral) |
| **18b**| `meal` | 5 min antes de terminar comida | **Terminar Comida** | `🍔 Extensión` | Alarma de aviso de fin. | Valida limpieza final + reanuda jornada |
| **19**| `active` | Post-comida (Ergonomía) | **Tomar Silla** | `🧘 Tarea Sentado` | Asigna tareas administrativas de ley silla. | Iniciar descanso ley silla (break_start) |
| **20**| `short_break` | En descanso Ley Silla | **Terminar Descanso** | `🏃 Fin Descanso` | Termina descanso antes del límite. | Terminar descanso (break_end) |
| **21**| `active` | 16:45 PM - 17:00 PM | **Entregar Turno** | `🗝️ Delegar Llaves` | Abre modal de arqueo de caja y firmas. | Iniciar delegación y arqueo |
| **22**| `active` | 17:00 PM - 18:00 PM (Cierre) | **Fichar Salida** | `🚪 Salida Anticipada` | Salida temprana autorizada por supervisor. | Abre checklist express + check_out |
| **23**| `finished` | Post check-out hoy | **Fin Jornada** | `⭐ Evaluar Clima` | Abre encuesta de clima laboral y feedback. | Deshabilitado |

---

### B. Para Empleado Común (Sofía López - Entrada: 08:30 AM, Sin Llaves)

| # | Estado Interno | Horario / Condición | Texto Principal | Botón Secundario Visible | Comportamiento del Botón Secundario | Acción al Presionar Dial |
| :---: | :--- | :--- | :--- | :--- | :--- | :--- |
| **1** | `inactive` | Retardos acumulados >= 3 | **Fichaje Bloqueado** | `🎓 Ir a la Academia` | Abre módulo LMS para realizar curso. | Bloqueado (Ir a Academia) |
| **2** | `inactive` | Día Feriado Oficial LFT | **Día Feriado** | `⚡ Laborar Horas Extras` | Desbloquea dial con tasa LFT triple. | Bloqueado (Salvo Overtime) |
| **3** | `inactive` | Día de Descanso | **Día Descanso** | `⚡ Laborar Horas Extras` | Desbloquea dial para horas extra de ley. | Bloqueado (Salvo Overtime) |
| **4** | `inactive` | 07:00 AM - 08:00 AM | **Reportar Falta** | `📝 Ver Historial` | Muestra justificaciones previas. | Abre modal de reporte |
| **5** | `inactive` | Reportó falta (Traspaso llaves) | **Llamar Suplente** | Ninguno | No aplicable (Empleado común sin llaves). | Deshabilitado |
| **6** | `inactive` | 08:00 AM - 08:15 AM | **En Camino** | `⚠️ Reportar Incidencia` | Reporta accidente/percance vial. | Abre modal de incidencias |
| **7** | `inactive` | Rango GPS (Apertura pendiente) | **Ya Llegué** | `💬 Enviar Mensaje` | Notifica al encargado y registra presencia para puntualidad. | Registra llegada para amnistía y puntualidad |
| **8** | `inactive` | 08:00 AM - 08:40 AM | **Abrir Tienda** | Ninguno | Exclusivo para Encargados. | Deshabilitado |
| **9** | `inactive` | Apertura vencida sin encargado | **Apertura Emergencia** | Ninguno | Exclusivo para Encargados. | Deshabilitado |
| **10**| `contingency` | Sin internet / luz en tienda | **Declarar Evento** | `⚡ Declarar Contingencia` | Genera reporte de falla eléctrica. | Fichar con firma criptográfica offline |
| **11**| `inactive` | 08:00 AM - 08:30 AM (Tienda cerrada) | **Esperando Apertura** | `💬 Enviar Mensaje` | Notifica al encargado y registra presencia para puntualidad. | Deshabilitado |
| **12**| `inactive` | Tienda Abierta (Horario normal) | **Fichar Entrada** | `🚨 Botón de Pánico` | Abre reporte silencioso a RRHH y directivos. | Fichar Entrada (check_in) |
| **13**| `inactive` | Tolerancia vencida (>08:45 AM) | **Acceso Bloqueado** | `🔑 Solicitud Desbloqueo` | Exige escanear código QR de supervisor. | Bloqueado sin código de supervisor |
| **14**| `active` | Salida por comisión autorizada | **Fichar Reingreso** | `🚶 Salida Temporal` | Registra salida para trámites bancarios. | Fichar regreso de comisión |
| **15**| `active` | Contingencia ambiental activa | **Contingencia Activa** | `ℹ️ Detalles` | Muestra declaratoria y medidas sanitarias. | Informativo (Jornada activa) |
| **16**| `active` | En turno (antes de las 10:10 AM) | **Jornada Activa** | `🚨 Botón de Pánico` | Activa protocolo de pánico. | Muestra tiempo de trabajo transcurrido |
| **16b**| `active` | 10:10 AM - 10:30 AM (Cola comida) | **Apartar Turno** | `📝 Historial` | Muestra reservas del comedor. | Abre modal de reserva secuencial |
| **17**| `active` | 5 min antes de su slot reservado | **Tomar Comida** | `🔄 Intercambiar Comida` | Abre modal para intercambiar slots de comida. | Valida limpieza + Iniciar Comida |
| **18**| `meal` | En descanso de comida | **Comiendo** | `🍔 Extensión` | Solicita prórroga de comida justificada. | Cuenta regresiva (Pausa jornada laboral) |
| **18b**| `meal` | 5 min antes de terminar comida | **Terminar Comida** | `🍔 Extensión` | Alarma de aviso de fin. | Valida limpieza final + reanuda jornada |
| **19**| `active` | Post-comida (Ergonomía) | **Tomar Silla** | `🧘 Tarea Sentado` | Asigna tareas administrativas de ley silla. | Iniciar descanso ley silla (break_start) |
| **20**| `short_break` | En descanso Ley Silla | **Terminar Descanso** | `🏃 Fin Descanso` | Termina descanso antes del límite. | Terminar descanso (break_end) |
| **21**| `active` | 16:45 PM - 17:00 PM | **Entregar Turno** | Ninguno | Exclusivo para Encargados. | Deshabilitado |
| **22**| `active` | 17:00 PM - 18:00 PM (Cierre) | **Fichar Salida** | `🚪 Salida Anticipada` | Salida temprana autorizada por supervisor. | Abre checklist express + check_out |
| **23**| `finished` | Post check-out hoy | **Fin Jornada** | `⭐ Evaluar Clima` | Abre encuesta de clima laboral y feedback. | Deshabilitado |

---

## 4. Protocolo ante Problemas con las Llaves y Dificultades de Apertura

Para garantizar la alta disponibilidad y la continuidad operativa en las sucursales, el sistema cuenta con los siguientes mecanismos de resolución de incidencias en la apertura:

### 4.1 Retraso o Incidente de Traslado del Encargado
* **Acción**: Si el Titular de Llaves tiene un percance en su camino (tránsito, llanta pinchada, etc.) y sabe que no llegará antes de las 08:30 AM, debe utilizar el botón **⚠️ Reportar Ausencia/Retardo** (Estado #4).
* **Resolución**:
  * Si la hora estimada de llegada (**ETA**) ingresada supera la tolerancia (**08:45 AM**), el sistema le habilita automáticamente el botón **Llamar a Suplente de Llaves** para contactar a Mateo (Suplente) e iniciar el traspaso.
  * Si la **ETA** es menor o igual a **08:45 AM**, el Titular continúa en tránsito y se aplica la **Amnistía de Puntualidad** automática a los colaboradores comunes en puerta mediante el botón **📍 Ya llegué**.

### 4.2 Pérdida de Llaves, Llave Rota o Falla en Cerradura (En Sitio)
Si el Encargado llega a la puerta pero tiene problemas físicos con las llaves o la cerradura que le impiden abrir la sucursal:
1. **Botón de Pánico**: El encargado debe presionar el **Botón de Pánico (🚨)** visible en el Dialer y seleccionar la opción **"Incidencia de Llaves / Problema de Acceso"**.
2. **Notificación Crítica**: Esto genera inmediatamente una alerta prioritaria en la Bitácora de la Matrix y notifica al área de Recursos Humanos, Operaciones y Mantenimiento de Zona.
3. **Activación de Apertura Contingente**: El sistema permite al encargado iniciar un protocolo de apertura de emergencia justificada o llamar a un cerrajero asignado con cargo directo a la empresa, registrando el log de eventualidad para congelar cualquier penalización de puntualidad de los colaboradores presentes.

### 4.3 Falla Técnica de GPS o Geocerca (Ubicación Incorrecta)
Si el encargado se encuentra físicamente en la tienda pero la señal de geocerca de su celular falla (marcando fuera de rango), se implementa la regla de **Apertura Forzosa**:
* **Bypass de Geocerca**: El dialer permite pulsar la opción **Apertura Forzosa** (Estado #154) ingresando un **Código Temporal de Desbloqueo** dictado telefónicamente por el Supervisor de zona o mediante una co-validación PIN del encargado, permitiendo registrar la apertura en sistema y amnistiar a la plantilla.

### 4.4 Ausencia Injustificada y Apertura de Emergencia por Suplente
Si el Titular no se presenta ni avisa:
1. **Alerta de Retraso de Apertura**: A las 08:15 AM (15 minutos antes de la hora laboral) sin registros, el sistema notifica de forma prioritaria en la Matrix.
2. **Apertura por Suplente**: El Suplente de Llaves (Mateo) presente en puerta (GPS <= 15m) verá cambiar su dial a **Apertura de Emergencia** tras la llamada o al vencerse el límite.
3. **Co-Validación con 2 Testigos**: El Suplente inicia la apertura en la app, la cual le exige la co-validación presencial (PIN y firma digital) de **2 colaboradores presentes** en la sucursal. Con esto, se abre la tienda en sistema, Mateo asume el Pase de Lista y las tareas de apertura, y se exime de retardo a los colaboradores co-validadores.

---

## 5. Funcionamiento de la Ley Silla y Propuestas de Mejora

### 5.1 Estado Actual en Talent360
1. **Mecanismo Temporal**: Cuando el colaborador acumula 2 horas de pie (`consecutiveMinutes: 120` configurado en el sistema) o regresa de su ventana de comida, el dialer habilita el estado **Descanso** (Estado #19) por 15 minutos.
2. **Registro Histórico**: La base de datos almacena marcas de tipo `silla_start` y `silla_end`.
3. **Monitoreo en Vivo**: El panel del supervisor (Dashboard) alerta si el descanso se prolonga más de los 15 minutos autorizados, emitiendo una alerta visual.

### 5.2 Propuesta de Mejora Integrada (Aprobación y Tareas Sentadas)
Para optimizar la productividad y cumplir con la Ley Federal del Trabajo de forma auditable:
1. **Flujo de Autorización del Supervisor**:
   - Al acumular los 120 minutos de pie, el colaborador ve el dialer en estado **Solicitar Silla**.
   - Al presionarlo, el Supervisor recibe una alerta en tiempo real en la Matrix QA o en su aplicación móvil.
   - El Supervisor aprueba la solicitud (ya sea de forma presencial con PIN/QR o remota con un click), lo cual desbloquea el botón **Descanso Ley Silla** en el celular del empleado.
2. **Asignación de Tareas Sentadas**:
   - Durante los 15 minutos de descanso, el colaborador no interrumpe su productividad. El sistema le despliega un modal con tareas administrativas que se pueden realizar sentado.
   - **Ejemplos**:
     * *Capacitación LFT/Seguridad en la Academia LMS*.
     * *Responder chats de atención al cliente o prospectos de ATS*.
     * *Auditoría digital de inventario de sucursal*.
     * *Checklist de revisión de tareas operativas diarias*.
3. **Control de Aforo de Descanso (Capacidad)**:
   - Se parametriza el valor `sillas_maximas_simultaneas` en el panel de sucursal. Si se alcanza el límite, el sistema encola las solicitudes automáticas y notifica al colaborador su turno de espera para sentarse.
4. **Historial y Compliance**:
   - Se genera una bitácora detallada con firmas de aprobación del supervisor para proteger legalmente a la empresa ante inspecciones del trabajo.


