# Un vigilante para el servidor — hoja para el dueño

Hoy nadie vigila el servidor. Si Postgres se cae un sábado a las 3 de la mañana, o si el respaldo
deja de correr, **la primera persona en enterarse es un cliente el lunes**. Esta hoja se resuelve
en unos 10 minutos y desde entonces el que se entera primero es usted, por teléfono.

Nosotros **no** damos de alta la cuenta: son sus alertas, a su correo y a su teléfono, y deben
seguir funcionando aunque nosotros no estemos. Aquí está exactamente qué teclear.

---

## 1. Qué hay que dar de alta

Sirve cualquiera de los dos servicios; los dos tienen plan gratuito suficiente para esto. Elija
uno, no los dos:

- **UptimeRobot** — https://uptimerobot.com (el plan gratis vigila cada 5 minutos)
- **Better Stack** — https://betterstack.com (el plan gratis vigila cada 3 minutos)

Cree la cuenta con **su** correo, no con el de nadie más.

## 2. Qué teclear, campo por campo

Es el mismo monitor en los dos servicios, sólo cambian los nombres de las casillas.

| Casilla | Qué poner |
|---|---|
| Tipo de monitor | **HTTP(S)** (en Better Stack: *Uptime → HTTP monitor*) |
| Nombre | `Talent 360 — API` |
| URL | `https://talent360.com.mx/api/health` |
| Método | `GET` |
| Frecuencia | cada **5 minutos** (o el mínimo que le deje el plan) |
| Qué se espera | **código de estado 200** — sólo 200. Nada de "2xx". |
| Avisar cuando falle | tras **2 comprobaciones fallidas** seguidas (no a la primera: evita el aviso por un parpadeo de red) |
| Avisar a | su **correo** y su **teléfono** (SMS o la app del servicio; la app es gratis y llega antes) |
| Segundo contacto | **Adán** — `akecuellarherbandez@gmail.com` |

Dos cosas que **no** hay que hacer:

- **No** pedir "que la página contenga tal palabra". El contrato de esta dirección es el número:
  200 significa sano, cualquier otra cosa significa enfermo. Buscar palabras se rompe solo.
- **No** apuntar el monitor a `https://talent360.com.mx/` a secas. Esa dirección la sirve el
  servidor de archivos y **devuelve 200 aunque el sistema entero esté muerto por detrás**: se
  quedaría en verde para siempre. Tiene que ser `/api/health`, que sí pasa por el programa.

## 3. Qué le está diciendo cada aviso

La dirección contesta algo así cuando todo está bien:

```json
{"status":"ok","db":"ok","respaldo":{"ok":true,"horas":6.2,"ultimo_utc":"2026-09-05T02:45:11Z","motivo":null},"timestamp":"..."}
```

Cuando algo falla, el código deja de ser 200 y el cuerpo dice qué es. Abra la dirección en el
navegador (se puede, no pide contraseña) y mire estas dos palabras:

| Lo que ve | Qué pasó | Qué hacer |
|---|---|---|
| **503** con `"db":"fail"` | La base de datos no contesta. Nadie puede fichar ni consultar nómina. **Es lo más grave.** | Avisar a Adán de inmediato. |
| **503** con `"respaldo"` y `"motivo":"viejo"` | El sistema funciona, pero **hace más de 26 horas que no se respalda**. No es urgente para los empleados; es urgentísimo para usted: si el disco se pierde hoy, se pierde lo que no se respaldó. | Avisar a Adán el mismo día. |
| **503** con `"motivo":"sin_marca"` o `"ilegible"` | El sistema no encuentra el recibo del respaldo. O el respaldo nunca ha corrido en esta máquina, o dejó de escribirlo. Se trata igual que el anterior: **un respaldo que no se puede confirmar no cuenta**. | Avisar a Adán el mismo día. |
| **502** o **503 sin cuerpo** | El programa está caído del todo (no llegó ni a contestar). | Avisar a Adán de inmediato. |
| **timeout** / "sin respuesta" | El servidor no responde: apagado, sin red, o el disco lleno. | Avisar a Adán de inmediato. |
| **Certificado caducado** | El candado de HTTPS se venció. La página empieza a dar miedo a quien entra. | Avisar a Adán; se renueva solo, así que si falla es que algo se atoró. |

La dirección **no revela nada privado**: no dice el nombre de las máquinas, ni contraseñas, ni
datos de ningún empleado. Sólo "sano / enfermo" y hace cuántas horas fue el último respaldo. Se
puede dejar pública sin riesgo, que es justo lo que la hace vigilable desde fuera.

## 4. El ensayo de alarma (no se salte esto)

Una alarma que nunca ha sonado no es una alarma; es una suposición. Hágalo el mismo día que la
dé de alta:

1. En el servicio de vigilancia, **edite el monitor** y cambie la URL a
   `https://talent360.com.mx/api/health-que-no-existe`. Guarde.
2. Espere dos ciclos (unos 10 minutos).
3. **Le debe llegar el aviso al teléfono y al correo.** Si no llega, no hay vigilancia: revise
   los contactos del servicio y repita. Es el paso que de verdad se está probando.
4. Deje la URL como estaba (`/api/health`) y confirme que el monitor vuelve a verde.
5. Anote aquí la fecha del ensayo: `Ensayo hecho el ____________`

Conviene repetirlo una vez al año, y siempre después de cambiar de teléfono.

## 5. Orden de estreno (para quien despliegue esto la primera vez)

⚠️ **Importante, y en este orden**, porque el nuevo `/api/health` responde **503 mientras no
exista la marca del respaldo** — y la marca la escribe el script de respaldo, no el despliegue.
Al revés, la dirección estrena en rojo y parece que el despliegue rompió algo:

1. **Subir el script de respaldo actualizado** al servidor y dejarlo en su sitio:
   ```bash
   scp scripts/respaldo_talent360.sh root@46.225.153.115:/tmp/respaldo.sh
   ssh root@46.225.153.115 "sed -i 's/\r$//' /tmp/respaldo.sh && install -m 700 /tmp/respaldo.sh /usr/local/bin/respaldo-talent360"
   ```
2. **Correrlo una vez a mano** y comprobar que dejó la marca:
   ```bash
   ssh root@46.225.153.115 "/usr/local/bin/respaldo-talent360 && cat /var/www/talent360-v2/Backend/storage/app/respaldo/ultimo.json"
   ```
   Debe imprimir algo como
   `{"instancia":"v2","terminado_utc":"2026-09-05T02:45:11Z","dump_bytes":48210944}`.
3. **Ahora sí, desplegar** (`/usr/local/bin/deploy-v2`).
4. Comprobar la dirección desde fuera: `curl -i https://talent360.com.mx/api/health` → debe dar
   **200**.
5. Recién entonces, dar de alta el monitor (pasos 1 y 2 de esta hoja) y hacer el ensayo.

A partir del siguiente despliegue esto ya no hace falta: **`deploy-v2` instala solo el script de
respaldo y su cron si faltan**, así que un servidor nuevo ya no puede nacer sin respaldo. Lo que
el despliegue **no** hace es fallar porque el respaldo sea viejo — un respaldo viejo no debe
impedir desplegar el arreglo que quizá lo repara. De eso avisa el vigilante, que es quien tiene
un humano detrás.

## 6. Lo que esto NO vigila

Para que nadie se confíe de más:

- **No** vigila que los datos sean correctos, sólo que el sistema esté vivo y con respaldo.
- **No** vigila el espacio en disco (se ha llenado antes con caché de compilación; hoy el
  despliegue lo limpia solo, pero nadie mira el número).
- **No** vigila que el respaldo se pueda RESTAURAR — sólo que exista y sea reciente. La
  restauración se prueba a mano; los pasos están en
  [RESPALDO_Y_RESTAURACION.md](RESPALDO_Y_RESTAURACION.md). Conviene repetir esa prueba una vez
  al año.
- **No** vigila la copia fuera del servidor (hoy depende de que la máquina de Adán esté
  encendida). El destino definitivo en la nube sigue siendo una decisión pendiente del dueño.

---

*Detalle técnico: la dirección la sirve `Backend/app/Http/Controllers/SaludController.php`; la
edad del respaldo la calcula `App\Support\EstadoDelRespaldo` leyendo
`Backend/storage/app/respaldo/ultimo.json`, con un margen de 26 horas (cron diario + 2 h). El
mismo cálculo lo usa `php artisan reloj:preflight`.*
