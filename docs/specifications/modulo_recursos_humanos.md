# ESPECIFICACIONES TÉCNICAS: MÓDULO DE RECLUTAMIENTO Y VACANTES

**Objetivo:** Automatizar el embudo de contratación (Funnel) desde la publicación de la vacante hasta la contratación formal, aplicando filtros de inducción virtuales para ahorrar tiempo al departamento de Recursos Humanos.

## 1. Publicación y Difusión de Vacantes
*   **Vitrina Pública:** Las vacantes disponibles se listan en el sitio web público.
*   **Viralidad:** Cada vacante debe tener botones de compartir (WhatsApp, Facebook, Instagram, Google).
*   **CMS (Administrador):** Desde el panel de administración, Recursos Humanos puede editar las vacantes y disparar el botón de compartir directamente a las redes sociales de Talent360 para mayor alcance.

## 2. El Embudo del Candidato (Wizard de Postulación)
Cuando un candidato le da clic a "Postularme", no se le manda a una entrevista física inmediatamente. Entra a un embudo virtual:

*   **Paso 1: Captura de Datos:** El candidato sube su información básica y de contacto.
*   **Paso 2: Inducción Fase 1 (Virtual):** 
    *   **Validación de Video:** Se le reproduce un video corporativo. El sistema debe detectar que el candidato vio el video de inducción completo. No se habilita el botón de "Continuar" ni se permite saltar hacia el examen hasta que el reproductor de video alcance el 100%.
    *   **Motor de Evaluación (Quizzes):** Realiza un Quiz Dinámico compuesto por:
        1. Opción Múltiple: Preguntas de retención de teoría básica.
        2. Preguntas Abiertas/Retóricas: Para medir actitudes, lógica y sentido común operativo.

*   **Paso 3: Evaluación del Filtro y Segunda Oportunidad (Empatía Operativa):**
    *   *Manejo de Fallos:* Si el candidato no alcanza el puntaje mínimo en el examen teórico, el sistema **NO** lo descartará ni lo bloqueará de forma automática. El sistema le sugerirá amablemente volver a tomar el curso de inducción y repetir el examen. 
    *   *Flujo de RH:* El perfil del candidato reprobado quedará marcado en el panel de RH con un tag amarillo `[Requiere Revisión Manual]`.
    *   *Justificación de Negocio:* Esta regla previene que Talent360 pierda candidatos con alta destreza manual u operativa que puedan tener dificultades cognitivas o técnicas con exámenes de retención teórica en pantalla. Recursos Humanos puede evaluar estas habilidades blandas/manuales posteriormente en una entrevista presencial.
    *   *Si aprueba:* El candidato avanza libremente.

## 3. Fase de Entrevista
*   **Pre-requisito:** Antes de llegar a la entrevista presencial, el candidato ya debió cursar la **"Inducción Fase 2"** desde su casa.
*   **Entrevista:** Se realiza una evaluación completa basándose en los resultados de los filtros anteriores.

## 4. Contratación
*   Al ser contratado y dado de alta en la base de datos, el sistema le **desbloquea su acceso a la PWA (Academia Interna)**.
*   A partir de aquí, el empleado puede empezar a tomar cursos desde su casa o en la tienda (fuera de horario laboral) para buscar ascensos y ganar medallas digitales.

## 5. Catálogo Oficial de Puestos y Permisos (Integración HR)
El módulo de Reclutamiento es la "Fuente de la Verdad" para todos los perfiles de colaboradores. De aquí maman otros módulos como el *Reloj Checador*.

### 5.1. Plantilla Operativa Base

| Puesto | Horario | Horas Netas | Comida | Día de Descanso Base |
|---|---|---|---|---|
| **Administrador / Gerente** | 08:20 - 18:00 | 8h 40m | 60 min | Domingo |
| **Supervisor de Tienda** | 08:20 - 18:00 | 8h 40m | 60 min | Lunes |
| **Supervisor de Cajas** | 08:20 - 18:00 | 8h 40m | 60 min | Martes |
| **Sup. de Compras y Producción** | 09:00 - 18:00 | 8h 00m | 60 min | Miércoles |
| **Cajeros** | 08:30 - 17:00 | 8h 00m | 30 min | Variable |
| **Ayudante Integral** | Variable | 8h 00m | 30 min | Variable |

### 5.2. Interfaz Maestra (CollaboratorProfile)
Todos los sistemas conectados deben leer este objeto para aplicar reglas de negocio dinámicamente sin hardcodear configuraciones:

\`\`\`typescript
interface CollaboratorProfile {
  id: number;
  nombre: string;
  puesto: string;
  area: string;
  
  // -- VARIABLES DE JORNADA --
  horaEntrada: string;
  horaSalida: string;
  minutosComida: number;
  diaDescanso: string;
  
  // -- SWITCHES DE PERMISOS (Asignables desde la Matrix HR) --
  esAperturador: boolean;         // Permiso para iniciar operaciones de sucursal
  jerarquiaLlaves: number;        // Prelación de Failsafe (1 = Admin, 2 = Sup. Tienda, 3 = Sup. Cajas)
  tiempoTolerancia: number;       // Minutos de gracia estándar (Ej. 10 mins)
  requiereJustificante: boolean;  // Bloqueo duro en retardos
  puedeEmitirAvisos: boolean;     // Permite Broadcast masivo en día de descanso
  aplicaLeySilla: boolean;        // Habilita el módulo de descansos de Ley Silla
  evaluacion360Activa: boolean;   // Sometido a evaluación obligatoria al finalizar turno
}
\`\`\`
