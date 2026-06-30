# Especificaciones Técnicas del Módulo: Motor Visual (Reloj Checador iOS)

Este documento detalla todas las características, funciones lógicas y estructuras que ya se han desarrollado y autorizado para el módulo del "Motor Visual" (El núcleo del Reloj Checador y Simulador Administrativo).

## 1. Arquitectura Base y Motor de Tiempo
- **Simulador de Jornadas:** Integración de un control deslizante ("Máquina del tiempo") que permite viajar a través de las horas del día simulado para probar la lógica en distintos escenarios de tiempo real.
- **Selector de Días:** Capacidad de avanzar entre los distintos días de la semana (Lunes a Domingo) afectando la matriz de evaluación y la simulación.
- **Perfiles Dinámicos en UI:** La aplicación celular simula la sesión local de un usuario. El usuario en pantalla se puede cambiar rápidamente desde el menú desplegable en la cabecera.

## 2. Sistema de Asistencia y Tolerancia (Amnistía)
- **Pase de Lista Matutino:** Cuando el Titular / Gerente abre la sucursal, se abre un modal de pase de lista. El gerente selecciona quién entró físicamente con él al local. 
- **Estado de "Perímetro":** Quien no entró con el gerente, entra en estado de "Perímetro" (esperando afuera) y debe solicitar permiso para entrar. Su tiempo de tolerancia sigue corriendo.
- **Amnistía Automática:** Si la tienda se abre tarde por culpa del titular, el reloj interno retrasa la "hora oficial" de todos, otorgando una amnistía de tolerancia para que el personal no tenga retardo por culpa de la demora en la apertura.

## 3. Evaluaciones 360 Forzosas
- **Gatillo de Evaluación:** Se implementó una verificación de salida. Si el usuario intenta hacer "Terminar Turno" y no ha realizado las evaluaciones 360 correspondientes, la app bloquea la salida y abre el motor de evaluación de forma obligatoria.
- **Control Global:** El administrador tiene un botón en el panel web para activar o desactivar este requerimiento de evaluación de forma global para el día en curso.

## 4. Matrices Administrativas Web
- **Resumen Integral de Jornada y Nómina:** Súper-Tabla interactiva que consolida el estado de asistencia de toda la semana para cada empleado.
  - Generación de insignias interactivas: *Puntual, Temprano, Retardo, Descanso, Falta*.
  - Indicador de Estado en Tiempo Real (Punto de color junto al nombre para mostrar Activo, Comiendo, Esperando, Inactivo).
  - Cálculo de días de descanso, saltando automáticamente las lógicas punitivas en los días asignados en el perfil de cada colaborador.
- **Control Global Administrativo:**
  - `Toggle Historial`: Muestra/Oculta la pestaña de historial en los celulares.
  - `Toggle Amnistía`: Apaga la lógica de protección por demora de apertura.
  - `Toggle Temas`: Permite forzar o bloquear el Modo Oscuro en los celulares de los usuarios.

## 5. Reportes Anónimos y UI Avanzada
- **Motor de Reportes:** Botón tipo bocina en la interfaz del celular que abre un formulario de reporte por "Inactividad", "Abandono de Área" o "Mala Conducta". 
- **Doble Vía de Anonimato:** El reporte garantiza anonimato al usuario que lo envía en su UI, pero el Panel de Administración Web intercepta el log e identifica al emisor original en tiempo real en la matriz del administrador.
- **Personalización:** Modos Claro/Oscuro integrados en el menú de engrane del dispositivo celular.

## 6. Módulo de Seguridad y Accesos (Llaves y Respaldo)
- **Círculo de Confianza y Delegación de Llaves:** Asignación de permisos individuales por perfil para poder abrir sucursales. Si el titular principal tiene su "Día de Descanso" programado para el día siguiente, el sistema bloquea su salida y le exige transferir las responsabilidades de llave a un empleado autorizado.
- **Sistema de Respaldo:** Botón de emergencia protegido por contraseña (PIN: 1234) que permite deshacer los últimos cambios en la UI para restablecer el estado simulado en caso de fallas durante las pruebas de QA.

## 7. Módulo de Reservación de Comidas (Aforo)
- **Reservación Inteligente:** Modal obligatorio para que los empleados seleccionen su horario de comida.
- **Control de Aforo y Roles:** El sistema valida que el aforo simultáneo permitido en comida no se exceda. Además, bloquea reservas que empalmen y dejen la tienda sin cobertura para roles únicos.

## 8. Arquitectura de Control Maestro y Feature Flags (Fase 7)
- **Tablero Maestro de Funciones:** Consolidación de todos los interruptores del sistema en un panel de "Feature Flags" clasificados por Niveles de Prioridad (Operación Crítica, Gestión Administrativa, y Experiencia de Usuario).
- **Aislamiento de "Modo App Nativa":** Capacidad de detectar el parámetro de URL `?mode=native`. Si está activo, el sistema oculta el entorno de la Matrix y renderiza el celular a pantalla completa. Esto habilita las pruebas físicas del simulador conectando dispositivos reales mediante la red local (`npm run dev -- --host`).
