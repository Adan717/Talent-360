# Plan de adopción del wizard del jefe

**Fecha:** 2026-08-02
**Estado:** propuesta, pendiente de acordar con el jefe antes de tocar código.

---

## Qué se decide aquí

El jefe reescribió `OnboardingWizard.tsx` (1 969 líneas frente a las 1 621 actuales) con una
experiencia claramente mejor. En paralelo, el backend (`OnboardingController::configureNicho`)
ganó dos cosas que antes no hacía: construir el **organigrama** y crear las **rutinas**.

Las dos piezas son buenas y hoy **no encajan**. Este documento propone cómo unirlas sin perder
ninguna de las dos, y sin tocar nada durante la ventana del piloto.

---

## Lo que aporta cada lado (verificado leyendo el código, no supuesto)

**Su wizard — mejor experiencia y mejor argumento de venta**

- Modular en 4 bloques: Giro → Puestos → Tareas → **Cursos LMS**.
- Cursos por **normativa mexicana**: LFT, Ley Silla, NOM-035, NOM-251, más cursos por giro y
  puesto. Para una PyME que teme una inspección, esto se vende solo.
- Bienvenida según el plan contratado y catálogo de los 10 módulos.
- Mobile-first, con stepper táctil en vez de listas desplegables largas.
- **El dueño elige** qué puestos y qué tareas quiere, en vez de recibir 96 impuestas.

**El backend actual — el motor**

- Construye el organigrama (`reports_to_role_id`), que es lo que **activa la validación
  jerárquica**: sin él, ninguna tarea pide firma del supervisor.
- Crea la rutina de apertura, que es lo que hace que **el checklist se reparta solo** al abrir.
- Calcula el **costo de cada tarea**: `sueldo / 480 × minutos`.
- Catálogo por giro cubriendo **6 giros** completos.

---

## El choque, en concreto

Su wizard manda al backend los puestos y tareas elegidos **desde el frontend**, y su catálogo
declara por tarea: `title, category, priority, assistant_type, assistant_prompt, target_role_name`.

Faltan dos campos, y cada uno rompe algo distinto:

| Campo ausente | Consecuencia | Gravedad |
|---|---|---|
| `momento` (apertura/cierre) | **No se crea ninguna rutina** → el módulo se queda sin automatización | Alta |
| `estimated_mins` | Toda tarea cae al valor por defecto de **15 min** → el costo de cada tarea sale mal | **Alta: es dinero** |

Ninguno de los dos falla con un error visible. El sistema sigue "funcionando" y da datos
incorrectos en silencio — la misma familia de defectos que esta ronda de auditoría vino a cerrar.

**Lo que sí encaja:** sus puestos traen `jerarquiaLlaves`, así que **el organigrama automático
funciona con su wizard sin tocar nada**.

### Y un hallazgo que decide el diseño

Su catálogo del frontend **sólo está completo para un giro**:

| Giro | Su frontend | Backend actual |
|---|---|---|
| materias_primas / repostería | 92 tareas | completo |
| retail | **4** | completo |
| restaurante | **5** | completo |
| taller | **3** | completo |
| oficina | — | completo |

Adoptar su catálogo tal cual dejaría a un cliente de restaurante con **5 tareas**. Esto no es un
argumento contra su wizard: es la prueba de que **el catálogo no debe vivir en el frontend**.

---

## Principio rector

> **El catálogo de puestos y tareas es un dato de dominio, no un artefacto de interfaz.**

Lo demuestra el propio sistema: sobre esas tareas se calcula el costo en pesos, se disparan
rutinas y se construye el organigrama. Eso es lógica de negocio.

De ahí sale la regla para todo lo que sigue: **una sola copia del catálogo, en el backend**, y el
wizard la consume. Hoy hay dos copias divergentes y ninguna es dueña; mientras siga así, cada
resincronización con el repo del jefe repetirá este mismo choque.

---

## Plan por fases

### Fase 0 — Antes de nada: acordarlo con el jefe *(no es código)*

Sin este paso, el resto genera una cuarta divergencia. Hay que acordar tres cosas:

1. Que su wizard es el que se queda.
2. Que el catálogo se sirve desde el backend y el wizard lo consume.
3. Quién hace cada fase y en qué orden.

### Fase 1 — Exponer el catálogo por API *(backend, bajo riesgo)*

`GET /admin/onboarding/catalogo?nicho=...` devuelve los puestos y tareas del giro, con **todos**
los campos: `momento`, `estimated_mins`, `jerarquiaLlaves`, `target_role_name`.

- No rompe nada: es un endpoint nuevo.
- Se cubre con tests de que cada giro devuelve su catálogo completo.

### Fase 2 — Que el wizard consuma el catálogo *(frontend)*

Sustituir el catálogo local del wizard por una llamada a ese endpoint. Se conserva íntegra su
lógica de selección, su stepper y sus cursos: **sólo cambia de dónde salen los datos**.

Al reenviar lo seleccionado, los campos `momento` y `estimated_mins` viajan de vuelta porque
vinieron del backend. El choque desaparece por construcción, no por parche.

Beneficio secundario: los 6 giros quedan completos en el wizard sin escribir un solo catálogo más.

### Fase 3 — Guardarraíl *(backend, pequeño)*

Validar en `configureNicho` que toda tarea recibida traiga `estimated_mins`. Si falta, **rechazar
con un error claro** en vez de asumir 15 minutos en silencio.

Es la lección de esta auditoría: los defectos caros no son los que fallan, son los que siguen
funcionando con datos incorrectos.

### Fase 4 — Fusionar las dos líneas de repo

Con el catálogo unificado, la fusión se limita a conflictos de interfaz. Los conocidos:

- `OnboardingWizard.tsx` → quedarse con **su versión**.
- `RecursosHumanos.tsx` → conflicto real: él lo cambió 102 líneas y ahí viven H3 (correos sin
  acentos) y H4 (selector de nivel de acceso). Hay que conservar ambos arreglos.
- `useAppStore.ts` → trivial; su cambio del subdominio por defecto es mejor que el anterior.
- Backend: **casi no lo tocó**. No hay conflicto con H21–H27 salvo el wizard.

---

## Qué NO hacer

- **No conectar su wizard al backend actual sin la Fase 1.** Produce dos regresiones silenciosas
  y una de ellas falsea el costo de nómina.
- **No tocar nada de esto durante la ventana del piloto.** El wizard sólo se ejecuta al dar de
  alta una empresa; DecorArte ya está configurada, así que esto no bloquea el piloto y puede
  hacerse después con calma.
- **No archivar su trabajo en silencio.** Ya hubo tres resincronizaciones dolorosas por construir
  en paralelo sin avisarse; ése es el coste real que hay que dejar de pagar.

---

## Nota aparte, de una línea

Su catálogo tampoco manda `estimated_mins`, y con la Fase 1 el problema desaparece. Pero si por
lo que sea se decide mantener el catálogo en el frontend, **añadir ese campo es obligatorio**: sin
él, el costo de cada tarea del sistema es incorrecto.
