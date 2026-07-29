import React, { useState, useEffect } from 'react';
import { Settings, Clock, Lock, Brain, Bot, Rocket, Plus, X, Camera, Hash, FileText, Search, LayoutList, Workflow, Armchair, Mic, Check, ChevronRight, ChevronLeft, Sparkles } from 'lucide-react';
import { useTaskStore } from '../../store/useTaskStore';
import type { Task, Routine, ProcedureStep } from '../../store/useTaskStore';
import { useAppStore } from '../../store/useAppStore';
import axiosInstance from '../../lib/axios';

// Detección automática de categoría por palabras clave del título. Se usa tanto
// para guardar la tarea como para mostrar en vivo la categoría sugerida en el
// formulario (antes se calculaba de forma invisible solo al guardar).
export function detectCategory(title: string): Task['category'] {
    const titleLower = title.toLowerCase();
    if (["mantenimiento", "limpieza", "maquinaria", "selladora", "sanitarios", "taller", "instalaciones"].some(k => titleLower.includes(k))) {
        return 'mantenimiento';
    }
    if (["sat", "compras", "gastos", "corte", "efectivo", "caja", "documental", "pedidos", "servicios", "consumos", "telefonía"].some(k => titleLower.includes(k))) {
        return 'administrativo';
    }
    return 'operativo';
}

const CATEGORY_LABELS: Record<Task['category'], string> = {
    operativo: 'Operativo',
    administrativo: 'Administrativo',
    mantenimiento: 'Mantenimiento',
    supervision: 'Supervisión',
};

export function PanelTareasRutinas() {
    const { tasks, routines, addTask, addRoutine, updateTask, updateRoutine } = useTaskStore();
    
    const { globalRoles } = useAppStore();

    // Los fallbacks de IDs fijos (1=Gerente, 5=Cajero, 6=Ayudante...) asumían la estructura de
    // puestos de un solo tenant — en multi-tenant cada empresa define sus propios puestos con
    // sus propios IDs, así que adivinar un nombre por número podía mostrar el puesto equivocado.
    // Si globalRoles no trae el puesto, mostramos el id crudo en vez de adivinar.
    const getRoleName = (id: number) => {
        if (id === 0) return 'Todos';
        const found = globalRoles?.find((r: any) => r.id === id);
        if (found) return found.name;
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
    
    // Mismo criterio que getRoleName arriba: sin IDs fijos adivinados, para no asignar al
    // puesto equivocado en un tenant que no tenga esos mismos nombres/IDs.
    const getRoleIdFromRoleName = (roleName: string): number => {
        if (roleName === 'Todos') return 0;
        const found = globalRoles?.find((r: any) => r.name === roleName);
        if (found) return found.id;
        return 0; // Sin match conocido: cae a la Bolsa de Trabajo en vez de un puesto adivinado
    };

    const getRoleNameFromRoleId = (id: number): string => {
        if (id === 0) return 'Todos';
        const found = globalRoles?.find((r: any) => r.id === id);
        if (found) return found.name;
        return `Rol #${id}`;
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
    const [newTaskValidationMode, setNewTaskValidationMode] = useState<'forced'|'auto'|'dynamic'|'ai_comparison'>('forced');
    const [newTaskCanBeDoneSitting, setNewTaskCanBeDoneSitting] = useState(false);
    // §35: modo de validación "Comparación (IA)" — solo válido junto con assistantType 'evidencia_foto'.
    const [newTaskAiReferenceImages, setNewTaskAiReferenceImages] = useState<string[]>([]);
    const [newTaskAiTolerance, setNewTaskAiTolerance] = useState('');
    // null = usar la detección automática por título; un valor = el usuario la corrigió a mano
    const [newTaskCategoryOverride, setNewTaskCategoryOverride] = useState<Task['category'] | null>(null);

    // Nuevos campos ricos alineados con Obsidian
    const [newTaskObjective, setNewTaskObjective] = useState('');
    const [newTaskProcedureSteps, setNewTaskProcedureSteps] = useState<ProcedureStep[]>([]);
    const [newTaskValidationCriteria, setNewTaskValidationCriteria] = useState<string[]>([]);
    const [newTaskFrequency, setNewTaskFrequency] = useState('Diaria');
    const [newTaskEvidenceType, setNewTaskEvidenceType] = useState('Supervisión directa');
    const [newTaskExecutorRoleId, setNewTaskExecutorRoleId] = useState(0);
    const [newTaskScheduledTime, setNewTaskScheduledTime] = useState('');
    // §38: vincular la tarea con una lección de la Academia (video antes de empezar).
    const [newTaskAcademyLessonId, setNewTaskAcademyLessonId] = useState<number | ''>('');
    const [academyCourses, setAcademyCourses] = useState<{ id: number; title: string }[]>([]);

    useEffect(() => {
        axiosInstance.get('/academy/courses')
            .then(res => setAcademyCourses(res.data?.courses || []))
            .catch(e => console.warn('No se pudo cargar el catálogo de la Academia', e));
    }, []);

    // Constructor de tarea dividido en 4 pasos (antes era una sola pantalla larga con
    // ~11 grupos de campos). 1: Qué y para quién, 2: Cuándo y cuánto, 3: Validación y
    // evidencia, 4: Procedimiento (SOP + checklist). El indicador de paso es clicable
    // en cualquier momento, así que editar una tarea existente no obliga a ir lineal.
    const [creatorStep, setCreatorStep] = useState<1 | 2 | 3 | 4>(1);
    const CREATOR_STEPS = [
        { step: 1 as const, label: 'Qué y para quién' },
        { step: 2 as const, label: 'Cuándo y cuánto' },
        { step: 3 as const, label: 'Validación' },
        { step: 4 as const, label: 'Procedimiento' },
    ];

    // Asistente de voz/IA para pre-llenar el formulario en un solo paso, reutilizando
    // el endpoint ya existente /admin/dashboard/parse-voice-task (mismo que usa el
    // wizard de voz del dashboard). No crea la tarea directamente: solo pre-llena los
    // campos para que el admin los revise en los 4 pasos antes de guardar.
    const [aiQuickInput, setAiQuickInput] = useState('');
    const [aiQuickLoading, setAiQuickLoading] = useState(false);
    const [aiQuickListening, setAiQuickListening] = useState(false);

    const handleAiQuickMic = () => {
        const SpeechRecognition = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
        if (!SpeechRecognition) {
            alert('Tu navegador no soporta dictado por voz. Puedes escribir la descripción de la tarea directamente.');
            return;
        }
        const rec = new SpeechRecognition();
        rec.lang = 'es-MX';
        rec.interimResults = false;
        rec.maxAlternatives = 1;
        rec.onstart = () => setAiQuickListening(true);
        rec.onerror = () => setAiQuickListening(false);
        rec.onend = () => setAiQuickListening(false);
        rec.onresult = (e: any) => {
            const transcript = e.results[0][0].transcript;
            setAiQuickInput(transcript);
        };
        rec.start();
    };

    const handleAiQuickGenerate = async () => {
        if (!aiQuickInput.trim()) return;
        setAiQuickLoading(true);
        try {
            const res = await axiosInstance.post('/admin/dashboard/parse-voice-task', { text: aiQuickInput });
            const d = res.data?.data;
            if (d) {
                if (d.title) setNewTaskTitle(d.title);
                if (d.estimated_mins) setNewTaskMins(d.estimated_mins);
                setNewTaskPriority(d.priority === 'bloqueante' ? 'bloqueante' : 'normal');
                if (d.assistant_detected && d.assistant_type) {
                    setNewTaskAssistant(d.assistant_type);
                    if (d.assistant_prompt) setNewTaskPrompt(d.assistant_prompt);
                }
                if (d.target_type === 'role' && d.target_id) {
                    setNewTaskExecutorRoleId(Number(d.target_id));
                }
            }
            setAiQuickInput('');
            setCreatorStep(1);
        } catch (e) {
            console.error('Error al interpretar la tarea por IA', e);
            alert('No se pudo interpretar la descripción. Puedes llenar el formulario manualmente.');
        } finally {
            setAiQuickLoading(false);
        }
    };

    // Formulario Nueva Rutina
    const [creatorMode, setCreatorMode] = useState<'tarea'|'rutina'>('tarea');
    const [newRoutineTitle, setNewRoutineTitle] = useState('');
    const [newRoutineRole, setNewRoutineRole] = useState(() => (globalRoles && globalRoles.length > 0 ? globalRoles[0].name : 'Ayudante General'));
    const [newRoutineTrigger, setNewRoutineTrigger] = useState<'on_checkin'|'scheduled'>('on_checkin');
    const [newRoutineAssignMode, setNewRoutineAssignMode] = useState<'checklist'|'equitativo'|'bolsa_trabajo'>('checklist');
    const [selectedTasks, setSelectedTasks] = useState<number[]>([]);

    // Categoría que realmente se guardará: la elegida a mano tiene prioridad sobre la detectada
    const detectedCategory = detectCategory(newTaskTitle);
    const effectiveCategory = newTaskCategoryOverride ?? detectedCategory;

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
        setNewTaskCategoryOverride(null);

        // Reset campos enriquecidos de Obsidian
        setNewTaskObjective('');
        setNewTaskProcedureSteps([]);
        setNewTaskValidationCriteria([]);
        setNewTaskFrequency('Diaria');
        setNewTaskEvidenceType('Supervisión directa');
        setNewTaskExecutorRoleId(0);
        setNewTaskScheduledTime('');
        setNewTaskAcademyLessonId('');
        setNewTaskAiReferenceImages([]);
        setNewTaskAiTolerance('');
        setCreatorStep(1);
        setAiQuickInput('');

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
        setNewTaskCategoryOverride(t.category);

        // Mapear campos enriquecidos de Obsidian
        setNewTaskObjective(t.description || '');
        setNewTaskProcedureSteps(t.procedureSteps || []);
        setNewTaskValidationCriteria(t.validationCriteria || []);
        setNewTaskFrequency(t.frequency || 'Diaria');
        setNewTaskEvidenceType(t.evidenceType || 'Supervisión directa');
        setNewTaskExecutorRoleId(t.targetType === 'role' ? Number(t.targetId) : 0);
        setNewTaskScheduledTime((t as any).scheduledTime || '');
        setNewTaskAcademyLessonId((t as any).academyLessonId || '');
        setNewTaskAiReferenceImages((t as any).aiReferenceImages || []);
        setNewTaskAiTolerance((t as any).aiToleranceDescription || '');
        setCreatorStep(1);

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
        const category = effectiveCategory;

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
                category,
                scheduledTime: newTaskScheduledTime || null,
                academyLessonId: newTaskAcademyLessonId || null,
                aiComparisonEnabled: newTaskValidationMode === 'ai_comparison' && newTaskAssistant === 'evidencia_foto',
                aiReferenceImages: newTaskAiReferenceImages,
                aiToleranceDescription: newTaskAiTolerance || null
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
                evidenceType: newTaskEvidenceType,
                scheduledTime: newTaskScheduledTime || null,
                academyLessonId: newTaskAcademyLessonId || null,
                aiComparisonEnabled: newTaskValidationMode === 'ai_comparison' && newTaskAssistant === 'evidencia_foto',
                aiReferenceImages: newTaskAiReferenceImages,
                aiToleranceDescription: newTaskAiTolerance || null
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
        setNewTaskCategoryOverride(null);
        setNewTaskScheduledTime('');
        setNewTaskAcademyLessonId('');
        setNewTaskAiReferenceImages([]);
        setNewTaskAiTolerance('');
        setCreatorStep(1);
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
              {/* Tarjeta Superior: Menú de Pestañas Sticky */}
              <div className="sticky -top-4 sm:-top-8 -mt-4 sm:-mt-8 -mx-4 sm:-mx-8 px-4 sm:px-8 pt-4 sm:pt-6 pb-2 sm:pb-3 bg-slate-50/90 backdrop-blur-md z-20 transition-all border-b border-slate-200/50 mb-4 sm:mb-6">
                  <div className="bg-white rounded-3xl p-1.5 sm:p-2 shadow-sm border border-slate-200">
                      <div className="flex items-center gap-1.5 sm:gap-2 bg-slate-50 p-1.5 rounded-3xl sm:rounded-2xl w-full overflow-x-auto whitespace-nowrap scrollbar-none">
                          <button 
                              onClick={() => setActiveTab('tareas')} 
                              className={`flex-shrink-0 flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2 text-[10px] sm:text-sm font-bold p-3 sm:px-6 sm:py-2.5 rounded-2xl sm:rounded-xl min-w-[85px] sm:min-w-0 transition-all relative ${
                                  activeTab === 'tareas' 
                                      ? 'bg-white text-blue-700 shadow-sm border border-slate-100' 
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
                                      ? 'bg-white text-blue-700 shadow-sm border border-slate-100' 
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
                                <div className="space-y-5 max-w-2xl mx-auto">
                                    {/* Asistente de voz/IA: dicta o escribe la tarea y se pre-llenan los campos de abajo */}
                                    <div className="p-3.5 rounded-xl border border-blue-200 bg-blue-50/50 flex items-center gap-2">
                                        <Sparkles size={16} className="text-blue-600 shrink-0" />
                                        <input
                                            value={aiQuickInput}
                                            onChange={e => setAiQuickInput(e.target.value)}
                                            onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); handleAiQuickGenerate(); } }}
                                            type="text"
                                            className="flex-1 min-w-0 bg-white border border-blue-200 rounded-lg px-3 py-2 text-xs font-medium focus:outline-none focus:ring-1 focus:ring-blue-500"
                                            placeholder="Describe o dicta la tarea: ej. Rellenar góndola de refrescos, 20 min, con foto de evidencia"
                                        />
                                        <button
                                            type="button"
                                            onClick={handleAiQuickMic}
                                            aria-label="Dictar por voz"
                                            className={`shrink-0 w-9 h-9 rounded-lg flex items-center justify-center transition-colors ${aiQuickListening ? 'bg-rose-100 text-rose-600 animate-pulse' : 'bg-white border border-blue-200 text-blue-600 hover:bg-blue-100'}`}
                                        >
                                            <Mic size={16} />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={handleAiQuickGenerate}
                                            disabled={aiQuickLoading || !aiQuickInput.trim()}
                                            className="shrink-0 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-40 px-3 py-2 rounded-lg transition-colors"
                                        >
                                            {aiQuickLoading ? '...' : 'Generar'}
                                        </button>
                                    </div>

                                    {/* Indicador de los 4 pasos, clicable en cualquier momento (útil al editar) */}
                                    <div className="flex items-center gap-1">
                                        {CREATOR_STEPS.map((s, idx) => (
                                            <React.Fragment key={s.step}>
                                                <button
                                                    type="button"
                                                    onClick={() => setCreatorStep(s.step)}
                                                    className={`flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[11px] font-bold transition-colors ${
                                                        creatorStep === s.step
                                                            ? 'bg-blue-600 text-white'
                                                            : creatorStep > s.step
                                                                ? 'bg-emerald-50 text-emerald-700'
                                                                : 'bg-slate-100 text-slate-400'
                                                    }`}
                                                >
                                                    {creatorStep > s.step ? <Check size={12} /> : <span>{s.step}</span>}
                                                    <span className="hidden sm:inline">{s.label}</span>
                                                </button>
                                                {idx < CREATOR_STEPS.length - 1 && <div className="flex-1 h-px bg-slate-200" />}
                                            </React.Fragment>
                                        ))}
                                    </div>

                                    {/* Vista previa en vivo: así la verá el colaborador en su lista de tareas */}
                                    <div className="p-3.5 rounded-xl border border-dashed border-slate-300 bg-slate-50/60 flex items-center justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="text-[10px] font-bold text-slate-400 uppercase mb-1">Vista previa para el colaborador</p>
                                            <p className="text-sm font-extrabold text-slate-800 truncate">{newTaskTitle || 'Título de la tarea…'}</p>
                                        </div>
                                        <div className="flex items-center gap-1.5 shrink-0 flex-wrap justify-end">
                                            <span className="text-[9px] font-black uppercase px-2 py-0.5 rounded-md bg-white border border-slate-200 text-slate-500">{newTaskMins} min</span>
                                            <span className="text-[9px] font-black uppercase px-2 py-0.5 rounded-md bg-blue-50 border border-blue-200 text-blue-700">{CATEGORY_LABELS[effectiveCategory]}</span>
                                            {newTaskPriority === 'bloqueante' && (
                                                <span className="text-[9px] font-black uppercase px-2 py-0.5 rounded-md bg-rose-50 border border-rose-200 text-rose-600">Bloqueante</span>
                                            )}
                                        </div>
                                    </div>

                                    {creatorStep === 1 && (
                                    <div className="space-y-4 sm:space-y-6">
                                        {/* Título de la Tarea */}
                                        <div>
                                            <label className="block text-sm font-bold text-slate-700 mb-2">Título de la Tarea</label>
                                            <input value={newTaskTitle} onChange={e => setNewTaskTitle(e.target.value)} type="text" className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm font-medium" placeholder="Ej. Limpiar cristales frontales" />
                                        </div>

                                        {/* Categoría: se detecta sola por el título, pero queda visible y es editable con un clic */}
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <span className="text-xs font-bold text-slate-500 shrink-0">Categoría:</span>
                                            {(Object.keys(CATEGORY_LABELS) as Task['category'][]).map(cat => (
                                                <button
                                                    key={cat}
                                                    type="button"
                                                    onClick={() => setNewTaskCategoryOverride(cat)}
                                                    className={`text-[11px] font-bold px-2.5 py-1 rounded-full border transition-colors ${
                                                        effectiveCategory === cat
                                                            ? 'bg-blue-600 text-white border-blue-600'
                                                            : 'bg-white text-slate-500 border-slate-200 hover:border-blue-300'
                                                    }`}
                                                >
                                                    {CATEGORY_LABELS[cat]}
                                                </button>
                                            ))}
                                            {newTaskCategoryOverride === null && (
                                                <span className="text-[10px] text-slate-400 italic">(detectada automáticamente por el título)</span>
                                            )}
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
                                    </div>
                                    )}

                                    {creatorStep === 2 && (
                                    <div className="space-y-4 sm:space-y-6">
                                        {/* Tiempo, hora programada y evidencia */}
                                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
                                            <div>
                                                <label className="block text-sm font-bold text-slate-700 mb-2">Tiempo Estimado (Mins)</label>
                                                <input value={newTaskMins} onChange={e => setNewTaskMins(parseInt(e.target.value) || 15)} type="number" className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm font-semibold text-slate-700" />
                                            </div>
                                            <div>
                                                <label className="block text-sm font-bold text-slate-700 mb-2">Hora Programada</label>
                                                <input
                                                    value={newTaskScheduledTime}
                                                    onChange={e => setNewTaskScheduledTime(e.target.value)}
                                                    type="time"
                                                    className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm"
                                                />
                                                <p className="text-[10px] text-slate-400 mt-1">Opcional — ordena el plan de trabajo del día por hora.</p>
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

                                        {/* Prioridad */}
                                        <div>
                                            <label className="block text-sm font-bold text-slate-700 mb-2">Nivel de Prioridad</label>
                                            <select value={newTaskPriority} onChange={e => setNewTaskPriority(e.target.value as any)} className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm bg-white">
                                                <option value="normal">Normal</option>
                                                <option value="bloqueante">Bloqueante (Evita Fichaje de Salida)</option>
                                            </select>
                                        </div>

                                        {/* Modos (Autocaptura y Ley Silla) */}
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <label className="flex items-center gap-3 p-3.5 bg-blue-50/50 border border-blue-200 rounded-xl cursor-pointer hover:bg-blue-100/50 transition-colors">
                                                <input type="checkbox" checked={newTaskAutoCap} onChange={e => setNewTaskAutoCap(e.target.checked)} className="w-5 h-5 text-blue-600 rounded border-slate-300" />
                                                <div>
                                                    <span className="font-bold text-blue-900 block text-xs flex items-center gap-1"><Brain size={14}/> Modo Autocaptura (IA)</span>
                                                    <span className="text-[10px] text-blue-700">Aprenderá tiempos reales.</span>
                                                </div>
                                            </label>
                                            <label className="flex items-center gap-3 p-3.5 bg-purple-50/50 border border-purple-200 rounded-xl cursor-pointer hover:bg-purple-100/50 transition-colors">
                                                <input type="checkbox" checked={newTaskCanBeDoneSitting} onChange={e => setNewTaskCanBeDoneSitting(e.target.checked)} className="w-5 h-5 text-purple-600 rounded border-slate-400" />
                                                <div>
                                                    <span className="font-bold text-purple-900 block text-xs flex items-center gap-1"><Armchair size={14}/> Tarea Sentada (Ley Silla)</span>
                                                    <span className="text-[10px] text-purple-700">Apta para tomar sentado.</span>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                    )}

                                    {creatorStep === 3 && (
                                    <div className="space-y-4 sm:space-y-6">
                                        {/* Modo de Supervisión */}
                                        <div>
                                            <label className="block text-sm font-bold text-slate-700 mb-2">Modo de Supervisión</label>
                                            <div className={`grid gap-2 ${newTaskAssistant === 'evidencia_foto' ? 'grid-cols-2 sm:grid-cols-4' : 'grid-cols-3'}`}>
                                                {([
                                                    { value: 'forced' as const, label: 'Forzosa', hint: 'Siempre valida', Icon: Lock },
                                                    { value: 'auto' as const, label: 'Automática', hint: 'Auto-aprueba', Icon: Rocket },
                                                    { value: 'dynamic' as const, label: 'Dinámica', hint: 'Por antigüedad', Icon: Brain },
                                                    ...(newTaskAssistant === 'evidencia_foto' ? [{ value: 'ai_comparison' as const, label: 'Comparación (IA)', hint: 'IA revisa la foto', Icon: Bot }] : []),
                                                ]).map(opt => {
                                                    const active = newTaskValidationMode === opt.value;
                                                    return (
                                                        <button
                                                            key={opt.value}
                                                            type="button"
                                                            onClick={() => setNewTaskValidationMode(opt.value)}
                                                            className={`flex flex-col items-center justify-center gap-1 p-2.5 sm:p-3 rounded-xl border text-center transition-colors ${
                                                                active
                                                                    ? 'bg-blue-50 border-blue-500 text-blue-700'
                                                                    : 'bg-white border-slate-200 text-slate-500 hover:border-blue-200'
                                                            }`}
                                                        >
                                                            <opt.Icon size={18} className={active ? 'text-blue-600' : 'text-slate-400'} />
                                                            <span className="text-[11px] font-bold">{opt.label}</span>
                                                            <span className="text-[8.5px] text-slate-400 leading-tight">{opt.hint}</span>
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                            {newTaskValidationMode === 'ai_comparison' && newTaskAssistant !== 'evidencia_foto' && (
                                                <p className="text-[10px] text-amber-600 font-bold mt-1.5">La Comparación (IA) requiere que el Mini-Asistente sea "Evidencia Fotográfica" (más abajo).</p>
                                            )}
                                        </div>

                                        {/* Configuración de Comparación (IA): imágenes de referencia + tolerancia */}
                                        {newTaskValidationMode === 'ai_comparison' && newTaskAssistant === 'evidencia_foto' && (
                                            <div className="p-4 sm:p-5 bg-indigo-50/50 rounded-2xl border border-indigo-200 space-y-3">
                                                <label className="text-sm font-bold text-indigo-900 flex items-center gap-2"><Bot size={16} /> Imágenes de Referencia (3-5)</label>
                                                <p className="text-[10px] text-indigo-600">La IA comparará la foto del empleado contra estas imágenes al completar la tarea.</p>
                                                <div className="flex flex-wrap gap-2">
                                                    {newTaskAiReferenceImages.map((img, idx) => (
                                                        <div key={idx} className="relative w-16 h-16 rounded-lg overflow-hidden border border-indigo-200">
                                                            <img src={img} alt={`Referencia ${idx + 1}`} className="w-full h-full object-cover" />
                                                            <button
                                                                type="button"
                                                                onClick={() => setNewTaskAiReferenceImages(prev => prev.filter((_, i) => i !== idx))}
                                                                className="absolute top-0 right-0 w-4 h-4 bg-rose-600 text-white rounded-bl-md flex items-center justify-center text-[9px] border-none cursor-pointer"
                                                            >
                                                                ✕
                                                            </button>
                                                        </div>
                                                    ))}
                                                    {newTaskAiReferenceImages.length < 5 && (
                                                        <label className="w-16 h-16 rounded-lg border-2 border-dashed border-indigo-300 flex items-center justify-center cursor-pointer hover:bg-indigo-100/40 text-indigo-500 text-xl font-black">
                                                            +
                                                            <input
                                                                type="file"
                                                                accept="image/*"
                                                                multiple
                                                                className="hidden"
                                                                onChange={(e) => {
                                                                    const files = Array.from(e.target.files || []);
                                                                    const remaining = 5 - newTaskAiReferenceImages.length;
                                                                    files.slice(0, remaining).forEach(file => {
                                                                        const reader = new FileReader();
                                                                        reader.onload = () => {
                                                                            setNewTaskAiReferenceImages(prev => prev.length < 5 ? [...prev, reader.result as string] : prev);
                                                                        };
                                                                        reader.readAsDataURL(file);
                                                                    });
                                                                    e.target.value = '';
                                                                }}
                                                            />
                                                        </label>
                                                    )}
                                                </div>
                                                {newTaskAiReferenceImages.length > 0 && newTaskAiReferenceImages.length < 3 && (
                                                    <p className="text-[10px] text-amber-600 font-bold">Se recomiendan al menos 3 imágenes para una comparación confiable.</p>
                                                )}
                                                <label className="text-sm font-bold text-indigo-900 block mt-2">Descripción de Tolerancia</label>
                                                <textarea
                                                    value={newTaskAiTolerance}
                                                    onChange={e => setNewTaskAiTolerance(e.target.value)}
                                                    rows={2}
                                                    placeholder='Ej: "Debe haber al menos 8 de las 10 piezas visibles en el anaquel"'
                                                    className="w-full p-3 rounded-xl border border-indigo-200 focus:ring-2 focus:ring-indigo-500 focus:outline-none text-sm bg-white"
                                                />
                                            </div>
                                        )}

                                        {/* Mini-Asistente */}
                                        <div className="p-4 sm:p-5 bg-slate-50 rounded-2xl border border-slate-200">
                                            <label className="text-sm font-bold text-slate-800 mb-2 flex items-center gap-2"><Bot size={18} className="text-blue-600"/> Mini-Asistente Acoplado</label>
                                            <select value={newTaskAssistant} onChange={e => {
                                                const val = e.target.value as any;
                                                setNewTaskAssistant(val);
                                                // La Comparación (IA) solo tiene sentido con evidencia fotográfica.
                                                if (val !== 'evidencia_foto' && newTaskValidationMode === 'ai_comparison') {
                                                    setNewTaskValidationMode('forced');
                                                }
                                            }} className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none mb-3 bg-white mt-2 text-sm">
                                                <option value="ninguno">Ninguno</option>
                                                <option value="evidencia_foto">Evidencia Fotográfica</option>
                                                <option value="captura_numero">Captura de Cantidad / Número</option>
                                                <option value="texto">Nota de Texto Corta</option>
                                            </select>
                                            {newTaskAssistant !== 'ninguno' && (
                                                <input value={newTaskPrompt} onChange={e => setNewTaskPrompt(e.target.value)} type="text" className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none text-sm bg-white" placeholder="¿Qué le preguntará el asistente al empleado?" />
                                            )}
                                        </div>

                                        {/* Vincular con lección de la Academia: muestra el video antes de que el colaborador empiece */}
                                        <div className="p-4 sm:p-5 bg-slate-50 rounded-2xl border border-slate-200">
                                            <label className="text-sm font-bold text-slate-800 mb-2 flex items-center gap-2">🎓 Lección de la Academia (opcional)</label>
                                            <select
                                                value={newTaskAcademyLessonId}
                                                onChange={e => setNewTaskAcademyLessonId(e.target.value ? Number(e.target.value) : '')}
                                                className="w-full p-3.5 sm:p-4 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 focus:outline-none bg-white mt-2 text-sm"
                                            >
                                                <option value="">Sin lección vinculada</option>
                                                {academyCourses.map(c => (
                                                    <option key={c.id} value={c.id}>{c.title}</option>
                                                ))}
                                            </select>
                                            <p className="text-[10px] text-slate-400 mt-2">Antes de iniciar esta tarea, el colaborador verá el video de esa lección — cuenta como progreso real en su Academia.</p>
                                        </div>
                                    </div>
                                    )}

                                    {creatorStep === 4 && (
                                    <div className="space-y-4 sm:space-y-6">
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
                                                                    className="w-full px-2.5 py-1 text-xs border border-slate-200 rounded-lg font-bold focus:ring-1 focus:ring-blue-500 focus:outline-none"
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
                                                                className="w-full p-2 text-xs border border-slate-200 rounded-lg focus:ring-1 focus:ring-blue-500 focus:outline-none min-h-[50px]"
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
                                                                className="w-full px-2.5 py-1.5 text-xs border border-slate-200 rounded-lg focus:ring-1 focus:ring-emerald-500 focus:outline-none"
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
                                    )}

                                    {/* Navegación entre pasos */}
                                    <div className="flex items-center justify-between pt-2">
                                        <button
                                            type="button"
                                            onClick={() => setCreatorStep((s) => (s > 1 ? ((s - 1) as any) : s))}
                                            disabled={creatorStep === 1}
                                            className="flex items-center gap-1 text-xs font-bold text-slate-500 disabled:opacity-30 px-3 py-2"
                                        >
                                            <ChevronLeft size={14} /> Atrás
                                        </button>
                                        {creatorStep < 4 && (
                                            <button
                                                type="button"
                                                onClick={() => setCreatorStep((s) => (s < 4 ? ((s + 1) as any) : s))}
                                                className="flex items-center gap-1 text-xs font-bold text-blue-600 px-3 py-2"
                                            >
                                                Siguiente <ChevronRight size={14} />
                                            </button>
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
