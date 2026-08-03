# Catálogo único: receta, cronograma y dueño

**Fecha:** 2026-08-03 (lunes)
**Responde a las tres condiciones puestas por el responsable de producto**: receta exacta de
cambios en el frontend, endpoint listo *antes* de tocar el wizard, y plan para los giros vacíos.

Acompaña a `CONTRATO_API_CATALOGO_2026-08-03.md`, que define la forma de la respuesta.

---

## 1. Receta exacta de cambios en `OnboardingWizard.tsx`

**El truco que hace esto pequeño:** `activePreset` (línea 415) es hoy una constante derivada, y se
usa en **17 lugares del JSX** (líneas 1150–1399). Si `activePreset` **conserva su nombre y su
forma** —`{ puestos, tareas, cursos, vacantes }`— y sólo cambia de dónde sale su contenido, esos
17 usos no se tocan.

No es que no existan: es que se dejan intactos a propósito.

| # | Línea | Hoy | Queda |
|---|---|---|---|
| 1 | **415** | `const activePreset = PRESET_DATA[selectedNicho] \|\| PRESET_DATA.retail;` | `activePreset` sale de estado. `puestos` y `tareas` del servidor; `cursos` y `vacantes` siguen saliendo de `PRESET_DATA`. **Misma forma exacta.** |
| 2 | **416-418** | `useState(() => activePreset.puestos.map(...))` ×3 | Arrancan en `[]`. Se llenan cuando llega el catálogo (punto 4). |
| 3 | **424-427** | 4 líneas dentro de `handleSelectNicho` que rellenan la selección | Se borran. Lo hace el efecto. Las líneas 422-423 y 428-430 (`setSubStep`, `SUB_NICHOS`) **no se tocan**. |
| 4 | *nueva* | — | Un `useEffect` sobre `selectedNicho`: pide el catálogo, arma `activePreset`, preselecciona los tres arreglos. |
| 5 | **621** | `const preset = PRESET_DATA[selectedNicho] \|\| PRESET_DATA.retail;` | Usar `activePreset`. Los filtros de **624 y 627 no cambian ni una letra**. |
| 6 | *nueva* | — | Una guarda de "cargando" antes del bloque que consume `activePreset`, para que no se pinte con listas vacías mientras llega la respuesta. |

**No se tocan:** las 17 referencias del JSX (1150–1399), los cursos, las vacantes, el stepper, los
sub-nichos, la bienvenida por plan, ni el caso `custom`.

**Sobre `custom`:** hoy cae en `|| PRESET_DATA.retail` y al enviar manda `undefined` en puestos y
tareas. El endpoint replica ese comportamiento —giro desconocido devuelve retail— para que el caso
siga igual.

**Estimación: ~2 horas**, incluida la prueba manual del wizard completo. Es una estimación sobre
trabajo ajeno, hecha leyendo el archivo; quien lo escribió juzgará mejor.

**Lo único que no es mecánico** es el punto 6. Hoy el catálogo está disponible en el mismo
instante en que se pinta el componente; con la llamada hay una espera de por medio, y hay que
decidir qué se ve mientras tanto y qué se ve si la red falla.

---

## 2. Cronograma — el endpoint primero, siempre

La condición era: **no dejar el wizard a medias esperando una API.** Por eso el orden es éste y no
al revés. En ningún momento hay un día en que el wizard esté desarmado.

| Día | Quién | Qué | Estado del wizard al terminar |
|---|---|---|---|
| **Lun 03** | yo | Mover el catálogo de PHP a un archivo de datos (JSON) y que `configureNicho` lo lea de ahí. Sin cambio de comportamiento. | **Funciona igual que hoy** |
| **Mar 04** | yo | El endpoint `GET /admin/onboarding/catalogo` + pruebas + **desplegado en la V2**. | **Funciona igual que hoy** |
| **Mar 04, tarde** | yo → él | Aviso con la URL viva para que la pruebe con el navegador antes de escribir una línea. | **Funciona igual que hoy** |
| **Mié 05** | él | Los 6 cambios de la receta (~2 h). Yo disponible ese día por si algo no encaja. | **Funciona con el catálogo del servidor** |
| **Jue 06** | yo | Guardarraíl: rechazar tareas sin `estimated_mins` en vez de asumir 15 min. | — |

El día 3 el endpoint ya lleva 24 horas desplegado y probado. Si algo falla ese día, el wizard
vuelve a su versión anterior con revertir un commit: el `PRESET_DATA` sigue en el archivo hasta
que la nueva ruta esté confirmada.

**Después** (sin fecha atada, porque depende de una decisión de producto, no de código): los giros
vacíos —punto 4 de este documento— y la periodicidad de nómina.

---

## 3. Quién es dueño del catálogo

La pregunta fue: *si necesito agregar una tarea a repostería, ¿la meto directo o abro un ticket?*

**Directo. Sin ticket, sin PHP, sin pedir permiso.**

Esa es justamente la razón de que el día 1 del cronograma sea *"mover el catálogo a un archivo de
datos"* y no *"escribir el endpoint"*. Si el catálogo se queda dentro del código PHP, servirlo por
API resuelve la duplicación pero **empeora** el problema de propiedad: hoy al menos está en el
frontend, donde él lo edita solo.

La repartición queda así:

| | Dueño | Qué decide |
|---|---|---|
| **Contenido** — qué tareas, en qué orden, cuántos minutos, de qué puesto | **producto** | Se edita en `Backend/resources/catalogos/*.json`. Ningún desarrollador de por medio. |
| **Forma** — qué campos son obligatorios y qué significan | **backend** | Cambiar la forma sí requiere código, porque hay cálculos colgando de ella. |

Y para que editar sin desarrollador no signifique editar a ciegas, una prueba automática valida
cada archivo: que toda tarea traiga `estimated_mins` y `momento`, y que su `target_role_name`
corresponda a un puesto que exista en ese mismo giro. Si una tarea queda mal, **lo dice la prueba
en el momento, no un cálculo de nómina tres semanas después**.

**El único costo honesto:** un archivo del repositorio requiere desplegar para que surta efecto.
Hoy eso es un comando (`deploy-v2`) y tarda alrededor de un minuto. Si con el tiempo resulta que
el catálogo se toca a diario, lo correcto será moverlo a base de datos con pantalla de
administración —y entonces se edita en caliente—. No conviene construir eso ahora sin saber si se
va a usar.

---

## 4. Los giros vacíos

La observación fue la más importante de todas, y es correcta: **un dueño de restaurante que
termina el asistente con 3 tareas no compra.** Eso pesa más que la duplicación, y ninguna
arquitectura lo tapa.

Dos cosas conviene separar:

**Por qué están vacíos.** No es descuido: hasta hoy llenar un giro significaba escribir arreglos
de PHP dentro de un controlador de miles de líneas. Sólo un desarrollador podía hacerlo, y el
desarrollador no sabe cómo se abre un restaurante. **El cambio del día 1 quita exactamente ese
obstáculo:** a partir de ahí, llenar un giro es editar un archivo de texto con una estructura
repetitiva, y lo puede hacer quien conozca el negocio.

**Quién los llena.** Se puede redactar un borrador —la estructura de las 92 de repostería sirve de
molde y las rutinas de apertura y cierre de un restaurante son conocidas—, pero un borrador no es
un catálogo: alguien que conozca el giro tiene que validarlo antes de ponérselo enfrente a un
cliente que paga.

**Propuesta, para no prometer a ciegas:** redactar **un solo giro** —restaurante, el que se
mencionó— y pasarlo a revisión. Con eso se sabe dos cosas que hoy nadie sabe: cuánto tarda
redactar uno y, sobre todo, **cuánto tarda revisarlo**, que es el cuello de botella real. Con ese
dato se decide si seguir con los otros tres, si se contrata a alguien del giro, o si el asistente
directamente no ofrece giros que no estén trabajados.

Comprometer los cuatro giros hoy, sin ese dato, sería inventar una fecha.

---

## 5. Nómina — periodicidad

Aprobado seguir: guardar el salario en diario y preguntar la periodicidad al capturar.

La condición puesta fue clara y es la correcta: **el campo de periodicidad tiene que estar visible
en la interfaz antes de dar de alta a otro cliente.** Una empresa quincenal a la que el sistema le
genera cuatro recibos semanales al mes no es un problema técnico.

No entra en el cronograma de arriba porque el catálogo bloquea a otra persona y esto no bloquea a
nadie: DecorArte paga semanal y el sistema acierta. Va inmediatamente después, y antes de cualquier
alta nueva.

Detalle completo en `NOMINA_PERIODICIDAD_MULTIEMPRESA_2026-08-02.md`.
