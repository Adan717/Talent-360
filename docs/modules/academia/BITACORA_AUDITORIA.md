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

### AC3 — confirmado y más grave de lo anotado: el examen se califica en el navegador

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

*(Pendiente de decisión: la calificación server-side implica dejar de mandar `correctAnswer` al
cliente y añadir un endpoint que reciba respuestas. Toca el contenido de los cursos, que es
dominio del jefe — se propone, no se rediseña por iniciativa.)*

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

## Siguiente

1. Desplegar los tres candados de AC7–AC9 a la V2 (es seguridad viva).
2. Llevar AC1/AC2/AC6 al jefe como decisión de producto: los cursos son suyos. Propuesta
   mínima: mandar `selected_cursos` desde el wizard, mover los cursos al catálogo JSON por giro
   (como puestos y tareas), repartir por puesto en vez de todo al gerente, y sacar la marca
   DecorArte y el video de prueba de las plantillas.
3. AC3 (examen server-side) como siguiente hallazgo con test, una vez decidido el punto 2.
