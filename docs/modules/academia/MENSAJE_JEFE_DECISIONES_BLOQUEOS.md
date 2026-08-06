# Mensaje para el jefe — decisiones de bloqueo en la Academia

Redactado 2026-08-05, al cerrar la auditoría del módulo. Lo manda el usuario; aquí queda la
versión para copiar y el porqué de cada opción.

---

**Asunto: Academia — dos decisiones que necesito de ti (bloqueos)**

Hola. Ya terminamos la revisión de Academia 360 y quedan dos cosas que no nos toca decidir a
nosotros porque son de operación, no de programación. Las dos son sobre "bloquear" al
colaborador. Te adelanto algo importante: **hoy el sistema no bloquea nada**, aunque las
pantallas decían que sí. Eso ya lo corregimos, porque un colaborador leía "tu bloqueo ha sido
levantado" y se quedaba creyendo que había estado impedido de trabajar.

**1. Cuando alguien reprueba el examen de un curso**

Hoy puede reintentar las veces que quiera. El sistema ya lleva la cuenta de cuántas veces
reprobó y con qué calificación (antes esa cuenta se borraba con solo cerrar y volver a abrir el
curso, así que no servía de nada). La pantalla le decía "tu curso ha sido bloqueado y se notificó
a tu administrador": ninguna de las dos cosas pasaba.

- **a) Dejarlo como está**: intentos libres, y tú ves el contador de cada quien.
- **b) Cerrarle el curso un rato** (por ejemplo 24 horas) después de 2 o 3 intentos fallidos,
  para que repase antes de volver a presentar.
- **c) Avisarte** cuando alguien reprueba X veces.

*Mi recomendación: (a) + (c).* Bloquear el curso puede dejar atorada justo a la persona que más
necesita avanzar, y el aviso ya te da la señal para acercarte a ella.

**2. La inducción y el reloj checador**

Hoy un colaborador nuevo puede fichar aunque no haya hecho su inducción. La Academia le decía al
terminarla "tu BLOQUEO OPERATIVO ha sido levantado, ya puedes registrar tu entrada", pero ese
bloqueo nunca existió.

- **a) Seguir sin bloquear**: le aparece la inducción como pendiente y se le recuerda.
- **b) No dejarlo fichar** hasta que la complete.

*Ojo con la (b)*: si el nuevo no puede registrar su entrada, su primer día puede quedar como
falta, y eso se arrastra a asistencia y a nómina. Si la quieres, habría que definir dos cosas:
qué pasa con ese día, y cuánto margen le damos (por ejemplo, 3 días para completarla).

*Mi recomendación: (a)*, y si acaso, que el encargado vea en su tablero quién trae la inducción
pendiente.

Cualquiera de las dos queda lista en poco tiempo en cuanto decidas: el sistema ya guarda todo lo
que hace falta para medirlas.

**3. Una tercera, chica**: la Academia le anuncia al colaborador un "Bono de incentivo de $500.00
MXN" al completar cierto curso, y **no hay nada en el sistema que pague ese bono**. Por ahora lo
dejamos en cero para no prometer lo que no se cumple. ¿Lo quitamos de la pantalla, o quieres que
se pague de verdad y armamos cómo?

---

---

## RESPUESTA DEL JEFE (2026-08-05) — decidido

1. **Examen: (a) + (c). Sin bloqueo.** Intentos libres. El aviso es **obligatorio**: a la
   **segunda reprobada** le llega notificación **al encargado de área**, no sólo al admin general
   — "el que está en piso es quien puede acercarse a la persona, no el de arriba". Razón para
   descartar el bloqueo de 24 h: castiga a quien más necesita capacitarse y, si el curso es
   obligatorio por ley (NOM-035, NOM-251), impedirle reintentar expone a la empresa en una
   inspección.
2. **Inducción: (a). Sin bloqueo, con visibilidad.** No se le impide fichar — un empleado nuevo
   que no puede registrar entrada llega a su segundo día con falta y sin pago, y eso genera
   conflicto laboral antes de que aprenda a usar el sistema. Lo que sí quiere: **alerta roja en el
   tablero del encargado** ("Juan Pérez — 2 días sin inducción") y **recordatorio diario al
   colaborador** ("complétala en los próximos 3 días"). A los 3 días entra el encargado a hablar
   con él, no el sistema a castigarlo.
3. **Bono de $500: quitarlo de la pantalla, hoy.** ✅ **HECHO** (`61f14f2`, desplegado). Vuelve
   cuando haya regla de negocio clara (quién lo gana, cuánto, cuándo se paga, de qué presupuesto)
   y cable a nómina que lo ejecute automáticamente.

### Estado de construcción (2026-08-05)

- **Bono**: quitado de la pantalla (`61f14f2`). ✅
- **`hire_date` obligatoria** en el alta, con relleno de los expedientes viejos (`304da64`). ✅
  Era el requisito para poder contar "N días sin inducción".
- **Tablero de pendientes** (`bbfaf2c`): `GET /supervisor/pendientes` devuelve las dos listas
  —inducción pendiente con sus días, y cursos con 2+ reprobadas— y
  `POST /supervisor/pendientes/{id}/atendido` deja que el encargado cierre el caso. ✅ Backend.
  **Falta el panel en el frontend** que lo pinte, y el recordatorio al colaborador.

**Cómo se decidió el canal del aviso**: NO por mensajería. `internal_messages` es el Chat
Operativo del Monitor 360 —está vivo y en uso— y meterle avisos automáticos ensuciaría una
conversación entre personas. El aviso es una consulta que el tablero pide. *(De paso apareció un
bug del Reloj, sin relación con Academia: el mensaje privado del admin a un colaborador se
guarda y se devuelve, pero el reloj nunca lo muestra — `setPrivateMessages` jamás se llama con
datos del servidor. La pantalla del admin miente: cree que lo mandó.)*

### Órdenes del jefe del 2026-08-06 (tras ver el organigrama)

1. **DecorArte (tenant 2): reparación APLICADA.** 6 puestos conectados. Se le mandó el árbol
   resultante para que valide. **En tenants reales no se toca nada hasta que él valide DecorArte**
   — el tenant 3 quedó intacto a propósito.
2. **El panel va en Recursos Humanos** ("ahí está el organigrama, ahí está la gestión de gente"),
   como sección "Mi Equipo / Pendientes". No duplicar tableros. Plan B —widget en Monitor 360—
   sólo si se complica. *Verificado: el rol `supervisor` YA entra a RRHH (el filtro de módulos
   sólo le quita reportes, matrix y settings), así que no hay que darle acceso nuevo.*
3. **Recordatorio al colaborador: banner en su app, NO correo.** "Tienes 2 días para tu
   inducción. Complétala aquí." A los 3 días sin completar, el caso se pone rojo en el tablero
   del encargado. *"No quiero que el sistema castigue al nuevo; quiero que me presione a mí para
   acercarme a él."*
4. **Bug del mensaje privado del Reloj: anotado, prioridad "después del tablero".** No se toca
   ahora. *"Si mando un mensaje y el empleado no lo ve, estoy hablando solo."*
5. **REGLA NUEVA, permanente:** ningún puesto se da de alta sin que el admin confirme a quién
   reporta. El asistente puede **sugerir**, pero el admin acepta o arrastra la línea. No más
   organigramas vacíos que se reparan después con comandos.

**Orden de trabajo:** hoy la reparación de DecorArte · mañana el panel en RRHH · pasado el
recordatorio en la app · después el bug del Reloj · el paso de confirmación del organigrama se
diseña esta semana y se implementa la siguiente.

### Construido (2026-08-06)

- **Tablero "Mi Equipo"** en Recursos Humanos (`7b1e8ac`), barra de escritorio y dock móvil.
  Inducción pendiente con días y rojo al vencerse el plazo; cursos con 2+ reprobadas y botón
  "Ya hablé con él". Probado en vivo contra DecorArte.
- **Los administradores no salen del tablero** (`909237e`): al verlo con datos reales listaba a
  toda la plantilla, incluida la dueña. Los supervisores sí siguen apareciendo.
- **Banner en la app del colaborador**: *"Tienes N días para tu inducción. Complétala aquí."*,
  y al tocarlo abre la Academia. Vencido, se pone rojo. `GET /academy/mi-induccion` hace la
  cuenta en el servidor con la MISMA constante que pinta el rojo del encargado
  (`App\Support\PlazoInduccion::DIAS`), para que el colaborador y su jefe no miren relojes
  distintos. Sin fecha de ingreso (expedientes viejos) avisa igual, pero sin inventar la cuenta.
  La tarjeta de notificaciones del reloj dice lo mismo que el banner.

### Correcciones manuales sobre DecorArte (2026-08-06, aprobadas por el jefe)

La convención mecánica conectaba cada puesto con el PRIMERO del nivel superior, y ese primero
salía *Supervisor de Compras*. Dos líneas quedaban absurdas en operación real y él las corrigió:

| Puesto | Antes | Ahora |
|---|---|---|
| Asesor de Ventas (Adán Cuéllar) | Supervisor de Compras | **Supervisor de Ventas** |
| Ayudante Integral | Supervisor de Compras | **Administrador Gerente** |

Se cambiaron por `PUT /job-roles/{id}` —el mismo endpoint que usa el lienzo al arrastrar—, así que
las dos representaciones quedaron sincronizadas (`org_parent_role_id` sólida y
`reports_to_role_ids` punteada). *Apoyo Eventual* sigue bajo *Asesor de Ventas*, que no se
mencionó. Validado contra los datos que carga Directorio > Puestos: el lienzo dibuja exactamente
los 7 puestos del giro (los sembrados de nivel 0 no aparecen ahí).

### Regla 5, ya con diseño acordado

Un paso más en el asistente, **entre "Vista previa de rutinas" y "Guardar"**: *"Revisa el
organigrama"*, con el árbol propuesto completo y un botón **"Confirmar y continuar"**.
**No se puede saltar**: si cierra o pospone, el asistente se queda ahí. Arrastrar una línea
activa el botón; no tocar nada también, si el árbol ya está bien. Once confirmaciones sueltas
son tortura; una pantalla que se revisa en 30 segundos, no.

### Alta de colaborador: sugerir el rol supervisor

Si el puesto elegido tiene gente que le reporta (`reports_to_role_ids` apuntándole), el alta debe
avisar: *"Este puesto tiene personas a cargo. ¿Asignar rol de supervisor?"*. **Sugerencia, no
obligación.** Nace de una trampa real: el rol `supervisor` entra a RRHH, pero al `empleado` la app
lo manda a otra pantalla — un encargado dado de alta como empleado nunca vería su tablero por más
que el organigrama diga que manda.

### Lo que hizo falta definir antes de construir 1 y 2

- **Quién es el "encargado de área".** El sistema no tiene esa figura: tiene el **organigrama**
  (`reports_to_role_id`) y el campo `area` del puesto. Y el organigrama que arma el asistente usa
  una **convención de arranque** —cada quien reporta al *primer* supervisor del nivel de arriba—,
  así que hasta que el admin lo ajuste, el aviso puede caerle a un supervisor que no es el jefe
  real de esa persona. Propuesta: mandarlo al supervisor del organigrama y, si no tiene, al admin.
- **La fecha de alta.** "2 días sin inducción" necesita desde cuándo contar, y `employees.hire_date`
  es opcional: el alta de colaborador **no la fija**, así que hoy está vacía salvo que el admin la
  escriba. Habría que exigirla en el alta, o contar desde `employees.created_at`.

## Notas internas (no van en el mensaje)

- La opción 1b es barata: el contador ya se guarda en `user_course_progress.failed_attempts`;
  faltaría sólo la ventana de espera y el aviso.
- La 2b es la cara: `has_completed_induction` hoy no gobierna ninguna puerta del backend. Habría
  que meter el candado en el fichaje, y ahí es donde aparece el problema de la falta del primer
  día — el mismo patrón que ya nos mordió tres veces: el arreglo corrige el comportamiento y deja
  los datos viejos (o el día en curso) rotos.
- Si contesta la 3 con "que se pague", eso es circuito de dinero: hay que decidir quién autoriza,
  contra qué se paga y con qué ancla anti-doble-pago, como las 6 puertas de Tareas.
