# 🔴 TICKET CRÍTICO — La periodicidad del salario no se declara

> **Estado:** ✅ **CERRADO el 2026-08-24** (`e85de4d`) · **Origen:** Fotografía Financiera, Fase 0

## Cómo se cerró

- **El `/ 6.0` salió del motor de nómina.** El salario diario lo resuelve
  `App\Support\SalarioDiarioCalculator` a partir de lo que el expediente DECLARA.
- **No se creó la columna `salary_periodicity`** que este ticket pedía: `periodicidad_captura` ya
  existía desde 2026-08-03 y guarda ese mismo hecho. Dos columnas para lo mismo habrían sido la
  duplicación que esta campaña lleva cerrando. El formulario de la ficha sigue aceptando el nombre
  `salary_periodicity` y ahí se traduce.
- **Retrocompatibilidad probada**: al expediente legado no se le movió un peso ($2,100 → $350
  diarios, idéntico al `/6`), pero ahora viaja marcado `salary.periodicity_pending`.
- **Verificado en pesos** con `nomina:fotografia` contra la línea base: la única nómina que cambió
  en todo el sistema fue la de Rosa Elena, y por la Regla 4 (el `$2,400`), no por el divisor.

Lo que sigue vivo del mandato original es la **recaptura**: mientras un expediente no declare su
periodicidad, se le conserva el supuesto histórico. Eso ya no es una bomba escondida — es una
bandera visible en la respuesta del motor.

---

> **Estado original:** ABIERTO · **Creado:** 2026-08-24
> **BLOQUEADOR PARA EL LANZAMIENTO COMERCIAL PÚBLICO.** No se vende a un cliente nuevo hasta
> que esto esté cerrado.

## El problema en una frase

`employees.base_salary` guarda una cantidad **sin decir de qué periodo es**, y el motor de nómina
*supone* que es semanal y la divide entre 6.

## Por qué es una bomba y no una deuda técnica más

Hoy el motor hace esto (`ClockService::calculatePayrollForEmployee`):

```php
$dailySalary = $baseSalary / 6.0;   // supone SEMANAL, y además reparte entre 6 y paga 7
```

Ese cálculo tiene **dos errores encima del otro**:

1. **El divisor.** Aun siendo semanal, la práctica LFT reparte entre **7**, no entre 6, porque el
   séptimo día es descanso pagado (art. 69). Repartir entre 6 y pagar 7 infla el diario **16.67 %**.
2. **La suposición de periodicidad — el error grande.** Si el número capturado era **mensual**, el
   salario diario sale **casi cinco veces** más grande.

Medido con datos reales por `php artisan nomina:fotografia`:

| Colaborador | Capturado | Diario que usa HOY (/6) | Si fuera SEMANAL (/7) | Si fuera MENSUAL (/30) |
|---|---|---|---|---|
| Francisco Vega | $14,000 | **$2,333.33** | $2,000.00 | $466.67 |
| Marisol Herrera | $18,000 | **$3,000.00** | $2,571.43 | $600.00 |
| Adán Cuéllar | $9,000 | **$1,500.00** | $1,285.71 | $300.00 |

Sueldos de $14,000 y $18,000 en México se leen como **mensuales** a simple vista. Si lo eran,
el sistema le está calculando a Francisco un salario diario de $2,333 cuando su diario real es
$466. Eso no es un redondeo: es **cinco veces el sueldo**, y arrastra al IMSS, al aguinaldo, a la
prima vacacional y a cualquier indemnización, porque todos se calculan sobre el salario diario.

## Exposición HOY: $0 — y por qué eso no tranquiliza

El piloto real (**DecorArte S.A.C.V**) captura `salario_diario` explícito para sus 3 colaboradores
con sueldo, así que el `/6` no lo toca. Los $8,750/semana ($455,000/año) que arroja la fotografía
son **íntegramente de empresas de prueba**.

La exposición es cero **por accidente de fechas**: ese cliente se dio de alta después de que
existiera `App\Support\SalarioDiario`. **El primer cliente que capture "$18,000 mensuales" en el
campo viejo recibe una nómina cinco veces inflada, y nadie se va a enterar hasta que llegue el
recibo.** Por eso es bloqueador de venta, no un pendiente de mantenimiento.

## Mandato para la próxima iteración

### 1. El esquema declara la periodicidad

Nueva columna explícita en el expediente:

```php
$table->enum('salary_periodicity', ['daily', 'weekly', 'biweekly', 'monthly'])->nullable();
```

`nullable` a propósito: **null significa "no se sabe"**, y no se sabe es distinto de cualquier
valor. Un expediente sin periodicidad declarada no se calcula a la brava — se marca, igual que hoy
se marca a quien no tiene sueldo (`salary.pending`, Regla 4).

### 2. Un calculador dinámico sustituye al `/6`

`App\Support\SalarioDiarioCalculator` (o estrategia por periodicidad) resuelve el salario diario
leyendo esa columna, y **el `/6` desaparece del motor**. Divisores: los de la práctica LFT/SAT que
ya declara `App\Support\SalarioDiario` — diario /1, semanal /7, quincenal /15, mensual /30.

Orden de precedencia: `salario_diario` explícito → `base_salary` con periodicidad declarada →
**pendiente** (nunca una suposición).

### 3. La migración es por RECAPTURA, jamás por fórmula

Regla que no se negocia, y es la razón de que este ticket no se haya ejecutado ya:

> **Cambiar el divisor debajo de un expediente existente le baja el sueldo a alguien que tiene un
> pago acordado, en silencio.** No se hace.

El camino es: (a) migración que deja `salary_periodicity` en **null** para todos los expedientes
legados; (b) pantalla que obliga a declararla al editar la ficha; (c) informe de quién falta;
(d) mientras tanto, los legados **siguen con el `/6` tal cual**, sin sorpresas, con el aviso a la
vista.

### 4. Antes y después: la fotografía

`php artisan nomina:fotografia --json` se corre antes y después de cada paso y se comparan los dos
JSON. Si el dinero de alguien se mueve, se ve en pesos. Ese guardarraíl ya existe.

## Criterio de terminado

- [ ] Columna `salary_periodicity` en `employees`, nullable, con migración reversible
- [ ] `SalarioDiarioCalculator` resolviendo por periodicidad; **cero apariciones de `/ 6.0`** en el motor
- [ ] Expediente sin periodicidad declarada → marcado, no calculado a la brava
- [ ] La ficha del colaborador obliga a declararla (y muestra el diario resultante antes de guardar)
- [ ] Informe de expedientes legados pendientes de recaptura
- [ ] Prueba por cada periodicidad + prueba de que un legado **no cambia** de importe
- [ ] `nomina:fotografia` antes/después con diferencia $0.00 en los expedientes ya capturados

## Referencias

- `Backend/app/Services/ClockService.php` — el `/ 6.0` vive aquí
- `Backend/app/Support/SalarioDiario.php` — los divisores correctos, ya escritos
- `Backend/app/Console/Commands/FotografiaFinancieraNomina.php` — el medidor
- `docs/FOTOGRAFIA_FINANCIERA_2026-08-24.md` — la corrida que destapó esto
- `docs/NOMINA_PERIODICIDAD_MULTIEMPRESA_2026-08-02.md` — el análisis original del problema
