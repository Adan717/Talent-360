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
> Comando `usuarios:marcar-contrasenas-conocidas` corrido en la V2. Pruebas en
> `CambioForzadoDeContrasenaTest` (7, incluida la del criterio literal del plan).

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

## 2. Lo chico ya decidido (media jornada en total)

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

## 5. ATS → Academia: la inscripción en la transición de contratación

De la decisión D2, el único trabajo real: **al contratar, inscribir a la persona en sus cursos de
inducción**. Más el vocabulario separado en las pantallas: *evaluación de postulación* /
*inducción* / *verificación de constancia*.

**Terminado cuando**: contratar a alguien lo deja con sus cursos asignados, y la prueba lo demuestra.

---

## 6. Asistente de reportes (2-3 días, realistas 5 con una sola persona)

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
