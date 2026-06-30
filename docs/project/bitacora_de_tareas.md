# Bitácora de Tareas (Talent 360)

> **Regla de Prioridad:** Las tareas están ordenadas de arriba hacia abajo según el flujo lógico de **dependencias de programación**. No se puede avanzar a un módulo sin completar el anterior.

---

# FASE 0: EL LABORATORIO DE PRUEBAS (EP)
## PRIORIDAD 0: Configuración del Entorno de Programación Visual
- `[x]` Estructurar subcarpetas del EP (`Pruebas_UI_UX`, `Laboratorio_Backend`, `Mock_Data`)
- `[x]` Inicializar Sandbox de Frontend (Vite/React) como "Catálogo Visual de Plantillas"
- `[x]` **Prototipar Módulo 1: Reloj Checador (UI de Semáforos, Botones y Alarmas)**
- `[ ]` Prototipar Módulo 2: Wizard de Reclutamiento (Filtros de Inducción)
- `[ ]` Prototipar Módulo 3: Academia Interna (Capacitación y Ascensos PWA)
- `[ ]` Integrar plantillas prototipo para la "Web Pública" (Recetario, Catálogo, Cursos)
- `[x]` Crear script `Desplegar_Modulo_a_Tienda.bat` (El "Botón Mágico")

---

# FASE 1: FLUJO MAESTRO TIENDA DECORARTE (CORE SAAS)
## PRIORIDAD 1: Fundación y Seguridad (El Cerebro)
*(Dependencia cero. Requerido para que todo el sistema exista).*
- `[ ]` Inicialización de proyecto Laravel en `1_Core_SaaS/Backend_Laravel`
- `[ ]` Configuración de Base de Datos PostgreSQL
- `[ ]` Autenticación Headless (Sanctum), Tokens API y Roles Globales
- `[ ]` Arquitectura Multi-Tenant (Marca Blanca y Dominios Personalizados)
- `[ ]` **CMS Panel:** Desarrollo del "Publishing Engine" (Integración API Facebook/Instagram para difundir).

## PRIORIDAD 2: Módulo de Identidad (Reclutamiento y Puestos)
*(Depende de P1).*
- `[ ]` Tablas maestras: `Puestos` (Permanente) vs `Vacantes` (Temporal)
- `[ ]` Módulo de Alta de Empleados y Mutación de Identidad (`Postulaciones` -> `Empleados`)
- `[ ]` Embudo Automatizado: Filtro de Cursos de Inducción (Fase 1 y 2) con regla de "Segunda Oportunidad".

## PRIORIDAD 3: Módulo de Control de Asistencia (Reloj Checador Inteligente)
*(Depende de P2).*
- `[ ]` Backend: CRON Job de Madrugada y asignación de horarios de apertura.
- `[ ]` Integración de API de Push Notifications (Firebase) para alarmas preventivas (1 hr antes, Comidas).
- `[ ]` PWA: UI/UX con Sistema de Semáforos (Azul, Verde, Ámbar, Rojo).
- `[ ]` Lógica de "Pre-Apertura", "Sala de Espera Virtual" y "Fichaje Masivo Automático".
- `[ ]` Reglas de Contingencia: Amnistía General, Modo Kiosco y validación de doble llave (Selfie + Aprobación).
- `[ ]` Panel de Supervisor: Kill-Switch remoto y Reporte de Auditoría Anónima.

## PRIORIDAD 4: Módulo Operativo de Tareas (Tienda)
*(Depende de P3).*
- `[ ]` Esquema `JSONB` para Asistentes Dinámicos y Máquina de Estados
- `[ ]` Checklists de operación diaria amarrados al rol del empleado.

## PRIORIDAD 5: Módulo de Compensaciones (Nómina y LFT)
*(Depende de P3 y P4).*
- `[ ]` Motor de cálculo de horas reales vs horas teóricas (Retardos, Turnos Huérfanos).
- `[ ]` Motor de Bonos de Puntualidad y Gratificaciones por Tareas.
- `[ ]` Auditoría Legal (LFT) mediante Asistente IA con filtro de Aprobación Humana.

---

# FASE 2: MARKETING Y ACADEMIA
## PRIORIDAD 6: Hub de Marketing y Plataformas Visuales (Web Pública)
*(Lee datos de P1 y P2. Puede desarrollarse en paralelo).*
- `[ ]` Sub-Módulo: Catálogo de Productos (Conexión API o DB local)
- `[ ]` Sub-Módulo: Recetario Dinámico (Motor Matemático por porciones y volumen de moldes)
- `[ ]` Vitrinas (Cursos, Vacantes, Servicios) y Contenido Educativo (Tips, Contacto).
- `[ ]` **Omnicanalidad:** Integración de Widgets de Share (WhatsApp, Telegram, Copiar Link) en todos los módulos.
- `[ ]` Integrar Agentes IA de Soporte (Web) y Ventas (WhatsApp)

## PRIORIDAD 7: Flujo Academia Talent360 (PWA Estudiantes)
- `[ ]` Pasarela de Pagos e Inscripciones (Cursos, Talleres).
- `[ ]` PWA Estudiantes: Rutas de Aprendizaje, Videoteca y Gamificación.
- `[ ]` Herramienta Financiera: Calculadora de Costeo de Recetas para Alumnos.
- `[ ]` Salón de Trofeos: Autogeneración de Certificados PDF.

## PRIORIDAD 8: Academia Interna (Capacitación Corporativa)
- `[ ]` Módulo de Inducciones (Cursos filtro para Reclutamiento).
- `[ ]` Plan de Carrera: Cursos para ascenso de puesto (Vinculado a Puestos de Trabajo).
