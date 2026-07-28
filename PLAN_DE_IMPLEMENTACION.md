# Plan de Implementación: Mejoras y Nuevas Funcionalidades Talent360

Plan de implementación detallado para incorporar el conjunto de mejoras aprobadas, optimizaciones y nuevas funcionalidades operativas en la plataforma **Talent360**.

---

## Mapeo de Componentes a Desarrollar

### 1. Módulo Landing Page & Onboarding SaaS
- **Archivos a modificar**: 
  - `Frontend/src/components/SaaSLandingPage.tsx`
  - `Backend/app/Http/Controllers/AuthController.php`
- **Funcionalidades**:
  - Verificación de email en segundo plano mediante token temporal.
  - Botón "Re-enviar Enlace de Activación" en onboarding.
  - Optimización de carga Landing (Lighthouse): Pre-carga de imágenes Hero/testimoniales (`fetchpriority="high"` y formato WebP).

---

### 2. Módulo Reloj Checador & Dialer Principal
- **Archivos a modificar**:
  - `Frontend/src/components/reloj/DialPrincipal.tsx`
  - `Frontend/src/components/reloj/useClockEngine.tsx`
  - `Backend/app/Http/Controllers/KeyTransferController.php`
  - `Backend/app/Http/Controllers/SupervisorReportController.php` (Nuevo)
- **Funcionalidades**:
  - **Indicador GPS & Precisión**: Micro-badge interactivo en la cabecera del Dialer (`DialPrincipal.tsx`) mostrando precisión en metros (ej. `GPS Ok · ± 6m`).
  - **Alerta de Batería Crítica (< 5%)**: Advertencia visual en pantalla si el nivel de batería del dispositivo cae al 5% o menos durante un turno activo.
  - **Reporte Imprimible de Traspaso de Llaves**: Generación de ticket/PDF firmado entre Encargado Saliente y Entrante.
  - **Reporte de Cierre de Jornada para Supervisores**: Tarea de cierre obligatorio para supervisores que compila en PDF/Ticket las asistencias, retardos, incidencias del día y notas operativas del supervisor.

---

### 3. Módulo Recursos Humanos & Expedientes
- **Archivos a modificar**:
  - `Frontend/src/components/RecursosHumanos.tsx`
  - `Backend/app/Http/Controllers/EmployeeController.php`
- **Funcionalidades**:
  - Descarga masiva de expedientes completos de colaborador empaquetados en formato **.ZIP** conteniendo todos los documentos adjuntos.

---

### 4. Módulo Reclutamiento (ATS) & Vacantes
- **Archivos a modificar**:
  - `Frontend/src/components/AtsManager.tsx`
  - `Backend/app/Http/Controllers/RecruitmentController.php`
- **Funcionalidades**:
  - Plantillas de correo automatizadas para notificar a los candidatos al avanzar en el embudo Kanban de contratación.

---

### 5. Módulo Academia & Capacitación
- **Archivos a modificar**:
  - `Frontend/src/components/GestorAcademia.tsx`
  - `Frontend/src/components/reloj/Academia.tsx`
  - `Backend/app/Http/Controllers/AcademyController.php`
- **Funcionalidades**:
  - **Reproductor Interactivo con Auto-Reanudación**: Guarda automáticamente cada 5 segundos la posición de reproducción (`currentTime`) para videos nativos o YouTube. Al reingresar al curso, salta al segundo exacto donde se pausó.
  - **Certificados PDF con QR de Autenticidad**: Generación de diplomas descargables en PDF con código QR dinámico de verificación pública.

---

### 6. Módulo Gestor de Documentos & Firma Digital
- **Archivos a modificar**:
  - `Frontend/src/components/GestorDocumentos.tsx`
- **Funcionalidades**:
  - Visor interactivo PDF integrado en modal (`iframe` / `pdfjs`) para previsualizar documentos sin forzar la descarga.

---

### 7. Módulo Cumplimiento LFT & Nóminas
- **Archivos a modificar**:
  - `Frontend/src/components/LftManager.tsx`
  - `Backend/app/Http/Controllers/LftExportController.php` (Nuevo)
- **Funcionalidades**:
  - **Simulador de Finiquito y Liquidación LFT**: Calculadora con desglose proporcional de Aguinaldo, Vacaciones, Prima Vacacional, Prima de Antigüedad e Indemnización Constitucional.
  - **Exportador de Reportes STPS para Inspecciones Laborales**: Generación de reportes de asistencia e incidencias en formato PDF/Excel oficial membretado y normativo listo para entregar a inspectores de la STPS.

---

### 8. Módulo Facturación, Licencias & SuperAdmin
- **Archivos a modificar**:
  - `Frontend/src/components/SaaSPlatformAdmin.tsx`
  - `Backend/app/Http/Controllers/PlatformAdminController.php`
- **Funcionalidades**:
  - Dashboard financiero con métricas de MRR (Ingreso Recurrente Mensual) y ARR (Ingreso Recurrente Anual) en tiempo real en el panel SuperAdmin.

---

## Verificación de Calidad
- Suite de Pruebas Backend: `php artisan test` (en `Backend/`).
- Compilación Frontend: `cmd /c "cd /d c:\Users\Servidor\Desktop\Talent360\Frontend && npm run build"`.
