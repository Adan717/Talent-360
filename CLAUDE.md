# Talent360 — Contexto para Claude Code

Este proyecto se desarrolla en paralelo por dos sesiones de Claude:
- **Backend** (esta sesión, Claude Code): `Backend/app/**`, `Backend/database/migrations/**`, `Backend/routes/api.php`, `Backend/tests/**`.
- **Frontend** (sesión aparte en Cowork): `Frontend/src/components/reloj/**`, `Frontend/src/lib/**`, `Frontend/src/store/**`.

El contrato compartido entre ambas vive en `docs/BACKEND_INTERFACES.md`. Ninguna de las dos partes debe tocar la zona de la otra ni improvisar cambios de contrato sin editar ese archivo primero.

## Palabra clave: "revisa pendientes del contrato"

Cuando Francisco escriba esta frase (o algo muy similar, como "revisa el contrato" o "revisa pendientes"), ve directo a `docs/BACKEND_INTERFACES.md`, sección **"📋 Pendientes para Claude Code"** (cerca del inicio del archivo, justo después de la tabla de propiedad de archivos). Esa tabla lista, con número de sección y estado, todo lo que el lado de Frontend ha especificado y todavía no se ha implementado del lado del backend. No hace falta releer todo el documento — solo esa tabla y las secciones que liste como pendientes.

Al terminar cada ítem:
1. Márcalo como hecho en esa misma tabla.
2. Agrega justo debajo de la sección correspondiente un bloque `## ✅ Implementado (fecha) — resumen` explicando qué decisiones tomaste (así ya se ha hecho en otras secciones del documento — sigue ese mismo formato).

Si no queda nada pendiente en la tabla, contesta simplemente "sin pendientes".
