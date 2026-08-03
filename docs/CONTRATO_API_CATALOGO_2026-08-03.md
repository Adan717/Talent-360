# Contrato de API: catálogo de puestos y tareas por giro

**Fecha:** 2026-08-03
**Para:** validación del responsable de producto antes de implementar.
**Alcance:** que el wizard deje de traer su propio catálogo y lo lea del servidor. **El wizard se
queda tal cual**; sólo cambia de dónde salen los datos.

---

## Por qué

Hoy existen **dos copias del mismo catálogo**: una en el frontend (`PRESET_DATA` dentro de
`OnboardingWizard.tsx`) y otra en el backend (`OnboardingController::configureNicho`). Ambas
describen los mismos puestos y tareas de repostería, y hay que mantenerlas sincronizadas a mano.

Al enviar, el wizard manda al backend las tareas elegidas **desde su copia**, y a esa copia le
faltan dos campos que el backend necesita:

| Campo ausente | Qué se rompe |
|---|---|
| `momento` (apertura/cierre) | No se crea la rutina de apertura → sin asignación automática |
| `estimated_mins` | Toda tarea cae a 15 min → el costo por tarea sale mal |

Con el catálogo servido desde el backend, esos campos **viajan de ida y vuelta solos** y el
problema desaparece por construcción, no por parche.

### Lo que este cambio NO aporta

Conviene decirlo para no vender humo: **el backend no tiene los giros más completos**. Se contaron
los dos lados:

| Giro | Frontend | Backend |
|---|---|---|
| materias_primas / repostería | 92 | 92 |
| retail | 4 | 4 |
| restaurante | 3 | 3 |
| oficina | 2 | 2 |
| taller | 5 | 4 |

Los números coinciden casi uno a uno: **no son dos catálogos distintos, es el mismo catálogo
duplicado.** Mover el catálogo al backend no gana ni una tarea.

Lo que gana es exactamente esto: **dejar de tener dos copias del mismo dato**, y que los campos
que el cálculo necesita vivan donde se usan.

*(Aparte, y ajeno a este cambio: que retail, restaurante, oficina y taller tengan 2–5 tareas es un
hueco de producto. Un cliente de restaurante hoy termina el asistente con 3 tareas, contra las 92
de repostería. Sólo un giro está trabajado de verdad.)*

---

## El endpoint

```
GET /api/v1/admin/onboarding/catalogo?nicho={giro}&sub_nicho={opcional}
```

Autenticado, rol admin. Devuelve el catálogo del giro **con todos los campos**.

### Respuesta

```jsonc
{
  "success": true,
  "nicho": "materias_primas",
  "puestos": [
    {
      "name": "Administrador Gerente",
      "area": "Gerencia General",
      "esAperturador": true,
      "jerarquiaLlaves": 1
    }
  ],
  "tareas": [
    {
      "title": "Desactivar alarma perimetral y encender switch principal de energía",
      "category": "seguridad",
      "priority": "bloqueante",
      "assistant_type": "evidencia_foto",
      "assistant_prompt": "Tome foto de la pantalla de alarma desactivada.",
      "target_role_name": "Administrador Gerente",

      // Los dos campos que hoy faltan:
      "estimated_mins": 10,
      "momento": "apertura"        // "apertura" | "cierre" | null
    }
  ]
}
```

**Los nombres de campo son exactamente los que ya usa `PRESET_DATA`**, más los dos nuevos. Eso es
deliberado: el filtrado, la preselección y el envío del wizard siguen funcionando sin tocarse.

### Qué NO devuelve, y por qué

`cursos` y `vacantes` **no van en este endpoint**. Los cursos por normativa (LFT, Ley Silla,
NOM-035, NOM-251) son aportación del wizard y no tienen equivalente en el backend; meterlos aquí
sería mover algo que hoy funciona bien donde está.

El wizard sigue tomándolos de su `PRESET_DATA`. Sólo cambian `puestos` y `tareas`.

---

## Qué cambia en el wizard

Tres puntos, todos en `OnboardingWizard.tsx`.

**1. Al elegir giro** (hoy en la línea ~424):

```ts
// ANTES
const preset = PRESET_DATA[nichoKey] || PRESET_DATA.retail;
setSelectedPuestos(preset.puestos.map(p => p.name));
setSelectedTareas(preset.tareas.map(t => t.title));
setSelectedCursos(preset.cursos.map(c => c.title));

// DESPUÉS
const { data } = await axiosInstance.get('/admin/onboarding/catalogo', { params: { nicho: nichoKey } });
setCatalogo(data);                                    // puestos + tareas del servidor
setSelectedPuestos(data.puestos.map(p => p.name));
setSelectedTareas(data.tareas.map(t => t.title));
setSelectedCursos(PRESET_DATA[nichoKey].cursos.map(c => c.title));   // los cursos siguen siendo tuyos
```

**2. Al enviar** (hoy en la línea ~621): sustituir `preset.puestos` / `preset.tareas` por
`catalogo.puestos` / `catalogo.tareas`. El `.filter(...)` no cambia.

**3. Estado de carga.** Es el único trabajo real: hoy `PRESET_DATA` está disponible de forma
síncrona y ahora hay una espera. Hace falta un indicador mientras llega y un camino para el error
de red.

**Lo que no cambia:** el stepper, la selección granular, los cursos, el diseño móvil, la
bienvenida por plan. Nada de la experiencia.

---

## Detalles de implementación (lado backend)

- El catálogo se extrae de `configureNicho` a una clase propia, para que el endpoint que lo
  **sirve** y el que lo **aplica** lean la misma fuente. Si no, volvemos a tener dos copias, sólo
  que ambas en el backend.
- `configureNicho` no cambia su contrato: sigue aceptando `selected_puestos` y `selected_tareas`.
  El wizard actual sigue funcionando durante la transición.
- **Validación:** al aplicar, si una tarea llega sin `estimated_mins`, se rechaza con un error
  claro en lugar de asumir 15 minutos en silencio. Es lo que impide que este problema vuelva por
  otra puerta.

---

## Lo que hace falta de tu lado

1. Validar que la forma de la respuesta encaja con tu wizard.
2. Confirmar que los cursos se quedan donde están.
3. Decir si prefieres que el endpoint acepte también `sub_nicho`, o si con el giro basta.

Con eso implemento el endpoint y te aviso para que cambies los tres puntos de arriba.
