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
            className={`border rounded-2xl p-3.5 shadow-xs hover:shadow-md transition-all duration-200 cursor-pointer active:scale-[0.99] text-left flex flex-col gap-2 relative overflow-hidden ${cardClass}`}
        >
            {/* Indicador lateral de estado */}
            <div className={`absolute top-0 left-0 w-1.5 h-full ${
                isOvertime && assignment.status !== 'completed' && assignment.status !== 'omitted' ? 'bg-rose-500' :
                assignment.status === 'awaiting_validation' ? 'bg-amber-400' :
                isFromPool ? 'bg-sky-400' :
                assignment.status === 'in_progress' ? 'bg-emerald-500' :
                assignment.status === 'paused' ? 'bg-slate-400' : 'bg-indigo-500'
            }`}></div>

            <div className="pl-1.5">
                {/* Fila 1: Badge de Estado y Prioridad / Origen */}
                <div className="flex justify-between items-center gap-2">
                    <span className={`text-[8.5px] font-black uppercase px-2 py-0.5 rounded-md border ${badgeClass}`}>
                        {badgeText}
                    </span>
                    <div className="flex items-center gap-1.5 shrink-0">
                        {task.priority === 'bloqueante' && (
                            <span className="text-[8px] font-black uppercase px-1.5 py-0.5 rounded bg-rose-50 text-rose-600 border border-rose-100/60">
                                ⚠️ Obligatoria
                            </span>
                        )}
                        {/* Badge de Origen de la Tarea */}
                        {isFromPool ? (
                            <span className="text-[8px] font-black uppercase px-1.5 py-0.5 rounded-md border bg-violet-50 text-violet-700 border-violet-200/60 shadow-xs animate-pulse">
                                ✨ Bolsa (+Bono)
                            </span>
                        ) : assignment.reservedAtMins !== null && assignment.reservedAtMins !== undefined && assignment.status === 'pending' ? (
                            (() => {
                                const limit = task.priority === 'bloqueante' ? 5 : 15;
                                const remaining = Math.max(0, (assignment.reservedAtMins + limit) - globalSimTime);
                                return (
                                    <span className="text-[8px] font-black uppercase px-1.5 py-0.5 rounded-md border bg-amber-50 text-amber-700 border-amber-300 shadow-xs animate-pulse">
                                        ⏳ Libera en {remaining} min
                                    </span>
                                );
                            })()
                        ) : assignment.assignedFromRoutineId ? (
                            <span className="text-[8px] font-black uppercase px-1.5 py-0.5 rounded-md border bg-slate-50 text-slate-650 border-slate-200">
                                📅 Rutina
                            </span>
                        ) : (
                            <span className="text-[8px] font-black uppercase px-1.5 py-0.5 rounded-md border bg-indigo-50/50 text-indigo-755 border-indigo-200/50">
                                📌 Directa
                            </span>
                        )}
                    </div>
                </div>

                {/* Fila 2: Título de la tarea */}
                <h4 className="font-extrabold text-xs text-slate-800 mt-2 line-clamp-2 leading-tight">
                    {task.title}
                </h4>

                {/* Fila 3: Colaborador, Barra de Progreso y Tiempo en la misma línea */}
                <div className="flex items-center justify-between gap-3 mt-3 text-[10px] text-slate-500 font-bold">
                    {/* Colaborador */}
                    <div className="flex items-center gap-1.5 min-w-0">
                        <User size={12} className="text-slate-450 shrink-0" />
                        <span className="truncate text-slate-700 font-black">
                            {collaboratorName}
                        </span>
                    </div>

                    {/* Lado derecho: Progreso, Tiempo y Controles */}
                    <div className="flex items-center gap-2 shrink-0">
                        {/* Progreso y Tiempo (siempre visible) */}
                        <div className="flex items-center gap-1.5">
                            {/* Barra de progreso slim */}
                            <div className="w-20 bg-slate-100 h-2.5 rounded-lg overflow-hidden relative border border-slate-200/50">
                                <div 
                                    className={`h-full absolute left-0 top-0 transition-all duration-300 ${
                                        assignment.status === 'completed' ? 'bg-teal-500' :
                                        assignment.status === 'omitted' ? 'bg-slate-350' :
                                        isOvertime ? 'bg-rose-500/85' : 
                                        assignment.status === 'in_progress' ? 'bg-emerald-500/80' : 'bg-[#8a2be2]/40'
                                    }`}
                                    style={{ width: `${percent}%` }}
                                ></div>
                            </div>
                            {/* Tiempo */}
                            <span className="text-[9px] font-black text-slate-700 shrink-0">
                                {timeDisplay}
                            </span>
                        </div>

                        {/* Botón rápido Play / Pausa */}
                        {showPlayPause && (
                            <button
                                onClick={onPlayPause}
                                className={`w-6 h-6 rounded-full flex items-center justify-center border-none cursor-pointer transition-all hover:scale-110 active:scale-95 shadow-sm text-white ${
                                    assignment.status === 'in_progress'
                                        ? 'bg-amber-500 hover:bg-amber-600'
                                        : 'bg-[#8a2be2] hover:bg-[#7b1fa2]'
                                }`}
                                title={assignment.status === 'in_progress' ? 'Pausar Tarea' : 'Iniciar Tarea'}
                            >
                                {assignment.status === 'in_progress' ? (
                                    <Pause size={10} className="fill-white text-white" />
                                ) : (
                                    <Play size={10} className="fill-white text-white translate-x-0.5" />
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
    const { globalSimTime, addMatrixEvent, globalRoles, globalUsers } = useAppStore(); // Filtros y pestañas locales
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

    const handleSelectAssignment = (id: string | null) => {
        setSelectedAssignmentId(id);
        setLocalInput('');
        setPhotoDone(false);
        setRejectingAssignmentId(null);
        setRejectFeedback('');
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

    // Determinar si tiene rol de supervisor
    const isSupervisor = currentUser?.role?.toLowerCase().includes('superv') || 
                         currentUser?.role?.toLowerCase().includes('geren') || 
                         currentUser?.role?.toLowerCase().includes('admin');

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

    // 4. Historial (Tareas completadas u omitidas por el colaborador hoy)
    const historyAssignmentsFiltered = filterBySearch(assignments.filter(a => 
        a.userId === currentUser.id && 
        ['completed', 'awaiting_validation', 'omitted'].includes(a.status)
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
        <div className="flex flex-col h-full bg-[#f8f9fe] text-slate-800 font-sans p-4 select-none relative overflow-y-auto">
            {/* Fila fija de botones (Grid de navegación intacto) */}
            <div className={`grid ${(isSupervisor || awaitingValidationFiltered.length > 0) ? 'grid-cols-4' : 'grid-cols-3'} gap-1.5 mb-3 shrink-0 select-none`}>
                {/* Botón 1: Todas */}
                <button
                    type="button"
                    onClick={() => setFilterTab('todos')}
                    className={`flex flex-col items-center justify-center py-2 px-1 rounded-2xl border transition-all cursor-pointer relative ${
                        filterTab === 'todos'
                            ? 'bg-[#8a2be2] text-white border-[#8a2be2] shadow-md scale-[1.02]'
                            : 'bg-white text-slate-500 border-slate-200/85 hover:bg-slate-50'
                    }`}
                >
                    <ClipboardList size={18} className={filterTab === 'todos' ? 'text-white' : 'text-slate-400'} />
                    <span className="text-[9px] font-black uppercase mt-1">Todas</span>
                    <span className={`absolute -top-1 -right-1 text-[8px] font-black px-1.5 py-0.2 rounded-full shadow-xs border ${
                        filterTab === 'todos' 
                            ? 'bg-white text-[#8a2be2] border-white' 
                            : 'bg-slate-100 text-slate-600 border-slate-200'
                    }`}>
                        {awaitingValidationFiltered.length + activeAssignmentsFiltered.length + puestoAssignmentsFiltered.length}
                    </span>
                </button>

                {/* Botón 2: Por Validar */}
                {(isSupervisor || awaitingValidationFiltered.length > 0) && (
                    <button
                        type="button"
                        onClick={() => setFilterTab('firma_pendiente')}
                        className={`flex flex-col items-center justify-center py-2 px-1 rounded-2xl border transition-all cursor-pointer relative ${
                            filterTab === 'firma_pendiente'
                                ? 'bg-amber-500 text-white border-amber-500 shadow-md scale-[1.02]'
                                : 'bg-white text-slate-500 border-slate-200/85 hover:bg-slate-50'
                        }`}
                    >
                        <FileCheck size={18} className={filterTab === 'firma_pendiente' ? 'text-white' : 'text-slate-400'} />
                        <span className="text-[8.5px] font-black uppercase mt-1 leading-none text-center">Por Validar</span>
                        {awaitingValidationFiltered.length > 0 && (
                            <span className={`absolute -top-1 -right-1 text-[8px] font-black px-1.5 py-0.2 rounded-full shadow-xs border animate-pulse ${
                                filterTab === 'firma_pendiente'
                                    ? 'bg-white text-amber-700 border-white'
                                    : 'bg-rose-500 text-white border-rose-450'
                            }`}>
                                {awaitingValidationFiltered.length}
                            </span>
                        )}
                    </button>
                )}

                {/* Botón 3: Mis Tareas */}
                <button
                    type="button"
                    onClick={() => setFilterTab('mis_tareas')}
                    className={`flex flex-col items-center justify-center py-2 px-1 rounded-2xl border transition-all cursor-pointer relative ${
                        filterTab === 'mis_tareas'
                            ? 'bg-[#8a2be2] text-white border-[#8a2be2] shadow-md scale-[1.02]'
                            : 'bg-white text-slate-500 border-slate-200/85 hover:bg-slate-50'
                    }`}
                >
                    <User size={18} className={filterTab === 'mis_tareas' ? 'text-white' : 'text-slate-400'} />
                    <span className="text-[9px] font-black uppercase mt-1 leading-none text-center">Mis Tareas</span>
                    {(activeAssignmentsFiltered.length + puestoAssignmentsFiltered.length) > 0 && (
                        <span className={`absolute -top-1 -right-1 text-[8px] font-black px-1.5 py-0.2 rounded-full shadow-xs border ${
                            filterTab === 'mis_tareas'
                                ? 'bg-white text-[#8a2be2] border-white'
                                : 'bg-slate-100 text-slate-600 border-slate-200'
                        }`}>
                            {activeAssignmentsFiltered.length + puestoAssignmentsFiltered.length}
                        </span>
                    )}
                </button>

                {/* Botón 4: Buscar */}
                <button
                    type="button"
                    onClick={() => setIsSearchOpen(!isSearchOpen)}
                    className={`flex flex-col items-center justify-center py-2 px-1 rounded-2xl border transition-all cursor-pointer relative ${
                        isSearchOpen
                            ? 'bg-violet-600 text-white border-violet-600 shadow-md scale-[1.02]'
                            : 'bg-white text-slate-500 border-slate-200/85 hover:bg-slate-50'
                    }`}
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
                        className="w-full pl-9 pr-8 py-2.5 border border-slate-200 bg-white rounded-xl focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 outline-none text-xs font-semibold shadow-sm transition-all"
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
            <div className="flex-1 overflow-y-auto pb-28 custom-scrollbar pr-1 -mr-1">
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
                        <Coffee size={36} className="text-indigo-400 mb-2 animate-bounce" />
                        <p className="text-sm font-black text-slate-700">¡Tablero limpio!</p>
                        <p className="text-xs text-slate-500 mt-1">
                            No se encontraron tareas en esta sección por ahora.
                        </p>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5">
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
                            className="w-full text-left px-3 py-2 rounded-xl text-xs font-black text-[#8a2be2] hover:bg-violet-50 border-none bg-transparent cursor-pointer flex items-center gap-2"
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
                        <div className="bg-violet-50/50 border border-violet-100 rounded-2xl p-4 mb-4 space-y-2">
                            <p className="font-black text-xs text-[#8a2be2] flex items-center gap-1">
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
                                    className="flex-1 p-2 bg-white border border-slate-200 rounded-xl outline-none text-xs font-semibold focus:ring-1 focus:ring-violet-500"
                                />
                                <button
                                    type="button"
                                    onClick={handleAIParsing}
                                    disabled={!aiInput}
                                    className="px-3 bg-[#8a2be2] hover:bg-[#7b1fa2] text-white rounded-xl border-none cursor-pointer flex items-center justify-center shrink-0 disabled:opacity-50"
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
                                    className="w-full p-2.5 border border-slate-200 bg-slate-55 rounded-xl focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 outline-none text-xs font-semibold"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-black text-slate-500 uppercase mb-1.5">
                                    Puesto Responsable
                                </label>
                                <select 
                                    value={newTargetRole}
                                    onChange={e => setNewTargetRole(Number(e.target.value))}
                                    className="w-full p-2.5 border border-slate-200 bg-slate-55 rounded-xl focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 outline-none text-xs font-semibold"
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
                                        className="w-full p-2.5 border border-slate-200 bg-slate-55 rounded-xl focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 outline-none text-xs font-semibold"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-black text-slate-500 uppercase mb-1.5">
                                        Prioridad
                                    </label>
                                    <select 
                                        value={newPriority}
                                        onChange={e => setNewPriority(e.target.value as any)}
                                        className="w-full p-2.5 border border-slate-200 bg-slate-55 rounded-xl focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 outline-none text-xs font-semibold"
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
                                <div className="grid grid-cols-2 gap-3 bg-slate-55 p-3 rounded-2xl border border-slate-100/50 text-xs text-slate-605 font-bold">
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
                                </div>

                                {t.description && (
                                    <div className="space-y-1">
                                        <h5 className="text-[10px] font-black uppercase text-slate-400 tracking-wider">Descripción</h5>
                                        <p className="text-xs text-slate-600 font-semibold leading-relaxed bg-slate-50/40 p-2.5 rounded-xl border border-slate-100">
                                            {t.description}
                                        </p>
                                    </div>
                                )}

                                {/* Checklist de subtareas */}
                                {t.subTasks && t.subTasks.length > 0 && (
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
                                )}

                                {/* Caso: Tarea esperando validación (Solo para Supervisor) */}
                                {a.status === 'awaiting_validation' && (
                                    <div className="space-y-3 pt-2 border-t border-slate-100">
                                        {a.assistantData && (
                                            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200 text-xs">
                                                <p className="font-extrabold text-violet-750 flex items-center gap-1.5 mb-1.5">
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
