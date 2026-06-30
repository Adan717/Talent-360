# Módulo: Reclutamiento & Bolsa de Trabajo (ATS)

El módulo de **Reclutamiento & ATS (Applicant Tracking System)** automatiza el flujo de atracción, evaluación y contratación de personal, conectando las vacantes publicadas con el proceso de onboarding e inducción.

---

## 1. Archivos Clave del Módulo
- **Componente de Reclutamiento**: [AtsManager.tsx](file:///c:/Users/Servidor/Desktop/Talent360/Frontend/src/components/AtsManager.tsx) (Administración de vacantes, postulantes e histórico de procesos).
- **Tablero Público**: [WebPublica.tsx](file:///c:/Users/Servidor/Desktop/Talent360/Frontend/src/components/WebPublica.tsx) (Bolsa de trabajo pública para que candidatos externos apliquen y carguen sus CVs).

---

## 2. Funcionalidades Detalladas

### A. Publicación y Gestión de Vacantes
- Creación de ofertas de empleo asociadas a puestos del catálogo de Recursos Humanos.
- Configuración de sueldos, requisitos, sucursales y descripciones del puesto.
- Estado de vacantes (Activas, Pausadas, Cerradas).

### B. Tablero de Control de Postulantes (Embudo ATS)
- Visualización de candidatos en un embudo Kanban por etapas:
  1. *Postulados*
  2. *En Filtro Telefónico*
  3. *Entrevistas programadas*
  4. *Evaluaciones*
  5. *Oferta Formal*
  6. *Contratados / Rechazados*
- Permite arrastrar candidatos entre columnas para avanzar su estatus en tiempo real.

### C. Agenda de Entrevistas & Comentarios
- Registro de fechas y horarios de entrevistas.
- Sección para que los reclutadores dejen comentarios cualitativos del postulante.

### D. Contratación Directa y Onboarding Automático
- Al hacer clic en "Contratar", el ATS extrae los datos del postulante y:
  - Lo convierte en colaborador activo dentro del catálogo de Recursos Humanos.
  - Le asigna un número de ID de empleado consecutivo.
  - Crea una cuenta con correo temporal e instrucciones de acceso.
  - Envía la invitación para descargar la PWA y activar su PIN del Reloj Checador.
