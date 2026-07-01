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
    assistantType: AssistantType;
    assistantPrompt?: string; // Ej: "¿Cuántas mermas hubo?"
    isAutoCapture: boolean;
    historicalMins: number[]; // Para calcular el promedio de autocaptura
    validationMode?: 'forced' | 'auto' | 'dynamic';
    canBeDoneSitting?: boolean;
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
}

interface TaskStoreState {
    tasks: Task[];
    routines: Routine[];
    assignments: TaskAssignment[];
    
    // Setters para inicialización desde Backend
    setTasks: (tasks: Task[]) => void;
    setRoutines: (routines: Routine[]) => void;
    setAssignments: (assignments: TaskAssignment[]) => void;
    syncToBackend: () => Promise<void>;

    // Acciones Admin
    addTask: (task: Task) => void;
    updateTask: (task: Task) => void;
    addRoutine: (routine: Routine) => void;
    updateRoutine: (routine: Routine) => void;
    
    // Acciones Operativas
    triggerCheckInRoutines: (userId: number, roleId: number, currentSimTime: number) => void;
    grabTaskFromPool: (assignmentId: string, userId: number, currentSimTime: number) => void;
    reserveTaskFromPool: (assignmentId: string, userId: number, currentSimTime: number) => void;
    releaseTask: (assignmentId: string) => void;
    startTask: (assignmentId: string, currentSimTime: number) => void;
    pauseTask: (assignmentId: string) => void;
    completeTask: (assignmentId: string, currentSimTime: number, assistantData?: any) => void;
    validateTaskAssignment: (assignmentId: string, status: 'completed' | 'in_progress', feedback?: string) => Promise<void>;
    handleSpillOver: (userId: number, roleId: number) => void;
    createDynamicTask: (title: string, roleTarget: any, estimatedMins?: number, priority?: TaskPriority) => void; // On-the-fly
    omitAssignment: (assignmentId: string) => void;
}

export const useTaskStore = create<TaskStoreState>((set, get) => ({
    tasks: [],
    routines: [],
    assignments: [],

    setTasks: (tasks) => set({ tasks }),
    setRoutines: (routines) => set({ routines }),
    setAssignments: (assignments) => set({ assignments }),

    syncToBackend: async () => {
        try {
            if (useAppStore.getState().isSandboxMode) return; // Guardado en memoria RAM solamente
            
            const state = get();
            await axiosInstance.post('/sync/tasks', {
                tasks: state.tasks,
                routines: state.routines,
                assignments: state.assignments
            });
        } catch (e) {
            console.error("Failed to sync tasks to backend:", e);
            alert("Error al guardar tareas. Verifique que el servidor backend esté encendido.");
        }
    },

    addTask: (task) => { set(state => ({ tasks: [...state.tasks, task] })); get().syncToBackend(); },
    updateTask: (task) => { set(state => ({ tasks: state.tasks.map(t => t.id === task.id ? task : t) })); get().syncToBackend(); },
    addRoutine: (routine) => { set(state => ({ routines: [...state.routines, routine] })); get().syncToBackend(); },
    updateRoutine: (routine) => { set(state => ({ routines: state.routines.map(r => r.id === routine.id ? routine : r) })); get().syncToBackend(); },

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
                        taskId: tId, userId, status: 'pending', startedAtMins: null, completedAtMins: null, assignedFromRoutineId: routine.id
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
                            taskId: tId, userId, status: 'pending', startedAtMins: null, completedAtMins: null, assignedFromRoutineId: routine.id
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
        get().syncToBackend();
    },

    reserveTaskFromPool: (assignmentId, userId, currentSimTime) => {
        set(state => ({
            assignments: state.assignments.map(a => 
                a.id === assignmentId ? { ...a, userId, status: 'pending', reservedAtMins: currentSimTime } : a
            )
        }));
        get().syncToBackend();
        
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
                a.id === assignmentId ? { ...a, userId: null, status: 'pending', reservedAtMins: null } : a
            )
        }));
        get().syncToBackend();

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
        get().syncToBackend();
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
        get().syncToBackend();
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
        get().syncToBackend();
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

        const newAssignments = assignments.map(a => {
            if (a.userId !== userId || !['pending', 'in_progress', 'paused'].includes(a.status)) return a;
            
            const task = tasks.find(t => t.id === a.taskId);
            if (!task) return a;

            if ([1, 2, 3, 4].includes(roleId) && task.priority === 'bloqueante') {
                // Tareas críticas de jefes NO se van a la bolsa. Se transfieren (Simulado aquí como 'spilled' para que las rescate el suplente)
                transferredCount++;
                return { ...a, status: 'spilled', userId: null } as TaskAssignment; // Requires specific rescue logic
            } else {
                // Tareas normales o de ayudantes caen a la Bolsa de Trabajo (userId: null)
                spiltCount++;
                return { ...a, userId: null, status: 'pending' } as TaskAssignment; // Rebotan a la bolsa
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

    createDynamicTask: (title, roleTarget, estimatedMins = 15, priority = 'normal') => {
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
        
        set(state => ({ 
            tasks: [...state.tasks, newTask],
            assignments: [...state.assignments, {
                id: `dyn_${newTask.id}`,
                taskId: newTask.id,
                userId: null, // Cae directo a la bolsa
                status: 'pending',
                startedAtMins: null,
                completedAtMins: null
            }]
        }));
        get().syncToBackend();

        useAppStore.getState().addMatrixEvent(
            '⚡ Tarea On-the-fly',
            `Un supervisor ha lanzado la tarea "${title}" a la Bolsa de Trabajo.`,
            'info'
        );
    },

    omitAssignment: (assignmentId) => {
        set(state => ({
            assignments: state.assignments.map(a => 
                a.id === assignmentId ? { ...a, status: 'omitted' } : a
            )
        }));
        get().syncToBackend();
        
        useAppStore.getState().addMatrixEvent(
            '❌ Tarea Omitida',
            `Se omitió la tarea asignada.`,
            'warning'
        );
    }
}));
