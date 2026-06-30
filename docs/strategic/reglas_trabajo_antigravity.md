# Reglas de Trabajo (Metodología Antigravity)

Este documento centraliza todas las reglas operativas, de documentación y de flujo de trabajo que el Asistente Antigravity y el Usuario deben respetar estrictamente durante todo el ciclo de vida del desarrollo de software.

## 1. 🗂️ Documentación y Fases de Implementación (Regla Principal)
* **Planes de Implementación Consecutivos:** Cada que haya una nueva fase de desarrollo, el plan propuesto debe tener un nombre estrictamente consecutivo (Ej. `Fase1_...`, `Fase2_...`, `Fase7_Control_Modulos.md`).
* **Ubicación Estricta:** Estos planes NO deben guardarse dentro de carpetas internas de los motores visuales. Deben guardarse en la raíz global del proyecto en la carpeta designada: `Talent 360/Planes_de_Implementacion`.
* **Proceso de Aprobación:** Antigravity NO escribirá especificaciones técnicas definitivas ni código de producción hasta que el usuario revise y apruebe el Plan de Implementación de la fase en turno.
* **Transición Obligatoria a Especificaciones Técnicas (NUEVA REGLA):** Siempre, sin excepción, después de que se implemente una Fase aprobada, la IA deberá actualizar inmediatamente la documentación general y el documento de **Especificaciones Técnicas** del módulo correspondiente para reflejar la arquitectura recién construida.

## 2. 🧪 Reglas del Entorno de Programación (Motor Visual)
* **Desarrollo Aislado:** Todo el desarrollo visual y la lógica de pruebas debe hacerse exclusivamente en el **Motor Visual** (Simulador en React/Vite) hasta que se decida "congelar" la pantalla. No se tocará el backend ni bases de datos reales durante esta fase.
* **Actualización Constante de Interfaz de Pruebas:** Cada vez que la IA inyecte una nueva función o módulo, tiene la obligación estricta de actualizar la **Bitácora de Implementación (Histórico)** y la sección de **Tutoriales de Prueba Interactivos** visibles en la pantalla principal del administrador en el Motor Visual.

## 3. ⏪ Sistema de Seguridad y Respaldo
* **Confirmación de Cambios Críticos:** Todas las funciones que alteren el flujo del simulador de forma destructiva o estructural (como el botón para deshacer los últimos cambios en la Matrix) deben contar con un sistema de confirmación (Ej. `prompt` de contraseña con NIP de administrador) para evitar accidentes.

## 4. 🛑 Lluvia de Ideas y Restricciones
* La IA nunca debe asumir un plan complejo por sí sola si hay ambigüedad; debe estructurar las opciones como un *Plan de Implementación*, explicar el impacto y esperar luz verde.
* Si el usuario da una instrucción que salta secuencias lógicas o rompe el flujo establecido, la IA debe detenerse, advertir al usuario sobre la inconsistencia, y proponer el camino seguro antes de programar nada.
