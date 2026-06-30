# Plan de Implementación Estratégico

Este documento resume la estrategia de ejecución general del proyecto a largo plazo, basada en las reglas de dependencia técnica acordadas.

## Fase 0: El Laboratorio de Pruebas (EP)
**Objetivo:** Construir la mesa de trabajo visual e interactiva.
- **Catálogo Visual de Plantillas:** Uso de Vite + React para previsualizar UIs y animaciones en el navegador sin tocar el servidor de producción.
- **Pruebas de Lógica:** Sandbox PHP para probar IA y algoritmos.
- **Mock Data:** Simulación de datos falsos para ver todos los escenarios UI.
- **El Botón Mágico (Continuous Deployment):** Un script automatizado (`Desplegar_Modulo_a_Tienda.bat`) que empaqueta y mueve el código validado gráficamente desde el EP hacia las carpetas reales de Producción (`2_Talent360_Tienda`). *Cero copiado manual.*

## Fase 1: El Flujo de la Tienda (Operativo)
**Objetivo:** Sistematizar al 100% las operaciones de Talent360 Tienda.
1. **Fundación SaaS:** Levantar Laravel, Postgres y Autenticación.
2. **Onboarding:** Crear a los usuarios, embudo de candidatos y Mutación de Identidad.
3. **Operación (Tareas):** Sistema JSONB, Máquina de Estados y CRON Jobs.
4. **Compensaciones:** Motor de bonos y pagos (Nómina/Gratificación).
5. **Cumplimiento Legal (LFT):** Integración de IA para leer leyes con aprobación humana.
6. **Frontends:** Conectar las interfaces (Web Pública y PWA Empleados) al cerebro central, importando los diseños desde el EP mediante el "Botón Mágico".

## Fase 2: La Academia Talent360 (Educativo y Ventas)
**Objetivo:** Crear una plataforma educativa que genere ventas para la tienda.
- **Backend Moodle:** Control de calificaciones, inscripciones y maestros.
- **Frontend Duolingo:** Retención mediante gamificación y rutas visuales.
- **Marketing:** Cross-Selling profundo con los productos de la tienda matriz.
