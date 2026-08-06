# Diseño: confirmar el organigrama antes de terminar el asistente

**Estado:** propuesta para revisión. **No hay código escrito.**
**Regla que lo origina** (jefe, 2026-08-06): *"Ningún puesto se da de alta sin que el admin
confirme a quién reporta. El asistente puede sugerir, pero el admin debe aceptar o arrastrar la
línea. No más organigramas vacíos que reparamos después con comandos."*

---

## Por qué

El asistente arma el organigrama con una convención automática: cada puesto reporta **al primero**
del nivel inmediatamente superior. Nadie la revisa, y produce líneas que en operación real no
tienen sentido — en DecorArte dejó al **Asesor de Ventas colgando de Supervisor de Compras**, y al
Ayudante Integral igual. Se corrigieron a mano el 2026-08-06.

Eso importa más que la estética, porque del organigrama cuelgan dos cosas:

- **La firma del supervisor**: `TaskValidationPolicy` concluye que quien no tiene jefe no necesita
  que nadie le valide la tarea.
- **El tablero de pendientes**: los avisos de "trae la inducción pendiente" o "se atoró con un
  curso" le llegan a quien esté arriba en el organigrama. Con líneas mal puestas, el aviso le
  llega a un puesto que no conoce a la persona — o a nadie, si está vacante.

Hoy eso se repara después con `reloj:reparar-organigrama`. La regla es dejar de necesitarlo.

---

## Dónde va

Un paso nuevo en el asistente, **entre "Vista previa de rutinas" y "Guardar"**:

```
1. Giro y catálogo   2. Colaborador   …   n-1. Vista previa de rutinas
                                          n.   ► REVISA EL ORGANIGRAMA ◄   (nuevo)
                                          n+1. Guardar
```

**No se puede saltar.** El botón de avanzar del paso siguiente queda inhabilitado hasta que el
admin pulse **"Confirmar organigrama"**. No hay "más tarde" ni "omitir".

---

## Qué datos hacen falta del backend

**Casi ninguno nuevo.** `GET /admin/onboarding/catalogo` ya devuelve los puestos con `name`,
`area`, `jerarquiaLlaves` y `esAperturador`. Se le agrega **un campo por puesto**:

```jsonc
{
  "name": "Asesor de Ventas",
  "area": "Piso de Ventas",
  "jerarquiaLlaves": 3,
  "esAperturador": false,
  "reporta_a": "Supervisor de Compras"   // ← NUEVO: la sugerencia, por NOMBRE
}
```

**Por qué el nombre y no un id:** en ese momento los puestos todavía no existen en la base, no
tienen id. El nombre es la llave natural del catálogo (es la misma que ya usan las tareas con
`target_role_name`).

**Por qué lo calcula el backend y no el frontend:** la convención del "primer puesto del nivel de
arriba" ya vive en `OnboardingController::construirOrganigrama`. Si el asistente la recalculara
por su cuenta, habría **dos implementaciones de la misma regla** y tarde o temprano dirían cosas
distintas. Se extrae a un `App\Support\OrganigramaSugerido` que usen los dos: el endpoint del
catálogo para sugerir, y `configureNicho` como respaldo para clientes viejos.

---

## Qué pinta el frontend

**Se reutiliza el lienzo que ya existe**: `OrganigramaPuestos.tsx`, el de Directorio > Puestos.
Es un componente **puro** —recibe `jobRoles`, `employees` y un callback `onUpdateRole`, y no habla
con la API por su cuenta—, así que sirve tal cual con puestos que todavía no se han guardado:

| Prop | Qué se le pasa en el asistente |
|---|---|
| `jobRoles` | Los puestos del catálogo con un **id temporal** (su posición en la lista) y `reports_to_role_ids` traducido desde `reporta_a` |
| `employees` | `[]` — todavía no hay nadie dado de alta |
| `onUpdateRole` | En vez de llamar a la API, **actualiza el estado local del asistente** |
| `readOnly` | `false` |

Con eso el admin arrastra las líneas exactamente igual que en Directorio > Puestos, que es lo que
se pidió, y **no hay que escribir un lienzo nuevo**. El componente ya trae de fábrica la detección
de ciclos (recorrido en anchura antes de aceptar una conexión), así que no se puede crear un bucle
"A reporta a B que reporta a A".

**Encima del lienzo, tres cosas:**

1. Una línea de contexto: *"Así queda tu organigrama. Revísalo: de aquí sale quién autoriza las
   tareas de cada quien y a quién le avisamos cuando alguien se atora."*
2. Un aviso cuando hay puestos que la convención puso donde no toca — **no se puede detectar
   automáticamente**, así que en su lugar se marcan los que **nadie revisó**: al arrastrar una
   línea, ese puesto queda "tocado" y pierde la marca. Sirve para que el admin vea de un vistazo
   qué está aceptando tal cual.
3. El botón **"Confirmar organigrama"**.

**Validaciones antes de dejar confirmar** (todas ya calculables en el cliente):

- Exactamente **un** puesto sin jefe (la cabeza). Si hay dos, el árbol está partido.
- Ningún ciclo (lo impide el propio lienzo).
- Ningún puesto apuntando a un puesto que no está en la lista.

---

## Qué guarda al confirmar

`handleConfigureNicho` ya manda `selected_puestos` con los puestos elegidos. Se le agrega a cada
uno **el mismo campo `reporta_a`**, con lo que el admin dejó en pantalla:

```jsonc
POST /admin/onboarding/configure-nicho
{
  "nicho": "materias_primas",
  "selected_puestos": [
    { "name": "Administrador Gerente", "area": "…", "jerarquiaLlaves": 1, "reporta_a": null },
    { "name": "Asesor de Ventas",      "area": "…", "jerarquiaLlaves": 3, "reporta_a": "Supervisor de Ventas" }
  ],
  "selected_tareas": [ … ],
  "selected_cursos": [ … ],
  "organigrama_confirmado": true
}
```

En el backend, `construirOrganigrama` cambia de "calcular" a **"aplicar lo confirmado"**:

- Si el puesto trae `reporta_a`, se usa ese — resolviendo el nombre contra `roleIdsMap`, igual que
  ya se hace con `target_role_name` de las tareas.
- Si no lo trae (**cliente viejo**), se cae a la convención de siempre. No se rompe nada de lo
  desplegado.
- Se escriben **las dos representaciones**, como ya se corrigió el 2026-08-06:
  `reports_to_role_id` (una), `reports_to_role_ids` (el arreglo de la línea punteada, que es la que
  lee el tablero) y `org_parent_role_id` (la línea sólida del árbol).

**`organigrama_confirmado`** se guarda en `system_settings` junto a `nicho_configurado`. Sirve para
dos cosas: saber qué empresas pasaron por la revisión, y que el comando de reparación pueda
**saltarse** a las que ya confirmaron a mano (hoy no distingue entre "nunca se armó" y "lo armó una
persona").

---

## El candado: qué pasa si cierra la ventana

Decisión pendiente, con dos opciones:

**(a) Mínima — recomendada para la v1.** El asistente no puede *terminar* sin confirmar. Si el
admin cierra la ventana, al volver a entrar el asistente arranca de nuevo (es lo que ya hace hoy,
porque `onboarding_completed` sólo se marca al final). No queda ningún organigrama a medias, que
es el objetivo de la regla.

**(b) Con memoria.** Guardar el paso alcanzado en `system_settings.onboarding_step` y devolverlo
ahí al reabrir. Es mejor experiencia si el alta se hace en dos ratos, pero es trabajo aparte y
toca el flujo completo del asistente, no sólo este paso.

*La regla del jefe —"si cierra la ventana, el asistente se queda ahí"— se cumple con (a) en lo
esencial: no se puede avanzar sin confirmar. Lo que (a) no da es retomar donde se quedó.*

---

## Lo que este paso NO resuelve

- **Los puestos sembrados al crear la empresa** (los de `jerarquiaLlaves` 0) no salen en el
  organigrama y seguirán ahí, sueltos. Habrá que decidir aparte si se archivan.
- **Las empresas ya dadas de alta** siguen necesitando `reloj:reparar-organigrama`. El paso nuevo
  evita el problema de aquí en adelante, no repara el pasado.
- **Quién ocupa cada puesto** se define después, al dar de alta a la gente. Aquí se confirma la
  estructura, no las personas — por eso `employees` va vacío.

## Costo estimado

| Pieza | Tamaño |
|---|---|
| `OrganigramaSugerido` + campo `reporta_a` en el catálogo | chico |
| Paso nuevo en el asistente reutilizando el lienzo | mediano |
| `construirOrganigrama` aplica lo confirmado + respaldo | chico |
| Pruebas (aplica lo confirmado, no deja avanzar sin confirmar, cliente viejo sigue funcionando) | mediano |

**Dos o tres días**, no una semana, y casi todo por la reutilización del lienzo. Si hubiera que
escribir un editor de árbol nuevo, sería el doble.
