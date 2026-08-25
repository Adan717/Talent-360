# Fotografía financiera — Fase 0 (2026-08-24)

Informe de **sólo lectura** exigido por el consejo antes de tocar el motor de nómina. Se corre con:

```
php artisan nomina:fotografia            # informe legible
php artisan nomina:fotografia --json     # para diffear entre corridas
```

Corre dentro de una transacción que **siempre se revierte**, porque el motor de nómina tiene una
escritura escondida (crea la política LFT de la empresa si falta) y una fotografía no cambia lo
que fotografía. Hay una prueba que lo fija comparando la huella de la base antes y después.

## Qué mide

**A) Cada nómina ya guardada, recalculada con el motor de hoy**, comparada peso a peso con lo que
quedó registrado. Sirve para dos preguntas distintas: *¿alguien quedó pagado de menos por un
defecto ya corregido?* y —corriéndolo antes y después de tocar el motor— *¿este cambio movió el
dinero de alguien?*

**B) Cuánto dinero mueve hoy la fórmula legada `base_salary / 6`**, con las tres lecturas
posibles del sueldo capturado (semanal, quincenal, mensual).

## Resultado de la primera corrida

### A · Nóminas: 26 revisadas, 4 con diferencia

| | |
|---|---|
| Nóminas revisadas | 26 |
| Con diferencia | 4 — **todas en la empresa de pruebas** (`pruebaqa360`) |
| Diferencia total | **+$20,902.78** (el motor de hoy pagaría más) |
| Nóminas del piloto real (`decorarte`, tenant 4) | 8, **todas cuadran al centavo** |

Las 4 diferencias están en la misma semana, **2026-07-27 → 2026-08-02**, y todas dicen *"faltas de
menos hoy"*. No es casualidad: los 4 colaboradores de esa empresa fueron dados de alta el **29 y 30
de julio**, es decir **después de que empezara ese periodo**. Es exactamente el defecto corregido en
la fase 11 —se cobraban como faltas los días anteriores al alta— y ésta es su huella en dinero:
**$20,902.78 en una sola semana, entre cuatro personas.**

Una de esas 4 está **firmada** (`approved_by_admin`): Adán Cuéllar, −$3,500.00. **Es una cuenta de
la empresa de pruebas, no una persona real cobrando.** Ninguna nómina del piloto real tiene
diferencia, así que **no hay nadie a quien se le deba dinero.**

### B · La fórmula `base_salary / 6`

| | |
|---|---|
| Plantilla activa | 9 |
| Con salario diario declarado (no les aplica el /6) | **3 — los 3 del piloto real** |
| Por la fórmula legada `/6` | 5 — **todos de empresas de prueba** |
| Sin sueldo capturado (el `$2,400` escondido) | **1** — Rosa Elena Márquez, contratada el 23-ago |

**Exposición real hoy: $0.** El piloto real captura salario diario explícito, así que el `/6` no lo
toca. Los $8,750/semana ($455,000/año) que arroja el informe son **íntegramente de datos de prueba**.

Pero el informe destapó algo más grande que el 6-contra-7. Los sueldos capturados en esas empresas
—$14,000, $18,000, $9,000— **parecen mensuales**, y el motor los trata como semanales:

| Colaborador | Capturado | Diario HOY (/6) | si fuera MENSUAL |
|---|---|---|---|
| Francisco Vega | $14,000 | **$2,333.33** | $466.67 |
| Marisol Herrera | $18,000 | **$3,000.00** | $600.00 |

Si esos sueldos eran mensuales, la distorsión no es del 16.67 % — es de **casi cinco veces**. El
defecto de fondo no es el divisor: es que **`base_salary` no declara periodicidad** y el motor
*supone* semanal. Por eso existe `App\Support\SalarioDiario` y por eso el piloto ya captura el
diario. Quién tiene qué periodicidad lo dice el contrato de cada persona; el sistema no puede
saberlo, y el informe ya no finge que sí.

## Lo que este informe NO hace

No corrige nada. El `/6` se queda intacto: cambiarlo bajaría el sueldo de gente con un pago ya
acordado. La migración es por **recaptura explícita del expediente**, nunca por cambiar la fórmula
debajo de quien ya cobra con ella.

## Consecuencia para las fases siguientes

- **Regla 8 (revisar nóminas firmadas): cerrada sin deuda.** Las diferencias son de datos de
  prueba; el piloto real cuadra al centavo.
- **Regla 4 (matar el `$2,400`): confirmada y sin riesgo.** Afecta a **una** persona, sin ningún
  recibo firmado suyo, y las pruebas fijan ese número como dato del expediente, no como default.
- **Línea base establecida.** Antes y después de cada cambio del motor se corre `--json` y se
  compara: si el dinero se mueve, se ve.
