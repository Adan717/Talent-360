# Especificación Completa del Botón Dialer y Flujo del Reloj Checador — Talent360

Este documento contiene la **especificación técnica y de negocio completa** del botón Dialer y los flujos operativos del **Reloj Checador PWA** de Talent360.

---

## 1. Arquitectura & Protocolo de Alta Disponibilidad (Offline-First)

### 1.1 Persistencia Offline (Sin Energía Eléctrica / Sin Internet)
- **Fichaje Offline Criptográfico (`IndexedDB`)**: En ausencia de red o energía eléctrica en la sucursal, la PWA funciona autónomamente. Al pulsar el dialer, la marca se registra localmente con:
  - Timestamp UTC del dispositivo.
  - Coordenadas GPS cacheadas de la puerta.
  - Firma digital criptográfica de integridad (`offline_stamp`).
- **Cola de Sincronización Asíncrona (`syncQueue`)**: Al restablecerse la conexión, los eventos en cola se transmiten al backend Laravel vía `/clock/punch-batch` dentro de una sola transacción `DB::transaction()`.
- **Efecto Laboral (Art. 56, 132 y 133 LFT)**: Fichajes offline con *Declaración de Eventualidad (Sin Luz)* devengan el **100% del salario de la jornada**, eximiendo a los trabajadores de faltas, retardos o pérdidas de bonos.

---

## 2. Matriz Cronológica Maestra de los 23 Estados del Dialer

| # | Estado Interno (`clockState`) | Condición / Horario | Ícono Lucide | Texto Principal (En Dial) | Subtexto Informativo | Botones Secundarios Visibles | Acción al Presionar | Efecto Laboral / LFT | Siguiente Estado |
| :---: | :--- | :--- | :---: | :--- | :--- | :--- | :--- | :--- | :--- |
| **1** | `inactive` | Retardos acumulados >= 3 | `🔒` `Fingerprint` | **🔒 Fichaje Bloqueado** | *Acumulaste 3 retardos. Completa curso.* | `🎓 Ir a la Academia` | Bloqueado | Exige curso obligatorio | `inactive` |
| **2** | `inactive` | Día Feriado Oficial LFT | `📅` `Sun` | **DÍA FERIADO (LFT)** | *Natalicio Benito Juárez. Descanso de Ley.* | `⚡ Laborar Horas Extras` | Bloqueado (Salvo Overtime) | Pago Triple si labora | `inactive` |
| **3** | `inactive` | `restDay === currentDay` | `🌴` `Sun` | **DÍA DE DESCANSO** | *Día libre programado* | `⚡ Laborar Horas Extras` | Bloqueado (Salvo Overtime) | Descanso pagado | `inactive` |
| **4** | `inactive` | 07:00 AM – Límite de aviso | `🏪` `Store` | **TIENDA CERRADA** | *Reportar Falta / Retardo disponible* | `⚠️ Reportar Incidencia` | Abre modal de reporte | Permite aviso en trayecto | `absent` / `inactive` |
| **5** | `inactive` | Encargado reportó falta/retardo | `📞` `Phone` | **Llamar a Suplente de Llaves** | *Pasar estafeta de apertura a suplente* | `📞 Marcar a Suplente` | Llama al suplente + Log Matrix | Registra evidencia de sustitución | `handover_pending` |
| **6** | `inactive` | En trayecto hacia sucursal (> 15m) | `📍` `MapPin` | **En Camino a Sucursal** | *Reportar incidencia si ocurre percance* | `⚠️ Reportar Incidencia` | Abre modal de reporte | Cobertura en traslado | `inactive` |
| **7** | `inactive` | Empleado sin llaves en puerta (<= 15m)| `📍` `LogIn` | **📍 Ya llegué** | *Registra llegada para amnistía* | `📞 Llamar Encargado Llaves` (*Sólo llaves*) | Registra llegada presencial | **Amnistía Automática por Encargado** | `waiting_room` |
| **8** | `inactive` | Encargado de llaves en puerta (<= 15m) | `🗝️` `Key`+`Store` | **Abrir Tienda** | *Horario oficial de apertura. Suma bono.* | `📞 Contactar Suplente` | Abre sucursal + Auto check-in | **Suma Bono de Cumplimiento** | `active` (`storeStatus='open'`) |
| **9** | `inactive` | Suplente en puerta + Apertura vencida| `⚠️` `Key` | **Apertura de Emergencia** | *Requiere 2 testigos presenciales* | `👥 Co-Validación Testigos` | Solicita PIN/Firma de 2 compañeros | Autoriza apertura contingente | `active` (`storeStatus='open'`) |
| **10**| `contingency_offline`| Sin energía / Sin internet en tienda | `⚡` `AlertTriangle` | **Declarar Eventualidad** | *Falla eléctrica / Sin red en sucursal* | `⚡ Declarar Contingencia` | Registra ficha de contingencia | **100% Salario Pagado LFT + Bonos** | `active` / `finished` |
| **11**| `inactive` | Tienda Cerrada (Empleado común) | `⏳` `Hourglass` | **⏳ Esperando Apertura** | *Apertura por: Carlos (Encargado)* | `📞 Llamar Encargado Llaves` (*Sólo llaves*) | Deshabilitado | Espera apertura en puerta | `inactive` |
| **12**| `inactive` / `waiting_room`| Tienda Abierta (Horario normal) | `🟢` `LogIn` | **Registrar Entrada** | *Fichaje ordinario de entrada* | `🚨 Emergencia / Pánico` | Ejecuta `check_in` | Jornada laboral iniciada | `active` |
| **13**| `inactive` | Tolerancia vencida post-apertura | `🔒` `MapPin` | **🔒 Acceso Bloqueado** | *Tolerancia vencida. Requiere QR supervisor.*| `🔑 Solicitud de Desbloqueo` | Exige QR/PIN de supervisor | Entrada con penalización | `active` (con retardo) |
| **14**| `active` | Salida autorizada por trámite/banco | `🚶` `LogIn` | **Registrar Reingreso** | *Pase de salida temporal (Regreso est. 30m)* | `🚶 Salida Temporal` | Registra reingreso de comisión | Tiempo laboral continuo | `active` |
| **15**| `active` | Contingencia ambiental/eléctrica | `🛡️` `AlertTriangle` | **Modo Contingencia Activo** | *Penalizaciones congeladas por administración*| `ℹ️ Detalles Contingencia` | Informativo (Pausa retardos) | **100% Salario Pagado LFT** | `active` |
| **16**| `active` | En turno (< 90 min desde entrada) | `🍔` `Coffee` | **Iniciar Comida** | *Disponible a las 10:30 AM* | `🔄 Intercambiar Comida` | Deshabilitado hasta cumplir ventana | Jornada laboral activa | `active` |
| **17**| `active` | En turno (> 90 min / slot reservado) | `🍔` `Coffee` | **Iniciar Comida** | *Haz clic para iniciar tu comida* | `🔄 Intercambiar Comida` | Ejecuta `meal_start` | Inicio descanso alimentos | `meal` |
| **18**| `meal` | En horario de comida | `🏃` `Utensils` | **Terminar Comida** | *Haz clic al regresar a la sucursal* | `🍔 Extensión de Comida` | Ejecuta `meal_end` | Fin descanso alimentos | `active` |
| **19**| `active` | Post-comida (Plan PRO) | `🧘` `Armchair` | **Descanso** | *Descanso Ley Silla (15 min)* | `🧘 Asignación Tarea Sentado` | Ejecuta `break_start` | Descanso ergonomía Ley Silla | `short_break` |
| **20**| `short_break` | En descanso Ley Silla | `🏃` `Armchair` | **Terminar Descanso** | *Haz clic al reincorporarte* | Informativo | Ejecuta `break_end` | Reincorporación laboral | `active` |
| **21**| `active` | Cierre de turno (shiftEnd - 15m) (PRO) | `🗝️` `Key` | **Entrega de Turno** | *Realizar arqueo y entrega de llaves* | `🗝️ Delegar Llaves` | Abre modal de arqueo y delegación | Transfiere responsabilidad caja | `active` (`isHandoverCompleted`) |
| **22**| `active` | Hora de salida (con checklist cierre) | `🚪` `LogOut` | **Registrar Salida** | *Checklist cierre seguro (luces/caja)* | `🚪 Salida Anticipada` | Checklist 3 ticks + `check_out` | Jornada laboral concluida | `finished` |
| **23**| `finished` | Post `check_out` del mismo día | `🏁` `CheckCircle` | **Jornada Finalizada** | *Turno concluido hoy.* | `⭐ Evaluación de Clima` | Deshabilitado (Previene dobles marcas)| Cierre diario verificado | `finished` |

---

## 3. Catálogo de Textos e Íconos Editables del Sistema

| Componente Visual | Ícono Lucide | Texto Principal | Subtexto Informativo | Ubicación en Código |
| :--- | :---: | :--- | :--- | :--- |
| **Perfil Usuario** | `Bell` | **Configura tu alarma** | *Programa tu alerta previa de trayecto (15, 30, 45, 60m)* | `RelojVisual.tsx` (Ajustes Perfil) |
| **Dialer Pre-Turno** | `Store` | **TIENDA CERRADA** | *Reportar Falta / Retardo disponible* | `useClockEngine.tsx` (`getButtonProps`) |
| **Dialer Suplente** | `Phone` | **Llamar a Suplente de Llaves** | *Pasar estafeta de apertura a suplente* | `useClockEngine.tsx` (`getButtonProps`) |
| **Dialer Trayecto** | `MapPin` | **En Camino a Sucursal** | *Reportar incidencia si ocurre algún percance* | `useClockEngine.tsx` (`getButtonProps`) |
| **Dialer Amnistía** | `LogIn` | **📍 Ya llegué** | *Registrar llegada para asegurar amnistía* | `useClockEngine.tsx` (`getButtonProps`) |
| **Dialer Apertura** | `Key`+`Store` | **Abrir Tienda** | *Horario oficial de apertura. Suma bono.* | `useClockEngine.tsx` (`getButtonProps`) |
| **Dialer Emergencia**| `Key`+`AlertTriangle`| **Apertura de Emergencia** | *Requiere co-validación de 2 testigos presenciales* | `useClockEngine.tsx` (`getButtonProps`) |
| **Dialer Sin Luz** | `AlertTriangle` | **Declarar Eventualidad** | *Falla eléctrica / Sin internet en tienda* | `useClockEngine.tsx` (`getButtonProps`) |
| **Dialer Entrada** | `LogIn` | **Registrar Entrada** | *Fichaje ordinario de entrada* | `useClockEngine.tsx` (`getButtonProps`) |
| **Dialer Reingreso** | `LogIn` | **Registrar Reingreso** | *Pase de salida temporal (Regreso est. 30m)* | `useClockEngine.tsx` (`getButtonProps`) |
| **Dialer Comida** | `Coffee` | **Iniciar Comida** | *Haz clic para iniciar tu descanso de comida* | `useClockEngine.tsx` (`getButtonProps`) |
| **Dialer Fin Comida**| `Utensils` | **Terminar Comida** | *Haz clic al regresar a la sucursal* | `useClockEngine.tsx` (`getButtonProps`) |
| **Dialer Ley Silla** | `Armchair` | **Descanso** | *Descanso Ley Silla (15 min)* | `useClockEngine.tsx` (`getButtonProps`) |
| **Dialer Handover** | `Key` | **Entrega de Turno** | *Realizar arqueo y entrega de llaves* | `useClockEngine.tsx` (`getButtonProps`) |
| **Dialer Salida** | `LogOut` | **Registrar Salida** | *Checklist cierre seguro (luces/caja)* | `useClockEngine.tsx` (`getButtonProps`) |
| **Dialer Concluido** | `CheckCircle` | **Jornada Finalizada** | *Turno concluido hoy.* | `useClockEngine.tsx` (`getButtonProps`) |
| **Botón Secundario** | `Phone` | **Llamar a Encargado de Llaves**| *Exclusivo para titulares y suplentes de llaves* | `DialPrincipal.tsx` (Botón Secundario) |
| **Botón Secundario** | `AlertTriangle` | **Emergencia / Pánico** | *Activar protocolo de bloqueo por pánico* | `DialPrincipal.tsx` (Botón Secundario) |

---

## 4. 🎬 Ejemplo Práctico Hipotético Completo (Punto a Fin)

### **Personajes del Escenario:**
- **Carlos Ramírez:** Encargado Titular de Llaves (Horario Oficial Apertura: 08:30 AM).
- **Mateo Fernández:** Suplente de Llaves.
- **Sofía López:** Colaboradora / Ayudante (Horario: 08:30 AM).
- **Miguel Ángel:** Colaborador / Ayudante (Horario: 08:30 AM).

---

### ⏱️ **Paso 1: 07:45 AM — Alarma de Traslado en Perfil ("Configura tu alarma")**
- En la PWA de su celular, Carlos entra a su perfil a la sección **"Configura tu alarma"** (ajustada a 45 min antes de su `shiftStart`).
- A las 07:45 AM, su teléfono emite la notificación push local: *`⏰ Es hora de salir hacia Sucursal Centro para asegurar tu Bono de Apertura.`*
- Su dialer muestra el ícono `Store` con **"TIENDA CERRADA"** y la opción **"Reportar Falta / Retardo"**.

---

### ⏱️ **Paso 2: 08:10 AM — Aproximación GPS en Trayecto**
- Carlos aborda el transporte público. El GPS detecta movimiento en camino (> 15m).
- Su dialer muestra: Ícono `MapPin` (amarillo) + **"En Camino a Sucursal"**, manteniendo accesible el botón de reporte por si ocurre un percance.

---

### ⏱️ **Paso 3: 08:18 AM — Llegada Previa de Sofía & Amnistía por Retardo de Encargado**
- Sofía llega a la puerta de la sucursal a las 08:18 AM. La tienda sigue cerrada porque Carlos viene atascado en el tráfico.
- Sofía ve en su dialer: Ícono `LogIn` (verde con pulso) + **"📍 Ya llegué"**. Como es colaboradora común (sin llaves), NO ve el botón de llamadas a menos que fuera suplente.
- Sofía presiona **"📍 Ya llegué"** a las 08:18 AM. El sistema guarda su presencia física en puerta. Esto le otorga **Amnistía Automática de Puntualidad**, garantizando su salario y bono al 100% sin importar la hora a la que abra el encargado.

---

### ⏱️ **Paso 4: 08:32 AM — Caso Contingencia Sin Luz al Abrir**
- Carlos llega a la puerta a las 08:32 AM, pero se encuentra con que **no hay energía eléctrica ni señal de internet en la plaza comercial**.
- Carlos y Sofía abren la PWA. Al no detectar red, la aplicación opera en modo **Offline-First**.
- Carlos presiona **"Declarar Eventualidad"** (`⚡ AlertTriangle`) y selecciona: *`⚡ Sin Energía Eléctrica en Sucursal`*.
  1. Su marca de llegada y apertura se guarda en `IndexedDB` local con firma criptográfica.
  2. Sofía y Miguel también presionan **"Declarar Eventualidad"** en sus celulares.
  3. El sistema de nómina pre-clasifica la jornada como **"Jornada Causal por Fuerza Mayor (100% Pagada LFT)"**, congelando retardos y protegiendo bonos.

---

### 🚨 **Paso 5 (Escenario Alternativo): 08:45 AM — Apertura de Emergencia por Suplente con 2 Testigos Presenciales**
- Si Carlos hubiera tenido un accidente y no se presenta:
  1. Transcurridos 15 minutos de la hora oficial (08:45 AM), Mateo (Suplente de Llaves) presente en puerta presiona **"Apertura de Emergencia"** (`Key` + `AlertTriangle`).
  2. El dialer despliega el modal de Co-Validación exigiendo la confirmación presencial de **2 testigos presentes en puerta**.
  3. Sofía y Miguel ingresan su PIN en la pantalla de Mateo.
  4. Con los 2 PINs validados, la tienda se abre, Mateo asume la responsabilidad del turno y se genera una alerta prioritaria en la Matrix a Recursos Humanos.

---

### ⏱️ **Paso 6: 01:30 PM — Comida & Ley Silla**
- A las 01:30 PM (más de 90 min de trabajo), Sofía presiona **"Iniciar Comida"** (`Coffee`). Regresa a las 02:15 PM y presiona **"Terminar Comida"** (`Utensils`).
- A las 03:30 PM, Sofía presiona **"Descanso"** (`Armchair`), disfrutando sus 15 minutos de ergonomía Ley Silla (Plan PRO).

---

### ⏱️ **Paso 7: 04:50 PM — Entrega de Turno**
- A las 04:50 PM (10 min antes de la salida), Carlos (o Mateo) ve habilitado en su dialer **"Entrega de Turno"** (`Key` cyan animado).
- Ejecuta el arqueo de caja y delega las llaves al encargado del turno vespertino.

---

### ⏱️ **Paso 8: 05:00 PM — Checklist de Cierre Seguro & Salida Final**
- A las 05:00 PM, Sofía presiona **"Registrar Salida"** (`LogOut`).
- El dialer despliega el **Checklist Exprès de Cierre Seguro** (5 segundos):
  - [x] *Luces y aires acondicionados apagados.*
  - [x] *Caja fuerte y valores resguardados.*
  - [x] *Alarma y cortina de seguridad activadas.*
- Al marcar los 3 ticks, se ejecuta `check_out` y el dialer conmuta permanentemente a **"Jornada Finalizada"** (`CheckCircle` 🏁).

---

## 5. Botones Secundarios / Adicionales del Checador

| Icono/Acción | Nombre del Botón | Condición de Aparición | Comportamiento / Función Operativa | Opciones de Emergencia y Ejemplo de Funcionamiento |
| :---: | :--- | :--- | :--- | :--- |
| 🚨 | **Botón de Pánico** | Visible todo el tiempo en el checador. | Abre modal para notificar emergencias críticas, bloqueando la pantalla del checador y alertando a RRHH / Directivos. | Desglosa 4 opciones específicas:<br>1. **Robo / Asalto**: Alertas de atraco con geolocalización activa.<br>2. **Incendio**: Llama interna y bloqueo para salvaguarda.<br>3. **Emergencia Médica**: Solicitud de ambulancia rápida.<br>4. **Fallo General de Energía**: Reporte de corte de luz.<br>*Ejemplo:* Si ocurre un asalto a las 4:00 PM, el cajero oprime el botón rojo de pánico y selecciona "Robo / Asalto", bloqueando el checador e inyectando un evento crítico en la bitácora del supervisor. |
| 📞 | **Llamar a Encargado de Llaves** | Exclusivo para titulares o suplentes de llaves cuando la tienda está cerrada. | Abre el marcador de teléfono nativo del celular para llamar directamente al encargado titular/suplente responsable de abrir. | *Ejemplo:* El suplente oprime el botón cuando la tienda sigue cerrada pasadas las 8:30 AM para marcarle al encargado asignado (obteniendo el teléfono mediante `responsibleUser.phone`) y coordinar la apertura. |
| 🍔/🔄 | **Intercambiar Comida** | Estado de jornada activa (`clockState === 'active'`) si cuenta con reserva de comedor. | Abre el modal de Swaps permitiendo transferir su turno de comida exclusivamente a compañeros del mismo nivel (`job_role_id`). | *Ejemplo:* Ana tiene su comida de 2:00 a 3:00 PM pero prefiere salir de 3:00 a 4:00 PM. Oprime el botón de intercambio y le envía una solicitud a Pedro para cambiar turnos de comedor. |
| 🚪 | **Salida Anticipada** | Estado de jornada activa antes del fin oficial de turno. | Permite registrar check-out temprano. Requiere escanear código QR de supervisor en planes Pro, o llenar causa en planes Free. | *Ejemplo:* Pedro debe retirarse a las 3:05 PM por una emergencia. Presiona "Salida Anticipada" y le pide a María (supervisora) que escanee su código QR para autorizar la salida temprana sin penalizaciones de nómina. |
| ➕/⏰ | **Laborar Horas Extras** | Estado `"DÍA DE DESCANSO"` o `"DÍA FERIADO (LFT)"`. | Permite desbloquear el dial checador para colaboradores en su día libre que asisten a cubrir un turno extra. | *Ejemplo:* La tienda necesita apoyo en el día feriado del 1 de mayo. Ana asiste a la tienda, oprime "Laborar Horas Extras" en su dial bloqueado de feriado, liberando el checador para registrar su jornada y devengar el sueldo triple por ley. |
| 🔓 | **Apertura Forzosa** | Encargados fuera de geocerca en hora de apertura. | Permite saltarse la regla de perímetro con amnistía temporal si el supervisor tiene permisos administrativos habilitados. | *Ejemplo:* La señal GPS falla en la sucursal. Francisco (gerente) oprime "Apertura Forzosa", lo cual le permite abrir la tienda y fichar entrada justificando su posición mediante bitácora administrativa. |
| 📝 | **Justificantes / Códigos** | Fichajes con retardo o bloqueos. | Permite validar códigos de autorización del supervisor o adjuntar justificantes al instante para mitigar deducciones de nómina. | *Ejemplo:* María llegó tarde por tráfico pesado. Al fichar con retardo mediante PIN del supervisor, oprime "Justificantes" y adjunta una foto del tráfico de Google Maps para que el administrador le anule el descuento de salario de forma digital. |
