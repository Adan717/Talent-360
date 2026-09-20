# Guion QA integral y certificación final — Talent 360

Fecha de corte: 2026-09-20  
Versión auditada y desplegada: `d2096da` (remediaciones funcionales en `b620389`)  
Entorno inicial: producción con Stripe Test; Stripe Live queda fuera hasta autorización expresa del dueño.  
Fuente de alcance: interfaz publicada, 12 módulos productivos, 374 rutas Laravel, pruebas actuales y decisiones de producto vigentes.

**Lectura rápida del recorrido:** las secciones 3 y 4 sólo preparan identidades, dispositivos,
seguridad y evidencia. La prueba del producto empieza en la sección 5 como visitante sin sesión:
**landing (5) → alta y onboarding (6) → autenticación (7) → aplicación y módulos (8–27) → cierre (28)**.

## 1. Dictamen previo

La aplicación está en condiciones de iniciar una ronda QA integral controlada. Los P1 y P2 encontrados
en el análisis previo fueron corregidos, validados en CI y desplegados. **No está todavía certificada
para producción comercial completa**: la certificación depende ahora de ejecutar este guion y cerrar
cualquier P0/P1 que aparezca durante la prueba funcional.

### Fortalezas verificadas

- Identidad visual coherente: logo real, paleta sobria, jerarquía y componentes Lucide en las vistas principales.
- Landing y login sin desbordamiento horizontal en escritorio ni en 390 px.
- Landing con un solo `h1`, jerarquía de encabezados coherente, imágenes con `alt` y botones con nombre accesible.
- Login con etiquetas reales para correo y contraseña, botón para mostrar contraseña y legales accesibles.
- Google está configurado; Apple permanece oculto mientras no tenga credenciales.
- Consola limpia en landing/login durante la inspección.
- Backend, migraciones de prueba, lint crítico y build pasan en CI; frontend: 265 pruebas aprobadas.
- Producción saludable: web 200, API 422 esperada sin credenciales, PostgreSQL operativo y respaldo vigente.

### Remediaciones previas cerradas el 2026-09-20

| Prioridad | Estado | Evidencia de cierre |
|---|---|---|
| P1 | Cerrado | Se retiraron precio y promesas de timbrado de nómina. La pre-nómina se presenta como referencia para el contador y, con timbrado apagado, no consulta Facturapi ni solicita CSD. |
| P1 | Cerrado | `npm audit` completo y de producción: 0 vulnerabilidades. |
| P1 | Cerrado | 0 errores ESLint; reglas de Hooks y `case` duplicado son puertas duras. Lint, pruebas y build se ejecutan en CI. Las 674 advertencias heredadas son deuda no bloqueante y se atenderán por módulos. |
| P1 | Cerrado | Matrix/override usa el mismo catálogo de módulos y capacidades de Pro/Enterprise que el servidor. |
| P2 | Cerrado | No quedan llamadas funcionales a `alert/confirm/prompt`; se usan notificaciones y diálogos accesibles. |
| P2 | Cerrado | No quedan clases de texto de 8–10 px; los controles públicos principales tienen objetivos táctiles de 44 px. |
| P2 | Cerrado | La landing y sus simuladores ya no usan emojis de presentación como controles o iconos. |
| P2 | Cerrado | “Módulos en Acción” se oculta mientras no existan videos reales; no se muestran tarjetas “Próximamente”. |
| P2 | Cerrado | Google reserva su espacio y muestra “Cargando acceso seguro…” mientras carga el SDK. |

### Deuda posterior que no bloquea esta ronda

- **P3 técnico:** algunos componentes siguen siendo muy grandes (`RelojVisual`, `useClockEngine`,
  RRHH y Superadmin). Conviene dividirlos por dominio después de estabilizar el producto, pero no es
  un requisito previo para iniciar QA.
- **Contenido opcional:** la sección de videos puede reactivarse cuando existan URLs reales. Su
  ausencia no bloquea ningún flujo funcional.

## 2. Reglas de ejecución

1. Probar sólo con tenants y personas que empiecen por `QA-20260920-`.
2. Nunca pegar contraseñas, tokens, llaves, CSD, datos bancarios ni PII real en el chat o evidencia.
3. Una acción por caso. Tras cada escritura importante: **guardar, recargar y volver a comprobar**.
4. Registrar cada resultado como: `ID | PASÓ/FALLÓ/BLOQUEADO/NO APLICA | navegador/dispositivo | evidencia | observación`.
5. Un caso falla si hay pantalla blanca, 500/502, dato inventado, pérdida al recargar, cruce de tenant, permiso indebido o mensaje que promete algo no realizado.
6. No corregir datos manualmente en BD para “hacer pasar” un caso. Si el flujo no puede producirlos, es hallazgo.
7. Capturar consola y petición de red sólo cuando falle. Ocultar tokens/cookies antes de compartir.
8. Las pruebas marcadas `⚠` son destructivas: usar el tenant desechable y confirmar antes que el respaldo esté vigente.
9. Stripe se prueba en **Test**. No activar Live ni efectuar cobros reales en esta ronda.
10. Validar el gating primero con tenants reales. Matrix sirve como comprobación complementaria, no sustituye la autorización del servidor.

### Severidad

- **P0:** pérdida/corrupción de datos, cruce de empresas, acceso sin autorización, pago real incorrecto o caída general.
- **P1:** flujo principal bloqueado, cálculo laboral/financiero incorrecto, promesa comercial falsa o falla sin alternativa.
- **P2:** función secundaria rota, UX confusa o accesibilidad importante.
- **P3:** detalle cosmético o deuda interna sin efecto funcional inmediato.

### Criterios de salida

- Cero P0 y P1 abiertos.
- 100 % de casos críticos aprobados: autenticación, aislamiento, alta, reloj, tareas, reportes, pagos Test y respaldo.
- Al menos 95 % del total aplicable aprobado; el resto sólo P2/P3 con responsable y fecha.
- Suites, build, lint crítico, auditorías de dependencias y salud de producción en verde.
- Reprueba completa de todo caso corregido y de su flujo vecino.

## 3. Inventario de cuentas, identidades y dispositivos

**Propósito:** definir qué actores necesitaremos durante la ronda. No es necesario crear manualmente
todos antes de comenzar; el propio guion indica cuándo deben nacer. Así se prueba el flujo completo en
vez de preparar por fuera aquello que queremos validar.

| Código | Perfil | Cuándo debe existir |
|---|---|---|
| VIS | Visitante anónimo, ventana privada y sin cookies. | Es el punto de partida de la sección 5. |
| PA | Platform admin; sólo contraseña. | Debe existir antes de la ronda; se usa principalmente en la sección 22. |
| A-PRO / T-A | Admin dueño y tenant Pro desechable. | Se crean mediante alta + Stripe Test en REG-05. |
| A-FREE / T-B | Admin dueño y tenant Freemium mediante Google. | Se crean en REG-02 y se reutilizan para aislamiento. |
| A-MAIL / T-C | Admin y tenant Freemium mediante correo/contraseña. | Se crean en REG-01 para validar el segundo método de alta. |
| A-ANUAL / T-D | Admin y tenant Pro anual desechable. | Se crean en REG-06 para no alterar la suscripción mensual de T-A. |
| SUP | Supervisor con permisos delegados limitados en T-A. | Se crea o habilita durante RH-02/RH-10. |
| EMP-A | Colaborador activo, turno diurno, salario y puesto completos. | Se crea en RH-05. |
| EMP-B | Colaborador para retardos, comida, tareas y concurrencia. | Se crea después de EMP-A en la sección 11. |
| EMP-I | Colaborador que se inactivará y reincorporará. | Se crea activo y cambia de estado en RH-15/RH-16. |

Antes de empezar sólo hay que disponer de:

1. La cuenta PA existente.
2. Una cuenta Google de prueba que nunca se haya registrado y acceso a su buzón.
3. Tres direcciones o alias de correo QA que lleguen a un buzón controlado: Pro mensual, Free por
   contraseña y Pro anual. No tienen que ser cuentas creadas en Talent 360; el guion las registrará.
4. La tarjeta oficial de Stripe Test; nunca una tarjeta real.
5. Dos navegadores o perfiles separados para sesiones simultáneas.

Ejecutar escritorio en Chrome y Edge actuales; móvil en Android Chrome y iPhone Safari/PWA si se
dispone. Para tiempo real usar dos sesiones simultáneas: admin y colaborador.

## 4. Preflight de seguridad y observabilidad

**Propósito:** confirmar que el entorno es seguro para probar y dejar una línea base reproducible.
Aquí todavía no se evalúan módulos ni experiencia de usuario. Al terminar PRE-08 se cierra la sesión,
se abre una ventana privada y la ejecución funcional comienza en PUB-01 desde la landing.

| ID | Acción | Resultado esperado |
|---|---|---|
| PRE-01 | Confirmar `/api/health`. | `status=ok`, BD `ok`, respaldo vigente. |
| PRE-02 | Guardar commit, fecha, navegador, dispositivo y zona horaria. | La ronda es reproducible. |
| PRE-03 | Confirmar Stripe Test y que checkout muestre “Entorno de prueba”. | Ningún cobro real posible. |
| PRE-04 | Confirmar acceso de PA y reservar los identificadores `QA-20260920-*` para T-A, T-B, T-C, T-D y sus usuarios. | Ninguna empresa operativa queda en alcance; los tenants se crearán en REG. |
| PRE-05 | Guardar una plantilla de valores QA para horarios, tolerancias, comedor, nómina y módulos. | Se aplicará después del alta y permitirá restaurar el baseline al cerrar. |
| PRE-06 | Abrir consola y red; limpiar registros. | Base de evidencia sin ruido previo. |
| PRE-07 | Confirmar Reverb conectado y hora servidor/tenant. | Tiempo real y fechas parten del reloj correcto. |
| PRE-08 | Confirmar último respaldo del servidor y espacio en disco. | Las pruebas destructivas tienen recuperación. |

**Hito de inicio funcional:** después de PRE-08, abrir `/` sin cookies como VIS y continuar en orden
con las secciones 5, 6 y 7. No iniciar sesión como PA para “precrear” T-A o T-B: esas altas forman
parte de lo que se debe certificar.

## 5. Sitio público, marca, legales y precios

| ID | Acción | Resultado esperado |
|---|---|---|
| PUB-01 | Abrir `/` sin cookies. | Landing nueva, logo correcto, un `h1`, sin parpadeo/pantalla blanca. |
| PUB-02 | Usar Plataforma, Soluciones y Precios. | Cada enlace aterriza en la sección correcta y el foco no se pierde. |
| PUB-03 | Usar todos los CTA “Crear/Comenzar gratis”. | Abren el mismo alta y conservan el plan previsto. |
| PUB-04 | Usar “Iniciar sesión” y volver. | Navegación limpia, sin estado de alta residual. |
| PUB-05 | Alternar mensual/anual y mover colaboradores al mínimo/medio/máximo. | Precio, ahorro y periodicidad coinciden con `/public/tarifario`. |
| PUB-06 | Elegir Gratis, Profesional y Enterprise sin finalizar compra. | Plan y precio correctos en el alta. |
| PUB-07 | Recorrer simulador Básica/Pro y reiniciarlo. | Sólo datos demo; no solicita cámara/GPS ni escribe en BD. |
| PUB-08 | Revisar el área posterior al simulador. | Si no hay videos reales, “Módulos en Acción” no aparece. Si se configuraron videos, sólo aparecen pestañas reproducibles y accesibles. |
| PUB-09 | Abrir FAQ, Privacidad, Términos y ARCO; cerrar con botón, Escape y clic permitido. | Contenido legible, foco atrapado/restaurado y sin recargar la landing. |
| PUB-10 | Verificar textos comerciales contra funciones reales. | No se promete CFDI/timbrado, biometría u otra función apagada/no configurada. |
| PUB-11 | Navegar sólo con Tab/Shift+Tab/Enter/Espacio. | Orden lógico, foco visible, sin trampas. |
| PUB-12 | Probar 320, 390, 768, 1024 y 1440 px; zoom 200 %. | Sin scroll horizontal ni controles tapados. |
| PUB-13 | Revisar iconos, emojis, estados y contraste. | Iconografía consistente; el color nunca es la única señal. |
| PUB-14 | Revisar título, favicon, metadatos y enlaces compartidos. | Marca Talent 360 correcta, sin nombres técnicos o del template. |

## 6. Alta de empresa, checkout Test y onboarding

Esta sección crea T-A/A-PRO, T-B/A-FREE, T-C/A-MAIL y T-D/A-ANUAL. T-A y T-B serán los tenants
principales del resto de la ronda; T-C y T-D certifican variantes de alta y facturación. A partir de
aquí ya existen las identidades necesarias para probar acceso, permisos y módulos sin preconfigurar
empresas fuera del recorrido.

| ID | Acción | Resultado esperado |
|---|---|---|
| REG-01 | Crear empresa Gratis con correo. | Identidad y tenant únicos; aceptación legal obligatoria. |
| REG-02 | Crear empresa Gratis con Google. | No solicita contraseña social; mismo flujo de Empresa. |
| REG-03 | Repetir correo, nombre/subdominio y doble clic. | Mensajes específicos y una sola empresa. |
| REG-04 | Probar nombre con acentos, espacios y caracteres límite. | Normalización estable; no genera URL inexistente. |
| REG-05 | Elegir Pro mensual en Stripe Test y pagar con 4242. | Checkout correcto, retorno `payment=success`, tenant activo y ciclo mensual. |
| REG-06 | Elegir Pro anual en Stripe Test. | Cobra anual y `current_period_end` anual, no mensual. |
| REG-07 | Probar tarjeta rechazada/cancelar/volver. | No aprovisiona como pagada; permite reintentar sin duplicar. |
| REG-08 | Actualizar/repetir el retorno exitoso. | Idempotente: un tenant, un customer y una suscripción. |
| REG-09 | Confirmar correo de bienvenida. | URL pública real, tenant y método de acceso correctos; sin contraseña en claro. |
| ONB-01 | Abrir onboarding nuevo y revisar catálogo por giro. | Sólo plantillas del giro; estado vacío explica salida. |
| ONB-02 | Completar Giro → Puestos → Tareas → Cursos → Organigrama. | Atrás/siguiente conservan selección; conteos coinciden. |
| ONB-03 | Desmarcar todo en cada etapa y continuar cuando sea válido. | Botones/validaciones explican el requisito real. |
| ONB-04 | Confirmar organigrama y crear estructura. | No crea ciclos/duplicados; datos aparecen en RRHH. |
| ONB-05 | Ejecutar paso 2 sin plantillas y “Saltar y finalizar”. | Finaliza y no deja al usuario atrapado. |
| ONB-06 | Recargar/cerrar en cada paso y retomar. | Retoma sin repetir inserciones. |
| ONB-07 | Cargar datos demo opcionales una sola vez. | Marcados como demo, aislados y sin duplicarse. |
| ONB-08 | Finalizar y volver a Configuración. | Wizard queda completo; puede editarse sin reaparecer forzosamente. |

## 7. Autenticación, Google, contraseña y recuperación

| ID | Acción | Resultado esperado |
|---|---|---|
| AUTH-01 | Abrir `/login` sin sesión. | Correo/contraseña, Google configurado; Apple oculto; sin huella simulada. |
| AUTH-02 | Esperar la carga de Google con red normal y lenta. | Muestra “Cargando acceso seguro…” y después aparece una vez, sin salto grave ni botón duplicado. |
| AUTH-03 | Enviar vacío, correo inválido y contraseña incorrecta. | Validación clara; no revela si la cuenta existe. |
| AUTH-04 | Mostrar/ocultar contraseña. | Conserva valor y foco; nombre accesible cambia correctamente. |
| AUTH-05 | Iniciar con A-PRO por contraseña. | Tenant correcto, URL sin secretos y sesión persistente al recargar. |
| AUTH-06 | Iniciar con PA por contraseña. | Abre consola de plataforma; nunca ofrece Google como bypass. |
| AUTH-07 | Iniciar con Google con A-FREE ya registrado en REG-02. | Entra al mismo usuario/tenant y no crea una segunda identidad. |
| AUTH-08 | Repetir Google desde el CTA de alta con ese mismo correo. | Detecta la identidad existente; no crea otro tenant por reintento. |
| AUTH-09 | Intentar Google con correo de PA. | Rechaza social y exige contraseña. |
| AUTH-10 | Cancelar el selector Google y reintentar. | Se recupera sin “intento vencido” permanente. |
| AUTH-11 | Recargar durante el retorno de Google. | Estado/nonce no se reutiliza; mensaje útil y reintento posible. |
| AUTH-12 | Abrir “Olvidé mi contraseña” con correo existente y desconocido. | Misma respuesta genérica para ambos. |
| AUTH-13 | Usar enlace de recuperación válido, vencido y reutilizado. | Sólo el primero permite cambiar; los otros se rechazan claramente. |
| AUTH-14 | Probar contraseña débil, conocida y confirmación distinta. | Reglas visibles y aplicadas en servidor. |
| AUTH-15 | Probar cambio forzado de contraseña. | No entra a módulos hasta completar; sesión anterior queda invalidada. |
| AUTH-16 | Cerrar sesión y pulsar Atrás. | No reaparecen datos privados; exige login. |
| AUTH-17 | Abrir login con sesión activa. | Advierte y permite continuar o cambiar de cuenta sin mezclarlas. |
| AUTH-18 | Dejar expirar la sesión y luego guardar. | Pide login; no muestra éxito falso ni pierde silenciosamente el formulario. |
| AUTH-19 | Repetir intentos incorrectos hasta el límite. | Throttle por cuenta/IP, respuesta genérica y recuperación posterior. |
| AUTH-20 | Probar dos pestañas: cerrar sesión en una y operar en otra. | La segunda detecta sesión inválida; no escribe. |

## 8. Navegación, permisos globales y experiencia base

| ID | Acción | Resultado esperado |
|---|---|---|
| NAV-01 | Abrir los 12 módulos desde menú. | Monitor, Directorio, Reloj, Tareas, Reportes, ATS, Academia, Archivo, Pre-nómina, LFT, SOP y Configuración cargan. |
| NAV-02 | Recargar cada `?module=` y usar Atrás/Adelante. | Conserva módulo válido; uno inválido vuelve a Dashboard. |
| NAV-03 | Contraer/expandir menú y usar menú móvil. | Contenido se ajusta; texto/tooltip identifica iconos. |
| NAV-04 | Abrir novedades, marcar leída y vaciar lista como PA. | Estados persisten; guardar cero novedades funciona. |
| NAV-05 | Probar carga lenta/error por módulo. | Skeleton/estado vacío/error recuperable; nunca blanco infinito. |
| NAV-06 | Cambiar tema si aplica. | Contraste y marca permanecen; política global se respeta. |
| NAV-07 | Entrar como SUP y EMP. | Sólo módulos/acciones autorizados; URL manual no los desbloquea. |
| NAV-08 | Comparar Free/Pro/Enterprise reales. | Módulos y features coinciden con servidor y página de precios. |
| NAV-09 | Deshabilitar un módulo para T-A desde PA. | Desaparece/bloquea sólo en T-A; T-B no cambia. |
| NAV-10 | Revisar todos los estados vacíos. | Explican qué falta y ofrecen una acción real, no datos inventados. |

## 9. Configuración de empresa, plan y respaldo de tenant

| ID | Acción | Resultado esperado |
|---|---|---|
| CFG-01 | Perfil: editar nombre, teléfono, zona horaria y logo permitido. | Persiste y se refleja sin cruzar tenant. |
| CFG-02 | Recorrer Onboarding, Reloj, Apertura, Comidas, Tareas, LFT, Nómina, Notificaciones, Permisos y ATS. | Todas cargan valores actuales. |
| CFG-03 | Cambiar un valor reversible por sección, recargar y restaurar. | Persistencia exacta y mensajes honestos. |
| CFG-04 | Configurar horarios nocturnos, descanso y tolerancia. | Validación permite cruce de medianoche y rechaza rangos imposibles. |
| CFG-05 | Configurar apertura, suplentes y checklist. | Asignaciones únicas y visibles en Reloj. |
| CFG-06 | Configurar comedor/aforo/turnos. | Límites se aplican en servidor. |
| CFG-07 | Configurar periodicidad y semana de nómina. | Cálculos posteriores usan la configuración nueva sólo donde corresponde. |
| CFG-08 | Editar matriz de permisos por puesto. | Privilegios reservados no pueden delegarse indebidamente. |
| CFG-09 | Abrir Plan y Módulos; cambiar plan sólo en Test. | Precio y límites vienen del tarifario, no números hardcodeados ni una venta separada de timbrado. |
| CFG-10 | Exportar respaldo JSON de tenant. | Lo declara parcial y firmado; archivo descargable y legible. |
| CFG-11 | Importar respaldo válido en tenant desechable. | Preview/confirmación, aislamiento y conteos correctos. |
| CFG-12 | Importar archivo corrupto, enorme o de otro tenant. | Rechazo seguro; no deja importación parcial. |

## 10. Monitor 360 y tiempo real

| ID | Acción | Resultado esperado |
|---|---|---|
| MON-01 | Abrir Monitor con A-PRO. | Conteos y personas pertenecen al tenant. |
| MON-02 | Fichar EMP-A desde otra sesión. | Monitor cambia en tiempo real sin recargar. |
| MON-03 | Cortar Reverb y restaurarlo. | Indica desconexión/reintenta; sincroniza al volver. |
| MON-04 | Enviar mensaje general y privado. | Sólo destinatarios correctos lo reciben; privado no aparece a terceros. |
| MON-05 | Preservar mensaje y revisar retención. | Acción y auditoría persisten. |
| MON-06 | Crear/asignar tarea manual y por voz. | Formulario/IA producen datos revisables; gating Pro correcto. |
| MON-07 | Forzar cierre de turno con permiso y sin permiso. | Sólo rol autorizado; motivo y auditoría obligatorios. |
| MON-08 | Registrar visita/proveedor y completarla. | Aparece una vez, conserva responsable y cierre. |
| MON-09 | Revisar pendientes, retardos, contingencias y pánico. | Cada tarjeta abre el registro correcto, no otro. |
| MON-10 | Cambiar fecha/zona horaria cerca de medianoche. | Dashboard usa día del tenant. |

## 11. Directorio Digital, puestos, organigrama y ciclo laboral

| ID | Acción | Resultado esperado |
|---|---|---|
| RH-01 | Listar, buscar, filtrar y ordenar activos/inactivos. | Conteos estables; limpiar filtro restaura lista. |
| RH-02 | Crear puesto mando y operativo con horarios/tolerancias. | Persisten y son seleccionables. |
| RH-03 | Crear área, vincularla y luego desvincularla. | No deja referencias fantasma. |
| RH-04 | Intentar borrar puesto con colaboradores/vacantes. | Bloqueo explicado; no borra relaciones. |
| RH-05 | Crear EMP-A con nombre acentuado, correo, alta, sueldo y periodicidad. | Una ficha; correo normalizado; datos laborales completos. |
| RH-06 | Crear homónimo/duplicado de correo. | Advierte y nunca pisa silenciosamente. |
| RH-07 | Editar ficha Personal/Laboral/Accesos y recargar. | Todo persiste; editar no apaga cuenta. |
| RH-08 | Cambiar puesto y jefe. | Organigrama y permisos se actualizan sin ciclos. |
| RH-09 | Intentar autorreporte y ciclo A→B→A. | Rechazado con explicación. |
| RH-10 | Generar invitación/PIN/QR y activar cuenta. | PIN de un uso, contraseña fuerte, usuario correcto. |
| RH-11 | Probar PIN erróneo, vencido y reutilizado. | Throttle y rechazo sin filtrar datos. |
| RH-12 | Configurar/cambiar PIN de kiosco. | Nunca se muestra el PIN guardado; bloquea PIN trivial/repetido. |
| RH-13 | Importar plantilla CSV válida con preview. | Conteos/errores por fila; una sola inserción. |
| RH-14 | Importar duplicados, columnas faltantes y archivo malicioso. | Rechazo por fila/archivo; no hay datos parciales inesperados. |
| RH-15 | Inactivar EMP-I con motivo. | Pierde acceso, sale de activos y conserva historial. |
| RH-16 | Reincorporar EMP-I. | Limpia baja, conserva historial y no duplica usuario. |
| RH-17 | Intentar eliminación definitiva con historial. | Archiva/protege según retención; nunca borra historial legal. |
| RH-18 | Marcar reserva legal y levantarla con motivos. | Ambos eventos auditados; purga no alcanza reserva activa. |
| RH-19 | Ejecutar simulacro de purga vencida. | Lista candidatos sin borrar; `⚠ --aplicar` sólo en copia aislada. |
| RH-20 | Revisar Mi Equipo, buzones y restricciones SUP/EMP. | Alcance jerárquico exacto; sin fichas ajenas. |

## 12. Reloj, asistencia, apertura, kiosco y contingencias

| ID | Acción | Resultado esperado |
|---|---|---|
| CLK-01 | Abrir reloj con EMP-A y comparar horario/tolerancia. | Coincide con Configuración y servidor. |
| CLK-02 | Registrar entrada normal. | Un movimiento; hora servidor; Monitor actualizado. |
| CLK-03 | Doble clic y dos pestañas en Entrada. | Idempotente; no duplica fichaje. |
| CLK-04 | Registrar entrada tarde dentro/fuera de tolerancia. | Estado y minutos coinciden con reporte/nómina. |
| CLK-05 | Probar retardo extremo y autorización SUP/PIN/QR. | Bloquea hasta autorización válida; auditoría completa. |
| CLK-06 | Solicitar/aprobar/rechazar justificante de retardo. | Sólo aprobado exime; reportes reflejan diferencia. |
| CLK-07 | Iniciar/terminar descanso Ley Silla. | Secuencia válida, duración y solicitudes correctas. |
| CLK-08 | Reservar comida, cambiar turno y probar aforo lleno. | Servidor evita sobrecupo y doble reserva. |
| CLK-09 | Iniciar/terminar comida con exceso. | Exceso exacto y evidencia protegida. |
| CLK-10 | Probar salida temprana con y sin autorización. | Regla/razón/auditoría correctas. |
| CLK-11 | Probar horas extra con y sin autorización/tope semanal. | No paga ni permite excedente no autorizado. |
| CLK-12 | Registrar salida normal. | Jornada completa, horas coherentes y sin segundo checkout. |
| CLK-13 | Dejar salida huérfana hasta barrido. | `auto_closed`, alerta y no inventa trabajo adicional. |
| CLK-14 | Turno que cruza medianoche y día de descanso. | Se atribuye al periodo/día correcto. |
| CLK-15 | GPS dentro/fuera del perímetro y permiso denegado. | Política real, alternativa/explicación y sin ubicación falsa. |
| CLK-16 | Selfie requerida, cancelada y archivo inválido. | No ficha si es obligatoria; imagen privada y tamaño limitado. |
| CLK-17 | Desconectar red, acumular fichajes offline y reconectar. | Cola HMAC, orden correcto, sin atasco ni duplicados. |
| CLK-18 | Enviar batch con un movimiento inválido. | Resultado por elemento; los válidos no se duplican al reintentar. |
| CLK-19 | Abrir sucursal por portador asignado. | Ventana/tolerancia y responsable correctos. |
| CLK-20 | Ausencia/retardo del portador y suplente. | Avisos y reasignación siguen configuración. |
| CLK-21 | Apertura de emergencia con testigos/PIN. | Exige reglas de seguridad y registra motivo. |
| CLK-22 | Cerrar con checklist incompleto/completo. | Sólo los puntos obligatorios bloquean; queda evidencia. |
| CLK-23 | Cerrar y reabrir el mismo día. | Reapertura válida sin borrar primer ciclo. |
| CLK-24 | Declarar contingencia sin luz/internet y resolver. | No equivale a fichaje; pago sólo tras resolución permitida. |
| CLK-25 | Activar botón de pánico y resolver. | Incidente privado, visible a responsables y auditable. |
| CLK-26 | Transferir llaves y responder aceptar/rechazar. | Sólo usuarios válidos; estado en vivo y sin doble titular. |
| CLK-27 | Kiosco: login PIN correcto/incorrecto/repetido. | Throttle por empleado; no enumera personas. |
| CLK-28 | Cerrar sesión del dispositivo y usar Atrás. | Borra cache local sensible y exige autenticación. |
| CLK-29 | Matrix: recorrer 23 estados y salir. | Todo marcado como simulación; cero escrituras en asistencia/nómina real. |
| CLK-30 | Salir de Matrix, recargar y fichar real. | Sandbox queda apagado; el fichaje real persiste. |

## 13. Tareas, rutinas, evidencia y recompensas

| ID | Acción | Resultado esperado |
|---|---|---|
| TASK-01 | Crear tarea manual con todos los campos. | Responsable, plazo, prioridad, pasos y evidencia persisten. |
| TASK-02 | Generar tarea con IA y editar antes de guardar. | IA propone; humano controla; no guarda automáticamente. |
| TASK-03 | Asignar por usuario, puesto y “al vuelo”. | Sólo destinatarios del tenant. |
| TASK-04 | Crear/editar/activar/desactivar rutina programada. | Una programación; zona horaria correcta. |
| TASK-05 | Iniciar, pausar, reanudar y completar como EMP. | Máquina de estados válida y marcas de tiempo correctas. |
| TASK-06 | Completar con foto/archivo requerido y sin él. | Requisito real; archivo privado y validado. |
| TASK-07 | Validar/rechazar como SUP con comentario. | Rechazo vuelve al estado correcto; comentario llega al colaborador. |
| TASK-08 | Validar con PIN y probar PIN ajeno/incorrecto. | Sólo supervisor autorizado; throttle. |
| TASK-09 | Omitir tarea con motivo. | Notifica al supervisor y no otorga recompensa. |
| TASK-10 | Dejar incompleta y resolver pendiente. | Gate y resolución según política; auditoría. |
| TASK-11 | Confirmar monedas/XP. | Se abonan una sola vez al validar, nunca al terminar/reintentar. |
| TASK-12 | Probar aislamiento y edición concurrente. | T-B no ve nada; conflicto no pisa cambios silenciosamente. |

## 14. Academia 360 e inducción

| ID | Acción | Resultado esperado |
|---|---|---|
| LMS-01 | Ver catálogo, filtros, inducción, capacitación, promoción y diplomas. | Contenido y estados coherentes; vacío explícito. |
| LMS-02 | Importar plantilla del giro. | Un curso real, no “simulado” en producción. |
| LMS-03 | Crear/editar/publicar curso con lecciones y examen. | Borrador/publicado y destinatarios persisten. |
| LMS-04 | Asignar por persona/puesto/toda plantilla. | Sólo destinatarios correctos, sin duplicados. |
| LMS-05 | Avanzar lecciones y recargar como EMP. | Progreso servidor, no sólo navegador. |
| LMS-06 | Enviar examen incompleto/incorrecto/correcto. | Exige todas y 100 % según regla vigente. |
| LMS-07 | Reintentar examen según política. | Intentos y reset admin auditados. |
| LMS-08 | Generar certificado y verificar folio sin sesión. | Folio único; revocado/no válido no aparece como vigente. |
| LMS-09 | Contratar desde ATS. | Inducción se asigna postcontratación como curso Academia. |
| LMS-10 | Bloqueo por inducción/retardos si aplica. | Explica curso pendiente; se libera sólo tras completar. |

## 15. ATS, portal público, candidatos y contratación

| ID | Acción | Resultado esperado |
|---|---|---|
| ATS-01 | Configurar marca, textos y datos públicos del portal. | Preview y página pública coinciden; sin datos internos. |
| ATS-02 | Crear vacante borrador con puesto/ubicación/requisitos. | Una vacante; validación clara. |
| ATS-03 | Editar/publicar/despublicar vacante. | URL/estado persistentes y SEO básico correcto. |
| ATS-04 | Abrir vacante en incógnito. | Sólo información pública del tenant correcto. |
| ATS-05 | Postular candidato con archivo válido. | Una candidatura, consentimiento y archivo privado. |
| ATS-06 | Probar archivo inválido, doble envío y campos maliciosos. | Rechazo/antiduplicado/sanitización. |
| ATS-07 | Mover candidato por etapas y recargar. | Estado persiste; columnas no permitidas no aceptan drop. |
| ATS-08 | Rechazar y recuperar candidato. | Sale/entra del tablero sin perder historial. |
| ATS-09 | Programar, editar y cancelar entrevista. | Agenda correcta, zona horaria y permisos. |
| ATS-10 | Contratar candidato. | Un colaborador, correo correcto, puesto real y cursos anunciados. |
| ATS-11 | Repetir contratación/doble clic. | Idempotente; no crea dos empleados. |
| ATS-12 | Crear/recibir alerta de vacantes pública. | Consentimiento, tenant y desuscripción cuando aplique. |

## 16. Archivo Digital

| ID | Acción | Resultado esperado |
|---|---|---|
| DOC-01 | Abrir expedientes/corporativos y estados vacíos. | Separación clara y permisos correctos. |
| DOC-02 | Subir PDF/JPG/PNG válido al expediente. | Metadatos, categoría, persona y tenant correctos. |
| DOC-03 | Descargar con dueño/admin y probar sin sesión/otro tenant. | Sólo autorizados; URL no es pública. |
| DOC-04 | Probar extensión falsa, ejecutable, >10 MB y nombre extraño. | Rechazo seguro; sin archivo huérfano. |
| DOC-05 | Validar/rechazar documento con motivo. | Estado y comentario persisten. |
| DOC-06 | Editar metadatos y vincular manual corporativo. | No duplica archivo; relaciones correctas. |
| DOC-07 | ⚠ Eliminar documento QA y usar enlace antiguo. | Eliminado de UI/almacenamiento; enlace deja de servir; auditoría. |
| DOC-08 | Inactivar empleado con expediente. | Documentos se conservan según retención. |

## 17. Organigrama y SOP / baúl organizacional

| ID | Acción | Resultado esperado |
|---|---|---|
| SOP-01 | Abrir Leer, Cambios, Editar, Sync y Matriz. | Vistas según permiso; sin 403 inesperado. |
| SOP-02 | Crear/editar/reordenar documento SOP. | Contenido sanitizado, versión y orden persistentes. |
| SOP-03 | Enviar sugerencia pública/interna y aprobar/rechazar. | Autor, tenant, estado y comentario correctos. |
| SOP-04 | Configurar matriz de visibilidad por puesto. | EMP ve sólo documentos permitidos. |
| SOP-05 | Crear/editar/eliminar usuario público del manual. | Acceso aislado y contraseña/passcode protegido. |
| SOP-06 | Probar URL pública con slug válido/ajeno/inexistente. | Sin filtración entre tenants. |
| SOP-07 | Registrar lectura/progreso. | Conteo correcto e idempotente. |
| SOP-08 | Generar/responder examen y revisar resultados. | Preguntas/resultado pertenecen al documento/usuario. |
| SOP-09 | Usar copiloto/cronista. | No revela documentos fuera de visibilidad; salida sanitizada. |
| SOP-10 | Sincronizar ZIP válido y ZIP malicioso/path traversal. | Sólo formatos/rutas permitidos; rollback ante error. |
| SOP-11 | Reconstruir caché/enlaces. | No pierde documentos ni permisos. |
| SOP-12 | ⚠ Purgar baúl en tenant desechable con respaldo. | Confirmación fuerte; sólo el tenant objetivo; restauración comprobada. |

## 18. Reportes, nómina operativa y referencia fiscal

| ID | Acción | Resultado esperado |
|---|---|---|
| REP-01 | Abrir catálogo Básicos/Avanzados por rol/plan. | Sólo reportes permitidos; descripción honesta. |
| REP-02 | Generar asistencia CSV/XLSX/PDF en mismo periodo. | Mismas filas, tenant, zona horaria y totales. |
| REP-03 | Repetir para tareas, horas, retardos y registro de jornada. | Cuadra contra eventos creados en esta ronda. |
| REP-04 | Repetir para aperturas, comedor, justificantes y rutinas. | Cuadra con casos CLK/TASK. |
| REP-05 | Repetir para expedientes, academia, reclutamiento y monedero. | Conteos y filtros correctos. |
| REP-06 | Generar costo por puesto, rotación y nómina histórica. | Salarios/periodicidades y archivados correctos. |
| REP-07 | Generar Pre-nómina para Contador. | PROVISIONAL/FIRMADO separados, vigencia fiscal y leyenda “no timbra”. |
| REP-08 | Comparar retardo justificado vs asistencia. | Justificado no se cobra; asistencia histórica permanece. |
| REP-09 | Probar rango vacío, invertido, enorme y futuro. | 422/explicación; nunca 500. |
| REP-10 | Autorizar nómina y volver a editar asistencia del periodo. | Neto firmado no cambia; genera ajuste/auditoría según alcance vigente. |
| REP-11 | Aprobar vista del empleado. | EMP sólo ve/acepta sus registros y recibos. |
| REP-12 | Probar tenant B con IDs de reportes de T-A. | 404/403; cero datos cruzados. |

## 19. LFT y cumplimiento

| ID | Acción | Resultado esperado |
|---|---|---|
| LFT-01 | Editar tolerancias/reglas y recargar. | Persistencia y efecto prospectivo coherente. |
| LFT-02 | Crear/editar/eliminar festivo QA. | Sin duplicados; cálculo laboral correcto. |
| LFT-03 | Leer/generar propuesta de reglamento. | Texto sanitizado y alcance explicado; no sustituye asesoría legal. |
| LFT-04 | Validar Ley Silla, descansos y excesos. | Misma regla en reloj, reporte y nómina. |
| LFT-05 | Revisar reserva/purga de cinco años. | Juicio abierto protege; simulacro no borra. |
| LFT-06 | Verificar tablas fiscales 2026 y periodos fuera de vigencia. | Advierte cuando debe recalcularse; no presenta estimación como definitiva. |

## 20. Facturación del SaaS y “Nómina CFDI” apagada

| ID | Acción | Resultado esperado |
|---|---|---|
| BILL-01 | Abrir módulo actual de facturación. | Debe presentarse como orientación/pre-nómina, no como timbrado disponible. |
| BILL-02 | Abrir estado de timbrado sin proveedor/CSD. | Explica que está desactivado; 503 deliberado, nunca “éxito”. |
| BILL-03 | Buscar controles de carga de CSD o timbrado de nómina. | No existen mientras la función esté apagada; no hay forma de entregar secretos fiscales. |
| BILL-04 | Revisar historial/facturas del SaaS. | Sólo cargos del tenant y montos del tarifario. |
| BILL-05 | Pago Test aprobado. | Actualiza customer, subscription, corte y estado una sola vez. |
| BILL-06 | Webhook Test repetido/desordenado. | Idempotente; firma obligatoria. |
| BILL-07 | Webhook sin firma/firma inválida. | 400; no cambia tenant. |
| BILL-08 | Simular `past_due`. | Banner sólo admin, inicia gracia de 5 días, reloj sigue durante gracia. |
| BILL-09 | Simular fin de gracia. | Suspensión automática y bloqueo esperado sin borrar datos. |
| BILL-10 | Registrar pago posterior. | Reactiva suspensión por deuda; no reactiva suspensión manual. |
| BILL-11 | Confirmar simulador de checkout con Stripe configurado. | Inerte/no accesible; no crea empresas simuladas. |
| BILL-12 | Confirmar que Live permanece pendiente. | Cero llaves/cargos Live hasta autorización del dueño. |

## 21. Soporte, chat, avisos y sugerencias

| ID | Acción | Resultado esperado |
|---|---|---|
| COM-01 | Chat operativo general/privado y dos tenants. | Tiempo real e aislamiento correctos. |
| COM-02 | Crear ticket de soporte como tenant. | Un ticket, prioridad/estado inicial y datos mínimos. |
| COM-03 | Responder/asignar/cerrar como PA. | Historial y notas internas no visibles al cliente cuando corresponda. |
| COM-04 | Usar copiloto de soporte con texto hostil/inyección. | Trata contenido como datos; no ejecuta ni revela secretos. |
| COM-05 | Enviar buzón/feedback anónimo y con sesión. | Privacidad, rate limit y alcance correctos. |
| COM-06 | Abrir novedades de producto y enlaces. | Contenido sanitizado; módulo destino permitido. |

## 22. Consola Platform Admin

| ID | Acción | Resultado esperado |
|---|---|---|
| PA-01 | Abrir estadísticas, tenants, alertas y auditorías. | Totales reales; ninguna empresa omitida/duplicada. |
| PA-02 | Buscar/filtrar/abrir T-A y T-B. | Detalle correcto y estable al volver. |
| PA-03 | Editar perfil del tenant QA. | Sólo campos permitidos; auditoría. |
| PA-04 | Habilitar/deshabilitar módulos y funciones de T-A. | Efecto sólo T-A y consistente backend/frontend. |
| PA-05 | Alternar estado del tenant. | Suspende/reactiva según motivo; sesiones y reloj responden correctamente. |
| PA-06 | Impersonar tenant QA y salir. | Banner visible, alcance limitado, retorno seguro; toda acción auditada. |
| PA-07 | Revocar todas las sesiones de tenant QA. | Tokens dejan de servir en todas las pestañas/dispositivos. |
| PA-08 | Banear/desbanear dispositivo QA. | Sólo el dispositivo indicado; mensaje y recuperación claros. |
| PA-09 | Revisar registros pendientes y ⚠ borrar uno QA. | Libera correo sin tocar usuarios completos. |
| PA-10 | Crear/editar/eliminar promoción QA. | Fechas, audiencia y visualización correctas. |
| PA-11 | Crear novedades, guardar lista vacía y volver a agregar. | Las tres operaciones persisten. |
| PA-12 | Editar configuración Freemium. | Nuevos tenants obedecen; existentes según contrato definido. |
| PA-13 | Editar simulador de landing. | Landing pública cambia sólo en contenido permitido. |
| PA-14 | Revisar reclamos sociales y gracia. | Aprobar/rechazar una vez, con auditoría. |
| PA-15 | Crear factura manual Test y revisar historial. | Monto/tenant/estado exactos, sin cobro Live. |
| PA-16 | Configuración bancaria/email sin guardar secretos en UI/logs. | Valores sensibles enmascarados y permisos estrictos. |
| PA-17 | Tickets: asignar agente, prioridad, nota y estado. | Flujo e historial completos. |
| PA-18 | ⚠ Eliminar tenant desechable recién respaldado. | Confirmación con nombre; sólo objetivo; volúmenes/otros tenants intactos. |

## 23. Planes, límites, permisos y aislamiento multiempresa

| ID | Acción | Resultado esperado |
|---|---|---|
| SEC-01 | Freemium: crear hasta límite y uno adicional. | Límite servidor, mensaje de upgrade; no inserta extra. |
| SEC-02 | Pro/Enterprise: verificar módulos/flags reales. | Coinciden con tarifario y overrides. |
| SEC-03 | Cambiar plan/trial y recargar todas las sesiones. | Gating inmediato y consistente; no borra datos al bajar. |
| SEC-04 | SUP intenta admin, facturación, permisos y PA por UI/URL/API. | 403/oculto; ninguna escritura. |
| SEC-05 | EMP intenta datos de otro empleado. | 403/404 sin confirmar existencia. |
| SEC-06 | Reutilizar IDs T-A autenticado en T-B en empleados, tareas, archivos, ATS, SOP y reportes. | Cero lectura/escritura cruzada. |
| SEC-07 | Modificar `tenant_id`, rol, precio o estado en payload. | Servidor ignora/rechaza campos protegidos. |
| SEC-08 | Probar CSRF/CORS desde origen no autorizado. | Rechazo; cookies/tokens con atributos correctos. |
| SEC-09 | Revisar almacenamiento navegador. | Sin contraseñas, llaves, CSD ni PII innecesaria. |
| SEC-10 | Revisar errores/logs/respuestas. | Sin stack traces, SQL, secretos o rutas internas. |
| SEC-11 | Probar uploads con doble extensión, SVG activo, ZIP traversal y nombre largo. | Rechazo/sanitización y almacenamiento privado. |
| SEC-12 | Probar rate limits en login, PIN, kiosco, público, IA y webhooks. | Límite específico y recuperación correcta. |
| SEC-13 | Probar enlaces públicos revocados/vencidos. | Dejan de servir inmediatamente según contrato. |
| SEC-14 | Confirmar bitácora inmutable con rol de aplicación. | App no puede UPDATE/DELETE del historial; migraciones sí. |

## 24. PWA, móvil, offline y permisos del dispositivo

| ID | Acción | Resultado esperado |
|---|---|---|
| MOB-01 | Instalar PWA Android/iOS si aplica. | Nombre, icono, splash y alcance correctos. |
| MOB-02 | Abrir desde icono y enlace profundo de módulo. | Sesión/ruta correctas; no abre doble interfaz. |
| MOB-03 | Navegar a 320/390 px por módulos críticos. | Sin controles fuera de pantalla; dock no tapa CTA. |
| MOB-04 | Cambiar orientación y teclado abierto. | Formulario usable; modal no queda inaccesible. |
| MOB-05 | Denegar/permitir cámara, ubicación y notificaciones. | Explicación y reintento; no bucle de permisos. |
| MOB-06 | Modo avión antes/durante/después de una acción. | Sólo acciones soportadas se encolan; otras fallan honestamente. |
| MOB-07 | Cerrar proceso y reabrir con cola pendiente. | Cola persiste de forma segura y sincroniza una vez. |
| MOB-08 | Publicar nueva versión con PWA abierta. | Actualización controlada; no mezcla chunks ni pantalla blanca. |
| MOB-09 | Probar dispositivo compartido/kiosco y cambio de usuario. | Cache/fotos/datos del usuario anterior no aparecen. |
| MOB-10 | Batería/reloj del dispositivo incorrectos. | Servidor manda fecha efectiva; no acepta fichaje futuro inventado. |

## 25. Accesibilidad y UX exigente

| ID | Acción | Resultado esperado |
|---|---|---|
| A11Y-01 | Recorrer cada pantalla sólo con teclado. | Todo accionable; orden/foco visible; Escape cierra modal. |
| A11Y-02 | Lector de pantalla en login, navegación, tablas, formularios y estados. | Nombre/rol/estado útiles; cambios dinámicos anunciados. |
| A11Y-03 | Zoom 200 % y texto grande móvil. | Sin pérdida de contenido/función. |
| A11Y-04 | Contraste normal/hover/focus/disabled/error. | Cumple AA; no depende sólo de color. |
| A11Y-05 | Objetivos táctiles. | Acciones principales ≥44 px; ninguna esencial <24 px. |
| A11Y-06 | Formularios con errores múltiples. | Resumen/foco al primer error; mensajes ligados al campo. |
| A11Y-07 | Modal largo y tabla ancha. | Scroll interno claro, cabecera/acciones alcanzables. |
| A11Y-08 | Estados carga/vacío/error/éxito. | Consistentes, sin `alert()` bloqueante como única respuesta. |
| A11Y-09 | Inspeccionar módulos críticos a zoom normal y 200 %. | No reaparece texto esencial menor a 12 px y la interfaz conserva jerarquía. |
| A11Y-10 | Revisar iconos y estados. | Icono del sistema + etiqueta/tooltip; ningún emoji funciona como control. |

## 26. Rendimiento, concurrencia y resiliencia

| ID | Acción | Resultado esperado |
|---|---|---|
| PERF-01 | Medir landing/login/app en carga fría y caliente. | Sin bloqueo prolongado; LCP/CLS/TBT dentro de objetivo acordado. |
| PERF-02 | Abrir módulo pesado por primera vez. | Lazy chunk carga con indicador; error de chunk recuperable. |
| PERF-03 | Lista con 1, 100 y volumen objetivo de empleados/tareas. | Scroll/filtros utilizables y sin congelamiento. |
| PERF-04 | Dos admins editan el mismo registro. | Conflicto visible o última escritura definida; nunca mezcla parcial. |
| PERF-05 | Doble clic/reintento en crear, pagar, fichar, contratar y validar. | Idempotencia en todas las operaciones críticas. |
| PERF-06 | Latencia alta, pérdida 5 % y desconexión temporal. | Loading/cancelación/reintento; sin éxito falso. |
| PERF-07 | Reiniciar backend/Reverb con frontend abierto. | Reconecta; sesión y datos consistentes. |
| PERF-08 | Respuesta 401/403/404/409/422/429/500 simulada. | Mensaje específico y recuperable; no pantalla blanca. |
| PERF-09 | Revisar memoria tras navegar por todos los módulos. | Sin crecimiento sostenido, timers/listeners duplicados. |
| PERF-10 | Ejecutar build, pruebas, lint y auditorías dos veces. | Resultado determinista; lint crítico y advisories P1 en cero. |

## 27. Respaldo, restauración y recuperación operativa

| ID | Acción | Resultado esperado |
|---|---|---|
| DR-01 | Verificar respaldo automático, hash/tamaño y antigüedad. | Archivo reciente, no vacío y fuera del contenedor. |
| DR-02 | Restaurar el respaldo en instancia/BD aislada. | Empresas, usuarios y conteos esenciales recuperados. |
| DR-03 | Validar archivos subidos además de PostgreSQL. | Respaldo incluye/metadocumenta almacenamiento requerido. |
| DR-04 | Probar rollback de un despliegue. | Código anterior inicia contra esquema compatible; salud verde. |
| DR-05 | Simular disco casi lleno/caché Docker. | Alerta antes de afectar PostgreSQL; limpieza no toca datos. |
| DR-06 | Revisar cron y monitor de respaldo. | Fallo genera señal accionable, no silencio. |
| DR-07 | Documentar RPO/RTO reales medidos. | Dueño sabe cuánto dato/tiempo puede perder. |
| DR-08 | Configurar y probar copia externa cuando estén las llaves. | Backblaze/alternativa cifrada, retención y restauración comprobadas. |

## 28. Cierre de ronda

| ID | Acción | Resultado esperado |
|---|---|---|
| END-01 | Restaurar ajustes anotados en PRE-05. | Entorno vuelve al baseline. |
| END-02 | Retirar datos QA que sea seguro retirar. | Sólo prefijo QA; historial legal se archiva, no se fuerza. |
| END-03 | Reejecutar `/api/health`, suites, build, lint y audits. | Sin regresión y respaldo posterior vigente. |
| END-04 | Reprobar cada P0/P1 corregido y dos flujos vecinos. | Corrección real, no parche local. |
| END-05 | Congelar commit candidato y generar reporte final. | Matriz completa, evidencias, riesgos aceptados y firma de salida. |

## 29. Formato para reportar durante la ejecución

Usar una línea por acción:

```text
CLK-08 | FALLÓ | iPhone/Safari PWA | Al reservar 13:00 duplicó la reserva | captura + hora 14:22
```

Para fallos P0/P1 añadir:

```text
Datos previos:
Pasos exactos:
Resultado observado:
Resultado esperado:
Usuario/rol/tenant:
Navegador/dispositivo:
Hora y zona horaria:
¿Persiste al recargar?:
¿Ocurre en otro tenant/rol?:
```

## 30. Orden recomendado de ejecución

1. PRE → PUB → REG/ONB → AUTH.
2. NAV/CFG → RRHH.
3. Reloj/Monitor con dos sesiones.
4. Tareas → Academia → ATS → Documentos → SOP.
5. Reportes/LFT/Facturación Test.
6. Platform Admin y aislamiento multiempresa.
7. Móvil/PWA → accesibilidad → rendimiento/resiliencia.
8. Respaldo/restauración → limpieza → cierre.

Estimación realista: **14–20 horas** de ejecución manual, repartidas en 2–3 días, más el tiempo de
corrección y reprueba. Intentar hacerlo de una sola sentada aumenta falsos positivos y omisiones.
