# Módulo: Academia de Capacitación e Inducción (Capacitación y Evaluación)

El módulo de **Academia de Capacitación** está diseñado para acelerar la curva de aprendizaje de los nuevos empleados mediante cursos interactivos de inducción corporativa y evaluaciones periódicas de conocimientos.

---

## 1. Archivos Clave del Módulo
- **Componente Principal**: [GestorAcademia.tsx](file:///c:/Users/Servidor/Desktop/Talent360/Frontend/src/components/GestorAcademia.tsx) (Interfaz interactiva de cursos, lecciones, cuestionarios y resultados).

---

## 2. Funcionalidades Detalladas

### A. Estructura de Cursos y Lecciones
- Organización del contenido de aprendizaje en Cursos, divididos a su vez en Lecciones secuenciales.
- Soporte para contenido de capacitación multimedia: texto con formato, imágenes y lecciones en video integradas (reproducción embebida).

### B. Lecciones Bloqueadas (Progreso Secuencial)
- Para garantizar el aprendizaje, las lecciones deben completarse en orden. El sistema bloquea el acceso a la lección posterior hasta que el usuario finaliza la anterior.

### C. Evaluaciones y Exámenes (Quizzes)
- Cuestionarios interactivos de opción múltiple al término de cada curso para evaluar la comprensión.
- Calificación automática al finalizar el cuestionario, mostrando al empleado qué respuestas fueron correctas y cuáles incorrectas.

### D. Programa de Inducción para Nuevos Ingresos
- Al ingresar a laborar por primera vez, el sistema activa un "Curso de Inducción" obligatorio.
- El empleado puede acceder de forma directa al material de la Academia desde la barra de navegación del Reloj Checador en su celular para estudiar y presentar sus exámenes durante sus primeros días de trabajo.
