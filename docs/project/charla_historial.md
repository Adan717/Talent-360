# Historial de Charla y Contexto (Resumen Estratégico)

> **Documento de Transición:** Este archivo contiene el resumen exacto de todos los acuerdos lógicos, arquitectónicos y estratégicos tomados. Sirve para dar "contexto inmediato" a la nueva sesión de Antigravity cuando se abra este proyecto.

## 1. La Decisión Fundacional: SaaS Multi-Tenant
- **Arquitectura:** Headless CMS (Laravel) Multi-Tenant (SaaS Ready). Diseñado para rentarse a otras empresas.

## 2. Unidades de Negocio (El caso Talent360)
1. **Talent360 Tienda:** Operación comercial y RRHH.
2. **Academia Talent360:** Institución educativa separada con venta cruzada.

## 3. El Pipeline de Desarrollo (Glosario)
- **EP (Entorno de Programación):** Laboratorio aislado (0_Entorno_Programacion_EP).
- **Módulos:** Piezas de código nativas.
- **CMS Central:** Panel de control estricto (RBAC).

## 4. Infraestructura en la Nube y Git
- **GCP:** Cloud SQL, Cloud Run, GCS.
- **Git:** Repositorio en la raíz, control por ramas (branches).

## 5. El Flujo Maestro de RRHH (Tienda)
Prospecto -> Candidato -> Mutación de Identidad -> Colaborador.

## 6. Acuerdos Técnicos (Módulo de Tareas)
JSONB, Máquina de Estados, CRON Jobs y Proactividad.

## 7. Módulo de Compensaciones
Nómina vs Gratificación. Bonos integrados dinámicamente en el Puesto de Trabajo basándose en la eficiencia (Tareas) y tiempos (Reloj).

## 8. Motor de Cumplimiento Legal (LFT)
- **Asistente Legal de IA:** Lee los documentos de la Ley Federal del Trabajo y sugiere cambios a las reglas del negocio.
- **Variables Globales:** Las leyes (ej. reducción de horas, ley silla) alteran variables globales que afectan a todos los puestos, vacantes y cálculos de nómina simultáneamente tras la aprobación del administrador (Filtro Humano).
- **Inducción Dinámica:** Los derechos laborales se resumen con IA en lenguaje sencillo y se integran al primer curso de inducción del PWA.
