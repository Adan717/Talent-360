# Módulo: Tareas, Checklist y Rutinas (TaskRunner)

El módulo de **Tareas y Rutinas** coordina las actividades operativas del día a día, permitiendo crear listas de tareas de cumplimiento obligatorio y programar rutinas recurrentes para cada puesto de trabajo.

---

## 1. Archivos Clave del Módulo
- **Componente de Ejecución**: [PanelTareasRutinas.tsx](file:///c:/Users/Servidor/Desktop/Talent360/Frontend/src/components/tareas_rutinas/PanelTareasRutinas.tsx) (Conocido como TaskRunner: interfaz interactiva para realizar tareas desde la perspectiva del empleado en su celular).
- **Gestión de Datos**: `src/store/useTaskStore.ts` (Store de Zustand que almacena asignaciones, tareas completadas, y el pool general de tareas pendientes de la sucursal).

---

## 2. Funcionalidades Detalladas

### A. Catálogo y Programación de Rutinas
- Creación de actividades operativas que deben repetirse bajo cierta periodicidad (Diaria, Semanal, Mensual).
- Las rutinas se programan para que se asignen de forma automática al iniciar el día a ciertos roles de puesto (ej: *Rutina de Limpieza*, *Revisión de Caja*, *Encendido de Equipos*).

### B. Checklist Operativos (TaskRunner en Celular)
- Interfaz simplificada tipo checklist que los colaboradores operan desde su checador móvil.
- Permite completar tareas secuenciales, marcándolas con una casilla de verificación.
- Al completarse las tareas, se registra la hora exacta y el colaborador responsable.

### C. Pool de Tareas Sucursal (Tareas Compartidas)
- El administrador puede lanzar tareas sueltas al "pool general" de la sucursal sin un colaborador asignado fijo.
- Cualquier colaborador disponible del turno puede tomar la tarea del pool (`grabTaskFromPool`), completarla, y registrar la evidencia en el sistema.

### D. Delegación de Rutinas de Apertura
- En lugar de mantener un checklist de apertura fijo y exclusivo dentro de las pantallas del checador, las tareas obligatorias tras abrir la sucursal (ej: encender luces, limpiar terminales) se delegan al TaskRunner.
- El encargado en turno ejecuta esta rutina directamente desde su pestaña "Tareas" tras ingresar al checador al inicio de la jornada.
