# Academia 360 — Bitácora de la auditoría

Arranque y estado previo: `ARRANQUE_AUDITORIA_2026-08-04.md` (hallazgos preliminares AC1–AC6).
Aquí se anota lo **confirmado**, con evidencia, y lo que se corrigió.

Entorno de medición: repo merge (`main`), suite en `talent360-merge-backend`, y datos reales
de la V2 en vivo (`46.225.153.115`, BD `talent360_v2_saas`).

---

## Ronda 1 — 2026-08-04

### AC7 — CRÍTICO (corregido): el registro público de la Wiki abría la API privada

La Wiki pública (`org-vault`, "La Receta Secreta") permite registrarse **sin sesión, sin
passcode y sin throttle** con solo saber el slug público de la empresa, y devuelve un token.
Ese token era un token Sanctum común y corriente; como el guard `sanctum` de este proyecto no
declara `provider`, `hasValidProvider()` lo aceptaba y **el token abría toda la API privada**.

Medido con una sonda automatizada antes del arreglo (empresa recién creada, atacante anónimo):

| Petición | Antes |
|---|---|
| `POST /public/org-vault/{slug}/register` con el `job_role_id` del puesto "Administrador" | 200, `role: "admin"` |
| `GET /api/v1/sync/state` | 200 — estado operativo completo de la empresa |
| `GET /api/v1/employees` | 200 |
| `POST /api/v1/employees` | **201 — alta de colaborador** |
| `PUT /api/v1/company/payroll-settings` | **200 — cambia la configuración de NÓMINA** |

El rol `admin` se obtenía solo: `publicRegister` lo deducía del `job_role_id` que mandaba el
propio solicitante, dándolo si el nombre del puesto contenía "administrador". Los ids son
consecutivos y no había throttle, así que se encontraban probando. Incluso sin ese truco, un
registro normal (`colaborador`) ya leía `/sync/state` y `/employees`.

**Corregido en tres capas** (`OrgVaultTokenAislamientoTest`, 3 pruebas):

1. `AppServiceProvider` — `Sanctum::authenticateAccessTokensUsing()` rechaza los tokens cuyo
   dueño es `ObsidianUser`. Es el único punto por el que pasan todas las rutas `auth:sanctum`.
   La Wiki sigue funcionando porque resuelve su usuario leyendo el token a mano.
2. `ObsidianController::publicRegister` — el registro público nace **siempre** como
   `colaborador`. El rol admin solo puede venir de `publicLogin`, que verifica la contraseña
   real del usuario de la plataforma. El puesto declarado se guarda solo si es de esa empresa.
3. Los tokens del vault se emiten con la habilidad `org-vault`, para que su origen quede
   marcado en la tabla.

**Datos ya generados:** en la V2 no hay ni un solo `obsidian_users` (0 registros, 0 admins),
así que nadie llegó a usar la puerta. No hay limpieza que hacer. Si en el futuro aparecen
registros públicos con `role='admin'`, hay que revisarlos a mano: esa combinación ya no puede
producirse por la vía pública.

### AC8 — corregido: el token de una empresa valía en la Wiki de otra

`resolvePublicUser()` devolvía cualquier `ObsidianUser` con token válido **sin mirar su
`tenant_id`**, y los ocho llamadores solo comprobaban `role === 'admin'`. Un admin de la Wiki
de la empresa A podía leer sugerencias, aprobarlas y reescribir documentos de la empresa B
cambiando el slug de la URL. Ahora la función resuelve la empresa del slug de la ruta y exige
que coincida con la del usuario, así que el candado aplica a los ocho sin depender de su orden
interno. Misma familia que H15 (una empresa escribía en la sucursal de otra).

### AC9 — corregido: las 14 rutas públicas no tenían throttle

Ninguna de las rutas `public/org-vault/*` tenía límite de peticiones: se podían martillar para
adivinar contraseñas del vault, passcodes e ids de puestos, y las que llaman a Gemini
(`copilot`, `scribe`, `exam/generate`, `exam/submit`) se pagan con la clave de la empresa o la
del `.env`. Quedan a 5/min las puertas de credenciales, 10/min la IA y 60/min la lectura —
mismo criterio que §52 en `login`/`forgot-password`.

### AC1 — confirmado: la selección de cursos del wizard es teatro, y el resultado real es peor

`handleConfigureNicho` manda `nicho`, `sub_nicho`, `selected_puestos` y `selected_tareas`, pero
**nunca `selected_cursos`**, aunque el backend lo acepta (`OnboardingController:557`). El
catálogo único que se hizo esta semana cubre `puestos` y `tareas`; los cursos siguen escritos a
mano dentro del controlador, sin catálogo. Elija lo que elija el dueño, entra la rama de
defaults del giro.

Y en vivo entra menos que eso: los dos tenants creados por el wizard en la V2 (2 "DecorArte
S.A. de C.V." y 3 "Panadería La Espiga QA") tienen **un solo curso**, el genérico "Inducción al
Software Corporativo y Gestión del Tiempo" — ni siquiera los dos cursos normativos de LFT/Ley
Silla que la rama por defecto promete. La promesa del wizard ("capacita e induce 100% en
automático") hoy entrega un curso genérico sin video.

### AC2 — confirmado en vivo: los cursos solo llegan al puesto de mando

`configureNicho` inserta todos los cursos con `target_job_role_id = $firstGerenteRole`. En la
V2, el único curso del tenant 2 apunta a "Administrador Gerente" (id 6) y el del tenant 3 a su
equivalente (id 17). El frontend filtra por ese campo (`Academia.tsx:423`), así que de los tres
colaboradores del tenant 2, **dos ven cero cursos** (Asesor de Ventas y Supervisor de
Producción). La inducción del cajero nuevo no existe.

### AC3 — CORREGIDO en la ronda 2 (abajo). Lo que se encontró:

`submitQuiz` (`Academia.tsx:229-306`) compara las respuestas en el cliente y, si aprueba,
postea `{status:'completed', score:100}`. El `score` es un literal; las respuestas del usuario
nunca viajan; el backend no puede recalificar. Además `GET /academy/courses` manda `quiz_data`
completo, o sea **las respuestas correctas viajan al navegador** antes de contestar. Los
reintentos son ilimitados (el contador de "vidas" se reinicia al reabrir el curso) y el
"bloqueo tras dos fallos, se notificó a tu Administrador" es solo texto: no hay POST ni estado
persistido. Tras aprobar tampoco hay candado: "Repasar Módulo" permite volver a postear
`completed`.

Consecuencia que cruza módulos: `ClockService::getPunctualityStatus` levanta el bloqueo por 3
retardos a partir de `completed_at` del curso de puntualidad configurado. Con lo anterior, **el
colaborador se desbloquea el checador solo**, cuantas veces quiera. El castigo por impuntualidad
es hoy voluntario.

*(Corregido en la ronda 2. El contenido de los cursos no se tocó: cambió quién califica, no qué
se pregunta.)*

### AC5 — confirmado: la inducción no se asigna sola

Nada asigna una inducción al dar de alta a un colaborador: `EmployeeController` no menciona
inducción, y `has_completed_induction` solo pinta una tarjeta de aviso en el reloj
(`RelojVisual.tsx:1329`). No hay bloqueo operativo real pese al texto que muestra la Academia
("Tu BLOQUEO OPERATIVO ha sido levantado"). El curso "de inducción" es una fila más, visible
solo para el puesto de mando (AC2).

### AC6 — confirmado en vivo: marca ajena y videos vacíos

Los cursos que inyecta el wizard nacen con `video_url` vacío — confirmado en la V2: los dos
cursos de los tenants 2 y 3 no tienen video. El `quiz_data` es siempre la misma pregunta
genérica con la respuesta en la primera opción. Y la rama de repostería/materias primas inyecta
a **cualquier** empresa del giro un curso titulado "Protocolo de Operación Comercial y Calidad
**Decorarte 360**" (`OnboardingController:754`). Las plantillas de `AcademyController` traen lo
mismo: "Inducción **DecorArte** 360" y, como video, `dQw4w9WgXcQ` (el rickroll de Rick Astley,
un marcador de prueba que quedó puesto). En la Wiki pública hay además una pantalla de
bienvenida especial condicionada al correo literal `marisoldecorarte@gmail.com`
(`WebPublicaOrganizacion.tsx:1349`); es solo cosmética (no da permisos), pero es marca del
cliente ancla dentro del producto SaaS.

### Circuito de dinero/XP — no existe, pero la interfaz lo promete

- Completar un curso **no paga** nada: ni monedas ni XP ni bono. No hay ancla anti-doble-pago
  porque no hay pago.
- `incentive_bonus_cents` se guarda y se muestra al colaborador ("Bono de incentivo de $500.00
  MXN al completarlo", `Academia.tsx:1093`), pero **nunca se paga** y el gestor del admin ni
  siquiera expone el campo para configurarlo. Es una promesa de dinero sin circuito detrás.
- `user_course_progress` sí tiene `unique(user_id, course_id)`: el progreso no se puede
  duplicar. (Observación menor: `updateOrInsert` mete `tenant_id` en la clave de búsqueda; si
  una fila vieja quedó con otro `tenant_id`, el update no la encuentra y el insert choca contra
  el unique → 500. Hoy no hay ni una fila de progreso en la V2, así que no hay datos viejos que
  arreglar.)
- Los certificados son decorativos: `window.print()` de un `div`, con fechas escritas a mano en
  el código, sin folio, sin registro en la BD y sin forma de verificarlos. Las plantillas viven
  como un JSON dentro de `system_settings`. Familia del H23 (aprobación que no guardaba nada).

---

## Estado de la V2 al momento de la auditoría

`academy_courses`: 4 cursos en el tenant 1 (sembrados, sin puesto asignado), 1 en el tenant 2 y
1 en el tenant 3. `user_course_progress`: **0 filas** — nadie ha completado nunca un curso.
`obsidian_users`: **0 filas**. El módulo está intacto: cualquier corrección que cambie cómo se
generan los datos no tiene datos viejos que reparar.

---

## Ronda 2 — 2026-08-04: AC3 cerrado (el examen se califica en el servidor)

Cambió **quién califica**, no qué se pregunta: el contenido de los cursos sigue intacto.

1. **Las respuestas correctas dejan de salir del servidor.** `getCourses` y `getCourse` quitan
   `correctAnswer`/`answer` de `quiz_data` para quien no administra cursos. El gestor
   (admin/supervisor) las sigue viendo, porque las edita.
2. **Nuevo `POST /academy/courses/{id}/quiz-attempt`**: recibe las respuestas del alumno,
   califica contra `quiz_data`, calcula el score real y escribe el progreso. Entiende los tres
   formatos que conviven en los cursos (`correctAnswer` por índice, `answer` por texto y
   `answer` por índice); si una pregunta no declara respuesta, no se puede acertar y el curso
   no se aprueba hasta que su administrador la configure.
3. **La puerta vieja se cierra donde importa**: `updateProgress`/`saveProgress` rechazan con 422
   un `status: completed` en cursos **con examen**. Los cursos sin examen se siguen completando
   viendo el video — de eso depende el gate de la Academia dentro de Tareas (§38, TaskRunner).
4. **Los intentos fallidos los cuenta el servidor** (columna nueva `failed_attempts`). Antes
   vivían en una variable del navegador que se reiniciaba al cerrar y reabrir el curso, así que
   ni el "Vidas: 2/2" ni el "se ha notificado a tu Administrador" tenían nada detrás. El texto
   que prometía un bloqueo inexistente se corrigió; **si debe haber un bloqueo real tras N
   intentos, es decisión del jefe** y hoy no lo hay.
5. De paso: el progreso se busca por `(user_id, course_id)`, que es el único índice de la tabla.
   Los métodos viejos metían `tenant_id` en la clave, así que una fila con otro tenant no se
   encontraba y el insert siguiente chocaba contra el unique (500 latente).

Efecto en el Reloj: el bloqueo por 3 retardos ya no se levanta declarándose aprobado. Hay una
prueba que lo recorre entero — tres retardos, intento reprobado (sigue bloqueado), intento
aprobado (se desbloquea).

### AC10 — corregido: el curso fantasma de Puntualidad rompía la Academia

El frontend inyectaba un "Curso de Puntualidad y Compromiso Laboral" con id 999 que solo existía
en el navegador, siempre que ningún curso real llevara "puntualidad" en el título — o sea,
**en todas las empresas creadas por el wizard**, y visible para todos los puestos
(`target_job_role_id: null`). Estaba roto de tres formas: su `quiz_data` era un texto JSON y no
un arreglo, así que abrir su evaluación reventaba la pantalla en el `.map`; no podía desbloquear
el checador, porque eso depende del progreso del curso REAL configurado en
`punctuality_course_id` y un id inventado no puede tener progreso; y le mostraba a cualquier
empresa un texto con la marca DecorArte y, como video, el rickroll de prueba. Se retiró.

**Pruebas:** `AcademiaExamenServidorTest` (7). Suite 1042/0 sqlite, 1043/0 Postgres, vitest
137/137.

---

---

## Ronda 3 — 2026-08-04: AC1 y AC2 cerrados (los cursos son del catálogo y llegan a todos)

**Los cursos salieron del PHP y entraron al catálogo del giro**, junto a puestos y tareas
(`resources/catalogos/onboarding/<giro>.json`, clave `cursos`). Es el mismo contenido de
siempre: los mismos títulos, descripciones y tipos que estaban escritos a mano en
`configureNicho`, sin inventar cursos nuevos. Ahora los edita quien conoce el negocio, sin
programar, y `CatalogoOnboardingValidoTest` avisa en el momento si algo queda mal.

**AC1 — el asistente ya manda lo que el dueño eligió.** `GET /admin/onboarding/catalogo`
devuelve también `cursos`, el wizard los preselecciona igual que puestos y tareas, y
`handleConfigureNicho` los reenvía **completos** en `selected_cursos`. Antes viajaban como mucho
títulos sueltos: el servidor sustituía la descripción por "Curso de capacitación inicial
precargado desde el Wizard de Onboarding" y adivinaba el tipo buscando las palabras "Inducción"
o "Protocolo" en el título. Un giro sin catálogo propio (el 'custom') hereda el de oficina, que
es la lista que le tocaba antes: los dos cursos de ley más la inducción al software.

**AC2 — los cursos ya no cuelgan todos del puesto de mando.** Cada curso declara en el catálogo
el `target_role_name` que debe cursarlo; si no declara ninguno, el curso queda sin puesto, que es
como la Academia lo muestra a **toda la plantilla**. Hoy todos los cursos del catálogo salen sin
puesto a propósito: la asignación fina por puesto es contenido, y el catálogo ya la soporta para
cuando producto quiera afinarla. Lo que se corrigió es el defecto: que sólo el gerente veía algo.

**De paso, dos cosas que aparecieron al hacerlo:**

- *Reaplicar el giro duplicaba los cursos.* Las tareas y las vacantes se borran antes de
  reinyectar, pero los cursos no: se acumulaban en cada pasada del asistente. Ahora se actualiza
  el que ya exista con ese título, en vez de borrar y reinsertar, **para no llevarse por delante
  el progreso** que los colaboradores ya tengan (la FK borra en cascada). Hay prueba de las dos
  cosas.
- *AC6, la mitad que sí es nuestra:* el curso "Protocolo de Operación Comercial y Calidad
  **Decorarte 360**" se le inyectaba a cualquier empresa del giro de repostería. En el catálogo
  quedó con nombre neutro, y una prueba impide que vuelva a colarse el nombre de una empresa
  cliente en el catálogo que se le carga a todas.

**Qué pasa con lo ya generado.** Los tenants configurados antes de este cambio no se reparan
solos: siguen con su curso genérico único colgado del puesto de mando. Para eso está
`php artisan academia:reparar-cursos-del-giro [--dry-run] [--tenant=]`, mismo patrón que la
reparación de rutinas de cierre (`33d15bd`): repone los cursos del giro que falten y saca del
puesto de mando los que ya estaban, **sin borrar nada** — ni los cursos que el administrador
haya dado de alta por su cuenta ni el progreso de los colaboradores (borrar un curso se lo
llevaría en cascada). Es un comando y no una migración a propósito: toca empresas vivas, así que
quien opera decide cuándo y sobre qué tenant, después de verlo en seco. Reasignar sólo AMPLÍA
quién ve el curso; nunca se lo quita a nadie.

**Pruebas:** `WizardCursosDelGiroTest` (9), `RepararCursosDelGiroTest` (5) y 5 validaciones
nuevas de catálogo por giro.

Sigue abierto de AC6: `video_url` vacío en todos los cursos del catálogo (no hay videos que
poner; es contenido) y el rickroll `dQw4w9WgXcQ` que aún vive en las plantillas de
`AcademyController::getTemplatesData`, junto con "Inducción DecorArte 360".

---

## Siguiente

1. Decisiones del jefe: si un colaborador que reprueba N veces debe quedar bloqueado (hoy el
   conteo se guarda pero no bloquea); si el bono `incentive_bonus_cents` que promete la interfaz
   ("$500.00 MXN al completarlo") debe pagarse de verdad o quitarse; y si quiere afinar el
   `target_role_name` de cada curso ahora que el catálogo lo permite.
3. Limpiar las plantillas de `getTemplatesData` (marca DecorArte + video rickroll) — es el resto
   de AC6.
4. Certificados: hoy son un `window.print()` sin folio ni registro (familia H23).
