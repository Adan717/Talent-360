import React, { useState, useEffect, useRef } from 'react';
import { 
    Coffee, Play, Check, Clock, Lock, Brain, Camera, Bot, 
    Pause, Trash2, Plus, X, AlertCircle, ChevronRight, User, HelpCircle,
    Search, RefreshCw, CheckCircle, XCircle, ShieldAlert
} from 'lucide-react';
import { useTaskStore } from '../../store/useTaskStore';
import type { Task, TaskAssignment } from '../../store/useTaskStore';
import { useAppStore } from '../../store/useAppStore';

// Componente para tarjeta deslizable con gestos táctiles y ratón
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
    const [currentX, setCurrentX] = useState(0);
    const [isDragging, setIsDragging] = useState(false);
    const [dragOffset, setDragOffset] = useState(0);
    const [showInstructions, setShowInstructions] = useState(false);
    const [localInput, setLocalInput] = useState('');
    const [photoDone, setPhotoDone] = useState(false);
    const cardRef = useRef<HTMLDivElement>(null);

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
        
        // Si es bloqueante, limitar el deslizamiento hacia la izquierda (omitir)
        if (task.priority === 'bloqueante' && offset < 0) {
            setDragOffset(Math.max(-25, offset * 0.15));
        } else {
            // amortiguación progresiva
            if (offset > 180) {
                setDragOffset(180 + (offset - 180) * 0.3);
            } else if (offset < -180) {
                setDragOffset(-180 + (offset + 180) * 0.3);
            } else {
                setDragOffset(offset);
            }
        }
    };

    const handleEnd = () => {
        if (!isDragging) return;
        setIsDragging(false);
        const threshold = 90;

        if (dragOffset > threshold) {
            // Deslizar a la derecha -> Ejecutar / Continuar / Completar
            if (assignment.userId !== currentUser.id && assignment.userId !== null) {
                // Tarea de otro colaborador del mismo puesto
                onStart();
                onShowToast(`Retomando la tarea de ${getRoleName(task.targetId as any)}`, 'info');
            } else if (assignment.status === 'pending' || isPaused) {
                onStart();
                onShowToast(`Tarea "${task.title}" iniciada`, 'success');
            } else if (isActive) {
                // Para completar, si tiene asistente de foto/cantidad, requiere interacción
                if (task.assistantType !== 'ninguno') {
                    onSelect();
                    setShowInstructions(true);
                    onShowToast("Completa la evidencia requerida", 'info');
                } else {
                    onComplete();
                    onShowToast("¡Tarea completada!", 'success');
                }
            }
        } else if (dragOffset < -threshold) {
            // Deslizar a la izquierda -> Omitir
            if (task.priority === 'bloqueante') {
                onShowToast("Las tareas bloqueantes son obligatorias", 'warning');
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

    // Colores de badges de categorías
    const catColors = {
        operativo: 'bg-emerald-50 text-emerald-700 border-emerald-100 dark:bg-emerald-950/30 dark:text-emerald-400 dark:border-emerald-900/50',
        administrativo: 'bg-blue-50 text-blue-700 border-blue-100 dark:bg-blue-950/30 dark:text-blue-400 dark:border-blue-900/50',
        mantenimiento: 'bg-orange-50 text-orange-700 border-orange-100 dark:bg-orange-950/30 dark:text-orange-400 dark:border-orange-900/50',
        supervision: 'bg-purple-50 text-purple-700 border-purple-100 dark:bg-purple-950/30 dark:text-purple-400 dark:border-purple-900/50'
    };

    return (
        <div 
            ref={cardRef}
            className={`relative rounded-2xl overflow-hidden mb-3 border transition-all select-none ${
                isSelected 
                    ? 'border-indigo-400 ring-2 ring-indigo-55 dark:ring-indigo-950/30' 
                    : 'border-slate-100 dark:border-slate-800'
            }`}
        >
            {/* Capa de fondo para el deslizamiento */}
            <div className="absolute inset-0 flex justify-between items-center px-6 z-0">
                {/* Fondo Derecho (Play / Check) */}
                <div 
                    className="absolute inset-y-0 left-0 bg-gradient-to-r from-emerald-500 to-teal-500 flex items-center pl-6 text-white transition-opacity"
                    style={{ opacity: dragOffset > 10 ? 1 : 0, width: '100%' }}
                >
                    <div className="flex items-center gap-2 font-black text-sm">
                        {isActive ? <Check size={18} className="animate-bounce" /> : <Play size={18} className="animate-pulse" />}
                        <span>{isActive ? 'Completar Tarea' : isAssignedToOther ? 'Continuar Tarea' : 'Iniciar Tarea'}</span>
                    </div>
                </div>

                {/* Fondo Izquierdo (Omitir) */}
                <div 
                    className="absolute inset-y-0 right-0 bg-gradient-to-l from-rose-500 to-orange-500 flex items-center justify-end pr-6 text-white transition-opacity"
                    style={{ opacity: dragOffset < -10 ? 1 : 0, width: '100%' }}
                >
                    <div className="flex items-center gap-2 font-black text-sm">
                        <span>{task.priority === 'bloqueante' ? 'Bloqueada (Obligatoria)' : 'Omitir Tarea'}</span>
                        <Trash2 size={18} />
                    </div>
                </div>
            </div>

            {/* Capa de tarjeta superior */}
            <div
                onTouchStart={onTouchStart}
                onTouchMove={onTouchMove}
                onTouchEnd={onTouchEnd}
                onMouseDown={onMouseDown}
                onMouseMove={onMouseMove}
                onMouseUp={onMouseUp}
                onMouseLeave={onMouseLeave}
                className={`relative z-10 p-4 bg-white dark:bg-slate-900 border-none shadow-sm transition-transform cursor-grab active:cursor-grabbing ${
                    isCompleted ? 'opacity-85' : ''
                }`}
                style={{ 
                    transform: `translateX(${dragOffset}px)`,
                    transition: isDragging ? 'none' : 'transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1)' 
                }}
            >
                {/* Barra de progreso sutil detrás del contenido */}
                {isActive && (
                    <div 
                        className={`absolute left-0 bottom-0 top-0 pointer-events-none z-0 ${
                            isOvertime ? 'bg-rose-500/10' : 'bg-indigo-500/5'
                        }`}
                        style={{ width: `${percent}%` }}
                    />
                )}

                <div className="relative z-10 flex flex-col">
                    {/* Fila superior: Categorías / Badges */}
                    <div className="flex items-center justify-between mb-2">
                        <div className="flex items-center gap-1.5 flex-wrap">
                            <span className={`text-[9px] font-black uppercase px-2 py-0.5 rounded-full border ${catColors[task.category] || catColors.operativo}`}>
                                {task.category}
                            </span>

                            {task.priority === 'bloqueante' && (
                                <span className="text-[9px] font-black uppercase px-2 py-0.5 rounded-full bg-rose-500 text-white border border-rose-500 animate-pulse flex items-center gap-0.5">
                                    <Lock size={9} /> Obligatoria
                                </span>
                            )}

                            {assignment.assignedFromRoutineId && (
                                <span className="text-[9px] font-bold text-slate-505 dark:text-slate-400 bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded-full">
                                    🔁 {getRoutineName(assignment.assignedFromRoutineId)}
                                </span>
                            )}

                            {isAssignedToOther && (
                                <span className="text-[9px] font-black uppercase px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-700 border border-amber-300/30 flex items-center gap-1">
                                    <User size={10} /> Pausada por puesto
                                </span>
                            )}
                        </div>

                        {/* Status label */}
                        <div>
                            {isCompleted ? (
                                <span className="text-[10px] font-black text-emerald-600 bg-emerald-50 dark:bg-emerald-950/20 px-2 py-0.5 rounded-lg flex items-center gap-1">
                                    ✓ Listo
                                </span>
                            ) : isOmitted ? (
                                <span className="text-[10px] font-black text-slate-400 bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded-lg">
                                    Saltada
                                </span>
                            ) : isActive ? (
                                <span className="text-[10px] font-black text-indigo-600 dark:text-indigo-400 animate-pulse flex items-center gap-1">
                                    ⚡ En Proceso
                                </span>
                            ) : isPaused ? (
                                <span className="text-[10px] font-black text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/20 px-2 py-0.5 rounded-lg flex items-center gap-1">
                                    ⏸️ En Pausa
                                </span>
                            ) : (
                                <span className="text-[10px] font-medium text-slate-400 dark:text-slate-500 border border-slate-100 dark:border-slate-800 px-2 py-0.5 rounded-lg">
                                    Pendiente
                                </span>
                            )}
                        </div>
                    </div>

                    {/* Título de la tarea y estimación */}
                    <div className="flex justify-between items-start gap-4 mb-2">
                        <div className="flex-1 min-w-0">
                            <h3 className={`font-black text-sm text-slate-850 dark:text-slate-200 leading-snug ${isCompleted ? 'text-slate-450 line-through' : ''}`}>
                                {task.title}
                            </h3>
                            {task.description && (
                                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5 font-medium line-clamp-1">
                                    {task.description}
                                </p>
                            )}
                        </div>
                        <div className="text-right shrink-0">
                            <span className="text-xs font-black text-slate-500 dark:text-slate-400 flex items-center gap-1 justify-end">
                                <Clock size={11} /> {task.estimatedMins} min
                            </span>
                            {isActive && (
                                <span className={`text-[10px] font-black block mt-0.5 ${isOvertime ? 'text-rose-600 animate-pulse' : 'text-indigo-500'}`}>
                                    ⏱️ {elapsed} min
                                </span>
                            )}
                        </div>
                    </div>

                    {/* Fila de Progreso Visual */}
                    {isActive && (
                        <div className="w-full bg-slate-100 dark:bg-slate-800 h-1.5 rounded-full overflow-hidden mb-3">
                            <div 
                                className={`h-full rounded-full transition-all duration-305 ${
                                    isOvertime ? 'bg-rose-500 animate-pulse' : 'bg-gradient-to-r from-indigo-500 to-violet-500'
                                }`} 
                                style={{ width: `${percent}%` }}
                            />
                        </div>
                    )}

                    {/* Botones de acción directos (para no depender 100% de gestos) */}
                    <div className="flex flex-wrap items-center justify-between mt-2 pt-2 border-t border-slate-50 dark:border-slate-850 gap-2">
                        <div className="flex gap-2">
                            {(task.description || (task.subTasks && task.subTasks.length > 0)) && (
                                <button 
                                    onClick={() => setShowInstructions(!showInstructions)}
                                    className="text-[10px] font-bold px-2 py-1 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-750 text-slate-650 dark:text-slate-300 rounded-lg transition-colors"
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
                                                className="p-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-lg transition-all hover:scale-105"
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
                                                className="flex items-center gap-1 px-3 py-1 bg-emerald-500 hover:bg-emerald-600 text-white font-black text-[10px] rounded-lg transition-all hover:scale-105"
                                            >
                                                <Check size={12} /> Completar
                                            </button>
                                        </>
                                    ) : (
                                        <>
                                            {task.priority !== 'bloqueante' && (
                                                <button 
                                                    onClick={onOmit}
                                                    className="p-1.5 hover:bg-rose-50 text-slate-400 hover:text-rose-500 dark:hover:bg-rose-950/20 rounded-lg transition-colors"
                                                    title="Omitir Tarea"
                                                >
                                                    <Trash2 size={12} />
                                                </button>
                                            )}
                                            <button 
                                                onClick={onStart} 
                                                className="flex items-center gap-1 px-3.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-[10px] rounded-lg transition-all hover:scale-105 shadow-sm"
                                            >
                                                <Play size={10} className="fill-white" /> {isPaused ? 'Reanudar' : isAssignedToOther ? 'Continuar' : 'Iniciar'}
                                            </button>
                                        </>
                                    )}
                                </>
                            )}
                        </div>
                    </div>

                    {/* Desplegable de Instrucciones y Checklist */}
                    {showInstructions && (
                        <div className="mt-3 p-3 bg-slate-50 dark:bg-slate-955/50 border border-slate-100 dark:border-slate-800 rounded-xl space-y-3 text-xs">
                            {task.description && (
                                <div>
                                    <p className="font-extrabold text-slate-850 dark:text-slate-200">Detalles de ejecución:</p>
                                    <p className="text-slate-650 dark:text-slate-400 mt-0.5 leading-relaxed font-medium">{task.description}</p>
                                </div>
                            )}

                            {task.subTasks && task.subTasks.length > 0 && (
                                <div className="space-y-1">
                                    <p className="font-extrabold text-slate-850 dark:text-slate-200 mb-1">Checklist de Pasos Obligatorios:</p>
                                    {task.subTasks.map(sub => (
                                        <label key={sub.id} className="flex items-center gap-2 p-2 bg-white dark:bg-slate-900 rounded-lg border border-slate-100 dark:border-slate-800/80 hover:bg-indigo-50 dark:hover:bg-slate-850 cursor-pointer transition-colors shadow-sm">
                                            <input type="checkbox" className="w-3.5 h-3.5 text-indigo-600 rounded border-slate-300 dark:border-slate-700" />
                                            <span className="text-slate-700 dark:text-slate-355 font-semibold">{sub.text}</span>
                                        </label>
                                    ))}
                                </div>
                            )}

                            {/* Mini Asistente en Enfoque */}
                            {task.assistantType !== 'ninguno' && !isCompleted && !isOmitted && (
                                <div className="p-3 bg-indigo-500/5 dark:bg-indigo-950/10 border border-indigo-200/50 dark:border-indigo-900/30 rounded-xl space-y-2">
                                    <p className="font-black text-indigo-855 dark:text-indigo-400 flex items-center gap-1">
                                        <Bot size={13} className="text-indigo-600" /> Asistente Requerido
                                    </p>
                                    <p className="text-slate-650 dark:text-slate-400 font-bold text-[11px]">{task.assistantPrompt}</p>
                                    
                                    {task.assistantType === 'evidencia_foto' && (
                                        <div className="space-y-2">
                                            {!photoDone ? (
                                                <button 
                                                    onClick={() => { setPhotoDone(true); setLocalInput('evidencia_checador_foto.jpg'); }}
                                                    className="w-full py-2 bg-indigo-50 hover:bg-indigo-100 dark:bg-slate-800 dark:hover:bg-slate-750 text-indigo-700 dark:text-indigo-400 rounded-lg border border-indigo-200/60 dark:border-indigo-900/40 text-[10px] font-black flex items-center justify-center gap-1.5 transition-colors"
                                                >
                                                    <Camera size={13} /> Capturar Foto de Evidencia
                                                </button>
                                            ) : (
                                                <div className="flex items-center gap-2 p-2 bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-100 dark:border-emerald-900/30 rounded-lg text-emerald-800 dark:text-emerald-400">
                                                    <Check size={14} className="text-emerald-600" />
                                                    <span className="font-extrabold truncate">evidencia_checador_foto.jpg</span>
                                                    <button onClick={() => { setPhotoDone(false); setLocalInput(''); }} className="ml-auto text-[10px] font-black underline text-slate-500 hover:text-slate-700">Cambiar</button>
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
                                            className="w-full p-2 border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-xs font-semibold"
                                        />
                                    )}

                                    {task.assistantType === 'texto' && (
                                        <input 
                                            type="text"
                                            value={localInput}
                                            onChange={e => setLocalInput(e.target.value)}
                                            placeholder="Escribe reporte de fin de tarea..."
                                            className="w-full p-2 border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-xs font-semibold"
                                        />
                                    )}

                                    <button 
                                        onClick={submitAssistant}
                                        disabled={!localInput}
                                        className="w-full py-2 bg-indigo-600 disabled:bg-slate-100 disabled:text-slate-400 hover:bg-indigo-700 text-white rounded-lg text-[10px] font-black shadow-sm transition-colors cursor-pointer border-none"
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
    const [activeTab, setActiveTab] = useState<'hoy' | 'puesto' | 'supervisar' | 'historial'>('hoy');
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedAssignmentId, setSelectedAssignmentId] = useState<string | null>(null);
    
    // Modal de creación de tareas
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [newTitle, setNewTitle] = useState('');
    const [newDesc, setNewDesc] = useState('');
    const [newMins, setNewMins] = useState(15);
    const [newPriority, setNewPriority] = useState<'normal' | 'bloqueante'>('normal');
    const [newTargetRole, setNewTargetRole] = useState<number>(currentUser.job_role_id || 6);

    // Estado local para feedback de rechazo en supervisión
    const [rejectingAssignmentId, setRejectingAssignmentId] = useState<string | null>(null);
    const [rejectFeedback, setRejectFeedback] = useState('');

    // Sistema de notificaciones toast interno
    const [toast, setToast] = useState<{ message: string, type: 'info' | 'success' | 'warning' } | null>(null);

    const showToast = (message: string, type: 'info' | 'success' | 'warning') => {
        setToast({ message, type });
    };

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

    // Determinar si tiene rol de supervisor
    const isSupervisor = currentUser?.role?.toLowerCase().includes('superv') || 
                         currentUser?.role?.toLowerCase().includes('geren') || 
                         currentUser?.role?.toLowerCase().includes('admin');

    // 1. Tareas de hoy (Checklist del colaborador logueado)
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

    // 3. Tareas esperando validación (solo visibles e interesantes para supervisores)
    // Mostramos todas las que estén en awaiting_validation, usualmente de otros colaboradores.
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
        setShowCreateModal(false);
    };

    // Acciones de Validación de Supervisor
    const handleApprove = async (assignmentId: string, taskTitle: string) => {
        await validateTaskAssignment(assignmentId, 'completed');
        showToast(`Tarea "${taskTitle}" aprobada`, 'success');
    };

    const handleReject = async (assignmentId: string, taskTitle: string) => {
        if (!rejectFeedback.trim()) {
            showToast("Debes escribir una razón para rechazar la tarea.", 'warning');
            return;
        }
        await validateTaskAssignment(assignmentId, 'in_progress', rejectFeedback);
        showToast(`Tarea "${taskTitle}" regresada a En Progreso`, 'info');
        setRejectingAssignmentId(null);
        setRejectFeedback('');
    };

    // Calcular estadísticas generales de productividad hoy
    const totalTodayCount = myAssignments.length;
    const completedTodayCount = myAssignments.filter(a => a.status === 'completed' || a.status === 'awaiting_validation').length;
    const productivityPercent = totalTodayCount > 0 ? Math.round((completedTodayCount / totalTodayCount) * 100) : 0;

    return (
        <div className="flex flex-col h-full bg-slate-50 dark:bg-slate-950 font-sans">
            {/* Header del panel */}
            {!hideHeader && (
                <div className="flex justify-between items-center p-4 border-b border-slate-100 dark:border-slate-900 bg-white dark:bg-slate-900 sticky top-0 z-20 shadow-sm shrink-0">
                    <button onClick={onBack} className="text-sm font-black text-indigo-600 hover:text-indigo-850 dark:text-indigo-400">
                        ← Volver al Reloj
                    </button>
                    <span className="text-xs font-black uppercase bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 px-3 py-1 rounded-full">
                        Tablero de Tareas
                    </span>
                </div>
            )}

            {/* Dashboard / Resumen de productividad */}
            <div className="p-4 sm:p-6 bg-gradient-to-br from-indigo-900 via-indigo-950 to-slate-950 text-white shrink-0 relative overflow-hidden">
                <div className="absolute right-0 bottom-0 top-0 w-1/3 bg-radial-gradient opacity-10 pointer-events-none"></div>
                <div className="relative z-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <p className="text-[10px] uppercase tracking-widest font-black text-indigo-300">Resumen de Productividad</p>
                        <h2 className="text-xl sm:text-2xl font-black mt-0.5">¡Hola, {currentUser?.name}!</h2>
                        <p className="text-xs text-indigo-200/80 mt-1 font-semibold">
                            Tu puesto es: <span className="text-indigo-300 underline decoration-indigo-400">{getRoleName(currentUser.job_role_id)}</span>
                        </p>
                    </div>

                    <div className="flex items-center gap-4">
                        <div className="flex flex-col text-right">
                            <span className="text-2xl font-black">{completedTodayCount}/{totalTodayCount}</span>
                            <span className="text-[9px] uppercase font-black text-indigo-300">Tareas Resueltas ({productivityPercent}%)</span>
                        </div>
                        <div className="w-16 h-2 bg-indigo-900/60 rounded-full overflow-hidden shrink-0 border border-indigo-800/30">
                            <div className="h-full bg-emerald-400 rounded-full" style={{ width: `${productivityPercent}%` }}></div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Selector de Pestañas & Buscador */}
            <div className="p-4 bg-white dark:bg-slate-900 border-b border-slate-100 dark:border-slate-900 shrink-0 sticky top-0 z-20 shadow-sm flex flex-col gap-3">
                <div className="flex justify-between items-center gap-3">
                    <div className="flex bg-slate-100 dark:bg-slate-955 p-1 rounded-xl w-full sm:w-auto overflow-x-auto whitespace-nowrap scrollbar-none">
                        <button 
                            onClick={() => setActiveTab('hoy')} 
                            className={`flex-1 sm:flex-initial text-center px-4 py-2 font-black text-xs rounded-lg transition-all flex items-center justify-center gap-1.5 ${
                                activeTab === 'hoy' 
                                    ? 'bg-white dark:bg-slate-900 text-indigo-650 dark:text-indigo-400 shadow-sm' 
                                    : 'text-slate-500 hover:text-slate-700'
                            }`}
                        >
                            📋 Hoy
                            {activeAssignmentsFiltered.length > 0 && (
                                <span className="bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300 font-extrabold text-[9px] px-1.5 py-0.5 rounded-full">
                                    {activeAssignmentsFiltered.length}
                                </span>
                            )}
                        </button>

                        <button 
                            onClick={() => setActiveTab('puesto')} 
                            className={`flex-1 sm:flex-initial text-center px-4 py-2 font-black text-xs rounded-lg transition-all flex items-center justify-center gap-1.5 ${
                                activeTab === 'puesto' 
                                    ? 'bg-white dark:bg-slate-900 text-indigo-650 dark:text-indigo-400 shadow-sm' 
                                    : 'text-slate-500 hover:text-slate-700'
                            }`}
                        >
                            💼 Puesto & Bolsa
                            {puestoAssignmentsFiltered.length > 0 && (
                                <span className="bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 font-extrabold text-[9px] px-1.5 py-0.5 rounded-full animate-pulse">
                                    {puestoAssignmentsFiltered.length}
                                </span>
                            )}
                        </button>

                        {/* Nueva Pestaña de Supervisión */}
                        {isSupervisor && (
                            <button 
                                onClick={() => setActiveTab('supervisar')} 
                                className={`flex-1 sm:flex-initial text-center px-4 py-2 font-black text-xs rounded-lg transition-all flex items-center justify-center gap-1.5 ${
                                    activeTab === 'supervisar' 
                                        ? 'bg-white dark:bg-slate-900 text-indigo-650 dark:text-indigo-400 shadow-sm' 
                                        : 'text-slate-500 hover:text-slate-700'
                                }`}
                            >
                                🔍 Supervisar
                                {awaitingValidationFiltered.length > 0 && (
                                    <span className="bg-rose-100 dark:bg-rose-900/60 text-rose-700 dark:text-rose-350 font-black text-[9.5px] px-1.5 py-0.5 rounded-full animate-pulse">
                                        {awaitingValidationFiltered.length}
                                    </span>
                                )}
                            </button>
                        )}

                        <button 
                            onClick={() => setActiveTab('historial')} 
                            className={`flex-1 sm:flex-initial text-center px-4 py-2 font-black text-xs rounded-lg transition-all flex items-center justify-center gap-1.5 ${
                                activeTab === 'historial' 
                                    ? 'bg-white dark:bg-slate-900 text-indigo-650 dark:text-indigo-400 shadow-sm' 
                                    : 'text-slate-500 hover:text-slate-700'
                            }`}
                        >
                            ✓ Historial
                        </button>
                    </div>

                    {/* Botón generar tarea (Supervisores) */}
                    {isSupervisor && (
                        <button 
                            onClick={() => setShowCreateModal(true)}
                            className="bg-indigo-600 hover:bg-indigo-700 text-white font-black text-xs px-3.5 py-2 rounded-xl flex items-center gap-1 shadow-sm transition-all hover:scale-105 active:scale-95 shrink-0"
                            title="Crear Nueva Tarea Rápida"
                        >
                            <Plus size={14} /> Crear Tarea
                        </button>
                    )}
                </div>

                {/* Input de Búsqueda */}
                <div className="relative">
                    <input 
                        type="text" 
                        placeholder="Buscar tareas por título..."
                        value={searchQuery}
                        onChange={e => setSearchQuery(e.target.value)}
                        className="w-full pl-3 pr-8 py-2 border border-slate-200 dark:border-slate-850 bg-slate-50 dark:bg-slate-955 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none text-xs font-semibold"
                    />
                    {searchQuery && (
                        <button onClick={() => setSearchQuery('')} className="absolute right-2.5 top-2.5 text-slate-400 hover:text-slate-655">
                            <X size={14} />
                        </button>
                    )}
                </div>
            </div>

            {/* Listado de tareas */}
            <div className="flex-1 p-4 sm:p-6 overflow-y-auto custom-scrollbar">
                {activeTab === 'hoy' && (
                    <>
                        <h3 className="text-xs font-black uppercase text-slate-400 tracking-wider mb-3">
                            Mis asignaciones prioritarias de hoy
                        </h3>
                        {activeAssignmentsFiltered.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center bg-white dark:bg-slate-900 rounded-3xl border-2 border-dashed border-slate-100 dark:border-slate-800 p-6 shadow-sm">
                                <Coffee size={36} className="text-indigo-400 mb-2 animate-bounce" />
                                <p className="text-sm font-black text-slate-700 dark:text-slate-350">¡Todo al día!</p>
                                <p className="text-xs text-slate-505 mt-1">
                                    No tienes tareas personales de hoy asignadas o pendientes por iniciar.
                                </p>
                            </div>
                        ) : (
                            activeAssignmentsFiltered.map(a => {
                                const t = tasks.find(tsk => tsk.id === a.taskId);
                                if (!t) return null;

                                const restriction = getRoutineTimeRestriction(a.assignedFromRoutineId);
                                const isCurrentlyLocked = restriction 
                                    ? (globalSimTime < restriction.startMin || globalSimTime > restriction.endMin)
                                    : false;

                                return (
                                    <SwipeableTaskCard 
                                        key={a.id}
                                        assignment={a}
                                        task={t}
                                        currentUser={currentUser}
                                        globalSimTime={globalSimTime}
                                        restriction={restriction}
                                        isCurrentlyLocked={isCurrentlyLocked}
                                        onStart={() => startTask(a.id, globalSimTime)}
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

                {activeTab === 'puesto' && (
                    <>
                        <div className="bg-amber-50 dark:bg-amber-950/20 border border-amber-200/50 dark:border-amber-900/30 rounded-2xl p-3.5 mb-4 text-xs flex gap-2">
                            <HelpCircle className="text-amber-600 shrink-0 mt-0.5" size={16} />
                            <div className="leading-relaxed">
                                <p className="font-extrabold text-amber-800 dark:text-amber-400">Trabajo Colaborativo por Puesto:</p>
                                <p className="text-amber-700/90 dark:text-slate-400 font-medium">
                                    Aquí aparecen tareas libres o que fueron pausadas por otros compañeros de tu mismo puesto. Puedes deslizarlas a la derecha para continuarlas o tomarlas en tu cronómetro personal.
                                </p>
                            </div>
                        </div>

                        <h3 className="text-xs font-black uppercase text-slate-400 tracking-wider mb-3">
                            Tareas disponibles para tu puesto y bolsa general
                        </h3>

                        {puestoAssignmentsFiltered.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center bg-white dark:bg-slate-900 rounded-3xl border-2 border-dashed border-slate-100 dark:border-slate-800 p-6 shadow-sm">
                                <AlertCircle size={36} className="text-slate-355 mb-2" />
                                <p className="text-sm font-black text-slate-700 dark:text-slate-355">Bolsa Vacía</p>
                                <p className="text-xs text-slate-505 mt-1">
                                    No hay tareas libres o en espera de reanudación en este momento para tu puesto.
                                </p>
                            </div>
                        ) : (
                            puestoAssignmentsFiltered.map(a => {
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
                                        onStart={() => grabTaskFromPool(a.id, currentUser.id, globalSimTime)}
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

                {/* Vista de Supervisión (Sólo para supervisores) */}
                {activeTab === 'supervisar' && isSupervisor && (
                    <>
                        <h3 className="text-xs font-black uppercase text-slate-400 tracking-wider mb-3">
                            Tareas que requieren tu validación y firma
                        </h3>

                        {awaitingValidationFiltered.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center bg-white dark:bg-slate-900 rounded-3xl border-2 border-dashed border-slate-100 dark:border-slate-800 p-6 shadow-sm">
                                <CheckCircle size={36} className="text-emerald-500 mb-2 animate-bounce" />
                                <p className="text-sm font-black text-slate-700 dark:text-slate-350">¡Todo revisado!</p>
                                <p className="text-xs text-slate-505 mt-1">
                                    No hay tareas pendientes de validación por parte de tus colaboradores.
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                {awaitingValidationFiltered.map(a => {
                                    const t = tasks.find(tsk => tsk.id === a.taskId);
                                    const worker = globalUsers?.find(u => u.id === a.userId);
                                    if (!t) return null;

                                    const isRejecting = rejectingAssignmentId === a.id;

                                    return (
                                        <div 
                                            key={a.id} 
                                            className="bg-white dark:bg-slate-900 border border-slate-150 dark:border-slate-800 rounded-2xl p-4 shadow-sm relative overflow-hidden"
                                        >
                                            {/* Badge decorativo superior */}
                                            <div className="absolute top-0 left-0 w-full h-1 bg-amber-500"></div>

                                            <div className="flex justify-between items-start gap-4 mb-2">
                                                <div>
                                                    <span className="text-[9px] font-black uppercase px-2 py-0.5 rounded bg-amber-100 text-amber-800 dark:bg-amber-950/30 dark:text-amber-400 border border-amber-200/20">
                                                        Pendiente de Firma
                                                    </span>
                                                    <h4 className="font-black text-sm text-slate-850 dark:text-slate-200 mt-1.5 leading-snug">
                                                        {t.title}
                                                    </h4>
                                                    <p className="text-xs text-slate-500 dark:text-slate-400 font-bold mt-1 flex items-center gap-1">
                                                        <User size={12} className="text-slate-400" />
                                                        Colaborador: <span className="text-slate-800 dark:text-slate-200 font-black">{worker?.name || `Usuario #${a.userId}`}</span> 
                                                        ({getRoleName(worker?.job_role_id)})
                                                    </p>
                                                </div>
                                                <div className="text-right shrink-0">
                                                    <span className="text-xs font-black text-slate-500 dark:text-slate-400">
                                                        ⏱️ {a.accumulatedMins || t.estimatedMins} min real
                                                    </span>
                                                </div>
                                            </div>

                                            {/* Reporte o Evidencia subida */}
                                            {a.assistantData && (
                                                <div className="my-3 p-3 bg-slate-50 dark:bg-slate-950/40 rounded-xl border border-slate-100 dark:border-slate-800 text-xs space-y-1">
                                                    <p className="font-extrabold text-indigo-750 dark:text-indigo-400 flex items-center gap-1.5">
                                                        <Bot size={13} /> Evidencia presentada:
                                                    </p>
                                                    <p className="text-slate-700 dark:text-slate-350 font-black bg-white dark:bg-slate-900 p-2 rounded-lg border border-slate-100 dark:border-slate-850 leading-relaxed">
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
                                                <div className="mt-3 p-3 bg-rose-500/5 border border-rose-200 dark:border-rose-900/30 rounded-xl space-y-2.5 animate-in slide-in-from-top-2 duration-150">
                                                    <p className="text-xs font-black text-rose-800 dark:text-rose-400">
                                                        Razón de devolución / Corrección requerida:
                                                    </p>
                                                    <textarea 
                                                        value={rejectFeedback}
                                                        onChange={e => setRejectFeedback(e.target.value)}
                                                        placeholder="Ej: Te faltó sanitizar el teclado del mostrador, por favor vuelve a hacerlo..."
                                                        rows={2}
                                                        className="w-full p-2 border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 rounded-lg outline-none text-xs font-semibold focus:ring-2 focus:ring-rose-500"
                                                    />
                                                    <div className="flex gap-2 justify-end">
                                                        <button 
                                                            type="button"
                                                            onClick={() => { setRejectingAssignmentId(null); setRejectFeedback(''); }}
                                                            className="px-3 py-1.5 bg-white dark:bg-slate-800 text-slate-500 rounded-lg text-[10px] font-black border border-slate-200 dark:border-slate-700 cursor-pointer"
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
                                                <div className="flex gap-2 justify-end mt-3 pt-2 border-t border-slate-50 dark:border-slate-850">
                                                    <button 
                                                        type="button"
                                                        onClick={() => setRejectingAssignmentId(a.id)}
                                                        className="flex items-center gap-1 px-3 py-1.5 hover:bg-rose-50 dark:hover:bg-rose-950/20 text-rose-600 rounded-lg text-[10px] font-black border border-rose-200/50 dark:border-rose-900/30 cursor-pointer"
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
                            <div className="flex flex-col items-center justify-center py-12 text-center bg-white dark:bg-slate-900 rounded-3xl border-2 border-dashed border-slate-100 dark:border-slate-800 p-6 shadow-sm">
                                <AlertCircle size={36} className="text-slate-300 mb-2" />
                                <p className="text-sm font-black text-slate-700 dark:text-slate-355">Historial Vacío</p>
                                <p className="text-xs text-slate-505 mt-1">
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

            {/* Alerta flotante de Toast */}
            {toast && (
                <div className="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 bg-slate-900 text-white px-4 py-2.5 rounded-xl shadow-lg flex items-center gap-2 border border-slate-850 animate-in fade-in slide-in-from-bottom-3 duration-250">
                    <span className="text-xs font-black">{toast.message}</span>
                </div>
            )}

            {/* Modal de creación de tareas (Para supervisores) */}
            {showCreateModal && (
                <div className="fixed inset-0 bg-slate-950/40 backdrop-blur-xs z-50 flex items-center justify-center p-4 animate-fade-in">
                    <div className="bg-white dark:bg-slate-900 rounded-3xl p-6 shadow-xl border border-slate-100 dark:border-slate-800 w-full max-w-md animate-in zoom-in-95 duration-200">
                        <div className="flex justify-between items-center mb-4">
                            <h3 className="text-lg font-black text-slate-855 dark:text-slate-200">Crear Tarea en Bolsa</h3>
                            <button 
                                onClick={() => setShowCreateModal(false)}
                                className="w-7 h-7 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400 hover:text-slate-600 flex items-center justify-center border-none cursor-pointer"
                            >
                                <X size={15} />
                            </button>
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
                                    className="w-full p-2.5 border border-slate-200 dark:border-slate-850 bg-slate-50 dark:bg-slate-955 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none text-xs font-semibold"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-black text-slate-500 uppercase mb-1.5">
                                    Puesto Responsable
                                </label>
                                <select 
                                    value={newTargetRole}
                                    onChange={e => setNewTargetRole(Number(e.target.value))}
                                    className="w-full p-2.5 border border-slate-200 dark:border-slate-850 bg-slate-50 dark:bg-slate-955 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none text-xs font-semibold"
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
                                        className="w-full p-2.5 border border-slate-200 dark:border-slate-850 bg-slate-50 dark:bg-slate-955 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none text-xs font-semibold"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-black text-slate-500 uppercase mb-1.5">
                                        Prioridad
                                    </label>
                                    <select 
                                        value={newPriority}
                                        onChange={e => setNewPriority(e.target.value as any)}
                                        className="w-full p-2.5 border border-slate-200 dark:border-slate-850 bg-slate-50 dark:bg-slate-955 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none text-xs font-semibold"
                                    >
                                        <option value="normal">Normal</option>
                                        <option value="bloqueante">Bloqueante (Obligatoria)</option>
                                    </select>
                                </div>
                            </div>

                            <button 
                                type="submit" 
                                className="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-black text-xs rounded-xl shadow-md transition-all active:scale-95 border-none cursor-pointer mt-2"
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
