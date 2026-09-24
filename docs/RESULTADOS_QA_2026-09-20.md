# Resultados QA integral — Talent 360

Inicio de ronda: 2026-09-20 10:32 America/Mexico_City  
Commit candidato: `d2096da` (HEAD local posterior de documentación: `3f119e6`)  
Entorno: producción controlada; Stripe Test. No se autoriza Stripe Live ni cobros reales.

## Preflight

| ID | Estado | Evidencia | Observación |
|---|---|---|---|
| PRE-01 | PASÓ | `GET https://talent360.com.mx/api/health` → 200; `status=ok`, `db=ok`, respaldo `ok` con antigüedad de 13.79 h | Producción y PostgreSQL disponibles. |
| PRE-02 | PASÓ | 2026-09-20 10:32 America/Mexico_City; navegador Codex In-app Browser; commit `d2096da` desplegado | Línea base registrada. |
| PRE-03 | PASÓ (evidencia previa) | Checkout de Stripe mostró explícitamente «Entorno de prueba» | No se activará Live durante esta ronda. |
| PRE-04 | PASÓ | Sesión existente `Prueba Stripe E2E`, rol Admin/Gerencia, plan Premium | El alta de tenants nuevos seguirá los casos REG, no se precrearán. |
| PRE-08 | PASÓ | Health reporta respaldo reciente y válido | Falta comprobación de restauración aislada en DR-02. |

## Primer barrido autenticado, sin escrituras

| ID | Estado | Evidencia | Observación |
|---|---|---|---|
| NAV-smoke-01 | PASÓ | Cargan sin pantalla blanca: Monitor, Directorio Digital, Reloj Checador, Tareas IA, Reportes IA, ATS, Academia 360, LFT, Organigrama/SOP y Configuración | Las rutas internas reales se validan mediante el menú; parámetros inválidos redirigen a Monitor. |
| REP-01 | PASÓ | Reportes muestra catálogo, exportaciones y pestaña «Nómina y Avanzados» con estado vacío honesto | No se descargaron archivos ni se autorizó nómina. |
| BILL-copy-01 | FALLÓ (P1) | Catálogo público autenticado de Reportes muestra «Pre-nómina Histórica» con descripción: «…netos, deducciones, firmas y timbrado». | Con el timbrado CFDI apagado, el texto promete/normaliza una función no disponible y contradice la leyenda correcta del reporte «Pre-nómina para tu Contador». Corregir copia y documentos relacionados antes de certificación. |
| LFT-UI-01 | FALLÓ (P2) | Simulador LFT muestra importes con tres decimales y separador ambiguo: `-$133,333` y `$1866,667`. | El cálculo interno es consistente, pero la presentación monetaria no usa formato MXN ni dos decimales; puede inducir error operativo. |

## Sitio público, sin sesión

| ID | Estado | Evidencia | Observación |
|---|---|---|---|
| PUB-01 | PASÓ | Landing limpia carga logo Talent 360, un único `h1`, contenido y demo | Sin pantalla blanca ni salto visible. |
| PUB-02 | PASÓ | Enlaces Plataforma, Soluciones y Precios llevan a `#lab-reloj`, `#lab-soluciones` y `#pricing` | Navegación por anclas correcta. |
| PUB-05 | PASÓ | Cambio mensual/anual y cotizador a 50 colaboradores actualizan importes reales: Pro anual $14,400/año y Enterprise $33,000/año | La carga asíncrona inicialmente indica que consulta tarifa; después reemplaza los placeholders sin estimar precios. |
| PUB-07 | PASÓ | El simulador declara explícitamente que usa datos de ejemplo, no solicita cámara/ubicación ni crea registros | No se escribieron datos. |
| PUB-08 | PASÓ | No se muestra «Módulos en Acción» ni tarjetas de videos vacías | Comportamiento esperado mientras no haya videos reales. |
| PUB-09 | PASÓ | FAQ abre modal con foco en cerrar; `Escape` lo cierra y devuelve foco a «Preguntas frecuentes» | Patrón de diálogo accesible. |
| PUB-03 / AUTH-02 | PASÓ | «Comenzar gratis» abre alta; muestra «Cargando acceso seguro…» y, tras cargar, un solo botón Google | No se completó autenticación ni se crearon cuentas. |
| AUTH-copy-01 | FALLÓ (P2) | Modal de alta: «Usa Google, Apple o completa tus datos…» | Apple permanece oculto por falta de credenciales. Debe decir «Google o completa tus datos» hasta habilitar Apple. |
| REG-validation-01 | PASÓ | Alta vacía enfoca nombre y anuncia «Completa este campo»; correo inválido anuncia el error y enfoca correo | No se envió una alta ni se creó identidad. |
| AUTH-01 / AUTH-02 / AUTH-04 | PASÓ | Login expone correo/contraseña, recuperación y una vez Google; Apple y huella no aparecen. «Mostrar contraseña» cambia a «Ocultar contraseña». | La carga de Google conserva espacio y no duplica botón. |
| AUTH-17 | FALLÓ (P2) | Con sesión ya existente, abrir `/login` y pulsar «Entrar al Sistema» vacío lleva directamente al Dashboard, sin aviso para continuar o cambiar cuenta. | No otorga acceso nuevo, pero rompe el flujo esperado y hace imposible distinguir una sesión activa antes de intentar acceder. |
| PUB-07b | PASÓ | Simulador Básica registra sólo «Fichaje registrado» local y el botón «Reiniciar simulador» restaura el estado inicial | No cambió URL, sesión ni registros de empresa. |
| PUB-09b | PASÓ | Aviso de privacidad se abre desde el pie y `Escape` lo cierra, devolviendo foco al botón que lo abrió | El contenido legal es legible y navegable. |
| A11Y-07 | FALLÓ (P2) | Al abrir el aviso legal, el foco permanece en el botón disparador en vez de moverse al diálogo o su botón de cierre | El cierre restaura correctamente el foco, pero falta moverlo/trapearlo al abrir. |
| SEC-ENC-01 | FALLÓ (P1) | El aviso público declara que fotografías de fichaje se conservan en almacenamiento privado pero «No se cifran en reposo»; también declara base de datos y archivos en Hetzner sin cifrado en reposo del disco | Para PII y fotografías/evidencia de presencia, esto requiere cifrado en reposo verificable y una revisión legal/operativa antes de certificación comercial. |

## Perfil, plan y navegación autenticada

| ID | Estado | Evidencia | Observación |
|---|---|---|---|
| NAV-02 | PASÓ | `?module=qa-invalido` se recupera a `?module=dashboard` | No queda pantalla en blanco ni ruta rota. |
| CFG-09 | PASÓ (lectura) | Perfil muestra plan Pro, $29 MXN/mes, una licencia y sin historial de facturación | No se cambió plan ni suscripción. |
| PLAN-UI-01 | FALLÓ (P2) | En el mismo perfil que muestra plan `PRO`, la cabecera dice «Enterprise Tenant». | Identidad de plan contradictoria. |
| PLAN-copy-01 | FALLÓ (P1) | Matriz de módulos Pro describe «Cálculo de nóminas, reportes e integraciones SAT». | El producto tiene timbrado/CFDI apagado; eliminar o reformular «integraciones SAT» antes de certificación. |
| PLAN-gating-01 | PENDIENTE DE REPRUEBA | Tenant histórico Pro muestra como activos módulos rotulados «REQUIERE ENTERPRISE». | Puede ser un override heredado. Se probará con el tenant Pro nuevo de REG-05 antes de clasificarlo como fallo de autorización. |
| CFG-10 | PASÓ (lectura) | Panel explica con precisión que el JSON está firmado HMAC, qué incluye y excluye archivos, recibos, contraseñas y PIN. | No se descargó ni importó respaldo. |
| SEC-ENC-02 | FALLÓ (P1) | El mismo panel indica: «No va cifrado: guárdalo en un lugar seguro». | Un respaldo portable con expedientes, cuentas y fichajes debe cifrarse antes de descargarse o requerir cifrado local verificable. |
| A11Y-07b | FALLÓ (P2) | El panel «Perfil de la Empresa & Licencias» no se cierra con `Escape`; sólo responde al botón explícito de cerrar. | Los modales del producto deben comportarse de forma consistente. |

## ATS y portal público

| ID | Estado | Evidencia | Observación |
|---|---|---|---|
| ATS-01 / ATS-04 | PASÓ | ATS carga vacante, configuración de portal y URL pública; el portal abierto sin sesión expone sólo marca, vacante, descripción, sueldo y requisitos. | No se revelaron datos internos ni candidatos. |
| ATS-04b | PASÓ | Detalle de vacante contiene jornada «No especificado» en lugar de inventar dato | Estado vacío honesto. No se envió postulación. |
| A11Y-10 | FALLÓ (P2) | Tabla de vacantes contiene botones sin nombre accesible; detalle de vacante también presenta un botón iconográfico sin nombre. | Añadir `aria-label`/texto visible para editar, publicar y volver/cerrar. |

## Academia

| ID | Estado | Evidencia | Observación |
|---|---|---|
| LMS-01 | PASÓ (lectura) | Catálogo, filtros, importación y creación de curso cargan; tres cursos de inducción se muestran con estado y contenido visibles. | No se creó curso ni se inició progreso. |
| A11Y-10b | FALLÓ (P2) | En las tarjetas de curso, los controles visuales «Material», «Examen de ejemplo» e «Iniciar» no se exponen como controles con nombre en el árbol accesible. | Un usuario de teclado/lector no puede descubrir ni activar de manera fiable las acciones del curso. |

## Alta Free por correo

| ID | Estado | Evidencia | Observación |
|---|---|---|---|
| REG-01 | FALLÓ (P1) | Alta Free por correo termina con avatar `FREEMIUM`, pero el onboarding dice «Plan Enterprise Activado». | Contradicción de plan de alta: la pantalla promete una suite Enterprise a una cuenta Free recién creada. |
| PLAN-gating-01 | PENDIENTE DE REPRUEBA | El catálogo expandido del onboarding enumera sólo Recursos Humanos y Reloj como activos; ATS está rotulado `PRO / ENT`. La página ATS visible queda detrás de la pantalla de onboarding. | No hay todavía evidencia de que el servidor permita la acción Enterprise; completar/cerrar onboarding y consultar una acción protegida antes de clasificarlo como fuga de permiso. |
| ONB-02 / PLAN-gating-02 | FALLÓ (P1) | El wizard Free permite seleccionar por defecto 7 puestos, 92 tareas, 5 cursos LMS/LFT y muestra vacantes ATS, aun cuando catálogo/landing rotulan ATS, Academia, LFT y pre-nómina como planes superiores. | Existe inconsistencia de producto y posibilidad de aprovisionar datos de módulos no incluidos. Se detuvo antes de «Crear estructura» para no contaminar el tenant QA ni otorgar prestaciones indebidas. |

## Reporte del jefe — sesiones entre dispositivos (2026-09-23)

Lo que vivió: con la misma cuenta, el celular y la laptop/escritorio muestran cosas distintas.
Registró la empresa **PC Master** con su Gmail en talent360.com.mx; después abrió la página en su
celular, que ya tenía una sesión iniciada: entró directo a la aplicación **con la cuenta de Adán** y
lo primero que vio fue el aviso de "descargar la aplicación".

Sin reproducir todavía. Causas **verificadas leyendo el código de `0a07640` y el `sw.js` publicado**:

| ID | Sev. | Hallazgo | Evidencia |
|---|---|---|---|
| JEFE-01 | P0 (sospecha fundada) | El service worker guarda **toda respuesta GET de `/api/`** (NetworkFirst, 24 h, 100 entradas) con la URL como llave, sin distinguir cuenta. Si la red tarda más de 10 s o falla, entrega lo guardado: datos de hasta un día antes, **o de otra cuenta que usó ese mismo navegador**. Ignora el `Cache-Control: no-store, private` que manda el servidor y nadie borra esa caché al cerrar sesión. Además deja nómina, expedientes y fichajes en el disco del dispositivo. | `Frontend/vite.config.ts:16-25`; confirmado en `https://talent360.com.mx/sw.js` (`talent360-api-cache`, `maxAgeSeconds:86400`). |
| JEFE-02 | P1 | **Cerrar sesión no cierra la sesión.** `handleLogout` sólo borra el token de `localStorage`; nunca llama a `POST /logout` (que existe y sí revoca). El token no caduca nunca (`sanctum.expiration = null`) y la cookie httpOnly `talent_auth_token` dura **un año** y sigue autenticando cualquier petición sin header. Toda sesión abierta en cualquier dispositivo sigue viva. | `Frontend/src/App.tsx:217-223`; `Backend/config/sanctum.php:53`; `AuthController.php:161` (cookie de 365 días) y `:195-206` (logout que nadie llama). |
| JEFE-03 | P2 | La cookie de autenticación la fijan `login`, `loginSocial` y `kioskLogin`, pero **no** el alta por checkout (`SubscriptionController:250`), el aprovisionamiento (`TenantController:168`) ni la suplantación (`PlatformAdminController:648`). En un navegador que ya tenía otra sesión, tras dar de alta una empresa el token en pantalla es de la cuenta nueva y la cookie sigue siendo de la anterior. Hoy no se encontró ninguna petición del frontend que viaje sin header, así que queda latente. | Llamadas a `createToken` fuera de `AuthController`. |
| JEFE-04 | P2 | Al abrir la página en un celular con sesión viva no se dice qué cuenta ni qué empresa quedó abierta; en el Reloj aparece a los 1.5 s el banner "Instalar Talent 360 App", que se lee como si obligara a descargar. No es una redirección: es el banner PWA de `RelojVisual`. | `RelojVisual.tsx:1941-1960` (temporizador), `:3332` (texto). |
| JEFE-05 | P3 | `qa_simulated_tier_override` en `localStorage` impone el plan mostrado en ese dispositivo (`activeTier = simulatedTierOverride \|\| currentTier`). Ya nada en la interfaz lo escribe, pero nadie borra un valor viejo: un equipo que lo usó en pruebas anteriores muestra otro plan que los demás. | `useAppStore.ts:180, 208`; la limpieza de `App.tsx:849` sólo quita `matrix_active_sim_session_id`. |

**Corrección `eb0302c`, DESPLEGADA el 2026-09-23 (respaldo previo `20260924_031305`); falta la
reprueba con dos dispositivos.** Comprobado en producción: el `sw.js` vivo usa `talent360-api-por-cuenta`;
la misma URL pedida con dos tokens distintos quedó en dos entradas separadas y la petición sin token
no se guardó. Al desplegar, 128 de los 209 tokens del servidor llevaban más de 30 días sin uso y
quedaron caducados.
- JEFE-01: la copia de la API se guarda en otra caché (`talent360-api-por-cuenta`), sólo para
  peticiones con token, con la huella del token dentro de la llave, y únicamente se usa cuando la red
  falla (se quitó el corte de 10 s). La caché vieja se borra al abrir la app. Se conserva para que el
  reloj abra sin señal.
- JEFE-02: `src/lib/sesion.ts` es el único cierre de sesión (las 8 salidas pasan por ahí): revoca
  en el servidor, que además expira la cookie, y limpia tokens, caché del reloj y copia de la API. Los tokens
  caducan tras **30 días sin uso** (`AppServiceProvider`).
- JEFE-03: el alta Free y `POST /tenants` también fijan la cookie. La suplantación del superadmin
  no se tocó: ahí la cookie debe seguir siendo la del superadmin.
- JEFE-04: "Cerrar" en el aviso de instalar se recuerda en el dispositivo. El aviso no se quitó:
  la causa de fondo era la sesión eterna (JEFE-02).
- JEFE-05: el plan simulado ya no se lee ni se escribe en `localStorage`; al abrir la app se borra el valor viejo.

**JEFE-06 (P1, segundo mensaje del jefe, 2026-09-23): se perdían fichajes hechos sin conexión.** Si alguien
fichaba sin señal y otra persona entraba después en ese celular, la cola se subía con la sesión
nueva, el servidor la rechazaba ("sólo tus propios ponches") y el cliente la BORRABA como rechazo
definitivo. Además, cerrar sesión no revisaba lo pendiente. Corregido: el servidor responde `ajeno`
(no `rejected`), así que cualquier cliente, incluso uno con la versión vieja en caché, lo conserva hasta que
su dueño entre; cerrar sesión intenta subir lo pendiente y, si queda algo propio, pregunta antes de
salir. La subida vive en `subirFichajesPendientes()` (`src/lib/offlineDb.ts`). Las tareas no tienen
cola sin conexión en esta versión: necesitan red.

Siguiente paso: reproducirlo en dos navegadores con cuentas QA y revisar en el servidor los tokens
vivos de la cuenta de Adán (`personal_access_tokens`: fecha de creación y último uso) para saber cuándo quedó
abierta en el celular del jefe. Mitigación inmediata en ese celular: borrar los datos del sitio
`talent360.com.mx` (así se van el token, la cookie y la caché del service worker); "Cerrar sesión" no basta.

## Bloqueos de ejecución actuales

- PUB-01 a PUB-14 requieren una sesión sin cookies. El navegador de control disponible comparte la sesión administrativa y no expone un perfil/incógnito separado. Se retomarán al disponer de un perfil limpio, sin cerrar ni contaminar la sesión administrativa.
- Los casos REG/AUTH que crean cuentas requieren los alias QA previstos en el guion y, para Checkout, autorización puntual antes de confirmar una suscripción Test.
