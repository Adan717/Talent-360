# Auditoría Completa — Simulador Matrix QA (2026-07-22)

**Alcance:** `PanelSimulador.tsx`, `useClockEngine.tsx` (rutas no-sandbox que usa la Matrix), `useStoreOpening.ts`, `useAppStore.ts` (resolución de plan/tier), y el estado actual del contrato con Backend. Objetivo: que la Matrix se pueda usar de punta a punta sin errores, con cualquier tenant.

**Veredicto general:** el diseño de fondo es sólido (aislamiento por `simulation_session_id`, un motor de reloj completo por celular, sandbox real desactivado a propósito). Encontré y corregí 3 bugs concretos, dejé documentado en el contrato un cuarto que requiere un cambio de backend, y señalo 3 puntos menores que no rompen nada hoy pero conviene conocer.

---

## 1. Bugs corregidos en esta pasada

### ✅ Corregido — Polling de apertura disparándose ~1 vez por segundo en vez de cada 5s

`useStoreOpening.ts`, el `useEffect` que sincroniza el estado de apertura tenía `globalSimTime` en su arreglo de dependencias:

```js
}, [globalSimTime, isSandboxMode]);
```

Como la Máquina del Tiempo cambia `globalSimTime` cada ~1 segundo real mientras "Auto-Run" está activo, este efecto se desmontaba y volvía a montar en cada tick — el `setInterval` de 5 segundos nunca llegaba a completar un ciclo, y la llamada inmediata `syncApertura()` de la línea siguiente terminaba disparándose aproximadamente una vez por segundo **por cada celular visible en la Matrix**, en vez de una vez cada 5 segundos como estaba pensado. Con varios empleados en pantalla y Auto-Run corriendo, esto multiplica innecesariamente las peticiones a `/store-opening/today` — es un candidato fuerte a explicar lentitud o (con muchos empleados) posibles rechazos si hay rate limiting de por medio.

**Corrección:** se saca `globalSimTime` de las dependencias y se lee fresco dentro de la función en cada llamada vía `useAppStore.getState().globalSimTime` (mismo patrón que ya usa el resto del archivo para leer `isSandboxMode`), en vez de depender del valor capturado por cierre. Así el intervalo de 5s se respeta de verdad y siempre usa la hora simulada más reciente.

### ✅ Corregido — "DecorArte" hardcodeado en la Matrix

Dos textos visibles al usuario tenían el nombre del tenant demo escrito a mano:
- El modal de clave de seguridad: "Estás en el entorno de producción de **DecorArte**".
- El banner de advertencia de modo producción: "El modo Sandbox está apagado para DecorArte".

Ambos ahora usan `currentUser?.tenant?.name` (con `'tu empresa'` como resguardo si no hay tenant cargado). Esto era exactamente el tipo de bloqueo que impediría usar la Matrix "para los demás tenants" — cualquier otra empresa probando su propio sistema vería el nombre de una empresa ajena en el diálogo de seguridad.

**Nota relacionada, no corregida en esta pasada:** encontré 5 ocurrencias más de "DecorArte" hardcodeado fuera de la Matrix (`Academia.tsx` — descripción y pregunta de quiz del curso de puntualidad, banner motivacional; `RelojVisual.tsx` — texto de consentimiento GPS, resumen semanal, confirmación de botón de pánico). No las toqué porque quedan fuera del alcance de "la Matrix" que pediste revisar ahora, pero son del mismo tipo de problema y bloquean lo mismo para otros tenants. Dime si quieres que las ataque en una pasada aparte.

### ✅ Corregido — Badge de llaves y orden de prioridad basados en `localStorage` potencialmente vacío/viejo

`getUserKeysIcon` y `getUserOpeningPriority` (usadas para el ícono 🔑 y para ordenar los celulares en pantalla) leían **solo** `localStorage.store_opening_assignments`. Como la Matrix ya opera sobre base de datos real (`isSandboxMode=false`, §13), esa llave de `localStorage` puede estar vacía si no visitaste RRHH en esa sesión del navegador — y si está vacía, caía a un heurístico 100% inventado en el cliente (cualquier `role === 'admin'/'encargado'/'platform_admin'` se marcaba como titular de llaves con prioridad según el orden de `globalUsers`), completamente desconectado de la asignación real que usa el backend para decidir quién puede abrir la tienda.

**Corrección:** ahora la Matrix llama directo a `GET /store-opening/assignments` al montar (y cada vez que RRHH dispara el evento `store_opening_assignments_updated`), y usa esa respuesta real como fuente de verdad. El heurístico local queda solo como resguardo mientras la petición no ha terminado.

**Hallazgo importante que esto destapó (no corregido, requiere backend):** ver sección 2.

---

## 2. 🔴 Hallazgo mayor — `GET /store-opening/assignments` todavía devuelve el ID equivocado (continuación de §28)

Al conectar la Matrix a datos reales encontré que el bug que Backend corrigió esta semana (§28: `employees.id` vs `users.id` tras la migración del 7 de julio) **no se corrigió en este endpoint específico**. `StoreOpeningController::getAssignments()` sigue devolviendo `employee_id` como `employees.id` sin resolver, y **26 sitios en 6 archivos del frontend** (RRHH, Matrix, `useStoreOpening.ts`, `useKeyholderDelegation.ts`, `MealQueue.tsx`, `RelojVisual.tsx`) comparan ese valor contra `users.id`.

**Impacto:** el badge 🔑 y el orden de apertura mostrados en pantalla pueden no corresponder al empleado correcto — es un desajuste de datos silencioso, no un error que truene, por eso es fácil que haya pasado desapercibido hasta ahora.

Ya quedó documentado con el fix exacto propuesto en `docs/BACKEND_INTERFACES.md` §29, pendiente para Claude Code. En cuanto lo implementen, ajusto el lado frontend (los 26 sitios) para usar el campo correcto.

---

## 3. Puntos menores (no bloquean el uso, pero vale la pena saberlos)

### 🟡 Contraseña de acceso a la Matrix hardcodeada como `"Master"`

El gate de seguridad (`if (passwordInput === "Master")`) es un literal visible en el código fuente/bundle del navegador — cualquiera con acceso a las herramientas de desarrollador puede leerlo. No es una vulnerabilidad grave porque este gate solo aplica a roles que NO sean `platform_admin` (que ya lo saltan automáticamente), pero noto que el literal `"Master"` coincide exactamente con la contraseña real de la base de datos en `Backend/.env` (`DB_PASSWORD=Master`) — reutilizar la misma palabra en dos lugares de sensibilidad distinta no es buena práctica, aunque hoy no representa una fuga directa. Si quieres, lo puedo mover a una variable de entorno del frontend o quitarlo del todo (ya que el verdadero control de acceso es el rol del usuario, no esta clave).

### 🟡 Rango de la Máquina del Tiempo fijo (7:30 a.m. – 7:00 p.m.)

El slider de tiempo simulado tiene `min={450} max={1140}` (7:30 am–7:00 pm) fijo en el código, sin importar el horario real configurado por cada tenant (`storeSchedule.openTime`/`closeTime`). Si una empresa abre antes de las 7:30 am o cierra después de las 7:00 pm, la Matrix no puede simular esas horas — no se puede probar la apertura real de esa empresa. Vale la pena hacerlo dinámico si van a probar la Matrix con tenants de horarios distintos a DecorArte.

### 🟡 Cada celular de la Matrix es un motor de reloj 100% independiente

Por diseño, cada celular corre su propia instancia completa de `useClockEngine` — su propio `fetchState()` al montar, su propia suscripción WebSocket privada al canal del tenant, y su propio polling de apertura cada 5s (ya corregido arriba). Esto es intencional y replica fielmente cómo se comportaría cada empleado en su propio dispositivo real, pero significa que el costo de red escala linealmente con el número de empleados visibles — con una plantilla grande (20-30 empleados) esto puede generar carga considerable al backend de forma simultánea. No es un bug, es una característica arquitectónica a tener en cuenta si en algún momento la Matrix se usa con tenants de plantilla grande.

---

## 4. Confirmado en esta pasada — ya resuelto por Backend, no requiere nada más

- **§27 (canal WebSocket privado):** Backend ya desplegó `PrivateChannel` + autorización en `routes/channels.php`. Activé el cambio correspondiente en el frontend (`echoInstance.private(...)`). Cerrado en ambos lados.
- **§28 (bug "Abrir Tienda" / `platform_admin`):** Backend corrigió el check de rol y, en una segunda pasada más profunda, el bug real de `employees.id` vs `users.id` en 3 métodos del servicio de apertura. 120/120 tests. El botón "Abrir Tienda" ya debería funcionar en la Matrix.

---

## 5. Buenas prácticas confirmadas (no requieren cambios)

- `PhoneErrorBoundary` aísla el renderizado de cada celular — si uno truena, no se cae toda la Matrix.
- El indicador "plan real vs. override manual" (agregado la sesión pasada) sigue funcionando y evita confusión sobre qué plan se está viendo.
- El aislamiento de datos de prueba por `simulation_session_id` (§13) sigue siendo consistente — nueva sesión y purga de datos de prueba no tocan fichajes reales.

---

## 6. Siguiente paso sugerido

1. Backend: implementar §29 (campo `user_id` resuelto en `/store-opening/assignments`) — es el único pendiente real que queda abierto.
2. Si quieres, ataco las 5 ocurrencias restantes de "DecorArte" hardcodeado fuera de la Matrix en la misma pasada que use §29, ya que tocaré los mismos archivos.
3. Opcional: mover el horario de la Máquina del Tiempo y la clave de acceso a algo configurable por tenant.

Verificado con `tsc --noEmit -p tsconfig.app.json` → 0 errores después de cada cambio.
