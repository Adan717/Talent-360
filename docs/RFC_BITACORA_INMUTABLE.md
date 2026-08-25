# RFC — Bitácora inmutable de asistencia

> **Estado:** propuesta, sin una línea de código escrita · **Fecha:** 2026-08-24
> **Pregunta que contesta:** si mañana llega una demanda laboral, ¿puede esta empresa probar a qué
> hora entró y salió una persona, y demostrar que ese registro no se tocó después?
> **Hoy la respuesta es NO.**

---

## 1. Por qué esto no es una funcionalidad más

En México **la carga de la prueba es del patrón**. Los artículos 784 y 804 de la LFT ponen sobre la
empresa la obligación de conservar y exhibir los controles de asistencia, y la consecuencia de no
poder hacerlo no es una multa: es que **se presumen ciertos los hechos que alega el trabajador**.

Traducido a este sistema: si un colaborador declara que trabajó de 8:00 a 20:00 durante seis meses
y la empresa presenta su reloj checador, el juez va a preguntar quién pudo editar esos registros y
con qué rastro. Hoy la respuesta honesta es *"un administrador, desde su pantalla, sin dejar
huella"*, y eso convierte la evidencia en papel mojado — o peor, en un indicio en contra.

Y no es hipotético. Esta misma campaña **escribió y borró fichajes reales**:

| Qué pasó | Rastro que quedó |
|---|---|
| El cierre automático estampó salidas sintéticas sobre asistencia real | Sólo la alerta que yo agregué después |
| `shifts:reparar-cierres-sinteticos` **borró** dos de esos fichajes | Un `audit_log`, y porque lo programé a propósito |
| El Monitor inserta fichajes desde `force-close-shift` | Sin registro de quién ni por qué |

Ninguno de esos rastros es inmutable, y todos dependen de que quien escribió el código se acordara
de registrarlo. **Eso no es una bitácora: es una costumbre.**

---

## 2. Las tres opciones que planteaste

### A) Event Sourcing sobre `time_entries`

Los fichajes dejan de ser filas mutables y pasan a ser una secuencia de eventos; el estado actual
se deriva reproduciéndolos.

**A favor:** es la respuesta teóricamente correcta — la historia *es* el dato.
**En contra, y es decisivo:** obliga a reescribir el módulo entero (el motor de nómina, los
reportes, el Monitor y el dial leen `time_entries` directamente en decenas de lugares), en un
sistema con nóminas reales, y por un requisito **probatorio**, no funcional. Es meses de trabajo y
un riesgo enorme para conseguir lo que otra opción da en días.

**Descartada.** No por complejidad: por proporción.

### B) Tabla de revisiones con *before/after*

Cada edición manual escribe una fila con el estado anterior, el nuevo, quién y por qué.

**A favor:** barata, entendible, y produce exactamente el documento que sirve en un juicio —
*"este registro se modificó el 12 de marzo, lo hizo Fulano, decía X y quedó en Y, por este motivo"*.
**En contra por sí sola:** vive en la capa de aplicación. Si alguien escribe un `UPDATE` saltándose
Eloquent —una consulta directa, una migración, un `psql` en el servidor— la revisión no se escribe
y **nadie se entera**. Depende de la disciplina, que es de lo que estamos huyendo.

### C) Triggers nativos de PostgreSQL

La propia base de datos captura cada `INSERT/UPDATE/DELETE` y lo escribe en una tabla espejo.

**A favor:** **no se puede evitar desde la aplicación.** Da igual si el cambio vino de Eloquent, de
un comando, de una migración o de alguien con `psql` abierto: queda registrado. Eso es justo lo que
una bitácora probatoria necesita.
**En contra por sí sola:** el trigger ve *qué* cambió, no *por qué* ni *quién* en términos de
negocio. Registra `user_id` de la fila, no el administrador que apretó el botón ni su justificación.
Y un juez pregunta el porqué.

---

## 3. Mi propuesta: B **y** C, cada una haciendo lo que la otra no puede

No son alternativas: son **dos capas distintas** y las dos hacen falta.

```
┌─ Capa 1 · TRIGGER de Postgres ────────────────────────────────┐
│  Ve TODO cambio, venga de donde venga. Nadie la puede saltar. │
│  Responde: QUÉ cambió, CUÁNDO, y cómo era antes.              │
└───────────────────────────────────────────────────────────────┘
┌─ Capa 2 · La aplicación declara la INTENCIÓN ─────────────────┐
│  Quién lo pidió, con qué autoridad y POR QUÉ.                 │
│  Responde: lo que un juez pregunta después del "qué".         │
└───────────────────────────────────────────────────────────────┘
```

### Capa 1 — `time_entries_historial` alimentada por trigger

Tabla espejo, `AFTER INSERT OR UPDATE OR DELETE ON time_entries FOR EACH ROW`. Guarda la operación,
la fila completa antes (`OLD`) y después (`NEW`) como `jsonb`, y el instante del servidor.

**Sin `UPDATE` ni `DELETE` propios**: se le revoca ese permiso al rol de la aplicación. La app puede
insertar en `time_entries` y leer el historial; **no puede modificar el historial**, ni con un
error, ni con una migración descuidada, ni con una inyección. Eso es lo que hace la palabra
*inmutable* verdadera y no decorativa.

Para atar la intención a la operación sin que el trigger sepa de negocio: la app fija
`SET LOCAL app.actor_id` / `app.motivo_id` al abrir la transacción, y el trigger los recoge con
`current_setting(..., true)`. Es el puente estándar y no obliga a tocar el esquema de nadie.

### Capa 2 — `asistencia_correcciones`

Una fila por corrección **hecha por un humano**, escrita por la aplicación: qué fichaje, valor
anterior y nuevo, quién lo autorizó, **motivo obligatorio en texto libre** y —esto importa— si el
colaborador afectado fue **notificado**. Un ajuste de asistencia que la persona nunca supo que
ocurrió es exactamente lo que se ve mal en un juicio.

Y una regla de producto que vale más que las dos tablas juntas: **corregir un fichaje no lo
sobrescribe.** Se inserta un registro nuevo que anula al anterior y ambos se conservan, igual que
una póliza contable no se borra, se cancela con otra. El fichaje original de las 8:03 sigue
existiendo aunque hoy cuente el de las 8:00.

---

## 4. Lo que hay que decidir antes de escribir código

Tres cosas, y ninguna es técnica:

1. **¿Cuánto se conserva?** La LFT pide conservar los controles de asistencia durante la relación
   laboral y un año después; para efectos fiscales suelen ser cinco. **Propongo cinco años**, y que
   la purga jamás alcance registros de una persona con un juicio abierto.
2. **¿Se le avisa al colaborador cuando le corrigen un fichaje?** Mi recomendación es **sí**, con
   un aviso en su reloj. Es la diferencia entre "se corrigió un error" y "le movieron el registro".
3. **¿Quién puede corregir?** Hoy cualquier admin. Propongo que sea una capacidad propia y
   separada, no incluida por defecto en `admin`.

## 5. Cómo lo haría, en orden

| Paso | Qué | Riesgo |
|---|---|---|
| 1 | Tabla `time_entries_historial` + trigger, **sin tocar nada más** | Ninguno: sólo observa |
| 2 | Dejar correr una semana y comparar el historial con lo que la app cree que hizo | Ninguno: es lectura |
| 3 | Revocar `UPDATE`/`DELETE` sobre el historial al rol de la app | Bajo, pero **exige probar el respaldo antes** |
| 4 | `asistencia_correcciones` + motivo obligatorio en las pantallas que editan asistencia | Medio: toca UI |
| 5 | Regla "corregir no sobrescribe" en el motor y los reportes | El mayor: cambia cómo se lee un día |

El paso 2 no es burocracia: es la fotografía financiera aplicada a otra cosa. Antes de confiar en
una bitácora hay que comprobar que ve lo que decimos que ve.

## 6. Lo que este RFC **no** propone

- **No propone Event Sourcing.** La proporción no lo justifica.
- **No propone bloquear la edición de fichajes.** Un jefe tiene motivos legítimos para corregir un
  olvido, y prohibirlo empuja a arreglarlo por fuera del sistema, que es peor. Se registra, no se
  impide — el mismo criterio de *avisa, nunca bloquea* que ya rige el reloj.
- **No propone firmar criptográficamente la cadena.** Encadenar hashes suena bien y no aporta gran
  cosa mientras quien administra el servidor pueda regenerarla; el valor probatorio real viene del
  respaldo externo diario, que ya existe y ya se probó restaurando.

## 7. Costo estimado

Pasos 1–3 (la parte que de verdad cierra el hueco legal): **2–3 días**.
Pasos 4–5 (la intención y la regla de no sobrescribir): **3–5 días**, con pantallas de por medio.

## 8. Referencias

- `Backend/app/Services/ClockService.php` — `processPunch`, el escritor principal
- `Backend/app/Http/Controllers/DashboardMonitorController.php:1099` — inserta fichajes desde el Monitor
- `Backend/app/Console/Commands/RepararCierresSinteticos.php:108` — **borra** fichajes
- `Backend/app/Console/Commands/CloseOrphanShifts.php` — escribe salidas sintéticas
- `docs/RESPALDO_Y_RESTAURACION.md` — el respaldo diario, ya probado restaurando
