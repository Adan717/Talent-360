# Pruebas que pasan de día y fallan de noche

**Regla corta: en una prueba, ninguna fecha se escribe a mano y ningún "hoy" se calcula en UTC.**

Si una prueba falla de madrugada y pasa por la mañana **sin que nadie haya tocado el código**,
casi siempre es uno de estos dos patrones. Los dos ya nos costaron una noche de depuración
(2026-08-06, suite de Postgres corrida a las 04:50 UTC).

---

## 1. "Hoy" en UTC contra "hoy" del negocio

El servidor y las pruebas corren en **UTC**. El producto razona en la zona del cliente
(`America/Mexico_City` por defecto, ver `App\Helpers\TenantTimezone`). **Entre las 18:00 y la
medianoche de México son dos días distintos.**

`CerrarSucursalTest` sembraba la fila del día con `now()` —UTC— mientras
`StoreOpeningService::getTodayOpeningStatus` la buscaba con la zona del tenant. A las 22:50 de
México la prueba abría la sucursal el día 6 y el servicio la buscaba el día 5: *"la sucursal no
está abierta"*.

```php
// MAL: es el día del servidor, no el del negocio.
'date' => now()->format('Y-m-d'),

// BIEN: el mismo criterio que usa el código que se está probando.
'date' => Carbon::now(TenantTimezone::for($tenantId))->format('Y-m-d'),
```

Aplica igual a los `whereDate('date', now())` de las aserciones.

## 2. Fechas fijas que caducan contra una ventana

`TurnoNocturnoCruzaMedianocheTest` fichaba en `'2026-07-30T04:00:00Z'`, escrito a mano. Los
ponches offline solo se aceptan dentro de `PunchBatchController::MAX_AGE_DAYS = 7` (anti-
backdating). **La prueba caducó sola**: el 6 de agosto a las 04:54 UTC ese ponche cumplió 7 días
con 54 minutos y empezó a rechazarse — mientras el otro ponche del caso, cuatro horas más tarde,
todavía entraba. Resultado: una fila en vez de dos, en una prueba que llevaba días en verde.

```php
// MAL: envejece hasta salirse de la ventana y un día empieza a fallar sola.
$this->fichaEn($user, '2026-07-30T04:00:00Z', 'check_in', 'x');

// BIEN: relativo a hoy, siempre dentro de la ventana y siempre en el pasado.
$noche = Carbon::now('America/Mexico_City')->startOfDay()->subDays(2);
$this->fichaEn($user, $noche->copy()->addHours(22)->utc()->format('Y-m-d\TH:i:s\Z'), 'check_in', 'x');
```

**Cuidado con el otro extremo**: si el instante se calcula sobre "hoy a las 02:00" y la suite
corre a la 01:00, ese instante queda en el FUTURO y lo rechaza el control de skew. Por eso el
ancla es *hace dos días*: la jornada completa queda siempre en el pasado, corra a la hora que
corra.

---

## Al escribir una prueba, pregúntate

1. ¿Hay alguna fecha literal? → hazla relativa a `now()`.
2. ¿Algo compara contra una ventana (antigüedad, vigencia, caducidad)? → el caso tiene que caer
   holgadamente dentro, no en el borde.
3. ¿El código que pruebo resuelve "hoy" con la zona del tenant? → siembra con esa misma zona.
4. ¿Pasaría igual a las 3 de la mañana? Si no lo sabes, es que no.

## Y una de operación

**No correr dos suites de Postgres a la vez.** Comparten la base `talent360_test`, y el
`RefreshDatabase` de una hace `drop table ... cascade` mientras la otra lee: se traban y salen
fallos con cara de bug de producto que no lo son (`SQLSTATE[40P01] Deadlock detected`).
