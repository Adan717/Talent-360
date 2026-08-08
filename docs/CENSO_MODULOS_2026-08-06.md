# Censo de módulos: en qué estado está cada uno (2026-08-06)

Medido, no estimado: tamaño del código, pruebas que lo tocan y uso real en la instancia de
pruebas. Sirve para decidir **qué se audita después** de Reloj, Tareas y Academia.

---

## Auditados y cerrados

| Módulo | Estado |
|---|---|
| **Reloj Checador** | Spec completo, endurecido, regresión en vivo. R1–R105. |
| **Tareas y Rutinas** | Endurecimiento 10/10 cerrado. Sólo quedan decisiones de producto. |
| **Academia 360** | AC1–AC10 cerrados. Circuito probado de punta a punta con un colaborador real. |

De paso quedaron tocados: el **asistente de alta** (catálogo único, confirmación de organigrama),
el **organigrama** (dos representaciones, comando de reparación), el **Monitor** (modo privado del
chat) y la **Wiki pública** (los tres candados de AC7–AC9).

---

## Sin auditar

### 1. Nómina — el de mayor riesgo, con diferencia

| Señal | Dato |
|---|---|
| Cálculo | `ClockService::calculatePayrollForEmployee`, **424 líneas en un solo método** |
| Timbrado | `FacturapiBillingProvider` (335 líneas) — llamadas reales a un proveedor externo |
| Pantallas | `FacturacionManager` 810 líneas + `NominaColaborador` 441 |
| Pruebas | 19 archivos mencionan nómina, **0 mencionan CFDI** |
| Uso real | **8 nóminas** ya generadas en la V2 |

**Lo que devuelve el cálculo hoy**: sueldo bruto y neto, bono de cumplimiento, faltas, retardos y
deducciones. **Lo que NO calcula**: aguinaldo, finiquito, antigüedad, prima vacacional,
vacaciones, séptimo día, ISR ni IMSS. Ninguno de los conceptos que la ley exige — lo verifiqué
buscándolos uno por uno en el servicio y en el controlador.

Dos cabos que ya destapamos apuntan aquí: `hire_date` se hizo obligatoria y **nadie calcula
antigüedad todavía**, y el bono de la Academia se quitó porque **no hay cable a nómina**.

*Es dinero real, con consecuencias legales y ante el SAT, y es lo único que un error aquí no
perdona.*

### 2. Bolsa de Trabajo (ATS)

| Señal | Dato |
|---|---|
| Código | `RecruitmentController` 293 líneas + `AtsManager` 378 |
| Pruebas | **0** para reclutamiento y candidatos, 1 roza vacantes |
| Uso real | 2 vacantes, **0 candidatos, 0 entrevistas** |
| Marcadores de trabajo sin terminar | 3 |

Nadie lo ha usado nunca. Sin datos reales, una auditoría aquí sería teórica.

### 3. Archivo Digital — CONSTRUIDO en la ronda 2026-08

| Señal | Dato |
|---|---|
| Código | `GestorDocumentos` reescrito con motor real + `DocumentosController` |
| Pruebas | `ArchivoDigitalTest` (10 casos) |
| Marcadores de trabajo sin terminar | 0 |

**Actualización 2026-08-08:** la "mirada rápida" reveló que era un mockup 100% frontend
(upload falso con pérdida de datos, expedientes fabricados, visor "SAT" inventado). Se
construyó completo en la ronda 2026-08: 2 tablas (`employee_documents`,
`company_documents`), storage privado con uuid y descarga autenticada, checklist fija de
6 con faltantes honestos, flujo pendiente→validado/rechazado, manuales corporativos con
vínculo real a Academia. Plan: `docs/modules/archivo_digital/PLAN_CONSTRUCCION_2026-08-08.md`.

### 4. Reportes IA

| Señal | Dato |
|---|---|
| Código | `ReportesManager` 410 líneas |
| Pruebas | 2 lo rozan |
| Riesgo | Lee y presenta; no escribe dinero ni asistencia |

Si un reporte miente, se ve. Es el de menor consecuencia.

### 5. Suscripciones y facturación de la plataforma

| Señal | Dato |
|---|---|
| Código | `SubscriptionController` 700 líneas, `BillingController` 259, `StripeWebhookController` |
| Pruebas | **0** mencionan suscripciones |

Es el cobro a los clientes del SaaS, no la nómina de los colaboradores. Riesgo de dinero también,
pero de otro tipo: si falla, se cobra mal a una empresa, no se le paga mal a una persona.

### 6. Sin uso todavía

Pedidos a proveedores (0 filas), evaluaciones de desempeño (0), Wiki/Obsidian (0 documentos, ya
asegurada en lo público). Auditar algo que nadie usa da hallazgos que nadie sufre.

---

## Recomendación

**Nómina**, y no está cerca. Es el único módulo con las tres cosas a la vez: **dinero real, uso
real (8 nóminas ya calculadas) y una laguna comprobada** — ningún concepto de ley calculado, cero
pruebas del timbrado.

El orden que propongo después: Suscripciones (dinero, sin pruebas), Archivo Digital (terminar más
que auditar), ATS y Reportes al final.

**Un matiz honesto sobre el alcance:** lo de los conceptos de ley no es una auditoría, es
construir lo que falta — y eso es decisión de producto, no un arreglo. Lo que sí es auditoría es
lo que ya existe: que las 8 nóminas calculadas estén bien, que el timbrado no se dispare dos
veces, que una nómina firmada no se pueda alterar.
