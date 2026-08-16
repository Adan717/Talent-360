# Plan de trabajo — orden acordado el 2026-08-11

Orden que sale del consejo de asesores (5 lentes + revisión por pares) sobre las 10 decisiones de
producto, corregido con lo que se verificó contra el código y contra las dos instancias reales.

**Cómo usar este archivo**: se trabaja de arriba abajo. Cada bloque dice qué se hace, por qué, y
**cómo se sabe que está terminado**. No se salta un bloque porque el siguiente sea más divertido.
Las decisiones que lo justifican están en `docs/DECISIONES_PRODUCTO.md`.

Estado al escribirlo: `main` @ `5babc6f` en los dos repos, desplegado en la V2. Suites sqlite y
Postgres en verde (1305 pruebas). 202 archivos de prueba.

---

## 0. Respaldo con restauración PROBADA — antes que nada ✅ HECHO 2026-08-13

> **Estado**: cron diario 02:45 UTC en el servidor (Postgres `-Fc` validado + `storage/app` +
> `.env` + `public/uploads`, ambas instancias, 14 días), copia fuera del servidor jalada a diario
> por la máquina de Adán (interina hasta que el dueño decida nube, §B1), y **restauración probada
> de verdad**: login en la app restaurada y descarga autenticada del expediente (200, `%PDF`,
> bytes exactos); prod verificada por conteos (13/13, 14/14, 10/10). No existe aún ninguna foto
> de fichaje en ninguna instancia (§67 no tiene endpoint de subida): se probó con el único
> archivo privado real. Detalle y runbook: `docs/RESPALDO_Y_RESTAURACION.md`.

**Por qué va primero:** hoy no existe ninguna copia. El mismo día en que se encontró un script de
despliegue que borraba todas las empresas salvo la primera, se confirmó que no hay de dónde
recuperar nada. Tres empresas ya corren nómina real: *producción ya ocurrió*, y lo que se está
decidiendo sin decirlo es cuántos meses de nómina se toleran perder.

**Regla mientras tanto:** *no cargar más nómina real hasta que el restore esté verde.*

Trabajo:

1. Volcado diario de Postgres **fuera del servidor** (el destino lo decide el dueño; ver
   `DECISIONES_PRODUCTO.md` §B1). Retención mínima 14 días.
2. **El storage privado va en el respaldo.** Éste es el hallazgo que sólo apareció en la revisión
   por pares: `pg_dump` no toca `storage/app/private`, donde viven los documentos del Archivo
   Digital, las evidencias de comedor y las fotos de fichaje. Restaurar sólo Postgres devuelve
   recibos que apuntan a archivos que ya no existen — justo la evidencia que sirve ante una junta.
3. Las dos instancias: `talent360-v2-*` y la de producción del jefe (`talent360-*`).

**Terminado cuando**: se ha levantado una copia en un contenedor limpio, se ha entrado a la
aplicación restaurada, y **se ha abierto una foto de fichaje desde ahí**. No cuando el cron corra
sin error: cuando alguien haya restaurado de verdad.

---

## 1. Revertir la decisión de las contraseñas viejas (el consejo la tumbó) ✅ HECHO 2026-08-13

> **Estado**: columna `must_change_password`, middleware `ForcePasswordChange` sobre el grupo
> `api` entero (una cuenta marcada sólo puede cambiar su contraseña o salir), pantalla de cambio
> en el login, y la política completa: **toda contraseña puesta por OTRO** (ficha de RRHH, reset
> del platform admin) **marca la cuenta; toda contraseña puesta por UNO MISMO** (cambio, enlace
> de reset, activación) **la desmarca**. Ninguna contraseña conocida puede elegirse como nueva.
> Comando `usuarios:marcar-contrasenas-conocidas` (users + platform_users). Pruebas en
> `CambioForzadoDeContrasenaTest` (12, incluida la del criterio literal del plan y las de la
> ronda adversarial: expulsión de tokens al cambiar, platform admin en la rotación, blocklist
> en el reset, kiosco exento).
>
> Comando de marcado corrido en la V2 (2026-08-13, tras la restauración del incidente):
> 6 cuentas marcadas, incluida la de PLATAFORMA del usuario. El e2e completo se probó en la
> V2 viva (ficha marca → pantalla de cambio → entra). Falta solo: correr el comando en la
> instancia del jefe cuando él despliegue.

**El único voto en contra de una decisión ya tomada, y con razón.** El argumento para no rotarlas
era "dejaría fuera a quien trabaja hoy". Es un dilema falso: **forzar el cambio en el siguiente
inicio de sesión no deja fuera a nadie**, porque la persona ya conoce su contraseña actual — por eso
funciona incluso sin correo configurado.

Lo medido el 2026-08-11:

| Instancia | Usuarios | Con contraseña conocida (`password123` / `123456`) | De ellos con mando |
|---|---|---|---|
| V2 (nuestra) | 7 | **6** | **3** |
| Producción del jefe | 13 | 1 | 0 |

**El trabajo urgente está en la V2**, no en producción. Y lo que se evita no es "una cuenta
comprometida": con esas credenciales cualquiera entra como otra persona y **firma o autoriza su
recibo de nómina**, lo que vacía el valor probatorio de todos los recibos firmados.

Trabajo: columna `must_change_password`, gate en el login, pantalla de cambio, y salida manual
(reset por admin) para quien se quede fuera por cualquier motivo.

**Terminado cuando**: una cuenta marcada no puede usar ninguna ruta salvo la de cambiar su
contraseña, y la prueba lo demuestra fallando sin el arreglo.

---

## 2. Lo chico ya decidido (media jornada en total) ✅ HECHO 2026-08-13

> **Estado**: los cuatro, con prueba. (1) Clave "Master" del Simulador: fuera. (2) Plan IA: el
> Monitor manda `ia_disponible` y el botón sólo existe con llave. (3) Retención de chat: sobre
> `internal_messages` — **descubrimiento: `team_chat_messages` era una tabla MUERTA** (nada la
> lee ni escribe; la purga de 7 días era teatro y el chat real no se purgaba jamás) — ajuste por
> empresa (7/30) en Configuración, aviso en la pantalla del chat, índice
> `(tenant_id, created_at)`, y 📌 conserva un mensaje citado en un incidente; privados y
> megáfono esperan la decisión (c) de D3. (4) Tolerancia por puesto: el servidor juzga con
> `role_clock_policies.config.tolerancia_retardo_mins` (puesto) > LFT (empresa) — la MISMA
> fuente que el dial ya pintaba desde la línea §65 (el control muerto de la ficha de puestos se
> retiró; el editor real es la Matriz); el dial muestra "Tolerancia: N min (de tu puesto/de la
> empresa)" y cada check_in estampa `tolerancia_aplicada`/`origen`. Sólo hacia adelante:
> verificado con prueba. Medido antes de conectar: en la V2 `role_clock_policies` está vacía y
> todo está en 10 — ninguna nómina cambia.

Va junto porque son cambios pequeños e independientes.

- **Quitar la clave "Master" del Simulador QA.** Era una cadena comparada en el navegador; el
  control real es el rol. *Nota del Forastero: si nadie de fuera entiende qué se quita, la decisión
  no está escrita — por eso queda explicado aquí y en `DECISIONES_PRODUCTO.md` §D5.*
- **Esconder el botón del "Plan IA" mientras no haya llave de IA configurada.** No hay ninguna en
  ninguna instancia: hoy el botón promete algo que no puede ocurrir.
- **Retención de chat configurable** (7 por defecto, tope 30): ajuste por empresa, el comando
  programado lo lee, **índice `(tenant_id, created_at)` en `team_chat_messages`** (hoy sólo tiene la
  llave primaria), decidir si aplica a `internal_messages` (hoy no se purgan nunca), y **decirlo en
  la pantalla del chat**. Añadir: un mensaje citado en un incidente se conserva indefinidamente.
- **Tolerancia de retardo por puesto** (precedencia puesto > empresa), con dos correcciones del
  consejo: **mostrar en pantalla la tolerancia aplicada y de dónde salió** (si no, dos personas
  fichan al mismo minuto y sólo una tiene retardo, y nadie le cree al software), y **aplicar sólo
  hacia adelante** — nunca recalcular un periodo ya firmado.

**Terminado cuando**: cada uno tiene su prueba y el dial muestra la tolerancia con su origen.

---

## 3. Correo, por etapas

Hoy `MAIL_MAILER=log`: no sale ni un correo de ninguna instancia. Eso significa que la doctrina del
producto —"nada bloquea, todo avisa"— **hoy es sólo "nada bloquea"**: la mitad que protege al
usuario no existe. Ninguna de las 7 rondas lo detectó.

1. **Primero lo interno**: restablecimiento de contraseña (hoy no llega a nadie), invitación de
   RRHH, alertas de conciliación.
2. **Después lo externo**: avisos a interesados en vacantes.

Por qué en ese orden: encender el correo es **la única decisión irreversible hacia afuera** —
convierte defectos silenciosos en mensajes a terceros que no se retiran.

**Bloqueado por el dueño**: elegir proveedor y entregar credenciales.

**Terminado cuando**: un restablecimiento de contraseña real llega a una bandeja real.

---

## 4. Nómina: `payroll_run` por fases

La decisión (manda el neto FIRMADO) se queda, con **tres correcciones que salieron del consejo**:

- **Idempotencia**: `payroll_adjustment` con clave única por evento de origen. Sin eso, reeditar la
  misma fecha o que el job corra dos veces = **doble cobro**.
- **Tope legal**: un ajuste negativo arrastrado al periodo siguiente es un descuento a salario
  futuro; el **art. 110 LFT** lo acota por causa y monto. "No es retardo, es ajuste" no sobrevive a
  una inspección.
- **Explicarlo en pantalla**: el encargado lo vivirá como *"corregí la falta de Juan y su recibo no
  cambió"*. Si no hay una frase que diga **por qué**, el sistema queda como mentiroso otra vez —
  que es exactamente el defecto que estas siete rondas persiguieron.

Fases:

- **A.** `status` en `payroll_run` (abierto→calculado→autorizado→timbrado→cerrado) + **bloqueo duro
  del recálculo** a partir de `autorizado`. En nómina algo SÍ bloquea, y está bien: es un libro
  contable, no un checador.
- **B.** Snapshot de los **insumos** (asistencia, tabulador, políticas, versión del motor), no sólo
  del resultado. Esto es lo que permite reconstruir dentro de cinco años una nómina tal como se
  calculó.
- **C.** Job de conciliación que alerta + `payroll_adjustment` al periodo siguiente.

**Antes de empezar la fase A**: decidir qué pasa con las nóminas **ya firmadas** de las 3 empresas.
Este es el punto ciego que cuatro de las cinco revisiones señalaron, y el patrón que ya falló tres
veces en este proyecto: *el arreglo corrige el comportamiento y deja los datos viejos rotos.*

---

## 5. ATS → Academia: la inscripción en la transición de contratación ✅ HECHO 2026-08-13

> **Estado**: la inscripción de Academia es implícita (curso del tenant visible por puesto,
> avance perezoso), así que contratar YA deja a la persona con sus cursos en cuanto existen su
> cuenta y expediente — lo que faltaba era DEMOSTRARLO y DECIRLO: la contratación ahora cuenta
> los cursos de inducción que le aplican al contratado y lo dice en el resultado ("Quedó
> inscrito en su inducción de la Academia (N cursos)" — la promesa que se había quitado por
> mentirosa, ahora de vuelta porque es verdad), o avisa si la Academia no tiene ninguno que le
> aplique. `AtsInduccionAcademiaTest` lo prueba de punta a punta (contratar → el contratado VE
> su curso → `mi-induccion` pendiente desde el `hire_date` que la contratación fija).
> Vocabulario (D2): la columna del tablero de reclutamiento dejó de llamarse "Inducción" — es
> la **Evaluación de Postulación**; "inducción" queda reservado para Academia post-contratación.

De la decisión D2, el único trabajo real: **al contratar, inscribir a la persona en sus cursos de
inducción**. Más el vocabulario separado en las pantallas: *evaluación de postulación* /
*inducción* / *verificación de constancia*.

**Terminado cuando**: contratar a alguien lo deja con sus cursos asignados, y la prueba lo demuestra.

---

## 6. Asistente de reportes (2-3 días, realistas 5 con una sola persona) ✅ HECHO 2026-08-13

> **Estado**: construido AL DISEÑO de abajo, punto por punto. OpenAI con `strict: true`
> (`OpenAiReportIntentParser`, única implementación de la interfaz), el parser devuelve
> INTENCIÓN y las fechas las resuelve el servidor con `PayrollWeekService` + tz del tenant
> (topes: 92 días, semana 1–53, futuro recortado), autocompletador que llena el formulario y
> NUNCA descarga (la descarga sale por `asistencia.csv`/`tareas.csv`, que ya autorizan —
> `asistencia.csv` ahora acepta rango `from`/`to` por la misma puerta), fixture de 40 frases
> con adversarias **que YA PASÓ contra la API real** (`AsistenteFixturesOpenAiTest`, opt-in
> `RUN_OPENAI_FIXTURES=1`, umbral 90%), alerta por tasa de fallo
> (`reportes:alerta-fallos-asistente`, diaria, ≥30% en 7 días → bitácora del Monitor), aviso
> LFPDPPP visible en la tarjeta, y bitácora `report_intent_logs` con la retención del bloque 2.
> Nómina EXCLUIDA hasta la fase A del bloque 4, tal como manda este mismo diseño. La llave
> vive SOLO en el `.env` del servidor (2026-08-13; pendiente del dueño: ponerle límite de
> gasto en OpenAI).

Va al final **a propósito**: el presupuesto de confianza está en cero después de 150 defectos, y no
hay llave de IA en ninguna instancia. Diseño acordado:

- OpenAI con `strict: true` (structured outputs). Interfaz `ReportIntentParser` con **una sola
  implementación** — la interfaz compra la opción de cambiar de proveedor; la segunda
  implementación se escribe el día que se compare, no antes.
- **El parser devuelve INTENCIÓN**: `{semana: 25}`, nunca cifras **ni fechas resueltas**. Todo lo
  que dependa de configuración del inquilino (periodicidad de nómina, corte de jornada, qué es la
  "semana 25") lo resuelve el servidor con el código que ya usa la pantalla.
- Los parámetros van **al endpoint de reportes que ya existe y ya autoriza**: no hay segunda puerta,
  así que la autorización no es disciplina, es estructura.
- **Autocompletador**: llena el formulario, el humano confirma. **Nunca descarga directo.**
  Confirmación de una sola pantalla en teléfono (es donde está el caso de uso real de este producto).
- Fixture de ~40 frases **incluyendo adversarias** (un reclutador pidiendo nómina, "todos los datos
  desde 1990", instrucciones inyectadas en la frase). Es el criterio de selección de proveedor y lo
  que convierte un retiro de modelo en `php artisan test`.
- **Alerta por tasa de fallo.** Es la pieza que evita repetir esta conversación en marzo.
- Empieza con **asistencia, retardos y horas trabajadas**. **Nómina espera a la fase A del bloque 4**
  — si no, el asistente descargaría un número que ya sabemos que es ambiguo.
- **Antes de la primera llamada**: aviso de privacidad y transferencia de datos declarada
  (LFPDPPP). Las frases del usuario pueden llevar nombres de trabajadores.
- El log de frases (frase + JSON + usuario) contiene datos personales: **hereda la política de
  retención** del bloque 2.

**Bloqueado por el dueño**: la llave de OpenAI (proyecto separado para desarrollo, con límite de
gasto).

---

## Ronda de Tareas y almacenamiento (2026-08-13, pedida por el dueño tras revisar el formulario)

- **"Describe o dicta la tarea → Generar"** ahora interpreta con OpenAI (strict) y pre-llena TODO
  el formulario (categoría, puesto, hora, evidencia con instrucción, objetivo, pasos); sin llave
  cae a las reglas fijas de siempre. Sigue sin crear nada solo.
- **Formulario de tarea auditado campo por campo**: "Frecuencia" y "Evidencia de Cumplimiento"
  eran texto libre que se guardaba y NADIE leía (el reparto lo deciden las rutinas) — retirados y
  reemplazados por la verdad; "Modo Autocaptura (IA)" no era IA — renombrado; el "Checklist de
  Validación" se guardaba pero no se le mostraba al supervisor al validar — ahora sí.
- **Defecto real de la comparación con IA**: en las tres salidas a revisión humana la FOTO NO SE
  GUARDABA (el supervisor abría una tarea sin evidencia). Corregido en el servidor + prueba.
- **Toda la IA sale por OpenAI** (única llave que existe): Plan IA del Monitor, comparación de
  fotos, Generar, asistente de la Wiki, Escribano de contratos, generador de examen y Copiloto de
  soporte. Gemini queda como alternativa si algún día es la única llave. El centinela que
  detectaba "sin llave" comparaba contra el placeholder equivocado (corregido).
- **Disco del servidor: 84% → 28%** — eran 23 GB de caché de compilación de Docker; `deploy-v2`
  ahora lo limpia solo (conserva 24 h). Los archivos del Archivo Digital viven en
  `storage/app/private` (uuid, sin URL pública, ya en el respaldo) — mover a object storage
  (Cloudflare R2 o Hetzner) cuando haya volumen; hoy no hay problema que resolver.

## Reportes operativos: 3 nuevos (2026-08-13)

Construidos: **retardos y faltas por colaborador**, **horas trabajadas y extra** y
**cumplimiento de rutinas**. Regla que mandó: ninguna cifra se recalcula si ya hay quien la
calcule — retardos/faltas salen del MISMO motor que la nómina (contar `is_late` a mano da más
retardos, porque las exenciones se aplican al calcular, no se escriben en el fichaje). Horas es
un dato operativo nuevo (la nómina paga por día). Cumplimiento declara su definición dentro del
CSV porque **ya existían cuatro conteos distintos** del mismo dato, y NO trae "minutos reales"
(sólo se miden al pausar: serían ceros). El asistente por frase ya los elige.

**Los 8 restantes también se construyeron** (misma tanda): justificantes y autorizaciones,
aperturas y cierres, comedor/Ley Silla, inducción y capacitación, expediente documental,
embudo de reclutamiento y monedero. **Son 12 reportes en total.** Dos decisiones de esa tanda:
la lista vive en un **catálogo único** (`App\Support\CatalogoDeReportes`) que alimenta a la vez
la pantalla, el asistente y la validación — con doce reportes, tenerla duplicada garantizaba
ofrecer descargas inexistentes, y hay una prueba que recorre el catálogo entero exigiendo que
cada id responda; y "apertura a tiempo" se **extrajo** del motor del bono
(`ClockService::aperturasATiempo`) para que el reporte y la nómina cuenten igual.

Lo que esos reportes NO pueden decir, y lo dicen en el propio CSV: el tiempo que un candidato
pasó en cada etapa (el sistema no registra los cambios de etapa) y los "minutos reales" de una
tarea (sólo se miden al pausar).

**Sigue pendiente**: nómina histórica y costo por puesto **esperan la fase A del bloque 4**;
rotación de personal necesita validar antes la calidad de `hire_date` y las bajas viejas.

## Fuera de la lista, y valen más que la mitad de ella

- **Avisarles a las tres empresas** que hoy no hay respaldo automático ni correo saliente. Cuesta
  cero y es el único control real mientras el bloque 0 no esté verde.
- **Preguntar al jefe si alguien pagó mirando la tarjeta equivocada** (la que decía $4,875 cuando la
  nómina eran $13,125). Precisión importante: el defecto era **de pantalla**; el `net_pay` guardado
  —el que se firma y se timbra— estaba correcto. El sistema no pagó mal. Lo que no se puede
  descartar es que una persona haya pagado leyendo esa tarjeta.
- **Continuidad**: llaves SSH, `.env` y todo el conocimiento están en una sola persona. Un respaldo
  que sólo una persona sabe restaurar no es un respaldo.
- **`deploy_to_hetzner.py`**: asegurarse de que el jefe no despliegue con su copia vieja del script
  (la vieja ejecuta `tenant:purge-test-tenants --force`, que borraba toda empresa con id > 1).

---

## Esperando algo del dueño (no bloquean el orden de arriba salvo donde se dice)

| Qué | Bloquea |
|---|---|
| Destino del respaldo en la nube | Bloque 0 |
| Proveedor de correo + credenciales | Bloque 3 |
| Llave de OpenAI | Bloque 6 |
| `FACTURAPI_KEY` + salida TLS hacia Facturapi | El timbrado CFDI |
| Revisión del catálogo del giro restaurante | Los giros oficina / retail / taller |
| Qué pasa con las nóminas ya firmadas | Bloque 4 fase A |
