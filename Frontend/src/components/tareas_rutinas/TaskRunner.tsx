import React, { useState, useEffect, useRef } from 'react';
import { 
    Coffee, Play, Check, Clock, Lock, Brain, Camera, Bot, 
    Pause, Trash2, Plus, X, AlertCircle, ChevronRight, User, HelpCircle,
    Search, RefreshCw, CheckCircle, XCircle, ShieldAlert, Sparkles, ClipboardList
} from 'lucide-react';
import { useTaskStore } from '../../store/useTaskStore';
import type { Task, TaskAssignment } from '../../store/useTaskStore';
import { useAppStore } from '../../store/useAppStore';

// Componente para tarjeta deslizable en modo claro y suave
interface SwipeableTaskCardProps {
    assignment: TaskAssignment;
    task: Task;
    currentUser: any;
    globalSimTime: number;
    restriction: any;
    isCurrentlyLocked: boolean;
    onStart: () => void;
    onPause: () => void;
    onComplete: () => void;
    onOmit: () => void;
    onShowToast: (msg: string, type: 'info' | 'success' | 'warning') => void;
    onSelect: () => void;
    isSelected: boolean;
    getRoutineName: (id?: number) => string;
    getRoleName: (id?: number) => string;
}

function SwipeableTaskCard({
    assignment,
    task,
    currentUser,
    globalSimTime,
    restriction,
    isCurrentlyLocked,
    onStart,
    onPause,
    onComplete,
    onOmit,
    onShowToast,
    onSelect,
    isSelected,
    getRoutineName,
    getRoleName
}: SwipeableTaskCardProps) {
    const [startX, setStartX] = useState(0);
    const [isDragging, setIsDragging] = useState(false);
    const [dragOffset, setDragOffset] = useState(0);
    const [showInstructions, setShowInstructions] = useState(false);
    const [localInput, setLocalInput] = useState('');
    const [photoDone, setPhotoDone] = useState(false);

    const isCompleted = assignment.status === 'completed' || assignment.status === 'awaiting_validation';
    const isOmitted = assignment.status === 'omitted';
    const isActive = assignment.status === 'in_progress';
    const isPaused = assignment.status === 'paused';

    const elapsed = assignment.status === 'pending' ? 0 : 
        ((assignment.accumulatedMins || 0) + 
        (assignment.status === 'in_progress' && assignment.startedAtMins ? (globalSimTime - assignment.startedAtMins) : 0));
    const percent = Math.min(100, Math.max(0, (elapsed / task.estimatedMins) * 100));
    const isOvertime = assignment.status !== 'pending' && elapsed > task.estimatedMins;

    // Gestores de arrastre
    const handleStart = (clientX: number) => {
        if (isCompleted || isOmitted || isCurrentlyLocked) return;
        setStartX(clientX);
        setIsDragging(true);
    };

    const handleMove = (clientX: number) => {
        if (!isDragging) return;
        const offset = clientX - startX;
        
        // Si es bloqueante/obligatoria, no permitir deslizar a la izquierda (omitir)
        if (task.priority === 'bloqueante' && offset < 0) {
            setDragOffset(Math.max(-25, offset * 0.15));
        } else {
            // amortiguación
            if (offset > 150) {
                setDragOffset(150 + (offset - 150) * 0.2);
            } else if (offset < -150) {
                setDragOffset(-150 + (offset + 150) * 0.2);
            } else {
                setDragOffset(offset);
            }
        }
    };

    const handleEnd = () => {
        if (!isDragging) return;
        setIsDragging(false);
        const threshold = 80;

        if (dragOffset > threshold) {
            // Deslizar a la derecha -> Play / Continuar / Completar
            if (assignment.userId !== currentUser.id && assignment.userId !== null) {
                onStart();
                onShowToast(`Retomando la tarea colaborativa de ${getRoleName(task.targetId as any)}`, 'info');
            } else if (assignment.status === 'pending' || isPaused) {
                onStart();
                onShowToast(`Tarea "${task.title}" iniciada`, 'success');
            } else if (isActive) {
                if (task.assistantType !== 'ninguno') {
                    onSelect();
                    setShowInstructions(true);
                    onShowToast("Completa el asistente requerido", 'info');
                } else {
                    onComplete();
                    onShowToast("¡Tarea completada!", 'success');
                }
            }
        } else if (dragOffset < -threshold) {
            // Deslizar a la izquierda -> Omitir
            if (task.priority === 'bloqueante') {
                onShowToast("Las tareas bloqueantes son obligatorias y no se pueden omitir", 'warning');
            } else {
                onOmit();
                onShowToast("Tarea omitida", 'info');
            }
        }
        setDragOffset(0);
    };

    // Soporte táctil
    const onTouchStart = (e: React.TouchEvent) => handleStart(e.touches[0].clientX);
    const onTouchMove = (e: React.TouchEvent) => handleMove(e.touches[0].clientX);
    const onTouchEnd = () => handleEnd();

    // Soporte de ratón
    const onMouseDown = (e: React.MouseEvent) => handleStart(e.clientX);
    const onMouseMove = (e: React.MouseEvent) => handleMove(e.clientX);
    const onMouseUp = () => handleEnd();
    const onMouseLeave = () => { if (isDragging) handleEnd(); };

    // Envío del asistente
    const submitAssistant = () => {
        if (!localInput.trim()) return;
        const { completeTask } = useTaskStore.getState();
        const { addMatrixEvent } = useAppStore.getState();
        
        completeTask(assignment.id, globalSimTime, localInput);
        setLocalInput('');
        setPhotoDone(false);
        onShowToast("Evidencia guardada y tarea completada", 'success');
        addMatrixEvent('✅ Asistente completado', `Tarea "${task.title}" con reporte: ${localInput}`, 'success', currentUser.id);
    };

    const isAssignedToOther = assignment.userId !== null && assignment.userId !== currentUser.id;

    // Badges en colores suaves y pasteles
    const getBadgeStyle = () => {
        // Si es urgente/inmediata (no es de rutina y tiene prioridad o asignada)
        const isUrgent = (task.priority === 'bloqueante') || (!assignment.assignedFromRoutineId && assignment.userId === currentUser.id);
        
        if (isUrgent && !isCompleted && !isOmitted) {
            return 'bg-rose-50 text-rose-600 border border-rose-100';
        }
        if (assignment.userId === null) {
            return 'bg-emerald-50 text-emerald-600 border border-emerald-100';
        }
        return 'bg-blue-50 text-blue-600 border border-blue-100';
    };

    const getBadgeLabel = () => {
        const isUrgent = (task.priority === 'bloqueante') || (!assignment.assignedFromRoutineId && assignment.userId === currentUser.id);
        if (isUrgent && !isCompleted && !isOmitted) return 'Inmediata / Urgente';
        if (assignment.userId === null) return 'Bolsa de Trabajo';
        return 'Rutina Laboral';
    };

    // Fondo dinámico de progreso para la tarjeta
    const cardBgStyle = isActive && !isOvertime
        ? {
            background: `linear-gradient(to right, #f0f4ff ${percent}%, #ffffff ${percent}%)`
          }
        : isOvertime
        ? {
            background: `linear-gradient(to right, #ffebee ${percent}%, #ffffff ${percent}%)`
          }
        : undefined;

    return (
        <div 
            className={`relative rounded-2xl overflow-hidden mb-3 border select-none transition-all ${
                isSelected 
                    ? 'border-indigo-400 ring-2 ring-indigo-50/50' 
                    : 'border-slate-200/60 shadow-[0_4px_12px_0_rgba(0,0,0,0.02)]'
            }`}
        >
            {/* Fondo revelado al deslizar */}
            <div className="absolute inset-0 flex justify-between items-center px-6 z-0">
                <div 
                    className="absolute inset-y-0 left-0 bg-emerald-500/80 flex items-center pl-6 text-white transition-opacity"
                    style={{ opacity: dragOffset > 10 ? 1 : 0, width: '100%' }}
                >
                    <div className="flex items-center gap-2 font-black text-xs uppercase tracking-wider">
                        {isActive ? <Check size={16} /> : <Play size={16} />}
                        <span>{isActive ? 'Completar' : 'Iniciar'}</span>
                    </div>
                </div>

                <div 
                    className="absolute inset-y-0 right-0 bg-rose-500/80 flex items-center justify-end pr-6 text-white transition-opacity"
                    style={{ opacity: dragOffset < -10 ? 1 : 0, width: '100%' }}
                >
                    <div className="flex items-center gap-2 font-black text-xs uppercase tracking-wider">
                        <span>{task.priority === 'bloqueante' ? 'Obligatoria' : 'Omitir'}</span>
                        <Trash2 size={16} />
                    </div>
                </div>
            </div>

            {/* Tarjeta principal */}
            <div
                onTouchStart={onTouchStart}
                onTouchMove={onTouchMove}
                onTouchEnd={onTouchEnd}
                onMouseDown={onMouseDown}
                onMouseMove={onMouseMove}
                onMouseUp={onMouseUp}
                onMouseLeave={onMouseLeave}
                className="relative z-10 p-4 bg-white text-slate-800 transition-transform cursor-grab active:cursor-grabbing border-none text-left"
                style={{ 
                    transform: `translateX(${dragOffset}px)`,
                    transition: isDragging ? 'none' : 'transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1)',
                    ...cardBgStyle
                }}
            >
                <div className="flex flex-col">
                    {/* Fila superior: Badges */}
                    <div className="flex items-center justify-between mb-2.5 flex-wrap gap-2">
                        <div className="flex items-center gap-1.5 flex-wrap">
                            <span className={`text-[9px] font-black uppercase px-2 py-0.5 rounded-full ${getBadgeStyle()}`}>
                                {getBadgeLabel()}
                            </span>

                            {isActive && (
                                <span className="text-[9px] font-black uppercase px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 border border-indigo-200 animate-pulse">
                                    En Proceso
                                </span>
                            )}

                            {isPaused && (
                                <span className="text-[9px] font-black uppercase px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 border border-amber-200">
                                    Pausada
                                </span>
                            )}

                            {isAssignedToOther && (
                                <span className="text-[9px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full">
                                    ⏸️ Pausada por puesto
                                </span>
                            )}
                        </div>

                        <div>
                            {isCompleted ? (
                                <span className="text-[10px] font-black text-emerald-600 bg-emerald-50 px-2.5 py-0.5 rounded-lg">
                                    ✓ Resuelta
                                </span>
                            ) : isOmitted ? (
                                <span className="text-[10px] font-black text-slate-400 bg-slate-50 px-2.5 py-0.5 rounded-lg">
                                    Omitida
                                </span>
                            ) : isCurrentlyLocked ? (
                                <span className="text-[10px] font-black text-rose-600 bg-rose-50 px-2.5 py-0.5 rounded-lg">
                                    🔒 Horario restringido
                                </span>
                            ) : null}
                        </div>
                    </div>

                    {/* Título de tarea y estimación */}
                    <div className="flex justify-between items-start gap-4 mb-2">
                        <div className="flex-1 min-w-0">
                            <h4 className={`font-black text-sm text-slate-800 leading-snug ${isCompleted ? 'text-slate-400 line-through' : ''}`}>
                                {task.title}
                            </h4>
                            {task.description && (
                                <p className="text-xs text-slate-500 mt-1 font-medium leading-normal">
                                    {task.description}
                                </p>
                            )}
                        </div>
                        <div className="text-right shrink-0">
                            <span className="text-xs font-bold text-slate-550 flex items-center gap-1 justify-end">
                                <Clock size={11} className="text-slate-400" /> {task.estimatedMins} min
                            </span>
                            {isActive && (
                                <span className={`text-[10px] font-black block mt-1 ${isOvertime ? 'text-rose-600 animate-pulse' : 'text-indigo-600'}`}>
                                    {isOvertime ? `Excedido: ${elapsed - task.estimatedMins} min` : `Restan: ${task.estimatedMins - elapsed} min`}
                                </span>
                            )}
                        </div>
                    </div>

                    {/* Fila de progreso visual e indicadores */}
                    {isActive && (
                        <div className="w-full bg-slate-100 h-1 rounded-full overflow-hidden mb-2 mt-1">
                            <div 
                                className={`h-full rounded-full transition-all duration-300 ${
                                    isOvertime ? 'bg-rose-500 animate-pulse' : 'bg-indigo-500'
                                }`} 
                                style={{ width: `${percent}%` }}
                            />
                        </div>
                    )}

                    {/* Fila de acciones y checklist */}
                    <div className="flex flex-wrap items-center justify-between mt-2 pt-2 border-t border-slate-100 gap-2">
                        <div className="flex gap-2">
                            {(task.description || (task.subTasks && task.subTasks.length > 0)) && (
                                <button 
                                    onClick={() => setShowInstructions(!showInstructions)}
                                    className="text-[10px] font-black px-2.5 py-1 bg-slate-50 hover:bg-slate-100 text-slate-600 rounded-lg transition-colors border border-slate-200/50 cursor-pointer"
                                >
                                    {showInstructions ? 'Ocultar pasos ▴' : 'Ver pasos ▾'}
                                </button>
                            )}
                        </div>

                        <div className="flex items-center gap-1.5">
                            {!isCompleted && !isOmitted && !isCurrentlyLocked && (
                                <>
                                    {isActive ? (
                                        <>
                                            <button 
                                                onClick={onPause} 
                                                className="p-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-lg transition-all hover:scale-105 cursor-pointer border-none"
                                                title="Pausar Tarea"
                                            >
                                                <Pause size={12} />
                                            </button>
                                            <button 
                                                onClick={() => {
                                                    if (task.assistantType !== 'ninguno') {
                                                        onSelect();
                                                        setShowInstructions(true);
                                                    } else {
                                                        onComplete();
                                                    }
                                                }} 
                                                className="flex items-center gap-1 px-3 py-1 bg-emerald-500 hover:bg-emerald-600 text-white font-black text-[10px] rounded-lg transition-all hover:scale-105 cursor-pointer border-none shadow-sm"
                                            >
                                                <Check size={12} /> Completar
                                            </button>
                                        </>
                                    ) : (
                                        <>
                                            {task.priority !== 'bloqueante' && (
                                                <button 
                                                    onClick={onOmit}
                                                    className="p-1.5 hover:bg-rose-50 text-slate-400 hover:text-rose-500 rounded-lg transition-colors cursor-pointer border-none"
                                                    title="Omitir Tarea"
                                                >
                                                    <Trash2 size={12} />
                                                </button>
                                            )}
                                            <button 
                                                onClick={onStart} 
                                                className="flex items-center gap-1.5 px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-[10px] rounded-lg transition-all hover:scale-105 shadow-md border-none cursor-pointer"
                                            >
                                                <Play size={10} className="fill-white" /> {isPaused ? 'Reanudar' : isAssignedToOther ? 'Continuar' : 'Iniciar'}
                                            </button>
                                        </>
                                    )}
                                </>
                            )}
                        </div>
                    </div>

                    {/* Desplegable de Instrucciones y Asistente */}
                    {showInstructions && (
                        <div className="mt-3 p-3 bg-slate-50 border border-slate-100 rounded-xl space-y-3 text-xs leading-normal">
                            {task.description && (
                                <div>
                                    <p className="font-extrabold text-slate-800">Detalles de ejecución:</p>
                                    <p className="text-slate-600 mt-0.5 leading-relaxed font-medium">{task.description}</p>
                                </div>
                            )}

                            {task.subTasks && task.subTasks.length > 0 && (
                                <div className="space-y-1">
                                    <p className="font-extrabold text-slate-800 mb-1">Checklist de Pasos Obligatorios:</p>
                                    {task.subTasks.map(sub => (
                                        <label key={sub.id} className="flex items-center gap-2 p-2 bg-white rounded-lg border border-slate-150/60 hover:bg-indigo-50 cursor-pointer transition-colors shadow-sm">
                                            <input type="checkbox" className="w-3.5 h-3.5 text-indigo-600 rounded border-slate-350" />
                                            <span className="text-slate-700 font-semibold">{sub.text}</span>
                                        </label>
                                    ))}
                                </div>
                            )}

                            {/* Mini Asistente en Enfoque */}
                            {task.assistantType !== 'ninguno' && !isCompleted && !isOmitted && (
                                <div className="p-3 bg-indigo-50 border border-indigo-100 rounded-xl space-y-2 text-left">
                                    <p className="font-black text-indigo-900 flex items-center gap-1">
                                        <Bot size={13} className="text-indigo-600" /> Asistente de Verificación
                                    </p>
                                    <p className="text-slate-650 font-bold text-[11px]">{task.assistantPrompt}</p>
                                    
                                    {task.assistantType === 'evidencia_foto' && (
                                        <div className="space-y-2">
                                            {!photoDone ? (
                                                <button 
                                                    onClick={() => { setPhotoDone(true); setLocalInput('evidencia_checador_foto.jpg'); }}
                                                    className="w-full py-2.5 bg-indigo-100 hover:bg-indigo-200 text-indigo-800 rounded-lg border border-indigo-300 text-[10px] font-black flex items-center justify-center gap-1.5 transition-colors cursor-pointer"
                                                >
                                                    <Camera size={13} /> Capturar Foto de Evidencia
                                                </button>
                                            ) : (
                                                <div className="flex items-center gap-2 p-2 bg-emerald-50 border border-emerald-100 rounded-lg text-emerald-800">
                                                    <Check size={14} className="text-emerald-600 font-black" />
                                                    <span className="font-extrabold truncate">evidencia_checador_foto.jpg</span>
                                                    <button onClick={() => { setPhotoDone(false); setLocalInput(''); }} className="ml-auto text-[10px] font-black underline text-slate-500 hover:text-slate-700 border-none bg-transparent cursor-pointer">Cambiar</button>
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {task.assistantType === 'captura_numero' && (
                                        <input 
                                            type="number"
                                            value={localInput}
                                            onChange={e => setLocalInput(e.target.value)}
                                            placeholder="Ingresa la cantidad..."
                                            className="w-full p-2.5 border border-slate-200 bg-white rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-xs font-semibold"
                                        />
                                    )}

                                    {task.assistantType === 'texto' && (
                                        <input 
                                            type="text"
                                            value={localInput}
                                            onChange={e => setLocalInput(e.target.value)}
                                            placeholder="Escribe reporte de fin de tarea..."
                                            className="w-full p-2.5 border border-slate-200 bg-white rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-xs font-semibold"
                                        />
                                    )}

                                    <button 
                                        onClick={submitAssistant}
                                        disabled={!localInput}
                                        className="w-full py-2 bg-indigo-650 disabled:bg-slate-100 disabled:text-slate-400 hover:bg-indigo-750 text-white rounded-lg text-[10px] font-black shadow-sm transition-colors cursor-pointer border-none"
                                    >
                                        Enviar Reporte y Completar
                                    </button>
                                </div>
                            )}
                        </div>
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
        validateTaskAssignment
    } = useTaskStore();
    const { globalSimTime, addMatrixEvent, globalRoles, globalUsers } = useAppStore();

    // Filtros y pestañas locales
    const [activeTab, setActiveTab] = useState<'hoy' | 'supervisar' | 'historial'>('hoy');
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedAssignmentId, setSelectedAssignmentId] = useState<string | null>(null);
    
    // Modal de creación de tareas
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [newTitle, setNewTitle] = useState('');
    const [newDesc, setNewDesc] = useState('');
    const [newMins, setNewMins] = useState(15);
    const [newPriority, setNewPriority] = useState<'normal' | 'bloqueante'>('normal');
    const [newTargetRole, setNewTargetRole] = useState<number>(currentUser.job_role_id || 6);

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
        if (id === 0 || !id) return 'Bolsa de Trabajo';
        return globalRoles?.find(r => r.id === id)?.name || `Puesto #${id}`;
    };

    const myRole = globalRoles?.find(r => r.id === currentUser.job_role_id);
    const userPositionName = myRole ? myRole.name : (currentUser?.role === 'admin' ? 'Administrador' : currentUser?.role === 'supervisor' ? 'Supervisor' : 'Colaborador');

    // Determinar si tiene rol de supervisor
    const isSupervisor = currentUser?.role?.toLowerCase().includes('superv') || 
                         currentUser?.role?.toLowerCase().includes('geren') || 
                         currentUser?.role?.toLowerCase().includes('admin');

    // 1. Tareas asignadas directamente al colaborador (rutina + inmediatas personales)
    const myAssignments = assignments.filter(a => 
        a.userId === currentUser.id && 
        ['pending', 'in_progress', 'paused', 'completed', 'awaiting_validation'].includes(a.status)
    );

    // 2. Bolsa de Trabajo y Puestos
    const puestoBolsaAssignments = assignments.filter(a => {
        const t = tasks.find(tsk => tsk.id === a.taskId);
        if (!t) return false;
        
        const isTargetedToMyRole = t.targetId === null || t.targetId === undefined || Number(t.targetId) === 0 || Number(t.targetId) === Number(currentUser.job_role_id);
        
        // Pendiente en la bolsa (nadie la tiene asignada)
        const isFreeInPool = a.userId === null && a.status === 'pending' && isTargetedToMyRole;
        
        // Pausada por otro colaborador que tiene exactamente mi puesto/rol
        const isPausedByPeer = a.userId !== null && a.userId !== currentUser.id && a.status === 'paused' && t.targetType === 'role' && Number(t.targetId) === Number(currentUser.job_role_id);
        
        return isFreeInPool || isPausedByPeer;
    });

    // 3. Tareas esperando validación (para supervisores)
    const awaitingValidationAssignments = assignments.filter(a => 
        a.status === 'awaiting_validation'
    );

    // 4. Historial (Tareas completadas u omitidas por el colaborador hoy)
    const historyAssignments = assignments.filter(a => 
        a.userId === currentUser.id && 
        ['completed', 'awaiting_validation', 'omitted'].includes(a.status)
    );

    // Filtrados según búsqueda
    const filterBySearch = (list: TaskAssignment[]) => {
        return list.filter(a => {
            const t = tasks.find(tsk => tsk.id === a.taskId);
            if (!t) return false;
            return t.title.toLowerCase().includes(searchQuery.toLowerCase());
        });
    };

    const activeAssignmentsFiltered = filterBySearch(myAssignments.filter(a => a.status !== 'completed' && a.status !== 'awaiting_validation'));
    const puestoAssignmentsFiltered = filterBySearch(puestoBolsaAssignments);
    const awaitingValidationFiltered = filterBySearch(awaitingValidationAssignments);
    const historyAssignmentsFiltered = filterBySearch(historyAssignments);

    // Fusión de tareas en una sola Lista Unificada (hoy)
    // Tareas inmediatas primero, luego rutinas, luego bolsa de trabajo disponible.
    const unifiedActiveList = [
        // 1. Inmediatas / urgentes
        ...activeAssignmentsFiltered.filter(a => a.assignedFromRoutineId === null || a.assignedFromRoutineId === undefined),
        // 2. Rutina laboral
        ...activeAssignmentsFiltered.filter(a => a.assignedFromRoutineId !== null && a.assignedFromRoutineId !== undefined),
        // 3. Bolsa de trabajo disponible
        ...filterBySearch(puestoBolsaAssignments)
    ];

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
            const found = globalRoles?.find(r => r.name.toLowerCase().includes('caje'));
            if (found) parsedRoleId = found.id;
        } else if (/ayudante/i.test(aiInput)) {
            const found = globalRoles?.find(r => r.name.toLowerCase().includes('ayud'));
            if (found) parsedRoleId = found.id;
        } else if (/gerente|encargado/i.test(aiInput)) {
            const found = globalRoles?.find(r => r.name.toLowerCase().includes('geren') || r.name.toLowerCase().includes('encar'));
            if (found) parsedRoleId = found.id;
        } else if (/supervisor/i.test(aiInput)) {
            const found = globalRoles?.find(r => r.name.toLowerCase().includes('superv'));
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
        const currentActive = myAssignments.find(a => a.status === 'in_progress');
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
            {/* Cabecera Unificada con el Estilo del Reloj Checador */}
            <div className="flex items-center justify-between px-4 py-4 shrink-0 bg-white text-left -mx-4 -mt-4 mb-4 rounded-b-[2rem] shadow-[0_4px_20px_0_rgba(0,0,0,0.04)] border-b border-slate-100/80">
                {/* Left: Module Info */}
                <div className="flex items-center gap-2.5 min-w-0">
                    <div className="shrink-0 flex items-center justify-center">
                        <ClipboardList className="w-9 h-9 text-[#8a2be2]" />
                    </div>
                    <div className="flex flex-col min-w-0 justify-center text-left">
                        <div className="flex items-center gap-2">
                            <h3 className="text-[16px] font-black text-slate-900 tracking-tight leading-tight">
                                Tablero de Tareas
                            </h3>
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[8.5px] font-black tracking-wider bg-violet-50 text-[#8a2be2] border border-violet-100">
                                v4.2.0
                            </span>
                        </div>
                        <p className="text-[11px] text-[#525f7f] font-bold mt-1.5 leading-none truncate">
                            Rutina, Bolsa e Inmediatas
                        </p>
                    </div>
                </div>

                {/* Right: Actions & User Profile */}
                <div className="flex items-center gap-3 shrink-0">
                    <button 
                        onClick={onBack}
                        className="bg-slate-100 hover:bg-slate-200 text-slate-600 font-extrabold text-[9px] uppercase px-3 py-2 rounded-xl border border-slate-200/50 cursor-pointer active:scale-95 transition-all select-none shrink-0"
                    >
                        <span>← Volver</span>
                    </button>

                    <div className="flex items-center gap-2.5 text-right">
                        <div className="flex flex-col min-w-0 text-right justify-center leading-tight">
                            <span className="text-[13px] font-black text-slate-900 truncate">
                                {currentUser?.name || 'Colaborador'}
                            </span>
                            <span className="text-[8.5px] font-extrabold text-slate-400 uppercase tracking-widest truncate mt-0.5">
                                {userPositionName}
                            </span>
                            <span className="text-[9px] font-black text-[#8a2be2] uppercase tracking-wider truncate mt-0.5">
                                {currentUser?.tenant?.name || 'Decorarte 360'}
                            </span>
                        </div>
                        
                        <div className="relative shrink-0">
                            <img 
                                src={currentUser?.avatar || "https://i.pravatar.cc/150?img=11"} 
                                alt="Avatar" 
                                className="w-12 h-12 rounded-full object-cover border border-slate-200/80 shadow-md" 
                            />
                        </div>
                    </div>
                </div>
            </div>

            {/* Buscador de tareas */}
            <div className="mb-4 shrink-0">
                <div className="relative">
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
                        <button onClick={() => setSearchQuery('')} className="absolute right-2.5 top-2.5 text-slate-400 hover:text-slate-600 bg-transparent border-none cursor-pointer">
                            <X size={14} />
                        </button>
                    )}
                </div>
            </div>

            {/* Listado principal */}
            <div className="flex-1 overflow-y-auto pb-20 custom-scrollbar pr-1 -mr-1">
                {activeTab === 'hoy' && (
                    <>
                        <div className="flex justify-between items-center mb-3">
                            <h3 className="text-xs font-black uppercase text-slate-400 tracking-wider">
                                Lista Unificada de Tareas Activas
                            </h3>
                            <span className="text-[10px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full">
                                {unifiedActiveList.length} disponibles
                            </span>
                        </div>

                        {unifiedActiveList.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center bg-white rounded-3xl border border-slate-200/60 p-6 shadow-sm">
                                <Coffee size={36} className="text-indigo-400 mb-2 animate-bounce" />
                                <p className="text-sm font-black text-slate-700">¡Tablero limpio!</p>
                                <p className="text-xs text-slate-500 mt-1">
                                    No tienes tareas personales de rutina, urgentes o en bolsa para desarrollar ahora.
                                </p>
                            </div>
                        ) : (
                            unifiedActiveList.map(a => {
                                const t = tasks.find(tsk => tsk.id === a.taskId);
                                if (!t) return null;

                                const restriction = getRoutineTimeRestriction(a.assignedFromRoutineId);
                                const isCurrentlyLocked = restriction 
                                    ? (globalSimTime < restriction.startMin || globalSimTime > restriction.endMin)
                                    : false;

                                const isFromPool = a.userId === null;

                                return (
                                    <SwipeableTaskCard 
                                        key={a.id}
                                        assignment={a}
                                        task={t}
                                        currentUser={currentUser}
                                        globalSimTime={globalSimTime}
                                        restriction={restriction}
                                        isCurrentlyLocked={isCurrentlyLocked}
                                        onStart={() => handleStartTaskCooperative(a.id, isFromPool)}
                                        onPause={() => pauseTask(a.id)}
                                        onComplete={() => completeTask(a.id, globalSimTime)}
                                        onOmit={() => omitAssignment(a.id)}
                                        onShowToast={showToast}
                                        onSelect={() => setSelectedAssignmentId(a.id)}
                                        isSelected={selectedAssignmentId === a.id}
                                        getRoutineName={getRoutineName}
                                        getRoleName={getRoleName}
                                    />
                                );
                            })
                        )}
                    </>
                )}

                {activeTab === 'supervisar' && isSupervisor && (
                    <>
                        <h3 className="text-xs font-black uppercase text-slate-400 tracking-wider mb-3">
                            Tareas esperando validación de firma
                        </h3>

                        {awaitingValidationFiltered.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center bg-white rounded-3xl border border-slate-200/60 p-6 shadow-sm">
                                <CheckCircle size={36} className="text-emerald-500 mb-2 animate-bounce" />
                                <p className="text-sm font-black text-slate-755">Todo al corriente</p>
                                <p className="text-xs text-slate-500 mt-1">
                                    No hay reportes de tareas esperando firma de supervisor.
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {awaitingValidationFiltered.map(a => {
                                    const t = tasks.find(tsk => tsk.id === a.taskId);
                                    const worker = globalUsers?.find(u => u.id === a.userId);
                                    if (!t) return null;

                                    const isRejecting = rejectingAssignmentId === a.id;

                                    return (
                                        <div 
                                            key={a.id} 
                                            className="bg-white border border-slate-200/80 rounded-2xl p-4 shadow-sm relative overflow-hidden"
                                        >
                                            <div className="absolute top-0 left-0 w-full h-1 bg-amber-400"></div>

                                            <div className="flex justify-between items-start gap-4 mb-2 text-left">
                                                <div>
                                                    <span className="text-[9px] font-black uppercase px-2.5 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-250/20">
                                                        Firma Pendiente
                                                    </span>
                                                    <h4 className="font-black text-sm text-slate-800 mt-2">
                                                        {t.title}
                                                    </h4>
                                                    <p className="text-xs text-slate-500 font-bold mt-1.5 flex items-center gap-1">
                                                        <User size={12} className="text-slate-400" />
                                                        Colaborador: <span className="text-slate-850 font-black">{worker?.name || `Usuario #${a.userId}`}</span> 
                                                        ({getRoleName(worker?.job_role_id)})
                                                    </p>
                                                </div>
                                                <div className="text-right shrink-0">
                                                    <span className="text-xs font-bold text-slate-500">
                                                        ⏱️ {a.accumulatedMins || t.estimatedMins} min real
                                                    </span>
                                                </div>
                                            </div>

                                            {/* Reporte / Evidencia presentada */}
                                            {a.assistantData && (
                                                <div className="my-3 p-3 bg-slate-50 rounded-xl border border-slate-100 text-xs space-y-1 text-left">
                                                    <p className="font-extrabold text-indigo-750 flex items-center gap-1.5">
                                                        <Bot size={13} /> Evidencia presentada:
                                                    </p>
                                                    <p className="text-slate-700 font-black bg-white p-2.5 rounded-lg border border-slate-150 leading-relaxed">
                                                        {t.assistantType === 'evidencia_foto' ? (
                                                            <span className="flex items-center gap-1.5">
                                                                <Camera size={13} className="text-indigo-500" />
                                                                {String(a.assistantData)} (Imagen capturada)
                                                            </span>
                                                        ) : (
                                                            String(a.assistantData)
                                                        )}
                                                    </p>
                                                </div>
                                            )}

                                            {/* Formulario de rechazo */}
                                            {isRejecting ? (
                                                <div className="mt-3 p-3 bg-rose-50 border border-rose-100 rounded-xl space-y-2.5 animate-in slide-in-from-top-2 duration-150 text-left">
                                                    <p className="text-xs font-black text-rose-800">
                                                        Razón de devolución / Corrección requerida:
                                                    </p>
                                                    <textarea 
                                                        value={rejectFeedback}
                                                        onChange={e => setRejectFeedback(e.target.value)}
                                                        placeholder="Ej: Te faltó limpiar el área trasera, por favor hazlo antes de terminar..."
                                                        rows={2}
                                                        className="w-full p-2 border border-slate-200 bg-white rounded-lg outline-none text-xs font-semibold focus:ring-2 focus:ring-rose-500"
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
                                                            onClick={() => handleReject(a.id, t.title)}
                                                            className="px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-[10px] font-black border-none cursor-pointer"
                                                        >
                                                            Devolver e Iniciar Corrección
                                                        </button>
                                                    </div>
                                                </div>
                                            ) : (
                                                <div className="flex gap-2 justify-end mt-3 pt-2 border-t border-slate-100">
                                                    <button 
                                                        type="button"
                                                        onClick={() => setRejectingAssignmentId(a.id)}
                                                        className="flex items-center gap-1 px-3 py-1.5 hover:bg-rose-50 text-rose-600 rounded-lg text-[10px] font-black border border-rose-200/50 cursor-pointer bg-transparent"
                                                    >
                                                        <XCircle size={13} /> Devolver Tarea
                                                    </button>
                                                    <button 
                                                        type="button"
                                                        onClick={() => handleApprove(a.id, t.title)}
                                                        className="flex items-center gap-1 px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-[10px] font-black border-none shadow-sm cursor-pointer transition-all hover:scale-105"
                                                    >
                                                        <CheckCircle size={13} /> Validar y Firmar
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </>
                )}

                {activeTab === 'historial' && (
                    <>
                        <h3 className="text-xs font-black uppercase text-slate-400 tracking-wider mb-3">
                            Historial de tareas completadas u omitidas
                        </h3>

                        {historyAssignmentsFiltered.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center bg-white rounded-3xl border border-slate-200/60 p-6 shadow-sm">
                                <AlertCircle size={36} className="text-slate-300 mb-2" />
                                <p className="text-sm font-black text-slate-700">Historial Vacío</p>
                                <p className="text-xs text-slate-500 mt-1">
                                    Aún no has completado u omitido tareas en tu turno de hoy.
                                </p>
                            </div>
                        ) : (
                            historyAssignmentsFiltered.map(a => {
                                const t = tasks.find(tsk => tsk.id === a.taskId);
                                if (!t) return null;

                                return (
                                    <SwipeableTaskCard 
                                        key={a.id}
                                        assignment={a}
                                        task={t}
                                        currentUser={currentUser}
                                        globalSimTime={globalSimTime}
                                        restriction={null}
                                        isCurrentlyLocked={false}
                                        onStart={() => {}}
                                        onPause={() => {}}
                                        onComplete={() => {}}
                                        onOmit={() => {}}
                                        onShowToast={showToast}
                                        onSelect={() => setSelectedAssignmentId(a.id)}
                                        isSelected={selectedAssignmentId === a.id}
                                        getRoutineName={getRoutineName}
                                        getRoleName={getRoleName}
                                    />
                                );
                            })
                        )}
                    </>
                )}
            </div>

            {/* Alerta flotante Toast */}
            {toast && (
                <div className="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 bg-slate-900/90 text-white px-4.5 py-2.5 rounded-2xl shadow-lg flex items-center gap-2 border border-slate-800/20 backdrop-blur-sm animate-in fade-in slide-in-from-bottom-2 duration-200">
                    <span className="text-xs font-black">{toast.message}</span>
                </div>
            )}

            {/* MENÚ FLOTANTE (FAB) en la parte inferior derecha para el supervisor */}
            {isSupervisor && (
                <div className="fixed bottom-6 right-6 z-40" ref={fabMenuRef}>
                    {/* Panel del Menú Desplegado (aparece arriba del FAB) */}
                    {showFabMenu && (
                        <div className="absolute bottom-16 right-0 bg-white border border-slate-200/80 rounded-2xl shadow-xl p-2.5 flex flex-col gap-1 min-w-[200px] animate-in fade-in slide-in-from-bottom-3 duration-200">
                            <p className="text-[9px] font-black uppercase tracking-widest text-slate-400 px-3 py-1.5 text-left border-b border-slate-50">
                                Herramientas Supervisor
                            </p>
                            
                            <button
                                onClick={() => { setActiveTab('hoy'); setShowFabMenu(false); }}
                                className={`w-full text-left px-3 py-2 rounded-xl text-xs font-black flex items-center gap-2 border-none cursor-pointer transition-colors ${
                                    activeTab === 'hoy' ? 'bg-indigo-50 text-indigo-700' : 'bg-transparent text-slate-650 hover:bg-slate-50'
                                }`}
                            >
                                📋 Ver Mis Tareas
                            </button>

                            <button
                                onClick={() => { setActiveTab('supervisar'); setShowFabMenu(false); }}
                                className={`w-full text-left px-3 py-2 rounded-xl text-xs font-black flex items-center gap-2 border-none cursor-pointer transition-colors justify-between ${
                                    activeTab === 'supervisar' ? 'bg-indigo-50 text-indigo-700' : 'bg-transparent text-slate-650 hover:bg-slate-50'
                                }`}
                            >
                                <span className="flex items-center gap-2">🔍 Supervisar Tareas</span>
                                {awaitingValidationFiltered.length > 0 && (
                                    <span className="bg-rose-100 text-rose-700 font-extrabold text-[9px] px-1.5 py-0.5 rounded-full shrink-0">
                                        {awaitingValidationFiltered.length}
                                    </span>
                                )}
                            </button>

                            <button
                                onClick={() => { setShowCreateModal(true); setShowFabMenu(false); }}
                                className="w-full text-left px-3 py-2 rounded-xl text-xs font-black text-slate-650 hover:bg-slate-50 border-none bg-transparent cursor-pointer flex items-center gap-2"
                            >
                                ➕ Crear Nueva Tarea
                            </button>

                            <button
                                onClick={() => { setActiveTab('historial'); setShowFabMenu(false); }}
                                className={`w-full text-left px-3 py-2 rounded-xl text-xs font-black flex items-center gap-2 border-none cursor-pointer transition-colors ${
                                    activeTab === 'historial' ? 'bg-indigo-50 text-indigo-700' : 'bg-transparent text-slate-655 hover:bg-slate-50'
                                }`}
                            >
                                ✓ Ver Historial Completo
                            </button>
                        </div>
                    )}

                    {/* Botón FAB circular */}
                    <button
                        onClick={() => setShowFabMenu(!showFabMenu)}
                        className="w-14 h-14 bg-[#8a2be2] hover:bg-[#7b1fa2] text-white rounded-full shadow-lg flex items-center justify-center transition-all hover:scale-105 active:scale-95 border-none cursor-pointer"
                        title="Filtros y Herramientas"
                    >
                        {showFabMenu ? <X size={22} /> : <Plus size={22} className="text-white" />}
                    </button>
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
                                    className="w-full p-2.5 border border-slate-200 bg-slate-50 rounded-xl focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 outline-none text-xs font-semibold"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-black text-slate-500 uppercase mb-1.5">
                                    Puesto Responsable
                                </label>
                                <select 
                                    value={newTargetRole}
                                    onChange={e => setNewTargetRole(Number(e.target.value))}
                                    className="w-full p-2.5 border border-slate-200 bg-slate-50 rounded-xl focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 outline-none text-xs font-semibold"
                                >
                                    <option value={0}>Cualquiera (Bolsa de Trabajo General)</option>
                                    {globalRoles?.map(role => (
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
                                        className="w-full p-2.5 border border-slate-200 bg-slate-50 rounded-xl focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 outline-none text-xs font-semibold"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-black text-slate-500 uppercase mb-1.5">
                                        Prioridad
                                    </label>
                                    <select 
                                        value={newPriority}
                                        onChange={e => setNewPriority(e.target.value as any)}
                                        className="w-full p-2.5 border border-slate-200 bg-slate-50 rounded-xl focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 outline-none text-xs font-semibold"
                                    >
                                        <option value="normal">Normal</option>
                                        <option value="bloqueante">Bloqueante (Obligatoria)</option>
                                    </select>
                                </div>
                            </div>

                            <button 
                                type="submit" 
                                className="w-full py-3 bg-indigo-650 hover:bg-indigo-750 text-white font-black text-xs rounded-xl shadow-md transition-all active:scale-95 border-none cursor-pointer mt-2"
                            >
                                Lanzar Tarea a Bolsa
                            </button>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
