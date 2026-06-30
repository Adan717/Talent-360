# Módulo: Recursos Humanos (Directorio, Organigrama y Puestos)

El módulo de **Recursos Humanos** gestiona la estructura organizativa de la empresa, el directorio de colaboradores y el catálogo de puestos oficiales, conectando la jerarquía organizativa con los permisos del Reloj Checador.

---

## 1. Archivos Clave del Módulo
- **Componente Principal**: [RecursosHumanos.tsx](file:///c:/Users/Servidor/Desktop/Talent360/Frontend/src/components/RecursosHumanos.tsx) (Interfaz interactiva de administración, organigramas y catálogo de puestos).
- **Modelo Backend**: `app/Models/User.php` y `app/Models/JobRole.php`.

---

## 2. Funcionalidades Detalladas

### A. Directorio Activo de Colaboradores
- Listado y búsqueda dinámica de todos los empleados de la organización.
- Ficha de perfil individual que detalla: datos de contacto, horario asignado, horas de comida, día de descanso semanal y permisos de apertura.
- Filtros rápidos por estado de colaborador (Activos, Archivados/Inactivos) y áreas/departamentos de la empresa.

### B. Catálogo de Puestos de Trabajo
- Definición de roles funcionales (ej: *Supervisor*, *Asesor de Ventas*, *Ayudante Integral*).
- Asignación de áreas y descripción de responsabilidades.
- Configuración de subordinación directa (jerarquía) para definir a quién reporta cada puesto, estructurando automáticamente el organigrama.
- Asignación de permisos especiales como **Aperturador de Sucursal** y asignación de nivel de prioridad.

### C. Organigrama Interactivo (Jerárquico y Niveles)
- **Vista de Árbol Conectado**: Representación visual interactiva en forma de árbol que conecta los puestos de trabajo en una estructura jerárquica de reporteo de arriba hacia abajo. Soporta arrastrar y soltar (Drag and Drop) para re-estructurar la jerarquía u organizar vacantes.
- **Vista de Niveles de Mando**: Agrupación horizontal de puestos según su nivel de autoridad (ej: Directivos, Supervisores, Operativos).

### D. Distintivo de Responsabilidad de Apertura (Llaves Físicas)
- Integra de forma visual la posesión de llaves físicas autorizadas para la apertura del local:
  - **Encargado Principal**: Muestra el distintivo de una llave (`🔑`).
  - **Encargados Suplentes**: Muestra el distintivo de dos llaves (`🔑🔑`).
- Estos distintivos se muestran dinámicamente al lado del nombre del colaborador en las fichas del Directorio y al lado del nombre de los puestos de trabajo en el Organigrama del árbol conectado y niveles.
