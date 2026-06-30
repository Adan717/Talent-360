# Flujo General del Sistema (Ecosistema Talent 360)

Este documento describe cómo se mueve un usuario y la información a través de la arquitectura Multi-Tenant (SaaS) de Marca Blanca.

## 1. El Cerebro Central (Core SaaS)
Todo nace en el backend (Laravel). Funciona como una máquina que dicta reglas. Gestiona el Multi-Tenant (Dominios Personalizados) y aloja los Motores Inteligentes: LFT, Motor Matemático de Recetas, Motor de Nóminas y el **Motor de Difusión (Publishing Engine)** para redes sociales.

## 2. Flujo Maestro de Reclutamiento y Operación (Tienda)
Este es el "Embudo de Contratación" de 5 fases:
1. **Postulación (Web Pública):** El usuario ve la Vacante, la comparte por WhatsApp, llena el Wizard y sube sus datos.
2. **Inducción Filtro (Fase 1):** El candidato es forzado a tomar un mini-curso automático (Filtro por video completo).
3. **Inducción Técnica (Fase 2):** Curso 2 técnico. Si reprueba, no se le bloquea, pasa a revisión manual por RH.
4. **Entrevista Presencial/Virtual:** RRHH lo evalúa.
5. **Contratación y Mutación:** Su identidad "muta" a Empleado. Se le asigna un **Puesto** oficial.
6. **Academia Interna (Capacitación Continua):** Acceso a cursos operativos y de ascenso.

## 3. Flujo del Hub de Marketing (Web Pública)
La Web Pública no guarda datos; es una "Vitrina" (Headless) que jala información del Core SaaS. Todos sus elementos cuentan con **Widgets de Difusión Orgánica** (Botón Compartir a WhatsApp, Telegram, SMS):
- Catálogo de Productos y Servicios (Compartibles).
- Recetario Dinámico (Compartibles).
- Vitrina de Vacantes y Cursos (Compartibles).
- Agentes de IA (Bot Web y WhatsApp).

## 4. Flujo del Gestor de Contenido (CMS Administrativo)
El panel de control (donde tú administras todo) tendrá un módulo de **Omnicanalidad**:
- Al crear una nueva Vacante, Curso o Producto, el CMS tendrá la capacidad de hacer un "Push" (Difusión) directo hacia Facebook, Instagram (API) o Google.
- Esto convierte al CMS en un hub centralizado de mercadotecnia.

## 5. Flujo Maestro de la Academia (Educación Privada - Externa)
1. **Inscripción:** El alumno externo ve el curso público y paga.
2. **Onboarding Educativo:** Recibe su kit de bienvenida y acceso a la PWA de Estudiantes.
3. **Estudio y Herramientas:** Consume las lecciones y utiliza la **Calculadora de Costeos**.
4. **Certificación y Cross-Selling:** Se gradúa y el sistema le sugiere insumos de la Tienda Talent360.
