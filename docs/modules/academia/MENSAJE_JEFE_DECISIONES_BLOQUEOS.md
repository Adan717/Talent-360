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

## Notas internas (no van en el mensaje)

- La opción 1b es barata: el contador ya se guarda en `user_course_progress.failed_attempts`;
  faltaría sólo la ventana de espera y el aviso.
- La 2b es la cara: `has_completed_induction` hoy no gobierna ninguna puerta del backend. Habría
  que meter el candado en el fichaje, y ahí es donde aparece el problema de la falta del primer
  día — el mismo patrón que ya nos mordió tres veces: el arreglo corrige el comportamiento y deja
  los datos viejos (o el día en curso) rotos.
- Si contesta la 3 con "que se pague", eso es circuito de dinero: hay que decidir quién autoriza,
  contra qué se paga y con qué ancla anti-doble-pago, como las 6 puertas de Tareas.
