# Guía de módulos de Talent 360

**Qué es este documento.** La descripción de **cada módulo tal como existe hoy en el sistema**: qué hace, quién lo ve, cómo se usa desde la pantalla y qué reglas conviene saber. Se levantó **desde el código real** el 2026-08-13 (no de las promesas de venta): si algo está marcado *"en construcción"* es porque la pantalla existe pero el sistema aún no lo respalda, y si una función no aparece aquí es porque no existe todavía.

**Los tres enchufes pendientes** (no son construcción, son credenciales o decisiones del dueño) aparecen en cada módulo bajo *"Depende de configuración externa"*: **correo saliente** (proveedor por elegir — hoy no llega ningún correo), **timbrado fiscal** (llave de Facturapi) y **destino en la nube del respaldo**.

**Roles.** *Administrador* (dueño de la empresa; todo), *Supervisor* (mando medio; lo que su puesto le permita), *Colaborador* (su Reloj y su Academia), *Admin de plataforma* (Talent360; el panel de empresas). **Planes.** Freemium → Pro → Enterprise; un módulo de plan superior se puede activar a la carta.

## Índice

1. [Monitor 360](#monitor-360)
2. [Directorio Digital (Recursos Humanos)](#directorio-digital-recursos-humanos)
3. [Reloj Checador](#reloj-checador)
4. [Tareas IA](#tareas-ia)
5. [Bolsa de Trabajo ATS](#bolsa-de-trabajo-ats)
6. [Academia 360](#academia-360)
7. [Archivo Digital](#archivo-digital)
8. [Reportes IA](#reportes-ia)
9. [Nómina CFDI 4.0](#nómina-cfdi-40)
10. [Matrix QA (Simulador)](#matrix-qa-simulador)
11. [Ley Federal del Trabajo (LFT)](#ley-federal-del-trabajo-lft)
12. [Organigrama y SOP (Wiki de procesos)](#organigrama-y-sop-wiki-de-procesos)
13. [Configuración](#configuración)
14. [Panel de Plataforma (solo Talent360)](#panel-de-plataforma-solo-talent360)
15. [Inicio de sesión y seguridad de la cuenta](#inicio-de-sesión-y-seguridad-de-la-cuenta)

---

## Monitor 360

**Qué es.** En la barra lateral aparece como **Monitor 360** ("Supervisión en Tiempo Real"). Es el tablero desde el que la gerencia ve quién está en turno ahora mismo, qué tarea trae cada quien, la bitácora del día, los proveedores que están en la sucursal, y donde resuelve lo que los colaboradores declaran desde su Reloj (retardos, justificantes, contingencias, emergencias, tareas que quedaron abiertas). También tiene el Chat Operativo de la sucursal.

**Quién lo ve y desde qué plan.**
- Plan mínimo: **Freemium** (siempre está en el menú de la empresa; no se puede apagar).
- **Administrador**: lo ve completo y puede hacer todo.
- **Supervisor**: lo ve en el menú, pero **la información y las acciones dependen de las capacidades que tenga su puesto** (ver "Reglas" abajo). Un supervisor sin puesto, o con un puesto sin capacidades, ve el tablero vacío.
- **Colaborador**: no lo ve; su pantalla es el Reloj Checador.
- **Admin de plataforma**: no entra aquí; tiene su propio panel.

**Funciones (todas respaldadas por el sistema).**

1. **Tablero "Control Operativo en Tiempo Real".** Se refresca solo cada 5 segundos (también con el botón circular de "Actualizar").
   - Cuatro cifras arriba: **Personal** (cuántos están checados de toda la plantilla con cuenta; si faltan dice "N sin checar"), **Almuerzo** (cuántos en descanso), **Eficiencia** promedio del día y, si la empresa tiene la Bolsa de Trabajo activa, **Prospectos** (candidatos registrados). La ficha de Personal lleva al Directorio; la de Prospectos, a la Bolsa de Trabajo.
   - Tarjetas de **colaboradores en turno**: nombre, puesto, estado (En Turno / En Descanso / Inactivo-Sin Tarea), tarea actual con minutos estimados, tareas completadas hoy y una barra de "Eficiencia del día". Quien ya salió (o no ha checado) **no aparece** en las tarjetas.
   - Cómo se usa: escribe en "Buscar colaborador o puesto..." o filtra con **Todos / En Turno / En Almuerzo**.

2. **Asignar Tarea Express a una persona.**
   - En la tarjeta del colaborador pulsa **Asignar Tarea** → captura título, minutos estimados, prioridad (Normal / Alta / Bloqueante) y qué evidencia se le pedirá al terminar (Sin evidencia / Foto / Capturar un número, con su instrucción) → **Asignar Tarea**.
   - La tarea le llega a esa persona a su Reloj, y al terminarla **siempre pide validación del supervisor** ("firma"); paga monedas con las mismas reglas que una rutina.

3. **Mensaje directo desde la tarjeta.** El icono de burbuja junto a "Asignar Tarea" abre el Chat con el nombre de la persona ya escrito.

4. **Chat Operativo de Sucursal** (botón "Chat Operativo").
   - Muestra los últimos 50 mensajes que te tocan: los del equipo, los privados que enviaste y los que te enviaron.
   - Para escribir: en "Para" elige **Todo el equipo** o una persona concreta ("— sólo para él/ella"), escribe y pulsa el botón de enviar (o Enter). Los privados se pintan en morado con la leyenda "Privado para …".
   - **A quién llega**: los mensajes al equipo los ven quienes tienen acceso al Monitor (administradores y supervisores). Un mensaje **privado le llega al colaborador a su Reloj Checador** en su siguiente sincronización (no es instantáneo) y solo lo ven él y quien lo escribió.
   - **📌 Conservar**: al lado de la hora de cada mensaje de equipo hay una chincheta; al pulsarla el mensaje queda "conservado" (citado en un incidente) y **la purga nunca lo borra**; volver a pulsar lo suelta.
   - **Retención**: la propia pantalla dice "Los mensajes del equipo se conservan N días". N lo fija el administrador en **Configuración → General → Retención del Chat de Equipo** (de 1 a 30 días; si no se ha configurado son 7). Los privados, los avisos de megáfono y los 📌 conservados no se borran.

5. **Proveedores en Sitio** (botón "+ Proveedor" o "Registrar" en el panel).
   - Captura Empresa/Proveedor (obligatorio), nombre del chofer y número de factura o remisión → **Confirmar Check-In**. Queda la hora de llegada y quién lo recibió.
   - Cuando se va, pulsa **Salida** en su tarjeta. El panel muestra a los que siguen dentro y a los que llegaron hoy; lo ven todos los gerentes de la empresa (queda guardado, no es solo de tu navegador).

6. **Bitácora en Vivo.** Los 10 movimientos más recientes de la empresa mezclando: entradas/salidas y descansos del Reloj, tareas (pendiente / iniciada / pausada / completada) y apertura o cierre de la sucursal. Solo lectura.

7. **Paneles de resolución** (aparecen arriba del tablero **solo cuando hay algo pendiente**; se ocultan si no hay nada; se actualizan solos):
   - **⏰ Solicitudes de Autorización de Entrada**: colaboradores con retardo pidiendo permiso para registrar su entrada → **✓ Autorizar** o **✕ Rechazar**.
   - **🚨 Emergencias Activas (Botón de Pánico)**: incidentes declarados desde el Reloj, con hora y enlace "ver ubicación" si mandó coordenadas → **✓ Marcar resuelto**.
   - **📝 Justificantes de Retardo**: la persona explica su retardo → **✓ Aprobar** (ese retardo **no se descuenta en nómina**) o **✕ Rechazar**.
   - **⚡ Contingencias por Fuerza Mayor** → **✓ Aprobar (pago 100%)** (la jornada se paga completa y **no cuenta como falta**) o **✕ Rechazar**.
   - **🌙 Tareas Inconclusas de Días Anteriores** (las que quedaron abiertas al cierre): **🟢 Aprobar y pagar** (paga la recompensa), **🟡 Reprogramar a hoy** o **🔴 Rechazar** (se omite sin pago).
   - En todos: **nadie resuelve lo suyo**; si la solicitud es tuya aparece "debe resolverla otro admin o supervisor".

8. **Plan Diario IA** (botón morado "Plan Diario IA").
   - **Solo aparece si el servidor tiene configurada una llave de IA** (OpenAI, que ya está; o Gemini). Sin llave, el botón no existe.
   - Al pulsarlo se muestra un diagnóstico del día (quién vino y quién no, tareas pendientes, puestos), un aviso si "hay huecos de personal" y una lista de sugerencias (reasignar una tarea pendiente o crear una nueva, a qué puesto o persona, minutos y motivo). Si la IA no responde, la pantalla lo dice ("El asistente de IA no está disponible") y **no inventa un plan**.
   - **No asigna nada por sí solo**: es una sugerencia para la junta; se reparte con "Asignar Tarea Express" o desde Tareas. Botón único: **Cerrar**.

9. **Nuevos Módulos Disponibles & Roadmap.** Carrusel con Reclutamiento ATS, Academia 360, Reportes IA, Archivo Digital y Nómina CFDI 4.0 con botón **Adoptar / Adoptado**: prende o apaga ese módulo en el menú de la empresa (queda guardado en la configuración). Los precios que muestran las tarjetas son informativos: **hoy el botón no cobra ni pide pago**. Botón **"💡 Sugerir una función a nuestro equipo"**: abre un cuadro de texto y la sugerencia se registra como ticket para el equipo de Talent360.

10. **Bienvenida.** El banner superior con el nombre de la empresa, el plan y los módulos activos se puede ocultar con la X: "Ocultar en esta sesión" o "No volver a mostrar nunca" (se guarda en ese navegador).

**Reglas de negocio que conviene saber.**
- **Capacidades por puesto (matriz §65)**: para **ver** el tablero, el puesto de un supervisor necesita al menos una de estas capacidades: *Tareas y rutinas*, *Aprobar operaciones*, *Aperturas/llaves* o *Reportes*. Para **operar** (asignar tareas, chat, proveedores, Plan IA) necesita alguna de las tres primeras; con solo *Reportes* mira pero no toca. El administrador pasa siempre. Los paneles de resolución y "Asignar Tarea Express" solo piden ser admin o supervisor.
- **En construcción**: la pantalla para que el administrador otorgue o quite esas capacidades a cada puesto **no existe todavía en la aplicación** (el servidor ya la soporta, solo admin). Hoy los puestos que ocupaban supervisores al momento de la actualización recibieron el juego por defecto (Tareas, Aprobar operaciones, Aperturas, Reportes); un puesto nuevo de supervisor nace sin capacidades y su Monitor sale vacío hasta que se configure. Otorgar permisos, la facturación fiscal, el plan y borrar la empresa **nunca** son delegables: son solo del administrador dueño.
- La **eficiencia** de cada tarjeta se calcula así: parte de 100; si llegó tarde baja 15; si tiene tareas del día se pondera 40% eso y 60% la proporción de tareas completadas.
- "Sin checar" cuenta contra **toda la plantilla activa con cuenta**, no solo contra los que vinieron.
- Las horas y "hoy" se calculan con la zona horaria de la empresa; los turnos nocturnos que cruzan medianoche se manejan bien.
- Un mensaje privado no puede enviarse a alguien de otra empresa ni a quien no tenga cuenta (no aparece en el selector).

**Depende de configuración externa.**
- **Plan Diario IA**: una llave de IA en el servidor (OpenAI ya está); sin ninguna, el botón desaparece.
- Nada más del Monitor requiere correo, PAC ni llaves.

---

---

## Directorio Digital (Recursos Humanos)

**Qué es.** En la barra lateral aparece como **Directorio Digital** ("Estructura y Contratos"). Es donde se da de alta a la gente, se lleva su ficha (datos personales, laborales, accesos, expediente), se administra el catálogo de puestos, se dibuja el organigrama y el encargado ve los pendientes de su equipo (inducción y cursos reprobados).

**Quién lo ve y desde qué plan.**
- Plan mínimo: **Freemium**.
- **Administrador y Supervisor**: entran y pueden hacer todo lo que se describe (alta, edición, baja, puestos, organigrama, PIN de invitación). No hay diferencia por capacidades en este módulo.
- **Colaborador**: no lo ve.
- Es de los pocos módulos que la empresa puede tener aun sin plan de pago.

**Pestañas y funciones.**

### 1. Colaboradores (Directorio Activo / Directorio Inactivo)

- **Buscar y filtrar**: caja "Buscar colaborador por nombre..." y lista "Todos los puestos". Las tarjetas muestran puesto, horario, día de descanso y minutos de comida, y teléfono con enlace para llamar.
- **Aviso de "sin puesto asignado"**: si alguien activo no tiene puesto, sale un recuadro ámbar con los nombres. No bloquea, pero avisa que esa persona **no puede fichar por el kiosco, no aparece en el organigrama y no se le pueden dar capacidades** (Monitor, tareas, aperturas).

- **Alta de Colaborador** (botón azul "Alta de Colaborador"; en celular, el botón flotante → "Alta"):
  1. Captura **Nombre completo**, **Puesto**, **Nivel de acceso** (Colaborador / Supervisor / Administrador), **Tipo de contrato** (Sueldo fijo / A destajo), **Fecha de ingreso** (obligatoria: el día que empieza a trabajar) y **Salario base** (obligatorio) con su periodicidad (Semanal / Quincenal / Mensual / Diario). Hay un botón de micrófono para dictar campos.
  2. Pulsa **Guardar**. La invitación (PIN) se manda por correo si el correo de la empresa está configurado; si no, no se detiene el alta.
  - El **correo de acceso se genera solo** a partir del nombre y el dominio de la empresa (sin acentos). Si eliges un puesto que tiene gente a cargo en el organigrama y dejas nivel "Colaborador", te sugiere darle **Supervisor** (si no, no verá los pendientes de su equipo).
  - **Homónimos**: si ya existe alguien con ese mismo correo generado, pregunta: *Aceptar* = es la misma persona (actualiza su ficha); *Cancelar* = es otra persona (le crea su propia ficha con correo `nombre2@…`). Nunca pisa la ficha del primero sin preguntar.
  - **Contraseña**: no se pide. El sistema genera una que nadie conoce y la persona fija la suya al activar su cuenta con el PIN.
  - **Horario**: el alta no lo pide; hereda el horario de la empresa (Configuración → horario de sucursal). Ajústalo después en la ficha (pestaña Laboral).

- **Ficha del Colaborador** (clic en la tarjeta) con 4 pestañas y botón **Guardar Ficha**:
  - **Personal**: nombre, CURP, RFC, teléfono celular (+52), NSS, dirección, contacto de emergencia (nombre y teléfono).
  - **Laboral**: puesto (con opción "— Sin puesto asignado —"), número de empleado, hora de entrada y salida, minutos de comida, día de descanso, permiso de llaves (Ninguno / Titular / Suplente), fecha de ingreso, tipo de contrato (Fijo / Destajo / Temporal), salario base + periodicidad (el sistema lo convierte a salario diario).
  - **Accesos**: casilla **Colaborador en Activo**; casilla **Permitir acceso web** (al marcarla la persona pasa a Supervisor y aparecen correo de acceso, rol *Supervisor de Confianza* / *Administrador General* y **Nueva contraseña (opcional)**); campos de ID Google/Apple/Samsung; y el bloque **Invitación y Activación Móvil**:
    - **Generar Código de Invitación** → crea un PIN de 6 dígitos y muestra el enlace de activación, botón **Copiar**, un **código QR** del enlace y **Enviar invitación por WhatsApp** (guarda el teléfono, abre WhatsApp con el mensaje y el PIN ya redactados; tú pulsas enviar).
    - El colaborador abre el enlace, escribe su PIN, confirma su nombre/foto y **elige su contraseña** (mínimo 8 caracteres; no acepta contraseñas conocidas como `password123`). El PIN se consume al usarse.
  - **Expediente**: solo lectura. Muestra si completó la inducción y cuántos documentos tiene en el Archivo Digital y cuántos faltan (los documentos se suben en el módulo Archivo Digital).

- **Enviar a inactivo** (icono de persona con menos en la tarjeta): la persona pasa al Directorio Inactivo, **conserva todo su historial** y pierde el acceso web (salvo si es administrador). Confirmación previa.
- **Re-activar Colaborador** (Directorio Inactivo): vuelve al directorio activo.
- **Eliminar Definitivamente** (Directorio Inactivo): pide confirmación. **Si la persona tiene historia (fichajes, recibos de nómina o documentos), no la borra: la archiva** e informa cuántos registros tiene y que deben conservarse (art. 804 LFT). Solo se borra de verdad a quien no tiene ningún registro. No puedes borrar tu propia cuenta ni al último administrador.

### 2. Puestos

- **Crear Nuevo Puesto** → ficha con dos pestañas:
  - **Perfil**: nombre, icono alusivo (por industria), estado activo/inactivo, áreas organizacionales (selección múltiple; "+ Crear nueva área…" y el botón de basura de un área la quita de todos los puestos), nivel de mando (1 Alta dirección … 6 Roles inactivos), descripción, responsabilidades y equipo requerido.
  - **Reglas de Negocio**: **Multiplicador de retardo** (factor por el que se multiplica el descuento por minuto tarde en nómina, si la empresa cobra retardos por minuto), **Portador de llaves físicas** (Ninguno / Solo apertura / Solo cierre / Ambos; lo usa el Reloj para la apertura de la sucursal). Las tres casillas *Justificante médico obligatorio*, *Autorizado para emitir avisos globales* y *Aplica Ley Silla* **se guardan pero hoy no tienen efecto** en ningún cálculo. El campo "Minutos de Tolerancia" ya no se edita aquí (ver Reglas).
  - Si creas un puesto con un nombre que ya existe (sin importar mayúsculas), **actualiza el existente** en vez de duplicarlo.
- **Importar desde Plantillas**: filtra por industria (Retail, Oficina, Restaurante, Manufactura, Salud, Educación), elige una plantilla (muestra horario, comida y tolerancia sugeridos) → **Confirmar e Importar**; se crea en tu catálogo.
- En cada tarjeta: clic para editar; **Ver colaboradores en este puesto**; **Ver vacantes para este puesto**; **Eliminar Puesto** (solo si no tiene colaboradores ni vacantes ligadas; si los tiene, lo dice y no borra).

### 3. Organigrama

- **🌳 Árbol Conectado**: las tarjetas de puesto se acomodan solas. Arrastra desde un puesto hasta otro para dibujar una conexión: línea **sólida** = jerarquía visual (un solo superior por puesto); línea **punteada** = "Reporta A" (un puesto puede reportar a varios). Clic en una conexión → pregunta si quitarla. Clic en un puesto → cajón lateral para editar descripción, responsabilidades, manual y puesto superior. Arrastra la ficha de un colaborador a otro puesto para **cambiarle el puesto** (queda guardado en su ficha).
- **📊 Carriles de Mando**: seis carriles (nivel 1 a 6). Arrastra un puesto a otro carril para cambiarle el nivel de mando; arrastra un colaborador sobre un puesto para reasignarlo. Al pasar el mouse resalta superiores (verde) y subordinados (azul).
- El sistema **rechaza ciclos** (un puesto no puede terminar siendo jefe de sí mismo, ni directa ni indirectamente).

### 4. Mi Equipo (pendientes del encargado)

- **Inducción pendiente**: quién no ha completado su inducción, con puesto, fecha de ingreso y días transcurridos; **se pinta en rojo al cumplir 3 días** desde su ingreso. Si la ficha no tiene fecha de ingreso, aparece pero sin conteo de días.
- **Se atoraron con un curso**: colaboradores con examen reprobado (intentos y último puntaje). Botón **"Ya hablé con él"** lo marca como atendido y lo pasa a "Ya atendidos"; si vuelve a reprobar, reaparece solo.
- **A quién le toca**: el **supervisor** ve a quienes ocupan un puesto que reporta al suyo (y a sí mismo); el **administrador** ve a toda la empresa. Los administradores no aparecen en la lista. Nada de esto bloquea al colaborador: **nada bloquea, todo avisa**. Botón **Actualizar** para recargar.

**Reglas de negocio que conviene saber.**
- **Límites de plan** (el sistema lo avisa y no deja guardar): en **Freemium** máximo **10 colaboradores activos** (salvo periodo de prueba vigente) y **1 cuenta con acceso web** (admin o supervisor); en **Pro**, 3 cuentas administrativas; en Enterprise sin límite de cuentas. Al superar el de cuentas aparece la ventana de "Actualizar suscripción".
- **Sueldo y fecha de ingreso son obligatorios** al dar de alta; sin sueldo la nómina no puede calcularse.
- **Contraseña puesta por el administrador = cambio forzado**: si en la ficha capturas "Nueva contraseña", en cuanto esa persona entre **no podrá hacer nada más que cambiarla** (y cerrar sesión). Si la contraseña la eligió la persona (activación con PIN o enlace de restablecimiento), no se fuerza. En ningún caso se aceptan `password123`, `123456` ni `Master`.
- Guardar la ficha **no apaga la cuenta** de la persona (aunque cambies teléfono o la muevas en el organigrama). Solo "Enviar a inactivo" quita el acceso.
- Quitar "Permitir acceso web" deja a la persona como Colaborador: sigue entrando a su Reloj, pero no al panel.
- El correo debe ser único en toda la plataforma; si otra cuenta ya lo usa, el formulario lo dice.
- El puesto solo puede ser uno de tu propia empresa (igual con los superiores del organigrama).
- **Tolerancia de retardo por puesto**: la que el Reloj y la nómina obedecen es la "regla de reloj por puesto"; si el puesto no tiene una, aplica la de la empresa (Ley Federal del Trabajo → tolerancia). **En construcción**: la pantalla para editar esas reglas por puesto (tolerancia, minutos de comida, pase de lista, evaluación de salida, abrir sucursal, kiosco) existe en el código pero **no tiene botón en la barra** de este módulo; solo se abre escribiendo `?tab=politicas_reloj` en la dirección y solo lista puestos que ya tengan regla. Trátala como no disponible hasta que se le ponga acceso.
- La invitación por PIN es pública pero está limitada a 10 intentos por minuto por dirección; el PIN deja de servir al usarse. Con el botón "Generar Código de Invitación" se puede emitir uno nuevo cuando haga falta.

**Depende de configuración externa.**
- **Correo de invitación automático** al dar de alta: requiere el servicio de correo de la empresa/plataforma configurado (pendiente en el despliegue actual). Mientras tanto, la invitación se entrega con el enlace/QR/WhatsApp desde la pestaña Accesos.
- El **código QR** del enlace de activación se genera con un servicio de internet externo (necesita conexión).
- WhatsApp abre en el navegador o app del usuario; el envío es manual.
- No requiere llaves de IA ni PAC.

---

## Reloj Checador

**Qué es.** En la barra lateral aparece como **Reloj Checador** ("Asistencia y Ley Silla"). Es la aplicación del colaborador: un **dial** (botón grande central) que cambia solo según la hora, el estado de la sucursal y lo que la persona ya hizo, y le dice en cada momento qué le toca (abrir la tienda, registrar entrada, iniciar comida, salir…). Todo lo que ahí se marca es lo que después alimenta la nómina. Funciona como app instalable en el celular y **también sin internet**.

**Quién lo ve y desde qué plan.**
- Plan mínimo: **Freemium**.
- **Colaborador**: es su pantalla principal (entra a `/empleado`). Necesita **expediente con puesto** para poder fichar; sin puesto la app se lo dice y no puede marcar (RRHH lo avisa también).
- **Administrador y supervisor**: lo ven en el menú y pueden fichar igual si tienen expediente; además desde su Reloj resuelven lo que les llegue (autorizaciones de entrada, PIN de supervisor).
- El **kiosco** (tableta compartida) lo abre cualquiera de la empresa con su sesión y desde ahí fichan los demás con su PIN.

### El dial: qué muestra según el momento

El dial nunca ofrece dos cosas a la vez; ofrece la única acción válida en ese instante. Los estados que verá la gente, en orden de un día típico:

1. **Fichaje Bloqueado (🔒)** — si la persona acumuló **3 entradas tardías autorizadas** desde la última vez que aprobó el *Curso de Puntualidad* de la empresa. Único botón: "Ir a la Academia". Se libera aprobando ese curso. Solo aplica si la empresa configuró un curso de puntualidad (Configuración → Reloj); si no, este estado no existe.
2. **Día Feriado (LFT)** / **Día de Descanso** — el dial está apagado; aparece la opción de "Laborar Horas Extras" (que requiere autorización del supervisor, ver abajo).
3. **Empresa Cerrada / Tienda Cerrada** — antes de la apertura. Muestra cuánto falta ("Tiempo de espera restante"). Desde aquí el colaborador puede **Reportar Incidencia** (voy tarde / no voy) para que su encargado lo sepa antes de la hora.
4. **📍 Ya llegué** — cuando la persona está en la puerta y la tienda sigue cerrada. Al pulsarlo se registra su llegada; si después la tienda abre tarde por culpa del encargado de llaves, **su entrada no cuenta como retardo** (amnistía automática, calculada por el servidor dentro de su ventana de tolerancia).
5. **Abrir Tienda** — solo lo ve el **portador de llaves** (titular o suplente, según su ficha en RRHH). Abrir la sucursal registra la apertura y su entrada de un golpe, y suma el bono de apertura si la empresa lo tiene configurado. Si el titular no llega, el suplente ve **Llamar a Suplente / Encargado** y, pasada la tolerancia, **Apertura de Emergencia**, que exige el PIN de **2 testigos** presentes.
6. **Registrar Entrada** — con la tienda abierta. Puede pedir **foto** (si la empresa activó foto de fichaje) y valida **GPS / geocerca** si está configurada (con la ubicación de la sucursal capturada en Configuración; sin ubicación configurada la geocerca no bloquea).
7. **Acceso Bloqueado (tolerancia vencida)** — llegó después de su tolerancia. No puede entrar solo: pide **autorización del supervisor** (por QR de 60 segundos o PIN del supervisor, o desde el Monitor). La entrada queda con retardo. También puede pedir un **justificante de retardo** que el encargado aprueba o rechaza en el Monitor (aprobado = no se descuenta).
8. **Iniciar Comida / Terminar Comida** — la comida se habilita después de los minutos mínimos de trabajo que fije la empresa (Configuración → Comedor). Si la empresa usa reservación de comedor, se aparta un horario y se puede **intercambiar** con un compañero (el intercambio lo valida el servidor). Puede pedir foto de evidencia. Comer de más se descuenta del cierre de jornada ("salida requerida hh:mm").
9. **Descanso (Ley Silla)** — descanso corto post-comida si la empresa activó Ley Silla; los descansos se piden y el encargado los aprueba desde el Monitor.
10. **Entrega de Turno** — para quien tiene llaves, cerca de la hora de salida: arqueo y delegación de llaves a otro portador.
11. **Registrar Salida** — con lista de cierre (luces/caja) si aplica. Salir antes de hora requiere **autorización de salida anticipada** del supervisor.
12. **Jornada Finalizada** — sin más marcas ese día (evita dobles). Desde aquí se contesta la evaluación de clima si está activa.

### Otras funciones del Reloj

- **Emergencia / Pánico** (botón secundario rojo): manda un aviso con tu ubicación (si la das) al Monitor; el encargado lo marca como resuelto. También puedes resolverlo tú.
- **Declarar Eventualidad** (⚡, sin luz / sin internet en la sucursal): la jornada queda protegida — **se paga completa y no cuenta faltas ni retardos** mientras la contingencia esté activa. La declaración la revisa y aprueba/rechaza el encargado en el Monitor. **Funciona sin internet**: se guarda en el teléfono y sube al reconectar.
- **Modo offline**: si se va la red, las marcas se guardan en el teléfono con firma de integridad y se envían solas al volver la conexión, **con la hora en que ocurrieron** (no con la hora de la subida). El dial lo dice ("Sin conexión — se guardará").
- **Kiosco por PIN**: en la tableta de la tienda, cada persona ficha tecleando su PIN de kiosco (se lo asigna RRHH). El kiosco solo ancla la empresa; la identidad la da el PIN.
- **Alarma de traslado** ("Configura tu alarma", en Perfil): aviso en el teléfono N minutos antes del turno (15/30/45/60).
- **Firmar Nómina de Conformidad** (pestaña Nómina): el colaborador ve su recibo del último periodo cerrado con el detalle diario y lo **firma**; sin esa firma el administrador no puede autorizar su pago. También ve su historial.
- **Mi monedero**: monedas y puntos ganados por tareas (ver Tareas IA).
- **Chat**: recibe los mensajes privados que le manden desde el Monitor (en la siguiente sincronización).
- **Perfil & Ajustes**: foto, teléfono, alarma, cambio de contraseña, activar 2FA.
- **Horarios y Turno**: entrada, salida, descanso, comida y **la tolerancia de retardo que se le aplica y de dónde sale** ("de tu puesto" o "de la empresa") — es la misma con la que el servidor juzga su entrada.

**Reglas que conviene saber.**
- **La tolerancia** que ve el dial es exactamente la que cobra la nómina: la del **puesto** si la empresa la configuró en la Matriz de Ventanas de Tiempo, y si no, la de la **empresa** (módulo LFT). Cada entrada guarda con qué tolerancia se juzgó.
- **Nada del reloj bloquea el trabajo salvo dos cosas** que sí son candados: los 3 retardos con curso de puntualidad configurado, y la tolerancia vencida (que se destraba con el supervisor). Todo lo demás avisa.
- Las horas y "el día de hoy" se calculan con la **zona horaria de la empresa**; los turnos nocturnos que cruzan medianoche se cuentan como una sola jornada.
- Los fichajes del **Simulador Matrix** nunca se mezclan con los reales.
- Las fotos de fichaje y de comedor se guardan en almacén privado (solo salen con sesión) y **se borran a los 90 días**.

**Depende de configuración externa:** nada. GPS/foto/kiosco/offline se activan o apagan desde Configuración → Reloj & Asistencia.

---

## Tareas IA

**Qué es.** En la barra lateral aparece como **Tareas IA** ("Automatiza Rutinas"). Es el módulo de rutinas diarias y tareas: el administrador define **qué se hace cada día en la sucursal** (por puesto y por momento del turno), el sistema se lo reparte a la gente en su Reloj, cada quien las va cerrando con la evidencia que se pida, y las cumplidas pagan **monedas** al monedero del colaborador. El encargado valida desde el Monitor lo que requiere firma.

**Quién lo ve y desde qué plan.**
- Plan mínimo: **Freemium**.
- **Administrador y supervisor**: administran las rutinas y ven el tablero.
- **Colaborador**: ve y ejecuta sus tareas del día desde el Reloj (pestaña Tareas).

**Funciones.**

1. **Tareas y rutinas.** Una **tarea** es la definición (qué se hace, cuánto tarda, qué evidencia pide, quién la valida). Una **rutina** es la que decide **cuándo y a quién se reparte**: tiene un disparador (al **fichar entrada**, al **abrir/cerrar** la sucursal, o a una **hora**) y una lista de tareas. **Una tarea que no está en ninguna rutina no le llega a nadie** — el formulario lo dice en el lugar donde antes había un campo "Frecuencia" que se guardaba y nadie leía. Al arrancar el giro con el asistente inicial se cargan las rutinas del catálogo (por ejemplo restaurante: 15 tareas de apertura/cierre).
   - Cómo se usa: Tareas IA → **Nueva tarea** (4 pasos: qué y para quién / cuándo y cuánto / validación / procedimiento) → guarda → mete la tarea en una **rutina** (o crea una) → se reparte sola cada vez que la rutina dispara.
   - **"Describe o dicta la tarea → Generar"**: escribe (o dicta con el micrófono) una frase como "el cajero hace el corte a las 9 de la noche, urgente, que anote el total" y el sistema **pre-llena todo el formulario** (título, minutos, prioridad, categoría, puesto, hora, evidencia con su instrucción, objetivo y pasos) usando la IA (OpenAI). No crea nada solo: revisas los 4 pasos y guardas. Sin llave de IA cae a un intérprete de reglas fijas (entiende "20 min", "foto", "urgente" y poco más).
   - Los campos del formulario y su efecto real: **Título** (lo que ve el colaborador) · **Categoría** (color y filtro; se detecta sola por el título y se puede cambiar) · **Puesto ejecutor** (a quién le puede tocar; "Bolsa de trabajo" = cualquiera la toma) · **Objetivo** (se muestra al colaborador al abrirla) · **Tiempo estimado** · **Hora programada** (ordena el plan del día) · **Prioridad**: *bloqueante* impide fichar salida sin terminarla · **Medir tiempo real** (guarda cuánto tardó cada quien para afinar el estimado; no es IA) · **Tarea sentada** (Ley Silla) · **Mini-asistente** = la evidencia que se pide al terminar (ninguna / foto / número / texto, con su instrucción) · **Modo de supervisión**: forzosa (siempre valida el encargado) / automática / dinámica (por antigüedad) / **comparación con IA** (solo con foto: compara contra tus fotos de referencia) · **Procedimiento** (pasos que el colaborador va marcando) · **Checklist de validación** (lo que el supervisor debe revisar; **se le muestra al validar**) · **Video de apoyo** (lección de Academia).
2. **Ejecución desde el Reloj.** El colaborador ve sus tareas del día; las **inicia, pausa y completa**. Si pide foto o número, no se puede cerrar sin darlo. Al completar, si la tarea exige validación, queda **"pendiente de firma"** hasta que el encargado la apruebe.
3. **Tarea al vuelo.** El colaborador puede registrar una tarea que hizo y no estaba en su lista (con título y minutos); **siempre** requiere validación del supervisor y no paga hasta que la aprueben.
4. **Tarea Express** (desde el Monitor, a una persona concreta) — ver Monitor 360.
5. **Validación gerencial.** En el Monitor, la tarjeta del colaborador muestra sus tareas; las que esperan firma se aprueban o rechazan ahí. Una tarea aprobada paga sus monedas **una sola vez** (el sistema impide pagar dos veces la misma).
6. **Tareas inconclusas de días anteriores.** Cada noche, lo que quedó abierto pasa al panel del Monitor: **Aprobar y pagar / Reprogramar a hoy / Rechazar sin pago**.
7. **Monedero y puntos.** El colaborador ve su saldo y su historial en el Reloj (Mi monedero). Las monedas hoy son **reconocimiento y ranking**; no se canjean por dinero desde el sistema.
8. **Video de apoyo**: una rutina puede vincularse a una lección de la Academia; al abrirla, el colaborador ve el video.

**Reglas que conviene saber.**
- La validación (firma) es del **encargado**, nunca de la misma persona que hizo la tarea.
- Una tarea pagada no vuelve a pagar aunque se reabra; el sistema lo garantiza en el servidor.
- El "reconocimiento por voz" para dictar tareas depende del navegador (funciona en Chrome); si no está disponible, se captura a mano.
- La **comparación con IA de fotos** funciona con la llave de OpenAI del servidor (o Gemini si es la única). Colaboradores con **menos de 30 días** siempre van a revisión humana (30-90 días: mitad; más de 90: casi siempre IA). Si la IA no está o no coincide, la tarea va al supervisor **con la foto** — antes se perdía en ese camino y el supervisor abría una tarea sin evidencia (corregido 2026-08-13).

**Depende de configuración externa:** la IA (Generar, comparación de fotos, Plan IA del Monitor) usa la llave de OpenAI que ya está en el servidor; sin ninguna llave, todo degrada a manual y lo dice.

---

## Bolsa de Trabajo ATS

**Qué es.** Aparece en la barra lateral como **"Bolsa de Trabajo ATS"** (descripción: "Vacantes y Prospectos"). Sirve para publicar vacantes en una página pública de empleos de tu empresa, recibir postulaciones, llevar a cada candidato por un tablero de etapas, agendar entrevistas y, al final, darlo de alta como colaborador con un clic.

**Quién lo ve y desde qué plan.**
- Roles: **admin** y **supervisor** (el supervisor ve todo el módulo igual que el admin). Los colaboradores (empleados) no tienen acceso.
- Plan: **Pro** o Enterprise (también durante el periodo de prueba). En plan gratuito sólo si Talent360 lo habilitó para ese plan o la empresa lo contrató a la carta.
- La página pública de empleos la ve cualquiera, sin sesión.

El módulo tiene cuatro pestañas: **Bolsa de Trabajo**, **Tablero Candidatos**, **Agenda de Entrevistas** y **Vista Pública (Web)**. En celular aparecen abajo como Vacantes / Tablero / Agenda / Pública, y el botón "+" del centro ofrece dos accesos rápidos: "Entrevista" y "Vacante".

### 1. Bolsa de Trabajo (Gestor de Vacantes)

Lista tus vacantes con su puesto oficial, título, modalidad, salario, el interruptor "Web Pública" y los botones editar / eliminar.

**Crear una vacante**
1. Botón **"Nueva Vacante"**.
2. Llena: Título público, **Puesto Oficial** (uno de los puestos de tu organigrama; obligatorio), Modalidad (Presencial / Híbrido / Remoto), Rango salarial (texto libre, p. ej. "$12,000 - $15,000 Mensuales"), Horario y jornada, Descripción, y la lista de **Requisitos** (escribe uno y pulsa "Agregar" o Enter; se pueden quitar con el bote de basura). Opcionalmente una imagen de portada: pega una liga o elige una de la galería de 6 imágenes.
3. **"Guardar Vacante"**. Nace publicada (interruptor encendido).

**Editar** — lápiz en la fila; mismo formulario.

**Publicar / pausar** — el interruptor de la columna "Web Pública" enciende o apaga la vacante. Apagada, deja de aparecer en la página pública y ya no acepta postulaciones (aunque alguien tuviera la liga directa).

**Eliminar** — bote de basura, pide confirmación. La vacante desaparece de la bolsa; las postulaciones que ya recibiste se conservan en el tablero.

**Ver QR Público** — muestra el código QR con la dirección de tu bolsa de trabajo (`…/vacantes/tu-direccion`) para imprimirlo o compartirlo. Si la dirección aún no se ha cargado, te pide abrir primero la pestaña "Vista Pública (Web)".

Reglas: el puesto oficial debe ser de tu propia empresa (si no, el servidor lo rechaza). Al configurar el giro con el asistente inicial se cargan vacantes de ejemplo del giro (retail: Asesor de Ventas y Cajero; restaurante: Mesero y Ayudante de Cocina; otros: Ejecutivo de Atención y Ventas); volver a aplicar el giro las borra y las vuelve a crear.

### 2. Vista Pública (Web) — configuración del portal de empleos

Panel izquierdo con dos sub-pestañas y una vista previa "en vivo" del portal a la derecha.

**Ajustes Básicos**
- **Habilitar Portal Web**: si lo apagas, el servidor deja de entregar vacantes y datos de contacto, y quien entre a la liga ve "Portal no disponible".
- **Ruta del Enlace (Slug)**: la parte final de la dirección pública (`…/vacantes/<slug>`). Sólo letras, números, guiones y guion bajo, máximo 100; no puede repetirse ni coincidir con el subdominio de otra empresa.
- Logo (liga a la imagen) y **Color de Marca** (6 colores prediseñados o uno propio).

**Asistente de Diseño (Banner/Footer)**: título y subtítulo del banner, imagen de fondo (liga), **video de bienvenida** (liga de YouTube; si lo dejas vacío no sale ningún video), texto de copyright, correo y teléfono de contacto, y ligas a Facebook / Instagram / LinkedIn.

Botón **"Guardar Configuración"** (se activa sólo cuando hay cambios).

**Compartir Portal de Empleos**: liga del portal con botones **copiar** y **visitar**, código QR y "Descargar QR Alta Resolución".

Nota: la vista previa muestra todas tus vacantes, incluidas las pausadas (con la etiqueta "Pausada / Ocupada"); el portal real sólo muestra las publicadas.

### 3. La página pública de empleos (lo que ve el candidato)

Dirección: `…/vacantes/<slug>`. Muestra el banner con tu marca, las vacantes publicadas en tarjetas (imagen, modalidad, título, horario) y, abajo, contacto y redes.

**Postularse**
1. Toca una vacante → se abre el detalle (descripción, requisitos, horario, salario).
2. **"Postularse a esta Vacante"** → captura Nombre completo, Correo y Teléfono (los tres obligatorios) → **"Revisar y enviar"**.
3. Revisa el resumen y pulsa **"Enviar mi postulación"** (o "Corregir mis datos"). El candidato queda como **Prospecto** en tu tablero.

**Compartir vacante**: por WhatsApp, Facebook o copiando la liga (la liga abre el portal con esa vacante seleccionada).

Reglas del portal:
- Sólo se puede postular a vacantes publicadas; a una pausada, oculta o eliminada el servidor contesta "Esta vacante ya no está recibiendo postulaciones".
- Límite de 20 solicitudes por minuto por conexión (postulaciones y avisos), para evitar abusos.
- Si el correo del candidato ya tuvo expediente en tu empresa (activo o archivado), el servidor lo marca automáticamente como **Fast-Track** ("ya trabajó aquí"). Es sólo una etiqueta: no se salta ninguna etapa.
- El portal **no permite adjuntar documentos** (acta, INE); por eso en el expediente del candidato verás "Sin documentos entregados".
- El formulario **"Notificarme disponibilidad"** sólo aparece en vacantes pausadas y el portal ya no muestra vacantes pausadas: en la práctica hoy ningún candidato llega a ese formulario (limitación conocida; la tabla de "Interesados" del tablero, descrita abajo, normalmente estará vacía).

### 4. Tablero Candidatos (Atracción de Talento)

Cinco columnas: **1. Prospectos → 2. Evaluación de Postulación → 3. Por Entrevistar → 4. Entrenamiento Piso → 5. Contratación**. Cada tarjeta muestra nombre, vacante a la que se postuló y, si aplica, la etiqueta Fast-Track. Se avanza con botones (no arrastrando):

- En Prospectos: **"A Evaluación"**. En Evaluación: **"A Entrevista"**. En Por Entrevistar: **"A Prueba"**. En Entrenamiento Piso: **"Contratar en 1-Click"**.
- **"Expediente"** (en cualquier etapa): abre la ficha con nombre, correo, estado, el resultado de la "Evaluación de Postulación" (hoy no hay pantalla para capturar esa calificación, así que normalmente dirá "Examen no realizado aún") y la documentación adjunta (ver nota anterior). Desde la ficha también puedes **"Contratar en 1-Click"** aunque no haya llegado a la etapa 4.
- **"Rechazar"** (etapas 1 a 4): pide confirmación; el candidato sale del tablero. Con **"Ver rechazados"** aparece la columna Rechazados, donde el botón **"Devolver a Prospectos"** lo recupera.
- **Interesados en vacantes cerradas**: tabla con los correos que pidieron aviso desde el portal (correo, puesto, fecha). No se les manda nada automáticamente; te toca contactarlos.

**Contratar en 1-Click**
1. Pulsa "Contratar en 1-Click" → ventana de confirmación que explica qué va a pasar → **"Contratar"**.
2. El sistema crea (o reactiva) su cuenta y su expediente de colaborador; te muestra un aviso con el **PIN de invitación** (6 dígitos, para que la persona active su cuenta y ponga su contraseña; también lo puedes consultar después en RRHH), cuántos cursos de inducción de la Academia le esperan y la lista de "Falta por hacer".

Lo que hace exactamente la contratación:
- Cuenta con rol de colaborador y contraseña que nadie conoce (la fija la persona al activar con el PIN). Si el correo ya tenía cuenta en la misma empresa (incluso archivada), la reactiva sin cambiarle el rol.
- Expediente con el **puesto de la vacante**, horario heredado del de la empresa, 60 min de comida, descanso en domingo y **fecha de ingreso = hoy** (de ahí cuenta el plazo de inducción de la Academia).
- **No captura sueldo**: te avisa que lo pongas en RRHH; mientras tanto la nómina usa un valor por defecto.
- Si el candidato quedó sin puesto (vacante borrada, por ejemplo) o si la Academia no tiene cursos de inducción que le apliquen, también te lo avisa. Nada de esto bloquea la contratación: avisa.
- Se **rechaza** si ese correo ya tiene cuenta en **otra** empresa de la plataforma; corrige el correo del candidato antes de contratar.

### 5. Agenda de Entrevistas

- **"Programar Entrevista"** → elige Candidato (sólo los que no están contratados ni rechazados), Fecha, Hora, Entrevistador/Reclutador y Notas → **"Agendar Entrevista"**.
- Las citas se listan en tarjetas ordenadas por fecha y hora. El bote de basura **cancela** la entrevista (pide confirmación).
- No se editan citas (cancela y vuelve a programar) y no se envía ningún aviso al candidato ni al entrevistador: la agenda es interna.

**Dependencias externas.** El código QR se genera con un servicio de internet (api.qrserver.com); sin conexión no se pinta. Las imágenes prediseñadas de vacantes vienen de una galería en internet. No hay envío de correos en este módulo (ni al postularse, ni al agendar, ni a los "interesados").

---

---

## Academia 360

**Qué es.** En la barra lateral aparece como **"Academia 360"** ("Inducción y Capacitación"). El administrador arma cursos (video de YouTube + descripción + examen opcional) por tipo y por puesto; el colaborador los ve en su app como una ruta de aprendizaje, presenta el examen, y al aprobar recibe un certificado con folio verificable. La inducción del recién contratado se da de alta aquí y el sistema avisa (nunca bloquea) si se queda pendiente.

**Quién lo ve y desde qué plan.**
- **Administrar cursos** (crear, editar, borrar, importar plantillas, plantillas de certificado): **admin** y **supervisor**, desde el módulo "Academia 360" del panel.
- **Tomar cursos**: todos los colaboradores desde su app del Reloj Checador (pestaña "Academia"); admin y supervisor también pueden.
- Plan: el registro de módulos lo etiqueta como Enterprise, pero el desbloqueo real lo incluye desde **Pro** (y en periodo de prueba). En plan gratuito sólo si Talent360 lo habilitó o se contrató a la carte.

### 1. Gestor de cursos (panel del administrador)

Pestañas: **Todos**, **Inducción**, **Entrenamiento**, **Promoción** y **Plantillas** (de certificado); buscador por título/descripción; botones **"Importar"** y **"Crear Curso"** (en Plantillas: "Nueva Plantilla"). En "Todos", los cursos se agrupan en "Cursos Generales / Inducción Común" (sin puesto destino: los ve toda la plantilla) y "Cursos para <puesto>".

**Crear o editar un curso**
1. **"Crear Curso"** (o el lápiz al pasar el cursor sobre una tarjeta).
2. Llena: Título; **Categoría**: Inducción (nuevos ingresos), Entrenamiento (capacitación continua) o Promoción (plan de carrera); Plantilla de certificado (opcional, ver abajo); **Puesto Destino** (vacío = todos los puestos; con puesto = sólo lo ve quien tiene ese puesto o quien elige ese puesto como meta); **URL del video** (funciona sólo con ligas de YouTube; si la dejas vacía o pones otra cosa, el colaborador ve "Este módulo no tiene video configurado" y puede evaluar de inmediato); Descripción.
3. **Examen Final**: "Añadir Pregunta" tantas veces como quieras; cada pregunta trae 4 opciones y marcas la correcta con el botón circular. Un curso sin preguntas se completa sólo con ver el material.
4. **"Guardar Curso"**.

**Eliminar**: bote de basura en la tarjeta, con confirmación (borrado lógico).

**Importar** plantillas: abre "Importar Cursos desde Plantillas" con 14 cursos estándar (Inducción a la Empresa, Manejo de Caja, Liderazgo y Supervisión, protocolos de apertura, recepción de mercancía, gastos, pedidos, inventarios, cierre diario, etc.), sin video (cada empresa pone el suyo) y algunos con una pregunta de ejemplo. Selecciona uno → **"Importar a mi Academia"**; luego edítalo a tu gusto.

**Cursos del catálogo del giro**: al configurar el giro con el asistente inicial, se cargan a la Academia los cursos que elegiste del catálogo de tu giro (por ejemplo, todos traen "Derechos Laborales & LFT" y "Ley Silla & Salud Ocupacional"; retail añade "Protocolo de Apertura y Operación Comercial" y "Excelencia en Servicio al Cliente"; restaurante, "Manejo Higiénico de Alimentos (NOM-251)" y "Seguridad e Inspección de Válvulas de Gas"; taller, "Seguridad Industrial y Uso de EPP"; oficina, "Inducción al Software Corporativo"). Llegan sin video, con una pregunta de examen y visibles para toda la plantilla; volver a aplicar el giro no los duplica y conserva el avance de la gente.

**Plantillas de certificado** (pestaña Plantillas → "Nueva Plantilla"): nombre de la plantilla, nombre de la empresa, sede, logo (liga), nombre y firma (liga) del instructor y del director, color primario y secundario. Se guardan en la configuración de la empresa y se usan al imprimir el certificado del curso que la tenga asignada. Borrar una plantilla sólo la quita de la lista.

**Ajuste relacionado (Configuración de la empresa → "Curso de Puntualidad Obligatorio")**: eliges uno de tus cursos. Al acumular **3 retardos** desde la última vez que lo aprobó, el dial del colaborador pasa a "🔒 Fichaje Bloqueado" con el botón "Ir a la Academia" y sólo se libera aprobando ese curso; los retardos en días con contingencia declarada no cuentan. Si dejas "Sin configurar", el bloqueo no aplica.

### 2. Academia en la app del colaborador

Se abre desde el menú del teléfono (**Academia**), desde los avisos "Inducción Pendiente" (botón *Completar*) y "Cursos Pendientes" (botón *Estudiar*), o desde el dial bloqueado por retardos.

Dos pestañas: **Plan** (carrera) y **Logros**.

**Plan**
1. Si no hay meta elegida, **"Elige tu Meta Profesional"**: tu propio puesto u otro puesto activo al que quieras ascender (con "Cambiar" puedes volver a elegir).
2. Se dibuja la ruta con los cursos generales más los del puesto elegido, con su porcentaje de avance. Los cursos se desbloquean **en orden**: cada uno abre cuando completaste el anterior (o su curso requisito). El primero siempre está disponible.
3. Toca un curso → panel con tipo (Inducción Obligatoria / Entrenamiento / Ascenso a Puesto), objetivo y avance → **"Comenzar a Estudiar"** (o "Repasar Módulo" si ya lo aprobaste).
4. En el reproductor, el botón **"Presentar Evaluación"** se habilita al terminar el video (si no hay video, de inmediato).
5. Responde todas las preguntas y **"Enviar Respuestas"**.

Reglas del examen:
- Lo **califica el servidor** (las respuestas correctas nunca viajan a la app). Se aprueba **sólo con todas las preguntas correctas**; la calificación es el porcentaje de aciertos.
- Si repruebas, el intento queda contado en el servidor (cerrar y reabrir el curso no lo borra); la app te pide volver a ver el video. Al llegar a **2 o más intentos reprobados** el caso aparece en el tablero del encargado (ver abajo).
- Un curso con examen no se puede marcar como completado sin aprobarlo. Un curso sin examen se completa al enviar desde la evaluación ("Este módulo no requiere evaluación formal").
- Al aprobar se emite tu **certificado con folio** (una sola vez por curso; volver a aprobar no genera otro). Si el curso es de inducción, tu expediente en RRHH pasa a "Inducción completada".

**Logros**: metas alcanzadas por puesto y **"Mis Certificados"**: cada uno con su folio y botón **"Imprimir"** (usa la plantilla de certificado del curso, si tiene; incluye nombre, curso, empresa, fecha de emisión, folio y la dirección donde verificarlo).

**Verificación pública del certificado**: cualquiera, sin sesión, entra a `…/certificado` (o `…/certificado/<folio>`), escribe el folio (formato `TAL-AAAA-XXXXXXXX`) y pulsa **"Verificar"**. Sólo se muestra lo que ya está impreso: nombre del participante, curso, empresa, fecha y calificación. Límite de 20 consultas por minuto por conexión.

### 3. Inducción pendiente: avisos, nunca bloqueos

- Los cursos de tipo **Inducción** visibles para su puesto son la inducción del nuevo. Al contratar desde la Bolsa de Trabajo (o dar de alta en RRHH) la persona ya los ve; no hay que "inscribirla".
- Plazo: **3 días** desde la fecha de ingreso. En su app aparece el aviso "Inducción Pendiente" con los días que le quedan ("Hoy es el último día", "Ya se pasó la fecha"); si el expediente no tiene fecha de ingreso, avisa sin cuenta regresiva. El aviso desaparece cuando completa **un** curso de inducción. Los administradores no reciben este aviso.
- **Tablero del encargado**: en RRHH → pestaña **"Mi Equipo"** ("Pendientes de mi equipo"). El admin ve a toda la empresa; el supervisor, a quienes ocupan puestos que reportan al suyo según el organigrama. Muestra (a) quién trae la inducción pendiente y desde hace cuántos días (en rojo al cumplir los 3 días) y (b) quién lleva 2 o más intentos reprobados en un curso, con el botón **"Ya hablé con él"** para marcarlo como atendido (si vuelve a reprobar, el caso reaparece; si aprueba, se cierra solo). Nada de esto impide fichar ni trabajar.
- Si la empresa no tiene ningún curso de inducción, no hay aviso ni casos que mostrar (y la contratación del ATS te lo advierte).

**Otras conexiones.** Una tarea del módulo de Tareas puede vincularse a una lección de la Academia para mostrar su video de apoyo (se configura en Tareas). El "bono por certificación" que antes se anunciaba se retiró: hoy la recompensa del curso es el certificado.

**Lo que no existe todavía / depende de configuración.** No se envían correos ni notificaciones por curso completado, reprobado o inducción vencida (todo es en pantalla). El tipo "Recertificación" lo acepta el servidor pero ninguna pantalla lo ofrece. Los videos deben estar en YouTube. La generación del certificado imprimible usa la impresora del navegador (imprimir/guardar como PDF).

---

## Archivo Digital

**En la barra lateral:** "Archivo Digital" (subtítulo "Expedientes y Manuales"). Dentro, la pantalla se titula "Gestor Documental y Expedientes". Sirve para guardar el expediente laboral de cada colaborador (los papeles de contratación) y los manuales oficiales de la empresa, en un almacén privado del que solo salen con sesión iniciada.

**Quién lo ve y desde qué plan**
- Roles: administrador y supervisor de la empresa. El colaborador NO ve su expediente en esta versión (decisión de producto, 2026-08-08).
- Plan: Enterprise. En Pro o Freemium solo aparece si la empresa lo activa como módulo adicional desde el tablero ("Nuevos Módulos Disponibles") o si está en periodo de prueba.

**Funciones (todas con respaldo real)**

1. **Pestaña "Expedientes Colaboradores" — directorio de carpetas.** Muestra una tarjeta por cada colaborador activo con su puesto, cuántos documentos tiene validados sobre los subidos ("2/4 validados") y cuántos de los 6 requeridos le faltan ("3 faltantes" o "Completo"). Se puede buscar por nombre o puesto con la caja "Buscar…".
   - Cómo se usa: entra al módulo → escribe en "Buscar…" si hace falta → haz clic en la carpeta del colaborador.

2. **Expediente del colaborador — lista de 6 documentos requeridos.** Al abrir una carpeta aparece a la derecha la lista fija de requeridos: Solicitud de empleo, Acta de nacimiento, INE / Identificación oficial, CURP, RFC / Constancia SAT y Comprobante de domicilio. Cada renglón dice si está FALTANTE, Pendiente, Validado o Rechazado. Debajo hay una sección "Otros Documentos" para archivos adicionales.

3. **Subir un documento.** Cada requerido que falta trae un botón "Subir"; en "Otros Documentos" está el botón "Subir Documento". Se ve una barra de avance real de la transferencia.
   - Cómo se usa: en el renglón del documento pulsa "Subir" → elige el archivo (PDF, JPG o PNG de hasta 10 MB) → espera a que llegue al 100 %. El documento queda en estado "Pendiente".
   - Si vuelves a subir un documento del mismo tipo (por ejemplo, otra INE), el anterior se retira del expediente y el nuevo lo sustituye. Los "Otros" no se sustituyen: se acumulan.

4. **Validar o rechazar un documento pendiente.** Los renglones en "Pendiente" traen los botones "Validar" y "Rechazar".
   - Validar: un clic en "Validar"; el renglón pasa a "Validado".
   - Rechazar: clic en "Rechazar" → se abre la ventana "Rechazar Documento" → escribe el motivo (obligatorio) → pulsa "Rechazar". El motivo queda visible en el renglón ("Motivo: …"). Un documento rechazado no vuelve a "Pendiente": hay que subir uno nuevo del mismo tipo, que lo sustituye.

5. **Ver, descargar y quitar.** Cada documento tiene los iconos de ojo (Ver), flecha (Descargar) y bote (Quitar del expediente).
   - Ver: abre un visor dentro del sistema (PDF o imagen) con botón "Descargar" y "Cerrar".
   - Quitar: pide confirmación ("¿Quitar … del expediente?"). Lo retira de la lista; el archivo físico se conserva en el servidor (no es un borrado definitivo).

6. **Pestaña "Documentos Corporativos" — almacén de manuales.** Tarjetas con nombre, categoría, tamaño y fecha; buscador "Buscar manuales…".
   - Subir manual: botón "Subir Manual" → escribe la Categoría (hay sugerencias: Manuales de Operación, Seguridad, Servicio al Cliente, Reglamento Interno, o escribe la tuya) → elige el archivo (PDF/JPG/PNG, máx. 10 MB) → "Subir". El nombre del manual es el nombre del archivo.
   - Ver / Descargar: botones en cada tarjeta, igual que en expedientes.
   - Vincular a un curso de Academia 360: botón "Vincular" → ventana "Vincular a Academia" → haz clic en el curso de la lista. La tarjeta pasa a mostrar "Vinculado a: <curso>". Para desvincular, abre la misma ventana y pulsa "Quitar vínculo". Si el curso se eliminó en Academia, la tarjeta lo avisa ("Curso eliminado") y permite quitar el vínculo.

**Reglas que conviene saber**
- Solo se aceptan PDF, JPG y PNG de hasta 10 MB; el sistema rechaza lo demás con un aviso.
- Ningún documento nace validado: todo lo que se sube queda "Pendiente" hasta que un administrador o supervisor lo valide.
- La lista de 6 requeridos es fija para todas las empresas y todos los puestos (no se configura).
- El módulo solo lista colaboradores activos; los dados de baja no aparecen en el directorio.
- Los archivos no tienen dirección pública: solo se pueden abrir o descargar con sesión iniciada dentro del sistema.
- "Quitar del expediente" no destruye el archivo; lo saca de la vista y el original se conserva.
- En el celular, el botón flotante central abre directamente "Subir Manual" en la pestaña Corporativos.

**Configuración externa pendiente:** ninguna. Funciona completo con la instalación actual.

---

---

## Reportes IA

**En la barra lateral:** "Reportes IA" (subtítulo "Analítica Nómina e incidencias"). Dentro se llama "Módulo de Reportes IA". Sirve para descargar la asistencia y las tareas completadas en hoja de cálculo, pedir esos reportes escribiendo una frase, y —solo el administrador— revisar y autorizar la prenómina del periodo cerrado, exportándola a Excel o PDF.

**Quién lo ve y desde qué plan**
- Roles: administrador y supervisor. El supervisor ve únicamente la pestaña "Reportes Operativos" (asistencia y tareas, sin ningún dato salarial); la pestaña "Nómina y Avanzados" solo aparece al administrador. Aunque alguien forzara la pantalla, el servidor exige la capacidad "manage_payroll" para entregar datos de nómina (el administrador la tiene siempre; un supervisor solo si el administrador se la otorga en la matriz de capacidades por puesto).
- Plan: Pro o Enterprise (o Freemium con el módulo activado como adicional o en periodo de prueba).

**Pestaña "Reportes Operativos" — 12 reportes**

Todos bajan en CSV que Excel abre bien, todos explican sus reglas al pie del archivo, y todos
los puede descargar un supervisor (no traen dato salarial). Máximo 92 días por descarga.

| Reporte | Qué contesta |
|---|---|
| **Asistencia** | El detalle: cada entrada, salida y retardo, movimiento por movimiento |
| **Retardos y Faltas por Colaborador** | Quién reincide. **Mismas cifras que la nómina**: respeta justificantes y contingencias aprobados |
| **Horas Trabajadas y Extra** | Horas en sucursal y efectivas por día (descontando comida), turnos nocturnos incluidos |
| **Cumplimiento de Rutinas** | Qué se hizo, qué se omitió y qué quedó sin cerrar, por persona y por tarea |
| **Tareas Completadas** | El listado de tareas cerradas, una por renglón |
| **Justificantes y Autorizaciones** | Qué se aprobó y qué se rechazó, con el motivo y quién lo resolvió: es la evidencia de por qué un retardo no se cobró |
| **Aperturas y Cierres** | Quién abrió, a qué hora, si fue a tiempo (misma regla que paga el bono), y las aperturas de emergencia |
| **Comedor y Ley Silla** | Comidas, excesos contra los minutos del expediente, y descansos de Ley Silla (evidencia de cumplimiento) |
| **Inducción y Capacitación** | Quién trae la inducción vencida, quién se atoró reprobando y qué certificados se emitieron |
| **Expediente Documental** | Qué documentos tiene validados cada quien y cuáles le faltan (foto de hoy, no un periodo) |
| **Embudo de Reclutamiento** | Candidatos por etapa y por vacante, contratados y rechazados |
| **Monedero y Reconocimientos** | Monedas y puntos ganados, tareas validadas y rechazadas, saldo y nivel |
| **Rotación de Personal** | Altas, bajas, plantilla activa, antigüedad y permanencia |
| **Nómina Histórica** 🔒 | Lo que se pagó en periodos anteriores: netos, deducciones, firmas y timbrado. **Solo con la capacidad de nómina** |
| **Costo de Nómina por Puesto y Área** 🔒 | Cuánto cuesta cada puesto y cada área: sueldo, bonos y cuánto se descontó por faltas y retardos. **Solo con la capacidad de nómina** |

**Dos cosas que estos reportes dicen de sí mismos, y conviene saber antes de usarlos:**

- **Nómina Histórica** lee los recibos **guardados**: no recalcula nada con la asistencia de hoy, así que un recibo firmado no cambia aunque después se corrija una falta (así debe ser: es el instrumento legal). Los recibos en **borrador** se totalizan aparte, porque el sistema los vuelve a calcular cada noche hasta que el colaborador firma. Y el "neto" **no incluye ISR ni IMSS**: este sistema no calcula retenciones fiscales — es sueldo del periodo menos deducciones internas más bonos. Si la empresa cambió de periodicidad, el reporte avisa cuando detecta recibos que cubren días repetidos.
- **Costo por Puesto** sólo puede sumar recibos que guardaron su desglose por concepto, cosa que el sistema hace **desde el 16 de agosto de 2026**: los recibos anteriores tienen su neto pero no sus partes, así que el reporte los deja fuera de las sumas y **dice cuántos son y cuánto valían** en vez de repartirlos a ojo. Cada recibo cuenta en el puesto y el área que tenía **cuando se generó**: si alguien cambia de puesto, su gasto pasado no se mueve de lugar. Ojo: el neto no incluye ISR ni IMSS, así que el costo patronal real es mayor.
- **Rotación** cuenta altas, plantilla y antigüedad con certeza, pero **las bajas anteriores al 16 de agosto de 2026 no tienen fecha**: hasta ese día el sistema no la registraba. De ahí en adelante cada baja guarda su día y su motivo (se pregunta al enviar a inactivo), y entonces el índice de rotación del periodo sí será calculable.

**Detalle de los reportes básicos**

1. **Asistencia del Día (CSV).** Tarjeta "Asistencia del Día" → botón "Descargar CSV". Baja un archivo con las columnas Fecha, Colaborador, Puesto, Movimiento (Entrada, Salida, Inicio/Fin de comida, Inicio/Fin de descanso), Hora, ¿Retardo? y Minutos de retardo, del día de hoy en la zona horaria de la empresa. Se abre bien en Excel en español (acentos correctos).

2. **Tareas Completadas (CSV).** Tarjeta "Tareas Completadas" → "Descargar CSV". Trae las tareas cerradas en los últimos 30 días: Fecha, Colaborador, Tarea, Prioridad, Minutos estimados, Minutos reales y Puntos.

2.b **Retardos y Faltas por Colaborador (CSV)** — *nuevo 2026-08-13*. Una línea por persona con: retardos, minutos acumulados, faltas físicas, faltas que se generaron por acumular retardos, faltas totales, exceso de comida y días festivos trabajados. Ordenado de más incidencias a menos (para ver quién reincide). Últimos 30 días por defecto.
   - **Las cifras son las mismas que la nómina**: salen del mismo motor que calcula el recibo, así que respetan los justificantes aprobados y las contingencias (un retardo justificado NO aparece como retardo cobrable). Contar los retardos a mano desde el CSV de asistencia da un número MAYOR, porque ahí no se ven las exenciones.

2.c **Horas Trabajadas y Extra (CSV)** — *nuevo*. Una línea por persona y día: entrada, salida, horas en sucursal, comida, descansos, **horas efectivas** (sucursal − comida − descansos) y si fue trabajo en día no laborable autorizado. Últimos 14 días por defecto.
   - Los turnos que cruzan medianoche cuentan como **una sola jornada**. Si alguien olvidó checar salida, la columna Observación lo dice ("Cerrada por el sistema" o "Sin salida registrada") y **no se le inventan horas**.
   - Es un reporte **operativo**: la nómina de este sistema se paga por día, no por horas, así que no altera ningún recibo.

2.d **Cumplimiento de Rutinas (CSV)** — *nuevo*. Dos bloques en el mismo archivo: por **colaborador** y por **tarea**, con asignadas, hechas, omitidas, sin cerrar y **% de cumplimiento**. Últimos 30 días por defecto.
   - Definición que usa (viene escrita en el propio archivo): *hechas* = completadas + las que esperan firma; *omitidas* = rechazadas o saltadas; *sin cerrar* = pendientes, en curso o pausadas.
   - **No trae "minutos reales"** a propósito: el sistema sólo mide el tiempo real cuando la persona *pausa* la tarea, así que esa columna sería casi toda ceros y engañaría.

3. **"Pídelo con tus palabras" (asistente por frase).** Solo aparece si la instancia tiene configurada la llave de OpenAI; si no, la tarjeta no existe y quedan los dos botones de arriba.
   - Cómo se usa: escribe la frase (por ejemplo "los retardos de la semana pasada" o "tareas completadas de julio", máximo 300 caracteres) → pulsa "Interpretar" (o Enter) → el sistema muestra "Entendí: Asistencia y retardos / Tareas completadas de <periodo>" y llena las fechas "Del" y "Al" → revisa (puedes corregir las fechas a mano) → pulsa "Descargar CSV".
   - El asistente NO descarga nada por sí solo: solo llena el formulario; la descarga siempre la confirmas tú con el botón.
   - Entiende periodos como hoy, ayer, últimos N días, la semana en curso, la semana pasada, "la semana 32", el mes en curso, el mes pasado o un rango de fechas ("del 1 al 15 de julio"). Las semanas se calculan con el día de inicio de semana configurado por tu empresa.
   - Sabe elegir entre los **cinco** reportes operativos: asistencia, tareas, retardos y faltas, horas trabajadas y cumplimiento de rutinas. Ejemplos: *"quién llega tarde seguido este mes"* → Retardos y Faltas; *"horas trabajadas de la semana pasada"* → Horas; *"cumplimiento de rutinas de julio"* → Rutinas. Si le pides nómina u otra cosa, contesta que no es uno de los reportes disponibles.
   - Debajo del cuadro se avisa: la frase se envía a OpenAI (EE. UU.) únicamente para interpretarla y se guarda junto con tu usuario según la retención configurada de la empresa; evita escribir datos personales innecesarios.

**Pestaña "Nómina y Avanzados" (solo administrador)**

Al entrar carga automáticamente la prenómina del **último periodo cerrado** de la empresa (semana, quincena o mes, según la periodicidad configurada en Configuración → "Nómina & Periodicidad"). El periodo se muestra en una etiqueta ("Periodo del … al …").

4. **Resumen del periodo.** Tres tarjetas: "Nómina Bruta del Periodo" (con la línea "+ $… en bonos de cumplimiento" si los hay), "Deducciones (Retardos y Faltas)" y "Total a Pagar (Neto)". Si hay colaboradores sin sueldo capturado, la tarjeta de neto avisa "No incluye N colaborador(es) sin sueldo capturado" y esos no entran en las sumas.

5. **Desglose por empleado.** Tabla con Colaborador, Puesto, Retardos, Faltas, Salario Base, Penalización y Neto a Pagar. Un colaborador sin sueldo capturado aparece con "Pendiente" / "Ajustar Salario" (se corrige en Recursos Humanos).
   - Cómo se usa: haz clic en el renglón del colaborador para desplegar el "Detalle Diario de Asistencia": por cada día del periodo, entrada y salida, "Día de Descanso", "Falta / Inasistencia" (solo en días ya terminados), "Sin registro aún" (día no concluido), aviso de "Exceso de comida: N min · salida requerida hh:mm" cuando aplica, y el estado de la "Firma Diaria" del colaborador (Firmado / Pendiente).

6. **Exportar Excel / Exportar PDF.** Botones al pie. El archivo se llama Prenomina_<inicio>_a_<fin>.xlsx o .pdf y contiene el mismo periodo que ves en pantalla, con una columna extra "Firma Empleado" (Borrador, Falta firma del colaborador, Firmada por el colaborador, Autorizada, Finalizada, Timbrada, Rechazada). En el Excel la columna "Bruto del Periodo" es contra la que se restan las deducciones para llegar al neto.

7. **Autorizar Pago de Nómina.** Botón verde al pie (deshabilitado si la tabla está vacía).
   - Cómo se usa: revisa la tabla → pulsa "Autorizar Pago de Nómina" → aparece la ventana "Pago Autorizado" (o "Nada que Autorizar") con el conteo real: "N nómina(s) autorizada(s) para pago" y, si aplica, "N sin autorizar: el colaborador aún no firma de conformidad".
   - Solo se autorizan las nóminas del periodo que el colaborador **ya firmó** desde su Reloj Checador ("Firmar Nómina de Conformidad"). Las no firmadas se quedan pendientes y se informan.
   - Nadie puede autorizar su propia nómina (si el administrador también es colaborador, la suya se salta y la debe autorizar otro administrador).
   - Autorizar dos veces no cambia nada: se conserva quién y cuándo autorizó la primera vez.
   - La autorización deja registro de quién y cuándo. El timbrado fiscal NO ocurre aquí: se hace en "Nómina CFDI 4.0" sobre las nóminas ya autorizadas.

**Reglas que conviene saber**
- Los reportes CSV admiten como máximo 92 días por descarga; si el asistente o las fechas piden más, el sistema lo rechaza con aviso. Tampoco acepta periodos futuros ("no hay asistencia del futuro"); si el rango termina en el futuro, lo recorta a hoy.
- Las descargas tienen límite de frecuencia (30 CSV por minuto, 20 interpretaciones por minuto) para no saturar el servidor.
- Los fichajes del Simulador Matrix nunca entran en los CSV ni en la nómina real.
- Cómo se calcula la prenómina (reglas de Ley Federal del Trabajo configurables en el módulo LFT): las faltas descuentan el día completo; cada 3 retardos cuentan como una falta (configurable); el descuento por minuto de retardo viene en $0 de fábrica (activarlo es decisión de cada empresa) y un retardo que ya se convirtió en falta no se cobra además por minuto; el séptimo día se paga proporcional a las faltas de la semana; el día festivo trabajado paga salario doble adicional; un retardo con justificante aprobado o un día con contingencia aprobada no se cobra ni cuenta como falta; el neto nunca baja de $0 y se le suman los bonos de puntualidad/apertura si la empresa los tiene configurados.
- Un día solo cuenta como falta si ya terminó; el periodo en curso no es autorizable: siempre se trabaja sobre el último periodo cerrado.
- Si en RRHH no está capturado el sueldo, la fila se marca "Pendiente" y se excluye de los totales (internamente el cálculo usa un valor histórico de $2,400 semanales, por eso conviene capturar el sueldo real antes de autorizar).
- El nombre "Reportes IA" es el de la barra lateral; el cálculo de nómina no usa inteligencia artificial: es asistencia + reglamento. Lo único con IA es el asistente por frase (queda como decisión pendiente del dueño el nombre del módulo).

**Configuración externa pendiente**
- El asistente por frase requiere una llave de OpenAI en el servidor; sin ella, la tarjeta simplemente no aparece (el resto del módulo funciona).
- El timbrado (siguiente paso tras autorizar) depende de la llave del PAC — ver "Nómina CFDI 4.0".

---

---

## Nómina CFDI 4.0

**En la barra lateral:** "Nómina CFDI 4.0" (subtítulo "Timbrado masivo del SAT"). Dentro se llama "Facturación Electrónica CFDI 4.0". Sirve para capturar los datos fiscales de la empresa y sus sellos digitales (CSD), timbrar los recibos de nómina ya autorizados a través del proveedor Facturapi y consultar los CFDI emitidos. La periodicidad de pago (semanal/quincenal/mensual) que usa todo el ciclo de nómina se configura en **Configuración → "Nómina & Periodicidad"**, no en este módulo.

**Quién lo ve y desde qué plan**
- Roles: solo el **administrador** de la empresa puede usarlo (datos fiscales, CSD, timbrado e historial exigen rol admin en el servidor). El módulo puede aparecer en la barra lateral de un supervisor, pero cualquier acción le será rechazada.
- Plan: no está en el paquete base de Pro; se necesita Enterprise, o activarlo como módulo adicional desde el tablero, o estar en periodo de prueba.
- Configuración → "Nómina & Periodicidad": administrador (el módulo Configuración no se muestra a supervisores).

**Pestaña "Fiscal / CSD"**

1. **Cédula de Identificación Fiscal.** Formulario con Razón Social (SAT), RFC (12 o 13 caracteres, se guarda en mayúsculas), Régimen Fiscal (601, 603, 605, 606, 612, 626) y Código Postal Fiscal.
   - Cómo se usa: llena los cuatro campos → "Guardar Datos Fiscales" → aparece "Datos fiscales actualizados con éxito". Con RFC guardado, el sistema intenta dar de alta la organización de tu empresa en Facturapi en segundo plano (si el proveedor no está configurado, esa parte se omite sin error).

2. **Certificados SAT (CSD).** Carga del archivo .cer, del archivo .key y de la contraseña de la llave.
   - Cómo se usa: "Buscar" junto a "Archivo Certificado (.cer)" → "Buscar" junto a "Archivo Llave Privada (.key)" → escribe la "Contraseña de la Llave CSD" → "Sincronizar y Subir al SAT". El botón se habilita solo con los tres datos.
   - Resultado: si la sincronización con el PAC funciona, "Sellos Digitales (CSD) cargados e integrados…"; si no, los certificados quedan guardados en tu empresa y la pantalla lo dice ("guardados localmente…"). Si ya había certificados guardados, la pantalla los muestra como "certificado_guardado.cer" / "llave_guardada.key".

**Pestaña "Timbrado de Nómina"**

3. **Tabla del periodo cerrado.** Carga automáticamente la nómina del último periodo cerrado (misma fuente que "Reportes IA → Nómina y Avanzados"), con la etiqueta "Periodo del … al …" y el conteo "N Colaboradores · N listos para timbrar". Columnas: Colaborador, RFC / CURP (si no hay RFC dice "Sin RFC (se usará genérico)"), Neto del Periodo (el neto **guardado** en la nómina firmada/autorizada; "Salario sin capturar" si falta el sueldo), Deducciones, Estado Nómina (🟡 Sin firma del colaborador → 🔵 Firmada · falta autorizar → 🟢 Autorizada), Estado Timbrado (Pendiente Timbrar / Timbrado SAT con folio / Falló Timbrado con el motivo) y Acciones.

4. **Timbrar Selección.** Solo se pueden marcar las nóminas **Autorizadas y sin folio fiscal**; las demás casillas están deshabilitadas. La casilla del encabezado marca todas las timbrables.
   - Cómo se usa: marca las casillas → "Timbrar Selección (N)" → avanza una barra de progreso; al final se informa "Timbrado completado: N de N" o "Se timbraron X de N…" y la columna Estado Timbrado muestra el folio (UUID) o el error por renglón.
   - El monto que se timbra es el neto guardado de la nómina autorizada; la pantalla no puede alterarlo. La periodicidad del CFDI sale de la configuración de la empresa (semanal = 02, quincenal = 04, mensual = 05) y los días del periodo son los reales del recibo.
   - Una nómina timbrada no se vuelve a timbrar (el sistema lo rechaza indicando la fecha del timbre anterior).

5. **Imprimir Ticket 80 mm.** Icono de impresora en "Acciones" de cada renglón: descarga un PDF tamaño ticket (80 mm) con la nómina del colaborador para el periodo. Funciona aunque no haya PAC configurado; no es un comprobante fiscal.

**Pestaña "Historial SAT"**

6. **Facturas y Recibos Emitidos.** Botón "Sincronizar Historial" que consulta al proveedor y lista Folio Fiscal (UUID), Receptor, RFC, Fecha de Emisión, Monto Total y Estado (Vigente / Cancelado). Sin llave del proveedor, muestra el error real ("No se pudo consultar el historial del proveedor fiscal") en lugar de datos inventados.
   - **En construcción:** los iconos "Descargar PDF", "Ver XML" y "Cancelar Factura" de la columna Acciones de esta tabla no hacen nada todavía (no tienen acción detrás). No listarlos como funciones.

**Configuración → "Nómina & Periodicidad" (administrador)**

7. **Periodicidad de pago.** Tres tarjetas: Semanal (SAT 02, un recibo por semana laboral), Quincenal (SAT 04, del 1 al 15 y del 16 al fin de mes) y Mensual (SAT 05, mes calendario). Además, "Día de inicio de la semana laboral" y "Día de pago" (Domingo a Sábado).
   - Cómo se usa: elige la tarjeta → ajusta día de inicio y día de pago → "Guardar Configuración de Nómina". Aparece "Configuración de nómina guardada. Los cambios aplican a partir del siguiente periodo."
   - Si la empresa nunca lo ha declarado, aparece un aviso ámbar: el sistema asume **semanal** hasta que confirmes o cambies y guardes.

**Reglas que conviene saber**
- Ciclo completo de una nómina: (1) el generador nocturno calcula el último periodo cerrado; (2) el colaborador firma de conformidad en su Reloj Checador; (3) el administrador autoriza en Reportes IA → Nómina y Avanzados; (4) aquí se timbra. Nada se timbra si no está autorizado; nada se autoriza si no está firmado.
- El cambio de periodicidad aplica desde el siguiente periodo: los recibos ya generados o firmados no se modifican, y el sistema no genera recibos nuevos sobre días que un recibo firmado ya cubre (sin doble pago).
- El día de inicio de semana define el corte semanal y, en quincenal/mensual, las semanas con las que se calcula el séptimo día.
- El CFDI de nómina usa el RFC y la CURP del expediente del colaborador; si faltan, se envían valores genéricos (RFC XAXX010101000). Conviene capturarlos en RRHH antes de timbrar.
- Datos fijos que hoy manda el sistema al timbrar y no se editan desde pantalla: tipo de nómina ordinaria, contrato por tiempo indeterminado, régimen de sueldos, riesgo clase I, banco 012 y una cuenta genérica si el colaborador no tiene CLABE. Si tu empresa requiere otros valores, todavía no hay dónde cambiarlos.
- Los datos fiscales y el CSD son la firma fiscal de la empresa: por eso no se delegan a supervisores.

**Configuración externa pendiente**
- **Llave de Facturapi (FACTURAPI_KEY) en el servidor.** Sin ella no hay timbrado ni historial: el timbrado devuelve error del proveedor y el historial muestra "No se pudo consultar…". Además hay un pendiente de red del servidor hacia Facturapi (TLS) reportado en la bitácora de nómina. Todo lo demás del módulo (datos fiscales, guardar CSD localmente, ticket 80 mm, periodicidad) funciona sin la llave.

---

## Matrix QA (Simulador)

**Qué es.** En la barra lateral aparece como **Matrix QA** ("Entorno de simulación"). Es un **simulador del Reloj Checador** con celulares virtuales y una "máquina del tiempo" para probar cómo se comporta el dial a distintas horas y con distintos puestos, sin esperar al día real. **Solo administrador** (y admin de plataforma). Plan **Pro** o superior.

**Funciones.**
1. **Celulares virtuales**: uno por colaborador; cada uno muestra su dial como lo vería esa persona.
2. **Máquina del tiempo**: adelanta o atrasa la hora simulada para ver cada estado del dial (cerrada → abrir → entrada → comida → salida).
3. **Modo Sandbox vs Producción**: en **sandbox** las marcas del simulador se guardan aparte y **nunca** entran en nómina ni reportes (llevan una marca de sesión de simulación). Si el sandbox está apagado, la pantalla lo advierte en rojo: cualquier fichaje ahí escribe en la base **real**.
4. **Ver logs de la sesión** de simulación.

**Reglas que conviene saber.**
- Ya **no pide ninguna "clave de seguridad"** para entrar (era una cadena fija en el navegador que no protegía nada; el control real es el rol).
- Los fichajes de simulación se distinguen siempre de los reales; los reportes y la nómina los excluyen.
- Nada de lo que hagas aquí "reinicia el día" real de la empresa (esa acción quedó reservada al administrador de plataforma).

**Depende de configuración externa:** nada.

---

## Ley Federal del Trabajo (LFT)

**Qué es.** En la barra lateral aparece como **Ley Federal del Trabajo** ("Reglamento y tolerancias"). Es donde el administrador fija **las reglas con las que se juzgan retardos y faltas** y con las que se calcula la nómina, más el calendario de días festivos de la empresa.

**Quién lo ve y desde qué plan.** Administrador (y supervisor: lo ve). Plan mínimo: **Freemium** — todas las empresas lo tienen.

**Funciones.**

1. **Tolerancias**: Tolerancia de **entrada** (minutos de gracia antes de que una entrada cuente como retardo — es la de la empresa; un puesto puede tener la suya propia en la Matriz de Ventanas de Tiempo y esa gana), tolerancia de **comida** y de **descansos**.
2. **Resolución de retardos**: cómo se trata un retardo — **descontar** (afecta nómina) o **extender turno** (repone tiempo al final).
3. **Escalas de sanción**: cuántos retardos equivalen a **1 falta**; faltas para **llamada de atención**; faltas para **suspensión**.
4. **Descuento por minuto de retardo ($ MXN)**: viene en **$0 de fábrica** (la LFT no permite descontar por minuto sin base contractual; activarlo es decisión de cada empresa). Un retardo que ya se convirtió en falta no se cobra además por minuto. Cada puesto puede tener un **multiplicador** de este descuento (ficha del puesto).
5. **Séptimo día proporcional** (casilla): el pago del día de descanso se reduce en proporción a las faltas de la semana.
6. **Días festivos**: agrega fecha y nombre; el Reloj los reconoce (dial de "Día Feriado") y la nómina paga **doble adicional** si se trabaja.
7. **Bonos** (si están activos): bono de puntualidad y bono por apertura a tiempo (se suman al neto).

**Reglas que conviene saber.**
- Cambiar una regla aplica **hacia adelante**: no recalcula fichajes ni recibos ya generados.
- La tolerancia que ve cada colaborador en su Reloj sale de aquí (o de su puesto) — nunca hay dos tolerancias distintas.
- Estas reglas son las que aparecen resumidas en Reportes IA → Nómina al calcular la prenómina.

**Depende de configuración externa:** nada.

---

## Organigrama y SOP (Wiki de procesos)

**Qué es.** En la barra lateral aparece como **Organigrama y SOP** ("Procesos, Puestos y Wiki"). Es la **wiki interna** de la empresa: manuales de procedimiento por puesto, documentos de operación (SOP), y una **bóveda pública** opcional donde la gente entra con registro propio a leer lo que la empresa publique.

**Quién lo ve y desde qué plan.** Administrador y supervisor. Plan **Pro** o superior. La bóveda pública la ve quien tenga la liga y se registre.

**Funciones.**

1. **Documentos y carpetas** en formato de texto enriquecido (estilo Obsidian): crear, editar, organizar por carpetas, buscar.
2. **Sincronizar desde local o ZIP**: subir una carpeta de notas o un archivo .zip para cargar muchos documentos de golpe.
3. **Vincular a puestos**: cada documento puede ligarse a un puesto para que sea "el manual" de ese puesto (aparece desde el organigrama de RRHH).
4. **Bóveda pública (org-vault)**: activar una dirección pública `…/vault/<slug>` donde cualquiera se **registra con su correo** y entra a leer los documentos marcados como públicos. Es solo lectura hacia afuera.
5. **Ayuda con IA** dentro del editor (resumir/redactar): **solo si hay llave de IA**; sin ella los botones no operan.

**Reglas que conviene saber.**
- La cuenta de la bóveda pública es **independiente** de la de la empresa: alguien registrado ahí **no** puede entrar a ningún otro módulo (se cerró un hueco al respecto en la auditoría de agosto).
- El organigrama visual (árbol y carriles de mando) vive en **Directorio Digital → Organigrama**, no aquí; este módulo es la parte documental.

**Depende de configuración externa:** IA opcional — usa la llave de OpenAI del servidor (o una de Gemini propia de la empresa si la configuró en el manual).

---

## Configuración

**Qué es.** En la barra lateral aparece como **Configuración** ("Ajustes del sistema"). Reúne los ajustes de toda la empresa. **Solo administrador** (los supervisores no lo ven).

**Pestañas y qué se ajusta en cada una.**

- **General / Perfil de empresa**: nombre, dirección, teléfono, **horario de operación de la sucursal** (apertura/cierre por día — de aquí heredan su horario los colaboradores nuevos), logo, y **Retención del Chat de Equipo** (1 a 30 días; 7 por defecto).
- **Onboarding & Expedientes**: textos de bienvenida de la invitación, cómo se entrega la invitación (WhatsApp / enlace), asistente inicial del giro (catálogo: retail, restaurante, oficina, taller — carga puestos, rutinas, cursos y vacantes de ejemplo; se puede volver a aplicar sin duplicar).
- **Reloj & Asistencia Global**: modo de fichaje (rápido sin GPS / GPS perimetral con **geocerca** y radio en metros, con captura de la ubicación de la sucursal), **foto de fichaje**, kiosco, IP permitida, ventana de "Ya llegué", **Curso de Puntualidad Obligatorio** (el de los 3 retardos), evaluación de clima al salir.
- **Apertura de Sucursales**: quiénes son portadores de llaves (titular/suplente por sucursal), bono de apertura, tolerancia de apertura antes de habilitar la apertura de emergencia.
- **Comedor & Reservaciones**: minutos de comida, minutos mínimos de trabajo antes de comer, reservación por franjas y aforo, evitar que dos del mismo puesto coman a la vez, foto de evidencia.
- **Tareas y Rutinas Globales**: si las tareas exigen validación del supervisor, umbral, si se permite rechazar.
- **LFT & Reglas Laborales**: acceso directo al módulo LFT.
- **Nómina & Periodicidad**: semanal / quincenal / mensual, día de inicio de semana y día de pago (ver Nómina CFDI).
- **Notificaciones & Alertas**: qué avisos se muestran (en pantalla; el correo saliente depende del proveedor pendiente).
- **Portal ATS & Vacantes**: acceso a la configuración del portal público de empleos.
- **Módulos activos**: encender/apagar módulos a la carta (también desde el carrusel del Monitor).

**Reglas que conviene saber.**
- Los cambios de reglas (tolerancias, comidas, periodicidad) aplican **hacia adelante**; nada se recalcula hacia atrás.
- La retención del chat solo acepta de 1 a 30; un valor inválido lo rechaza el sistema.

**Depende de configuración externa:** el envío real de correos (invitaciones, restablecimiento) — hoy el correo saliente **no está conectado**; todo lo demás funciona.

---

## Panel de Plataforma (solo Talent360)

**Qué es.** Es el panel del **administrador de la plataforma** (la cuenta de Talent360, no la de una empresa cliente), en `/superadmin`. No aparece en la barra lateral de las empresas.

**Funciones.**
1. **Empresas (tenants)**: lista, plan de cada una, activar/suspender (una empresa suspendida no puede entrar: "Empresa suspendida"), editar datos y **restablecer la contraseña del administrador** de una empresa (la persona deberá cambiarla al entrar).
2. **Impersonar** a una empresa para dar soporte (entrar como su administrador).
3. **Respaldos**: exportar/reponer los datos de una empresa (reponer **no borra**: actualiza; y el archivo no incluye contraseñas ni secretos).
4. **Kill-switch**: apagar el acceso de una empresa de inmediato.
5. **Bitácora de seguridad**: eventos de inicio de sesión, bloqueos, alertas del asistente de reportes, etc.
6. **Tickets de soporte y sugerencias** que mandan las empresas desde el Monitor.
7. **Módulos y catálogos**: qué módulos puede tener cada plan; el catálogo del asistente del giro.

**Reglas que conviene saber.**
- **Ningún despliegue ni acción del panel borra empresas** (se retiró la purga que existía).
- El respaldo diario del servidor (base de datos + archivos + configuración, de ambas instancias) es automático y **ya se probó restaurando**; su copia fuera del servidor es interina hasta decidir el destino en la nube.

---

## Inicio de sesión y seguridad de la cuenta

- **Cambio forzado de contraseña**: si tu contraseña la puso otra persona (RRHH, el admin de plataforma) o es una de las conocidas de fábrica, al entrar verás **"Crea tu nueva contraseña"** y no podrás hacer nada más hasta elegir la tuya. Al cambiarla, **se cierran las demás sesiones** abiertas con la contraseña vieja. Nunca se aceptan `password123`, `123456` ni `Master`.
- **Sesión abierta en un dispositivo compartido**: si en la tableta alguien dejó su sesión, la pantalla de entrada lo **avisa** y te deja continuar con esa sesión o entrar con la tuya (lo que cierra la anterior). No te mete solo a la cuenta ajena.
- **Verificación en dos pasos (2FA)**: se activa desde Perfil; al entrar pide el código de la app autenticadora.
- **Olvidé mi contraseña**: genera un enlace de restablecimiento que **hoy no llega por correo** (el correo saliente está pendiente); mientras tanto, un administrador restablece desde RRHH (con cambio forzado) o desde el panel de plataforma.
- **Kiosco**: entrada especial de 15 minutos por PIN para la tableta de la tienda.
- **Bloqueo por intentos**: demasiadas contraseñas equivocadas bloquean temporalmente ese correo (y una lluvia de correos inexistentes bloquea la dirección de origen), sin afectar a las cuentas legítimas.

---

*Generado el 2026-08-13 a partir del código en `main` (`7894d1a`). Cuando un módulo cambie, hay que actualizar su sección aquí — este documento vale mientras diga lo que el sistema hace, no lo que se quisiera que hiciera.*
