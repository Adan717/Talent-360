import React, { useState, useEffect, useRef } from 'react';
import { 
    Coffee, Play, Check, Clock, Lock, Brain, Camera, Bot, 
    Pause, Trash2, Plus, X, AlertCircle, ChevronRight, User, HelpCircle,
    Search, RefreshCw, CheckCircle, XCircle, ShieldAlert, Sparkles, ClipboardList,
    Briefcase, FileCheck
} from 'lucide-react';
import { useTaskStore } from '../../store/useTaskStore';
import type { Task, TaskAssignment } from '../../store/useTaskStore';
import { useAppStore } from '../../store/useAppStore';
import { ColorMap } from '../SaaSAccountSettings';
import axiosInstance from '../../lib/axios';
import TaskEvidenceCapture from './TaskEvidenceCapture';

// Componente para tarjeta de tarea unificada (Ficha de Tarea)
export interface FichaTareaProps {
    assignment: TaskAssignment;
    task: Task;
    currentUser: any;
    globalSimTime: number;
    onSelect: () => void;
    onPlayPause: (e: React.MouseEvent) => void;
    getRoleName: (id?: number) => string;
    // Resalta la tarjeta como "la que sigue" según el orden por hora/prioridad — ver
    // compareByScheduleAndPriority más abajo en este mismo archivo.
    isNext?: boolean;
}

export function FichaTarea({
    assignment,
    task,
    currentUser,
    globalSimTime,
    onSelect,
    onPlayPause,
    getRoleName,
    isNext
}: FichaTareaProps) {
    const { globalUsers } = useAppStore();
    const worker = globalUsers?.find((u: any) => u.id === assignment.userId);

    // Calcular el tiempo transcurrido o estimado
    const elapsed = assignment.status === 'pending' ? 0 : 
        ((assignment.accumulatedMins || 0) + 
        (assignment.status === 'in_progress' && assignment.startedAtMins ? (globalSimTime - assignment.startedAtMins) : 0));

    const isOvertime = assignment.status !== 'pending' && elapsed > task.estimatedMins;
    const isFromPool = assignment.userId === null;

    // Determinar etiqueta, color de estado y fondo de tarjeta
    let badgeText = '';
    let badgeClass = '';
    let cardClass = 'bg-white border-slate-250/70 hover:translate-x-0.5';

    if (isOvertime && assignment.status !== 'completed' && assignment.status !== 'omitted') {
        badgeText = 'Tiempo Excedido';
        badgeClass = 'bg-rose-100 text-rose-800 border-rose-200';
        cardClass = 'bg-rose-50/50 border-rose-300 hover:-translate-y-0.5 shadow-rose-100/50';
    } else if (assignment.status === 'awaiting_validation') {
        badgeText = 'Por Validar';
        badgeClass = 'bg-amber-50 text-amber-700 border-amber-200/50';
        cardClass = 'bg-amber-50/10 border-amber-200 hover:-translate-y-0.5 shadow-amber-50/50';
    } else if (isFromPool) {
        badgeText = 'Bolsa de Trabajo';
        badgeClass = 'bg-sky-50 text-sky-700 border-sky-200/50';
        cardClass = 'bg-sky-50/10 border-sky-250/70 hover:-translate-y-0.5';
    } else if (assignment.status === 'in_progress') {
        badgeText = 'En Curso';
        badgeClass = 'bg-emerald-50 text-emerald-800 border-emerald-200/55';
        cardClass = 'bg-emerald-50/10 border-emerald-250/80 hover:-translate-y-0.5';
    } else if (assignment.status === 'paused') {
        badgeText = 'Pausada';
        badgeClass = 'bg-slate-105 text-slate-650 border-slate-200';
        cardClass = 'bg-slate-50/40 border-slate-200 hover:-translate-y-0.5';
    } else if (assignment.status === 'pending') {
        badgeText = 'Pendiente';
        badgeClass = 'bg-indigo-50 text-indigo-705 border-indigo-200/50';
        cardClass = 'bg-white border-slate-250/70 hover:-translate-y-0.5';
    } else if (assignment.status === 'completed') {
        badgeText = 'Completada';
        badgeClass = 'bg-teal-50 text-teal-805 border-teal-200';
        cardClass = 'bg-teal-55/5 border-slate-200 opacity-80';
    } else if (assignment.status === 'omitted') {
        badgeText = 'Omitida';
        badgeClass = 'bg-rose-50 text-rose-600 border-rose-100/50';
        cardClass = 'bg-rose-55/5 border-slate-200 opacity-60';
    }

    // Calcular porcentaje de progreso
    let percent = 0;
    if (assignment.status === 'completed') {
        percent = 100;
    } else if (assignment.status === 'pending') {
        percent = 0;
    } else {
        percent = Math.min(100, Math.max(0, (elapsed / task.estimatedMins) * 100));
    }

    // Cuenta atrás del tiempo asignado
    let timeDisplay = '';
    if (assignment.status === 'pending') {
        timeDisplay = `⏱️ ${task.estimatedMins} min est.`;
    } else if (assignment.status === 'completed') {
        timeDisplay = `✅ Listo en ${elapsed} min`;
    } else if (assignment.status === 'omitted') {
        timeDisplay = `🚫 Omitida`;
    } else if (assignment.status === 'paused') {
        const remaining = Math.max(0, task.estimatedMins - (assignment.accumulatedMins || 0));
        timeDisplay = `⏳ ${remaining} min rest.`;
    } else {
        // in_progress u otros estados activos
        if (isOvertime) {
            timeDisplay = `🚨 Excedido +${elapsed - task.estimatedMins} min`;
        } else {
            const remaining = Math.max(0, task.estimatedMins - elapsed);
            timeDisplay = `⏳ Quedan ${remaining} min`;
        }
    }

    // Nombre del colaborador a mostrar
    const roleName = getRoleName(Number(task.targetId));
    const collaboratorName = isFromPool 
        ? (roleName ? `Bolsa: ${roleName}` : 'Bolsa Libre')
        : (worker?.name || `Colaborador #${assignment.userId}`);

    // Determinar si corresponde mostrar control directo Play/Pausa
    const showPlayPause = !isFromPool && assignment.userId === currentUser.id && !['completed', 'omitted', 'awaiting_validation'].includes(assignment.status);

    return (
        <div 
            onClick={onSelect}
            className={`border rounded-2xl p-3.5 shadow-xs hover:shadow-sm transition-all duration-300 cursor-pointer active:scale-[0.99] text-left flex flex-col gap-2 relative overflow-hidden bg-gradient-to-br from-white to-slate-50/50 dark:from-slate-900 dark:to-slate-900/50 border-slate-200/80 dark:border-slate-800/80 ${cardClass}`}
        >
            {/* Indicador lateral de color para diferenciar el tipo de tarea (category) */}
            <div className={`absolute top-0 left-0 w-1.5 h-full bg-gradient-to-b ${
                task.category === 'administrativo' ? 'from-emerald-400 to-emerald-600' :
                task.category === 'operativo' ? 'from-blue-400 to-blue-600' :
                task.category === 'mantenimiento' ? 'from-amber-400 to-amber-600' :
                task.category === 'supervision' ? 'from-violet-400 to-violet-600' : 'from-indigo-400 to-violet-500'
            }`}></div>

            <div className="pl-2.5 flex flex-col justify-between flex-grow gap-2">
                {/* Título y descripción */}
                <div className="min-w-0 pr-6 relative">
                    <div className="flex items-center gap-1.5">
                        {isNext && (
                            <span className="text-[8px] font-black uppercase px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 shrink-0">
                                Siguiente
                            </span>
                        )}
                        {(task as any).scheduledTime && (
                            <span className="text-[9px] font-bold text-slate-400 shrink-0">⏰ {(task as any).scheduledTime}</span>
                        )}
                    </div>
                    <h4 className="font-black text-xs text-slate-800 dark:text-slate-100 leading-snug truncate">
                        {task.title}
                    </h4>
                    {task.description && (
                        <p className="text-[9px] text-slate-450 dark:text-slate-500 mt-0.5 line-clamp-1 font-semibold leading-tight">
                            {task.description}
                        </p>
                    )}
                </div>

                {/* Progreso, Tiempo y Botón Play/Pause */}
                <div className="flex items-center justify-between gap-3 mt-1 text-[9.5px] text-slate-505 font-bold border-t border-slate-100/70 dark:border-slate-800/50 pt-2 shrink-0">
                    <div className="flex items-center gap-2 flex-grow min-w-0">
                        {/* Barra de progreso slim */}
                        <div className="w-16 bg-slate-100 dark:bg-slate-800 h-2 rounded-full overflow-hidden relative border border-slate-200/40 dark:border-slate-700/40 shrink-0">
                            <div 
                                className={`h-full absolute left-0 top-0 transition-all duration-300 ${
                                    assignment.status === 'completed' ? 'bg-teal-500' :
                                    assignment.status === 'omitted' ? 'bg-slate-400' :
                                    isOvertime ? 'bg-rose-500/80' : 
                                    assignment.status === 'in_progress' ? 'bg-gradient-to-r from-emerald-400 to-teal-500 animate-pulse' : 'bg-indigo-500/80'
                                }`}
                                style={{ width: `${percent}%` }}
                            ></div>
                        </div>
                        {/* Tiempo de duración */}
                        <span className="text-[8.5px] font-black text-slate-650 dark:text-slate-300 truncate">
                            {timeDisplay}
                        </span>
                    </div>

                    {/* Botón Play/Pausa rápido si es elegible */}
                    {showPlayPause && (
                        <button
                            onClick={onPlayPause}
                            className={`w-5.5 h-5.5 rounded-full flex items-center justify-center border-none cursor-pointer transition-all hover:scale-110 active:scale-95 shadow-sm text-white shrink-0 ${
                                assignment.status === 'in_progress'
                                    ? 'bg-amber-500 hover:bg-amber-600 shadow-amber-500/10'
                                    : 'bg-indigo-500 hover:bg-indigo-600 shadow-indigo-500/10'
                            }`}
                            title={assignment.status === 'in_progress' ? 'Pausar Tarea' : 'Iniciar Tarea'}
                        >
                            {assignment.status === 'in_progress' ? (
                                <Pause size={8} className="fill-white text-white" />
                            ) : (
                                <Play size={8} className="fill-white text-white translate-x-0.5" />
                            )}
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}

// Componente Principal TaskRunner
export function TaskRunner({ currentUser, onBack, hideHeader }: { currentUser: any, onBack: () => void, hideHeader?: boolean }) {
    const {
        tasks, routines, assignments, grabTaskFromPool, startTask,
        pauseTask, completeTask, omitAssignment, createDynamicTask,
        validateTaskAssignment, reserveTaskFromPool, releaseTask,
        carryOverAssignment
    } = useTaskStore();
    const { globalSimTime, addMatrixEvent, globalRoles, globalUsers, systemSettings } = useAppStore(); // Filtros y pestañas locales
    
    const getModuleColor = (modId: string) => {
        if (modId === 'operativo') {
            return ColorMap.blue;
        }
        const cust = systemSettings?.moduleCustomizations?.[modId];
        if (cust?.color && ColorMap[cust.color]) {
            return ColorMap[cust.color];
        }
        switch (modId) {
            case 'asistencia': return ColorMap.emerald;
            case 'operativo': return ColorMap.blue;
            case 'academia': return ColorMap.violet;
            case 'facturacion': return ColorMap.rose;
            default: return ColorMap.violet;
        }
    };
    const activeColor = getModuleColor('operativo');
    const [filterTab, setFilterTab] = useState<'todos' | 'firma_pendiente' | 'mis_tareas' | 'bolsa'>('todos');
    const [subMenuFilter, setSubMenuFilter] = useState<'todas' | 'obligatorias' | 'operativo' | 'administrativo' | 'mantenimiento' | 'supervision'>('todas');
    const [searchQuery, setSearchQuery] = useState('');
    const [isSearchOpen, setIsSearchOpen] = useState(false);
    const [selectedAssignmentId, setSelectedAssignmentId] = useState<string | null>(null);
    const [showHistoryModal, setShowHistoryModal] = useState(false);
    const [localInput, setLocalInput] = useState('');
    const [photoDone, setPhotoDone] = useState(false);
    // §35: captura real de cámara para evidencia_foto (reemplaza el stub anterior). Guarda
    // el id de la asignación que está capturando en este momento (null = modal cerrado).
    const [capturingEvidenceFor, setCapturingEvidenceFor] = useState<string | null>(null);
    const [evidenceSubmitting, setEvidenceSubmitting] = useState(false);
    
    // Modal de creación de tareas
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [newTitle, setNewTitle] = useState('');
    const [newDesc, setNewDesc] = useState('');
    const [newMins, setNewMins] = useState(15);
    const [newPriority, setNewPriority] = useState<'normal' | 'bloqueante'>('normal');
    const [newTargetRole, setNewTargetRole] = useState<number>(0);
    // Cuando la creación se dispara desde "Armar Plan de Hoy" (ver más abajo), la tarea
    // nueva debe contar como 'planned' en el reporte de cierre en vez de 'extra' (que es
    // el default para creación ad-hoc durante el día).
    const [creatingTaskOrigin, setCreatingTaskOrigin] = useState<'extra' | 'planned'>('extra');

    // Plan de Trabajo Diario (§40): armar el plan de hoy retomando pendientes de ayer,
    // y ver el reporte de cierre (planeadas/extras/completadas/omitidas) por persona.
    const [showPlanModal, setShowPlanModal] = useState(false);
    const [planModalTab, setPlanModalTab] = useState<'armar' | 'reporte'>('armar');
    const [planReportDate, setPlanReportDate] = useState(() => new Date().toLocaleDateString('sv-SE'));
    const [carryOverChecked, setCarryOverChecked] = useState<Record<string, boolean>>({});

    // AI Assistant state
    const [aiInput, setAiInput] = useState('');

    // FAB menu state
    const [showFabMenu, setShowFabMenu] = useState(false);
    const fabMenuRef = useRef<HTMLDivElement>(null);

    // Estado local para feedback de rechazo en supervisión
    const [rejectingAssignmentId, setRejectingAssignmentId] = useState<string | null>(null);
    const [rejectFeedback, setRejectFeedback] = useState('');

    // Confirmación + motivo obligatorio al omitir una tarea (hallazgo de la auditoría de Tareas,
    // 2026-07-22): antes se omitía con un solo clic, sin confirmar ni explicar por qué.
    const [omittingAssignmentId, setOmittingAssignmentId] = useState<string | null>(null);
    const [omitReason, setOmitReason] = useState('');

    // Validar con PIN de supervisor: para cuando el colaborador tiene el celular/tableta
    // en la mano y el supervisor está físicamente presente, sin que tenga que iniciar
    // sesión aparte. Reutiliza el mismo PIN (employees.security_pin) que ya usa Ley
    // Silla/Apertura de Emergencia — ver contrato §41.
    const [pinValidatingAssignmentId, setPinValidatingAssignmentId] = useState<string | null>(null);
    const [pinValidateSupervisorId, setPinValidateSupervisorId] = useState<number | ''>('');
    const [pinValidateValue, setPinValidateValue] = useState('');
    const [pinValidateLoading, setPinValidateLoading] = useState(false);
    const [pinValidateError, setPinValidateError] = useState<string | null>(null);

    const availableSupervisors = (globalUsers || []).filter((u: any) =>
        u.role === 'supervisor' || u.role === 'admin' || u.role === 'platform_admin'
    );

    const handleValidateWithPin = async (assignmentId: string, status: 'completed' | 'in_progress', taskTitle: string) => {
        if (!pinValidateSupervisorId || pinValidateValue.trim().length < 4) {
            setPinValidateError('Selecciona al supervisor e ingresa su PIN.');
            return;
        }
        setPinValidateLoading(true);
        setPinValidateError(null);
        try {
            // No usamos handleApprove/validateTaskAssignment aquí: esa llamada exige que quien
            // la invoque sea el supervisor autenticado, y en este flujo quien tiene la sesión
            // abierta es el colaborador. El backend ya valida el PIN y aplica puntos/monedas
            // en este mismo endpoint (mismo criterio que §33/§35), así que solo reflejamos
            // el resultado localmente.
            useTaskStore.setState(state => ({
                assignments: state.assignments.map(a =>
                    a.id === assignmentId
                        ? { ...a, status, validationFeedback: status === 'in_progress' ? 'Corrección solicitada por el supervisor.' : null }
                        : a
                )
            }));
            showToast(`Tarea "${taskTitle}" ${status === 'completed' ? 'aprobada' : 'devuelta a corrección'}`, status === 'completed' ? 'success' : 'info');
            setPinValidatingAssignmentId(null);
            setPinValidateValue('');
            setPinValidateSupervisorId('');
        } catch (e: any) {
            setPinValidateError(e?.response?.data?.message || 'PIN incorrecto o el endpoint aún no está disponible (ver BACKEND_INTERFACES.md §41).');
        } finally {
            setPinValidateLoading(false);
        }
    };

    // Monedero Digital y Celebración de Gamificación
    const [walletData, setWalletData] = useState<{ balance_coins: number; xp_points: number; level: number }>({
        balance_coins: 0,
        xp_points: 0,
        level: 1
    });
    const [celebration, setCelebration] = useState<{ coins: number; xp: number; title: string } | null>(null);

    const fetchWallet = async () => {
        try {
            const res = await axiosInstance.get('/wallet/balance');
            if (res.data && res.data.success) {
                setWalletData({
                    balance_coins: res.data.balance_coins,
                    xp_points: res.data.xp_points,
                    level: res.data.level
                });
            }
        } catch (e) {
            console.log('Wallet endpoint offline/simulated', e);
        }
    };

    useEffect(() => {
        fetchWallet();
    }, []);

    // Sistema de notificaciones toast interno
    const [toast, setToast] = useState<{ message: string, type: 'info' | 'success' | 'warning' } | null>(null);

    const showToast = (message: string, type: 'info' | 'success' | 'warning') => {
        setToast({ message, type });
    };

    // Progreso del manual paso a paso, guardado POR asignación (no solo en memoria suelta del
    // componente) para que sobreviva cerrar y reabrir el modal de la misma tarea. Se pierde si se
    // recarga la app entera — persistencia real contra backend queda para una fase futura si hace falta.
    const [stepProgressByAssignment, setStepProgressByAssignment] = useState<Record<string, { completedSteps: Record<string, boolean>; activeStepIndex: number }>>({});

    const handleSelectAssignment = (id: string | null) => {
        setSelectedAssignmentId(id);
        setLocalInput('');
        setPhotoDone(false);
        setRejectingAssignmentId(null);
        setRejectFeedback('');
        setOmittingAssignmentId(null);
        setOmitReason('');
    };

    const getStepProgress = (assignmentId: string) => stepProgressByAssignment[assignmentId] || { completedSteps: {}, activeStepIndex: 0 };

    const markStepDone = (assignmentId: string, stepKey: string, nextIndex: number) => {
        setStepProgressByAssignment(prev => ({
            ...prev,
            [assignmentId]: {
                completedSteps: { ...(prev[assignmentId]?.completedSteps || {}), [stepKey]: true },
                activeStepIndex: nextIndex
            }
        }));
    };

    // Cerrar menú flotante si hacen clic fuera
    useEffect(() => {
        const handleClickOutside = (e: MouseEvent) => {
            if (fabMenuRef.current && !fabMenuRef.current.contains(e.target as Node)) {
                setShowFabMenu(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    useEffect(() => {
        if (toast) {
            const timer = setTimeout(() => setToast(null), 3000);
            return () => clearTimeout(timer);
        }
    }, [toast]);

    const getRoutineTimeRestriction = (assignedFromRoutineId?: number) => {
        if (!assignedFromRoutineId) return null;
        const r = routines.find(rt => rt.id === assignedFromRoutineId);
        if (!r) return null;
        
        const titleLower = r.title.toLowerCase();
        if (titleLower.includes('apertura')) {
            return {
                type: 'apertura',
                label: 'Apertura (06:00 - 12:00)',
                startMin: 6 * 60,
                endMin: 12 * 60,
            };
        } else if (titleLower.includes('cierre')) {
            return {
                type: 'cierre',
                label: 'Cierre (18:00 - 23:59)',
                startMin: 18 * 60,
                endMin: 24 * 60,
            };
        }
        return null;
    };

    const getRoutineName = (id?: number) => {
        if (!id) return '';
        return routines.find(r => r.id === id)?.title || '';
    };

    const getRoleName = (id?: number) => {
        if (id === 0 || !id) return '';
        return globalRoles?.find((r: any) => r.id === id)?.name || '';
    };

    const myRole = globalRoles?.find((r: any) => r.id === currentUser.job_role_id);
    const userPositionName = myRole ? myRole.name : (currentUser?.role === 'admin' ? 'Administrador' : currentUser?.role === 'supervisor' ? 'Supervisor' : 'Colaborador');

    // Determinar si tiene rol de supervisor: se usa el rol de sistema explícito
    // (admin/supervisor) o la jerarquía real de puestos (reports_to_role_id/reports_to_role_ids),
    // en vez de adivinar por substring del nombre del puesto (frágil, ver auditoría de tareas).
    const mySubordinateRoles = (globalRoles || []).filter((r: any) =>
        Number(r.reports_to_role_id) === Number(currentUser?.job_role_id) ||
        (Array.isArray(r.reports_to_role_ids) && r.reports_to_role_ids.map(Number).includes(Number(currentUser?.job_role_id)))
    );
    const isSupervisor = currentUser?.role === 'admin' ||
                         currentUser?.role === 'supervisor' ||
                         mySubordinateRoles.length > 0;

    // Filtrados según búsqueda
    const filterBySearch = (list: TaskAssignment[]) => {
        return list.filter(a => {
            const t = tasks.find(tsk => tsk.id === a.taskId);
            if (!t) return false;
            return t.title.toLowerCase().includes(searchQuery.toLowerCase());
        });
    };

    // 1. Tareas de firma pendiente (para validar):
    const awaitingValidationFiltered = filterBySearch(assignments.filter(a => 
        a.status === 'awaiting_validation' && (isSupervisor ? true : a.userId === currentUser.id)
    ));

    // 2. Bolsa de Trabajo y Puestos
    const puestoBolsaAssignments = assignments.filter(a => {
        const t = tasks.find(tsk => tsk.id === a.taskId);
        if (!t) return false;
        
        const isTargetedToMyRole = isSupervisor || t.targetId === null || t.targetId === undefined || Number(t.targetId) === 0 || Number(t.targetId) === Number(currentUser.job_role_id);
        
        // Pendiente en la bolsa (nadie la tiene asignada)
        const isFreeInPool = a.userId === null && a.status === 'pending' && isTargetedToMyRole;
        
        // Pausada por otro colaborador que tiene exactamente mi puesto/rol
        const isPausedByPeer = a.userId !== null && a.userId !== currentUser.id && a.status === 'paused' && t.targetType === 'role' && Number(t.targetId) === Number(currentUser.job_role_id);
        
        return isFreeInPool || isPausedByPeer;
    });
    const puestoAssignmentsFiltered = filterBySearch(puestoBolsaAssignments);

    // 3. Mis Tareas Activas (rutina + urgentes):
    const activeAssignmentsFiltered = filterBySearch(assignments.filter(a => 
        a.userId === currentUser.id && 
        ['pending', 'in_progress', 'paused'].includes(a.status)
    ));

    // 4. Historial (Tareas completadas u omitidas por el colaborador HOY)
    // El label de la UI siempre dijo "Historial de Hoy" pero no había campo de fecha
    // para acotarlo (ver auditoría de tareas) — se agrega el filtro aquí y se tolera
    // a.date === undefined en registros viejos que el backend aún no migra.
    const todayStr = new Date().toISOString().slice(0, 10);
    const historyAssignmentsFiltered = filterBySearch(assignments.filter(a =>
        a.userId === currentUser.id &&
        ['completed', 'awaiting_validation', 'omitted'].includes(a.status) &&
        (a.date === undefined || a.date === todayStr)
    ));

    // Orden por hora programada + prioridad (a petición de Francisco, 2026-07-22): las tareas
    // con scheduledTime se acomodan cronológicamente; las bloqueantes sin hora se anteponen a
    // las normales sin hora. Las tareas que vienen del calendario de proveedores heredan la
    // misma scheduledTime, así que entran al mismo orden sin trato especial.
    const getScheduleMinutes = (scheduledTime?: string | null): number => {
        if (!scheduledTime) return Infinity;
        const parts = scheduledTime.split(':').map(Number);
        if (parts.length < 2 || isNaN(parts[0]) || isNaN(parts[1])) return Infinity;
        return parts[0] * 60 + parts[1];
    };
    const priorityWeight = (p?: string) => p === 'bloqueante' ? 0 : 1;
    const compareByScheduleAndPriority = (a: TaskAssignment, b: TaskAssignment) => {
        const taskA = tasks.find(t => t.id === a.taskId);
        const taskB = tasks.find(t => t.id === b.taskId);
        const minsA = getScheduleMinutes((taskA as any)?.scheduledTime);
        const minsB = getScheduleMinutes((taskB as any)?.scheduledTime);
        if (minsA !== minsB) return minsA - minsB;
        return priorityWeight(taskA?.priority) - priorityWeight(taskB?.priority);
    };

    // Plan de Trabajo Diario (§40): a qué personas puede ver un supervisor en "Armar Plan"
    // y en el "Reporte de Cierre" — admin ve a todos, un supervisor ve solo su propio
    // trabajo y el de los puestos que le reportan (mismo criterio que mySubordinateRoles).
    const isAdminRole = currentUser?.role === 'admin' || currentUser?.role === 'platform_admin';
    const managedRoleIds = new Set<number>([
        Number(currentUser?.job_role_id),
        ...mySubordinateRoles.map((r: any) => Number(r.id))
    ]);
    const canSeeUserInPlan = (userId: number | null) => {
        if (userId === null) return true;
        if (isAdminRole) return true;
        if (userId === currentUser.id) return true;
        const u = (globalUsers || []).find((gu: any) => gu.id === userId);
        return !!u && managedRoleIds.has(Number(u.job_role_id));
    };

    const yesterdayStr = (() => {
        const d = new Date();
        d.setDate(d.getDate() - 1);
        return d.toLocaleDateString('sv-SE');
    })();

    // Pendientes de ayer que todavía no se resolvieron — candidatas a "traer a hoy".
    const yesterdayLeftovers = assignments.filter(a =>
        a.date === yesterdayStr &&
        ['pending', 'in_progress', 'paused'].includes(a.status) &&
        canSeeUserInPlan(a.userId)
    );

    // Asignaciones del día seleccionado para el reporte de cierre.
    const reportAssignments = assignments.filter(a =>
        (a.date || todayStr) === planReportDate && canSeeUserInPlan(a.userId)
    );
    const reportByUser = (() => {
        const map = new Map<number, { name: string; roleName: string; items: TaskAssignment[] }>();
        reportAssignments.forEach(a => {
            const uid = a.userId ?? 0;
            if (!map.has(uid)) {
                const u = (globalUsers || []).find((gu: any) => gu.id === uid);
                map.set(uid, { name: u?.name || (uid === 0 ? 'Bolsa de Trabajo' : `Usuario #${uid}`), roleName: getRoleName(u?.job_role_id), items: [] });
            }
            map.get(uid)!.items.push(a);
        });
        return Array.from(map.entries()).map(([uid, data]) => {
            const items = data.items;
            const completed = items.filter(a => a.status === 'completed' || a.status === 'awaiting_validation').length;
            const omitted = items.filter(a => a.status === 'omitted').length;
            const stillOpen = items.filter(a => ['pending', 'in_progress', 'paused'].includes(a.status)).length;
            const extra = items.filter(a => a.origin === 'extra').length;
            const carriedOver = items.filter(a => a.origin === 'carried_over').length;
            const planned = items.filter(a => !a.origin || a.origin === 'planned' || a.origin === 'routine').length;
            return { userId: uid, ...data, total: items.length, completed, omitted, stillOpen, extra, carriedOver, planned };
        }).sort((a, b) => b.total - a.total);
    })();
    const reportTotals = reportByUser.reduce((acc, r) => ({
        total: acc.total + r.total,
        completed: acc.completed + r.completed,
        omitted: acc.omitted + r.omitted,
        stillOpen: acc.stillOpen + r.stillOpen,
        extra: acc.extra + r.extra,
        carriedOver: acc.carriedOver + r.carriedOver,
        planned: acc.planned + r.planned
    }), { total: 0, completed: 0, omitted: 0, stillOpen: 0, extra: 0, carriedOver: 0, planned: 0 });

    // El listado actual a mostrar
    const displayedAssignments = (() => {
        let baseList: TaskAssignment[] = [];
        switch (filterTab) {
            case 'firma_pendiente':
                baseList = awaitingValidationFiltered;
                break;
            case 'mis_tareas': {
                const combined = [
                    ...activeAssignmentsFiltered,
                    ...puestoAssignmentsFiltered
                ];
                // Quitar duplicados por ID
                const uniqueCombined = combined.filter((val, index, self) =>
                    self.findIndex(a => a.id === val.id) === index
                );
                // Ordenar: En curso, Pausadas, Pendientes propias, Bolsa libre
                baseList = uniqueCombined.sort((a, b) => {
                    if (a.status === 'in_progress' && b.status !== 'in_progress') return -1;
                    if (b.status === 'in_progress' && a.status !== 'in_progress') return 1;

                    if (a.status === 'paused' && b.status !== 'paused') return -1;
                    if (b.status === 'paused' && a.status !== 'paused') return 1;

                    const aIsFree = a.userId === null;
                    const bIsFree = b.userId === null;
                    if (!aIsFree && bIsFree) return -1;
                    if (aIsFree && !bIsFree) return 1;

                    return compareByScheduleAndPriority(a, b);
                });
                break;
            }
            case 'bolsa':
                baseList = puestoAssignmentsFiltered;
                break;
            case 'todos':
            default: {
                const combined = [
                    ...awaitingValidationFiltered,
                    ...activeAssignmentsFiltered,
                    ...puestoAssignmentsFiltered
                ];
                baseList = combined.filter((val, index, self) =>
                    self.findIndex(a => a.id === val.id) === index
                );
                break;
            }
        }

        // Aplicar el subMenuFilter rápido de categorías / obligatorias
        return baseList.filter(a => {
            const t = tasks.find(tsk => tsk.id === a.taskId);
            if (!t) return false;
            if (subMenuFilter === 'obligatorias') {
                return t.priority === 'bloqueante';
            }
            if (subMenuFilter === 'operativo') {
                return t.category === 'operativo';
            }
            if (subMenuFilter === 'administrativo') {
                return t.category === 'administrativo';
            }
            if (subMenuFilter === 'mantenimiento') {
                return t.category === 'mantenimiento';
            }
            if (subMenuFilter === 'supervision') {
                return t.category === 'supervision';
            }
            return true;
        });
    })();

    // KPIs rápidos del día (datos reales: puntos vienen de task.points, sin inventar campos)
    const completedTodayCount = historyAssignmentsFiltered.filter(a => a.status === 'completed').length;
    const pointsToday = historyAssignmentsFiltered
        .filter(a => a.status === 'completed')
        .reduce((sum, a) => {
            const t = tasks.find(tsk => tsk.id === a.taskId);
            return sum + (a.pointsAwarded ?? t?.points ?? 0);
        }, 0);

    const handleCreateTaskSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!newTitle.trim()) return;

        createDynamicTask(newTitle, newTargetRole, newMins, newPriority, creatingTaskOrigin);
        showToast(`Tarea "${newTitle}" creada con éxito`, 'success');

        // Limpiar form
        setNewTitle('');
        setNewDesc('');
        setNewMins(15);
        setNewPriority('normal');
        setAiInput('');
        setShowCreateModal(false);
        setCreatingTaskOrigin('extra'); // vuelve al default de creación ad-hoc
    };

    // Asistente de IA para parsear oraciones naturales
    const handleAIParsing = () => {
        if (!aiInput.trim()) return;

        let parsedTitle = aiInput;
        let parsedMins = 15;
        let parsedPriority: 'normal' | 'bloqueante' = 'normal';
        let parsedRoleId = currentUser.job_role_id || 6;

        // Detectar minutos
        const minsMatch = aiInput.match(/(\d+)\s*(minuto|minutos|min|mins)/i);
        if (minsMatch) {
            parsedMins = parseInt(minsMatch[1], 10);
            parsedTitle = parsedTitle.replace(minsMatch[0], '');
        }

        // Detectar prioridad
        if (/urgente|bloqueante|inmediata|prioridad|importante/i.test(aiInput)) {
            parsedPriority = 'bloqueante';
            parsedTitle = parsedTitle.replace(/urgente|bloqueante|inmediata|prioridad|importante/i, '');
        }

        // Detectar roles
        if (/cajero|cajera/i.test(aiInput)) {
            const found = globalRoles?.find((r: any) => r.name.toLowerCase().includes('caje'));
            if (found) parsedRoleId = found.id;
        } else if (/ayudante/i.test(aiInput)) {
            const found = globalRoles?.find((r: any) => r.name.toLowerCase().includes('ayud'));
            if (found) parsedRoleId = found.id;
        } else if (/gerente|encargado/i.test(aiInput)) {
            const found = globalRoles?.find((r: any) => r.name.toLowerCase().includes('geren') || r.name.toLowerCase().includes('encar'));
            if (found) parsedRoleId = found.id;
        } else if (/supervisor/i.test(aiInput)) {
            const found = globalRoles?.find((r: any) => r.name.toLowerCase().includes('superv'));
            if (found) parsedRoleId = found.id;
        }

        // Limpieza de espacios y conectores sobrantes
        parsedTitle = parsedTitle.replace(/,\s*,/g, ',').replace(/\s+/g, ' ').trim();
        // Quitar conjunciones y palabras vacías al principio/final
        parsedTitle = parsedTitle.replace(/^(para el|para la|el|la|para)\s+/i, '');
        parsedTitle = parsedTitle.replace(/\s+(en|de|para|con)$/i, '');
        if (parsedTitle.endsWith(',') || parsedTitle.endsWith('.')) {
            parsedTitle = parsedTitle.slice(0, -1);
        }

        setNewTitle(parsedTitle || "Nueva Tarea");
        setNewMins(parsedMins);
        setNewPriority(parsedPriority);
        setNewTargetRole(parsedRoleId);
        showToast("Asistente IA completó el formulario", "success");
    };

    // §35: completar una tarea con evidencia — si la tarea usa validationMode: 'ai_comparison'
    // (solo aplica a assistantType 'evidencia_foto'), se manda la foto real a
    // POST /task-assignments/{id}/ai-validate en vez de completar localmente; el backend ya
    // resuelve ahí la curva de antigüedad (nuevos: siempre humano) y, si le toca IA, compara
    // contra las imágenes de referencia con Gemini. Para el resto de asistentes (número/texto)
    // o evidencia_foto sin ai_comparison, se conserva el flujo local de siempre (completeTask).
    const submitTaskEvidence = async (assignmentId: string, task: Task, evidenceValue: string) => {
        const isAiMode = task.assistantType === 'evidencia_foto' && task.validationMode === 'ai_comparison' && task.aiComparisonEnabled;

        if (!isAiMode) {
            completeTask(assignmentId, globalSimTime, evidenceValue || undefined);
            const basePts = task.points || 10;
            const coins = Number((basePts * 0.1).toFixed(2));
            setCelebration({ coins, xp: basePts, title: task.title });
            setWalletData(prev => ({
                ...prev,
                balance_coins: Number((prev.balance_coins + coins).toFixed(2)),
                xp_points: prev.xp_points + basePts
            }));
            showToast(task.assistantType === 'ninguno' ? '¡Tarea completada!' : 'Evidencia guardada y completada', 'success');
            return true;
        }

        setEvidenceSubmitting(true);
        try {
            const res = await axiosInstance.post(`/task-assignments/${assignmentId}/ai-validate`, {
                evidence_photo_base64: evidenceValue
            });
            const status = res.data?.status;
            const reviewedBy = res.data?.reviewed_by;
            const aiResult = res.data?.ai_result || null;

            useTaskStore.setState(state => ({
                assignments: state.assignments.map(a => a.id === assignmentId ? {
                    ...a,
                    status: status === 'completed' ? 'completed' : 'awaiting_validation',
                    assistantData: evidenceValue,
                    aiValidationResult: aiResult,
                    validationFeedback: status === 'awaiting_validation'
                        ? (aiResult?.reasoning || (reviewedBy === 'ai_unavailable' ? 'Validación por IA no disponible en este momento; enviado a revisión humana.' : null))
                        : null
                } : a)
            }));

            if (status === 'completed') {
                const basePts = task.points || 10;
                const coins = Number((basePts * 0.1).toFixed(2));
                setCelebration({ coins, xp: basePts, title: task.title });
                setWalletData(prev => ({
                    ...prev,
                    balance_coins: Number((prev.balance_coins + coins).toFixed(2)),
                    xp_points: prev.xp_points + basePts
                }));
                showToast('La IA validó tu evidencia. ¡Tarea completada!', 'success');
            } else if (reviewedBy === 'ai_unavailable') {
                showToast('IA no disponible en este momento; se envió a revisión de tu supervisor.', 'warning');
            } else if (reviewedBy === 'human_spotcheck') {
                showToast('Enviado a revisión de tu supervisor (verificación aleatoria).', 'info');
            } else {
                showToast('La IA no encontró coincidencia con la referencia; tu supervisor lo revisará.', 'warning');
            }
            return true;
        } catch (e) {
            console.error('Fallo la validación por IA:', e);
            showToast('No se pudo validar la evidencia. Intenta de nuevo.', 'warning');
            return false;
        } finally {
            setEvidenceSubmitting(false);
        }
    };

    // Acciones de Validación de Supervisor
    const handleApprove = async (assignmentId: string, taskTitle: string) => {
        await validateTaskAssignment(assignmentId, 'completed');
        showToast(`Tarea "${taskTitle}" aprobada`, 'success');
    };

    const handleReject = async (assignmentId: string, taskTitle: string) => {
        if (!rejectFeedback.trim()) {
            showToast("Escribe una razón para rechazar la tarea.", 'warning');
            return;
        }
        await validateTaskAssignment(assignmentId, 'in_progress', rejectFeedback);
        showToast(`Tarea "${taskTitle}" devuelta a corrección`, 'info');
        setRejectingAssignmentId(null);
        setRejectFeedback('');
    };

    // Manejar reanudación de una tarea deteniendo la actual si estuviera activa
    const doStartTaskCooperative = (assignmentId: string, isFromPool: boolean) => {
        // Encontrar si hay alguna tarea en progreso para pausarla primero
        const currentActive = activeAssignmentsFiltered.find(a => a.status === 'in_progress');
        if (currentActive) {
            pauseTask(currentActive.id);
            showToast(`Pausada la tarea: ${tasks.find(t => t.id === currentActive.taskId)?.title}`, 'info');
        }

        if (isFromPool) {
            grabTaskFromPool(assignmentId, currentUser.id, globalSimTime);
        } else {
            startTask(assignmentId, globalSimTime);
        }
    };

    // §38: si la tarea trae una lección de la Academia vinculada, se interpone el video
    // antes de arrancar. Obligatorio de ver mientras el empleado esté en su ventana de
    // nuevo ingreso (<30 días, mismo criterio que validation_mode:dynamic) o si nunca lo
    // ha visto; después queda disponible para repetir pero ya no bloquea.
    const [pendingStart, setPendingStart] = useState<{ assignmentId: string; isFromPool: boolean } | null>(null);
    const [academyGateDismissable, setAcademyGateDismissable] = useState(false);

    const academyAssistantEnabled = currentUser?.clock_preferences?.academy_assistant_enabled !== false;
    const isNewHire = (() => {
        if (!currentUser?.hire_date) return false;
        const days = Math.floor((Date.now() - new Date(currentUser.hire_date).getTime()) / 86400000);
        return days >= 0 && days < 30;
    })();

    const handleStartTaskCooperative = (assignmentId: string, isFromPool: boolean) => {
        const a = assignments.find(x => x.id === assignmentId);
        const t = a ? tasks.find(tsk => tsk.id === a.taskId) : null;
        const lessonVideoUrl = (t as any)?.academyLessonVideoUrl;

        if (lessonVideoUrl && academyAssistantEnabled) {
            setPendingStart({ assignmentId, isFromPool });
            // Obligatorio (no se puede cerrar hasta terminar) solo durante la ventana de
            // nuevo ingreso; fuera de ella se puede saltar de inmediato para repetirlo
            // "por si se les olvida" sin que estorbe el flujo del día a día.
            setAcademyGateDismissable(!isNewHire);
            return;
        }
        doStartTaskCooperative(assignmentId, isFromPool);
    };

    return (
        <div className="flex flex-col h-full bg-[#f8f9fe] text-slate-800 font-sans px-2.5 pb-4 pt-1.5 select-none relative overflow-hidden">
            {/* Header de Monedero Digital & Gamificación (XP + Level + Coins) */}
            <div className="bg-gradient-to-r from-amber-500 via-amber-600 to-amber-700 text-white rounded-2xl p-3 mb-3 shadow-md flex items-center justify-between shrink-0">
                <div className="flex items-center gap-2.5">
                    <div className="w-10 h-10 bg-white/20 backdrop-blur-md rounded-xl flex items-center justify-center text-xl shadow-inner">
                        🪙
                    </div>
                    <div className="text-left">
                        <span className="text-[10px] font-black uppercase tracking-wider text-amber-100 block leading-tight">Monedero Digital Talent360</span>
                        <span className="text-lg font-black tracking-tight text-white leading-none">
                            ${walletData.balance_coins.toFixed(2)} <span className="text-xs font-bold text-amber-200">Coins</span>
                        </span>
                    </div>
                </div>
                <div className="flex items-center gap-2 bg-white/15 px-3 py-1.5 rounded-xl border border-white/20 backdrop-blur-sm">
                    <span className="text-xs">🌟</span>
                    <div className="text-right">
                        <span className="text-[9.5px] font-black text-amber-100 uppercase tracking-wide block leading-none">Nivel {walletData.level}</span>
                        <span className="text-xs font-extrabold text-white">{walletData.xp_points} XP</span>
                    </div>
                </div>
            </div>

            {/* Fila fija de 2 botones principales (Todas y Mis Tareas) */}
            <div className="grid grid-cols-2 gap-2.5 mb-3.5 shrink-0 select-none">
                {/* Botón 1: Todas (Muestra todas, con badge de completadas/total) */}
                <button
                    type="button"
                    onClick={() => setFilterTab('todos')}
                    className={`flex flex-col items-center justify-center py-2.5 px-2 rounded-2xl border transition-all cursor-pointer relative ${
                        filterTab === 'todos'
                            ? 'text-white shadow-md scale-[1.01]'
                            : 'bg-white text-slate-550 border-slate-200/85 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-800'
                    }`}
                    style={filterTab === 'todos' ? { backgroundColor: activeColor.hex, borderColor: activeColor.hex } : {}}
                >
                    <ClipboardList size={17} className={filterTab === 'todos' ? 'text-white' : 'text-slate-400'} />
                    <span className="text-[9px] font-black uppercase mt-1">Todas</span>
                    <span className={`absolute -top-1 -right-1 text-[8px] font-black px-1.5 py-0.2 rounded-full shadow-xs border ${
                        filterTab === 'todos' 
                            ? 'bg-white border-white' 
                            : 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-800 dark:border-slate-700 dark:text-slate-350'
                    }`}
                        style={filterTab === 'todos' ? { color: activeColor.hex } : {}}
                    >
                        {completedTodayCount}/{activeAssignmentsFiltered.length + historyAssignmentsFiltered.length}
                    </span>
                </button>

                {/* Botón 2: Mis Tareas (Muestra las del colaborador, con badge de puntos) */}
                <button
                    type="button"
                    onClick={() => setFilterTab('mis_tareas')}
                    className={`flex flex-col items-center justify-center py-2.5 px-2 rounded-2xl border transition-all cursor-pointer relative ${
                        filterTab === 'mis_tareas'
                            ? 'text-white shadow-md scale-[1.01]'
                            : 'bg-white text-slate-550 border-slate-200/85 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-800'
                    }`}
                    style={filterTab === 'mis_tareas' ? { backgroundColor: activeColor.hex, borderColor: activeColor.hex } : {}}
                >
                    <User size={17} className={filterTab === 'mis_tareas' ? 'text-white' : 'text-slate-400'} />
                    <span className="text-[9px] font-black uppercase mt-1 leading-none text-center">Mis Tareas</span>
                    <span className={`absolute -top-1 -right-1 text-[7.5px] font-black px-1.5 py-0.2 rounded-full shadow-xs border ${
                        filterTab === 'mis_tareas'
                            ? 'bg-white border-white animate-pulse'
                            : 'bg-emerald-500 text-white border-emerald-450'
                    }`}
                        style={filterTab === 'mis_tareas' ? { color: activeColor.hex } : {}}
                    >
                        {filterTab === 'mis_tareas' ? `+${pointsToday} pts` : `+${pointsToday}p`}
                    </span>
                </button>
            </div>

            {/* Submenú de Filtros Rápidos Horizontal Deslizable (Reemplaza a Lista Unificada y Buscar) */}
            <div className="flex items-center gap-1.5 overflow-x-auto pb-2 mb-3.5 scrollbar-none shrink-0 select-none -mx-1 px-1">
                <button
                    type="button"
                    onClick={() => setSubMenuFilter('todas')}
                    className={`px-3 py-1.5 rounded-full text-[8.5px] font-extrabold uppercase border tracking-wider transition-all cursor-pointer ${
                        subMenuFilter === 'todas'
                            ? 'bg-slate-800 text-white border-slate-800 shadow-sm'
                            : 'bg-white text-slate-550 border-slate-200 hover:bg-slate-50'
                    }`}
                >
                    Todas
                </button>
                <button
                    type="button"
                    onClick={() => setSubMenuFilter('obligatorias')}
                    className={`px-3 py-1.5 rounded-full text-[8.5px] font-extrabold uppercase border tracking-wider transition-all cursor-pointer flex items-center gap-1 shrink-0 ${
                        subMenuFilter === 'obligatorias'
                            ? 'bg-rose-600 text-white border-rose-600 shadow-sm'
                            : 'bg-rose-50/40 text-rose-605 border-rose-100 hover:bg-rose-50'
                    }`}
                >
                    ⚠️ Obligatorias
                </button>
                <button
                    type="button"
                    onClick={() => setSubMenuFilter('operativo')}
                    className={`px-3 py-1.5 rounded-full text-[8.5px] font-extrabold uppercase border tracking-wider transition-all cursor-pointer flex items-center gap-1 shrink-0 ${
                        subMenuFilter === 'operativo'
                            ? 'bg-blue-600 text-white border-blue-600 shadow-sm'
                            : 'bg-blue-50/40 text-blue-605 border-blue-100 hover:bg-blue-50'
                    }`}
                >
                    ⚙️ Operativas
                </button>
                <button
                    type="button"
                    onClick={() => setSubMenuFilter('administrativo')}
                    className={`px-3 py-1.5 rounded-full text-[8.5px] font-extrabold uppercase border tracking-wider transition-all cursor-pointer flex items-center gap-1 shrink-0 ${
                        subMenuFilter === 'administrativo'
                            ? 'bg-emerald-600 text-white border-emerald-600 shadow-sm'
                            : 'bg-emerald-50/40 text-emerald-605 border-emerald-100 hover:bg-emerald-50'
                    }`}
                >
                    📋 Administrativas
                </button>
                <button
                    type="button"
                    onClick={() => setSubMenuFilter('mantenimiento')}
                    className={`px-3 py-1.5 rounded-full text-[8.5px] font-extrabold uppercase border tracking-wider transition-all cursor-pointer flex items-center gap-1 shrink-0 ${
                        subMenuFilter === 'mantenimiento'
                            ? 'bg-amber-600 text-white border-amber-600 shadow-sm'
                            : 'bg-amber-50/40 text-amber-605 border-amber-100 hover:bg-amber-50'
                    }`}
                >
                    🔧 Mantenimiento
                </button>
                <button
                    type="button"
                    onClick={() => setSubMenuFilter('supervision')}
                    className={`px-3 py-1.5 rounded-full text-[8.5px] font-extrabold uppercase border tracking-wider transition-all cursor-pointer flex items-center gap-1 shrink-0 ${
                        subMenuFilter === 'supervision'
                            ? 'bg-violet-600 text-white border-violet-600 shadow-sm'
                            : 'bg-violet-50/40 text-violet-605 border-violet-100 hover:bg-violet-50'
                    }`}
                >
                    🔍 Supervisión
                </button>
            </div>

            {/* Listado principal */}
            <div 
                className="flex-1 overflow-y-auto pb-24 scrollbar-none"
                style={{ scrollbarWidth: 'none', msOverflowStyle: 'none' }}
            >

                {displayedAssignments.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-12 text-center bg-white rounded-3xl border border-slate-200/60 p-6 shadow-sm">
                        <Sparkles size={36} className="text-[#2dce89] mb-2 animate-pulse" />
                        <p className="text-sm font-black text-slate-700">¡Tablero limpio!</p>
                        <p className="text-xs text-slate-500 mt-1">
                            No se encontraron tareas en esta sección por ahora.
                        </p>
                    </div>
                ) : (
                    <div className="flex flex-col gap-2.5">
                        {displayedAssignments.map((a, idx) => {
                            const t = tasks.find(tsk => tsk.id === a.taskId);
                            if (!t) return null;

                            return (
                                <FichaTarea
                                    key={a.id}
                                    assignment={a}
                                    task={t}
                                    currentUser={currentUser}
                                    globalSimTime={globalSimTime}
                                    isNext={idx === 0 && filterTab === 'mis_tareas' && a.status === 'pending'}
                                    onSelect={() => handleSelectAssignment(a.id)}
                                    onPlayPause={(e) => {
                                        e.stopPropagation();
                                        if (a.status === 'in_progress') {
                                            pauseTask(a.id);
                                            showToast(`Pausada la tarea: ${t.title}`, 'info');
                                        } else {
                                            handleStartTaskCooperative(a.id, a.userId === null);
                                        }
                                    }}
                                    getRoleName={getRoleName}
                                />
                            );
                        })}
                    </div>
                )}
            </div>

            {/* Alerta flotante Toast */}
            {toast && (
                <div className="fixed bottom-24 left-1/2 -translate-x-1/2 z-50 bg-slate-900/90 text-white px-4.5 py-2.5 rounded-2xl shadow-lg flex items-center gap-2 border border-slate-800/20 backdrop-blur-sm animate-in fade-in slide-in-from-bottom-2 duration-200">
                    <span className="text-xs font-black">{toast.message}</span>
                </div>
            )}

            {/* Botón Flotante (FAB) - Posicionamiento absoluto contenido en el simulador */}
            <div className="absolute bottom-20 right-4 z-40" ref={fabMenuRef}>
                {isSupervisor && showFabMenu && (
                    <div className="absolute bottom-16 right-0 bg-white border border-slate-200/80 rounded-2xl shadow-xl p-2.5 flex flex-col gap-1 min-w-[200px] animate-in fade-in slide-in-from-bottom-3 duration-200 text-left">
                        <p className="text-[9px] font-black uppercase tracking-widest text-slate-400 px-3 py-1.5 text-left border-b border-slate-50">
                            Menú de Tareas
                        </p>
                        
                        <button
                            type="button"
                            onClick={() => { setShowCreateModal(true); setShowFabMenu(false); }}
                            className="w-full text-left px-3 py-2 rounded-xl text-xs font-black text-slate-655 hover:bg-slate-50 border-none bg-transparent cursor-pointer flex items-center gap-2"
                        >
                            ➕ Crear Tarea Nueva
                        </button>

                        <button
                            type="button"
                            onClick={() => { 
                                setShowCreateModal(true); 
                                setShowFabMenu(false);
                                // Auto focus IA assistant
                                setTimeout(() => {
                                    const aiInputEl = document.querySelector('input[placeholder*="inventario"]');
                                    if (aiInputEl) (aiInputEl as HTMLInputElement).focus();
                                }, 100);
                            }}
                            className="w-full text-left px-3 py-2 rounded-xl text-xs font-black hover:bg-blue-50 border-none bg-transparent cursor-pointer flex items-center gap-2"
                            style={{ color: activeColor.hex }}
                        >
                            🤖 Crear con Asistente de IA
                        </button>

                        <button
                            type="button"
                            onClick={() => { setShowHistoryModal(true); setShowFabMenu(false); }}
                            className="w-full text-left px-3 py-2 rounded-xl text-xs font-black text-slate-655 hover:bg-slate-50 border-none bg-transparent cursor-pointer flex items-center gap-2"
                        >
                            📜 Ver Historial de Hoy
                        </button>

                        <button
                            type="button"
                            onClick={() => { setShowPlanModal(true); setPlanModalTab('armar'); setShowFabMenu(false); }}
                            className="w-full text-left px-3 py-2 rounded-xl text-xs font-black text-slate-655 hover:bg-slate-50 border-none bg-transparent cursor-pointer flex items-center gap-2"
                        >
                            🗓️ Plan del Día
                        </button>
                    </div>
                )}

                <button
                    onClick={() => {
                        if (isSupervisor) {
                            setShowFabMenu(!showFabMenu);
                        } else {
                            setShowHistoryModal(true);
                        }
                    }}
                    className="w-14 h-14 bg-[#8a2be2] hover:bg-[#7b1fa2] text-white rounded-full shadow-lg flex items-center justify-center transition-all hover:scale-105 active:scale-95 border-none cursor-pointer"
                    title={isSupervisor ? "Menú de Acciones" : "Ver Historial"}
                >
                    {isSupervisor && showFabMenu ? (
                        <X size={22} />
                    ) : isSupervisor ? (
                        <Plus size={22} className="text-white" />
                    ) : (
                        <ClipboardList size={22} className="text-white" />
                    )}
                </button>
            </div>

            {/* Modal de video de la Academia antes de iniciar una tarea vinculada (§38) */}
            {pendingStart && (() => {
                const a = assignments.find(x => x.id === pendingStart.assignmentId);
                const t = a ? tasks.find(tsk => tsk.id === a.taskId) : null;
                const videoUrl = (t as any)?.academyLessonVideoUrl as string | undefined;
                const lessonId = (t as any)?.academyLessonId as number | undefined;
                if (!t || !videoUrl) return null;

                // Además de la tarea, esto es una lección real de la Academia — se marca
                // como completada ahí para que cuente en gamificación/ascenso, no solo
                // como un clip aislado dentro del módulo de Tareas.
                const markLessonSeen = () => {
                    if (!lessonId) return;
                    axiosInstance.post(`/academy/courses/${lessonId}/progress`, { status: 'completed' })
                        .catch(e => console.warn('No se pudo registrar el progreso en la Academia', e));
                };

                // Controles mínimos: sin marca, sin relacionados, sin pantalla completa,
                // sin anotaciones — solo play/pausa, como pidió Francisco.
                const embedUrl = `${videoUrl}${videoUrl.includes('?') ? '&' : '?'}modestbranding=1&rel=0&fs=0&disablekb=1&iv_load_policy=3`;

                return (
                    <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[9999] flex items-center justify-center p-4 animate-fade-in text-left">
                        <div className="bg-white rounded-3xl p-5 shadow-2xl border border-slate-100 w-full max-w-md">
                            <h3 className="font-black text-slate-800 text-sm mb-1 flex items-center gap-1.5">
                                🎓 Antes de empezar: {t.title}
                            </h3>
                            <p className="text-xs text-slate-500 mb-3">
                                {academyGateDismissable
                                    ? 'Ya la completaste antes — puedes verla de nuevo o continuar directo.'
                                    : 'Es tu primera vez con esta tarea. Míralo completo antes de empezar.'}
                            </p>
                            <div className="rounded-2xl overflow-hidden bg-slate-100 aspect-video mb-4">
                                <iframe
                                    src={embedUrl}
                                    className="w-full h-full border-0"
                                    allow="autoplay; encrypted-media"
                                    title={`Lección: ${t.title}`}
                                />
                            </div>
                            <div className="flex gap-2">
                                {academyGateDismissable && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            const p = pendingStart;
                                            setPendingStart(null);
                                            if (p) doStartTaskCooperative(p.assignmentId, p.isFromPool);
                                        }}
                                        className="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-600 font-black text-xs rounded-xl border-none cursor-pointer"
                                    >
                                        Saltar video
                                    </button>
                                )}
                                <button
                                    type="button"
                                    onClick={() => {
                                        markLessonSeen();
                                        const p = pendingStart;
                                        setPendingStart(null);
                                        if (p) doStartTaskCooperative(p.assignmentId, p.isFromPool);
                                    }}
                                    className="flex-1 py-3 bg-[#8a2be2] hover:bg-[#7b1fa2] text-white font-black text-xs rounded-xl border-none cursor-pointer"
                                >
                                    Comenzar tarea
                                </button>
                            </div>
                        </div>
                    </div>
                );
            })()}

            {/* Modal de Historial */}
            {showHistoryModal && (
                <div className="fixed inset-0 bg-slate-900/30 backdrop-blur-xs z-50 flex items-center justify-center p-4 animate-fade-in text-left">
                    <div className="bg-white rounded-3xl p-6 shadow-xl border border-slate-200/80 w-full max-w-md max-h-[85vh] flex flex-col">
                        <div className="flex justify-between items-center mb-4 sticky top-0 bg-white pb-2 border-b border-slate-150 z-10 shrink-0">
                            <h3 className="text-sm font-black text-slate-800 flex items-center gap-1.5">
                                📜 Historial de Tareas de Hoy
                            </h3>
                            <button 
                                onClick={() => setShowHistoryModal(false)}
                                className="w-7 h-7 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-400 hover:text-slate-600 flex items-center justify-center border-none cursor-pointer"
                            >
                                <X size={15} />
                            </button>
                        </div>
                        
                        <div className="flex-1 overflow-y-auto custom-scrollbar pr-1">
                            {historyAssignmentsFiltered.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-12 text-center bg-slate-50 rounded-2xl p-6 border border-slate-100">
                                    <AlertCircle size={32} className="text-slate-350 mb-2" />
                                    <p className="text-xs font-black text-slate-700">Sin historial registrado</p>
                                    <p className="text-[11px] text-slate-400 mt-1">
                                        No has completado ni omitido tareas durante tu turno actual.
                                    </p>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {historyAssignmentsFiltered.map(a => {
                                        const t = tasks.find(tsk => tsk.id === a.taskId);
                                        if (!t) return null;

                                        return (
                                            <div 
                                                key={a.id}
                                                onClick={() => {
                                                    handleSelectAssignment(a.id);
                                                    setShowHistoryModal(false);
                                                }}
                                                className="bg-slate-55/40 border border-slate-200/60 rounded-xl p-3 hover:bg-slate-50 transition-colors cursor-pointer"
                                            >
                                                <div className="flex justify-between items-start gap-2">
                                                    <span className={`text-[8px] font-black uppercase px-2 py-0.5 rounded-md border ${
                                                        a.status === 'completed' ? 'bg-teal-50 text-teal-700 border-teal-200/50' :
                                                        a.status === 'awaiting_validation' ? 'bg-amber-50 text-amber-700 border-amber-250/50' :
                                                        'bg-rose-50 text-rose-700 border-rose-250/50'
                                                    }`}>
                                                    <span>
                                                        {a.status === 'completed' ? 'Completada' :
                                                         a.status === 'awaiting_validation' ? 'Por Validar' : 'Omitida'}
                                                    </span>
                                                    </span>
                                                    <span className="text-[9px] text-slate-450 font-extrabold flex items-center gap-1">
                                                        <Clock size={10} />
                                                        {a.accumulatedMins || t.estimatedMins} min
                                                    </span>
                                                </div>
                                                <h4 className="font-extrabold text-xs text-slate-750 mt-1.5 line-clamp-1">
                                                    {t.title}
                                                </h4>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* Modal de Plan de Trabajo Diario (§40): armar el plan de hoy + reporte de cierre */}
            {showPlanModal && isSupervisor && (
                <div className="fixed inset-0 bg-slate-900/30 backdrop-blur-xs z-50 flex items-center justify-center p-4 animate-fade-in text-left">
                    <div className="bg-white rounded-3xl p-6 shadow-xl border border-slate-200/80 w-full max-w-lg max-h-[90vh] flex flex-col">
                        <div className="flex justify-between items-center mb-3 sticky top-0 bg-white pb-2 border-b border-slate-150 z-10 shrink-0">
                            <h3 className="text-sm font-black text-slate-800 flex items-center gap-1.5">
                                🗓️ Plan de Trabajo Diario
                            </h3>
                            <button
                                onClick={() => setShowPlanModal(false)}
                                className="w-7 h-7 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-400 hover:text-slate-600 flex items-center justify-center border-none cursor-pointer"
                            >
                                <X size={15} />
                            </button>
                        </div>

                        {/* Sub-tabs: Armar Plan / Reporte de Cierre */}
                        <div className="flex bg-slate-100 rounded-xl p-1 mb-4 shrink-0">
                            <button
                                type="button"
                                onClick={() => setPlanModalTab('armar')}
                                className={`flex-1 py-1.5 rounded-lg text-[11px] font-black border-none cursor-pointer transition-colors ${
                                    planModalTab === 'armar' ? 'bg-white shadow-sm' : 'bg-transparent text-slate-400'
                                }`}
                                style={planModalTab === 'armar' ? { color: activeColor.hex } : {}}
                            >
                                Armar Plan de Hoy
                            </button>
                            <button
                                type="button"
                                onClick={() => setPlanModalTab('reporte')}
                                className={`flex-1 py-1.5 rounded-lg text-[11px] font-black border-none cursor-pointer transition-colors ${
                                    planModalTab === 'reporte' ? 'bg-white shadow-sm' : 'bg-transparent text-slate-400'
                                }`}
                                style={planModalTab === 'reporte' ? { color: activeColor.hex } : {}}
                            >
                                Reporte de Cierre
                            </button>
                        </div>

                        <div className="flex-1 overflow-y-auto custom-scrollbar pr-1">
                            {planModalTab === 'armar' && (
                                <div className="space-y-4">
                                    <p className="text-[11px] text-slate-500 font-semibold leading-normal">
                                        Retoma los pendientes de ayer que quieras traer al plan de hoy, o agrega tareas nuevas. Al confirmar, cada pendiente marcado queda re-fechado a hoy.
                                    </p>

                                    <div>
                                        <h4 className="text-[9px] font-black text-slate-400 uppercase tracking-wider mb-2">
                                            Pendientes de Ayer ({yesterdayLeftovers.length})
                                        </h4>
                                        {yesterdayLeftovers.length === 0 ? (
                                            <div className="bg-slate-50 border border-dashed border-slate-200 rounded-xl p-4 text-center text-[11px] text-slate-400 font-bold">
                                                No quedaron pendientes de ayer. 🎉
                                            </div>
                                        ) : (
                                            <div className="space-y-2">
                                                {yesterdayLeftovers.map(a => {
                                                    const t = tasks.find(tsk => tsk.id === a.taskId);
                                                    if (!t) return null;
                                                    const u = (globalUsers || []).find((gu: any) => gu.id === a.userId);
                                                    const checked = carryOverChecked[a.id] !== false; // default true
                                                    return (
                                                        <label
                                                            key={a.id}
                                                            className="flex items-start gap-2.5 bg-slate-55/40 border border-slate-200/60 rounded-xl p-3 cursor-pointer hover:bg-slate-50 transition-colors"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                checked={checked}
                                                                onChange={(e) => setCarryOverChecked(prev => ({ ...prev, [a.id]: e.target.checked }))}
                                                                className="mt-0.5 shrink-0"
                                                            />
                                                            <div className="min-w-0 flex-1">
                                                                <h5 className="font-extrabold text-xs text-slate-750 truncate">{t.title}</h5>
                                                                <p className="text-[9px] text-slate-400 font-semibold">
                                                                    {u?.name || (a.userId === null ? 'Bolsa de Trabajo' : `Usuario #${a.userId}`)}
                                                                </p>
                                                            </div>
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>

                                    <button
                                        type="button"
                                        onClick={() => {
                                            setShowPlanModal(false);
                                            setCreatingTaskOrigin('planned');
                                            setShowCreateModal(true);
                                        }}
                                        className="w-full py-2.5 rounded-xl text-xs font-black border border-dashed border-slate-300 text-slate-500 hover:bg-slate-50 bg-transparent cursor-pointer"
                                    >
                                        + Agregar Tarea Nueva para Hoy
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => {
                                            const toCarryOver = yesterdayLeftovers.filter(a => carryOverChecked[a.id] !== false);
                                            toCarryOver.forEach(a => carryOverAssignment(a.id, todayStr));
                                            showToast(`Plan de hoy confirmado: ${toCarryOver.length} pendiente(s) traídos de ayer.`, 'success');
                                            setCarryOverChecked({});
                                            setShowPlanModal(false);
                                        }}
                                        disabled={yesterdayLeftovers.length === 0}
                                        className="w-full py-2.5 rounded-xl text-xs font-black text-white border-none cursor-pointer disabled:opacity-40"
                                        style={{ backgroundColor: activeColor.hex }}
                                    >
                                        Confirmar Plan de Hoy
                                    </button>
                                </div>
                            )}

                            {planModalTab === 'reporte' && (
                                <div className="space-y-4">
                                    <div className="flex items-center gap-2">
                                        <label className="text-[10px] font-black text-slate-400 uppercase">Fecha</label>
                                        <input
                                            type="date"
                                            value={planReportDate}
                                            onChange={(e) => setPlanReportDate(e.target.value)}
                                            className="p-1.5 border border-slate-200 rounded-lg text-xs font-semibold outline-none"
                                        />
                                    </div>

                                    {/* Totales agregados */}
                                    <div className="grid grid-cols-4 gap-1.5">
                                        <div className="bg-slate-50 border border-slate-150 rounded-xl p-2 text-center">
                                            <p className="text-sm font-black text-slate-700">{reportTotals.total}</p>
                                            <p className="text-[8px] font-bold text-slate-400 uppercase">Total</p>
                                        </div>
                                        <div className="bg-emerald-50 border border-emerald-100 rounded-xl p-2 text-center">
                                            <p className="text-sm font-black text-emerald-700">{reportTotals.completed}</p>
                                            <p className="text-[8px] font-bold text-emerald-500 uppercase">Hechas</p>
                                        </div>
                                        <div className="bg-rose-50 border border-rose-100 rounded-xl p-2 text-center">
                                            <p className="text-sm font-black text-rose-700">{reportTotals.omitted}</p>
                                            <p className="text-[8px] font-bold text-rose-500 uppercase">Omitidas</p>
                                        </div>
                                        <div className="bg-amber-50 border border-amber-100 rounded-xl p-2 text-center">
                                            <p className="text-sm font-black text-amber-700">{reportTotals.extra}</p>
                                            <p className="text-[8px] font-bold text-amber-500 uppercase">Extras</p>
                                        </div>
                                    </div>

                                    {reportByUser.length === 0 ? (
                                        <div className="bg-slate-50 border border-dashed border-slate-200 rounded-xl p-4 text-center text-[11px] text-slate-400 font-bold">
                                            Sin asignaciones registradas ese día.
                                        </div>
                                    ) : (
                                        <div className="space-y-2">
                                            {reportByUser.map(r => (
                                                <div key={r.userId} className="bg-white border border-slate-200 rounded-xl p-3">
                                                    <div className="flex items-center justify-between mb-1.5">
                                                        <h5 className="font-extrabold text-xs text-slate-750">{r.name}</h5>
                                                        <span className="text-[9px] font-bold text-slate-400">{r.total} tareas</span>
                                                    </div>
                                                    <div className="flex flex-wrap gap-1">
                                                        <span className="text-[8px] font-black px-1.5 py-0.5 rounded-md bg-emerald-50 text-emerald-700">✔ {r.completed} hechas</span>
                                                        {r.omitted > 0 && <span className="text-[8px] font-black px-1.5 py-0.5 rounded-md bg-rose-50 text-rose-700">✕ {r.omitted} omitidas</span>}
                                                        {r.stillOpen > 0 && <span className="text-[8px] font-black px-1.5 py-0.5 rounded-md bg-slate-100 text-slate-500">⏳ {r.stillOpen} abiertas</span>}
                                                        {r.extra > 0 && <span className="text-[8px] font-black px-1.5 py-0.5 rounded-md bg-amber-50 text-amber-700">+{r.extra} extras</span>}
                                                        {r.carriedOver > 0 && <span className="text-[8px] font-black px-1.5 py-0.5 rounded-md bg-indigo-50 text-indigo-700">↺ {r.carriedOver} de ayer</span>}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* Modal de creación de tareas con ASISTENTE DE IA (Light Theme) */}
            {showCreateModal && (
                <div className="fixed inset-0 bg-slate-900/30 backdrop-blur-xs z-50 flex items-center justify-center p-4 animate-fade-in text-left">
                    <div className="bg-white rounded-3xl p-6 shadow-xl border border-slate-200/80 w-full max-w-md max-h-[92vh] overflow-y-auto custom-scrollbar">
                        <div className="flex justify-between items-center mb-4 sticky top-0 bg-white pb-2 border-b border-slate-50 z-10">
                            <h3 className="text-lg font-black text-slate-800">Lanzar Tarea</h3>
                            <button 
                                onClick={() => setShowCreateModal(false)}
                                className="w-7 h-7 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-400 hover:text-slate-600 flex items-center justify-center border-none cursor-pointer"
                            >
                                <X size={15} />
                            </button>
                        </div>

                        {/* Asistente IA Rápido */}
                        <div className="bg-blue-50/50 border border-blue-100 rounded-2xl p-4 mb-4 space-y-2">
                            <p className="font-black text-xs text-blue-600 flex items-center gap-1">
                                <Sparkles size={13} /> Asistente de Creación Rápida con IA
                            </p>
                            <p className="text-[10px] text-slate-500 font-semibold leading-normal">
                                Escribe lo que necesitas en lenguaje natural y la IA auto-completará los campos del formulario.
                            </p>
                            <div className="flex gap-2">
                                <input 
                                    type="text"
                                    value={aiInput}
                                    onChange={e => setAiInput(e.target.value)}
                                    placeholder="Ej: Contar inventario de mermas por 20 minutos urgente para cajero"
                                    className="flex-1 p-2 bg-white border border-slate-200 rounded-xl outline-none text-xs font-semibold focus:ring-1 focus:ring-blue-500"
                                />
                                <button
                                    type="button"
                                    onClick={handleAIParsing}
                                    disabled={!aiInput}
                                    className="px-3 bg-blue-600 hover:bg-blue-700 text-white rounded-xl border-none cursor-pointer flex items-center justify-center shrink-0 disabled:opacity-50"
                                    title="Interpretar texto con IA"
                                >
                                    <Sparkles size={13} />
                                </button>
                            </div>
                        </div>

                        <form onSubmit={handleCreateTaskSubmit} className="space-y-4">
                            <div>
                                <label className="block text-xs font-black text-slate-500 uppercase mb-1.5">
                                    Título de la Tarea
                                </label>
                                <input 
                                    type="text" 
                                    value={newTitle}
                                    onChange={e => setNewTitle(e.target.value)}
                                    placeholder="Ej: Limpieza de cafetera industrial..."
                                    required
                                    className="w-full p-2.5 border border-slate-200 bg-slate-55 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none text-xs font-semibold"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-black text-slate-500 uppercase mb-1.5">
                                    Puesto Responsable
                                </label>
                                <select 
                                    value={newTargetRole}
                                    onChange={e => setNewTargetRole(Number(e.target.value))}
                                    className="w-full p-2.5 border border-slate-200 bg-slate-55 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none text-xs font-semibold"
                                >
                                    <option value={0}>Cualquiera (Bolsa de Trabajo General)</option>
                                    {globalRoles?.map((role: any) => (
                                        <option key={role.id} value={role.id}>{role.name}</option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-black text-slate-500 uppercase mb-1.5">
                                        Tiempo Est. (Minutos)
                                    </label>
                                    <input 
                                        type="number" 
                                        min={5}
                                        max={240}
                                        value={newMins}
                                        onChange={e => setNewMins(Number(e.target.value))}
                                        required
                                        className="w-full p-2.5 border border-slate-200 bg-slate-55 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none text-xs font-semibold"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-black text-slate-500 uppercase mb-1.5">
                                        Prioridad
                                    </label>
                                    <select 
                                        value={newPriority}
                                        onChange={e => setNewPriority(e.target.value as any)}
                                        className="w-full p-2.5 border border-slate-200 bg-slate-55 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none text-xs font-semibold"
                                    >
                                        <option value="normal">Normal</option>
                                        <option value="bloqueante">Bloqueante (Obligatoria)</option>
                                    </select>
                                </div>
                            </div>

                            <button 
                                type="submit" 
                                className="w-full py-3 bg-indigo-650 hover:bg-indigo-755 text-white font-black text-xs rounded-xl shadow-md transition-all active:scale-95 border-none cursor-pointer mt-2"
                            >
                                Lanzar Tarea a Bolsa
                            </button>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal de Detalles de Tarea */}
            {selectedAssignmentId && (() => {
                const a = assignments.find(asg => asg.id === selectedAssignmentId);
                if (!a) return null;
                const t = tasks.find(tsk => tsk.id === a.taskId);
                if (!t) return null;

                const worker = globalUsers?.find((u: any) => u.id === a.userId);
                const isFromPool = a.userId === null;
                const restriction = getRoutineTimeRestriction(a.assignedFromRoutineId);

                const isCompleted = a.status === 'completed' || a.status === 'awaiting_validation';
                const isOmitted = a.status === 'omitted';
                const isActive = a.status === 'in_progress';
                const isPaused = a.status === 'paused';

                const elapsed = a.status === 'pending' ? 0 :
                    ((a.accumulatedMins || 0) +
                    (a.status === 'in_progress' && a.startedAtMins ? (globalSimTime - a.startedAtMins) : 0));

                // Los pasos con verification_required:true deben quedar marcados antes de poder
                // completar la tarea. Si la tarea no tiene manual de procedimiento, no aplica.
                const hasSteps = !!(t.procedureSteps && t.procedureSteps.length > 0);
                const stepProgress = hasSteps ? getStepProgress(a.id) : null;
                const requiredStepsDone = !hasSteps || t.procedureSteps!
                    .filter(s => s.verification_required)
                    .every(s => stepProgress!.completedSteps[`${s.step_number}_${s.title}`]);
                // Si ya hay manual de procedimiento, el mini-asistente se muestra embebido en el
                // último paso (ver bloque del stepper arriba) — no se repite aquí abajo.
                const assistantShownInSteps = hasSteps;

                return (
                    <div className="fixed inset-0 bg-slate-900/35 backdrop-blur-xs z-50 flex items-center justify-center p-4 animate-fade-in text-left">
                        <div className="bg-white rounded-3xl p-6 shadow-xl border border-slate-200/80 w-full max-w-md max-h-[90vh] overflow-y-auto custom-scrollbar flex flex-col">
                            {/* Header */}
                            <div className="flex justify-between items-start mb-4 border-b border-slate-100 pb-3">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span className={`text-[8.5px] font-black uppercase px-2 py-0.5 rounded border ${
                                            a.status === 'awaiting_validation' ? 'bg-amber-50 text-amber-700 border-amber-200' :
                                            a.userId === null ? 'bg-sky-50 text-sky-700 border-sky-200' :
                                            a.status === 'in_progress' ? 'bg-emerald-50 text-emerald-700 border-emerald-250' :
                                            a.status === 'paused' ? 'bg-slate-100 text-slate-600 border-slate-200' :
                                            'bg-indigo-50 text-indigo-755 border-indigo-200'
                                        }`}>
                                            {a.status === 'awaiting_validation' ? 'Por Validar' :
                                             a.userId === null ? 'Bolsa de Trabajo' :
                                             a.status === 'in_progress' ? 'En Curso' :
                                             a.status === 'paused' ? 'Pausada' : 'Pendiente'}
                                        </span>
                                        {t.priority === 'bloqueante' && (
                                            <span className="text-[8px] font-black uppercase px-1.5 py-0.5 rounded bg-rose-50 text-rose-600 border border-rose-100">
                                                ⚠️ Obligatoria
                                            </span>
                                        )}
                                    </div>
                                    <h3 className="text-base font-black text-slate-800 mt-2 leading-snug">
                                        {t.title}
                                    </h3>
                                </div>
                                <button 
                                    onClick={() => handleSelectAssignment(null)}
                                    className="w-7 h-7 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-400 hover:text-slate-600 flex items-center justify-center border-none cursor-pointer shrink-0"
                                >
                                    <X size={15} />
                                </button>
                            </div>

                            {/* Content */}
                            <div className="space-y-3.5 flex-1">
                                {/* Metadata compacta en una sola línea, sin caja de rejilla */}
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-500 font-bold">
                                    <span className="flex items-center gap-1">
                                        <User size={12} className="text-slate-400" />
                                        {isFromPool ? 'Bolsa de Trabajo' : (worker?.name || `Usuario #${a.userId}`)}
                                    </span>
                                    <span className="flex items-center gap-1">
                                        <Clock size={12} className="text-slate-400" />
                                        {a.status === 'pending' ? `${t.estimatedMins} min est.` : `${elapsed} min real`}
                                    </span>
                                    {t.scheduledTime && (
                                        <span className="flex items-center gap-1">⏰ {t.scheduledTime} hrs</span>
                                    )}
                                </div>

                                {t.description && (
                                    <p className="text-xs text-slate-600 font-semibold leading-relaxed">
                                        {t.description}
                                    </p>
                                )}

                                {/* Manual de Ejecución: solo el paso actual se ve completo — los pasos
                                    hechos se colapsan a una fila con check, los que faltan ni se muestran.
                                    Progreso guardado por asignación (ver stepProgressByAssignment arriba)
                                    para que sobreviva cerrar y reabrir este mismo modal. */}
                                {t.procedureSteps && t.procedureSteps.length > 0 ? (() => {
                                    const steps = t.procedureSteps!;
                                    const { completedSteps, activeStepIndex } = getStepProgress(a.id);
                                    const requiredSteps = steps.filter(s => s.verification_required);
                                    const requiredDone = requiredSteps.every(s => completedSteps[`${s.step_number}_${s.title}`]);
                                    const currentStep = steps[activeStepIndex];
                                    const doneCount = steps.filter((s, idx) => idx < activeStepIndex || completedSteps[`${s.step_number}_${s.title}`]).length;
                                    const isLastStep = activeStepIndex >= steps.length - 1;
                                    const showAssistantHere = isActive && isLastStep && t.assistantType !== 'ninguno';

                                    return (
                                        <div className="space-y-2.5">
                                            <div className="flex items-center justify-between text-[11px] font-bold text-slate-500">
                                                <span>Paso {Math.min(activeStepIndex + 1, steps.length)} de {steps.length}</span>
                                                <span>{doneCount} completados</span>
                                            </div>
                                            <div className="flex gap-1">
                                                {steps.map((s, idx) => (
                                                    <div key={s.step_number} className={`flex-1 h-1 rounded-full ${
                                                        idx < activeStepIndex || completedSteps[`${s.step_number}_${s.title}`] ? 'bg-emerald-500' :
                                                        idx === activeStepIndex ? 'bg-indigo-600' : 'bg-slate-200'
                                                    }`} />
                                                ))}
                                            </div>

                                            {currentStep ? (
                                                <div className="border border-indigo-150 bg-indigo-50/40 rounded-2xl p-3.5 space-y-2.5">
                                                    <div className="flex items-center gap-2">
                                                        <span className="w-5 h-5 rounded-full bg-indigo-600 text-white flex items-center justify-center text-[10px] font-black shrink-0">{currentStep.step_number}</span>
                                                        <p className="text-xs font-extrabold text-indigo-900">{currentStep.title}</p>
                                                    </div>
                                                    {currentStep.detailed_instruction && (
                                                        <p className="text-[10.5px] text-slate-600 leading-relaxed font-medium">
                                                            {currentStep.detailed_instruction}
                                                        </p>
                                                    )}

                                                    {showAssistantHere && (
                                                        <div className="bg-white rounded-xl border border-indigo-100 p-2.5 space-y-2">
                                                            <p className="text-[10px] font-black text-indigo-800 flex items-center gap-1"><Bot size={12} className="text-[#8a2be2]" /> {t.assistantPrompt || 'Asistente de evidencia'}</p>
                                                            {t.assistantType === 'evidencia_foto' && (
                                                                !photoDone ? (
                                                                    <button type="button" onClick={() => setCapturingEvidenceFor(a.id)} className="w-full py-2 bg-slate-50 hover:bg-slate-100 text-indigo-700 rounded-lg border border-indigo-150 text-[10px] font-black flex items-center justify-center gap-1.5 cursor-pointer">
                                                                        <Camera size={12} /> Capturar foto de evidencia
                                                                    </button>
                                                                ) : (
                                                                    <div className="flex items-center gap-1.5 text-emerald-700 text-[10.5px] font-bold">
                                                                        <img src={localInput} alt="Evidencia" className="w-6 h-6 rounded object-cover border border-emerald-200" />
                                                                        <Check size={12} /> Evidencia lista
                                                                        <button type="button" onClick={() => { setPhotoDone(false); setLocalInput(''); }} className="ml-auto text-[9px] font-black underline text-slate-500 hover:text-slate-700 border-none bg-transparent cursor-pointer">Cambiar</button>
                                                                    </div>
                                                                )
                                                            )}
                                                            {t.assistantType === 'captura_numero' && (
                                                                <input type="number" value={localInput} onChange={e => setLocalInput(e.target.value)} placeholder="Cantidad..." className="w-full p-2 text-xs border border-slate-200 rounded-lg" />
                                                            )}
                                                            {t.assistantType === 'texto' && (
                                                                <input type="text" value={localInput} onChange={e => setLocalInput(e.target.value)} placeholder="Reporte breve..." className="w-full p-2 text-xs border border-slate-200 rounded-lg" />
                                                            )}
                                                        </div>
                                                    )}

                                                    <button
                                                        type="button"
                                                        disabled={showAssistantHere && (
                                                            (t.assistantType === 'evidencia_foto' && !photoDone) ||
                                                            (t.assistantType !== 'evidencia_foto' && t.assistantType !== 'ninguno' && !localInput.trim())
                                                        ) || evidenceSubmitting}
                                                        onClick={async () => {
                                                            const stepKey = `${currentStep.step_number}_${currentStep.title}`;
                                                            if (showAssistantHere) {
                                                                // Último paso y requiere evidencia: aquí sí se completa la tarea de verdad,
                                                                // no solo se avanza el paso — evita el hueco de "todos los pasos marcados
                                                                // pero la asignación nunca pasó a completed/awaiting_validation".
                                                                const ok = await submitTaskEvidence(a.id, t, localInput);
                                                                if (ok) handleSelectAssignment(null);
                                                            } else {
                                                                markStepDone(a.id, stepKey, activeStepIndex + 1);
                                                            }
                                                        }}
                                                        className="w-full py-2 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-lg text-[10.5px] font-black cursor-pointer border-none"
                                                    >
                                                        {showAssistantHere ? (evidenceSubmitting ? 'Validando…' : 'Completar tarea') : 'Marcar paso listo'}
                                                    </button>
                                                </div>
                                            ) : (
                                                <div className="flex items-center gap-1.5 text-emerald-700 text-[11px] font-bold p-2">
                                                    <Check size={13} /> Todos los pasos completados
                                                </div>
                                            )}

                                            {doneCount > 0 && (
                                                <div className="flex flex-wrap gap-1.5">
                                                    {steps.slice(0, doneCount).map(s => (
                                                        <span key={s.step_number} className="text-[10px] font-bold text-slate-500 flex items-center gap-1 bg-slate-50 border border-slate-150 rounded-full px-2 py-0.5">
                                                            <Check size={10} className="text-emerald-600" /> {s.title}
                                                        </span>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    );
                                })() : (
                                    /* Checklist de subtareas de respaldo */
                                    t.subTasks && t.subTasks.length > 0 && (
                                        <div className="space-y-1.5">
                                            <h5 className="text-[10px] font-black uppercase text-slate-400 tracking-wider">Subtareas obligatorias</h5>
                                            <div className="space-y-1">
                                                {t.subTasks.map(sub => (
                                                    <label key={sub.id} className="flex items-center gap-2 p-2 bg-white rounded-lg border border-slate-150/60 hover:bg-indigo-50/20 cursor-pointer transition-colors shadow-xs">
                                                        <input
                                                            type="checkbox"
                                                            defaultChecked={sub.completed}
                                                            className="w-3.5 h-3.5 text-indigo-650 rounded border-slate-350 focus:ring-indigo-500"
                                                        />
                                                        <span className="text-xs text-slate-700 font-semibold">{sub.text}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        </div>
                                    )
                                )}

                                {/* Caso: Tarea esperando validación (Solo para Supervisor) */}
                                {a.status === 'awaiting_validation' && (
                                    <div className="space-y-3 pt-2 border-t border-slate-100">
                                        {a.assistantData && (
                                            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200 text-xs">
                                                <p className="font-extrabold text-blue-700 flex items-center gap-1.5 mb-1.5">
                                                    📸 Evidencia presentada:
                                                </p>
                                                <p className="text-slate-800 font-black bg-white p-2.5 rounded-lg border border-slate-150">
                                                    {t.assistantType === 'evidencia_foto' ? (
                                                        <span className="flex items-center gap-1.5 text-slate-755">
                                                            <Camera size={13} className="text-indigo-500" />
                                                            {String(a.assistantData)} (Evidencia Fotográfica)
                                                        </span>
                                                    ) : (
                                                        String(a.assistantData)
                                                    )}
                                                </p>
                                            </div>
                                        )}

                                        {rejectingAssignmentId === a.id ? (
                                            <div className="p-3 bg-rose-50 border border-rose-100 rounded-xl space-y-2.5 animate-in slide-in-from-top-2 duration-150">
                                                <p className="text-xs font-black text-rose-800">
                                                    Razón de devolución / Corrección requerida:
                                                </p>
                                                <textarea 
                                                    value={rejectFeedback}
                                                    onChange={e => setRejectFeedback(e.target.value)}
                                                    placeholder="Ej: Te faltó limpiar el área trasera, por favor hazlo antes de terminar..."
                                                    rows={2.5}
                                                    className="w-full p-2.5 border border-slate-200 bg-white rounded-lg outline-none text-xs font-semibold focus:ring-2 focus:ring-rose-500"
                                                />
                                                <div className="flex gap-2 justify-end">
                                                    <button 
                                                        type="button"
                                                        onClick={() => { setRejectingAssignmentId(null); setRejectFeedback(''); }}
                                                        className="px-3 py-1.5 bg-white text-slate-500 rounded-lg text-[10px] font-black border border-slate-200 cursor-pointer"
                                                    >
                                                        Cancelar
                                                    </button>
                                                    <button 
                                                        type="button"
                                                        onClick={() => {
                                                            handleReject(a.id, t.title);
                                                            handleSelectAssignment(null);
                                                        }}
                                                        className="px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-[10px] font-black border-none cursor-pointer"
                                                    >
                                                        Devolver Tarea
                                                    </button>
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="flex gap-2 justify-end mt-2 pt-2">
                                                {isSupervisor ? (
                                                    <>
                                                        <button 
                                                            type="button"
                                                            onClick={() => setRejectingAssignmentId(a.id)}
                                                            className="flex-1 flex items-center justify-center gap-1.5 px-3 py-2.5 hover:bg-rose-50 text-rose-650 rounded-xl text-xs font-black border border-rose-200 cursor-pointer bg-transparent"
                                                        >
                                                            <XCircle size={14} /> Devolver Tarea
                                                        </button>
                                                        <button 
                                                            type="button"
                                                            onClick={() => {
                                                                handleApprove(a.id, t.title);
                                                                handleSelectAssignment(null);
                                                            }}
                                                            className="flex-1 flex items-center justify-center gap-1.5 px-3.5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-black border-none shadow-sm cursor-pointer transition-all hover:scale-105"
                                                        >
                                                            <CheckCircle size={14} /> Validar y Firmar
                                                        </button>
                                                    </>
                                                ) : pinValidatingAssignmentId === a.id ? (
                                                    <div className="w-full p-3 bg-slate-50 border border-slate-200 rounded-xl space-y-2">
                                                        <select
                                                            value={pinValidateSupervisorId}
                                                            onChange={e => setPinValidateSupervisorId(e.target.value ? Number(e.target.value) : '')}
                                                            className="w-full p-2 text-xs border border-slate-200 rounded-lg bg-white"
                                                        >
                                                            <option value="">Selecciona al supervisor...</option>
                                                            {availableSupervisors.map((s: any) => (
                                                                <option key={s.id} value={s.id}>{s.name}</option>
                                                            ))}
                                                        </select>
                                                        <input
                                                            value={pinValidateValue}
                                                            onChange={e => setPinValidateValue(e.target.value)}
                                                            type="password"
                                                            inputMode="numeric"
                                                            maxLength={6}
                                                            placeholder="PIN del supervisor"
                                                            className="w-full p-2 text-xs border border-slate-200 rounded-lg text-center tracking-widest"
                                                        />
                                                        {pinValidateError && (
                                                            <p className="text-[10px] text-rose-600 font-bold text-center">{pinValidateError}</p>
                                                        )}
                                                        <div className="flex gap-2">
                                                            <button
                                                                type="button"
                                                                onClick={() => { setPinValidatingAssignmentId(null); setPinValidateError(null); setPinValidateValue(''); }}
                                                                className="flex-1 py-2 text-[10px] font-black text-slate-500 bg-white border border-slate-200 rounded-lg cursor-pointer"
                                                            >
                                                                Cancelar
                                                            </button>
                                                            <button
                                                                type="button"
                                                                disabled={pinValidateLoading}
                                                                onClick={() => handleValidateWithPin(a.id, 'completed', t.title)}
                                                                className="flex-1 py-2 text-[10px] font-black text-white bg-emerald-600 rounded-lg cursor-pointer disabled:opacity-50"
                                                            >
                                                                {pinValidateLoading ? '...' : 'Confirmar'}
                                                            </button>
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <div className="w-full flex flex-col items-center gap-1.5">
                                                        <p className="text-[10px] text-center w-full text-slate-400 font-extrabold uppercase">
                                                            Esperando firma de supervisor
                                                        </p>
                                                        <button
                                                            type="button"
                                                            onClick={() => setPinValidatingAssignmentId(a.id)}
                                                            className="flex items-center gap-1 text-[10px] font-bold text-slate-500 hover:text-blue-600"
                                                        >
                                                            <Lock size={11} /> Validar con PIN de supervisor
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* Caso: Tarea de la Bolsa de Trabajo */}
                                {isFromPool && (
                                    <div className="pt-3 border-t border-slate-100 flex gap-2">
                                        <button
                                            type="button"
                                            onClick={() => {
                                                reserveTaskFromPool(a.id, currentUser.id, globalSimTime);
                                                handleSelectAssignment(null);
                                            }}
                                            className="flex-1 py-3 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-black text-xs rounded-xl border border-indigo-200 cursor-pointer flex items-center justify-center gap-1.5"
                                        >
                                            <Clock size={13} /> Reservar
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                handleStartTaskCooperative(a.id, true);
                                                handleSelectAssignment(null);
                                            }}
                                            className="flex-1 py-3 bg-[#8a2be2] hover:bg-[#7b1fa2] text-white font-black text-xs rounded-xl shadow-md transition-all active:scale-95 border-none cursor-pointer flex items-center justify-center gap-1.5"
                                        >
                                            <Play size={13} className="fill-white" /> Iniciar Ya
                                        </button>
                                    </div>
                                )}

                                {/* Caso: Tarea propia activa / en progreso / pausada */}
                                {!isFromPool && a.userId === currentUser.id && a.status !== 'awaiting_validation' && (
                                    <div className="space-y-3 pt-3 border-t border-slate-100">
                                        {/* Acciones de ejecución */}
                                        {a.status === 'pending' || a.status === 'paused' ? (
                                            <div className="flex gap-2 w-full">
                                                {a.reservedAtMins !== null && a.reservedAtMins !== undefined && (
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            releaseTask(a.id);
                                                            handleSelectAssignment(null);
                                                        }}
                                                        className="flex-1 py-3 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-250/50 font-black text-xs rounded-xl cursor-pointer flex items-center justify-center gap-1.5"
                                                    >
                                                        <XCircle size={13} /> Liberar Bolsa
                                                    </button>
                                                )}
                                                <button
                                                     type="button"
                                                     onClick={() => {
                                                         handleStartTaskCooperative(a.id, false);
                                                     }}
                                                     className="flex-1 py-3 bg-[#8a2be2] hover:bg-[#7b1fa2] text-white font-black text-xs rounded-xl shadow-md transition-all active:scale-95 border-none cursor-pointer flex items-center justify-center gap-2"
                                                 >
                                                     <Play size={13} className="fill-white" /> Iniciar Tarea
                                                 </button>
                                             </div>
                                         ) : isActive ? (
                                             <div className="space-y-3">
                                                 {/* Controles de pausar y completar */}
                                                 <div className="flex gap-2">
                                                     <button
                                                         type="button"
                                                         onClick={() => pauseTask(a.id)}
                                                         className="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-black text-xs rounded-xl border border-slate-200 cursor-pointer flex items-center justify-center gap-2"
                                                     >
                                                         <Pause size={13} /> Pausar Tarea
                                                     </button>
                                                     {t.assistantType === 'ninguno' && (
                                                         <button
                                                             type="button"
                                                             disabled={!requiredStepsDone}
                                                             title={!requiredStepsDone ? 'Completa los pasos obligatorios del manual antes de terminar la tarea' : undefined}
                                                             onClick={() => {
                                                                 completeTask(a.id, globalSimTime);
                                                                 const basePts = t.points || 10;
                                                                 const coins = Number((basePts * 0.1).toFixed(2));
                                                                 setCelebration({ coins, xp: basePts, title: t.title });
                                                                 setWalletData(prev => ({
                                                                     ...prev,
                                                                     balance_coins: Number((prev.balance_coins + coins).toFixed(2)),
                                                                     xp_points: prev.xp_points + basePts
                                                                 }));
                                                                 showToast("¡Tarea completada!", 'success');
                                                                 handleSelectAssignment(null);
                                                             }}
                                                             className="flex-1 py-3 bg-emerald-600 hover:bg-emerald-700 disabled:bg-slate-100 disabled:text-slate-400 text-white font-black text-xs rounded-xl border-none shadow-md cursor-pointer flex items-center justify-center gap-2"
                                                         >
                                                             <Check size={13} /> Completar
                                                         </button>
                                                     )}
                                                 </div>
                                                 {!requiredStepsDone && t.assistantType === 'ninguno' && (
                                                     <p className="text-[10px] text-amber-600 font-bold text-center -mt-1.5">Completa los pasos obligatorios del manual para poder terminar.</p>
                                                 )}

                                                 {/* Mini Asistente de evidencias (solo si no hay manual de pasos — si lo hay, ya se muestra embebido arriba) */}
                                                 {t.assistantType !== 'ninguno' && !assistantShownInSteps && (
                                                     <div className="p-3 bg-indigo-50 border border-indigo-100 rounded-2xl space-y-2.5 text-left">
                                                         <p className="font-black text-indigo-900 flex items-center gap-1 text-[11px]">
                                                             <Bot size={13} className="text-[#8a2be2]" /> Asistente de Evidencias
                                                         </p>
                                                         <p className="text-slate-655 font-bold text-[10.5px] leading-relaxed">{t.assistantPrompt}</p>
                                                         
                                                         {t.assistantType === 'evidencia_foto' && (
                                                             <div className="space-y-2">
                                                                 {!photoDone ? (
                                                                     <button
                                                                         type="button"
                                                                         onClick={() => setCapturingEvidenceFor(a.id)}
                                                                         className="w-full py-2.5 bg-white hover:bg-slate-50 text-indigo-805 rounded-xl border border-indigo-200 text-[10px] font-black flex items-center justify-center gap-1.5 transition-colors cursor-pointer"
                                                                     >
                                                                         <Camera size={13} /> Capturar Foto de Evidencia
                                                                     </button>
                                                                 ) : (
                                                                     <div className="flex items-center gap-2 p-2 bg-emerald-50 border border-emerald-100 rounded-xl text-emerald-800 text-[11px]">
                                                                         <img src={localInput} alt="Evidencia" className="w-7 h-7 rounded object-cover border border-emerald-200 shrink-0" />
                                                                         <Check size={13} className="text-emerald-600 font-black shrink-0" />
                                                                         <span className="font-bold truncate">Evidencia capturada</span>
                                                                         <button type="button" onClick={() => { setPhotoDone(false); setLocalInput(''); }} className="ml-auto text-[9.5px] font-black underline text-slate-500 hover:text-slate-700 border-none bg-transparent cursor-pointer shrink-0">Cambiar</button>
                                                                     </div>
                                                                 )}
                                                             </div>
                                                         )}

                                                         {t.assistantType === 'captura_numero' && (
                                                             <input
                                                                 type="number"
                                                                 value={localInput}
                                                                 onChange={e => setLocalInput(e.target.value)}
                                                                 placeholder="Ingresa la cantidad..."
                                                                 className="w-full p-2 border border-slate-200 bg-white rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none text-xs font-semibold"
                                                             />
                                                         )}

                                                         {t.assistantType === 'texto' && (
                                                             <input
                                                                 type="text"
                                                                 value={localInput}
                                                                 onChange={e => setLocalInput(e.target.value)}
                                                                 placeholder="Escribe reporte de fin de tarea..."
                                                                 className="w-full p-2 border border-slate-200 bg-white rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none text-xs font-semibold"
                                                             />
                                                         )}

                                                         <button
                                                             type="button"
                                                             onClick={async () => {
                                                                 if (!localInput.trim()) return;
                                                                 const ok = await submitTaskEvidence(a.id, t, localInput);
                                                                 if (ok) {
                                                                     addMatrixEvent('✅ Asistente completado', `Tarea "${t.title}" con reporte: ${localInput}`, 'success', currentUser.id);
                                                                     handleSelectAssignment(null);
                                                                 }
                                                             }}
                                                             disabled={!localInput || evidenceSubmitting}
                                                             className="w-full py-2 bg-indigo-650 disabled:bg-slate-100 disabled:text-slate-400 hover:bg-indigo-755 text-white rounded-xl text-[10px] font-black shadow-sm transition-colors cursor-pointer border-none"
                                                         >
                                                             {evidenceSubmitting ? 'Validando…' : 'Enviar Evidencia y Completar'}
                                                         </button>
                                                     </div>
                                                 )}
                                             </div>
                                         ) : null}

                                        {/* Omitir (si es normal y no bloqueante): confirmación + motivo obligatorio,
                                            no un solo clic — y ya notifica al backend (ver omitAssignment). */}
                                        {t.priority !== 'bloqueante' && (
                                            omittingAssignmentId === a.id ? (
                                                <div className="p-3 bg-rose-50 border border-rose-100 rounded-xl space-y-2.5 animate-in slide-in-from-top-2 duration-150">
                                                    <p className="text-xs font-black text-rose-800">¿Por qué se omite esta tarea?</p>
                                                    <textarea
                                                        value={omitReason}
                                                        onChange={e => setOmitReason(e.target.value)}
                                                        placeholder="Ej: no había insumos suficientes en el área..."
                                                        rows={2}
                                                        className="w-full p-2.5 border border-slate-200 bg-white rounded-lg outline-none text-xs font-semibold focus:ring-2 focus:ring-rose-500"
                                                    />
                                                    <div className="flex gap-2 justify-end">
                                                        <button
                                                            type="button"
                                                            onClick={() => { setOmittingAssignmentId(null); setOmitReason(''); }}
                                                            className="px-3 py-1.5 bg-white text-slate-500 rounded-lg text-[10px] font-black border border-slate-200 cursor-pointer"
                                                        >
                                                            Cancelar
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={!omitReason.trim()}
                                                            onClick={() => {
                                                                omitAssignment(a.id, omitReason.trim());
                                                                showToast("Tarea omitida", 'info');
                                                                handleSelectAssignment(null);
                                                            }}
                                                            className="px-3 py-1.5 bg-rose-600 hover:bg-rose-700 disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-lg text-[10px] font-black border-none cursor-pointer"
                                                        >
                                                            Confirmar y omitir
                                                        </button>
                                                    </div>
                                                </div>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() => setOmittingAssignmentId(a.id)}
                                                    className="w-full py-1.5 bg-transparent text-slate-400 hover:text-rose-600 font-bold text-[10.5px] cursor-pointer text-center border-none"
                                                >
                                                    Omitir esta tarea
                                                </button>
                                            )
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                );
            })()}

            {/* Modal Celebrativo de Monedas Ganadas (+XP & +Coins) */}
            {celebration && (
                <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[120] flex items-center justify-center p-4 animate-in fade-in duration-200">
                    <div className="bg-white rounded-[2.5rem] p-7 max-w-xs w-full shadow-2xl text-center border border-amber-200 relative animate-in zoom-in-95 duration-300">
                        <div className="w-20 h-20 bg-gradient-to-tr from-amber-400 to-amber-500 rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg shadow-amber-500/30 animate-bounce">
                            <span className="text-4xl">🪙</span>
                        </div>
                        <h3 className="text-xl font-black text-slate-900 tracking-tight mb-1">¡Recompensa Obtenida! 🎉</h3>
                        <p className="text-xs font-bold text-slate-500 mb-4 px-2 truncate">{celebration.title}</p>
                        
                        <div className="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-5 flex items-center justify-around">
                            <div className="text-center">
                                <span className="text-xs font-black text-amber-700 block uppercase tracking-wider">Monedas</span>
                                <span className="text-2xl font-black text-amber-600">+${celebration.coins.toFixed(2)}</span>
                            </div>
                            <div className="h-8 w-px bg-amber-200"></div>
                            <div className="text-center">
                                <span className="text-xs font-black text-indigo-700 block uppercase tracking-wider">Experiencia</span>
                                <span className="text-2xl font-black text-indigo-600">+{celebration.xp} XP</span>
                            </div>
                        </div>

                        <button
                            type="button"
                            onClick={() => setCelebration(null)}
                            className="w-full py-3.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white font-black text-xs uppercase tracking-wider rounded-2xl shadow-md border-none cursor-pointer active:scale-95 transition-all"
                        >
                            ¡Excelente! Continuar
                        </button>
                    </div>
                </div>
            )}

            {/* §35: captura real de cámara para evidencia_foto — reemplaza el stub anterior */}
            {capturingEvidenceFor && (
                <TaskEvidenceCapture
                    title={(() => {
                        const capturingAssignment = assignments.find(a => a.id === capturingEvidenceFor);
                        const capturingTask = capturingAssignment ? tasks.find(t => t.id === capturingAssignment.taskId) : null;
                        return capturingTask?.assistantPrompt || capturingTask?.title || 'Evidencia Fotográfica de la Tarea';
                    })()}
                    submitting={evidenceSubmitting}
                    onCancel={() => setCapturingEvidenceFor(null)}
                    onCapture={(dataUrl) => {
                        setPhotoDone(true);
                        setLocalInput(dataUrl);
                        setCapturingEvidenceFor(null);
                    }}
                />
            )}
        </div>
    );
}
