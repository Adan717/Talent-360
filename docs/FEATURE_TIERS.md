# Funciones por Plan — Freemium / Pro / Enterprise (Jul 2026)

Este documento mapea las funciones reales del código (auditadas directamente, no supuestas) contra los tres planes de Talent360, aprovechando que la división de `useClockEngine.tsx` en módulos por dominio (ver `hooks/`) ahora calca casi 1:1 las fronteras de negocio. Es un documento de **producto/frontend** — no requiere trabajo de Backend salvo lo que se anota al final en "Coordinación con Backend".

Convención: 🆓 Freemium incluido · 💎 Pro y superior · 🏢 Solo Enterprise · 🔓 Sin gating hoy (libre para todos, indistinto del plan).

---

## 1. Reloj Checador — núcleo

| Función | Flag / módulo en código | Hoy | Propuesta |
|---|---|---|---|
| Fichaje básico (entrada/salida/comida/descanso), dial de 23 estados | módulo `reloj` | 🆓 (en los 3 planes) | 🆓 Se mantiene — es el producto base |
| Salida temporal, ausencia, pase de lista (`roll_call`) | `roll_call` | 💎 (pro/enterprise) | 💎 Se mantiene |
| Reserva de turnos de comida (`meal_reservation`) | `meal_reservation` | 💎 | 💎 Se mantiene |
| Timers de comida/descanso con control de tiempo (`meal_timers`) | `meal_timers` | 💎 | 💎 Se mantiene |
| Ley Silla (silla obligatoria por normativa) | `enable_ley_silla` | 💎 | 💎 Se mantiene — es cumplimiento normativo, valor claro para vender |
| Validación de checklists de apertura/cierre | `checklists_validation` | 💎 | 💎 Se mantiene |
| Asistente de voz en Dashboard | `voice_assistant` (Dashboard) vs `voice_commands` (flag real) | 💎 — **pero ver bug #3 abajo, hoy nunca se desbloquea** | 💎 Una vez corregido el nombre del flag |

## 2. Apertura de tienda (hooks/useStoreOpening.ts)

| Función | Flag | Hoy | Propuesta |
|---|---|---|---|
| Apertura programada, ventana de tolerancia, cesión automática por deadline | `store_opening` | 💎 | 💎 Se mantiene |
| Reporte de ausencia/retardo/tienda cerrada | `store_opening` (mismo flag) | 💎 | 💎 Se mantiene |
| Checklist de cierre seguro | `store_opening` (mismo flag) | 💎 | 💎 Se mantiene |
| Apertura de emergencia con 2 testigos + PIN de seguridad | 🔓 sin gating | 🔓 | Recomiendo dejarlo 🆓 **siempre libre** — es un mecanismo de seguridad/continuidad de negocio, no una conveniencia; cobrar por esto puede verse mal si un cliente lo necesita en una emergencia real |
| Declaración de contingencia (sin luz/sin internet) | 🔓 sin gating | 🔓 | Igual que arriba: 🆓 siempre libre, es protección legal (LFT) del empleado, no un lujo |

## 3. Delegación de llaves (hooks/useKeyholderDelegation.ts)

| Función | Flag | Hoy | Propuesta |
|---|---|---|---|
| Entrega de Turno / arqueo al cierre (estado #21 de la matriz) | `keys_control` | 💎 (corregido 2026-07-21, antes usaba un chequeo de tier hardcodeado que ignoraba el array real por tenant) | 💎 Se mantiene |
| Transferencia de llaves en vivo entre dos titulares (`/key-transfers/*`) | `keys_control` (gate agregado 2026-07-21) | 💎 | 💎 Se mantiene |
| Cálculo de portadores de llaves (`isUserActiveKeyholder`) y aviso proactivo a suplente (estado #5) | Sin gate propio — es parte del flujo de apertura, cubierto por `store_opening` | 💎 (vía `store_opening`) | Se queda así a propósito: es información de seguridad (quién puede abrir/responder en emergencia), no conviene aislarla detrás de un segundo flag |
| Alerta de abandono de tienda | 🔓 sin gating | 🔓 | 🆓 Dejar libre — es seguridad, no conveniencia |

### ✅ Implementado (2026-07-21) — `keys_control`

Antes, `keys_control` existía en los arrays de `allowedFeatures` de `useAppStore.ts` pero ningún componente lo consultaba (confirmado por grep: cero resultados en `Frontend/src/components`). La "Entrega de Turno" (estado #21) se gateaba con un chequeo de tier hardcodeado (`isPro = currentTier === 'pro' || 'enterprise' || tenant_id === 1`) que ignora `allowedFeatures` por completo, y la transferencia de llaves en vivo no tenía ningún gate.

Cambios en `Frontend/src/components/reloj/hooks/useKeyholderDelegation.ts` y `useClockEngine.tsx`:
- `isKeysControlUnlocked = isFeatureUnlocked('keys_control')` ahora gatea `handleKeyDelegation`, `initiateKeyTransfer`, `checkPendingKeyTransfers` y `respondToKeyTransfer` (con alerta explicando que es función Pro cuando corresponde).
- El botón "Entrega de Turno" del dial (estado #21) ahora exige `isKeysControlUnlocked` además de `isPro` — sin cambio de comportamiento para tenants actuales (el flag ya viene incluido por default en pro/enterprise), pero ahora si el backend manda `tenant_allowed_features` sin `keys_control` para un tenant Pro específico, el gate lo respeta de verdad.
- El banner de transferencia de llaves pendiente en `RelojVisual.tsx` ahora también revisa `isKeysControlUnlocked` como defensa adicional (aunque `pendingKeyTransfers` ya no se puebla sin el flag).
- Deliberadamente NO se gateó `isUserActiveKeyholder`/`handleCallSuplente` (estado #5) con `keys_control` — siguen dependiendo solo de `store_opening`, porque saber quién tiene llaves es información de seguridad que se necesita incluso en apertura de emergencia (que es 🆓 siempre libre, ver fila de arriba en la sección 2). El badge de "Sucursal Abierta/Cerrada" tampoco se tocó — sigue visible en todos los planes, sin relación con `keys_control`.
- Verificado con `tsc --noEmit`, 0 errores.

## 4. GPS / Offline (hooks/useGeoAndOfflineSync.ts)

| Función | Flag | Hoy | Propuesta |
|---|---|---|---|
| Geofencing básico (perímetro permitido) | `gps_validation` — **flag roto, ver bug #1** | Nunca se desbloquea hoy | 🆓 — la validación de ubicación básica debería ser parte del producto base, no un candado roto |
| Cola offline de fichajes (HMAC) + sincronización | 🔓 sin gating | 🔓 | 🆓 Dejar libre — es confiabilidad del producto base, no una feature premium |
| "En camino a sucursal" (aproximación por GPS) / alerta de abandono de perímetro | 🔓 sin gating | 🔓 | 💎 — esto sí es una conveniencia de supervisión, tiene sentido como Pro |
| Validación por reconocimiento facial | `face_validation` — **flag roto, ver bug #1** | Nunca se desbloquea hoy | 🏢 Enterprise — es la validación más fuerte, encaja bien como diferenciador del plan top |

## 5. Módulos generales (fuera de Reloj)

| Módulo | Hoy (freemium) | Hoy (pro/enterprise) | Qué contiene |
|---|---|---|---|
| `rrhh` | 🆓 | 🆓 | Expedientes de empleados (RecursosHumanos.tsx) |
| `operativo` | 🆓 | 🆓 | Tareas y rutinas (PanelTareasRutinas.tsx, TaskRunner) |
| `reportes` | ❌ | 💎 | Reportes/nómina (ReportesManager.tsx) |
| `ats` | ❌ | 💎 | Reclutamiento/vacantes (AtsManager, GestorVacantes, RecruitmentBoard) |
| `academia` | ❌ | 💎 | LMS/cursos (GestorAcademia.tsx) |
| `documentos` | ❌ | 💎 | Gestión documental (GestorDocumentos.tsx) |
| `portal` | ❌ | ❌ — **nunca aparece en ningún plan, ver bug #2** | Portal del empleado (referenciado en nav, sin dueño claro) |
| `facturacion` | ❌ | ❌ — **nunca aparece en ningún plan, ver bug #2** | Facturación (FacturacionManager.tsx) |
| Respaldos del sistema (`system_backups`) | ❌ | No incluido en el array por defecto tampoco | 🏢 Propongo Enterprise — es una garantía operativa de nivel superior |
| Logo personalizado (`custom_logo`) | ❌ | No incluido por defecto tampoco | 💎 Encaja bien como branding de Pro |
| Gestión de rutinas dentro de Tareas (`routines_management`) | ❌ | No incluido por defecto tampoco | 💎 |

## 6. Simulador Matrix

El módulo `matrix` (simulador/sandbox de pruebas) está desbloqueado siempre para todos los planes — tiene sentido dejarlo así, es una herramienta de onboarding/QA, no un producto vendible.

---

## Hallazgos técnicos (independientes de qué tabla de arriba decidas)

Estos son bugs reales encontrados al auditar el código para armar esta tabla — conviene corregirlos sin importar qué decidas sobre precios, porque hoy causan comportamiento inconsistente:

1. **`gps_validation` y `face_validation` nunca se pueden desbloquear.** Se consultan en `RelojVisual.tsx` pero no existen en ninguno de los arrays por defecto de `allowedFeatures` (ni freemium ni pro/enterprise) en `useAppStore.ts`. Resultado: por más que un cliente pague Pro o Enterprise, esa pantalla de configuración siempre muestra estas dos validaciones como bloqueadas.
2. **`portal` y `facturacion` aparecen en la navegación pero en ningún plan.** `App.tsx`, `OnboardingWizard.tsx`, `CompanySettingsPanel.tsx` y `SaaSAccountSettings.tsx` referencian estos dos módulos, pero no están en el array de `allowedModules` de ningún tier — quedan bloqueados siempre, sin importar el plan.
3. **Posible bug de nombres: `voice_assistant` vs `voice_commands`.** El Dashboard (`DashboardTalent360.tsx`) valida `isFeatureUnlocked('voice_assistant')`, pero el flag que de verdad se otorga en Pro/Enterprise se llama `voice_commands`. Si son la misma función, el asistente de voz nunca se desbloquea aunque el cliente pague Pro.
4. **`PanelSimulador.tsx` tiene su propio mapa de módulos por tier, hardcodeado y separado** del array real en `useAppStore.ts` — le faltan `portal` y `facturacion` por completo. Esto puede hacer que el simulador de QA muestre información de planes distinta a la que realmente aplica en producción.

## Coordinación con Backend

Los arrays de `allowedModules`/`allowedFeatures` en el frontend son el *fallback* por defecto (usados en Sandbox o si el backend no manda nada). En producción, el backend ya envía `tenant_plan`, `tenant_allowed_modules` y `tenant_allowed_features` por tenant real (`useAppStore.ts::fetchState()`, línea ~495-498) — es decir, la fuente de verdad real para un cliente pagando ya vive del lado de Backend. Antes de cambiar cualquier default aquí, vale la pena que Claude Code confirme qué está poblando realmente esos 3 campos por tenant hoy, para que la tabla de arriba (una vez que la decidas) se refleje también ahí. Esto no bloquea nada de lo que sigue — es solo una verificación pendiente para cuando quieras aterrizar los cambios de verdad.
