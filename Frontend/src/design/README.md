# Sistema de color de Talent 360

`tokens-talent360.json` es la fuente canónica aprobada. Conserva los valores,
los cinco grupos semánticos (`success`, `warning`, `danger`, `info`, `error`),
los usos por componente y las reglas del archivo proporcionado por el usuario.
Las propuestas de color de los mockups anteriores quedan sustituidas por este archivo.

## Integración

`scripts/design-tokens.mjs` expande `@talent360-tokens` durante PostCSS, antes de
Tailwind. Genera variables CSS y utilidades desde el JSON y lo registra como
dependencia de Vite para recarga en desarrollo. No se mantiene otra copia de la
paleta ni un archivo CSS generado manualmente. Los componentes que necesitan
colores en JavaScript importan `tokens` desde `theme.ts`.

Usar `bg-accent` / `hover:bg-accent-hover` para acciones primarias,
`bg-accent-soft` para selecciones y `text-brand-dark` para identidad en claro.
Las tarjetas usan `bg-surface border-border`; los textos, `text-text-1`,
`text-text-2` y `text-text-3`. Un estado se presenta con `StatusBadge` o con un
contenedor equivalente que combine fondo semántico, icono y texto explícito.

El JSON especifica sidebar oscura: `usage.sidebar-bg`, `usage.sidebar-text`
y `usage.sidebar-active-bg`. El token `menu-active-bg` se utiliza para menús
claros; no sustituye al estado activo de la sidebar oscura. Las selecciones
incluyen otro indicador además del borde. El foco usa `:focus-visible`, un
anillo de 2px y separación de 2px; respeta los colores forzados del sistema.

## Compatibilidad

Las claves antiguas de personalización de módulos siguen siendo legibles, pero
resuelven al tema navy. No se reescriben configuraciones guardadas, permisos,
sesiones, contratos de API, ni cálculos de asistencia o nómina. Los colores
configurables de portales públicos de cada empresa y los certificados impresos
conservan su identidad propia. Los iconos oficiales de proveedores de acceso
también conservan sus colores.

## Verificación

Ejecutar `npm test` y `npm run build` desde `Frontend`. Las pruebas de tokens
comprueban contraste de las combinaciones definidas y propagación del JSON al
CSS; no equivalen a una certificación de accesibilidad de todas las pantallas.
Los controles existentes se verifican además en el navegador con datos locales
de prueba y peticiones de API interceptadas, sin afectar registros reales.
