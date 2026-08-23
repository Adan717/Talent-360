# Guion de prueba de punta a punta

Para probar el sistema como si fueras un cliente real, empezando con la empresa ya creada y el
giro ya elegido. El orden **importa**: cada fase deja los datos que la siguiente necesita.

Cada paso dice **qué hacer** y **qué debe pasar**, para que puedas distinguir un defecto de un
comportamiento correcto. Al final hay una lista de cosas que **parecen defectos y no lo son** —
léela antes de empezar, te ahorra media prueba.

---

## Fase 0 · Poner las reglas antes de generar datos (10 min)

Todo lo que midas después se mide contra esto, y **corregirlo más tarde no recalcula lo ya
generado** (es un patrón que ya nos mordió tres veces en este proyecto). Así que primero:

1. **Configuración → Datos Generales**: nombre, dirección, teléfono y el **horario de la tienda**.
   Guarda y **recarga la página**: los valores deben seguir ahí.
2. **Configuración → Reloj y Ley Silla**: la **tolerancia de retardos** (minutos de gracia) y los
   parámetros de Ley Silla. Anota la tolerancia que pusiste — la verificas en la fase 4.
3. **Configuración → Nómina & Periodicidad**: semanal, quincenal o mensual. Decide ahora.
4. **Configuración → Comidas**: minutos y horario del comedor.

> **Qué debe pasar:** cada pestaña confirma en verde y, al recargar, conserva lo guardado. Si algo
> se pierde al recargar, eso **sí** es un defecto: anótalo con la pestaña y el campo.

---

## Fase 1 · Estructura: puestos (10 min)

**RRHH → Puestos** → crea al menos tres: uno de mando (con gente a cargo) y dos operativos
distintos. Ponles horario, día de descanso y minutos de comida.

**RRHH → Organigrama**: deben aparecer colgando según quién reporta a quién.

---

## Fase 2 · Gente (15 min)

**RRHH → Alta de Colaborador**, al menos tres personas:

- Una **supervisora** (nivel Supervisor) con el puesto de mando.
- Dos **colaboradores** operativos. A uno ponle **acentos en el nombre** a propósito (María
  Núñez): fue un defecto real y conviene confirmar que sigue cerrado.

**Qué debe pasar:**
- El correo de acceso se genera solo, sin acentos, con el dominio de la empresa.
- **No te pide contraseña**: la fija la persona al activar su cuenta.
- Si eliges un puesto con gente a cargo y dejas nivel "Colaborador", te **sugiere** subirlo a
  Supervisor. Es un aviso, no un bloqueo.
- Si dos personas generan el mismo correo, te **pregunta** si es la misma persona. Nunca pisa la
  ficha del primero sin preguntar.

Completa la ficha de uno: pestaña **Personal** (CURP, RFC, NSS) y **Laboral** (**salario base** —
sin esto la nómina lo marca "Pendiente" y lo excluye de los totales).

---

## Fase 3 · Activación — el paso que todo el mundo olvida (10 min)

Sin esto **nadie puede fichar**, y es donde más gente cree que el sistema está roto.

Ficha del colaborador → **Accesos** → **Generar Código de Invitación**. Sale un PIN de 6 dígitos,
un enlace y un QR.

Ahora entrégalo **tú**: el botón de WhatsApp abre la aplicación con el mensaje y el PIN ya
escritos, y tú pulsas enviar. O copia el enlace, o escanea el QR con el móvil.

Abre ese enlace (en el móvil, o en una ventana de incógnito) → escribe el PIN → confirma nombre y
foto → **elige contraseña** (mínimo 8; rechaza las conocidas tipo `password123`).

**Qué debe pasar:** el PIN se consume al usarse. Si lo reutilizas ya no sirve: hay que generar
otro. Repite la activación con al menos dos personas — las necesitas fichando.

---

## Fase 4 · El Reloj Checador, el corazón del sistema (30 min)

Con dos cuentas ya activadas. Lo ideal es hacerlo desde dos dispositivos (o dos navegadores) para
ver el Monitor en vivo desde el tuyo.

1. **Apertura de sucursal** (si asignaste portador de llaves en Configuración → Apertura): que el
   portador abra. Fíjate si el sistema la marca **a tiempo** o tarde.
2. **Entrada a tiempo** con el colaborador A.
3. **Entrada TARDE a propósito** con el colaborador B, pasada la tolerancia que anotaste.
   **Verifica que el dial diga la misma tolerancia que luego cobra la nómina** — que no
   coincidieran fue un defecto real: el dial prometía 15 minutos y el servidor cobraba desde 10.
4. **Comida**: inicio y fin. Excédete a propósito con uno para generar exceso.
5. **Descanso de Ley Silla** con el otro.
6. **Salida** de ambos. Deja a uno **sin checar salida** a propósito: el sistema debe cerrar la
   jornada solo y **decirlo**, no inventar horas. El barrido corre **cada hora** y sólo actúa
   pasada la hora de cierre de la sucursal, así que no lo esperes al minuto: la salida queda
   marcada `auto_closed` y con una alerta 🔴 de auditoría a nombre de esa persona.
7. **Cierre de la sucursal**: declara el cierre con el encargado. Si luego necesitas volver a
   abrir el mismo día, se puede (por ejemplo, cerraste por error).

> **La prueba cruzada más valiosa:** apunta en papel a qué hora entró cada quien y cuántos minutos
> tarde. Lo vas a contrastar contra los reportes y la nómina.

---

## Fase 5 · Tareas (20 min)

**Tareas IA → Nueva tarea.** Prueba el campo de IA: describe la tarea con tus palabras y pulsa
**Generar** — debe llenarte el formulario completo (título, minutos, prioridad, categoría, puesto,
objetivo y pasos). Revisa que lo propuesto tenga sentido y corrígelo.

Asigna la tarea a un puesto. Desde el reloj del colaborador: iniciar, pausar, terminar **con
foto**. Desde tu sesión de supervisora: **validar una y rechazar otra** con comentario.

**Qué debe pasar:** el ciclo de validación depende de lo que dejaste en Configuración → Tareas.
Las monedas y los puntos se abonan **al validar**, no al terminar.

---

## Fase 6 · Justificantes: la conexión reloj ↔ nómina (10 min)

Del retardo que provocaste en la fase 4: que el colaborador pida un **justificante**, y apruébalo.

**Qué debe pasar:** ese retardo deja de cobrarse. Lo verificas en la fase 10 — el reporte de
"Retardos y Faltas" debe mostrar **uno menos** que el de Asistencia. Esa diferencia no es un
error: es la prueba de que las exenciones funcionan.

---

## Fase 7 · Academia (15 min)

**Academia** → revisa los cursos que cargó tu giro. Como colaborador: entra a un curso, haz el
examen, saca el certificado. Copia el **folio** y verifícalo en la página pública de certificados
**sin iniciar sesión**.

> **Ojo:** el examen exige **el 100 % de respuestas correctas**. No es un defecto, es la regla
> actual del sistema.

---

## Fase 8 · Archivo Digital (10 min)

Sube documentos del expediente de un colaborador (PDF, JPG o PNG, hasta 10 MB). Como
administradora: **valida uno y rechaza otro con motivo**.

**Qué debe pasar:** el expediente son **6 documentos fijos**. Un rechazado cuenta como faltante y
hay que volver a subirlo.

---

## Fase 9 · Reclutamiento (20 min)

**Bolsa de Trabajo** → crea una vacante y publícala. Abre el **portal público** en incógnito y
postúlate como candidato. Vuelve al tablero, mueve al candidato de etapa y **contrátalo**.

**Qué debe pasar:** al contratar se crean su ficha y su cuenta en RRHH. Comprueba que aparece en
el directorio y que no tocó a nadie de otra empresa.

---

## Fase 10 · Reportes: donde se comprueba que todo cuadra (30 min)

Ya tienes datos de verdad. **Reportes IA → Reportes Operativos**:

1. **Asistencia** en Excel, contra lo que anotaste en papel.
2. **Retardos y Faltas**: el retardo justificado **no** debe aparecer.
3. **Horas Trabajadas**: a quien dejaste sin checar salida debe decirlo en Observación, no
   inventarle horas.
4. **Comedor y Ley Silla**: el exceso de comida y el descanso.
5. **Cumplimiento de Rutinas** y **Tareas Completadas**: deben cuadrar con lo que validaste.
6. El **asistente por frase**: *"los retardos de esta semana"*, *"quién abrió tarde"*, *"cómo va
   el reclutamiento"*. Debe entender, llenar las fechas y **no descargar solo**.
7. Los **tres formatos** en un mismo reporte: Excel (encabezado fijo, filtros, notas en su
   pestaña), CSV y PDF (portada con empresa y periodo).

> **Trampa conocida:** hay un límite de **30 descargas por minuto**, contando todos los reportes y
> los tres formatos juntos. Si bajas muchos seguidos, los últimos fallan. Espera un minuto.

---

## Fase 11 · Nómina: lo que NO se puede probar el día 1

**Es una limitación real, no un defecto, y conviene saberla antes de mirar la pantalla.**

La nómina trabaja siempre sobre el **último periodo CERRADO** (la semana o quincena anterior,
según lo que elegiste en la fase 0), porque es lo único que se puede firmar y autorizar: el
periodo en curso todavía puede cambiar.

Como tu empresa nació hoy, ese periodo anterior **no tiene asistencia**: verás la plantilla con
ceros y faltas. Es correcto, y es inútil para probar.

Lo que sí puedes verificar hoy:

- Que la tabla lista a la plantilla y muestra el periodo correcto en su etiqueta.
- Que quien no tiene salario capturado sale como **"Pendiente"** y **no entra en los totales**.
- Que **Exportar Excel** y **Exportar PDF** bajan ese mismo periodo y **cuadran entre sí**.
- Que **Autorizar Pago** dice cuántas autorizó y cuántas faltan de firma del colaborador
  (deberían faltar todas: nadie ha firmado).
- En **Nómina CFDI 4.0**, que la pantalla avisa que **no hay llave del PAC** en vez de fingir.

Para probarla con números reales hay dos caminos: **dejar correr unos días** de fichajes de
verdad, o **pedir que se siembre asistencia hacia atrás** en la empresa de prueba para que el
periodo cerrado tenga contenido.

> **El Simulador Matrix no sirve para esto**: sus fichajes están excluidos de los reportes y de la
> nómina real a propósito, justo para que nunca se confundan con datos verdaderos.

---

## Fase 12 · Monitor y chat (10 min)

**Monitor 360** con la gente fichada: deben verse en turno, con sus tareas y el tablero al día.
Prueba el **chat de equipo** y un mensaje privado. Comprueba que el chat aparece también cuando la
empresa está cerrada o la persona está en descanso.

---

## Cosas que parecen defectos y NO lo son

Léelas antes de reportar nada:

| Lo que verás | Por qué |
|---|---|
| **No llega ningún correo** | No hay proveedor configurado. Está pendiente de tu decisión; el sistema lo avisa, no lo esconde |
| **WhatsApp abre pero no envía solo** | No hay integración de WhatsApp. El botón abre la aplicación con el mensaje listo y **tú** pulsas enviar |
| **Facturación dice "sin llave del PAC"** | Falta la `FACTURAPI_KEY`. El ambiente fiscal lo decide el servidor, no un ajuste de la empresa |
| **La nómina sale en ceros el día 1** | Trabaja sobre el último periodo cerrado, vacío en una empresa nueva (fase 11) |
| **El examen de Academia exige 100 %** | Es la regla actual. La "calificación mínima" de Configuración no hacía nada y se retiró |
| **"Inducción vencida" no aparece hoy** | El plazo son 3 días desde el ingreso |
| **Un reporte falla si bajas muchos seguidos** | Límite de 30 descargas por minuto. Espera y sigue |
| **El PDF corta a 2,000 renglones** | Es a propósito y el propio documento lo dice. Para periodos largos, Excel |
| **Los fichajes del Simulador Matrix no salen en reportes ni nómina** | Excluidos a propósito para que no se confundan con datos reales |
| **El navegador marca el sitio como inseguro** | La instancia de prueba va por HTTP sin certificado. Algunas redes con filtrado lo bloquean del todo |
| **Una supervisora no puede validar tareas de alguien que no está debajo de ella** | Es jerárquico: valida sólo a los puestos que le reportan en el organigrama. Si tu supervisora no tiene a nadie debajo, no tendrá nada que validar |
| **Quien sale y vuelve a entrar el mismo día sí puede** | El reloj lo acepta (comprobado en vivo: salida 13:43:17, entrada 13:43:18, sin cobrarle un segundo retardo). Lo que sigue pendiente de tu decisión es cómo se paga: hoy la nómina paga **por día**, y el reporte de horas suma sólo los bloques ya cerrados y lo dice en sus observaciones |
| **Al terminar una tarea a veces se pagan las monedas y a veces no** | Depende del organigrama: si tu puesto no le reporta a nadie, no hay quién valide y se paga al terminar. Con supervisor arriba, se paga **al validar** |

---

## Cómo reportar lo que encuentres

Tres líneas bastan por hallazgo:

1. **Dónde**: módulo y pantalla exacta.
2. **Qué hiciste**: los clics, en orden.
3. **Qué esperabas y qué pasó**.

Y lo más útil de todo: **si una cifra no cuadra con otra**, dime las dos y dónde las viste. Esa
familia de defectos —dos números para el mismo dato— es la que más veces ha aparecido en este
sistema y la más cara de encontrar sin ti.
