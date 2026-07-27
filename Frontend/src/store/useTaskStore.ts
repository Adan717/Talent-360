import { create } from 'zustand';
import { useAppStore } from './useAppStore'; // Para interactuar con Matrix events
import axiosInstance from '../lib/axios';

export type TaskPriority = 'normal' | 'bloqueante';
export type AssignMode = 'equitativo' | 'checklist' | 'horario_fijo' | 'bolsa_trabajo';
export type AssistantType = 'ninguno' | 'evidencia_foto' | 'captura_numero' | 'firma' | 'texto';

export interface SubTask {
    id: number;
    text: string;
    completed: boolean;
}

export interface ProcedureStep {
    step_number: number;
    title: string;
    detailed_instruction: string;
    verification_required: boolean;
}

export interface Task {
    id: number;
    title: string;
    description?: string;
    estimatedMins: number;
    priority: TaskPriority;
    
    // Nivel de Especialidad y Clasificación
    category: 'operativo' | 'administrativo' | 'mantenimiento' | 'supervision';
    targetType: 'role' | 'user' | 'pool' | 'department';
    targetId?: string | number;
    points?: number;

    subTasks: SubTask[];
    procedureSteps?: ProcedureStep[];
    assistantType: AssistantType;
    assistantPrompt?: string; // Ej: "¿Cuántas mermas hubo?"
    isAutoCapture: boolean;
    historicalMins: number[]; // Para calcular el promedio de autocaptura
    validationMode?: 'forced' | 'auto' | 'dynamic' | 'ai_comparison';
    canBeDoneSitting?: boolean;
    scheduledTime?: string | null;

    // Nuevas propiedades ricas alineadas con Obsidian
    frequency?: string;
    evidenceType?: string;
    validationCriteria?: string[];
    is_validated?: boolean;

    // §38: vínculo con una lección de la Academia (cada "lección" es una fila de
    // academy_courses, que es donde vive video_url — no hay tabla de lecciones aparte).
    academyLessonId?: number | null;
    academyLessonVideoUrl?: string | null;

    // §35: modo de validación "Comparación (IA)" — solo tiene sentido si
    // assistantType === 'evidencia_foto'. El admin sube 3-5 imágenes de referencia +
    // una descripción de tolerancia al configurar la tarea; el backend usa Gemini para
    // comparar la evidencia del empleado contra esas referencias en POST /task-assignments/{id}/ai-validate.
    aiComparisonEnabled?: boolean;
    aiReferenceImages?: string[]; // data URLs base64, 3-5
    aiToleranceDescription?: string | null;
}

export interface Routine {
    id: number;
    title: string;
    targetRoleId: number; // Ej: 6 para 'Ayudante Integral'
    assignMode: AssignMode;
    trigger: 'on_checkin' | 'scheduled';
    triggerTime?: string; // Si es scheduled
    taskIds: number[];
}

export interface TaskAssignment {
    id: string; // Unique assignment ID
    taskId: number;
    userId: number | null; // Null significa que está en la Bolsa de Trabajo
    status: 'pending' | 'in_progress' | 'paused' | 'completed' | 'spilled' | 'awaiting_validation' | 'omitted';
    startedAtMins: number | null;
    expectedEndTimeMins?: number | null;
    completedAtMins: number | null;
    assignedFromRoutineId?: number;
    assistantData?: any; // La foto o el número capturado
    validationFeedback?: string | null;
    accumulatedMins?: number;
    reservedAtMins?: number | null;
    warned80Percent?: boolean;
    // Fecha (YYYY-MM-DD) a la que pertenece la asignación. Opcional mientras el backend
    // termina de poblarla (ver docs/BACKEND_INTERFACES.md §14) — el frontend debe tolerar
    // que venga undefined en registros antiguos y no debe romper si falta.
    date?: string;
    // Puntos realmente otorgados al completar (columna points_awarded, ya existe en BD
    // pero aún no se puebla desde el backend). Cuando no venga, se usa task.points como fallback.
    pointsAwarded?: number;
    // §40: origen de la asignación para el reporte de cierre del plan de trabajo diario.
    // 'planned' = agregada en la ventana matutina (armar plan de hoy), 'carried_over' =
    // pendiente de un día anterior que se retomó hoy, 'extra' = agregada fuera de esa
    // ventana (creación ad-hoc durante el día), 'routine' = generada por una rutina.
    origin?: 'planned' | 'carried_over' | 'extra' | 'routine';
    // §35: resultado guardado por POST /task-assignments/{id}/ai-validate, para que el
    // supervisor pueda auditar después por qué la IA aprobó/rechazó una entrega.
    aiValidationResult?: { match: boolean; confidence?: number; reasoning?: string } | null;
    // §62 + resync: marca de INTENCIÓN EXPLÍCITA de que userId sea null (bolsa de trabajo:
    // release, rebote, rutina fija, tarea dinámica). Sin este flag, el backend interpreta el
    // null como estado a medio hidratar y conserva al dueño previo (candado anti-pérdida).
    ownerCleared?: boolean;
}

interface TaskStoreState {
    tasks: Task[];
    routines: Routine[];
    assignments: TaskAssignment[];
    
    // Setters para inicialización desde Backend
    setTasks: (tasks: Task[]) => void;
    setRoutines: (routines: Routine[]) => void;
    setAssignments: (assignments: TaskAssignment[]) => void;
    syncToBackend: (includeCatalog?: boolean) => Promise<void>;
    syncAssignmentRow: (assignmentId: string) => Promise<void>;

    // Acciones Admin
    addTask: (task: Task) => void;
    updateTask: (task: Task) => void;
    addRoutine: (routine: Routine) => void;
    updateRoutine: (routine: Routine) => void;
    
    // Acciones Operativas
    triggerCheckInRoutines: (userId: number, roleId: number, currentSimTime: number) => void;
    /** Rutinas de HORARIO FIJO (trigger='scheduled' + triggerTime). Ver nota en la implementación. */
    triggerScheduledRoutines: (currentSimTime: number, user: any) => void;
    grabTaskFromPool: (assignmentId: string, userId: number, currentSimTime: number) => void;
    reserveTaskFromPool: (assignmentId: string, userId: number, currentSimTime: number) => void;
    releaseTask: (assignmentId: string) => void;
    startTask: (assignmentId: string, currentSimTime: number) => void;
    pauseTask: (assignmentId: string) => void;
    completeTask: (assignmentId: string, currentSimTime: number, assistantData?: any) => void;
    validateTaskAssignment: (assignmentId: string, status: 'completed' | 'in_progress', feedback?: string) => Promise<void>;
    handleSpillOver: (userId: number, roleId: number) => void;
    createDynamicTask: (title: string, roleTarget: any, estimatedMins?: number, priority?: TaskPriority, origin?: TaskAssignment['origin']) => void; // On-the-fly
    omitAssignment: (assignmentId: string, reason?: string) => void;
    // §40: retoma una asignación pendiente de un día anterior y la re-fecha a hoy con
    // origin: 'carried_over', para que el reporte de cierre la cuente como parte del plan
    // del día en curso en vez de perderse en el día en que quedó pendiente.
    carryOverAssignment: (assignmentId: string, newDate: string) => void;
}

export const useTaskStore = create<TaskStoreState>((set, get) => ({
    tasks: [],
    routines: [],
    assignments: [],

    setTasks: (tasks) => set({ tasks }),
    setRoutines: (routines) => set({ routines }),
    setAssignments: (assignments) => set({ assignments }),

    // §31 (docs/BACKEND_INTERFACES.md): antes se mandaban SIEMPRE `tasks`/`routines` completos
    // (el catálogo entero), incluso en acciones puramente operativas (completar/pausar/tomar de la
    // bolsa una asignación propia). Eso no solo era ineficiente (§33) — además hacía imposible que el
    // backend distinguiera "esto es una edición real del catálogo" de "esto es un empleado operando su
    // propia tarea", que es justo lo que necesita para poder exigir rol admin/supervisor solo cuando
    // corresponde. `includeCatalog` (default false) controla si se incluyen `tasks`/`routines` en el
    // payload: solo las 4 acciones de administración (addTask/updateTask/addRoutine/updateRoutine) lo
    // pasan en true; las acciones operativas siguen mandando `assignments` como siempre.
    syncToBackend: async (includeCatalog: boolean = false) => {
        try {
            const appState = useAppStore.getState();
            if (appState.isSandboxMode) return; // Guardado en memoria RAM solamente

            // ─────────────────────────────────────────────────────────────────────────────
            // §62 (auditoría en vivo 2026-07-26) — CANDADOS CONTRA PÉRDIDA DE DATOS.
            // Este método manda `assignments` COMPLETO y el backend lo interpreta como la
            // verdad absoluta: lo que no venga en el arreglo, lo borra. En producción eso ya
            // destruyó las 5 asignaciones del día de una colaboradora al iniciar UNA tarea,
            // porque en ese instante el estado local estaba a medio hidratar (se vio que su
            // puesto aparecía como "Colaborador" en vez de "Atención Al Cliente") — muy
            // probablemente tras uno de los 502 intermitentes de /sync/state (§45/§46).
            //
            // OJO: estos candados reducen la probabilidad, NO cierran el agujero. La
            // protección de fondo va en el servidor (§62 punto 1: una lista vacía o parcial
            // nunca debe significar "borra lo que no venga"; un borrado debe ser explícito).
            // ─────────────────────────────────────────────────────────────────────────────

            // Candado 1: sesión a medio cargar. Si aún no sabemos con certeza quién es el
            // usuario, cualquier estado derivado (incluido el filtrado de asignaciones por
            // puesto) es poco confiable y no debe escribirse.
            const user = appState.currentUser;
            if (!user || user.role === 'Loading' || appState.isLoadingDB) {
                console.warn('[§62] Sincronización de tareas abortada: la sesión todavía no está hidratada.');
                return;
            }

            const state = get();

            // Candado 2: nunca mandar un arreglo vacío. Un vacío legítimo no existe en este
            // flujo — si de verdad hay que borrar algo, va por su endpoint explícito. Enviarlo
            // solo puede significar "todavía no cargué", y es exactamente lo que borra el día
            // entero de un empleado.
            if (state.assignments.length === 0) {
                console.warn('[§62] Sincronización de tareas abortada: el arreglo de asignaciones está vacío (estado sin cargar).');
                return;
            }

            const payload: any = { assignments: state.assignments };
            if (includeCatalog) {
                payload.tasks = state.tasks;
                payload.routines = state.routines;
            }
            await axiosInstance.post('/sync/tasks', payload);
        } catch (e) {
            console.error("Failed to sync tasks to backend:", e);
            alert("Error al guardar tareas. Verifique que el servidor backend esté encendido.");
        }
    },

    // §33 punto 3: sincroniza solo la fila que cambió vía PUT /task-assignments/{id}, en vez
    // de reenviar el arreglo completo de assignments por cada clic operativo (tomar de la
    // bolsa, iniciar, pausar, completar, liberar, omitir). El backend ya porta la misma lógica
    // de recálculo de puntos/validación que antes solo vivía en POST /sync/tasks (§33 punto 1).
    syncAssignmentRow: async (assignmentId) => {
        try {
            const appState = useAppStore.getState();
            if (appState.isSandboxMode) return;
            // §62: mismo criterio que en syncToBackend — no escribir con la sesión a medias.
            // Aquí el riesgo es menor (se manda UNA fila, no puede borrar las demás), pero una
            // fila armada sobre estado incompleto igual puede guardar datos equivocados.
            if (!appState.currentUser || appState.currentUser.role === 'Loading' || appState.isLoadingDB) {
                console.warn('[§62] Escritura de asignación abortada: la sesión todavía no está hidratada.');
                return;
            }
            const assignment = get().assignments.find(a => a.id === assignmentId);
            if (!assignment) return;
            await axiosInstance.put(`/task-assignments/${assignmentId}`, assignment);
        } catch (e) {
            console.error("Failed to sync assignment row to backend:", e);
            alert("Error al guardar la tarea. Verifique que el servidor backend esté encendido.");
        }
    },

    addTask: (task) => { set(state => ({ tasks: [...state.tasks, task] })); get().syncToBackend(true); },
    updateTask: (task) => { set(state => ({ tasks: state.tasks.map(t => t.id === task.id ? task : t) })); get().syncToBackend(true); },
    addRoutine: (routine) => { set(state => ({ routines: [...state.routines, routine] })); get().syncToBackend(true); },
    updateRoutine: (routine) => { set(state => ({ routines: state.routines.map(r => r.id === routine.id ? routine : r) })); get().syncToBackend(true); },

    triggerCheckInRoutines: (userId, roleId, currentSimTime) => {
        const { routines, tasks, assignments } = get();
        // 1. Buscar rutinas que calcen con el rol y sean 'on_checkin'
        const matchingRoutines = routines.filter(r => r.targetRoleId === roleId && r.trigger === 'on_checkin');
        
        let newAssignments: TaskAssignment[] = [];
        
        matchingRoutines.forEach(routine => {
            // Revisar si ya se asignaron hoy (simplificado para el sandbox)
            const alreadyAssigned = assignments.some(a => a.assignedFromRoutineId === routine.id && a.userId === userId);
            if (alreadyAssigned) return;

            if (routine.assignMode === 'checklist') {
                // Clonación: Cada usuario recibe TODAS las tareas
                routine.taskIds.forEach(tId => {
                    newAssignments.push({
                        id: `chk_${userId}_${routine.id}_${tId}_${Date.now()}`,
                        taskId: tId, userId, status: 'pending', startedAtMins: null, completedAtMins: null, assignedFromRoutineId: routine.id, origin: 'routine'
                    });
                });
            } else if (routine.assignMode === 'equitativo') {
                // Reparto Equitativo (simplificado: por ahora se las asignamos directo, si llega otro ayudante después, la lógica avanzada robaría tareas pendientes)
                // Para el MVP del sandbox: Se le asignan todas si es el primero, si es el segundo, solo toma la mitad de las no iniciadas.
                routine.taskIds.forEach(tId => {
                    // Check if someone else already has this exact task assignment pending
                    const existingEq = assignments.find(a => a.assignedFromRoutineId === routine.id && a.taskId === tId);
                    if (!existingEq) {
                        newAssignments.push({
                            id: `eq_${userId}_${routine.id}_${tId}_${Date.now()}`,
                            taskId: tId, userId, status: 'pending', startedAtMins: null, completedAtMins: null, assignedFromRoutineId: routine.id, origin: 'routine'
                        });
                    }
                });
            }
        });

        if (newAssignments.length > 0) {
            set(state => ({ assignments: [...state.assignments, ...newAssignments] }));
            get().syncToBackend();
            useAppStore.getState().addMatrixEvent(
                '🔄 Tareas Asignadas',
                `Se dispararon ${newAssignments.length} tareas automáticas para el usuario al hacer check-in.`,
                'success'
            );
        }
    },

    /**
     * Rutinas de HORARIO FIJO (merge FE). El panel de admin ya ofrecía "Horario Fijo / Programado"
     * y el backend guardaba `routines.trigger_time`, pero NADA las disparaba: el admin configuraba
     * "a las 14:00 asignar estas tareas", se guardaba bien... y no se asignaba nunca, en silencio.
     *
     * `user` es el usuario de ESTA instancia del reloj (overrideUser en el simulador, el usuario
     * global en producción) — NO se lee currentUser del store, para que en el simulador
     * multi-instancia cada empleado dispare su propio checklist.
     */
    triggerScheduledRoutines: (currentSimTime, user) => {
        if (!user) return;
        const { routines, assignments } = get();
        const clockStates = useAppStore.getState().globalClockStates || {};
        const iAmOnShift = ['active', 'meal'].includes(clockStates[user.id]);
        const dateKey = new Date().toLocaleDateString('sv-SE'); // YYYY-MM-DD

        const parseTimeToMins = (t: any): number | null => {
            if (!t || typeof t !== 'string') return null;
            const parts = t.split(':');
            const h = parseInt(parts[0], 10);
            const m = parseInt(parts[1], 10);
            if (isNaN(h) || isNaN(m)) return null;
            return h * 60 + m;
        };

        const newAssignments: TaskAssignment[] = [];

        routines.filter(r => r.trigger === 'scheduled').forEach(routine => {
            const triggerMins = parseTimeToMins((routine as any).triggerTime);
            if (triggerMins === null) return;         // sin hora válida → no dispara
            if (currentSimTime < triggerMins) return; // aún no es la hora

            if (routine.assignMode === 'checklist') {
                // Cada usuario en turno del rol crea SUS PROPIAS tareas.
                if (!iAmOnShift || Number(user.job_role_id) !== Number(routine.targetRoleId)) return;
                routine.taskIds.forEach(tId => {
                    // El id lleva la FECHA (no Date.now()): así la rutina dispara UNA vez al día
                    // por usuario y tarea, aunque el reloj re-evalúe cada minuto.
                    const schId = `sch_${routine.id}_${user.id}_${tId}_${dateKey}`;
                    if (assignments.some(a => a.id === schId)) return; // ya disparó hoy
                    newAssignments.push({
                        id: schId, taskId: tId, userId: user.id, status: 'pending',
                        startedAtMins: null, completedAtMins: null,
                        assignedFromRoutineId: routine.id, origin: 'routine'
                    } as TaskAssignment);
                });
            } else {
                // bolsa_trabajo y equitativo → a la Bolsa (una por tarea, id sin usuario).
                routine.taskIds.forEach(tId => {
                    const schId = `sch_${routine.id}_${tId}_${dateKey}`;
                    if (assignments.some(a => a.id === schId)) return; // ya en la bolsa hoy
                    newAssignments.push({
                        id: schId, taskId: tId, userId: null, ownerCleared: true, status: 'pending',
                        startedAtMins: null, completedAtMins: null,
                        assignedFromRoutineId: routine.id, origin: 'routine'
                    } as TaskAssignment);
                });
            }
        });

        if (newAssignments.length > 0) {
            set(state => ({ assignments: [...state.assignments, ...newAssignments] }));
            get().syncToBackend();
            useAppStore.getState().addMatrixEvent(
                '⏰ Rutina Programada',
                `Se dispararon ${newAssignments.length} tareas de una rutina de horario fijo.`,
                'info'
            );
        }
    },

    grabTaskFromPool: (assignmentId, userId, currentSimTime) => {
        set(state => {
            const assignment = state.assignments.find(a => a.id === assignmentId);
            const task = assignment ? state.tasks.find(t => t.id === assignment.taskId) : null;
            const expectedEndTimeMins = (currentSimTime && task) ? currentSimTime + task.estimatedMins : null;
            
            return {
                assignments: state.assignments.map(a =>
                    a.id === assignmentId ? { ...a, userId, status: 'in_progress', startedAtMins: currentSimTime, expectedEndTimeMins, accumulatedMins: a.accumulatedMins || 0, reservedAtMins: null } : a
                )
            };
        });
        get().syncAssignmentRow(assignmentId);
    },

    reserveTaskFromPool: (assignmentId, userId, currentSimTime) => {
        set(state => ({
            assignments: state.assignments.map(a =>
                a.id === assignmentId ? { ...a, userId, status: 'pending', reservedAtMins: currentSimTime } : a
            )
        }));
        get().syncAssignmentRow(assignmentId);

        const assignment = get().assignments.find(a => a.id === assignmentId);
        const task = assignment ? get().tasks.find(t => t.id === assignment.taskId) : null;
        useAppStore.getState().addMatrixEvent(
            '⏳ Tarea Reservada',
            `Se reservó la tarea "${task?.title || 'de la Bolsa'}" para comenzar pronto.`,
            'info'
        );
    },

    releaseTask: (assignmentId) => {
        set(state => ({
            assignments: state.assignments.map(a =>
                a.id === assignmentId ? { ...a, userId: null, ownerCleared: true, status: 'pending', reservedAtMins: null } : a
            )
        }));
        get().syncAssignmentRow(assignmentId);

        const assignment = get().assignments.find(a => a.id === assignmentId);
        const task = assignment ? get().tasks.find(t => t.id === assignment.taskId) : null;
        useAppStore.getState().addMatrixEvent(
            '🤝 Tarea Liberada',
            `La tarea "${task?.title || 'de la Bolsa'}" volvió a la Bolsa de Trabajo.`,
            'info'
        );
    },

    startTask: (assignmentId, currentSimTime) => {
        set(state => {
            const assignment = state.assignments.find(a => a.id === assignmentId);
            const task = assignment ? state.tasks.find(t => t.id === assignment.taskId) : null;
            const remainingMins = (task && assignment) ? (task.estimatedMins - (assignment.accumulatedMins || 0)) : 15;
            const expectedEndTimeMins = currentSimTime + remainingMins;
            
            return {
                assignments: state.assignments.map(a =>
                    a.id === assignmentId ? { ...a, status: 'in_progress', startedAtMins: currentSimTime, expectedEndTimeMins, reservedAtMins: null } : a
                )
            };
        });
        get().syncAssignmentRow(assignmentId);
    },

    pauseTask: (assignmentId) => {
        const { globalSimTime } = useAppStore.getState();
        set(state => ({
            assignments: state.assignments.map(a => {
                if (a.id === assignmentId && a.status === 'in_progress') {
                    const elapsed = a.startedAtMins ? (globalSimTime - a.startedAtMins) : 0;
                    const newAccumulated = (a.accumulatedMins || 0) + elapsed;
                    return { 
                        ...a, 
                        status: 'paused', 
                        accumulatedMins: newAccumulated, 
                        startedAtMins: null 
                    };
                }
                return a;
            })
        }));
        get().syncAssignmentRow(assignmentId);
    },

    completeTask: (assignmentId, currentSimTime, assistantData) => {
        const { tasks, assignments, updateTask } = get();
        const assignment = assignments.find(a => a.id === assignmentId);
        if (!assignment) return;

        const task = tasks.find(t => t.id === assignment.taskId);
        
        // Registrar tiempo si es autocaptura
        if (task && task.isAutoCapture && assignment.startedAtMins) {
            const timeTaken = currentSimTime - assignment.startedAtMins;
            if (timeTaken > 0) {
                const updatedTask = { ...task, historicalMins: [...task.historicalMins, timeTaken] };
                updateTask(updatedTask);
            }
        }

        // Verificar si requiere validación en el frontend de inmediato
        const systemSettings = useAppStore.getState().systemSettings;
        const currentUser = useAppStore.getState().currentUser;
        const tasksConfig = systemSettings?.tasksConfig;
        
        // Simular si el rol del usuario reporta a alguien
        // En el sandbox, todos los roles excepto Gerente (roleId 1) o Admin reportan a alguien
        const reportsTo = currentUser && currentUser.role !== 'admin' && currentUser.role !== 'platform_admin' && currentUser.role !== 'Gerente';

        let targetStatus: 'completed' | 'awaiting_validation' = 'completed';
        
        if (reportsTo && task) {
            const validationMode = task.validationMode || 'forced';
            
            if (validationMode === 'forced') {
                if (tasksConfig && tasksConfig.requireSupervisorValidation) {
                    const threshold = tasksConfig.validationThreshold || 'all_tasks';
                    if (threshold === 'all_tasks') {
                        targetStatus = 'awaiting_validation';
                    } else if (threshold === 'blockers_only' && task.priority === 'bloqueante') {
                        targetStatus = 'awaiting_validation';
                    }
                } else {
                    targetStatus = 'awaiting_validation';
                }
            } else if (validationMode === 'dynamic') {
                const hireDateStr = currentUser?.hire_date;
                let days = 0;
                if (hireDateStr) {
                    const hireDate = new Date(hireDateStr);
                    const diffTime = Math.abs(Date.now() - hireDate.getTime());
                    days = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                }
                
                if (days < 30) {
                    targetStatus = 'awaiting_validation';
                } else if (days < 90) {
                    targetStatus = Math.random() < 0.50 ? 'awaiting_validation' : 'completed';
                } else {
                    targetStatus = Math.random() < 0.15 ? 'awaiting_validation' : 'completed';
                }
            } else if (validationMode === 'auto') {
                targetStatus = 'completed';
            }
        }

        set(state => ({
            assignments: state.assignments.map(a =>
                a.id === assignmentId ? { ...a, status: targetStatus, completedAtMins: currentSimTime, assistantData, validationFeedback: null } : a
            )
        }));
        get().syncAssignmentRow(assignmentId);
    },

    validateTaskAssignment: async (assignmentId, status, feedback) => {
        try {
            const res = await axiosInstance.post(`/admin/assignments/${assignmentId}/validate`, {
                status,
                feedback
            });
            if (res.status === 200) {
                set(state => ({
                    assignments: state.assignments.map(a => 
                        a.id === assignmentId ? { 
                            ...a, 
                            status: status, 
                            validationFeedback: status === 'in_progress' ? feedback : null,
                            completedAtMins: status === 'in_progress' ? null : a.completedAtMins
                        } : a
                    )
                }));
                
                // Si se rechaza, añadir evento en Matrix
                if (status === 'in_progress') {
                    useAppStore.getState().addMatrixEvent(
                        'Tarea Rechazada',
                        `El supervisor rechazó una tarea y la regresó a En Progreso. Motivo: ${feedback}`,
                        'warning'
                    );
                } else {
                    useAppStore.getState().addMatrixEvent(
                        'Tarea Aprobada',
                        `El supervisor validó y completó la tarea.`,
                        'success'
                    );
                }
            }
        } catch (e) {
            console.error("Error validating task assignment", e);
        }
    },

    handleSpillOver: (userId, roleId) => {
        const { assignments, tasks } = get();
        // Buscar tareas pendientes o en progreso del usuario
        let spiltCount = 0;
        let transferredCount = 0;

        // Antes esto era [1, 2, 3, 4].includes(roleId) — una lista de IDs de puesto fija que
        // asumía la estructura de un solo tenant. Generalizado: un puesto cuenta como "jefe"
        // (sus bloqueantes se transfieren, no caen a la bolsa) si algún otro puesto le reporta
        // — mismo criterio de jerarquía que ya usa TaskRunner.tsx (mySubordinateRoles) — en vez
        // de adivinar por número, así funciona igual sin importar cómo cada empresa numere sus puestos.
        const globalRolesForSpillOver = useAppStore.getState().globalRoles || [];
        const isManagementRole = globalRolesForSpillOver.some((r: any) =>
            Number(r.reports_to_role_id) === Number(roleId) ||
            (Array.isArray(r.reports_to_role_ids) && r.reports_to_role_ids.map(Number).includes(Number(roleId)))
        );

        const newAssignments = assignments.map(a => {
            if (a.userId !== userId || !['pending', 'in_progress', 'paused'].includes(a.status)) return a;

            const task = tasks.find(t => t.id === a.taskId);
            if (!task) return a;

            if (isManagementRole && task.priority === 'bloqueante') {
                // Tareas críticas de jefes NO se van a la bolsa. Se transfieren (Simulado aquí como 'spilled' para que las rescate el suplente)
                transferredCount++;
                return { ...a, status: 'spilled', userId: null, ownerCleared: true } as TaskAssignment; // Requires specific rescue logic
            } else {
                // Tareas normales o de ayudantes caen a la Bolsa de Trabajo (userId: null)
                spiltCount++;
                return { ...a, userId: null, ownerCleared: true, status: 'pending' } as TaskAssignment; // Rebotan a la bolsa
            }
        });

        set({ assignments: newAssignments });
        get().syncToBackend();

        if (spiltCount > 0) {
            useAppStore.getState().addMatrixEvent(
                '🚨 Spill-over Activado',
                `${spiltCount} tareas de un usuario inactivo cayeron a la Bolsa de Trabajo.`,
                'warning'
            );
        }
    },

    createDynamicTask: (title, roleTarget, estimatedMins = 15, priority = 'normal', origin = 'extra') => {
        const newTask: Task = {
            id: Date.now(),
            title,
            estimatedMins,
            priority,
            category: 'operativo',
            targetType: 'role',
            targetId: roleTarget,
            subTasks: [],
            assistantType: 'ninguno',
            isAutoCapture: false,
            historicalMins: []
        };

        const todayStr = new Date().toLocaleDateString('sv-SE');
        set(state => ({
            tasks: [...state.tasks, newTask],
            assignments: [...state.assignments, {
                id: `dyn_${newTask.id}`,
                taskId: newTask.id,
                userId: null, ownerCleared: true, // Cae directo a la bolsa
                status: 'pending',
                startedAtMins: null,
                completedAtMins: null,
                date: todayStr,
                origin
            }]
        }));
        // Crea una Task nueva de verdad (no solo una asignación) — necesita ir en el catálogo.
        get().syncToBackend(true);

        useAppStore.getState().addMatrixEvent(
            '⚡ Tarea On-the-fly',
            `Un supervisor ha lanzado la tarea "${title}" a la Bolsa de Trabajo.`,
            'info'
        );
    },

    carryOverAssignment: (assignmentId, newDate) => {
        set(state => ({
            assignments: state.assignments.map(a =>
                a.id === assignmentId ? { ...a, date: newDate, origin: 'carried_over' } : a
            )
        }));
        get().syncAssignmentRow(assignmentId);
    },

    omitAssignment: (assignmentId, reason) => {
        set(state => ({
            assignments: state.assignments.map(a =>
                a.id === assignmentId ? { ...a, status: 'omitted', validationFeedback: reason || null } : a
            )
        }));
        get().syncAssignmentRow(assignmentId);

        const { tasks, assignments } = get();
        const assignment = assignments.find(a => a.id === assignmentId);
        const task = assignment ? tasks.find(t => t.id === assignment.taskId) : null;

        useAppStore.getState().addMatrixEvent(
            '❌ Tarea Omitida',
            reason ? `Se omitió "${task?.title || 'la tarea'}". Motivo: ${reason}` : `Se omitió la tarea asignada.`,
            'warning'
        );

        // Aviso real al supervisor (hallazgo de la auditoría de Tareas, 2026-07-22): antes esto solo
        // dejaba rastro en el timeline de la Matrix, que no existe fuera del Simulador — en producción
        // nadie se enteraba de una tarea omitida salvo que abriera la lista y notara el badge. Sigue el
        // mismo patrón de compatibilidad hacia adelante que /clock/door-notice: si el endpoint aún no
        // existe del lado backend, no se trata como error visible — la omisión local ya se aplicó.
        if (!useAppStore.getState().isSandboxMode && assignment) {
            axiosInstance.post(`/task-assignments/${assignmentId}/omit`, { reason: reason || null })
                .catch(e => console.warn('omit-notify endpoint no disponible aún (ver BACKEND_INTERFACES.md):', e));
        }
    }
}));
