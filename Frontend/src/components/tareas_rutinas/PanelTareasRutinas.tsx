import React, { useState } from 'react';
import { Settings, Clock, Lock, Brain, Bot, Rocket, Plus, X, Camera, Hash, FileText, Search, LayoutList, Workflow, Armchair } from 'lucide-react';
import { useTaskStore } from '../../store/useTaskStore';
import type { Task, Routine, ProcedureStep } from '../../store/useTaskStore';
import { useAppStore } from '../../store/useAppStore';


export function PanelTareasRutinas() {
    const { tasks, routines, addTask, addRoutine, updateTask, updateRoutine } = useTaskStore();
    
    const { globalRoles } = useAppStore();

    const getRoleName = (id: number) => {
        const found = globalRoles?.find((r: any) => r.id === id);
        if (found) return found.name;
        if (id === 1) return 'Gerente';
        if (id === 5) return 'Cajero';
        if (id === 6) return 'Ayudante';
        if (id === 0) return 'Todos';
        return `Rol #${id}`;
    };
    
    // UI States
    const [activeTab, setActiveTab] = useState<'tareas'|'rutinas'>('tareas');
    const [showFabMenu, setShowFabMenu] = useState(false);
    const searchInputRef = React.useRef<HTMLInputElement>(null);
    const [showCreator, setShowCreator] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [showMobileSearch, setShowMobileSearch] = useState(false);
    const [editingTask, setEditingTask] = useState<Task | null>(null);
    const [editingRoutine, setEditingRoutine] = useState<Routine | null>(null);
    
    const getRoleIdFromRoleName = (roleName: string): number => {
        if (roleName === 'Todos') return 0;
        const found = globalRoles?.find((r: any) => r.name === roleName);
        if (found) return found.id;
        if (roleName === 'Ayudante General') return 6;
        if (roleName === 'Cajera') return 5;
        if (roleName === 'Encargado') return 1;
        return 1;
    };

    const getRoleNameFromRoleId = (id: number): string => {
        if (id === 0) return 'Todos';
        const found = globalRoles?.find((r: any) => r.id === id);
        if (found) return found.name;
        if (id === 6) return 'Ayudante General';
        if (id === 5) return 'Cajera';
        if (id === 1) return 'Encargado';
        return 'Todos';
    };

    // Filtros de Tareas
    const [selectedRoleFilter, setSelectedRoleFilter] = useState<string>('all');
    const [selectedStatusFilter, setSelectedStatusFilter] = useState<string>('all');

    const filteredTasks = tasks.filter(t => {
        const matchesSearch = t.title.toLowerCase().includes(searchQuery.toLowerCase());
        
        let matchesRole = true;
        if (selectedRoleFilter !== 'all') {
            if (selectedRoleFilter === 'pool') {
                matchesRole = t.targetType === 'pool' || !t.targetId || t.targetId === 0;
            } else {
                matchesRole = t.targetType === 'role' && String(t.targetId) === String(selectedRoleFilter);
            }
        }
        
        let matchesStatus = true;
        if (selectedStatusFilter !== 'all') {
            const isValidated = t.is_validated ?? false;
            if (selectedStatusFilter === 'validated') {
                matchesStatus = isValidated === true;
            } else if (selectedStatusFilter === 'pending') {
                matchesStatus = isValidated === false;
            }
        }
        
        return matchesSearch && matchesRole && matchesStatus;
    });
    
    const filteredRoutines = routines.filter(r => r.title.toLowerCase().includes(searchQuery.toLowerCase()));

    const { currentTier, isFeatureUnlocked } = useAppStore();

    // Formulario de Nueva Tarea
    const [newTaskTitle, setNewTaskTitle] = useState('');
    const [newTaskMins, setNewTaskMins] = useState(15);
    const [newTaskPriority, setNewTaskPriority] = useState<'normal'|'bloqueante'>('normal');
    const [newTaskAutoCap, setNewTaskAutoCap] = useState(false);
    const [newTaskAssistant, setNewTaskAssistant] = useState<'ninguno'|'evidencia_foto'|'captura_numero'|'texto'>('ninguno');
    const [newTaskPrompt, setNewTaskPrompt] = useState('');
    const [newTaskValidationMode, setNewTaskValidationMode] = useState<'forced'|'auto'|'dynamic'>('forced');
    const [newTaskCanBeDoneSitting, setNewTaskCanBeDoneSitting] = useState(false);

    // Nuevos campos ricos alineados con Obsidian
    const [newTaskObjective, setNewTaskObjective] = useState('');
    const [newTaskProcedureSteps, setNewTaskProcedureSteps] = useState<ProcedureStep[]>([]);
    const [newTaskValidationCriteria, setNewTaskValidationCriteria] = useState<string[]>([]);
    const [newTaskFrequency, setNewTaskFrequency] = useState('Diaria');
    const [newTaskEvidenceType, setNewTaskEvidenceType] = useState('Supervisión directa');
    const [newTaskExecutorRoleId, setNewTaskExecutorRoleId] = useState(0);

    // Formulario Nueva Rutina
    const [creatorMode, setCreatorMode] = useState<'tarea'|'rutina'>('tarea');
    const [newRoutineTitle, setNewRoutineTitle] = useState('');
    const [newRoutineRole, setNewRoutineRole] = useState(() => (globalRoles && globalRoles.length > 0 ? globalRoles[0].name : 'Ayudante General'));
    const [newRoutineTrigger, setNewRoutineTrigger] = useState<'on_checkin'|'scheduled'>('on_checkin');
    const [newRoutineAssignMode, setNewRoutineAssignMode] = useState<'checklist'|'equitativo'|'bolsa_trabajo'>('checklist');
    const [selectedTasks, setSelectedTasks] = useState<number[]>([]);

    const handleOpenCreator = () => {
        setEditingTask(null);
        setEditingRoutine(null);
        setNewTaskTitle('');
        setNewTaskMins(15);
        setNewTaskPriority('normal');
        setNewTaskAutoCap(false);
        setNewTaskAssistant('ninguno');
        setNewTaskPrompt('');
        setNewTaskValidationMode('forced');
        setNewTaskCanBeDoneSitting(false);
        
        // Reset campos enriquecidos de Obsidian
        setNewTaskObjective('');
        setNewTaskProcedureSteps([]);
        setNewTaskValidationCriteria([]);
        setNewTaskFrequency('Diaria');
        setNewTaskEvidenceType('Supervisión directa');
        setNewTaskExecutorRoleId(0);

        setNewRoutineTitle('');
        setNewRoutineRole(globalRoles && globalRoles.length > 0 ? globalRoles[0].name : 'Ayudante General');
        setNewRoutineTrigger('on_checkin');
        setNewRoutineAssignMode('checklist');
        setSelectedTasks([]);
        setShowCreator(true);
    };

    const handleEditTaskClick = (t: Task) => {
        setEditingTask(t);
        setEditingRoutine(null);
        setNewTaskTitle(t.title);
        setNewTaskMins(t.estimatedMins);
        setNewTaskPriority(t.priority);
        setNewTaskAutoCap(t.isAutoCapture);
        setNewTaskAssistant(t.assistantType as any);
        setNewTaskPrompt(t.assistantPrompt || '');
        setNewTaskValidationMode(t.validationMode || 'forced');
        setNewTaskCanBeDoneSitting(t.canBeDoneSitting || false);
        
        // Mapear campos enriquecidos de Obsidian
        setNewTaskObjective(t.description || '');
        setNewTaskProcedureSteps(t.procedureSteps || []);
        setNewTaskValidationCriteria(t.validationCriteria || []);
        setNewTaskFrequency(t.frequency || 'Diaria');
        setNewTaskEvidenceType(t.evidenceType || 'Supervisión directa');
        setNewTaskExecutorRoleId(t.targetType === 'role' ? Number(t.targetId) : 0);
        
        setCreatorMode('tarea');
        setShowCreator(true);
    };

    const handleEditRoutineClick = (r: Routine) => {
        setEditingRoutine(r);
        setEditingTask(null);
        setNewRoutineTitle(r.title);
        setNewRoutineRole(getRoleNameFromRoleId(r.targetRoleId));
        setNewRoutineTrigger(r.trigger);
        setNewRoutineAssignMode(r.assignMode as any);
        setSelectedTasks(r.taskIds);
        setCreatorMode('rutina');
        setShowCreator(true);
    };

    const handleSaveTask = () => {
        if (!newTaskTitle) return;
        
        const executorRole = newTaskExecutorRoleId;
        const targetType = executorRole === 0 ? 'pool' : 'role';
        const targetId = executorRole === 0 ? 0 : executorRole;
        
        // Determinar categoría por palabras clave en el título
        const titleLower = newTaskTitle.toLowerCase();
        let category: 'operativo' | 'administrativo' | 'mantenimiento' | 'supervision' = 'operativo';
        if (["mantenimiento", "limpieza", "maquinaria", "selladora", "sanitarios", "taller", "instalaciones"].some(k => titleLower.includes(k))) {
            category = 'mantenimiento';
        } else if (["sat", "compras", "gastos", "corte", "efectivo", "caja", "documental", "pedidos", "servicios", "consumos", "telefonía"].some(k => titleLower.includes(k))) {
            category = 'administrativo';
        }

        if (editingTask) {
            updateTask({
                ...editingTask,
                title: newTaskTitle,
                estimatedMins: newTaskMins,
                priority: newTaskPriority,
                assistantType: newTaskAssistant as any,
                assistantPrompt: newTaskPrompt,
                isAutoCapture: newTaskAutoCap,
                validationMode: newTaskValidationMode,
                canBeDoneSitting: newTaskCanBeDoneSitting,
                description: newTaskObjective,
                procedureSteps: newTaskProcedureSteps,
                validationCriteria: newTaskValidationCriteria,
                frequency: newTaskFrequency,
                evidenceType: newTaskEvidenceType,
                targetType,
                targetId,
                category
            });
        } else {
            addTask({
                id: Date.now(),
                title: newTaskTitle,
                estimatedMins: newTaskMins,
                priority: newTaskPriority,
                category,
                targetType,
                targetId,
                subTasks: [], 
                assistantType: newTaskAssistant as any,
                assistantPrompt: newTaskPrompt,
                isAutoCapture: newTaskAutoCap,
                historicalMins: [],
                validationMode: newTaskValidationMode,
                canBeDoneSitting: newTaskCanBeDoneSitting,
                description: newTaskObjective,
                procedureSteps: newTaskProcedureSteps,
                validationCriteria: newTaskValidationCriteria,
                frequency: newTaskFrequency,
                evidenceType: newTaskEvidenceType
            });
        }
        setShowCreator(false);
        setNewTaskTitle('');
        setNewTaskValidationMode('forced');
        setNewTaskCanBeDoneSitting(false);
        setNewTaskObjective('');
        setNewTaskProcedureSteps([]);
        setNewTaskValidationCriteria([]);
        setNewTaskFrequency('Diaria');
        setNewTaskEvidenceType('Supervisión directa');
        setNewTaskExecutorRoleId(0);
        setEditingTask(null);
    };

    const handleSaveRoutine = () => {
        if (!newRoutineTitle || selectedTasks.length === 0) return;
        if (editingRoutine) {
            updateRoutine({
                ...editingRoutine,
                title: newRoutineTitle,
                targetRoleId: getRoleIdFromRoleName(newRoutineRole),
                trigger: newRoutineTrigger,
                assignMode: newRoutineAssignMode as any,
                taskIds: selectedTasks
            });
        } else {
            addRoutine({
                id: Date.now(),
                title: newRoutineTitle,
                targetRoleId: getRoleIdFromRoleName(newRoutineRole),
                trigger: newRoutineTrigger,
                assignMode: newRoutineAssignMode as any,
                taskIds: selectedTasks
            });
        }
        setShowCreator(false);
        setNewRoutineTitle('');
        setSelectedTasks([]);
        setEditingRoutine(null);
    };

    return (
        <div className="max-w-7xl mx-auto space-y-6 font-sans">
              {/* Tarjeta Superior: Menú de Pestañas */}
              <div className="bg-transparent sm:bg-white rounded-3xl p-0 sm:p-8 shadow-none sm:shadow-sm border-none sm:border sm:border-slate-200">
                  <div className="flex items-center gap-1.5 sm:gap-2 bg-slate-100/60 sm:bg-slate-50 p-1.5 rounded-3xl sm:rounded-2xl w-full overflow-x-auto whitespace-nowrap scrollbar-none border border-slate-200">
                      <button 
                          onClick={() => setActiveTab('tareas')} 
                          className={`flex-shrink-0 flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2 text-[10px] sm:text-sm font-bold p-3 sm:px-6 sm:py-2.5 rounded-2xl sm:rounded-xl min-w-[85px] sm:min-w-0 transition-all relative ${
                              activeTab === 'tareas' 
                                  ? 'bg-white text-blue-700 shadow-sm border border-slate-150' 
                                  : 'bg-transparent border-transparent text-slate-500 hover:text-slate-700 hover:bg-slate-100'
                          }`}
                      >
                          <LayoutList size={18} className={activeTab === 'tareas' ? 'text-blue-600' : 'text-slate-400'} />
                          <span className="whitespace-normal sm:whitespace-nowrap text-center leading-tight">Tareas</span>
                          {/* Counter Badge */}
                          <span className={`absolute top-1 sm:top-auto sm:relative right-1.5 sm:right-auto px-1.5 py-0.5 rounded-full text-[9px] font-black leading-none ${
                              activeTab === 'tareas' 
                                  ? 'bg-blue-100 text-blue-800 border border-blue-200' 
                                  : 'bg-slate-200 text-slate-600 border border-slate-300'
                          }`}>
                              {tasks.length}
                          </span>
                      </button>
                      <button 
                          onClick={() => setActiveTab('rutinas')} 
                          className={`flex-shrink-0 flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2 text-[10px] sm:text-sm font-bold p-3 sm:px-6 sm:py-2.5 rounded-2xl sm:rounded-xl min-w-[85px] sm:min-w-0 transition-all relative ${
                              activeTab === 'rutinas' 
                                  ? 'bg-white text-blue-700 shadow-sm border border-slate-150' 
                                  : 'bg-transparent border-transparent text-slate-500 hover:text-slate-700 hover:bg-slate-100'
                          }`}
                      >
                          <Workflow size={18} className={activeTab === 'rutinas' ? 'text-blue-600' : 'text-slate-400'} />
                          <span className="whitespace-normal sm:whitespace-nowrap text-center leading-tight">Rutinas</span>
                          {/* Counter Badge */}
                          <span className={`absolute top-1 sm:top-auto sm:relative right-1.5 sm:right-auto px-1.5 py-0.5 rounded-full text-[9px] font-black leading-none ${
                              activeTab === 'rutinas' 
                                  ? 'bg-blue-100 text-blue-800 border border-blue-200' 
                                  : 'bg-slate-200 text-slate-600 border border-slate-300'
                          }`}>
                              {routines.length}
                          </span>
                      </button>
                  </div>
              </div>

              {/* Tarjeta Inferior: Contenido principal */}
              <div className="bg-white rounded-3xl p-4 sm:p-8 shadow-sm border border-slate-200 min-h-[500px]">
                  {/* Encabezado Interno y Controles (Buscador & Crear) - Oculto en móvil */}
                  <div className="hidden sm:flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6 border-b border-slate-100 pb-4 w-full">
                      <div className="flex items-center gap-2">
                          <h2 className="text-slate-800 font-extrabold text-base sm:text-lg">
                              {activeTab === 'tareas' ? 'Catálogo de Tareas' : 'Rutinas Automatizadas'}
                          </h2>
                      </div>
                      
                      <div className="flex items-center gap-3 w-full md:w-auto shrink-0 justify-between md:justify-end">
                          <div className="relative w-full md:w-64">
                              <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} />
                              <input 
                                  ref={searchInputRef} 
                                  type="text" 
                                  placeholder={activeTab === 'tareas' ? "Buscar tarea..." : "Buscar rutina..."} 
                                  value={searchQuery} 
                                  onChange={(e) => setSearchQuery(e.target.value)} 
                                  className="w-full pl-9 pr-4 py-2 bg-white border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all" 
                              />
                          </div>
                          <button onClick={handleOpenCreator} className="flex justify-center bg-blue-600 text-white px-5 py-2 rounded-xl text-sm font-medium hover:bg-blue-700 transition-colors shadow-sm items-center gap-2 shrink-0">
                              <Plus size={16} /> Crear Nuevo
                          </button>
                      </div>
                  </div>

                  {/* BUSCADOR MÓVIL CONDICIONAL */}
                  {showMobileSearch && (
                      <div className="block sm:hidden mb-6 relative">
                          <input
                              ref={searchInputRef}
                              type="text"
                              value={searchQuery}
                              onChange={e => setSearchQuery(e.target.value)}
                              placeholder={activeTab === 'tareas' ? "Buscar tarea..." : "Buscar rutina..."}
                              className="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none text-sm bg-white"
                          />
                          <div className="absolute left-3.5 top-3.5 text-slate-400">
                              <Search size={16} />
                          </div>
                      </div>
                  )}

                 {/* Contenido Tareas */}
                 {activeTab === 'tareas' && (
                     <div className="space-y-6">
                        {/* Barra de Filtros Rápidos (Puesto y Estado) */}
                        <div className="flex flex-wrap items-center gap-3 bg-slate-50/60 p-3.5 rounded-2xl border border-slate-200">
                            <div className="flex items-center gap-1.5">
                                <span className="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Filtrar por:</span>
                            </div>
                            
                            {/* Selector de Puesto / Rol */}
                            <div className="relative">
                                <select
                                    value={selectedRoleFilter}
                                    onChange={(e) => setSelectedRoleFilter(e.target.value)}
                                    className="pl-3 pr-8 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all appearance-none cursor-pointer shadow-sm"
                                >
                                    <option value="all">💼 Todos los Puestos</option>
                                    <option value="pool">🌐 Bolsa de Trabajo (Pool)</option>
                                    {globalRoles?.map((r: any) => (
                                        <option key={r.id} value={r.id}>
                                            👤 {r.name}
                                        </option>
                                    ))}
                                </select>
                                <div className="absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400 text-[10px]">▼</div>
                            </div>

                            {/* Selector de Estado de Validación */}
                            <div className="relative">
                                <select
                                    value={selectedStatusFilter}
                                    onChange={(e) => setSelectedStatusFilter(e.target.value)}
                                    className="pl-3 pr-8 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-700 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all appearance-none cursor-pointer shadow-sm"
                                >
                                    <option value="all">📝 Todos los Estados</option>
                                    <option value="validated">✓ Validadas</option>
                                    <option value="pending">⚠️ Pendientes de revisión</option>
                                </select>
                                <div className="absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400 text-[10px]">▼</div>
                            </div>

                            {/* Limpiar Filtros */}
                            {(selectedRoleFilter !== 'all' || selectedStatusFilter !== 'all') && (
                                <button
                                    onClick={() => { setSelectedRoleFilter('all'); setSelectedStatusFilter('all'); }}
                                    className="text-xs text-blue-600 hover:text-blue-800 font-bold flex items-center gap-1 sm:ml-auto"
                                >
                                    <X size={12} /> Limpiar
                                </button>
                            )}
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6 pb-6">
                        {filteredTasks.length === 0 && (
                            <div className="col-span-full text-center py-10 text-slate-500 text-sm font-medium">No se encontraron tareas con esa búsqueda.</div>
                        )}
                        {filteredTasks.map(t => (
                            <div key={t.id} className="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm hover:shadow-lg transition-all group relative">
                                {t.priority === 'bloqueante' && (
                                    <div className="absolute top-0 left-0 w-full h-1.5 bg-rose-500 rounded-t-3xl"></div>
                                )}
                                <div className="flex justify-between items-start mb-4">
                                    <h3 className="font-bold text-slate-800 text-lg leading-tight">{t.title}</h3>
                                    <button onClick={() => handleEditTaskClick(t)} className="text-slate-400 hover:text-blue-600 transition-colors" title="Editar Tarea"><Settings size={18}/></button>
                                </div>
                                <div className="flex flex-wrap gap-2 mb-4">
                                    <span className="px-2.5 py-1 bg-slate-100 text-slate-600 text-[10px] font-bold rounded-md flex items-center gap-1">
                                        <Clock size={12}/> {t.estimatedMins} min
                                    </span>
                                    {t.priority === 'bloqueante' && (
                                        <span className="px-2.5 py-1 bg-rose-50 text-rose-600 text-[10px] font-bold rounded-md flex items-center gap-1">
                                            <Lock size={12}/> Bloqueante
                                        </span>
                                    )}
                                    {t.isAutoCapture && (
                                        <span className="px-2.5 py-1 bg-blue-50 text-blue-600 text-[10px] font-bold rounded-md flex items-center gap-1">
                                            <Brain size={12}/> Autocaptura
                                        </span>
                                    )}
                                    {t.canBeDoneSitting && (
                                        <span className="px-2.5 py-1 bg-purple-50 text-purple-700 text-[10px] font-bold rounded-md flex items-center gap-1">
                                            <Armchair size={12}/> Ley Silla (Sentado)
                                        </span>
                                    )}
                                    {t.assistantType !== 'ninguno' && (
                                        <span className="px-2.5 py-1 bg-amber-50 text-amber-700 text-[10px] font-bold rounded-md flex items-center gap-1">
                                            <Bot size={12}/> Asistente
                                        </span>
                                    )}
                                    {t.validationMode === 'auto' ? (
                                        <span className="px-2.5 py-1 bg-emerald-50 text-emerald-700 text-[10px] font-bold rounded-md flex items-center gap-1">
                                            ⚡ Auto-Aprobación
                                        </span>
                                    ) : t.validationMode === 'dynamic' ? (
                                        <span className="px-2.5 py-1 bg-indigo-50 text-indigo-700 text-[10px] font-bold rounded-md flex items-center gap-1">
                                            📊 Supervisión Dinámica
                                        </span>
                                    ) : (
                                        <span className="px-2.5 py-1 bg-slate-100 text-slate-700 text-[10px] font-bold rounded-md flex items-center gap-1">
                                            🔒 Supervisión Forzada
                                        </span>
                                    )}
                                    {t.is_validated ? (
                                        <span className="px-2.5 py-1 bg-emerald-50 text-emerald-700 text-[10px] font-bold rounded-md flex items-center gap-1 border border-emerald-100/50">
                                            ✓ Validada
                                        </span>
                                    ) : (
                                        <span className="px-2.5 py-1 bg-amber-50 text-amber-700 text-[10px] font-bold rounded-md flex items-center gap-1 border border-amber-100/50">
                                            ⚠️ Pendiente
                                        </span>
                                    )}
                                </div>
                                <div className="text-xs text-slate-400 border-t border-slate-100 pt-4 flex justify-between">
                                    <span>{t.subTasks?.length || 0} pasos internos</span>
                                    {t.historicalMins.length > 0 && <span>{t.historicalMins.length} datos reales</span>}
                                </div>
                            </div>
                        ))}
                    </div>
                    </div>
                )}

                {/* Contenido Rutinas */}
                {activeTab === 'rutinas' && !isFeatureUnlocked('routines_management') && (
                    <div className="flex-1 flex flex-col items-center justify-center animate-in fade-in zoom-in-95 duration-500 max-w-2xl mx-auto text-center pb-20">
                        <div className="w-20 h-20 bg-amber-50 rounded-full flex items-center justify-center mb-6 border border-amber-100">
                            <Workflow size={40} className="text-amber-500" />
                        </div>
                        <h3 className="text-2xl font-black text-slate-800 mb-4">Automatización de Rutinas</h3>
                        <p className="text-slate-500 mb-8 leading-relaxed">
                            Deja de asignar tareas manualmente. Con Talent 360 PRO puedes empaquetar tareas en "Rutinas Inteligentes" que se disparan automáticamente cuando el empleado ficha su entrada, o en horarios programados.
                        </p>
                        <button className="py-3 px-8 bg-gradient-to-r from-slate-900 to-slate-800 hover:from-slate-800 hover:to-slate-700 text-white font-bold rounded-xl shadow-md transition-all flex items-center justify-center gap-2 group">
                            <Lock size={18} className="text-slate-400 group-hover:text-white transition-colors" />
                            Mejorar a Plan PRO
                        </button>
                    </div>
                )}

                {activeTab === 'rutinas' && isFeatureUnlocked('routines_management') && (
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 pb-6">
                        {filteredRoutines.length === 0 && (
                            <div className="col-span-full text-center py-10 text-slate-500 text-sm font-medium">No se encontraron rutinas con esa búsqueda.</div>
                        )}
                        {filteredRoutines.map(r => (
                            <div key={r.id} className="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm hover:shadow-lg transition-all relative">
                                <div className="flex justify-between items-start gap-2 mb-1">
                                    <h3 className="font-black text-slate-900 text-xl leading-tight">{r.title}</h3>
                                    <button onClick={() => handleEditRoutineClick(r)} className="text-slate-400 hover:text-blue-600 transition-colors" title="Editar Rutina"><Settings size={18}/></button>
                                </div>
                                <p className="text-sm font-bold text-slate-500 mb-4">Para: <span className="text-blue-600">{getRoleName(r.targetRoleId)}</span></p>
                                
                                <div className="space-y-2 mb-6">
                                    <div className="flex items-center gap-2 text-xs font-bold text-slate-600 bg-slate-50 p-2.5 rounded-lg">
                                        <Settings size={14} className="text-slate-400"/>
                                        <span className="text-slate-700">Modo:</span>
                                        <span className="text-emerald-600">{r.assignMode.toUpperCase()}</span>
                                    </div>
                                    <div className="flex items-center gap-2 text-xs font-bold text-slate-600 bg-slate-50 p-2.5 rounded-lg">
                                        <Rocket size={14} className="text-slate-400"/>
                                        <span className="text-slate-700">Disparador:</span>
                                        <span className="text-emerald-600">{r.trigger === 'on_checkin' ? 'Al fichar entrada' : 'Horario fijo'}</span>
                                    </div>
                                </div>

                                <div className="border-t border-slate-100 pt-4">
                                    <p className="text-xs font-bold text-slate-400 mb-2 uppercase tracking-widest">{r.taskIds.length} Tareas Incluidas</p>
                                    <ul className="text-sm text-slate-600 space-y-1">
                                        {r.taskIds.map(tid => {
                                            const tsk = tasks.find(t => t.id === tid);
                                            return <li key={tid} className="flex items-center gap-2"><span className="w-1.5 h-1.5 rounded-full bg-slate-300"></span>{tsk?.title || 'Tarea desconocida'}</li>
                                        })}
                                    </ul>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Modal Creador */}
            {showCreator && (
                <div className="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex items-center justify-center p-2 sm:p-6 md:p-8 animate-fade-in overflow-y-auto">
                    <div className="bg-white rounded-3xl shadow-xl w-full max-w-4xl max-h-[95vh] sm:max-h-[90vh] overflow-hidden flex flex-col border border-slate-100">
                        <div className="p-4 sm:p-6 border-b border-slate-100 flex flex-col gap-3 sm:gap-4 sticky top-0 bg-white/90 backdrop-blur-md z-10">
                            <div className="flex justify-between items-center">
                                <h2 className="text-lg sm:text-xl font-bold text-slate-900">
                                    {editingTask 
                                        ? 'Editar Tarea (Catálogo)' 
                                        : editingRoutine 
                                            ? 'Editar Rutina' 
                                            : !isFeatureUnlocked('routines_management') 
                                                ? 'Crear Nueva Tarea (Bolsa de Trabajo)' 
                                                : 'Constructor de Operaciones'
                                    }
                                </h2>
                                <button onClick={() => setShowCreator(false)} className="w-8 h-8 rounded-full bg-slate-50 text-slate-400 font-bold hover:text-slate-600 flex items-center justify-center"><X size={18}/></button>
                            </div>
                            {isFeatureUnlocked('routines_management') && (
                                <div className="flex bg-slate-100 p-1 rounded-xl flex-col sm:flex-row gap-1">
                                    <button onClick={() => setCreatorMode('tarea')} className={`flex-1 py-2 font-bold text-xs sm:text-sm rounded-lg transition-colors flex items-center justify-center gap-2 ${creatorMode === 'tarea' ? 'bg-white shadow text-blue-600' : 'text-slate-500 hover:text-slate-700'}`}><Plus size={16}/> Crear Tarea (Catálogo)</button>
                                    <button 
                                        onClick={() => setCreatorMode('rutina')} 
                                        className={`flex-1 py-2 font-bold text-xs sm:text-sm rounded-lg transition-colors flex items-center justify-center gap-2 ${creatorMode === 'rutina' ? 'bg-white shadow text-blue-600' : 'text-slate-500 hover:text-slate-700'}`}
                                    >
                                        <Settings size={16}/> Ensamblar Rutina
                                    </button>
                                </div>
                            )}
                        </div>
                        
                        <div className="p-4 sm:p-6 md:p-8 flex-1 overflow-y-auto custom-scrollbar">
                            {creatorMode === 'tarea' ? (
                                <div className="space-y-4 sm:space-y-6 max-w-2xl mx-auto">
                                    {/* Título de la Tarea */}
                                    <div>
                                        <label className="block text-sm font-bold text-slate-700 mb-2">Título de la Tarea</label>
                                        <input value={newTaskTitle} onChange={e => setNewTaskTitle(e.target.value)} type="text" className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm font-medium" placeholder="Ej. Limpiar cristales frontales" />
                                    </div>

                                    {/* Objetivo de la Tarea */}
                                    <div>
                                        <label className="block text-sm font-bold text-slate-700 mb-2">Objetivo / Propósito (Obsidian Callout)</label>
                                        <textarea 
                                            value={newTaskObjective} 
                                            onChange={e => setNewTaskObjective(e.target.value)} 
                                            className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm min-h-[70px] leading-relaxed" 
                                            placeholder="¿Cuál es el fin último de esta tarea?"
                                        />
                                    </div>

                                    {/* Puesto Ejecutor y Frecuencia */}
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                                        <div>
                                            <label className="block text-sm font-bold text-slate-700 mb-2">Puesto Ejecutor</label>
                                            <select 
                                                value={newTaskExecutorRoleId} 
                                                onChange={e => setNewTaskExecutorRoleId(Number(e.target.value))} 
                                                className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm bg-white"
                                            >
                                                <option value={0}>🌐 Bolsa de Trabajo (Pool General)</option>
                                                {globalRoles?.map((r: any) => (
                                                    <option key={r.id} value={r.id}>👤 {r.name}</option>
                                                ))}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-sm font-bold text-slate-700 mb-2">Frecuencia</label>
                                            <input 
                                                value={newTaskFrequency} 
                                                onChange={e => setNewTaskFrequency(e.target.value)} 
                                                type="text" 
                                                className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm" 
                                                placeholder="Ej. Diaria, Al cierre, Semanal"
                                            />
                                        </div>
                                    </div>

                                    {/* Evidencia y Tiempo estimado */}
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                                        <div>
                                            <label className="block text-sm font-bold text-slate-700 mb-2">Tiempo Estimado (Mins)</label>
                                            <input value={newTaskMins} onChange={e => setNewTaskMins(parseInt(e.target.value) || 15)} type="number" className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm font-semibold text-slate-700" />
                                        </div>
                                        <div>
                                            <label className="block text-sm font-bold text-slate-700 mb-2">Evidencia de Cumplimiento</label>
                                            <input 
                                                value={newTaskEvidenceType} 
                                                onChange={e => setNewTaskEvidenceType(e.target.value)} 
                                                type="text" 
                                                className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm" 
                                                placeholder="Ej. Supervisión directa, Foto"
                                            />
                                        </div>
                                    </div>

                                    {/* Modos (Autocaptura y Ley Silla) */}
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <label className="flex items-center gap-3 p-3.5 bg-blue-50/50 border border-blue-200 rounded-xl cursor-pointer hover:bg-blue-100/50 transition-colors">
                                            <input type="checkbox" checked={newTaskAutoCap} onChange={e => setNewTaskAutoCap(e.target.checked)} className="w-5 h-5 text-blue-600 rounded border-slate-350" />
                                            <div>
                                                <span className="font-bold text-blue-900 block text-xs flex items-center gap-1"><Brain size={14}/> Modo Autocaptura (IA)</span>
                                                <span className="text-[10px] text-blue-700">Aprenderá tiempos reales.</span>
                                            </div>
                                        </label>
                                        <label className="flex items-center gap-3 p-3.5 bg-purple-50/50 border border-purple-200 rounded-xl cursor-pointer hover:bg-purple-100/50 transition-colors">
                                            <input type="checkbox" checked={newTaskCanBeDoneSitting} onChange={e => setNewTaskCanBeDoneSitting(e.target.checked)} className="w-5 h-5 text-purple-600 rounded border-slate-355" />
                                            <div>
                                                <span className="font-bold text-purple-900 block text-xs flex items-center gap-1"><Armchair size={14}/> Tarea Sentada (Ley Silla)</span>
                                                <span className="text-[10px] text-purple-700">Apta para tomar sentado.</span>
                                            </div>
                                        </label>
                                    </div>

                                    {/* Prioridad y Modo de Supervisión */}
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                                        <div>
                                            <label className="block text-sm font-bold text-slate-700 mb-2">Nivel de Prioridad</label>
                                            <select value={newTaskPriority} onChange={e => setNewTaskPriority(e.target.value as any)} className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm bg-white">
                                                <option value="normal">Normal</option>
                                                <option value="bloqueante">Bloqueante (Evita Fichaje de Salida)</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-sm font-bold text-slate-700 mb-2">Modo de Supervisión</label>
                                            <select value={newTaskValidationMode} onChange={e => setNewTaskValidationMode(e.target.value as any)} className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm bg-white">
                                                <option value="forced">Forzosa (Siempre requiere validar)</option>
                                                <option value="auto">Automática (Auto-aprobación inmediata)</option>
                                                <option value="dynamic">Dinámica (Muestreo por antigüedad)</option>
                                            </select>
                                        </div>
                                    </div>

                                    {/* Mini-Asistente */}
                                    <div className="p-4 sm:p-5 bg-slate-50 rounded-2xl border border-slate-200">
                                        <label className="text-sm font-bold text-slate-800 mb-2 flex items-center gap-2"><Bot size={18} className="text-blue-600"/> Mini-Asistente Acoplado</label>
                                        <select value={newTaskAssistant} onChange={e => setNewTaskAssistant(e.target.value as any)} className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none mb-3 bg-white mt-2 text-sm">
                                            <option value="ninguno">Ninguno</option>
                                            <option value="evidencia_foto">Evidencia Fotográfica</option>
                                            <option value="captura_numero">Captura de Cantidad / Número</option>
                                            <option value="texto">Nota de Texto Corta</option>
                                        </select>
                                        {newTaskAssistant !== 'ninguno' && (
                                            <input value={newTaskPrompt} onChange={e => setNewTaskPrompt(e.target.value)} type="text" className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm bg-white" placeholder="¿Qué le preguntará el asistente al empleado?" />
                                        )}
                                    </div>

                                    {/* Pasos del Proceso (SOP) */}
                                    <div className="bg-slate-50 p-4 sm:p-6 rounded-2xl border border-slate-200 space-y-4">
                                        <div className="flex justify-between items-center border-b border-slate-200 pb-2">
                                            <label className="text-sm font-bold text-slate-800 flex items-center gap-2">📋 Pasos del Proceso (SOP)</label>
                                            <button 
                                                type="button"
                                                onClick={() => {
                                                    setNewTaskProcedureSteps([
                                                        ...newTaskProcedureSteps,
                                                        {
                                                            step_number: newTaskProcedureSteps.length + 1,
                                                            title: '',
                                                            detailed_instruction: '',
                                                            verification_required: true
                                                        }
                                                    ]);
                                                }}
                                                className="text-xs bg-blue-600 text-white px-3 py-1.5 rounded-lg hover:bg-blue-700 font-bold transition-all"
                                            >
                                                + Añadir paso
                                            </button>
                                        </div>
                                        
                                        {newTaskProcedureSteps.length === 0 ? (
                                            <p className="text-xs text-slate-500 italic text-center py-2">No hay pasos definidos. Añade el primer paso del SOP.</p>
                                        ) : (
                                            <div className="space-y-3 max-h-64 overflow-y-auto pr-1">
                                                {newTaskProcedureSteps.map((step, idx) => (
                                                    <div key={idx} className="bg-white p-3 rounded-xl border border-slate-200 flex flex-col gap-2 relative">
                                                        <div className="flex items-center gap-2 justify-between">
                                                            <span className="text-xs font-bold text-blue-600 bg-blue-50 w-5 h-5 rounded-full flex items-center justify-center shrink-0">{idx + 1}</span>
                                                            <input 
                                                                value={step.title}
                                                                onChange={e => {
                                                                    const updated = [...newTaskProcedureSteps];
                                                                    updated[idx].title = e.target.value;
                                                                    setNewTaskProcedureSteps(updated);
                                                                }}
                                                                type="text" 
                                                                className="w-full px-2.5 py-1 text-xs border border-slate-205 rounded-lg font-bold focus:ring-1 focus:ring-blue-500 focus:outline-none" 
                                                                placeholder="Título del paso"
                                                            />
                                                            <button 
                                                                type="button"
                                                                onClick={() => {
                                                                    const updated = newTaskProcedureSteps.filter((_, i) => i !== idx)
                                                                        .map((s, newIdx) => ({ ...s, step_number: newIdx + 1 }));
                                                                    setNewTaskProcedureSteps(updated);
                                                                }}
                                                                className="text-slate-400 hover:text-rose-600 transition-colors"
                                                            >
                                                                🗑️
                                                            </button>
                                                        </div>
                                                        <textarea 
                                                            value={step.detailed_instruction}
                                                            onChange={e => {
                                                                const updated = [...newTaskProcedureSteps];
                                                                updated[idx].detailed_instruction = e.target.value;
                                                                setNewTaskProcedureSteps(updated);
                                                            }}
                                                            className="w-full p-2 text-xs border border-slate-205 rounded-lg focus:ring-1 focus:ring-blue-500 focus:outline-none min-h-[50px]" 
                                                            placeholder="Descripción detallada de la instrucción..."
                                                        />
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>

                                    {/* Checklist de Validación */}
                                    <div className="bg-slate-50 p-4 sm:p-6 rounded-2xl border border-slate-200 space-y-4">
                                        <div className="flex justify-between items-center border-b border-slate-200 pb-2">
                                            <label className="text-sm font-bold text-slate-800 flex items-center gap-2">✅ Checklist de Validación</label>
                                            <button 
                                                type="button"
                                                onClick={() => {
                                                    setNewTaskValidationCriteria([...newTaskValidationCriteria, '']);
                                                }}
                                                className="text-xs bg-emerald-600 text-white px-3 py-1.5 rounded-lg hover:bg-emerald-700 font-bold transition-all"
                                            >
                                                + Añadir criterio
                                            </button>
                                        </div>
                                        
                                        {newTaskValidationCriteria.length === 0 ? (
                                            <p className="text-xs text-slate-500 italic text-center py-2">No hay criterios de validación definidos.</p>
                                        ) : (
                                            <div className="space-y-2 max-h-48 overflow-y-auto pr-1">
                                                {newTaskValidationCriteria.map((crit, idx) => (
                                                    <div key={idx} className="flex items-center gap-2">
                                                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                                                        <input 
                                                            value={crit}
                                                            onChange={e => {
                                                                const updated = [...newTaskValidationCriteria];
                                                                updated[idx] = e.target.value;
                                                                setNewTaskValidationCriteria(updated);
                                                            }}
                                                            type="text" 
                                                            className="w-full px-2.5 py-1.5 text-xs border border-slate-205 rounded-lg focus:ring-1 focus:ring-emerald-500 focus:outline-none" 
                                                            placeholder="Ej. La selladora está apagada y limpia."
                                                        />
                                                        <button 
                                                            type="button"
                                                            onClick={() => {
                                                                const updated = newTaskValidationCriteria.filter((_, i) => i !== idx);
                                                                setNewTaskValidationCriteria(updated);
                                                            }}
                                                            className="text-slate-400 hover:text-rose-600 transition-colors"
                                                        >
                                                            🗑️
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 sm:gap-8">
                                    <div className="space-y-4 sm:space-y-6">
                                        <h3 className="text-lg font-bold text-slate-900 border-b pb-2 text-left">Paso 1: Configuración</h3>
                                        <div>
                                            <label className="block text-sm font-bold text-slate-700 mb-2">Nombre de la Rutina</label>
                                            <input value={newRoutineTitle} onChange={e => setNewRoutineTitle(e.target.value)} type="text" className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm" placeholder="Ej. Protocolo de Cierre" />
                                        </div>
                                        <div>
                                            <label className="block text-sm font-bold text-slate-700 mb-2">Rol Destino</label>
                                            <select value={newRoutineRole} onChange={e => setNewRoutineRole(e.target.value)} className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm bg-white">
                                                {globalRoles && globalRoles.length > 0 ? (
                                                    <>
                                                        {globalRoles.map((r: any) => (
                                                            <option key={r.id} value={r.name}>{r.name}</option>
                                                        ))}
                                                        <option value="Todos">Todos</option>
                                                    </>
                                                ) : (
                                                    <>
                                                        <option value="Ayudante General">Ayudante General</option>
                                                        <option value="Cajera">Cajera</option>
                                                        <option value="Encargado">Encargado</option>
                                                        <option value="Todos">Todos</option>
                                                    </>
                                                )}
                                            </select>
                                        </div>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div>
                                                <label className="block text-sm font-bold text-slate-700 mb-2">Momento (Disparador)</label>
                                                <select value={newRoutineTrigger} onChange={e => setNewRoutineTrigger(e.target.value as any)} className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm bg-white">
                                                    <option value="on_checkin">Al Fichar Entrada</option>
                                                    <option value="scheduled">Horario Fijo / Programado</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label className="block text-sm font-bold text-slate-700 mb-2">Modo Ejecución</label>
                                                <select value={newRoutineAssignMode} onChange={e => setNewRoutineAssignMode(e.target.value as any)} className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm bg-white">
                                                    <option value="checklist">Checklist Personal</option>
                                                    <option value="equitativo">Equitativo (Se reparte)</option>
                                                    <option value="bolsa_trabajo">A la Bolsa de Trabajo</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="space-y-4">
                                        <h3 className="text-lg font-bold text-slate-900 border-b pb-2 text-left">Paso 2: Seleccionar Tareas</h3>
                                        <div className="bg-slate-50 border border-slate-200 rounded-2xl p-3 sm:p-4 h-64 sm:h-80 overflow-y-auto space-y-2 custom-scrollbar">
                                            {tasks.map(t => (
                                                <label key={t.id} className={`flex items-start gap-3 p-2.5 sm:p-3 rounded-xl border cursor-pointer transition-colors ${selectedTasks.includes(t.id) ? 'bg-blue-50 border-blue-200' : 'bg-white border-slate-100 hover:border-blue-100'}`}>
                                                    <input 
                                                        type="checkbox" 
                                                        checked={selectedTasks.includes(t.id)} 
                                                        onChange={(e) => {
                                                            if (e.target.checked) setSelectedTasks([...selectedTasks, t.id]);
                                                            else setSelectedTasks(selectedTasks.filter(id => id !== t.id));
                                                        }}
                                                        className="mt-1 w-4 h-4 text-blue-600 rounded border-slate-300" 
                                                    />
                                                    <div className="text-left">
                                                        <span className="block font-bold text-xs sm:text-sm text-slate-800">{t.title}</span>
                                                        <span className="text-[10px] sm:text-xs text-slate-500 flex items-center gap-1 mt-1"><Clock size={10}/> {t.estimatedMins} min {t.priority === 'bloqueante' && '• Bloqueante'}</span>
                                                    </div>
                                                </label>
                                            ))}
                                        </div>
                                        <div className="flex flex-col sm:flex-row gap-2 justify-between items-start sm:items-center text-xs sm:text-sm font-bold text-slate-500 bg-slate-100 p-3 sm:p-4 rounded-xl">
                                            <span>Tareas Seleccionadas: {selectedTasks.length}</span>
                                            <span className="text-blue-600">Tiempo Aprox: {selectedTasks.reduce((acc, tid) => acc + (tasks.find(t => t.id === tid)?.estimatedMins || 0), 0)} min</span>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>

                        <div className="p-4 sm:p-6 border-t border-slate-100 bg-slate-50 rounded-b-3xl flex flex-col-reverse sm:flex-row justify-end gap-3 sm:gap-4 sticky bottom-0 z-10">
                            <button onClick={() => setShowCreator(false)} className="w-full sm:w-auto px-6 py-3 font-bold text-slate-500 hover:text-slate-700 text-center text-sm">Cancelar</button>
                            {creatorMode === 'tarea' ? (
                                <button onClick={handleSaveTask} className="w-full sm:w-auto px-8 py-3 bg-blue-600 text-white font-black rounded-xl hover:bg-blue-700 shadow-md text-center text-sm">
                                    {editingTask ? 'Guardar Cambios' : 'Guardar Tarea'}
                                </button>
                            ) : (
                                <button onClick={handleSaveRoutine} disabled={selectedTasks.length === 0 || !newRoutineTitle} className="w-full sm:w-auto px-8 py-3 bg-blue-600 disabled:opacity-50 disabled:cursor-not-allowed text-white font-black rounded-xl hover:bg-blue-700 shadow-md text-center text-sm">
                                    {editingRoutine ? 'Guardar Cambios' : 'Ensamblar y Guardar Rutina'}
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            )}
            {/* Botón de Acción Flotante (FAB) Responsivo */}
            <div className="fixed bottom-6 right-6 z-40 block sm:hidden">
                {showFabMenu && (
                    <div className="flex flex-col items-center gap-3.5 mb-3.5">
                        <button
                            onClick={() => {
                                setShowMobileSearch(!showMobileSearch);
                                setTimeout(() => searchInputRef.current?.focus(), 300);
                                setShowFabMenu(false);
                            }}
                            className="w-12 h-12 bg-white hover:bg-slate-50 text-slate-600 border border-slate-200 rounded-full flex items-center justify-center shadow-lg active:scale-90 transition-all duration-300 animate-fade-in-up-2"
                        >
                            <Search size={20} className="text-slate-500" />
                        </button>
                        <button
                            onClick={() => {
                                handleOpenCreator();
                                setShowFabMenu(false);
                            }}
                            className="w-12 h-12 bg-blue-600 hover:bg-blue-700 text-white rounded-full flex items-center justify-center shadow-lg shadow-blue-600/20 active:scale-90 transition-all duration-300 animate-fade-in-up-1"
                        >
                            <Plus size={20} />
                        </button>
                    </div>
                )}
                <button 
                    onClick={() => setShowFabMenu(!showFabMenu)}
                    className="w-14 h-14 bg-blue-600 hover:bg-blue-700 text-white rounded-full flex items-center justify-center shadow-lg shadow-blue-600/35 transition-transform active:scale-95 z-50 relative"
                >
                    <Plus size={24} className={`transition-transform duration-300 ${showFabMenu ? 'rotate-45' : ''}`} />
                </button>
            </div>
        </div>
    );
}
