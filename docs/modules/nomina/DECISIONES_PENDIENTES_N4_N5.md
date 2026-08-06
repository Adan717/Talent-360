# Nómina — decisiones de producto pendientes (N4 y N5)

**Fecha:** 2026-08-06 · **Estado:** esperando decisión del jefe. Sin su respuesta no se toca
la política de descuentos: cambiar cuánto se le descuenta a la gente no es decisión técnica.

Contexto mínimo: la Ronda 1 ya corrigió lo que era mentira o error de cálculo (timbre falso,
semana en curso, faltas fantasma). Lo que sigue es **política**: cuánto se descuenta por un
retardo y con qué tope.

---

## El problema en una página

**N5 — Los descuentos pueden superar el sueldo.** El sistema descuenta $2 por minuto de
retardo POR DEFECTO (`late_penalty_per_minute = 2.00`), y nada limita la suma de descuentos:
en las pruebas hubo netos de $0.00. El artículo 110 de la LFT limita los descuentos al
salario y el 107 **prohíbe las multas al salario**. El propio curso de LFT de la Academia 360
enseña esto — pregunta real del examen que los colaboradores contestan:

> *"¿Está permitido descontar dinero del salario base del trabajador como multa por llegar
> tarde?"* → **"No, el Artículo 107 prohíbe estrictamente imponer multas al salario."**

Hoy el producto hace por defecto lo que su propio curso llama ilegal.

**N4 — Un mismo retardo se cobra hasta TRES veces.** (1) por minuto ($2 × minuto ×
multiplicador del puesto); (2) acumulado: cada 3 retardos = 1 falta, que descuenta un día
completo; (3) esa falta además baja el proporcional del séptimo día. Las tres deducciones se
suman sin que nada lo impida.

Lo que la LFT sí permite: descontar el DÍA no trabajado (falta real, art. 63/110) y tratar
retardos acumulados conforme al reglamento interior (la figura de 3 retardos = 1 falta es
práctica común y defendible). Lo que no: la multa por minuto.

---

## Mensaje redactado para el jefe (copiar y pegar)

> Jefe, ya entramos a Nómina. Corregimos lo urgente (el timbrado fingía éxito, la semana se
> calculaba a la mitad y salían netos en $0 — ya quedó). Pero hay UNA decisión que es tuya
> antes de seguir, porque es de política de descuentos, no de código:
>
> Hoy el sistema descuenta $2 por cada minuto de retardo (configurable, pero ese es el
> default), y además el mismo retardo puede cobrarse doble: acumulando 3 retardos se marca
> una falta que descuenta el día completo Y baja el pago del séptimo día. Resultado real en
> pruebas: colaboradores con neto de $0.00.
>
> El detalle legal: el art. 107 de la LFT prohíbe las multas al salario — y nuestro propio
> curso de la Academia se lo enseña así a los colaboradores. El descuento por minuto es
> exactamente eso, una multa. Lo que sí permite la ley: descontar el día de una falta real y
> manejar retardos acumulados vía reglamento interior (3 retardos = 1 falta).
>
> Te propongo (opción A): quitar el descuento por minuto (default $0; si una empresa insiste
> en activarlo, que sea decisión explícita de ella en su configuración, bajo su
> responsabilidad), dejar los retardos SOLO por acumulación (3 = 1 falta, configurable), y
> que esa falta acumulada sí baje el séptimo día (es el mismo criterio que una falta real).
> Con eso un retardo se cobra UNA vez y por la vía que la ley tolera.
>
> Alternativas si no quieres la A: (B) mantener el descuento por minuto pero con tope diario
> (p. ej. nunca más del costo del día) y quitando la doble vía de acumulación; o (C) dejarlo
> como está, sabiendo que el default contradice el art. 107 y a nuestra propia Academia.
>
> ¿Con cuál nos vamos? Con tu respuesta lo implemento en la siguiente ronda de Nómina.

---

## Nota técnica para cuando haya respuesta

- Opción A = `late_penalty_per_minute` default 0.00 (migración que ponga en 0 los tenants que
  tengan el default heredado de 2.00 SIN haberlo tocado a mano — ojo con el patrón de datos
  viejos), UI de LFT settings con leyenda del art. 107 si alguien lo activa, y
  `late_action_mode` documentado. La acumulación 3→1 y el séptimo día ya existen y quedan.
- Opción B = tope `min(deducción_por_minutos, salario_diario)` por día + apagar
  `absencesFromLates` cuando el modo por-minuto esté activo (una sola vía a la vez).
- En ambos casos: N6 (bruto que respete el periodo pedido) entra en la misma ronda.
