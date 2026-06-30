import React, { useState } from 'react';
import { Coffee, ArrowDown, Briefcase, Play, Check, Clock, Lock, Brain, Camera, Bot, Pause } from 'lucide-react';
import { useTaskStore } from '../../store/useTaskStore';
import type { Task, TaskAssignment } from '../../store/useTaskStore';
import { useAppStore } from '../../store/useAppStore';

export function TaskRunner({ currentUser, onBack, hideHeader }: { currentUser: any, onBack: () => void, hideHeader?: boolean }) {
    const { tasks, routines, assignments, grabTaskFromPool, startTask, pauseTask, completeTask } = useTaskStore();
    const { globalSimTime, addMatrixEvent } = useAppStore();

    const [assistantInput, setAssistantInput] = useState('');
    const [showInstructions, setShowInstructions] = useState(false);
    const [selectedAssignmentId, setSelectedAssignmentId] = useState<string | null>(null);
    const [showPhotoPrompt, setShowPhotoPrompt] = useState(false);

    const getRoutineTimeRestriction = (assignedFromRoutineId?: number) => {
        if (!assignedFromRoutineId) return null;
        const r = routines.find(rt => rt.id === assignedFromRoutineId);
        if (!r) return null;
        
        const titleLower = r.title.toLowerCase();
        if (titleLower.includes('apertura')) {
            return {
                type: 'apertura',
                label: 'Apertura (06:00 - 12:00)',
                startMin: 6 * 60, // 360
                endMin: 12 * 60,  // 720
            };
        } else if (titleLower.includes('cierre')) {
            return {
                type: 'cierre',
                label: 'Cierre (18:00 - 23:59)',
                startMin: 18 * 60, // 1080
                endMin: 24 * 60,   // 1440
            };
        }
        return null;
    };

    // Filtrar asignaciones del usuario (Todas: Pendientes, En Progreso, Pausadas, Completadas y en Validación)
    const myAssignments = assignments.filter(a => a.userId === currentUser.id && ['pending', 'in_progress', 'paused', 'completed', 'awaiting_validation'].includes(a.status));
    
    // Tarea activa (la que está en in_progress o paused)
    const activeAssignment = myAssignments.find(a => a.status === 'in_progress') || myAssignments.find(a => a.status === 'paused');
    
    // Tarea actual enfocada:
    // 1. Si el usuario seleccionó una, usamos esa.
    // 2. Si no, y hay una activa, usamos la activa.
    // 3. Si no, usamos la primera pendiente.
    // 4. Si no, la primera de la lista.
    const currentAssignment = myAssignments.find(a => a.id === selectedAssignmentId) 
        || activeAssignment 
        || myAssignments.find(a => a.status === 'pending') 
        || myAssignments[0];

    const currentTask = currentAssignment ? tasks.find(t => t.id === currentAssignment.taskId) : null;

    React.useEffect(() => {
        setShowPhotoPrompt(false);
        setAssistantInput('');
    }, [currentAssignment?.id]);

    // Bolsa de Trabajo (Tareas huérfanas) filtradas por puesto
    const poolAssignments = assignments.filter(a => {
        if (a.userId !== null || a.status !== 'pending') return false;
        const t = tasks.find(tsk => tsk.id === a.taskId);
        if (!t) return false;
        // Si no tiene targetId, cualquiera puede tomarla. Si tiene, debe coincidir con el del empleado
        return t.targetId === null || t.targetId === undefined || Number(t.targetId) === Number(currentUser.job_role_id);
    });

    const handleComplete = () => {
        if (!currentAssignment || !currentTask) return;
        
        // Validación del Asistente
        if (currentTask.assistantType === 'evidencia_foto' && !assistantInput.trim()) {
            setShowPhotoPrompt(true);
            return;
        }

        if (currentTask.assistantType !== 'ninguno' && !assistantInput.trim()) {
            addMatrixEvent('Validación Fallida', `Debes completar el asistente: ${currentTask.assistantPrompt}`, 'error', currentUser.id);
            return;
        }

        completeTask(currentAssignment.id, globalSimTime, assistantInput);
        setAssistantInput('');
        setShowPhotoPrompt(false);
        addMatrixEvent('✅ Tarea Completada', `El empleado terminó la tarea: ${currentTask.title}`, 'success', currentUser.id);
        
        // Reset selected assignment to null to auto-advance focus to the next logical task
        setSelectedAssignmentId(null);
    };

    const formatTime = (mins: number) => {
        const h = Math.floor(mins / 60);
        const m = mins % 60;
        return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}`;
    };

    return (
        <div className="flex flex-col h-full animate-fade-in custom-scrollbar overflow-y-auto">
            {/* Header */}
            {!hideHeader && (
                <div className="flex justify-between items-center mb-6">
                    <button onClick={onBack} className="text-sm font-bold text-slate-500 hover:text-slate-700">← Volver al Reloj</button>
                    <div className="px-4 py-1 bg-emerald-100 text-emerald-800 rounded-full text-xs font-black">MODO ENFOQUE</div>
                </div>
            )}

            {/* MODO ENFOQUE */}
            <div className="flex-1 flex flex-col">
                {currentTask && currentAssignment ? (() => {
                    const elapsed = currentAssignment.status === 'pending' ? 0 : 
                        ((currentAssignment.accumulatedMins || 0) + 
                        (currentAssignment.status === 'in_progress' && currentAssignment.startedAtMins ? (globalSimTime - currentAssignment.startedAtMins) : 0));
                    const percent = Math.min(100, Math.max(0, (elapsed / currentTask.estimatedMins) * 100));
                    const isOvertime = currentAssignment.status !== 'pending' && elapsed > currentTask.estimatedMins;

                    // Time restriction
                    const restriction = getRoutineTimeRestriction(currentAssignment.assignedFromRoutineId);
                    const isLockedByTime = restriction 
                        ? (globalSimTime < restriction.startMin || globalSimTime > restriction.endMin)
                        : false;
                    
                    const isCompleted = currentAssignment.status === 'completed' || currentAssignment.status === 'awaiting_validation';

                    return (
                        <div className="bg-white border-2 border-indigo-100 rounded-2xl p-4 shadow-md mb-4 relative overflow-hidden transition-all duration-300">
                            {currentTask.priority === 'bloqueante' && (
                                <div className="absolute top-0 left-0 w-full h-1 bg-rose-500 z-10"></div>
                            )}

                            {/* Fondo de Progreso */}
                            {currentAssignment.status !== 'pending' && (
                                <div 
                                    className={`absolute left-0 top-0 bottom-0 transition-all duration-500 z-0 ${
                                        isOvertime 
                                            ? 'bg-rose-50/70 animate-pulse' 
                                            : currentAssignment.status === 'paused' 
                                            ? 'bg-amber-50/70' 
                                            : 'bg-indigo-50/60'
                                    }`}
                                    style={{ width: `${percent}%` }}
                                />
                            )}

                            {/* Contenido principal en capa superior */}
                            <div className="relative z-10">
                                {/* Fila superior: Tarea Actual + Categoria e Indicador de estado */}
                                <div className="flex items-center justify-between mb-2">
                                    <div className="flex items-center gap-2">
                                        <span className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Tarea Actual</span>
                                        <span className={`text-[9px] font-black uppercase px-2 py-0.5 rounded-full ${
                                            currentTask.category === 'operativo' ? 'bg-emerald-100 text-emerald-700' : 
                                            currentTask.category === 'administrativo' ? 'bg-blue-100 text-blue-700' : 
                                            currentTask.category === 'supervision' ? 'bg-purple-100 text-purple-700' : 
                                            'bg-orange-100 text-orange-700'
                                        }`}>
                                            {currentTask.category}
                                        </span>
                                    </div>
                                    {currentAssignment.status !== 'pending' && (
                                        <span className={`text-[10px] font-black uppercase tracking-wider flex items-center gap-1 ${
                                            isOvertime ? 'text-rose-600 animate-pulse' : 
                                            currentAssignment.status === 'paused' ? 'text-amber-600' : 
                                            'text-indigo-600'
                                        }`}>
                                            {isOvertime ? '🚨 Excedido' : currentAssignment.status === 'paused' ? '⏸️ Pausada' : '⚡ En progreso'}
                                        </span>
                                    )}
                                </div>

                                {/* Título de la Tarea (Más pequeño) */}
                                <h1 className="text-lg font-black text-slate-800 leading-tight mb-2">{currentTask.title}</h1>

                                {/* Indicadores y botón de proceso */}
                                <div className="flex flex-wrap items-center gap-1.5 text-[11px] mb-3 text-slate-600 font-bold">
                                    <span className="px-2 py-0.5 bg-slate-100 border border-slate-200 rounded flex items-center gap-1">
                                        <Clock size={11} /> {currentTask.estimatedMins} min est.
                                    </span>
                                    {currentAssignment.status !== 'pending' && (
                                        <span className="px-2 py-0.5 bg-slate-100/90 border border-slate-200/50 rounded">
                                            ⏱️ {elapsed} / {currentTask.estimatedMins} min ({Math.round(percent)}%)
                                        </span>
                                    )}
                                    {currentTask.priority === 'bloqueante' && (
                                        <span className="px-2 py-0.5 bg-rose-100 border border-rose-200 text-rose-700 rounded flex items-center gap-0.5">
                                            <Lock size={11} /> Bloqueante
                                        </span>
                                    )}
                                    {currentTask.isAutoCapture && (
                                        <span className="px-2 py-0.5 bg-blue-100 border border-blue-200 text-blue-700 rounded flex items-center gap-0.5">
                                            <Brain size={11} /> Autocaptura
                                        </span>
                                    )}
                                    {/* Botón para desplegar proceso */}
                                    {(currentTask.description || (currentTask.subTasks && currentTask.subTasks.length > 0)) && (
                                        <button 
                                            onClick={() => setShowInstructions(!showInstructions)} 
                                            className={`ml-auto px-2 py-0.5 rounded border font-black text-[11px] transition-colors flex items-center gap-0.5 ${
                                                showInstructions 
                                                    ? 'bg-slate-700 text-white border-slate-700' 
                                                    : 'bg-indigo-50 border-indigo-100 text-indigo-600 hover:bg-indigo-100'
                                            }`}
                                        >
                                            {showInstructions ? 'Ocultar Proceso ▴' : 'Ver Proceso ▾'}
                                        </button>
                                    )}
                                </div>

                                {/* Panel Desplegable de Instrucciones y Checklist de Pasos */}
                                {showInstructions && (
                                    <div className="mb-3 p-3 bg-slate-50/95 border border-slate-200 rounded-xl space-y-2 text-xs relative z-20">
                                        {currentTask.description && (
                                            <div>
                                                <p className="font-bold text-slate-800">Descripción / Instrucciones:</p>
                                                <p className="text-slate-600 font-medium leading-relaxed">{currentTask.description}</p>
                                            </div>
                                        )}
                                        {currentTask.subTasks && currentTask.subTasks.length > 0 && (
                                            <div className="space-y-1">
                                                <p className="font-bold text-slate-800">Pasos para efectuar la tarea:</p>
                                                {currentTask.subTasks.map(sub => (
                                                    <label key={sub.id} className="flex items-center gap-2 p-2 bg-white rounded-lg border border-slate-100 cursor-pointer hover:bg-indigo-50 transition-colors shadow-sm">
                                                        <input type="checkbox" className="w-4 h-4 text-indigo-600 rounded focus:ring-indigo-500" />
                                                        <span className="text-slate-700 font-medium">{sub.text}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* Mini-Asistente */}
                                {currentTask.assistantType !== 'ninguno' && (
                                    <>
                                        {/* Flujo de Fotos (Oculto por defecto, se activa en showPhotoPrompt) */}
                                        {currentTask.assistantType === 'evidencia_foto' && showPhotoPrompt && (
                                            <div className="mb-3 p-3.5 bg-amber-50 border border-amber-200 rounded-xl text-xs animate-in slide-in-from-top-2 duration-200">
                                                <p className="font-bold text-amber-800 mb-1 flex items-center gap-1.5">
                                                    <Bot size={13} /> Asistente de Foto Requerido:
                                                </p>
                                                <p className="text-amber-700 mb-2.5 font-semibold">{currentTask.assistantPrompt}</p>
                                                
                                                {!assistantInput ? (
                                                    <div>
                                                        <button 
                                                            type="button"
                                                            onClick={() => setAssistantInput('foto_evidencia_simulada.jpg')}
                                                            className="w-full py-2.5 bg-amber-100 hover:bg-amber-200 text-amber-900 font-black rounded-lg border border-amber-300 flex items-center justify-center gap-1 text-[11px] transition-all hover:scale-[1.01]"
                                                        >
                                                            <Camera size={13} className="text-amber-800" /> Toca para simular tomar foto
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => setShowPhotoPrompt(false)}
                                                            className="w-full mt-2 text-center text-[10px] text-slate-500 font-bold hover:text-slate-700 hover:underline"
                                                        >
                                                            Cancelar
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <div className="bg-emerald-50/50 border border-emerald-100 rounded-xl p-2.5 text-xs text-emerald-800">
                                                        <p className="font-extrabold flex items-center gap-1.5 mb-2">
                                                            <Check size={14} className="text-emerald-600" />
                                                            Evidencia fotográfica capturada
                                                        </p>
                                                        <div className="bg-white/80 rounded-lg p-2 flex items-center gap-2 border border-emerald-100 shadow-sm">
                                                            <div className="w-10 h-10 rounded bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-400 overflow-hidden shrink-0">
                                                                <Camera size={18} className="text-slate-400" />
                                                            </div>
                                                            <div className="min-w-0 flex-1">
                                                                <p className="text-[10px] font-black text-emerald-950 truncate">foto_evidencia_simulada.jpg</p>
                                                                <p className="text-[9px] text-emerald-700/80 font-bold">Simulación completada con éxito</p>
                                                            </div>
                                                        </div>
                                                        <div className="mt-3 flex gap-2">
                                                            <button
                                                                type="button"
                                                                onClick={() => setAssistantInput('')}
                                                                className="flex-1 py-1.5 bg-white hover:bg-slate-50 text-slate-600 border border-slate-200 rounded-lg font-bold text-[10px] transition-colors"
                                                            >
                                                                Volver a Tomar
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={handleComplete}
                                                                className="flex-1 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-black text-[10px] shadow-sm transition-colors"
                                                            >
                                                                Confirmar y Terminar
                                                            </button>
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        )}

                                        {/* Flujo de Cantidades/Texto (Siempre visible durante ejecución) */}
                                        {currentTask.assistantType !== 'evidencia_foto' && currentAssignment.status !== 'pending' && (
                                            <div className="mb-3 p-3 bg-indigo-50/60 border border-indigo-100 rounded-xl text-xs">
                                                <p className="font-bold text-indigo-800 mb-1 flex items-center gap-1.5">
                                                    <Bot size={13} className="text-indigo-600" /> Asistente de Reporte Requerido:
                                                </p>
                                                <p className="text-indigo-700 mb-2 font-semibold">{currentTask.assistantPrompt}</p>
                                                {currentTask.assistantType === 'captura_numero' && (
                                                    <input 
                                                        type="number" 
                                                        value={assistantInput} 
                                                        onChange={e => setAssistantInput(e.target.value)} 
                                                        className="w-full p-2 bg-white rounded-lg border border-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 text-xs font-semibold text-slate-800 shadow-sm" 
                                                        placeholder="Ingresa la cantidad..." 
                                                    />
                                                )}
                                                {currentTask.assistantType === 'texto' && (
                                                    <input 
                                                        type="text" 
                                                        value={assistantInput} 
                                                        onChange={e => setAssistantInput(e.target.value)} 
                                                        className="w-full p-2 bg-white rounded-lg border border-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 text-xs font-semibold text-slate-800 shadow-sm" 
                                                        placeholder="Escribe aquí..." 
                                                    />
                                                )}
                                            </div>
                                        )}
                                    </>
                                )}

                                {/* Advertencia de tiempo excedido */}
                                {isOvertime && (
                                    <p className="text-[10px] font-black text-rose-600 mb-3 animate-pulse bg-rose-50/50 p-2 rounded border border-rose-100">
                                        ⚠️ ¡Tiempo estimado excedido! Finaliza la tarea lo antes posible.
                                    </p>
                                )}

                                {/* Fila de Botones de Acción (Ultra compactos o Mensajes de estado) */}
                                <div className="flex gap-2">
                                    {isCompleted ? (
                                        <div className="flex-1 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl py-2.5 px-3 text-center text-xs font-black flex items-center justify-center gap-1.5 shadow-sm">
                                            <Check size={16} className="text-emerald-600" /> Tarea Completada
                                            {currentAssignment.status === 'awaiting_validation' && ' (Esperando Validación)'}
                                        </div>
                                    ) : isLockedByTime ? (
                                        <div className="flex-1 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl py-2.5 px-3 text-center text-xs font-black flex items-center justify-center gap-1.5 shadow-sm">
                                            🔒 Bloqueada: Solo disponible en {restriction?.type}
                                        </div>
                                    ) : currentAssignment.status === 'pending' ? (
                                        <button 
                                            type="button"
                                            onClick={() => startTask(currentAssignment.id, globalSimTime)} 
                                            className="flex-1 bg-indigo-600 text-white font-black py-2.5 rounded-xl text-sm hover:bg-indigo-700 active:scale-95 transition-all shadow-md flex items-center justify-center gap-1.5"
                                        >
                                            <Play size={16} /> Iniciar Tarea
                                        </button>
                                    ) : showPhotoPrompt ? (
                                        null
                                    ) : (
                                        <>
                                            {currentAssignment.status === 'paused' ? (
                                                <button 
                                                    type="button"
                                                    onClick={() => startTask(currentAssignment.id, globalSimTime)} 
                                                    className="w-11 h-11 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl flex items-center justify-center transition-all active:scale-95 shadow-md flex-shrink-0"
                                                    title="Reanudar"
                                                >
                                                    <Play size={18} />
                                                </button>
                                            ) : (
                                                <button 
                                                    type="button"
                                                    onClick={() => pauseTask(currentAssignment.id)} 
                                                    className="w-11 h-11 bg-amber-500 hover:bg-amber-600 text-white rounded-xl flex items-center justify-center transition-all active:scale-95 shadow-md flex-shrink-0"
                                                    title="Pausar"
                                                >
                                                    <Pause size={18} />
                                                </button>
                                            )}
                                            <button 
                                                type="button"
                                                onClick={handleComplete} 
                                                className="flex-1 bg-emerald-500 hover:bg-emerald-600 text-white font-black py-2.5 rounded-xl text-sm transition-all active:scale-95 shadow-md flex items-center justify-center gap-1.5"
                                            >
                                                <Check size={16} /> Completar Tarea
                                            </button>
                                        </>
                                    )}
                                </div>
                            </div>
                        </div>
                    );
                })() : (
                    <div className="flex-1 flex flex-col items-center justify-center text-center p-6 bg-slate-50 rounded-3xl border-2 border-dashed border-slate-200 mb-6">
                        <Coffee size={48} className="text-slate-300 mb-4" />
                        <h2 className="text-xl font-black text-slate-800 mb-2">Sin Tareas Obligatorias</h2>
                        <p className="text-sm text-slate-500">Has terminado todo tu trabajo asignado. Disfruta tu tiempo o toma algo de la Bolsa de Trabajo.</p>
                    </div>
                )}

                {/* LISTADO DE TAREAS DEL DÍA */}
                <div className="mb-6">
                    <h3 className="font-black text-slate-800 mb-3 flex items-center gap-2">
                        📋 Mis Tareas de Hoy <span className="text-xs font-normal text-slate-500 bg-slate-100 px-2 py-1 rounded-full">{myAssignments.length} asignadas</span>
                    </h3>
                    
                    {myAssignments.length === 0 ? (
                        <p className="text-xs text-slate-400 text-center py-4 italic">No tienes tareas asignadas para hoy.</p>
                    ) : (
                        <div className="space-y-2 max-h-60 overflow-y-auto pr-1">
                            {myAssignments.map(a => {
                                const t = tasks.find(tsk => tsk.id === a.taskId);
                                if (!t) return null;
                                
                                const isSelected = currentAssignment?.id === a.id;
                                const isCompleted = a.status === 'completed' || a.status === 'awaiting_validation';
                                const isAwaiting = a.status === 'awaiting_validation';
                                const isActive = a.status === 'in_progress';
                                const isPaused = a.status === 'paused';
                                
                                // Restricción horaria
                                const restriction = getRoutineTimeRestriction(a.assignedFromRoutineId);
                                const isCurrentlyLocked = restriction 
                                    ? (globalSimTime < restriction.startMin || globalSimTime > restriction.endMin)
                                    : false;

                                return (
                                    <button
                                        key={a.id}
                                        onClick={() => setSelectedAssignmentId(a.id)}
                                        className={`w-full text-left p-3 rounded-xl border transition-all flex items-center justify-between shadow-sm relative overflow-hidden ${
                                            isSelected 
                                                ? 'border-indigo-500 bg-indigo-50/20 ring-2 ring-indigo-100' 
                                                : 'border-slate-200 bg-white hover:border-slate-300'
                                        }`}
                                    >
                                        <div className="flex-1 min-w-0 pr-2">
                                            <div className="flex items-center gap-1.5 mb-1 flex-wrap">
                                                <span className={`text-[8px] font-black uppercase px-1.5 py-0.5 rounded-md ${
                                                    t.category === 'operativo' ? 'bg-emerald-100 text-emerald-700' : 
                                                    t.category === 'administrativo' ? 'bg-blue-100 text-blue-700' : 
                                                    t.category === 'supervision' ? 'bg-purple-100 text-purple-700' : 
                                                    'bg-orange-100 text-orange-700'
                                                }`}>
                                                    {t.category}
                                                </span>
                                                {restriction && (
                                                    <span className={`text-[8px] font-black uppercase px-1.5 py-0.5 rounded-md ${
                                                        isCurrentlyLocked ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-600'
                                                    }`}>
                                                        ⏱️ {restriction.type}
                                                    </span>
                                                )}
                                            </div>
                                            <p className={`font-bold text-sm truncate ${
                                                isCompleted ? 'text-slate-400 line-through' : 'text-slate-800'
                                            }`}>
                                                {t.title}
                                            </p>
                                            <p className="text-[10px] text-slate-500 font-medium">
                                                ⏱️ {t.estimatedMins} min est.
                                            </p>
                                        </div>
                                        
                                        <div className="flex items-center gap-2">
                                            {isCompleted ? (
                                                isAwaiting ? (
                                                    <span className="text-[10px] font-bold text-blue-600 bg-blue-50 px-2 py-1 rounded-lg border border-blue-100 flex items-center gap-1">
                                                        <Clock size={10} className="animate-spin" /> Esperando
                                                    </span>
                                                ) : (
                                                    <span className="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-1 rounded-lg border border-emerald-100 flex items-center gap-1">
                                                        <Check size={10} /> Listo
                                                    </span>
                                                )
                                            ) : isActive ? (
                                                <span className="text-[10px] font-bold text-indigo-600 bg-indigo-50 px-2 py-1 rounded-lg border border-indigo-100 animate-pulse flex items-center gap-1">
                                                    <Play size={8} className="fill-indigo-600 text-indigo-600" /> Activa
                                                </span>
                                            ) : isPaused ? (
                                                <span className="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-1 rounded-lg border border-amber-100 flex items-center gap-1">
                                                    <Coffee size={10} /> Pausa
                                                </span>
                                            ) : isCurrentlyLocked ? (
                                                <span className="text-[10px] font-bold text-rose-600 bg-rose-50 px-2 py-1 rounded-lg border border-rose-100 flex items-center gap-1">
                                                    🔒 Bloqueada
                                                </span>
                                            ) : (
                                                <span className="text-[10px] font-bold text-slate-400 border border-slate-200 px-2 py-1 rounded-lg">
                                                    Pendiente
                                                </span>
                                            )}
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>

                {/* BOLSA DE TRABAJO */}
                <div className="mt-auto">
                    <h3 className="font-black text-slate-800 mb-4 flex items-center gap-2">
                        <Briefcase size={20} className="text-slate-600" /> Bolsa de Trabajo <span className="text-xs font-normal text-slate-500 bg-slate-100 px-2 py-1 rounded-full">{poolAssignments.length} extras</span>
                    </h3>
                    
                    {poolAssignments.length === 0 ? (
                        <p className="text-xs text-slate-400 text-center py-4 italic">No hay tareas extras disponibles en este momento.</p>
                    ) : (
                        <div className="grid grid-cols-1 gap-3">
                            {poolAssignments.map(a => {
                                const t = tasks.find(tsk => tsk.id === a.taskId);
                                if(!t) return null;
                                return (
                                    <div key={a.id} className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between hover:border-indigo-300 transition-colors group">
                                        <div>
                                            <div className="flex items-center gap-2 mb-1">
                                                <span className={`text-[8px] font-black uppercase px-1.5 py-0.5 rounded-md ${t.category === 'operativo' ? 'bg-emerald-100 text-emerald-700' : t.category === 'administrativo' ? 'bg-blue-100 text-blue-700' : t.category === 'supervision' ? 'bg-purple-100 text-purple-700' : 'bg-orange-100 text-orange-700'}`}>
                                                    {t.category}
                                                </span>
                                            </div>
                                            <p className="font-bold text-slate-800 text-sm mb-1">{t.title}</p>
                                            <p className="text-[10px] text-slate-500 font-medium">✨ {t.points ?? 10} puntos • ⏱️ {t.estimatedMins} min</p>
                                        </div>
                                        <button onClick={() => grabTaskFromPool(a.id, currentUser.id, globalSimTime)} className="bg-indigo-50 text-indigo-600 font-bold text-xs px-4 py-2 rounded-xl border border-indigo-100 group-hover:bg-indigo-600 group-hover:text-white transition-colors">
                                            ¡Yo la hago!
                                        </button>
                                    </div>
                                )
                            })}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
