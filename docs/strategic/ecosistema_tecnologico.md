# Ecosistema Tecnológico y Herramientas (Stack)

Este documento registra todas las tecnologías, "Skills" (Habilidades IA) y Sub-agentes que conforman la fábrica de desarrollo para Talent 360.

## 1. Stack Tecnológico Base
- **Backend:** Laravel (PHP) - El "Cerebro Central" (Headless CMS).
- **Base de Datos:** PostgreSQL - Usando campos `JSONB` avanzados para los Asistentes de Tareas.
- **Frontend PWA/Web:** Next.js / React.
- **Estilos:** Vanilla CSS / TailwindCSS (UI Premium, animaciones estilo "Duolingo").
- **Infraestructura Cloud:** Google Cloud Platform (Cloud SQL, Cloud Run, Cloud Storage).

## 2. Herramientas del Laboratorio (EP)
Dentro del `0_Entorno_Programacion_EP` operamos bajo un esquema estricto de pruebas antes de mover código:
- **Sandbox Visual:** Vite + React + TailwindCSS (Para previsualizar cada módulo en milisegundos).
- **Mock Data Engine:** Generadores JSON para probar estados sin base de datos.
- **Auditoría Estática de Seguridad:** Herramientas de *Linting* (ESLint/Prettier) configuradas en el EP para revisar consistencia de código y vulnerabilidades visuales antes del empaquetado.
- **Continuous Deployment (El Botón Mágico):** Script `Desplegar_Modulo_a_Tienda.bat` para inyectar código validado hacia producción.

## 3. Skills Oficiales (Habilidades IA)
- **`fullstack-web-architect` [ACTIVA]:** Automatiza y guía la creación de plataformas usando nuestra metodología exacta.

## 4. Sub-Agentes (El Equipo Virtual)
El Agente Principal (Antigravity) coordina a este equipo. Trabajan en paralelo en el EP.
- **`research` (Investigador):** Búsqueda de documentación sin gastar contexto.
- **`self` (Clon de Programación):** Un clon para tareas masivas en segundo plano.
