# Academia 360 — Arranque de la auditoría del módulo

**Fecha:** 2026-08-04 · **Estado:** listo para arrancar en sesión nueva.
**Orden acordado con producto (2026-07-15):** Reloj ✅ → Tareas ✅ → **Academia**.
**Playbook:** el mismo que cerró los dos anteriores — spec/gap-analysis → ronda de hallazgos en
vivo → endurecimiento con tests → regresión final.

---

## Estado en ambos repos (medido 2026-08-04, tras el resync 4)

**La Academia es LA MISMA en las dos líneas.** El diff `main` vs `refs/jefe/main` sobre los
archivos del módulo da ~15 inserciones de su lado contra ~1,700 nuestras (que son migraciones y
endurecimiento general que él no tiene). Conclusión: el módulo es creación suya, entró completo
en los resyncs, y **nadie lo ha auditado nunca** — ni una ronda de hallazgos, ni tests de sus
puertas más allá de 3 archivos.

| Pieza | Dónde | Tamaño |
|---|---|---|
| `AcademyController` | Backend | 393 líneas |
| `ObsidianController` (wiki/exámenes/copilot) | Backend | **2,321 líneas** |
| `GestorAcademia.tsx` | Frontend | 915 líneas |
| Migraciones | induction_courses, academy_courses, user_course_progress, certificate_template, seed_lft_course, 3 tablas obsidian | 8 |
| Tests existentes | AcademyCourseTemplateTest, AcademyProgressGuardTest, TaskAcademyLinkTest | **solo 3** |
| Rutas públicas `org-vault` (SIN sesión) | login, register, documento, copilot, passcode, sugerencias (crear/aprobar/rechazar), scribe, progreso, examen (status/generate/submit) | **14** |

---

## Hallazgos preliminares (verificados leyendo código, pendientes de confirmar en vivo)

**AC1 — La selección de cursos del wizard es teatro.** El bloque 1D del wizard deja elegir
cursos (`selectedCursos`), pero `handleConfigureNicho` **nunca manda `selected_cursos`** al
backend. Elija lo que elija el dueño, el backend cae SIEMPRE a su rama de defaults. Familia
exacta del H27 ("el wizard promete, nadie reparte"). Decisión de diseño pendiente: o se manda la
selección, o se quita el checkbox teatro. *(Ojo: los cursos son dominio del jefe — proponer, no
rediseñar.)*

**AC2 — Todos los cursos apuntan SOLO al puesto de mando.** `configureNicho` inserta cada curso
con `target_job_role_id = $firstGerenteRole`. ¿Los demás puestos ven algún curso? ¿La inducción
del cajero nuevo existe? Verificar en vivo qué ve un colaborador de piso recién dado de alta.

**AC3 — Los quiz son de UNA pregunta genérica** ("¿Cuál es el objetivo principal de este
protocolo?") con la respuesta correcta en la primera opción, para TODOS los cursos. ¿Aprobar
significa algo? ¿Se puede re-aprobar infinitas veces y farmear XP/monedas?

**AC4 — 14 rutas públicas de org-vault sin sesión.** Incluyen registro público, copilot (¿llama
a Gemini con presupuesto de quién?), generación de exámenes y aprobación de sugerencias. La
lección del Reloj: la superficie pública sin throttle/validación fue donde vivían los agujeros.
Revisar: throttle, aislamiento de tenant por slug, quién puede aprobar sugerencias públicas.

**AC5 — ¿La inducción se asigna sola?** El pitch del wizard: "capacita e induce 100% en
automático". Verificar el circuito completo: alta de colaborador → ¿le aparece su inducción sin
que nadie la asigne? (El equivalente del checklist de apertura que H27 destapó.)

**AC6 — `video_url` vacío en todos los cursos inyectados** y `quiz_data` con sello DecorArte en
un texto ("Protocolo... Decorarte 360") que se inyecta a CUALQUIER empresa del giro repostería —
la familia H12 (marca ajena hardcodeada).

---

## Preguntas para el circuito de dinero/XP

- ¿Completar un curso paga monedas/XP? ¿Con ancla anti-doble-pago como las 6 puertas de Tareas?
- ¿`user_course_progress` tiene unique por (user, curso) o se puede duplicar progreso?
- ¿El certificado (certificate_template) se genera y persiste, o es otro stub tipo H23?

## Entorno listo para la sesión

- **V2 en vivo**: `http://46.225.153.115:3002` — tenant 2 DecorArte (`marisolherrera@pruebaqa360.com`
  / `password123`; Francisco Vega supervisor: `franciscovega@...` misma clave). SSH:
  `ssh -i ~/.ssh/talent360_v2 root@46.225.153.115`, deploy con `deploy-v2`.
- **Local**: contenedor `talent360-merge-backend` corre la suite (`docker exec ... php artisan test`);
  para servir HTTP: `docker exec -d talent360-merge-backend php artisan serve --host=0.0.0.0 --port=8001`.
  Preview FE: `talent360-merge-frontend` (vite :5174). BD local sembrable
  (`admin.verif@wizard.local` / `verif-1234`, tenant 1).
- **Suite**: 1032/0 sqlite; correr también Postgres (`--configuration=phpunit.postgres.xml`)
  antes de publicar. Línea base limpia al momento de escribir esto (`1b5c105`).

## Reglas de la casa que aplican aquí

1. Los cursos por normativa son **dominio del jefe** — auditar y proponer con hallazgos
   numerados (AC#), no rediseñar por iniciativa.
2. Al corregir algo que genera datos: **qué pasa con lo ya generado** (los cursos ya inyectados
   en tenants vivos).
3. Superficie pública = primera prioridad de seguridad (lección R-throttle del login).
4. Un hallazgo por vez, test que lo fija, bitácora en este directorio.
