# QA funcional viva — Talent360

Fecha de inicio: 2026-09-10

Fuente de alcance: componentes, rutas y servidor actuales; no documentos históricos.
Responsables: Adán ejecuta la experiencia real; Codex registra, contrasta con código, corrige y verifica.

## Regla de trabajo

- Se prueba **una acción por vez**. No se avanza si una acción bloqueante falla.
- Usar sólo la empresa y usuarios creados para pruebas. Nunca alterar una empresa que ya opere.
- Todo dato nuevo llevará el prefijo `QA-` y la fecha para poder identificarlo y retirarlo.
- No compartir contraseñas, códigos, llaves, archivos `.p8`, CSD ni datos bancarios en el chat.
- Adán responde: `ID | PASÓ o FALLÓ | qué ocurrió`. Si falló, agrega captura y texto visible.
- Codex actualiza esta tabla al recibir cada resultado. Una corrección sólo se marca resuelta después
  de reproducirla, probar el arreglo y verificar el servidor desplegado.

Estados: `PENDIENTE`, `PASÓ`, `FALLÓ`, `BLOQUEADO`, `NO APLICA`.

## Fuera de esta ronda

- Stripe en modo Live y un cargo real.
- Google y Apple hasta cargar sus credenciales oficiales.
- Acceso social del superadmin: por decisión del dueño se prueba únicamente con contraseña.
- Respaldo externo Backblaze B2 hasta crear bucket y llaves.
- Emisión fiscal real, movimientos bancarios o cualquier acción sobre datos de una empresa operativa.

## Precondición

- Navegador en `https://talent360.com.mx/login`.
- Cuenta administradora de la empresa creada con Stripe **Test**.
- Escritorio primero; la revisión móvil se hace al final.

## 1. Acceso y sesión

| ID | Acción manual | Resultado esperado | Estado | Evidencia / notas |
|---|---|---|---|---|
| ACC-01 | Abrir `/login` sin sesión. | Sólo aparecen correo/contraseña; Google y Apple se ocultan sin credenciales; no hay Samsung ni huella. | PASÓ | Verificado en la web desplegada el 2026-09-10: sólo correo, contraseña, recuperación y avisos legales. |
| ACC-02 | Intentar entrar dejando ambos campos vacíos. | El formulario exige los datos y no navega. | PENDIENTE | |
| ACC-03 | Escribir correo válido y contraseña incorrecta. | Mensaje genérico; no revela datos internos ni inicia sesión. | PENDIENTE | |
| ACC-04 | Usar el icono de mostrar/ocultar contraseña. | Cambia visibilidad sin borrar el valor. | PENDIENTE | |
| ACC-05 | Abrir “¿Olvidaste tu contraseña?” y volver. | Carga recuperación y permite regresar al login. | PENDIENTE | |
| ACC-06 | Entrar con la cuenta administradora de prueba. | Abre la empresa correcta sin token ni contraseña en la URL. | PENDIENTE | |
| ACC-07 | Recargar la página ya autenticada. | Conserva la sesión y vuelve al módulo actual. | PENDIENTE | |
| ACC-08 | Cerrar sesión y usar Atrás del navegador. | No vuelve a mostrar datos privados; exige iniciar sesión. | PENDIENTE | |

## 2. Dashboard y navegación principal

| ID | Acción manual | Resultado esperado | Estado | Evidencia / notas |
|---|---|---|---|---|
| NAV-01 | Abrir Dashboard. | Carga sin pantalla blanca ni error; los totales pertenecen a la empresa de prueba. | PENDIENTE | |
| NAV-02 | Abrir cada tarjeta/enlace visible del Dashboard y regresar. | Lleva al módulo correcto y la URL refleja `module=`. | PENDIENTE | |
| NAV-03 | Contraer y expandir el menú lateral. | El contenido se ajusta y todos los módulos siguen accesibles. | PENDIENTE | |
| NAV-04 | Recargar en un módulo distinto de Dashboard. | Permanece en ese módulo; no queda una pantalla vacía. | PENDIENTE | |

## 3. Recursos Humanos

| ID | Acción manual | Resultado esperado | Estado | Evidencia / notas |
|---|---|---|---|---|
| RRHH-01 | Abrir Colaboradores y cambiar entre activos/inactivos. | Listas coherentes, sin mezclar empresas. | PENDIENTE | |
| RRHH-02 | Buscar y ordenar colaboradores. | La lista responde y no pierde registros al limpiar el filtro. | PENDIENTE | |
| RRHH-03 | Abrir una ficha y recorrer sus secciones sin guardar. | Datos, puesto, horario y expediente corresponden a la persona elegida. | PENDIENTE | |
| RRHH-04 | Crear `QA-Colaborador-20260910` con correo de prueba. | Se crea una sola vez y aparece en el directorio. | PENDIENTE | |
| RRHH-05 | Editar un dato no sensible del colaborador QA y recargar. | El cambio persiste. | PENDIENTE | |
| RRHH-06 | Suspender el colaborador QA. | Pasa a inactivos y se apaga su acceso/asistencia. | PENDIENTE | |
| RRHH-07 | Reactivar el colaborador QA. | Vuelve a activos sin duplicarse. | PENDIENTE | |
| RRHH-08 | Abrir Puestos; crear y editar `QA-Puesto-20260910`. | Puesto persistente y seleccionable. | PENDIENTE | |
| RRHH-09 | Abrir Organigrama y cambiar entre jerarquía/reporta a. | La estructura coincide y no deja nodos huérfanos inesperados. | PENDIENTE | |
| RRHH-10 | Abrir Mi Equipo y Buzones. | Cargan sin 403/500; el contenido respeta el rol. | PENDIENTE | |

## 4. ATS

| ID | Acción manual | Resultado esperado | Estado | Evidencia / notas |
|---|---|---|---|---|
| ATS-01 | Abrir Vacantes, Tablero, Agenda y Pública. | Las cuatro pestañas cargan y la URL conserva la pestaña. | PENDIENTE | |
| ATS-02 | Crear `QA-Vacante-20260910` sin publicarla. | Se guarda una sola vacante en borrador. | PENDIENTE | |
| ATS-03 | Editar la vacante QA y recargar. | Los cambios persisten. | PENDIENTE | |
| ATS-04 | Publicar la vacante QA y abrir su página pública en incógnito. | Sólo se ve información pública de esa empresa. | PENDIENTE | |
| ATS-05 | Enviar una candidatura QA desde la página pública. | Aparece una sola vez en el tablero. | PENDIENTE | |
| ATS-06 | Mover al candidato entre etapas y recargar. | La etapa persiste sin duplicación. | PENDIENTE | |
| ATS-07 | Programar y luego editar una entrevista QA. | Aparece correctamente en Agenda. | PENDIENTE | |

## 5. Tareas y rutinas

| ID | Acción manual | Resultado esperado | Estado | Evidencia / notas |
|---|---|---|---|---|
| OPE-01 | Abrir Tareas y Rutinas; usar búsqueda. | Ambas listas cargan y el filtro se puede limpiar. | PENDIENTE | |
| OPE-02 | Crear `QA-Tarea-20260910` y asignarla al colaborador QA. | La tarea aparece una sola vez y conserva responsable/plazo. | PENDIENTE | |
| OPE-03 | Editar la tarea QA y recargar. | El cambio persiste. | PENDIENTE | |
| OPE-04 | Completar/validar la tarea desde el flujo permitido. | Estado, hora y responsable quedan correctos. | PENDIENTE | |
| OPE-05 | Crear `QA-Rutina-20260910`, activarla y desactivarla. | Guarda la programación y respeta el interruptor. | PENDIENTE | |

## 6. Academia LMS

| ID | Acción manual | Resultado esperado | Estado | Evidencia / notas |
|---|---|---|---|---|
| LMS-01 | Abrir Todos, Inducción, Capacita, Promoción y Diplomas. | Los filtros son coherentes; Academia no se presenta como vacía por defecto. | PENDIENTE | |
| LMS-02 | Abrir un curso precargado y revisar contenido. | El curso muestra contenido y destinatarios correctos. | PENDIENTE | |
| LMS-03 | Crear `QA-Curso-20260910` sin publicarlo. | Se guarda un solo borrador. | PENDIENTE | |
| LMS-04 | Asignar el curso QA al colaborador QA. | La asignación aparece para la persona correcta. | PENDIENTE | |
| LMS-05 | Recorrer progreso/examen/certificado con datos QA. | Estados y porcentajes avanzan sin regalar aprobación. | PENDIENTE | |

## 7. Documentos y organización

| ID | Acción manual | Resultado esperado | Estado | Evidencia / notas |
|---|---|---|---|---|
| DOC-01 | Abrir Expedientes y Corporativo. | Ambas pestañas cargan y separan documentos personales/corporativos. | PENDIENTE | |
| DOC-02 | Abrir el expediente del colaborador QA. | Sólo muestra documentos de esa persona y empresa. | PENDIENTE | |
| DOC-03 | Subir un PDF QA sin datos reales y descargarlo. | El archivo descargado coincide y no es público sin sesión. | PENDIENTE | |
| DOC-04 | Abrir Organigrama y SOP: Leer, Cambios, Editar, Sync y Matriz. | Las cinco vistas cargan según permisos. | PENDIENTE | |
| DOC-05 | Crear/editar una página `QA-SOP-20260910` y abrir vista pública si aplica. | Persiste y la vista pública no expone contenido privado. | PENDIENTE | |

## 8. Reloj, asistencia y Matrix QA

| ID | Acción manual | Resultado esperado | Estado | Evidencia / notas |
|---|---|---|---|---|
| REL-01 | Abrir Reloj Checador como admin y revisar estado actual. | Carga jornada, horario y estado sin datos de otra empresa. | PENDIENTE | |
| REL-02 | Abrir el flujo del colaborador QA sin registrar un fichaje real. | Las advertencias y permisos corresponden a su configuración. | PENDIENTE | |
| REL-03 | Ejecutar entrada/salida sólo dentro de Matrix QA. | El movimiento queda marcado como simulación y no afecta nómina real. | PENDIENTE | |
| REL-04 | Probar descanso/comida en Matrix QA. | Cronómetros y estados siguen una secuencia válida. | PENDIENTE | |
| REL-05 | Recargar Matrix QA y salir. | No deja la sesión simulada contaminando el reloj real. | PENDIENTE | |

## 9. Reportes, LFT y facturación

| ID | Acción manual | Resultado esperado | Estado | Evidencia / notas |
|---|---|---|---|---|
| REP-01 | Abrir Básicos y Avanzados. | Sólo aparecen pestañas permitidas por plan/rol. | PENDIENTE | |
| REP-02 | Generar un reporte corto en CSV, XLSX y PDF. | Los tres descargan, abren y muestran la misma empresa/periodo. | PENDIENTE | |
| REP-03 | Intentar un rango inválido o excesivo. | La pantalla explica el límite; no devuelve 500. | PENDIENTE | |
| LFT-01 | Abrir Reglamento y Festivos. | Ambas pestañas cargan valores actuales de la empresa. | PENDIENTE | |
| LFT-02 | Cambiar una tolerancia QA, guardar, recargar y restaurarla. | Persiste y luego vuelve al valor anterior. | PENDIENTE | |
| LFT-03 | Crear y retirar un festivo QA. | Se refleja sin duplicar fechas. | PENDIENTE | |
| FAC-01 | Abrir Fiscal CSD, Timbrado e Historial SAT sin cargar secretos. | Las vistas cargan y explican lo no configurado sin afirmar que timbraron. | PENDIENTE | |
| FAC-02 | Confirmar que Nómina orienta y calcula, pero no timbra por sí sola. | No existe una acción engañosa de “timbrado exitoso” sin proveedor/credencial real. | PENDIENTE | |

## 10. Configuración, cuenta y respaldo

| ID | Acción manual | Resultado esperado | Estado | Evidencia / notas |
|---|---|---|---|---|
| SET-01 | Recorrer Onboarding, Reloj, Apertura, Comidas, Tareas, LFT, Nómina, Notificaciones, Permisos y ATS. | Cada sección carga y muestra valores actuales. | PENDIENTE | |
| SET-02 | Cambiar un ajuste reversible QA, guardar, recargar y restaurarlo. | Persiste y se restaura sin afectar otra empresa. | PENDIENTE | |
| SET-03 | Abrir Perfil, Facturación, Módulos y Respaldos de la empresa. | Cargan sin error y muestran empresa/plan correctos. | PENDIENTE | |
| SET-04 | Generar el respaldo JSON del panel. | Informa honestamente que es parcial y firmado, no una BD completa cifrada. | PENDIENTE | |
| SET-05 | Abrir permisos con un rol no administrador. | No permite elevarse ni modificar controles reservados al admin. | PENDIENTE | |

## 11. Móvil, errores y aislamiento

| ID | Acción manual | Resultado esperado | Estado | Evidencia / notas |
|---|---|---|---|---|
| X-01 | Repetir ACC-01, NAV-01 y RRHH-01 en ancho móvil. | No hay recortes bloqueantes; menú abre/cierra y botones son alcanzables. | PENDIENTE | |
| X-02 | Desconectar Internet en una pantalla de sólo lectura y reconectar. | Muestra error recuperable; no pierde ni inventa guardados. | PENDIENTE | |
| X-03 | Abrir una URL de módulo inexistente. | Recupera Dashboard o muestra salida clara; nunca pantalla vacía. | PENDIENTE | |
| X-04 | Dejar sesión inactiva hasta expirar y luego guardar. | Pide iniciar sesión; no aparenta haber guardado. | PENDIENTE | |
| X-05 | Revisar consola del navegador al final de cada módulo. | Sin errores rojos nuevos ni secretos/datos personales impresos. | PENDIENTE | |

## Hallazgos y correcciones

Se agrega aquí cada `FALLÓ` con archivo/ruta real, causa comprobada, commit, despliegue y repetición.

| Hallazgo | Estado | Causa verificada | Corrección / commit | Reprueba |
|---|---|---|---|---|
| — | — | — | — | — |
