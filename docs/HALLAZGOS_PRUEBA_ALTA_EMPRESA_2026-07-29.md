# Hallazgos — prueba de alta de empresa desde cero (2026-07-29)

**Entorno:** instancia V2 en el servidor del jefe (`http://46.225.153.115:3002`), commit `99b7fce`.
**Escenario:** registro público → plan Enterprise (checkout simulado) → wizard de giro (Repostería) →
alta de 3 colaboradores desde Directorio Digital.
**Resultado del flujo:** funciona de punta a punta. Empresa aprovisionada, 7 puestos + 92 checklists
cargados por el wizard, 3 colaboradores creados. Los hallazgos de abajo son defectos encontrados
DURANTE ese recorrido; ninguno impide operar, pero el #1 afecta dinero.

---

## 🔴 H1 — El sueldo capturado en el alta NUNCA llega a la nómina ni al costo de tareas

**Qué pasa:** el formulario "Alta de Colaborador" envía el sueldo en el campo `salary`
(`RecursosHumanos.tsx:1132`) y `EmployeeController::store` lo persiste tal cual. Pero **todo el
cálculo de dinero lee `base_salary`**, que queda `NULL`:

- `TaskAssignmentController` (update / aiValidate / validateWithPin / resolveIncomplete) y
  `TaskSyncController`: `$employee->base_salary > 0 ? $employee->base_salary : 300.00`
- El costo financiero de cada tarea = `(base_salary / 480) * minutos`

**Consecuencia:** todo colaborador dado de alta por esta pantalla se calcula con el **salario por
defecto de $300**, sin importar lo que se haya capturado. Verificado en vivo:

```
Francisco Vega  | salary=14000.00 | base_salary=NULL
Adán Cuéllar    | salary= 9000.00 | base_salary=NULL
Marisol Herrera | salary=18000.00 | base_salary=NULL
```

**Fix sugerido:** decidir UNA columna de verdad. Lo menos invasivo: que `store`/`update` escriban
`base_salary` cuando llegue `salary` (o que el FE mande ambos), más una migración que copie
`salary → base_salary` donde esté nulo. Conviene test que cubra "alta con sueldo → el costo de
tarea usa ese sueldo, no 300".

## 🟠 H2 — El wizard de giro NUNCA se abre solo en una empresa nueva

**Qué pasa:** `App.tsx` está bien (`if (!onboarding_completed) setShowOnboarding(true)`), pero
`ClockController::getState` trae un fallback:

```php
if (!isset($systemSettings['onboarding_completed'])) {
    $hasJobRoles = DB::table('job_roles')->where('tenant_id', $tenantId)->exists();
    if ($hasJobRoles) { $systemSettings['onboarding_completed'] = true; }
}
```

y **la creación del tenant siembra 4 puestos por defecto** (Gerente de Sucursal, Asesor de Ventas,
Cajero, Almacenista). Entonces el fallback se cumple desde el primer login → el wizard se marca
como completado sin que nadie lo haya corrido.

**Consecuencia:** el asistente que precarga puestos/tareas/cursos por giro —la puerta de entrada
del producto— no aparece para NINGUNA empresa nueva. Hay que descubrir a mano el botón
"Comenzar Configuración". (Con el wizard abierto manualmente todo funcionó bien: giro Repostería →
sub-giro "Insumos para Repostería & Panadería" → 7 puestos + 92 checklists.)

**Fix sugerido:** anclar el fallback a algo que solo exista DESPUÉS del wizard (p. ej. `tasks`
del tenant > 0), o marcar `onboarding_completed=false` explícitamente al crear el tenant.

## 🟠 H3 — Los correos autogenerados conservan acentos

**Qué pasa:** `RecursosHumanos.tsx:1129` arma el correo con
`name.toLowerCase().replace(/\s/g,'')` + dominio, sin normalizar diacríticos:

```
Adán Cuéllar → adáncuéllar@pruebaqa360.com
```

**Consecuencia:** un correo con acentos rompe el envío real de mail (SMTP) y puede fallar al
teclearlo en el login. Además, si dos nombres difieren solo por acentos colisionan.

**Fix sugerido:** normalizar (`NFD` + quitar diacríticos) antes de armar el correo.

## 🟡 H4 — El alta no permite elegir el rol y arrastra el puesto anterior

- `role: 'empleado'` está **hardcodeado** en el payload del alta: todo colaborador nace como
  empleado y hay que editar su ficha después para volverlo supervisor/admin.
- Al reabrir "Alta de Colaborador" tras guardar, el `<select>` de puesto **conserva el puesto del
  alta anterior** (el nombre sí se limpia). Riesgo de dar de alta a alguien con el puesto
  equivocado por descuido.

**Fix sugerido:** exponer el selector de rol en el alta (el backend ya valida
`in:admin,supervisor,empleado`) y limpiar el estado del formulario al abrirlo.

---

## Contexto operativo de la prueba

- El checkout simulado requirió el opt-in `ALLOW_SIMULATED_CHECKOUT` (commit `99b7fce`): la
  instancia V2 corre con `APP_ENV=production` y el guard F3 devuelve 404 en producción. La
  variable está encendida SOLO en el `.env` de esta instancia; producción real sigue protegida.
- Datos de la prueba (tenant 2 de la BD `talent360_v2_saas`): empresa "DecorArte S.A. de C.V.",
  admin `prueba.qa360@test.local`, colaboradores Francisco Vega (Supervisor de Producción,
  supervisor), Adán Cuéllar (Asesor de Ventas, empleado), Marisol Herrera (Administrador Gerente,
  admin), todos con contraseña por defecto `password123`.
- ⚠️ El nombre coincide a propósito con la DecorArte real del jefe, pero vive en OTRA base
  (la V2 es `talent360_v2_saas`, aislada de la producción del puerto :3000).
