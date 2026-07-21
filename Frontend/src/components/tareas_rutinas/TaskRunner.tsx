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

// Componente para tarjeta de tarea unificada (Ficha de Tarea)
export interface FichaTareaProps {
    assignment: TaskAssignment;
    task: Task;
    currentUser: any;
    globalSimTime: number;
    onSelect: () => void;
    onPlayPause: (e: React.MouseEvent) => void;
    getRoleName: (id?: number) => string;
}

export function FichaTarea({
    assignment,
    task,
    currentUser,
    globalSimTime,
    onSelect,
    onPlayPause,
    getRoleName
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
            className={`border rounded-2xl p-4 shadow-sm hover:shadow-md transition-all duration-300 cursor-pointer active:scale-[0.99] text-left flex flex-col gap-3.5 relative overflow-hidden bg-gradient-to-br from-white to-slate-50/50 dark:from-slate-900 dark:to-slate-900/50 border-slate-200/80 dark:border-slate-800/80 hover:-translate-y-0.5 ${cardClass}`}
        >
            {/* Indicador lateral de estado (Estilo gradiente de alta fidelidad) */}
            <div className={`absolute top-0 left-0 w-1.5 h-full bg-gradient-to-b ${
                isOvertime && assignment.status !== 'completed' && assignment.status !== 'omitted' ? 'from-rose-500 to-red-600' :
                assignment.status === 'awaiting_validation' ? 'from-amber-400 to-yellow-500' :
                isFromPool ? 'from-sky-400 to-blue-500' :
                assignment.status === 'in_progress' ? 'from-emerald-400 to-teal-500 animate-pulse' :
                assignment.status === 'paused' ? 'from-slate-400 to-slate-550' : 'from-indigo-400 to-violet-500'
            }`}></div>

            <div className="pl-1.5 flex flex-col justify-between flex-grow gap-2.5">
                {/* Fila 1: Badges y etiquetas */}
                <div className="flex justify-between items-start gap-2">
                    <span className={`text-[8.5px] font-black uppercase px-2 py-0.5 rounded-lg border tracking-wider shadow-xs ${badgeClass}`}>
                        {badgeText}
                    </span>
                    <div className="flex flex-wrap items-center justify-end gap-1 shrink-0">
                        {task.priority === 'bloqueante' && (
                            <span className="text-[7.5px] font-black uppercase px-1.5 py-0.5 rounded-md bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20 shadow-xs">
                                ⚠️ Obligatoria
                            </span>
                        )}
                        {task.scheduledTime && (
                            <span className="text-[7.5px] font-black px-1.5 py-0.5 rounded-md border bg-sky-500/10 text-sky-600 dark:text-sky-400 border-sky-500/20 flex items-center gap-0.5 shrink-0">
                                ⏰ {task.scheduledTime}
                            </span>
                        )}
                        {isFromPool ? (
                            <span className="text-[7.5px] font-black uppercase px-1.5 py-0.5 rounded-md border bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20 shadow-xs animate-pulse">
                                ✨ Bolsa
                            </span>
                        ) : assignment.assignedFromRoutineId ? (
                            <span className="text-[7.5px] font-black uppercase px-1.5 py-0.5 rounded-md border bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border-slate-200 dark:border-slate-700">
                                📅 Rutina
                            </span>
                        ) : (
                            <span className="text-[7.5px] font-black uppercase px-1.5 py-0.5 rounded-md border bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border-indigo-500/20">
                                📌 Directa
                            </span>
                        )}
                    </div>
                </div>

                {/* Fila 2: Título y descripción breve si existe */}
                <div>
                    <h4 className="font-black text-xs text-slate-800 dark:text-slate-100 leading-tight">
                        {task.title}
                    </h4>
                    {task.description && (
                        <p className="text-[8.5px] text-slate-400 dark:text-slate-500 mt-1 line-clamp-1 font-semibold leading-none">
                            {task.description}
                        </p>
                    )}
                </div>

                {/* Fila 3: Colaborador, Barra de Progreso y Tiempo */}
                <div className="flex items-center justify-between gap-3 mt-1.5 text-[9.5px] text-slate-500 font-bold border-t border-slate-100 dark:border-slate-800/80 pt-2.5">
                    {/* Colaborador */}
                    <div className="flex items-center gap-1.5 min-w-0">
                        <div className="w-5 h-5 rounded-full bg-slate-100 dark:bg-slate-850 flex items-center justify-center text-[10px] shrink-0 border border-slate-200/55 dark:border-slate-750">
                            👤
                        </div>
                        <span className="truncate text-slate-700 dark:text-slate-355 font-extrabold text-[8.5px]">
                            {collaboratorName}
                        </span>
                    </div>

                    {/* Lado derecho: Progreso y Controles */}
                    <div className="flex items-center gap-2 shrink-0">
                        <div className="flex items-center gap-1.5">
                            {/* Barra de progreso slim y pulida */}
                            <div className="w-16 bg-slate-100 dark:bg-slate-800 h-2 rounded-full overflow-hidden relative border border-slate-200/40 dark:border-slate-700/40">
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
                            {/* Tiempo */}
                            <span className="text-[8.5px] font-black text-slate-750 dark:text-slate-255 shrink-0">
                                {timeDisplay}
                            </span>
                        </div>

                        {/* Botón rápido Play / Pausa */}
                        {showPlayPause && (
                            <button
                                onClick={onPlayPause}
                                className={`w-5.5 h-5.5 rounded-full flex items-center justify-center border-none cursor-pointer transition-all hover:scale-110 active:scale-95 shadow-sm text-white ${
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
        </div>
    );
}

// Componente Principal TaskRunner
export function TaskRunner({ currentUser, onBack, hideHeader }: { currentUser: any, onBack: () => void, hideHeader?: boolean }) {
    const { 
        tasks, routines, assignments, grabTaskFromPool, startTask, 
        pauseTask, completeTask, omitAssignment, createDynamicTask,
        validateTaskAssignment, reserveTaskFromPool, releaseTask
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
    const [searchQuery, setSearchQuery] = useState('');
    const [isSearchOpen, setIsSearchOpen] = useState(false);
    const [selectedAssignmentId, setSelectedAssignmentId] = useState<string | null>(null);
    const [showHistoryModal, setShowHistoryModal] = useState(false);
    const [localInput, setLocalInput] = useState('');
    const [photoDone, setPhotoDone] = useState(false);
    
    // Modal de creación de tareas
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [newTitle, setNewTitle] = useState('');
    const [newDesc, setNewDesc] = useState('');
    const [newMins, setNewMins] = useState(15);
    const [newPriority, setNewPriority] = useState<'normal' | 'bloqueante'>('normal');
    const [newTargetRole, setNewTargetRole] = useState<number>(0);

    // AI Assistant state
    const [aiInput, setAiInput] = useState('');

    // FAB menu state
    const [showFabMenu, setShowFabMenu] = useState(false);
    const fabMenuRef = useRef<HTMLDivElement>(null);

    // Estado local para feedback de rechazo en supervisión
    const [rejectingAssignmentId, setRejectingAssignmentId] = useState<string | null>(null);
    const [rejectFeedback, setRejectFeedback] = useState('');

    // Sistema de notificaciones toast interno
    const [toast, setToast] = useState<{ message: string, type: 'info' | 'success' | 'warning' } | null>(null);

    const showToast = (message: string, type: 'info' | 'success' | 'warning') => {
        setToast({ message, type });
    };

    const [completedSteps, setCompletedSteps] = useState<Record<string, boolean>>({});
    const [activeStepIndex, setActiveStepIndex] = useState(0);

    const handleSelectAssignment = (id: string | null) => {
        setSelectedAssignmentId(id);
        setLocalInput('');
        setPhotoDone(false);
        setRejectingAssignmentId(null);
        setRejectFeedback('');
        setCompletedSteps({});
        setActiveStepIndex(0);
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

    // El listado actual a mostrar
    const displayedAssignments = (() => {
        switch (filterTab) {
            case 'firma_pendiente':
                return awaitingValidationFiltered;
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
                return uniqueCombined.sort((a, b) => {
                    if (a.status === 'in_progress' && b.status !== 'in_progress') return -1;
                    if (b.status === 'in_progress' && a.status !== 'in_progress') return 1;

                    if (a.status === 'paused' && b.status !== 'paused') return -1;
                    if (b.status === 'paused' && a.status !== 'paused') return 1;

                    const aIsFree = a.userId === null;
                    const bIsFree = b.userId === null;
                    if (!aIsFree && bIsFree) return -1;
                    if (aIsFree && !bIsFree) return 1;

                    return 0;
                });
            }
            case 'bolsa':
                return puestoAssignmentsFiltered;
            case 'todos':
            default: {
                const combined = [
                    ...awaitingValidationFiltered,
                    ...activeAssignmentsFiltered,
                    ...puestoAssignmentsFiltered
                ];
                return combined.filter((val, index, self) =>
                    self.findIndex(a => a.id === val.id) === index
                );
            }
        }
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

        createDynamicTask(newTitle, newTargetRole, newMins, newPriority);
        showToast(`Tarea "${newTitle}" creada con éxito`, 'success');
        
        // Limpiar form
        setNewTitle('');
        setNewDesc('');
        setNewMins(15);
        setNewPriority('normal');
        setAiInput('');
        setShowCreateModal(false);
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
    const handleStartTaskCooperative = (assignmentId: string, isFromPool: boolean) => {
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

    return (
        <div className="flex flex-col h-full bg-[#f8f9fe] text-slate-800 font-sans px-2.5 pb-4 pt-1.5 select-none relative overflow-hidden">
            {/* Tira de KPIs             {/* Fila fija de 3 botones principales */}
            <div className="grid grid-cols-3 gap-2 mb-3 shrink-0 select-none">
                {/* Botón 1: Todas (Muestra todas, con badge de completadas/total) */}
                <button
                    type="button"
                    onClick={() => setFilterTab('todos')}
                    className={`flex flex-col items-center justify-center py-2.5 px-1 rounded-2xl border transition-all cursor-pointer relative ${
                        filterTab === 'todos'
                            ? 'text-white shadow-md scale-[1.02]'
                            : 'bg-white text-slate-500 border-slate-200/85 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-800'
                    }`}
                    style={filterTab === 'todos' ? { backgroundColor: activeColor.hex, borderColor: activeColor.hex } : {}}
                >
                    <ClipboardList size={18} className={filterTab === 'todos' ? 'text-white' : 'text-slate-400'} />
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
                    className={`flex flex-col items-center justify-center py-2.5 px-1 rounded-2xl border transition-all cursor-pointer relative ${
                        filterTab === 'mis_tareas'
                            ? 'text-white shadow-md scale-[1.02]'
                            : 'bg-white text-slate-500 border-slate-200/85 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-800'
                    }`}
                    style={filterTab === 'mis_tareas' ? { backgroundColor: activeColor.hex, borderColor: activeColor.hex } : {}}
                >
                    <User size={18} className={filterTab === 'mis_tareas' ? 'text-white' : 'text-slate-400'} />
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

                {/* Botón 3: Buscar */}
                <button
                    type="button"
                    onClick={() => setIsSearchOpen(!isSearchOpen)}
                    className={`flex flex-col items-center justify-center py-2.5 px-1 rounded-2xl border transition-all cursor-pointer relative ${
                        isSearchOpen
                            ? 'text-white shadow-md scale-[1.02]'
                            : 'bg-white text-slate-500 border-slate-200/85 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-800'
                    }`}
                    style={isSearchOpen ? { backgroundColor: activeColor.hex, borderColor: activeColor.hex } : {}}
                >
                    <Search size={18} className={isSearchOpen ? 'text-white' : 'text-slate-400'} />
                    <span className="text-[9px] font-black uppercase mt-1">Buscar</span>
                </button>
            </div>

            {/* Buscador Deslizable Colapsable con Altura y Opacidad Animadas */}
            <div 
                className={`transition-all duration-300 ease-in-out overflow-hidden flex items-center gap-2 shrink-0 ${
                    isSearchOpen 
                        ? 'h-12 opacity-100 mb-3 scale-y-100 origin-top' 
                        : 'h-0 opacity-0 mb-0 scale-y-0 origin-top pointer-events-none'
                }`}
            >
                <div className="relative flex-1">
                    <input 
                        type="text" 
                        placeholder="Buscar tareas por título..."
                        value={searchQuery}
                        onChange={e => setSearchQuery(e.target.value)}
                        className="w-full pl-9 pr-8 py-2.5 border border-slate-200 bg-white rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none text-xs font-semibold shadow-sm transition-all"
                    />
                    <div className="absolute left-3 top-3 text-slate-400">
                        <Search size={14} />
                    </div>
                    {searchQuery && (
                        <button 
                            onClick={() => setSearchQuery('')} 
                            className="absolute right-2.5 top-2.5 text-slate-400 hover:text-slate-600 bg-transparent border-none cursor-pointer"
                        >
                            <X size={14} />
                        </button>
                    )}
                </div>
                <button 
                    onClick={() => {
                        setSearchQuery('');
                    }}
                    className="bg-slate-100 hover:bg-slate-200 text-slate-600 font-extrabold text-[10px] uppercase px-3.5 py-2.5 rounded-xl border border-slate-200/50 cursor-pointer active:scale-95 transition-all select-none shrink-0"
                >
                    <span>Limpiar</span>
                </button>
            </div>

            {/* Listado principal */}
            <div 
                className="flex-1 overflow-y-auto pb-24 scrollbar-none"
                style={{ scrollbarWidth: 'none', msOverflowStyle: 'none' }}
            >
                <div className="flex justify-between items-center mb-3">
                    <h3 className="text-xs font-black uppercase text-slate-400 tracking-wider">
                        {filterTab === 'todos' ? 'Lista Unificada de Tareas' :
                         filterTab === 'firma_pendiente' ? 'Tareas esperando validación' :
                         filterTab === 'mis_tareas' ? 'Mis Tareas y Bolsa' :
                         'Bolsa de Trabajo'}
                    </h3>
                    <span className="text-[10px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full">
                        {displayedAssignments.length} {displayedAssignments.length === 1 ? 'tarea' : 'tareas'}
                    </span>
                </div>

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
                        {displayedAssignments.map(a => {
                            const t = tasks.find(tsk => tsk.id === a.taskId);
                            if (!t) return null;

                            return (
                                <FichaTarea 
                                    key={a.id}
                                    assignment={a}
                                    task={t}
                                    currentUser={currentUser}
                                    globalSimTime={globalSimTime}
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

            {/* Botón Flotante (FAB) */}
            <div className="fixed bottom-24 right-6 z-40" ref={fabMenuRef}>
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
                            <div className="space-y-4 flex-1">
                                {/* Metadata Row */}
                                <div className={`grid ${t.scheduledTime ? 'grid-cols-3' : 'grid-cols-2'} gap-3 bg-slate-55 p-3 rounded-2xl border border-slate-100/50 text-xs text-slate-605 font-bold`}>
                                    <div className="space-y-1">
                                        <p className="text-[9px] uppercase tracking-wider text-slate-400">Responsable</p>
                                        <p className="text-slate-800 font-black flex items-center gap-1">
                                            <User size={13} className="text-slate-400" />
                                            {isFromPool ? 'Bolsa de Trabajo' : (worker?.name || `Usuario #${a.userId}`)}
                                        </p>
                                    </div>
                                    <div className="space-y-1">
                                        <p className="text-[9px] uppercase tracking-wider text-slate-400">Tiempo</p>
                                        <p className="text-slate-800 font-black flex items-center gap-1">
                                            <Clock size={13} className="text-slate-400" />
                                            {a.status === 'pending' ? `${t.estimatedMins} min est.` : `${elapsed} min real`}
                                        </p>
                                    </div>
                                    {t.scheduledTime && (
                                        <div className="space-y-1">
                                            <p className="text-[9px] uppercase tracking-wider text-slate-400">Programado</p>
                                            <p className="text-slate-800 font-black flex items-center gap-1">
                                                <span className="text-slate-400 font-black">⏰</span>
                                                {t.scheduledTime} hrs
                                            </p>
                                        </div>
                                    )}
                                </div>

                                {t.description && (
                                    <div className="space-y-1">
                                        <h5 className="text-[10px] font-black uppercase text-slate-400 tracking-wider">Descripción</h5>
                                        <p className="text-xs text-slate-600 font-semibold leading-relaxed bg-slate-50/40 p-2.5 rounded-xl border border-slate-100">
                                            {t.description}
                                        </p>
                                    </div>
                                )}

                                {/* Stepper Visual para Manual de Procedimiento */}
                                {t.procedureSteps && t.procedureSteps.length > 0 ? (
                                    <div className="space-y-3 bg-slate-50/50 p-4 rounded-2xl border border-slate-150/60 text-left">
                                        <h5 className="text-[10px] font-black uppercase text-indigo-650 tracking-wider mb-3 flex items-center gap-1.5">
                                            <span>📖</span> Manual de Ejecución Paso a Paso
                                        </h5>
                                        
                                        <div className="relative pl-6 space-y-4">
                                            {/* Linea vertical de conexión */}
                                            <div className="absolute left-[9px] top-2 bottom-2 w-0.5 bg-slate-200"></div>

                                            {t.procedureSteps.map((step, idx) => {
                                                const stepKey = `${step.step_number}_${step.title}`;
                                                const isStepCompleted = completedSteps[stepKey] || false;
                                                const isCurrent = idx === activeStepIndex;
                                                const isLocked = idx > activeStepIndex;

                                                return (
                                                    <div key={stepKey} className="relative flex gap-3.5 items-start">
                                                        {/* Círculo indicador del paso */}
                                                        <button
                                                            type="button"
                                                            disabled={isLocked || isStepCompleted}
                                                            onClick={() => {
                                                                setCompletedSteps(prev => ({ ...prev, [stepKey]: true }));
                                                                if (idx === activeStepIndex) {
                                                                    setActiveStepIndex(idx + 1);
                                                                }
                                                            }}
                                                            className={`absolute -left-6 w-5 h-5 rounded-full border flex items-center justify-center text-[10px] font-black transition-all cursor-pointer ${
                                                                isStepCompleted
                                                                    ? 'bg-emerald-500 border-emerald-500 text-white'
                                                                    : isCurrent
                                                                    ? 'bg-indigo-600 border-indigo-600 text-white shadow-md animate-pulse'
                                                                    : 'bg-white text-slate-400 border-slate-200'
                                                            }`}
                                                        >
                                                            {isStepCompleted ? '✓' : step.step_number}
                                                        </button>

                                                        <div className="flex-1 min-w-0">
                                                            <p className={`text-xs font-extrabold ${isCurrent ? 'text-indigo-900' : isLocked ? 'text-slate-400 font-bold' : 'text-slate-400 line-through'}`}>
                                                                {step.title}
                                                            </p>
                                                            {isCurrent && step.detailed_instruction && (
                                                                <p className="text-[10.5px] text-slate-505 leading-relaxed mt-1 font-medium bg-white p-2.5 rounded-lg border border-slate-100 shadow-xs">
                                                                    {step.detailed_instruction}
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ) : (
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
                                                ) : (
                                                    <p className="text-[10px] text-center w-full text-slate-400 font-extrabold uppercase">
                                                        Esperando firma de supervisor
                                                    </p>
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
                                                            onClick={() => {
                                                                completeTask(a.id, globalSimTime);
                                                                showToast("¡Tarea completada!", 'success');
                                                                handleSelectAssignment(null);
                                                            }}
                                                            className="flex-1 py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-black text-xs rounded-xl border-none shadow-md cursor-pointer flex items-center justify-center gap-2"
                                                        >
                                                            <Check size={13} /> Completar
                                                        </button>
                                                    )}
                                                </div>

                                                {/* Mini Asistente de evidencias */}
                                                {t.assistantType !== 'ninguno' && (
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
                                                                        onClick={() => { setPhotoDone(true); setLocalInput('evidencia_checador_foto.jpg'); }}
                                                                        className="w-full py-2.5 bg-white hover:bg-slate-50 text-indigo-805 rounded-xl border border-indigo-200 text-[10px] font-black flex items-center justify-center gap-1.5 transition-colors cursor-pointer"
                                                                    >
                                                                        <Camera size={13} /> Capturar Foto de Evidencia
                                                                    </button>
                                                                ) : (
                                                                    <div className="flex items-center gap-2 p-2 bg-emerald-50 border border-emerald-100 rounded-xl text-emerald-800 text-[11px]">
                                                                        <Check size={13} className="text-emerald-600 font-black" />
                                                                        <span className="font-bold truncate">evidencia_checador_foto.jpg</span>
                                                                        <button type="button" onClick={() => { setPhotoDone(false); setLocalInput(''); }} className="ml-auto text-[9.5px] font-black underline text-slate-500 hover:text-slate-700 border-none bg-transparent cursor-pointer">Cambiar</button>
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
                                                            onClick={() => {
                                                                if (!localInput.trim()) return;
                                                                completeTask(a.id, globalSimTime, localInput);
                                                                showToast("Evidencia guardada y completada", 'success');
                                                                addMatrixEvent('✅ Asistente completado', `Tarea "${t.title}" con reporte: ${localInput}`, 'success', currentUser.id);
                                                                handleSelectAssignment(null);
                                                            }}
                                                            disabled={!localInput}
                                                            className="w-full py-2 bg-indigo-650 disabled:bg-slate-100 disabled:text-slate-400 hover:bg-indigo-755 text-white rounded-xl text-[10px] font-black shadow-sm transition-colors cursor-pointer border-none"
                                                        >
                                                            Enviar Evidencia y Completar
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        ) : null}

                                        {/* Botón de Omitir (si es normal y no bloqueante) */}
                                        {t.priority !== 'bloqueante' && (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    omitAssignment(a.id);
                                                    showToast("Tarea omitida", 'info');
                                                    handleSelectAssignment(null);
                                                }}
                                                className="w-full py-2.5 bg-transparent hover:bg-rose-50 text-rose-600 font-extrabold text-[10px] uppercase tracking-wider rounded-xl border border-rose-200/40 cursor-pointer flex items-center justify-center gap-1 transition-all"
                                            >
                                                <Trash2 size={12} /> Omitir esta Tarea
                                            </button>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                );
            })()}
        </div>
    );
}
