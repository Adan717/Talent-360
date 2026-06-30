# Glosario y Terminología (Talent 360)

*   **Core SaaS (El Cerebro):** El backend central hecho en Laravel. Almacena las reglas de negocio maestras, la base de datos PostgreSQL y gestiona el multi-tenant. No tiene interfaz gráfica para los empleados, es solo una API.
*   **PWA (Progressive Web App):** Aplicación web que se comporta como una app nativa en el celular (se puede instalar, enviar notificaciones push, etc.).
*   **Multi-Tenant (Marca Blanca):** Arquitectura que permite que el sistema se "rente" a otras empresas. Cada empresa (Tenant) tiene sus propios datos, colores y dominio web, pero todos usan el mismo Core SaaS.
*   **Puesto de Trabajo:** Entidad permanente en el sistema que dicta el sueldo, permisos, responsabilidades y bonos. (Ej. Vendedor Titular).
*   **Vacante:** Entidad temporal publicitaria que se muestra en la Web Pública para atraer candidatos a un Puesto.
*   **Mutación de Identidad:** El proceso de la base de datos donde un "Candidato" se transforma en un "Empleado" activo, asignándole un Puesto y habilitando sus accesos.
*   **Reloj Checador Inteligente:** Módulo proactivo que emite alarmas pre-apertura, audita asistencia mediante GPS/Wi-Fi y se sincroniza con el Motor de Nóminas.
*   **Amnistía General:** Regla de negocio en el Reloj Checador donde, si un Encargado abre tarde la sucursal por una emergencia válida, el sistema perdona automáticamente los retardos de los empleados que esperaban afuera.
*   **Academia Talent360 (Pública):** Producto E-Commerce. Plataforma de cursos pagados para el público general, con PWA de Estudiantes y Calculadora de Costeos.
*   **Academia de la Tienda (Interna):** Módulo de capacitación *exclusivo* para empleados operativos (dentro de su PWA). Sirve para inducciones, entrenamiento continuo del puesto actual y rutas de estudio para ascensos (Plan de Carrera).
