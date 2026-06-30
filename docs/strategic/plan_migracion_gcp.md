# Estrategia de Migración a Google Cloud Platform (GCP)

Para garantizar un desarrollo seguro y ágil, el código nace en el Laboratorio (EP local) y eventualmente escala a la nube pública. Este es el mapa de ruta de migración.

## Fase Local (Actual)
Todo el desarrollo, validación visual y pruebas de módulos se hacen en la computadora local del arquitecto (Entorno de Programación EP). 
**Por qué:** Es gratuito, ultra rápido y permite iterar diseños visuales sin latencia de red. No pagamos servidores mientras estamos construyendo.

## Fase Staging (Pruebas en la Nube)
**¿Cuándo?** Cuando la "Prioridad 6" de la Bitácora (PWA, Web y SaaS) esté funcional localmente.
**Estrategia:**
1. Desplegaremos el `1_Core_SaaS` en un contenedor de **Google Cloud Run** (escalado automático, si no hay visitas, cobra cero).
2. Levantaremos una base de datos **Google Cloud SQL (PostgreSQL)** de bajo rendimiento (tier básico) para pruebas de la Máquina de Estados y el Motor de Tareas.
3. Desplegaremos la PWA en **Firebase Hosting / Cloud Run**.
*Objetivo:* Probar el sistema con empleados de prueba en sus celulares reales, simulando el Reloj Checador y los GPS antes del lanzamiento público.

## Fase Producción (Lanzamiento)
**¿Cuándo?** Cuando las pruebas de Staging sean exitosas y el Motor LFT esté validado.
**Estrategia:**
1. Aislamiento Multi-Tenant activado en la base de datos de producción (GCP SQL de alto rendimiento).
2. Creación del "Bucket" en **Google Cloud Storage (GCS)** con reglas de seguridad estrictas (Solo RH puede leer expedientes y PDFs legales).
3. Conexión de dominios oficiales (ej. `talent360.com` y `academiatalent360.com`).
4. Monitoreo activado (Google Cloud Logging y Alertas de Seguridad).
