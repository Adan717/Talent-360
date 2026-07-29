import React, { useState } from 'react';
import { 
  Users, Briefcase, GraduationCap, Clock, 
  ArrowUpRight, Activity, ShieldCheck, Zap,
  Lock, Settings, LayoutDashboard, ListTodo, BarChart3, Star,
  Play, Send, CheckCircle2, MessageSquare, PlusCircle, Sparkles, MessageCircle, AlertTriangle, FileText, ChevronRight, Receipt,
  Check, AlertCircle, Utensils, Armchair, Cpu, Bot, Mic,
  Smile, Frown, Award, Ban, UserMinus, UserCheck
} from 'lucide-react';
import { GlobalSystemSettingsPanel } from './GlobalSystemSettingsPanel';
import { OnboardingWizard } from './OnboardingWizard';

import { useAppStore } from '../store/useAppStore';
import { useTaskStore } from '../store/useTaskStore';
import axiosInstance from '../lib/axios';
import { echoInstance } from '../lib/echo';

// Keep active utterances in memory to prevent Chrome garbage collection bug
let activeUtterances: SpeechSynthesisUtterance[] = [];

export const DashboardTalent360 = ({ setActiveModule }: { setActiveModule?: (mod: string) => void }) => {
  const [activeTab, setActiveTab] = useState<'overview' | 'onboarding'>('overview');
  const { globalUsers, currentTier, currentUser, globalSimTime, systemSettings, fetchState, isFeatureUnlocked } = useAppStore();
  const { tasks, assignments, validateTaskAssignment } = useTaskStore();
  
  const [showSetupWizard, setShowSetupWizard] = useState(false);
  const [isAdoptionSaving, setIsAdoptionSaving] = useState(false);

  const [realStats, setRealStats] = useState({
    active_users: globalUsers.length,
    vacancies: 0,
    tasks: 0,
    courses: 0,
    retardos_hoy: 0,
    cumplimiento: 100,
    candidates_count: 0,
    candidates_recent_activity: false
  });

  const [monitorData, setMonitorData] = useState<{
    users: Array<{
      id: number;
      name: string;
      role_name: string;
      status: 'active' | 'break' | 'idle' | 'offline';
      status_text: string;
      active_task: { id: number; title: string; started_at_mins: number; estimated_mins?: number; accumulated_mins?: number } | null;
      active_tasks?: Array<{ id: number; title: string; started_at_mins: number | null; estimated_mins: number; accumulated_mins: number; status: string }>;
      completed_tasks_count: number;
      completed_points?: number;
      avatar: string | null;
      time_remaining: string;
      shift_start?: string;
      shift_end: string;
      efficiency: number;
      meal_minutes?: number;
      time_entries?: Array<any>;
      is_active_employee?: boolean;
    }>;
    available_tasks: Array<{
      id: number;
      title: string;
      estimated_mins: number;
      priority: string;
    }>;
    feed: Array<{
      id: string;
      user: string;
      action: string;
      details: string;
      time: string;
      timestamp: string;
    }>;
    chat: Array<{
      id: number;
      sender_id: number | null;
      sender_name: string;
      content: string;
      type: string;
      time: string;
      timestamp: string;
    }>;
    job_roles?: Array<{
      id: number;
      name: string;
    }>;
  }>({
    users: [],
    available_tasks: [],
    feed: [],
    chat: [],
    job_roles: []
  });

  const [isLoadingMonitor, setIsLoadingMonitor] = useState(true);
  const [selectedUserForTask, setSelectedUserForTask] = useState<any>(null);
  const [selectedTaskId, setSelectedTaskId] = useState<number | null>(null);
  const [assigningTask, setAssigningTask] = useState(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  
  // Drag and Drop y Modales Interactivos del Monitor
  const [draggedTaskId, setDraggedTaskId] = useState<number | null>(null);
  const [dragOverUserId, setDragOverUserId] = useState<number | null>(null);
  const [suspendingUser, setSuspendingUser] = useState<number | null>(null);
  const [selectedTaskIdForAssign, setSelectedTaskIdForAssign] = useState<number | null>(null);

  // Vista enfocada del Monitor en móvil: una sección a la vez (Bolsa/Equipo/Actividad).
  // En escritorio (lg+) las tres siguen viéndose lado a lado, sin cambios.
  const [activeMonitorTab, setActiveMonitorTab] = useState<'bolsa' | 'equipo' | 'actividad'>('bolsa');

  const getTenureLabel = (hireDate?: string | null): string | null => {
    if (!hireDate) return null;
    const days = Math.floor((Date.now() - new Date(hireDate).getTime()) / (1000 * 60 * 60 * 24));
    if (days < 0) return null;
    if (days < 30) return `nuevo · ${days}d`;
    if (days < 365) return `${Math.floor(days / 30)} meses`;
    return `${Math.floor(days / 365)} años`;
  };

  // Rendimiento (privilegio de admin): usa assignments/tasks ya sincronizados vía
  // /sync/state (tenant-wide, no filtrado por usuario — confirmado en ClockController.php)
  // para calcular tiempo trabajado real vs. estimado por colaborador, sin requerir
  // cambios de backend. `getMonitorData()` no sirve para esto: solo trae accumulated_mins
  // de tareas activas y no suma tiempo de tareas ya completadas.
  const isAdminRole = currentUser?.role === 'admin';
  const todayStr = new Date().toLocaleDateString('sv-SE'); // YYYY-MM-DD local

  const getUserWorkStats = (userId: number) => {
    const todays = assignments.filter(a => a.userId === userId && (!a.date || a.date === todayStr));
    const attempted = todays.filter(a => ['completed', 'in_progress', 'paused', 'awaiting_validation', 'omitted'].includes(a.status));
    let workedMins = 0;
    let estimatedMins = 0;
    attempted.forEach(a => {
      workedMins += a.accumulatedMins || 0;
      const task = tasks.find(t => t.id === a.taskId);
      if (task) estimatedMins += task.estimatedMins || 0;
    });
    const completedCount = todays.filter(a => a.status === 'completed').length;
    const efficiencyPct = workedMins > 0
      ? Math.round((estimatedMins / workedMins) * 100)
      : (completedCount > 0 ? 100 : null);
    return { workedMins, estimatedMins, taskCount: todays.length, completedCount, efficiencyPct };
  };

  // Chat States
  const [chatTab, setChatTab] = useState<'chat' | 'feed'>('chat');
  const [chatInput, setChatInput] = useState('');
  const [chatType, setChatType] = useState<'general' | 'permission' | 'food_change' | 'announcement'>('general');
  const [sendingMessage, setSendingMessage] = useState(false);

  // Quick Task Creation States
  const [showCreateTaskModal, setShowCreateTaskModal] = useState(false);
  const [newTaskTitle, setNewTaskTitle] = useState('');
  const [newTaskMins, setNewTaskMins] = useState(30);
  const [newTaskPoints, setNewTaskPoints] = useState(10);
  const [newTaskPriority, setNewTaskPriority] = useState('normal');
  const [newTaskCategory, setNewTaskCategory] = useState('operativo');
  const [newTaskTargetType, setNewTaskTargetType] = useState<'role' | 'user'>('role');
  const [newTaskTargetId, setNewTaskTargetId] = useState('');
  const [newTaskAssistantType, setNewTaskAssistantType] = useState('ninguno');
  const [newTaskAssistantPrompt, setNewTaskAssistantPrompt] = useState('');
  const [newTaskIsAutoCapture, setNewTaskIsAutoCapture] = useState(false);
  const [creatingTask, setCreatingTask] = useState(false);

  // Voice Note NLU Assistant States
  const [isListening, setIsListening] = useState(false);
  const [voiceTranscript, setVoiceTranscript] = useState('');
  const [showVoicePreview, setShowVoicePreview] = useState(false);
  const [voiceParsedData, setVoiceParsedData] = useState<any>(null);

  // Voice Wizard States
  const [voiceWizardActive, setVoiceWizardActive] = useState(false);
  const [voiceWizardStep, setVoiceWizardStep] = useState<
    'idle' | 'asking_initial' | 'asking_time' | 'asking_assignee' | 'asking_assistant' | 'asking_assistant_prompt' | 'confirm_save'
  >('idle');
  const [voiceWizardPrompt, setVoiceWizardPrompt] = useState('');
  const [voiceWizardTranscript, setVoiceWizardTranscript] = useState('');

  // Task Creation Mode Selector
  const [taskCreationMode, setTaskCreationMode] = useState<'voice' | 'manual' | null>(null);

  const fetchStats = () => {
    axiosInstance.get('/admin/dashboard/stats')
      .then(res => {
        if (res.data && res.data.status === 'success') {
          setRealStats(res.data.data);
        } else if (res.data) {
          setRealStats(res.data as any);
        }
      })
      .catch(err => console.error("Error fetching dashboard stats", err));
  };

  const fetchMonitorData = () => {
    axiosInstance.get('/admin/dashboard/monitor')
      .then(res => {
        if (res.data && res.data.status === 'success') {
          setMonitorData(res.data.data);
        }
        setIsLoadingMonitor(false);
      })
      .catch(err => {
        console.error("Error fetching monitor data", err);
        setIsLoadingMonitor(false);
      });
  };

  const handleSendMessage = (e: React.FormEvent) => {
    e.preventDefault();
    if (!chatInput.trim()) return;
    setSendingMessage(true);

    axiosInstance.post('/admin/dashboard/send-message', {
      content: chatInput,
      type: chatType
    })
      .then(res => {
        setChatInput('');
        setSendingMessage(false);
        // El canal de websockets se encargará de actualizar el chat automáticamente
      })
      .catch(err => {
        console.error("Error sending message", err);
        setSendingMessage(false);
      });
  };

  const handleCreateTask = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newTaskTitle.trim()) return;
    setCreatingTask(true);

    axiosInstance.post('/admin/dashboard/create-task', {
      title: newTaskTitle,
      estimated_mins: newTaskMins,
      points: newTaskPoints,
      priority: newTaskPriority,
      category: newTaskCategory,
      target_type: newTaskTargetType,
      target_id: newTaskTargetId ? parseInt(newTaskTargetId) : null,
      assistant_type: newTaskAssistantType,
      assistant_prompt: newTaskAssistantType !== 'ninguno' ? newTaskAssistantPrompt : '',
      is_auto_capture: newTaskIsAutoCapture
    })
      .then(res => {
        setCreatingTask(false);
        setNewTaskTitle('');
        setNewTaskPoints(10);
        setNewTaskTargetType('role');
        setNewTaskTargetId('');
        setNewTaskAssistantType('ninguno');
        setNewTaskAssistantPrompt('');
        setNewTaskIsAutoCapture(false);
        setShowCreateTaskModal(false);
        setTaskCreationMode(null);
        setToastMessage('Tarea creada con éxito en la bolsa de tareas.');
        setTimeout(() => setToastMessage(null), 3000);
        // El canal de websockets se encargará de actualizar el monitor automáticamente
      })
      .catch(err => {
        console.error("Error creating task", err);
        setCreatingTask(false);
      });
  };

  const speakText = (text: string, callback?: () => void) => {
    if (!window.speechSynthesis) {
      if (callback) callback();
      return;
    }
    window.speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = 'es-MX';
    
    const voices = window.speechSynthesis.getVoices();
    const spanishVoice = voices.find(v => v.lang.startsWith('es-MX')) ||
                          voices.find(v => v.lang.startsWith('es-ES')) ||
                          voices.find(v => v.lang.includes('es'));
    if (spanishVoice) {
      utterance.voice = spanishVoice;
    }
    
    // Store reference to prevent garbage collection
    activeUtterances.push(utterance);
    
    utterance.onend = () => {
      activeUtterances = activeUtterances.filter(u => u !== utterance);
      setTimeout(() => {
        if (callback) callback();
      }, 350); // Delay to avoid picking up computer's own voice
    };
    
    utterance.onerror = (err) => {
      console.error("Speech synthesis error", err);
      activeUtterances = activeUtterances.filter(u => u !== utterance);
      if (callback) callback();
    };
    
    window.speechSynthesis.speak(utterance);
  };

  const parseTimeFromText = (text: string): number | null => {
    const lower = text.toLowerCase();
    const numbersMap: { [key: string]: number } = {
      'uno': 1, 'dos': 2, 'tres': 3, 'cuatro': 4, 'cinco': 5,
      'seis': 6, 'siete': 7, 'ocho': 8, 'nueve': 9, 'diez': 10,
      'quince': 15, 'veinte': 20, 'treinta': 30, 'cuarenta': 40,
      'cincuenta': 50, 'sesenta': 60, 'noventa': 90,
      'una hora': 60, 'dos horas': 120, 'tres horas': 180,
      'media hora': 30, 'un cuarto de hora': 15
    };
    
    for (const key in numbersMap) {
      if (lower.includes(key)) {
        return numbersMap[key];
      }
    }
    
    const match = lower.match(/\d+/);
    if (match) {
      return parseInt(match[0]);
    }
    
    return null;
  };

  const startListeningForStep = (step: typeof voiceWizardStep) => {
    const SpeechRecognition = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
    if (!SpeechRecognition) {
      console.error("Speech recognition not supported");
      return;
    }

    const rec = new SpeechRecognition();
    rec.lang = 'es-MX';
    rec.interimResults = false;
    rec.maxAlternatives = 1;

    rec.onstart = () => {
      setIsListening(true);
      setVoiceWizardTranscript('');
    };

    rec.onerror = (e: any) => {
      console.error("Speech recognition error in step " + step, e);
      setIsListening(false);
    };

    rec.onend = () => {
      setIsListening(false);
    };

    rec.onresult = (event: any) => {
      const text = event.results[0][0].transcript;
      setVoiceWizardTranscript(text);
      handleVoiceWizardResult(step, text);
    };

    rec.start();
  };

  const handleVoiceWizardResult = (step: typeof voiceWizardStep, text: string) => {
    if (step === 'asking_initial') {
      setVoiceTranscript(text);
      axiosInstance.post('/admin/dashboard/parse-voice-task', { text })
        .then(res => {
          if (res.data && res.data.status === 'success') {
            const parsed = res.data.data;
            setVoiceParsedData(parsed);
            
            setNewTaskTitle(parsed.title || '');
            setNewTaskMins(parsed.estimated_mins || 30);
            setNewTaskPoints(parsed.points || 10);
            setNewTaskPriority(parsed.priority || 'normal');
            setNewTaskCategory(parsed.category || 'operativo');
            setNewTaskTargetType(parsed.target_type || 'role');
            setNewTaskTargetId(parsed.target_id ? String(parsed.target_id) : '');
            setNewTaskAssistantType(parsed.assistant_type || 'ninguno');
            setNewTaskAssistantPrompt(parsed.assistant_prompt || '');
            
            if (!parsed.time_detected) {
              startVoiceWizardStep('asking_time', 'Entendido. ¿Cuánto tiempo en minutos deseas asignarle a esta tarea?');
            } else if (!parsed.target_id) {
              startVoiceWizardStep('asking_assignee', '¿A qué colaborador o puesto deseas asignar esta tarea?');
            } else if (!parsed.assistant_detected) {
              startVoiceWizardStep('asking_assistant', '¿Deseas acoplar algún asistente de evidencia, como foto, cantidad o texto? O di ninguno.');
            } else if (parsed.assistant_type !== 'ninguno' && !parsed.assistant_prompt) {
              startVoiceWizardStep('asking_assistant_prompt', '¿Qué pregunta o instrucción le mostrará el asistente al empleado?');
            } else {
              startVoiceWizardStep('confirm_save', 'Listo. La tarea está configurada. ¿Deseas guardarla ahora? Di guardar o cancelar.');
            }
          } else {
            startVoiceWizardStep('asking_initial', 'No pude procesar la tarea. Por favor, descríbela de nuevo.');
          }
        })
        .catch(err => {
          console.error("Error parsing voice task", err);
          startVoiceWizardStep('asking_initial', 'Hubo un error al procesar. Por favor, repite la tarea.');
        });
    } else if (step === 'asking_time') {
      const parsedTime = parseTimeFromText(text);
      if (parsedTime !== null) {
        setNewTaskMins(parsedTime);
        setNewTaskPoints(Math.max(5, Math.round(parsedTime / 3)));
        
        if (!newTaskTargetId) {
          startVoiceWizardStep('asking_assignee', 'Entendido. ¿A qué colaborador o puesto deseas asignar esta tarea?');
        } else {
          startVoiceWizardStep('asking_assistant', 'Entendido. ¿Deseas acoplar algún asistente de evidencia, como foto, cantidad o texto? O di ninguno.');
        }
      } else {
        startVoiceWizardStep('asking_time', 'No entendí los minutos. ¿Cuánto tiempo estimas para esta tarea?');
      }
    } else if (step === 'asking_assignee') {
      const lower = text.toLowerCase();
      const matchedUser = globalUsers.find(u => 
        lower.includes(u.name.toLowerCase()) || 
        lower.includes(u.name.split(' ')[0].toLowerCase())
      );
      
      if (matchedUser) {
        setNewTaskTargetType('user');
        setNewTaskTargetId(String(matchedUser.id));
        startVoiceWizardStep('asking_assistant', `Asignada a ${matchedUser.name}. ¿Deseas acoplar algún asistente de evidencia? Di foto, cantidad, texto o ninguno.`);
      } else {
        const matchedRole = (monitorData.job_roles || []).find(r => 
          lower.includes(r.name.toLowerCase())
        );
        if (matchedRole) {
          setNewTaskTargetType('role');
          setNewTaskTargetId(String(matchedRole.id));
          startVoiceWizardStep('asking_assistant', `Asignada al puesto de ${matchedRole.name}. ¿Deseas acoplar algún asistente de evidencia? Di foto, cantidad, texto o ninguno.`);
        } else {
          if (lower.includes('ninguno') || lower.includes('cualquiera') || lower.includes('todos') || lower.includes('nadie')) {
            setNewTaskTargetType('role');
            setNewTaskTargetId('');
            startVoiceWizardStep('asking_assistant', 'Entendido, sin destinatario específico. ¿Deseas acoplar algún asistente de evidencia? Di foto, cantidad, texto o ninguno.');
          } else {
            startVoiceWizardStep('asking_assignee', 'No encontré ese colaborador o puesto. Di el nombre de nuevo o di ninguno.');
          }
        }
      }
    } else if (step === 'asking_assistant') {
      const lower = text.toLowerCase();
      if (lower.includes('foto') || lower.includes('fotográfica') || lower.includes('imagen') || lower.includes('cámara')) {
        setNewTaskAssistantType('evidencia_foto');
        startVoiceWizardStep('asking_assistant_prompt', 'Asistente de foto seleccionado. ¿Qué pregunta o instrucción le mostrará el asistente al empleado?');
      } else if (lower.includes('cantidad') || lower.includes('número') || lower.includes('cifra') || lower.includes('contador')) {
        setNewTaskAssistantType('captura_numero');
        startVoiceWizardStep('asking_assistant_prompt', 'Asistente de cantidad seleccionado. ¿Qué pregunta de inventario o cantidad le mostrará al empleado?');
      } else if (lower.includes('texto') || lower.includes('nota') || lower.includes('comentario') || lower.includes('escribir')) {
        setNewTaskAssistantType('texto');
        startVoiceWizardStep('asking_assistant_prompt', 'Asistente de texto seleccionado. ¿Qué pregunta o nota corta le mostrará al empleado?');
      } else if (lower.includes('ninguno') || lower.includes('no') || lower.includes('sin')) {
        setNewTaskAssistantType('ninguno');
        setNewTaskAssistantPrompt('');
        startVoiceWizardStep('confirm_save', 'Perfecto, sin asistente. ¿Deseas guardar la tarea ahora? Di guardar o cancelar.');
      } else {
        startVoiceWizardStep('asking_assistant', 'No entendí. Di foto, cantidad, texto o ninguno.');
      }
    } else if (step === 'asking_assistant_prompt') {
      setNewTaskAssistantPrompt(text);
      startVoiceWizardStep('confirm_save', `Entendido: "${text}". ¿Deseas guardar la tarea ahora? Di guardar o cancelar.`);
    } else if (step === 'confirm_save') {
      const lower = text.toLowerCase();
      if (lower.includes('guardar') || lower.includes('sí') || lower.includes('si') || lower.includes('confirmar') || lower.includes('grabar') || lower.includes('ok') || lower.includes('acepto')) {
        saveTaskDirectly();
      } else if (lower.includes('cancelar') || lower.includes('no') || lower.includes('descartar') || lower.includes('salir')) {
        speakText('Operación cancelada.');
        setVoiceWizardActive(false);
        setVoiceWizardStep('idle');
      } else {
        startVoiceWizardStep('confirm_save', 'No entendí. Di guardar para registrar la tarea o cancelar para salir.');
      }
    }
  };

  const startVoiceWizardStep = (step: typeof voiceWizardStep, promptText: string) => {
    setVoiceWizardStep(step);
    setVoiceWizardPrompt(promptText);
    speakText(promptText, () => {
      startListeningForStep(step);
    });
  };

  const startVoiceWizard = () => {
    if (voiceWizardActive) {
      if (window.speechSynthesis) {
        window.speechSynthesis.cancel();
      }
      setIsListening(false);
      setVoiceWizardActive(false);
      setVoiceWizardStep('idle');
      return;
    }
    
    setVoiceWizardActive(true);
    setNewTaskTitle('');
    setNewTaskMins(30);
    setNewTaskPoints(10);
    setNewTaskPriority('normal');
    setNewTaskCategory('operativo');
    setNewTaskTargetType('role');
    setNewTaskTargetId('');
    setNewTaskAssistantType('ninguno');
    setNewTaskAssistantPrompt('');
    setVoiceParsedData(null);
    setVoiceTranscript('');
    setVoiceWizardTranscript('');

    startVoiceWizardStep('asking_initial', 'Hola. Describe la tarea que deseas crear y a quién deseas asignarla.');
  };

  const saveTaskDirectly = () => {
    setCreatingTask(true);
    axiosInstance.post('/admin/dashboard/create-task', {
      title: newTaskTitle,
      estimated_mins: newTaskMins,
      points: newTaskPoints,
      priority: newTaskPriority,
      category: newTaskCategory,
      target_type: newTaskTargetType,
      target_id: newTaskTargetId ? parseInt(newTaskTargetId) : null,
      assistant_type: newTaskAssistantType,
      assistant_prompt: newTaskAssistantType !== 'ninguno' ? newTaskAssistantPrompt : '',
      is_auto_capture: newTaskIsAutoCapture
    })
      .then(res => {
        setCreatingTask(false);
        setVoiceWizardActive(false);
        setVoiceWizardStep('idle');
        
        setNewTaskTitle('');
        setNewTaskPoints(10);
        setNewTaskTargetType('role');
        setNewTaskTargetId('');
        setNewTaskAssistantType('ninguno');
        setNewTaskAssistantPrompt('');
        setNewTaskIsAutoCapture(false);
        setShowCreateTaskModal(false);
        setTaskCreationMode(null);
        
        setToastMessage('Tarea creada con éxito vía asistente de voz.');
        setTimeout(() => setToastMessage(null), 3000);
        
        speakText('Tarea guardada exitosamente.');
      })
      .catch(err => {
        console.error("Error creating task via voice wizard", err);
        setCreatingTask(false);
        speakText('Hubo un error al guardar la tarea. Puedes hacerlo manualmente.');
      });
  };

  const toggleVoiceRecognition = () => {
    startVoiceWizard();
  };

  React.useEffect(() => {
    fetchStats();
    fetchMonitorData();

    // Configurar token dinámico en Laravel Echo
    const token = localStorage.getItem('talent_auth_token');
    if (echoInstance && echoInstance.options && echoInstance.options.auth) {
      echoInstance.options.auth.headers.Authorization = `Bearer ${token}`;
    }

    let channelName = '';
    if (currentUser?.tenant_id) {
      channelName = `tenant.${currentUser.tenant_id}`;
      console.log(`Subscribing to private channel: private-${channelName}`);
      echoInstance.private(channelName)
        .listen('.MonitorUpdated', (e: any) => {
          console.log('Real-time event: MonitorUpdated', e);
          fetchMonitorData();
          fetchStats();
        })
        .listen('.NewChatMessage', (e: any) => {
          console.log('Real-time event: NewChatMessage', e);
          if (e.message) {
            setMonitorData(prev => {
              const alreadyExists = prev.chat.some(c => c.id === e.message.id);
              if (alreadyExists) return prev;
              return {
                ...prev,
                chat: [...prev.chat, e.message].sort((a, b) => a.id - b.id)
              };
            });
          }
        });
    }

    // Polling de respaldo de baja frecuencia
    const interval = setInterval(() => {
      fetchStats();
      fetchMonitorData();
    }, 15000);

    return () => {
      clearInterval(interval);
      if (channelName) {
        console.log(`Leaving private channel: private-${channelName}`);
        echoInstance.leave(channelName);
      }
    };
  }, [currentUser]);

  // Efecto para reproducir alerta sonora cuando hay tareas excedidas en progreso
  React.useEffect(() => {
    if (!monitorData || !monitorData.users) return;
    
    // Buscar si hay alguna tarea en progreso que esté sobre el tiempo estimado
    let hasOvertimeTask = false;
    monitorData.users.forEach((user) => {
      const userActiveTasks = (user as any).active_tasks || [];
      userActiveTasks.forEach((t: any) => {
        if (t.status === 'in_progress') {
          const elapsed = (t.accumulated_mins || 0) + 
            (t.started_at_mins ? (globalSimTime - t.started_at_mins) : 0);
          if (elapsed > t.estimated_mins) {
            hasOvertimeTask = true;
          }
        }
      });
    });

    if (hasOvertimeTask) {
      const playBeep = () => {
        try {
          const audioCtx = new (window.AudioContext || (window as any).webkitAudioContext)();
          const oscillator = audioCtx.createOscillator();
          const gainNode = audioCtx.createGain();
          
          oscillator.type = 'sine';
          oscillator.frequency.setValueAtTime(880, audioCtx.currentTime); // Nota La5 (A5)
          gainNode.gain.setValueAtTime(0.08, audioCtx.currentTime); // Volumen 8%
          
          oscillator.connect(gainNode);
          gainNode.connect(audioCtx.destination);
          
          oscillator.start();
          oscillator.stop(audioCtx.currentTime + 0.18); // Duración 180ms
        } catch (e) {
          console.error("Audio Context playback failed", e);
        }
      };
      playBeep();
    }
  }, [globalSimTime, monitorData]);

  const handleSendMockMessage = (name: string) => {
    setToastMessage(`Mensaje de control enviado con éxito a ${name}`);
    setTimeout(() => setToastMessage(null), 3000);
  };

  const handleAssignTask = () => {
    if (!selectedUserForTask || !selectedTaskId) return;
    setAssigningTask(true);

    axiosInstance.post('/admin/dashboard/assign-task', {
      user_id: selectedUserForTask.id,
      task_id: selectedTaskId
    })
      .then(res => {
        setAssigningTask(false);
        setSelectedUserForTask(null);
        setSelectedTaskId(null);
        setToastMessage('Tarea asignada exitosamente en tiempo real.');
        setTimeout(() => setToastMessage(null), 3000);
        // El canal de websockets se encargará de actualizar el monitor automáticamente
      })
      .catch(err => {
        console.error("Error assigning task", err);
        setAssigningTask(false);
        setToastMessage('Error al asignar la tarea.');
        setTimeout(() => setToastMessage(null), 3000);
      });
  };

  const handleAssignTaskDrop = (userId: number, taskId: number) => {
    setAssigningTask(true);
    axiosInstance.post('/admin/dashboard/assign-task', {
      user_id: userId,
      task_id: taskId
    })
      .then(res => {
        setAssigningTask(false);
        setToastMessage('Tarea asignada exitosamente en tiempo real.');
        setTimeout(() => setToastMessage(null), 3000);
        // El canal de websockets se encargará de actualizar el monitor automáticamente
      })
      .catch(err => {
        console.error("Error assigning task", err);
        setAssigningTask(false);
        setToastMessage('Error al asignar la tarea.');
        setTimeout(() => setToastMessage(null), 3000);
      });
  };

  const handleToggleUserSuspension = (employeeId: number, currentActive: boolean) => {
    setSuspendingUser(employeeId);
    axiosInstance.put(`/employees/${employeeId}`, {
      is_active: !currentActive,
      is_active_employee: !currentActive
    })
      .then(res => {
        setSuspendingUser(null);
        setToastMessage(currentActive ? 'Colaborador suspendido temporalmente.' : 'Colaborador reactivado con éxito.');
        setTimeout(() => setToastMessage(null), 3000);
        fetchMonitorData();
      })
      .catch(err => {
        console.error("Error toggling user suspension", err);
        setSuspendingUser(null);
        setToastMessage('Error al cambiar el estado del colaborador.');
        setTimeout(() => setToastMessage(null), 3000);
      });
  };

  const stats = [
    { label: 'Asistencia del Día', value: `${realStats.cumplimiento}%`, icon: CheckCircle2, color: 'text-emerald-600', bg: 'bg-emerald-100', trend: 'Cumplimiento' },
    { label: 'Retardos del Día', value: realStats.retardos_hoy.toString(), icon: Clock, color: 'text-amber-600', bg: 'bg-amber-100', trend: 'Hoy' },
  ];

  const visibleStats = stats.filter(stat => {
    const numericValue = parseFloat(stat.value.replace('%', ''));
    return numericValue !== 0 && !isNaN(numericValue);
  });

  return (
    <div className="max-w-7xl mx-auto space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
      
      {/* Header y Pestañas del Dashboard */}
      <div className="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
          <div>
            <h1 className="text-2xl font-black text-slate-900 tracking-tight">
              Bienvenido a {currentUser?.tenant?.name || 'Talent 360'}
            </h1>
            <p className="text-sm text-slate-500 mt-1 flex flex-wrap items-center gap-1.5 font-medium">
              <span>Resumen general de tu empresa (Plan <span className="font-extrabold text-blue-600">{(currentUser?.tenant?.plan || currentTier).toUpperCase()}</span>)</span>
              {currentUser?.tenant?.created_at && (
                <>
                  <span className="text-slate-300">•</span>
                  <span>Cliente desde {new Date(currentUser.tenant.created_at).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                </>
              )}
            </p>
          </div>
          
          {currentTier === 'freemium' && (
            <button className="flex items-center gap-2 bg-gradient-to-r from-amber-500 to-yellow-600 text-white px-5 py-2.5 rounded-xl font-bold shadow-md shadow-amber-500/20 hover:shadow-lg hover:shadow-amber-500/30 transition-all hover:-translate-y-0.5">
              <Zap size={18} className="fill-current" />
              Mejorar a PRO
            </button>
          )}
        </div>

        {/* Custom Tabs */}
        <div className="flex gap-2 border-b border-slate-100 pb-0 overflow-x-auto custom-scrollbar">
          <button 
            onClick={() => setActiveTab('overview')}
            className={`flex items-center gap-2 px-4 py-2 border-b-2 transition-colors font-bold whitespace-nowrap ${activeTab === 'overview' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'}`}
          >
            <LayoutDashboard size={18} />
            Visión General
          </button>
          <button 
            onClick={() => setActiveTab('onboarding')}
            className={`flex items-center gap-2 px-4 py-2 border-b-2 transition-colors font-bold whitespace-nowrap ${activeTab === 'onboarding' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'}`}
          >
            <Settings size={18} />
            Configuración de Onboarding
          </button>
        </div>
      </div>

      {activeTab === 'onboarding' ? (
        <GlobalSystemSettingsPanel initialTab="onboarding" />
      ) : (
        <>
          {showSetupWizard && (
            <OnboardingWizard onComplete={() => setShowSetupWizard(false)} />
          )}



      {/* Métricas complementarias (lo que no repite ya las píldoras de arriba) */}
      <div className="grid grid-cols-2 gap-4">
        {visibleStats.map((stat, idx) => (
          <div key={idx} className="bg-white p-3.5 rounded-xl shadow-sm border border-slate-200 flex items-center gap-3.5 group hover:border-blue-200 transition-colors">
            <div className={`p-3.5 rounded-2xl ${stat.bg} ${stat.color} shrink-0`}>
              <stat.icon size={40} strokeWidth={1.5} />
            </div>
            <div className="flex-1 min-w-0">
              <h3 className="text-xl font-black text-slate-800 leading-none">{stat.value}</h3>
              <p className="text-xs font-semibold text-slate-500 truncate mt-1">{stat.label}</p>
              <div className="mt-1 flex items-center gap-1.5 flex-wrap">
                <div className="text-[9px] font-bold text-slate-400 bg-slate-50 py-0.5 px-2 rounded inline-block w-max">
                  {stat.trend}
                </div>
              </div>
            </div>
            <ArrowUpRight size={14} className="text-slate-300 group-hover:text-slate-500 transition-colors shrink-0 self-start mt-0.5" />
          </div>
        ))}
      </div>

      {/* Monitor de Actividad en Tiempo Real */}
      <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 space-y-6">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-100 pb-4 gap-4">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-blue-50 text-blue-600 rounded-xl">
              <Activity size={20} strokeWidth={2} className="animate-pulse" />
            </div>
            <div>
              <h2 className="text-lg font-black text-slate-800 tracking-tight">Monitor de Actividad en Tiempo Real</h2>
              <p className="text-xs text-slate-500 mt-0.5 font-medium">Seguimiento de turnos, tareas activas y reportes del personal</p>
            </div>
          </div>
          <div className="flex items-center gap-2 self-end sm:self-auto">
            <button 
              onClick={() => {
                setTaskCreationMode(null);
                setShowCreateTaskModal(true);
              }}
              className="text-xs font-bold text-white bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 px-3.5 py-2 rounded-xl transition-all shadow-md shadow-blue-500/10 flex items-center gap-1.5"
            >
              <PlusCircle size={14} />
              Crear Tarea
            </button>
            <button 
              onClick={fetchMonitorData}
              className="text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 px-3.5 py-2 rounded-xl transition-colors"
            >
              Actualizar
            </button>
          </div>
        </div>

        {isLoadingMonitor ? (
          <div className="flex flex-col items-center justify-center py-16 text-slate-400">
            <span className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mb-2"></span>
            <p className="text-xs font-medium">Cargando monitor de actividad...</p>
          </div>
        ) : (
          <div className="space-y-6">
            {/* Tareas Pendientes de Validación por el Supervisor */}
            {assignments.filter(a => a.status === 'awaiting_validation').length > 0 && (
              <div className="bg-indigo-50 border border-indigo-100 rounded-2xl p-5 mb-4 animate-in slide-in-from-top-4 duration-300">
                <h3 className="text-sm font-black text-indigo-950 flex items-center gap-2 mb-3">
                  <span className="w-2.5 h-2.5 rounded-full bg-indigo-600 animate-pulse"></span>
                  Tareas Pendientes de Validación ({assignments.filter(a => a.status === 'awaiting_validation').length})
                </h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {assignments.filter(a => a.status === 'awaiting_validation').map((assignment) => {
                    const task = tasks.find(t => t.id === assignment.taskId);
                    const employee = globalUsers.find(u => u.id === assignment.userId);
                    return (
                      <div key={assignment.id} className="bg-white border border-indigo-200/50 p-4 rounded-xl shadow-sm flex flex-col justify-between">
                        <div>
                          <div className="flex justify-between items-start gap-2">
                            <span className="text-xs font-bold text-slate-500 block">Colaborador: <span className="text-slate-800 font-extrabold">{employee?.name || 'Empleado'}</span></span>
                            {task?.priority === 'bloqueante' ? (
                              <span className="bg-rose-100 text-rose-800 text-[9px] font-black tracking-wider uppercase px-2 py-0.5 rounded-md">Crítica</span>
                            ) : null}
                          </div>
                          <h4 className="font-bold text-slate-800 text-sm mt-1">{task?.title || 'Tarea'}</h4>
                          <p className="text-xs text-slate-400 mt-1 truncate">{task?.description || 'Sin descripción'}</p>
                          
                          {/* Evidencia */}
                          <div className="mt-3 bg-slate-50 p-2.5 rounded-lg border border-slate-100 text-xs">
                            <span className="font-bold text-slate-500 block uppercase text-[9px] tracking-wider mb-1">Evidencia entregada</span>
                            {task?.assistantType === 'evidencia_foto' ? (
                              <div className="space-y-1.5">
                                <span className="text-slate-600 block italic">Foto adjuntada:</span>
                                {assignment.assistantData?.photoUrl ? (
                                  <img src={assignment.assistantData.photoUrl} alt="Evidencia" className="h-16 w-auto rounded border border-slate-200" />
                                ) : (
                                  <div className="w-24 h-16 bg-slate-200 border border-slate-300 rounded flex items-center justify-center text-[10px] text-slate-500 font-medium">📷 Evidencia Foto</div>
                                )}
                              </div>
                            ) : task?.assistantType === 'captura_numero' ? (
                              <span className="text-slate-700 block font-bold mt-0.5">Número: {assignment.assistantData?.number || assignment.assistantData || 'Sin captura'}</span>
                            ) : task?.assistantType === 'texto' ? (
                              <span className="text-slate-700 block font-medium mt-0.5 italic">"{assignment.assistantData?.text || assignment.assistantData || 'Sin texto'}"</span>
                            ) : (
                              <span className="text-slate-500 block italic mt-0.5">No requiere evidencia física</span>
                            )}
                          </div>
                        </div>

                        <div className="mt-4 pt-3 border-t border-slate-100 flex gap-2">
                          <button 
                            onClick={() => validateTaskAssignment(assignment.id, 'completed')}
                            className="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-1.5 rounded-lg text-xs transition-colors"
                          >
                            Aprobar
                          </button>
                          <button 
                            onClick={() => {
                              const feedback = prompt("Introduce el motivo de rechazo (comentarios para el empleado):");
                              if (feedback !== null) {
                                validateTaskAssignment(assignment.id, 'in_progress', feedback || 'Revisión requerida.');
                              }
                            }}
                            className="flex-1 bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold py-1.5 rounded-lg text-xs border border-rose-200/60 transition-colors"
                          >
                            Rechazar
                          </button>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
            
            {/* Selector de sección: en móvil se ve una a la vez, en escritorio (lg+) esto se oculta y las tres se ven lado a lado */}
            <div className="flex gap-1 bg-slate-100 rounded-xl p-1 lg:hidden">
              {([
                { key: 'bolsa', label: 'Bolsa' },
                { key: 'equipo', label: 'Equipo' },
                { key: 'actividad', label: 'Actividad' },
              ] as const).map(tab => (
                <button
                  key={tab.key}
                  onClick={() => setActiveMonitorTab(tab.key)}
                  className={`flex-1 py-2 text-xs font-black rounded-lg transition-colors ${
                    activeMonitorTab === tab.key ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500'
                  }`}
                >
                  {tab.label}
                </button>
              ))}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">

              {/* PANEL 1: Bolsa de Tareas Rápidas & Asistente IA (1/4) */}
              <div className={`${activeMonitorTab === 'bolsa' ? 'flex' : 'hidden'} lg:flex lg:col-span-1 bg-slate-50/60 p-4 rounded-2xl border border-slate-200 flex-col justify-between h-full min-h-[500px]`}>
                <div className="space-y-4 flex-1 flex flex-col">
                  <div className="flex items-center justify-between border-b border-slate-200/60 pb-3">
                    <div>
                      <h3 className="text-xs font-black text-slate-700 uppercase tracking-wider flex items-center gap-1.5">
                        <ListTodo size={14} className="text-blue-500" /> Bolsa de Tareas
                      </h3>
                      <p className="text-[10px] text-slate-400 font-semibold mt-0.5">Arrastra o selecciona para asignar</p>
                    </div>
                    <span className="bg-slate-200 text-slate-600 text-[9px] font-black px-1.5 py-0.5 rounded">
                      {monitorData.available_tasks.length}
                    </span>
                  </div>

                  {/* Lista de Tareas */}
                  <div className="space-y-2 flex-1 overflow-y-auto max-h-[320px] custom-scrollbar pr-1">
                    {monitorData.available_tasks.map((task) => {
                      const isSelected = selectedTaskIdForAssign === task.id;
                      const isDragged = draggedTaskId === task.id;
                      return (
                        <div
                          key={task.id}
                          draggable={true}
                          onDragStart={(e) => {
                            e.dataTransfer.setData('text/plain', String(task.id));
                            setDraggedTaskId(task.id);
                          }}
                          onDragEnd={() => setDraggedTaskId(null)}
                          onClick={() => {
                            if (isSelected) {
                              setSelectedTaskIdForAssign(null);
                            } else {
                              setSelectedTaskIdForAssign(task.id);
                              setToastMessage('Modo móvil: Toca ahora a un colaborador para asignarle la tarea.');
                              setTimeout(() => setToastMessage(null), 4000);
                            }
                          }}
                          className={`p-3 rounded-xl border transition-all duration-200 cursor-grab active:cursor-grabbing text-left select-none relative overflow-hidden group ${
                            isDragged ? 'opacity-30 border-dashed border-blue-400 bg-blue-50/20' :
                            isSelected ? 'border-blue-500 bg-blue-50 ring-2 ring-blue-500/10' :
                            'border-slate-200 bg-white hover:border-slate-300 hover:shadow-xs'
                          }`}
                        >
                          <div className="flex justify-between items-start gap-1">
                            <span className="font-extrabold text-xs text-slate-800 line-clamp-2 leading-tight pr-4">
                              {task.title}
                            </span>
                            <span className={`text-[8px] font-extrabold px-1.5 py-0.2 rounded uppercase shrink-0 ${
                              task.priority === 'high' || task.priority === 'bloqueante' ? 'bg-rose-100 text-rose-700' :
                              task.priority === 'medium' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'
                            }`}>
                              {task.priority === 'bloqueante' ? 'Crítica' : task.priority}
                            </span>
                          </div>
                          <div className="mt-2.5 flex items-center justify-between text-[9px] text-slate-400 font-bold">
                            <span className="flex items-center gap-0.5">⏱️ {task.estimated_mins} min</span>
                            <span className="opacity-0 group-hover:opacity-100 transition-opacity text-blue-600 font-black flex items-center gap-0.5">
                              {isSelected ? 'Toca colaborador' : 'Arrastra ➔'}
                            </span>
                          </div>
                        </div>
                      );
                    })}

                    {monitorData.available_tasks.length === 0 && (
                      <div className="text-center py-10 border border-dashed border-slate-200 rounded-xl bg-white text-slate-400 text-[10px] font-bold">
                        Bolsa vacía. Genera tareas abajo con IA.
                      </div>
                    )}
                  </div>
                </div>

                {/* Generador de Tareas IA */}
                <div className="mt-4 pt-3 border-t border-slate-200/60">
                  <label className="text-[9px] font-black uppercase tracking-wider text-slate-500 flex items-center gap-1 mb-1.5">
                    <Bot size={12} className="text-blue-600" /> Asistente de Tareas IA
                  </label>
                  <form
                    onSubmit={(e) => {
                      e.preventDefault();
                      const form = e.currentTarget;
                      const input = form.elements.namedItem('ai_task_input') as HTMLInputElement;
                      if (!input.value.trim()) return;
                      
                      const text = input.value;
                      const lower = text.toLowerCase();
                      let mins = 30;
                      let priority = 'normal';
                      if (lower.includes('min') || lower.includes('m')) {
                        const match = lower.match(/\d+/);
                        if (match) mins = parseInt(match[0]);
                      }
                      if (lower.includes('urgente') || lower.includes('critica') || lower.includes('ya')) {
                        priority = 'high';
                      }

                      axiosInstance.post('/admin/dashboard/create-task', {
                        title: text.charAt(0).toUpperCase() + text.slice(1),
                        estimated_mins: mins,
                        points: Math.round(mins / 3),
                        priority: priority,
                        category: 'operativo',
                        target_type: 'role',
                        target_id: null,
                        assistant_type: 'ninguno',
                        is_auto_capture: false
                      }).then(() => {
                        input.value = '';
                        setToastMessage('Tarea generada por IA y agregada a la bolsa.');
                        setTimeout(() => setToastMessage(null), 3000);
                        fetchMonitorData();
                      }).catch(err => console.error("Error creating AI task", err));
                    }}
                    className="flex gap-1.5"
                  >
                    <input
                      type="text"
                      name="ai_task_input"
                      placeholder="Ej. Barrer bodega 20 min urgente..."
                      className="flex-1 bg-white border border-slate-200 rounded-xl px-3 py-2 text-[10px] font-bold text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    />
                    <button
                      type="submit"
                      className="bg-blue-600 hover:bg-blue-700 text-white rounded-xl px-3 flex items-center justify-center border-none cursor-pointer text-[10px] font-black"
                    >
                      Crear
                    </button>
                  </form>
                </div>
              </div>

              {/* PANEL 2: Colaboradores en Turno / "Equipo" (2/4) */}
              <div className={`${activeMonitorTab === 'equipo' ? 'block' : 'hidden'} lg:block lg:col-span-2 space-y-4`}>
                <div className="flex items-center justify-between">
                  <h3 className="text-xs font-black text-slate-400 uppercase tracking-wider flex items-center gap-1">
                    <Users size={14} className="text-slate-400" /> Colaboradores Activos
                  </h3>
                  <span className="bg-blue-50 text-blue-700 text-[10px] font-black px-2.5 py-0.5 rounded-md">
                    {monitorData.users.length} En Turno
                  </span>
                </div>

                {/* Rendimiento (privilegio de admin): ranking de eficiencia real de hoy */}
                {isAdminRole && monitorData.users.length > 0 && (() => {
                  const ranked = monitorData.users
                    .map(u => ({ user: u, stats: getUserWorkStats(u.id) }))
                    .filter(r => r.stats.efficiencyPct !== null)
                    .sort((a, b) => (b.stats.efficiencyPct as number) - (a.stats.efficiencyPct as number));
                  if (ranked.length === 0) return null;
                  return (
                    <div className="bg-indigo-50/60 border border-indigo-100 rounded-2xl p-3">
                      <h4 className="text-[9px] font-black text-indigo-500 uppercase tracking-wider flex items-center gap-1 mb-2">
                        <BarChart3 size={11} /> Rendimiento del Día (solo admin)
                      </h4>
                      <div className="space-y-1">
                        {ranked.slice(0, 5).map(({ user: u, stats }, i) => (
                          <div key={u.id} className="flex items-center justify-between text-[10px]">
                            <span className="flex items-center gap-1.5 text-slate-600 font-bold truncate">
                              <span className="text-slate-300 font-black w-3 shrink-0">{i + 1}</span>
                              {u.name}
                              <span className="text-slate-400 font-medium">· {stats.completedCount} tareas</span>
                            </span>
                            <span className={`font-black px-1.5 py-0.5 rounded-md shrink-0 ${
                              (stats.efficiencyPct as number) >= 100 ? 'bg-emerald-100 text-emerald-700' :
                              (stats.efficiencyPct as number) >= 80 ? 'bg-slate-100 text-slate-600' : 'bg-rose-100 text-rose-600'
                            }`}>
                              {stats.efficiencyPct}%
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>
                  );
                })()}

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {monitorData.users.map((user) => {
                    const timeEntries = user.time_entries || [];
                    const checkIn = timeEntries.find((e: any) => e.type === 'check_in');
                    const mealStart = timeEntries.find((e: any) => e.type === 'meal_start');
                    const mealEnd = timeEntries.find((e: any) => e.type === 'meal_end');
                    const sillaStarts = timeEntries.filter((e: any) => e.type === 'silla_start');
                    const sillaEnds = timeEntries.filter((e: any) => e.type === 'silla_end');
                    const checkOut = timeEntries.find((e: any) => e.type === 'check_out');

                    const takenSillaBreaksCount = sillaStarts.length;
                    const isCurrentlySillaResting = sillaStarts.length > sillaEnds.length;

                    // Alertas LFT
                    const hasEntryLate = checkIn?.is_late;
                    const lateMinutes = checkIn?.late_minutes || 0;

                    let mealDurationMins = 0;
                    let isMealOvertime = false;
                    if (mealStart) {
                      const startParts = mealStart.time.split(':').map(Number);
                      const startMins = startParts[0] * 60 + startParts[1];
                      let endMins = new Date().getHours() * 60 + new Date().getMinutes();
                      if (mealEnd) {
                        const endParts = mealEnd.time.split(':').map(Number);
                        endMins = endParts[0] * 60 + endParts[1];
                      }
                      mealDurationMins = endMins - startMins;
                      const mealLimit = user.meal_minutes || 60;
                      if (mealDurationMins > mealLimit) {
                        isMealOvertime = true;
                      }
                    }

                    const isSillaOvertime = isCurrentlySillaResting && (new Date().getMinutes() % 15 > 12);
                    const userActiveTasks = user.active_tasks || [];
                    const activeTask = userActiveTasks[0] || null;
                    const hasTaskDelay = activeTask && activeTask.accumulated_mins && activeTask.estimated_mins && 
                                         activeTask.accumulated_mins > activeTask.estimated_mins;

                    // Bonos Nómina MX
                    const isBonoPuntualidadActive = !hasEntryLate;
                    const isBonoAsistenciaActive = user.status !== 'offline';
                    const isBonoProductividadActive = user.efficiency >= 85 && user.completed_tasks_count >= 1;

                    // Sugerencias de IA
                    const iaDiagnostic = (() => {
                      if (user.status === 'offline') return "Sin conexión hoy.";
                      if (user.efficiency < 70) return "IA: Rendimiento bajo. Programar mentoría o revisar fatiga LFT.";
                      if (hasTaskDelay) return "IA: Tarea retrasada. Sugerencia: Apoyar con otro colaborador.";
                      if (isMealOvertime) return "IA: Exceso en comida detectado. Notificar aviso LFT.";
                      if (isBonoProductividadActive) return "IA: Desempeño excelente. Candidato a incentivo de productividad.";
                      return "IA: Ritmo de trabajo óptimo y estable.";
                    })();

                    // Drag over state
                    const isDragOver = dragOverUserId === user.id;

                    // Status Dot Color
                    let statusDot = 'bg-slate-400';
                    let edgeColor = 'border-l-slate-350';
                    if (user.status === 'active') {
                      statusDot = 'bg-emerald-500 animate-pulse';
                      edgeColor = 'border-l-emerald-500';
                    } else if (user.status === 'break') {
                      statusDot = 'bg-amber-500';
                      edgeColor = 'border-l-amber-500';
                    } else if (user.status === 'idle') {
                      statusDot = 'bg-rose-500 animate-ping';
                      edgeColor = 'border-l-rose-500';
                    }

                    // Calculo de barra de progreso de turno
                    const progressPercent = (() => {
                      if (!user.shift_end) return 50;
                      try {
                        const now = new Date();
                        const currentMins = now.getHours() * 60 + now.getMinutes();
                        const [endH, endM] = user.shift_end.split(':').map(Number);
                        const endMins = endH * 60 + endM;
                        const startMins = endMins - 540; // 9 hrs shift
                        if (currentMins < startMins) return 0;
                        if (currentMins > endMins) return 100;
                        return Math.round(((currentMins - startMins) / 540) * 100);
                      } catch (e) {
                        return 50;
                      }
                    })();

                    const timeRemainingStr = (() => {
                      if (!user.shift_end) return 'N/D';
                      try {
                        const now = new Date();
                        const currentMins = now.getHours() * 60 + now.getMinutes();
                        const [endH, endM] = user.shift_end.split(':').map(Number);
                        const endMins = endH * 60 + endM;
                        if (currentMins >= endMins) return '0h 0m';
                        const diffMins = endMins - currentMins;
                        return `${Math.floor(diffMins / 60)}h ${diffMins % 60}m`;
                      } catch (e) {
                        return 'N/D';
                      }
                    })();

                    return (
                      <div
                        key={user.id}
                        onDragOver={(e) => {
                          e.preventDefault();
                          setDragOverUserId(user.id);
                        }}
                        onDragLeave={() => setDragOverUserId(null)}
                        onDrop={(e) => {
                          e.preventDefault();
                          setDragOverUserId(null);
                          const taskId = parseInt(e.dataTransfer.getData('text/plain'));
                          if (taskId) handleAssignTaskDrop(user.id, taskId);
                        }}
                        onClick={() => {
                          if (selectedTaskIdForAssign) {
                            handleAssignTaskDrop(user.id, selectedTaskIdForAssign);
                            setSelectedTaskIdForAssign(null);
                          }
                        }}
                        className={`bg-white border border-slate-200 border-l-4 ${edgeColor} p-4.5 rounded-2xl flex flex-col justify-between shadow-sm hover:shadow-md transition-all duration-300 relative overflow-hidden group ${
                          isDragOver ? 'border-blue-500 ring-4 ring-blue-500/10 scale-102 bg-blue-50/10' : ''
                        } ${selectedTaskIdForAssign ? 'ring-2 ring-blue-400/50 cursor-pointer animate-pulse' : ''}`}
                      >
                        {/* Cabecera: Avatar + Nombre + Puesto */}
                        <div>
                          <div className="flex items-start justify-between gap-2.5">
                            <div className="flex items-center gap-3">
                              <div className="relative shrink-0">
                                {user.avatar ? (
                                  <img src={user.avatar} alt={user.name} className="w-10 h-10 rounded-full object-cover border border-slate-100" />
                                ) : (
                                  <div className="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-white font-bold flex items-center justify-center text-sm shadow-inner">
                                    {user.name.charAt(0)}
                                  </div>
                                )}
                                <span className={`absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full border-2 border-white ${statusDot}`}></span>
                              </div>
                              <div className="min-w-0">
                                <h4 className="text-sm font-extrabold text-slate-800 truncate">{user.name}</h4>
                                <div className="flex items-center gap-1.5 flex-wrap">
                                  <p className="text-[10px] text-slate-400 font-semibold truncate uppercase tracking-wider">{user.role_name}</p>
                                  {getTenureLabel((user as any).hire_date) && (
                                    <span className={`text-[8px] font-black px-1.5 py-0.5 rounded-full shrink-0 ${
                                      getTenureLabel((user as any).hire_date)?.startsWith('nuevo')
                                        ? 'bg-amber-100 text-amber-700'
                                        : 'bg-slate-100 text-slate-500'
                                    }`}>
                                      {getTenureLabel((user as any).hire_date)}
                                    </span>
                                  )}
                                </div>
                              </div>
                            </div>
                            <span className="text-[9px] font-extrabold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 border border-slate-200">
                              {user.efficiency}% Efic.
                            </span>
                          </div>

                          {/* Sección de Bonos Nómina MX */}
                          <div className="mt-3 grid grid-cols-3 gap-1.5 text-center">
                            <div className={`p-1.5 rounded-lg border text-[9px] font-bold flex flex-col justify-center items-center ${
                              isBonoPuntualidadActive ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-rose-50 text-rose-700 border-rose-100'
                            }`}>
                              <Award size={10} className="mb-0.5" />
                              <span>Puntualidad</span>
                              <span className="text-[8px] font-black uppercase mt-0.5">{isBonoPuntualidadActive ? 'Activo' : 'Perdido'}</span>
                            </div>
                            <div className={`p-1.5 rounded-lg border text-[9px] font-bold flex flex-col justify-center items-center ${
                              isBonoAsistenciaActive ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-rose-50 text-rose-700 border-rose-100'
                            }`}>
                              <CheckCircle2 size={10} className="mb-0.5" />
                              <span>Asistencia</span>
                              <span className="text-[8px] font-black uppercase mt-0.5">{isBonoAsistenciaActive ? 'Activo' : 'Inactivo'}</span>
                            </div>
                            <div className={`p-1.5 rounded-lg border text-[9px] font-bold flex flex-col justify-center items-center ${
                              isBonoProductividadActive ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-slate-50 text-slate-500 border-slate-200'
                            }`}>
                              <Sparkles size={10} className="mb-0.5" />
                              <span>Productividad</span>
                              <span className="text-[8px] font-black uppercase mt-0.5">{isBonoProductividadActive ? 'Activo' : 'N/D'}</span>
                            </div>
                          </div>

                          {/* Alertas LFT / Incidentes de Descanso */}
                          <div className="mt-3 space-y-1.5">
                            {hasEntryLate && (
                              <div className="flex items-center gap-1.5 text-[9px] font-black text-rose-600 bg-rose-50 border border-rose-100 p-1.5 rounded-lg">
                                <AlertTriangle size={11} className="shrink-0" />
                                <span>Retardo registrado en Entrada: ({lateMinutes} min tarde)</span>
                              </div>
                            )}
                            {isMealOvertime && (
                              <div className="flex items-center gap-1.5 text-[9px] font-black text-rose-600 bg-rose-50 border border-rose-100 p-1.5 rounded-lg">
                                <AlertTriangle size={11} className="shrink-0" />
                                <span>Exceso LFT en tiempo de Comida: (+{mealDurationMins - (user.meal_minutes || 60)} min)</span>
                              </div>
                            )}
                            {isSillaOvertime && (
                              <div className="flex items-center gap-1.5 text-[9px] font-black text-amber-600 bg-amber-50 border border-amber-100 p-1.5 rounded-lg animate-pulse">
                                <Armchair size={11} className="shrink-0" />
                                <span>Ley Silla LFT: Alerta de descanso prolongado.</span>
                              </div>
                            )}
                            {hasTaskDelay && (
                              <div className="flex items-center gap-1.5 text-[9px] font-black text-rose-600 bg-rose-50 border border-rose-100 p-1.5 rounded-lg animate-pulse">
                                <AlertCircle size={11} className="shrink-0" />
                                <span>Demora en Tarea Activa: ({activeTask.accumulated_mins - activeTask.estimated_mins} min excedidos)</span>
                              </div>
                            )}
                          </div>

                          {/* Analítica e IA de Diagnóstico */}
                          <div className="mt-3 p-2 bg-slate-50 border border-slate-100 rounded-xl text-[10px] font-medium text-slate-600 italic flex gap-1.5 items-start">
                            <Cpu size={12} className="text-blue-500 shrink-0 mt-0.5" />
                            <span>{iaDiagnostic}</span>
                          </div>

                          {/* Línea de Progreso Temporal de Jornada */}
                          <div className="mt-4 space-y-2">
                            <div className="flex items-center justify-between text-[9px] text-slate-400 font-bold">
                              <span className="flex items-center gap-0.5"><Clock size={10} /> Resta de Turno: {timeRemainingStr}</span>
                              <span>Jornada: {progressPercent}%</span>
                            </div>
                            <div className="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden relative">
                              <div
                                className={`h-full transition-all duration-500 ${user.status === 'break' ? 'bg-amber-400' : 'bg-blue-600'}`}
                                style={{ width: `${progressPercent}%` }}
                              ></div>
                            </div>
                          </div>

                          {/* Tiempo trabajado real vs. turno (privilegio de admin) */}
                          {isAdminRole && (() => {
                            const stats = getUserWorkStats(user.id);
                            let shiftMins = 540; // fallback: 9h
                            if (user.shift_start && user.shift_end) {
                              const [sh, sm] = user.shift_start.split(':').map(Number);
                              const [eh, em] = user.shift_end.split(':').map(Number);
                              const diff = (eh * 60 + em) - (sh * 60 + sm);
                              if (diff > 0) shiftMins = diff;
                            }
                            const workedPercent = Math.min(100, Math.round((stats.workedMins / shiftMins) * 100));
                            return (
                              <div className="mt-2">
                                <div className="flex items-center justify-between text-[9px] text-indigo-400 font-bold">
                                  <span>Trabajado: {(stats.workedMins / 60).toFixed(1)}h de {(shiftMins / 60).toFixed(1)}h turno</span>
                                  {stats.efficiencyPct !== null && <span>{stats.efficiencyPct}% efic.</span>}
                                </div>
                                <div className="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden relative">
                                  <div
                                    className="h-full bg-indigo-500 transition-all duration-500"
                                    style={{ width: `${workedPercent}%` }}
                                  ></div>
                                </div>
                              </div>
                            );
                          })()}
                        </div>

                        {/* Tareas del Día del Colaborador */}
                        <div className="mt-4 space-y-2">
                          <span className="text-[9px] font-black text-slate-400 uppercase tracking-wider block">Avance de Rutinas</span>
                          {userActiveTasks.length > 0 ? (
                            userActiveTasks.map((t: any) => {
                              const elapsed = t.accumulated_mins || 0;
                              const percent = Math.min(100, Math.max(0, (elapsed / t.estimated_mins) * 100));
                              const isOver = elapsed > t.estimated_mins;
                              return (
                                <div key={t.id} className={`p-2.5 rounded-xl border flex flex-col justify-between transition-all ${
                                  isOver && t.status === 'in_progress' ? 'bg-rose-50 border-rose-200' : 'bg-blue-50/30 border-blue-100'
                                }`}>
                                  <div className="flex justify-between items-center text-[9px] font-bold text-slate-400 mb-1">
                                    <span className="font-extrabold text-blue-600 truncate max-w-[120px]">{t.title}</span>
                                    <span>{elapsed}/{t.estimated_mins}m</span>
                                  </div>
                                  <div className="w-full bg-slate-200 h-1 rounded-full overflow-hidden">
                                    <div 
                                      className={`h-full ${isOver ? 'bg-rose-500' : 'bg-blue-500'}`}
                                      style={{ width: `${percent}%` }}
                                    ></div>
                                  </div>
                                </div>
                              );
                            })
                          ) : (
                            <div className="bg-slate-50 border border-slate-150 rounded-xl p-2.5 text-center text-[10px] text-slate-400 font-bold">
                              No tiene tareas activas. Arrastra una aquí.
                            </div>
                          )}
                        </div>

                        {/* Acciones del Supervisor */}
                        <div className="mt-4 pt-3 border-t border-slate-100 flex justify-between gap-1.5 items-center">
                          <button
                            onClick={() => setSelectedUserForTask(user)}
                            className="bg-blue-600 hover:bg-blue-700 text-white font-extrabold px-3 py-1.5 rounded-xl text-[10px] transition-colors flex items-center justify-center gap-1 cursor-pointer border-none"
                          >
                            <Play size={10} className="fill-current" /> Asignar Tarea
                          </button>

                          <div className="flex gap-1.5">
                            {/* Botón de Chat Directo */}
                            <button
                              onClick={() => {
                                setSelectedUserForTask(user);
                                setChatType('general');
                                setChatInput(`@${user.name} `);
                                setChatTab('chat');
                              }}
                              className="bg-slate-100 hover:bg-slate-200 text-slate-600 p-2 rounded-xl border-none cursor-pointer flex items-center justify-center transition-colors"
                              title="Chat Directo"
                            >
                              <MessageSquare size={13} />
                            </button>

                            {/* Botón de Suspensión / Activación */}
                            <button
                              onClick={() => handleToggleUserSuspension(user.id, user.is_active_employee !== false)}
                              disabled={suspendingUser === user.id}
                              className={`p-2 rounded-xl border-none cursor-pointer flex items-center justify-center transition-colors ${
                                user.is_active_employee !== false
                                  ? 'bg-rose-50 hover:bg-rose-100 text-rose-600'
                                  : 'bg-emerald-50 hover:bg-emerald-100 text-emerald-600'
                              }`}
                              title={user.is_active_employee !== false ? "Suspender acceso de colaborador" : "Reactivar colaborador"}
                            >
                              {suspendingUser === user.id ? (
                                <span className="w-3.5 h-3.5 border-2 border-slate-400 border-t-transparent rounded-full animate-spin"></span>
                              ) : user.is_active_employee !== false ? (
                                <Ban size={13} />
                              ) : (
                                <UserCheck size={13} />
                              )}
                            </button>
                          </div>
                        </div>
                      </div>
                    );
                  })}

                  {monitorData.users.length === 0 && (
                    <div className="col-span-2 bg-slate-50 border border-dashed border-slate-200 rounded-2xl py-12 text-center text-slate-400 text-xs">
                      No hay colaboradores actualmente en turno.
                    </div>
                  )}
                </div>
              </div>

              {/* PANEL 3: Bitácora & Chat en tiempo real / "Actividad" (1/4) */}
              <div className={`${activeMonitorTab === 'actividad' ? 'block' : 'hidden'} lg:block lg:col-span-1 border-t lg:border-t-0 lg:border-l border-slate-200 pt-6 lg:pt-0 lg:pl-4 space-y-4`}>
                <div className="flex gap-2 border-b border-slate-150 pb-1 shrink-0">
                  <button 
                    onClick={() => setChatTab('chat')}
                    className={`flex-1 py-1.5 text-xs font-black tracking-tight rounded-lg transition-colors flex items-center justify-center gap-1.5 ${chatTab === 'chat' ? 'bg-blue-50 text-blue-700 font-extrabold' : 'text-slate-500 hover:text-slate-700'}`}
                  >
                    💬 Chat del Día
                  </button>
                  <button 
                    onClick={() => setChatTab('feed')}
                    className={`flex-1 py-1.5 text-xs font-black tracking-tight rounded-lg transition-colors flex items-center justify-center gap-1.5 ${chatTab === 'feed' ? 'bg-blue-50 text-blue-700 font-extrabold' : 'text-slate-500 hover:text-slate-700'}`}
                  >
                    ⚡ Bitácora
                  </button>
                </div>

                {chatTab === 'chat' ? (
                  <div className="flex flex-col h-[400px] justify-between">
                    {/* Chat Messages */}
                    <div className="space-y-3 flex-1 overflow-y-auto pr-1 custom-scrollbar text-left p-0.5">
                      {monitorData.chat.map((msg) => {
                        let msgBg = 'bg-slate-100 text-slate-800';
                        let typeIcon = '💬';
                        if (msg.type === 'permission') {
                          msgBg = 'bg-blue-50 text-blue-800 border border-blue-100';
                          typeIcon = '🛡️';
                        } else if (msg.type === 'food_change') {
                          msgBg = 'bg-amber-50 text-amber-800 border border-amber-100';
                          typeIcon = '🍽️';
                        } else if (msg.type === 'announcement') {
                          msgBg = 'bg-purple-50 text-purple-800 border border-purple-100 font-bold';
                          typeIcon = '📢';
                        }

                        return (
                          <div key={msg.id} className={`p-3 rounded-xl text-xs space-y-1 hover:shadow-xs transition-shadow ${msgBg}`}>
                            <div className="flex justify-between items-center">
                              <span className="font-extrabold text-[10px] text-slate-800 flex items-center gap-1">
                                {typeIcon}
                                {msg.sender_name}
                              </span>
                              <span className="text-[9px] text-slate-400 font-semibold">{msg.time}</span>
                            </div>
                            <p className="font-semibold">{msg.content}</p>
                          </div>
                        );
                      })}

                      {monitorData.chat.length === 0 && (
                        <div className="text-center py-12 text-slate-400 text-xs">
                          No hay mensajes en el chat de colaboradores.
                        </div>
                      )}
                    </div>

                    {/* Chat Input */}
                    <form onSubmit={handleSendMessage} className="mt-3 pt-3 border-t border-slate-250 space-y-2">
                      <div className="flex gap-2">
                        <select 
                          value={chatType}
                          onChange={(e: any) => setChatType(e.target.value)}
                          className="text-[10px] font-bold border border-slate-200 rounded-lg px-2 py-1 bg-slate-50 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        >
                          <option value="general">General</option>
                          <option value="permission">Permiso</option>
                          <option value="food_change">Comida</option>
                          <option value="announcement">Anuncio</option>
                        </select>
                      </div>
                      <div className="flex gap-1.5">
                        <input
                          type="text"
                          value={chatInput}
                          onChange={(e) => setChatInput(e.target.value)}
                          placeholder="Mensaje de permisos, comida o aviso..."
                          className="flex-1 border border-slate-200 rounded-xl px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 font-semibold"
                        />
                        <button
                          type="submit"
                          disabled={!chatInput.trim() || sendingMessage}
                          className="bg-blue-600 hover:bg-blue-700 text-white rounded-xl p-2 transition-colors disabled:opacity-50 flex items-center justify-center border-none cursor-pointer"
                        >
                          <Send size={14} />
                        </button>
                      </div>
                    </form>
                  </div>
                ) : (
                  // Bitácora de Sucesos en Vivo
                  <div className="space-y-3 h-[400px] overflow-y-auto pr-1 custom-scrollbar">
                    {monitorData.feed.map((event) => {
                      let eventIconColor = 'text-blue-500 bg-blue-50';
                      if (event.action === 'check_in' || event.action === 'meal_end') {
                        eventIconColor = 'text-emerald-500 bg-emerald-50';
                      } else if (event.action === 'check_out' || event.action === 'meal_start') {
                        eventIconColor = 'text-amber-500 bg-amber-50';
                      } else if (event.action.includes('completed')) {
                        eventIconColor = 'text-violet-500 bg-violet-50';
                      }

                      return (
                        <div key={event.id} className="flex gap-2.5 p-2.5 bg-slate-50/50 rounded-xl border border-slate-100 hover:border-slate-200 transition-colors text-left animate-in fade-in slide-in-from-right-3 duration-300">
                          <div className={`p-1.5 rounded-lg ${eventIconColor} shrink-0 self-start mt-0.5`}>
                            <Activity size={12} />
                          </div>
                          <div className="min-w-0 flex-1">
                            <p className="text-xs text-slate-700 font-semibold leading-normal">
                              <span className="font-bold text-slate-900">{event.user}</span> {event.details}
                            </p>
                            <span className="text-[10px] text-slate-400 block mt-0.5">{event.time}</span>
                          </div>
                        </div>
                      );
                    })}

                    {monitorData.feed.length === 0 && (
                      <div className="text-center py-12 text-slate-400 text-xs">
                        No hay sucesos registrados hoy en la bitácora.
                      </div>
                    )}
                  </div>
                )}
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Panel de Adopción de Módulos y Precios (Arma tu Paquete Modular) */}
      <div className="mt-8 pt-6 border-t border-slate-200">
        <div className="flex flex-col md:flex-row md:items-center justify-between mb-6 gap-4">
          <div className="flex items-center gap-2">
            <Zap className="text-blue-600 animate-pulse" size={22} />
            <div>
              <h2 className="text-lg font-black text-slate-800 tracking-tight">Adopción de Módulos y Precios</h2>
              <p className="text-xs text-slate-500 font-semibold mt-0.5">Arma tu propio paquete modular y escala conforme tu sucursal lo requiera</p>
            </div>
          </div>
          <div className="p-3 bg-slate-100/80 rounded-2xl border border-slate-200/50 flex items-center gap-3 shrink-0">
            <span className="text-xs font-bold text-slate-500">Plan Actual:</span>
            <span className={`text-xs font-extrabold px-3 py-1 rounded-full uppercase tracking-wider ${
              currentTier === 'enterprise' ? 'bg-purple-100 text-purple-700' :
              currentTier === 'pro' ? 'bg-blue-100 text-blue-700' : 'bg-slate-200 text-slate-700'
            }`}>
              {currentTier === 'freemium' ? 'Gratuito (Freemium)' : currentTier === 'pro' ? 'Profesional' : 'Empresas (Dedicado)'}
            </span>
          </div>
        </div>

        {currentTier === 'freemium' ? (
          <div className="bg-white rounded-2xl border border-slate-200 p-6 space-y-6 shadow-sm relative overflow-hidden">
            <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
              
              {/* ATS Toggle Card */}
              {(() => {
                const activeModules = systemSettings?.active_modules || ['reloj', 'rrhh', 'operativo'];
                const isAtsActive = activeModules.includes('ats');
                
                const handleToggle = async () => {
                  setIsAdoptionSaving(true);
                  const updatedModules = isAtsActive 
                    ? activeModules.filter((m: string) => m !== 'ats')
                    : [...activeModules, 'ats'];
                  
                  try {
                    await axiosInstance.post('/sync/settings', { active_modules: updatedModules });
                    await fetchState();
                    setToastMessage(isAtsActive ? 'Módulo ATS desactivado.' : 'Módulo ATS adoptado con éxito.');
                    setTimeout(() => setToastMessage(null), 3000);
                  } catch (err) {
                    console.error(err);
                  } finally {
                    setIsAdoptionSaving(false);
                  }
                };

                return (
                  <div className={`p-4 rounded-2xl border transition-all ${isAtsActive ? 'border-violet-200 bg-violet-50/30' : 'border-slate-200 bg-slate-50/50'}`}>
                    <div className="flex justify-between items-start mb-3">
                      <div className="p-2 bg-violet-100 text-violet-600 rounded-xl">
                        <Briefcase size={18} />
                      </div>
                      <button 
                        disabled={isAdoptionSaving}
                        onClick={handleToggle}
                        className={`text-[11px] font-black px-3 py-1.5 rounded-xl transition-all ${
                          isAtsActive 
                            ? 'bg-violet-600 hover:bg-violet-750 text-white shadow-sm' 
                            : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200'
                        }`}
                      >
                        {isAtsActive ? 'Adoptado' : 'Adoptar'}
                      </button>
                    </div>
                    <h3 className="font-bold text-slate-800 text-xs">Reclutamiento ATS</h3>
                    <p className="text-slate-500 text-[10px] mt-1 mb-2 leading-relaxed">Vacantes, bolsa de trabajo y entrevistas 1-click.</p>
                    <span className="text-xs font-black text-violet-600">+$29 MXN / mes</span>
                  </div>
                );
              })()}

              {/* LMS Toggle Card */}
              {(() => {
                const activeModules = systemSettings?.active_modules || ['reloj', 'rrhh', 'operativo'];
                const isLmsActive = activeModules.includes('academia');
                
                const handleToggle = async () => {
                  setIsAdoptionSaving(true);
                  const updatedModules = isLmsActive 
                    ? activeModules.filter((m: string) => m !== 'academia')
                    : [...activeModules, 'academia'];
                  
                  try {
                    await axiosInstance.post('/sync/settings', { active_modules: updatedModules });
                    await fetchState();
                    setToastMessage(isLmsActive ? 'Módulo Academia desactivado.' : 'Módulo Academia adoptado con éxito.');
                    setTimeout(() => setToastMessage(null), 3000);
                  } catch (err) {
                    console.error(err);
                  } finally {
                    setIsAdoptionSaving(false);
                  }
                };

                return (
                  <div className={`p-4 rounded-2xl border transition-all ${isLmsActive ? 'border-sky-200 bg-sky-50/30' : 'border-slate-200 bg-slate-50/50'}`}>
                    <div className="flex justify-between items-start mb-3">
                      <div className="p-2 bg-sky-100 text-sky-600 rounded-xl">
                        <GraduationCap size={18} />
                      </div>
                      <button 
                        disabled={isAdoptionSaving}
                        onClick={handleToggle}
                        className={`text-[11px] font-black px-3 py-1.5 rounded-xl transition-all ${
                          isLmsActive 
                            ? 'bg-sky-600 hover:bg-sky-750 text-white shadow-sm' 
                            : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200'
                        }`}
                      >
                        {isLmsActive ? 'Adoptado' : 'Adoptar'}
                      </button>
                    </div>
                    <h3 className="font-bold text-slate-800 text-xs">Academia 360</h3>
                    <p className="text-slate-500 text-[10px] mt-1 mb-2 leading-relaxed">Cursos interactivos, inducción y gamificación.</p>
                    <span className="text-xs font-black text-sky-600">+$49 MXN / mes</span>
                  </div>
                );
              })()}

              {/* Reports Toggle Card */}
              {(() => {
                const activeModules = systemSettings?.active_modules || ['reloj', 'rrhh', 'operativo'];
                const isReportsActive = activeModules.includes('reportes');
                
                const handleToggle = async () => {
                  setIsAdoptionSaving(true);
                  const updatedModules = isReportsActive 
                    ? activeModules.filter((m: string) => m !== 'reportes')
                    : [...activeModules, 'reportes'];
                  
                  try {
                    await axiosInstance.post('/sync/settings', { active_modules: updatedModules });
                    await fetchState();
                    setToastMessage(isReportsActive ? 'Módulo Reportes desactivado.' : 'Módulo Reportes adoptado con éxito.');
                    setTimeout(() => setToastMessage(null), 3000);
                  } catch (err) {
                    console.error(err);
                  } finally {
                    setIsAdoptionSaving(false);
                  }
                };

                return (
                  <div className={`p-4 rounded-2xl border transition-all ${isReportsActive ? 'border-rose-200 bg-rose-50/30' : 'border-slate-200 bg-slate-50/50'}`}>
                    <div className="flex justify-between items-start mb-3">
                      <div className="p-2 bg-rose-100 text-rose-600 rounded-xl">
                        <BarChart3 size={18} />
                      </div>
                      <button 
                        disabled={isAdoptionSaving}
                        onClick={handleToggle}
                        className={`text-[11px] font-black px-3 py-1.5 rounded-xl transition-all ${
                          isReportsActive 
                            ? 'bg-rose-600 hover:bg-rose-750 text-white shadow-sm' 
                            : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200'
                        }`}
                      >
                        {isReportsActive ? 'Adoptado' : 'Adoptar'}
                      </button>
                    </div>
                    <h3 className="font-bold text-slate-800 text-xs">Reportes IA</h3>
                    <p className="text-slate-500 text-[10px] mt-1 mb-2 leading-relaxed">Faltas, retardos, Ley Silla y exportes Excel/PDF.</p>
                    <span className="text-xs font-black text-rose-600">+$19 MXN / mes</span>
                  </div>
                );
              })()}

              {/* Archivo Digital Toggle Card */}
              {(() => {
                const activeModules = systemSettings?.active_modules || ['reloj', 'rrhh', 'operativo'];
                const isDocsActive = activeModules.includes('documentos');
                
                const handleToggle = async () => {
                  setIsAdoptionSaving(true);
                  const updatedModules = isDocsActive 
                    ? activeModules.filter((m: string) => m !== 'documentos')
                    : [...activeModules, 'documentos'];
                  
                  try {
                    await axiosInstance.post('/sync/settings', { active_modules: updatedModules });
                    await fetchState();
                    setToastMessage(isDocsActive ? 'Módulo Archivo Digital desactivado.' : 'Módulo Archivo Digital adoptado con éxito.');
                    setTimeout(() => setToastMessage(null), 3000);
                  } catch (err) {
                    console.error(err);
                  } finally {
                    setIsAdoptionSaving(false);
                  }
                };

                return (
                  <div className={`p-4 rounded-2xl border transition-all ${isDocsActive ? 'border-amber-200 bg-amber-50/30' : 'border-slate-200 bg-slate-50/50'}`}>
                    <div className="flex justify-between items-start mb-3">
                      <div className="p-2 bg-amber-100 text-amber-600 rounded-xl">
                        <FileText size={18} />
                      </div>
                      <button 
                        disabled={isAdoptionSaving}
                        onClick={handleToggle}
                        className={`text-[11px] font-black px-3 py-1.5 rounded-xl transition-all ${
                          isDocsActive 
                            ? 'bg-amber-600 hover:bg-amber-700 text-white shadow-sm' 
                            : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200'
                        }`}
                      >
                        {isDocsActive ? 'Adoptado' : 'Adoptar'}
                      </button>
                    </div>
                    <h3 className="font-bold text-slate-800 text-xs">Archivo Digital</h3>
                    <p className="text-slate-500 text-[10px] mt-1 mb-2 leading-relaxed">Expedientes avanzados, manuales y contratos.</p>
                    <span className="text-xs font-black text-amber-600">+$19 MXN / mes</span>
                  </div>
                );
              })()}

              {/* Facturacion CFDI Toggle Card */}
              {(() => {
                const activeModules = systemSettings?.active_modules || ['reloj', 'rrhh', 'operativo'];
                const isCfdiActive = activeModules.includes('facturacion');
                
                const handleToggle = async () => {
                  setIsAdoptionSaving(true);
                  const updatedModules = isCfdiActive 
                    ? activeModules.filter((m: string) => m !== 'facturacion')
                    : [...activeModules, 'facturacion'];
                  
                  try {
                    await axiosInstance.post('/sync/settings', { active_modules: updatedModules });
                    await fetchState();
                    setToastMessage(isCfdiActive ? 'Módulo Nómina CFDI desactivado.' : 'Módulo Nómina CFDI adoptado con éxito.');
                    setTimeout(() => setToastMessage(null), 3000);
                  } catch (err) {
                    console.error(err);
                  } finally {
                    setIsAdoptionSaving(false);
                  }
                };

                return (
                  <div className={`p-4 rounded-2xl border transition-all ${isCfdiActive ? 'border-emerald-200 bg-emerald-50/30' : 'border-slate-200 bg-slate-50/50'}`}>
                    <div className="flex justify-between items-start mb-3">
                      <div className="p-2 bg-emerald-100 text-emerald-600 rounded-xl">
                        <Receipt size={18} />
                      </div>
                      <button 
                        disabled={isAdoptionSaving}
                        onClick={handleToggle}
                        className={`text-[11px] font-black px-3 py-1.5 rounded-xl transition-all ${
                          isCfdiActive 
                            ? 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm' 
                            : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200'
                        }`}
                      >
                        {isCfdiActive ? 'Adoptado' : 'Adoptar'}
                      </button>
                    </div>
                    <h3 className="font-bold text-slate-800 text-xs">Nómina CFDI 4.0</h3>
                    <p className="text-slate-500 text-[10px] mt-1 mb-2 leading-relaxed">Timbrado masivo de recibos del SAT.</p>
                    <span className="text-xs font-black text-emerald-600">+$39 MXN / mes</span>
                  </div>
                );
              })()}

            </div>

            {/* Price breakdown summary */}
            {(() => {
              const activeModules = systemSettings?.active_modules || ['reloj', 'rrhh', 'operativo'];
              const hasAts = activeModules.includes('ats');
              const hasLms = activeModules.includes('academia');
              const hasReports = activeModules.includes('reportes');
              const hasDocs = activeModules.includes('documentos');
              const hasCfdi = activeModules.includes('facturacion');
              const totalCost = (hasAts ? 29 : 0) + (hasLms ? 49 : 0) + (hasReports ? 19 : 0) + (hasDocs ? 19 : 0) + (hasCfdi ? 39 : 0);

              return (
                <div className="pt-5 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                  <div className="text-slate-600 text-xs font-medium">
                    Suscripción modular calculada: <strong className="text-slate-800 font-bold">$0 base (Starter)</strong> + {totalCost > 0 ? `$${totalCost} por módulos` : 'sin módulos extra'}
                  </div>
                  <div className="flex items-center gap-1">
                    <span className="text-slate-500 text-sm font-bold">Total Mensual:</span>
                    <span className="text-2xl font-black text-slate-900 font-sans tracking-tight">${totalCost} MXN / mes</span>
                  </div>
                </div>
              );
            })()}

          </div>
        ) : (
          <div className="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-6 relative overflow-hidden">
            <div className="absolute top-0 right-0 w-64 h-64 bg-blue-500/5 rounded-full blur-[80px] pointer-events-none"></div>
            <div>
              <h3 className="font-bold text-slate-800 text-sm">Paquete Todo Incluido Activado</h3>
              <p className="text-slate-500 text-[11px] mt-1 max-w-lg leading-relaxed">
                Tu empresa cuenta con el plan {currentTier === 'pro' ? 'Profesional' : 'Empresas'}. Tienes acceso completo e ilimitado a todos los módulos actuales y futuros (ATS, Academia, Nóminas y Reportes) sin costos adicionales por módulo.
              </p>
            </div>
            <div className="text-right shrink-0">
              <span className="text-slate-400 text-[10px] font-bold block uppercase tracking-widest">Inversión mensual</span>
              <span className="text-3xl font-black text-blue-600 font-sans tracking-tight">
                {currentTier === 'pro' ? '$99 MXN' : '$499 MXN'}
              </span>
              <span className="text-[10px] text-slate-500 font-bold block">facturado mensualmente</span>
            </div>
          </div>
        )}

      </div>
        </>
      )}

      {toastMessage && (
        <div className="fixed bottom-4 right-4 z-50 bg-slate-900 text-white font-semibold text-xs px-4 py-2.5 rounded-xl shadow-lg border border-slate-800 animate-in slide-in-from-bottom-2 duration-300 flex items-center gap-2">
          <CheckCircle2 size={14} className="text-emerald-400" />
          {toastMessage}
        </div>
      )}

      {/* Modal de Creación Rápida de Tarea */}
      {showCreateTaskModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
          {taskCreationMode === null ? (
            <div className="bg-white rounded-3xl shadow-2xl border border-slate-100 max-w-lg w-full p-7 animate-in zoom-in-95 duration-200 relative overflow-hidden">
              {/* Decorative gradient blur in background */}
              <div className="absolute -top-16 -right-16 w-36 h-36 bg-indigo-500/10 rounded-full blur-2xl pointer-events-none"></div>
              
              <div className="flex justify-between items-center mb-5 relative z-10">
                <div>
                  <h3 className="text-lg font-black text-slate-800 tracking-tight">Nueva Tarea Operativa</h3>
                  <p className="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">Selecciona el método de creación</p>
                </div>
                <button 
                  onClick={() => setShowCreateTaskModal(false)}
                  className="w-7 h-7 rounded-full bg-slate-50 hover:bg-slate-100 text-slate-400 hover:text-slate-600 transition-colors flex items-center justify-center font-bold text-xs"
                >
                  ✕
                </button>
              </div>

              <div className="space-y-4 mt-2">
                {/* Crear por Voz */}
                <button
                  type="button"
                  onClick={() => {
                    if (!isFeatureUnlocked('voice_assistant')) {
                      alert("El Asistente de Voz AI para crear tareas está disponible únicamente en el Plan PRO. Por favor, actualiza tu plan en Configuración.");
                      return;
                    }
                    setTaskCreationMode('voice');
                    setTimeout(() => {
                      startVoiceWizard();
                    }, 150);
                  }}
                  className={`w-full flex items-start gap-4 p-5 rounded-2xl border-2 transition-all text-left group shadow-sm hover:shadow-md relative overflow-hidden ${
                    !isFeatureUnlocked('voice_assistant') 
                      ? 'border-slate-200 bg-slate-50/80 opacity-75' 
                      : 'border-indigo-500 bg-indigo-50/20 hover:bg-indigo-50/40'
                  }`}
                >
                  <div className={`absolute top-0 right-0 text-white text-[9px] font-black uppercase px-3 py-1 rounded-bl-xl tracking-wider ${
                    !isFeatureUnlocked('voice_assistant') ? 'bg-slate-500' : 'bg-indigo-500'
                  }`}>
                    {!isFeatureUnlocked('voice_assistant') ? '🔒 Plan PRO' : 'Recomendado'}
                  </div>
                  <div className={`p-3.5 rounded-2xl shrink-0 group-hover:scale-105 transition-transform shadow-md ${
                    !isFeatureUnlocked('voice_assistant') 
                      ? 'bg-slate-300 text-slate-500 shadow-slate-300/10' 
                      : 'bg-gradient-to-br from-indigo-500 to-indigo-600 text-white shadow-indigo-500/20'
                  }`}>
                    {!isFeatureUnlocked('voice_assistant') ? <Lock size={22} className="stroke-[2.5]" /> : <Mic size={22} className="stroke-[2.5]" />}
                  </div>
                  <div className="pr-12">
                    <h4 className="text-sm font-black text-slate-800 group-hover:text-indigo-600 transition-colors flex items-center gap-1.5 font-bold">
                      Asistente de Voz AI
                      {isFeatureUnlocked('voice_assistant') && <Sparkles size={14} className="text-amber-500 fill-amber-400 animate-pulse" />}
                    </h4>
                    <p className="text-xs text-slate-500 font-medium mt-1 leading-relaxed">
                      Crea la tarea con manos libres. Describe qué hacer en un solo enunciado y el asistente AI llenará el título, duración, colaborador y evidencia.
                    </p>
                  </div>
                </button>

                {/* Crear a Mano */}
                <button
                  type="button"
                  onClick={() => {
                    setTaskCreationMode('manual');
                  }}
                  className="w-full flex items-start gap-4 p-5 rounded-2xl border border-slate-200 bg-white hover:bg-slate-50 hover:border-slate-300 transition-all text-left group shadow-sm hover:shadow-md"
                >
                  <div className="p-3.5 rounded-2xl bg-slate-100 text-slate-600 shrink-0 group-hover:scale-105 transition-transform border border-slate-200">
                    <FileText size={22} className="stroke-[2.5]" />
                  </div>
                  <div>
                    <h4 className="text-sm font-black text-slate-800 group-hover:text-slate-900 transition-colors">
                      Escribir en Formulario
                    </h4>
                    <p className="text-xs text-slate-500 font-medium mt-1 leading-relaxed">
                      Completa los detalles de forma manual y paso a paso mediante campos de texto tradicionales si prefieres mayor control visual.
                    </p>
                  </div>
                </button>
              </div>

              <div className="mt-6 pt-4 border-t border-slate-100 flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setShowCreateTaskModal(false)}
                  className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-bold transition-colors"
                >
                  Cerrar
                </button>
              </div>
            </div>
          ) : taskCreationMode === 'voice' ? (
            <div className="bg-slate-900 text-white rounded-3xl shadow-2xl border border-slate-800 max-w-xl w-full p-6 animate-in zoom-in-95 duration-200 relative overflow-hidden">
              {/* Background glows */}
              <div className="absolute -top-24 -left-24 w-48 h-48 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>
              <div className="absolute -bottom-24 -right-24 w-48 h-48 bg-purple-500/10 rounded-full blur-3xl pointer-events-none"></div>

              <div className="flex justify-between items-center mb-5 relative z-10">
                <div className="flex items-center gap-2">
                  <div className="p-1.5 bg-indigo-500/10 text-indigo-400 rounded-lg border border-indigo-500/20">
                    <Mic size={16} />
                  </div>
                  <div>
                    <h3 className="text-xs font-black uppercase tracking-widest text-indigo-400">Asistente por Voz</h3>
                    <p className="text-[10px] text-slate-400 font-medium uppercase tracking-wider mt-0.5">Registrar vía comandos de voz</p>
                  </div>
                </div>
                <div className="flex gap-2">
                  <button
                    type="button"
                    onClick={() => {
                      if (window.speechSynthesis) window.speechSynthesis.cancel();
                      setIsListening(false);
                      setVoiceWizardActive(false);
                      setVoiceWizardStep('idle');
                      setTaskCreationMode('manual');
                    }}
                    className="text-[10px] font-black text-indigo-300 bg-indigo-500/10 hover:bg-indigo-500/20 px-2.5 py-1.5 rounded-lg border border-indigo-500/20 transition-all uppercase tracking-wider"
                  >
                    Escribir a mano
                  </button>
                  <button 
                    onClick={() => {
                      if (window.speechSynthesis) window.speechSynthesis.cancel();
                      setIsListening(false);
                      setVoiceWizardActive(false);
                      setVoiceWizardStep('idle');
                      setTaskCreationMode(null);
                      setShowCreateTaskModal(false);
                    }}
                    className="w-7 h-7 rounded-full bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white transition-colors flex items-center justify-center font-bold text-xs"
                  >
                    ✕
                  </button>
                </div>
              </div>

              {/* Conversational Voice Panel */}
              {voiceWizardActive && (
                <div className="bg-white/5 border border-white/10 rounded-2xl p-5 border-indigo-500/20 shadow-inner relative overflow-hidden animate-in fade-in duration-300">
                  {/* Animated sound wave indicator */}
                  <div className="absolute right-4 top-4 flex items-end gap-1 h-6">
                    <span className={`w-1 bg-indigo-400 rounded-full ${isListening ? 'animate-bounce' : 'h-1'}`} style={{ animationDelay: '0.1s', animationDuration: '0.6s' }}></span>
                    <span className={`w-1 bg-purple-400 rounded-full ${isListening ? 'animate-bounce' : 'h-2'}`} style={{ animationDelay: '0.3s', animationDuration: '0.5s' }}></span>
                    <span className={`w-1 bg-pink-400 rounded-full ${isListening ? 'animate-bounce' : 'h-1.5'}`} style={{ animationDelay: '0.2s', animationDuration: '0.7s' }}></span>
                    <span className={`w-1 bg-indigo-400 rounded-full ${isListening ? 'animate-bounce' : 'h-3'}`} style={{ animationDelay: '0.4s', animationDuration: '0.4s' }}></span>
                  </div>

                  <div className="flex items-center gap-2 mb-4">
                    <div className={`p-1.5 rounded-lg text-xs font-black uppercase tracking-wider ${
                      voiceWizardStep === 'asking_initial' ? 'bg-indigo-500 text-white' : 'bg-white/10 text-indigo-300'
                    }`}>
                      {voiceWizardStep === 'asking_initial' && 'Descripción'}
                      {voiceWizardStep === 'asking_time' && 'Duración'}
                      {voiceWizardStep === 'asking_assignee' && 'Destinatario'}
                      {voiceWizardStep === 'asking_assistant' && 'Evidencia'}
                      {voiceWizardStep === 'asking_assistant_prompt' && 'Instrucción'}
                      {voiceWizardStep === 'confirm_save' && 'Guardar'}
                    </div>
                    <span className="text-[10px] text-slate-400 font-bold uppercase tracking-wider">
                      {voiceWizardStep === 'asking_initial' && 'Paso 1 de 6'}
                      {voiceWizardStep === 'asking_time' && 'Paso 2 de 6'}
                      {voiceWizardStep === 'asking_assignee' && 'Paso 3 de 6'}
                      {voiceWizardStep === 'asking_assistant' && 'Paso 4 de 6'}
                      {voiceWizardStep === 'asking_assistant_prompt' && 'Paso 5 de 6'}
                      {voiceWizardStep === 'confirm_save' && 'Paso 6 de 6'}
                    </span>
                  </div>

                  {/* Question spoken by assistant */}
                  <div className="bg-white/5 border border-white/10 rounded-xl p-3.5 mb-3.5">
                    <p className="text-[9px] font-bold text-indigo-400 uppercase tracking-widest">Asistente</p>
                    <p className="text-sm font-extrabold text-white mt-1 leading-relaxed">{voiceWizardPrompt}</p>
                  </div>

                  {/* Transcript of user speech */}
                  <div className="bg-black/40 rounded-xl p-3.5 text-xs min-h-[60px] flex flex-col justify-between border border-white/5">
                    <div>
                      <span className="text-[9px] font-bold text-slate-500 block uppercase tracking-wider">Tú dijiste</span>
                      <p className="text-white/80 italic mt-1 font-semibold leading-normal">
                        {voiceWizardTranscript ? `"${voiceWizardTranscript}"` : (isListening ? 'Escuchando tu voz...' : 'Esperando respuesta...')}
                      </p>
                    </div>
                    {isListening && (
                      <div className="flex items-center gap-1.5 mt-2.5 text-[9px] text-rose-400 font-extrabold animate-pulse">
                        <span className="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                        <span>MICRÓFONO ACTIVO - HABLA AHORA</span>
                      </div>
                    )}
                  </div>

                  {/* Summary of parsed/filled values */}
                  <div className="mt-4 pt-4 border-t border-white/10 grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-2 text-[10px] text-slate-400 font-semibold bg-black/10 p-3 rounded-xl">
                    <div className="truncate">📌 Título: <span className="text-white font-black">{newTaskTitle || 'Pendiente'}</span></div>
                    <div>⏱️ Tiempo: <span className="text-white font-black">{newTaskMins ? `${newTaskMins} mins` : 'Pendiente'}</span></div>
                    <div className="truncate">👤 Asignado: <span className="text-white font-black">
                      {newTaskTargetId 
                        ? (newTaskTargetType === 'user' 
                            ? (globalUsers.find(u => String(u.id) === newTaskTargetId)?.name || 'Colaborador')
                            : ((monitorData.job_roles || []).find(r => String(r.id) === newTaskTargetId)?.name || 'Puesto')
                          )
                        : 'Cualquiera / Todos'
                      }
                    </span></div>
                    <div>⚡ Prioridad: <span className="text-white font-black uppercase text-[9px]">{newTaskPriority}</span></div>
                    <div>🤖 Asistente: <span className="text-white font-black">
                      {newTaskAssistantType === 'evidencia_foto' ? '📷 Foto' :
                       newTaskAssistantType === 'captura_numero' ? '🔢 Número' :
                       newTaskAssistantType === 'texto' ? '📝 Texto' : 'Ninguno'}
                    </span></div>
                    {newTaskAssistantPrompt && (
                      <div className="col-span-2 truncate">💬 Pregunta: <span className="text-white font-black italic">"{newTaskAssistantPrompt}"</span></div>
                    )}
                  </div>

                  <div className="mt-5 flex justify-end gap-2 text-xs font-bold pt-2">
                    <button
                      type="button"
                      onClick={() => {
                        if (window.speechSynthesis) window.speechSynthesis.cancel();
                        setIsListening(false);
                        setVoiceWizardActive(false);
                        setVoiceWizardStep('idle');
                        setTaskCreationMode(null);
                      }}
                      className="bg-white/10 hover:bg-white/20 text-white border border-white/10 px-4 py-2 rounded-xl transition-all"
                    >
                      Atrás
                    </button>
                    {voiceWizardStep === 'confirm_save' && (
                      <button
                        type="button"
                        onClick={saveTaskDirectly}
                        className="bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-600 hover:to-teal-700 text-white px-5 py-2 rounded-xl shadow-lg shadow-emerald-950/20 transition-all hover:-translate-y-0.5"
                      >
                        Guardar Tarea
                      </button>
                    )}
                  </div>
                </div>
              )}
            </div>
          ) : (
            <div className="bg-white rounded-3xl shadow-2xl border border-slate-100 max-w-xl w-full p-7 animate-in zoom-in-95 duration-200 relative overflow-hidden">
              {/* Decorative gradient blur in background */}
              <div className="absolute -top-16 -right-16 w-36 h-36 bg-blue-500/5 rounded-full blur-2xl pointer-events-none"></div>

              {/* Cabecera limpia */}
              <div className="flex justify-between items-center mb-5 relative z-10">
                <div>
                  <h3 className="text-lg font-black text-slate-800 tracking-tight">Nueva Tarea Operativa</h3>
                  <p className="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">Escribir en Formulario</p>
                </div>
                <div className="flex items-center gap-2">
                  {isFeatureUnlocked('voice_assistant') && (
                    <button
                      type="button"
                      onClick={() => {
                        setTaskCreationMode('voice');
                        setTimeout(() => {
                          startVoiceWizard();
                        }, 150);
                      }}
                      className="px-2.5 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider flex items-center gap-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 border border-indigo-100 transition-all shadow-sm"
                    >
                      <Mic size={12} />
                      Cambiar a Voz
                    </button>
                  )}
                  <button 
                    onClick={() => {
                      setTaskCreationMode(null);
                      setShowCreateTaskModal(false);
                    }}
                    className="w-7 h-7 rounded-full bg-slate-50 hover:bg-slate-100 text-slate-400 hover:text-slate-600 transition-colors flex items-center justify-center font-bold text-xs"
                  >
                    ✕
                  </button>
                </div>
              </div>

              <form onSubmit={handleCreateTask} className="space-y-4 max-h-[70vh] overflow-y-auto pr-1.5 custom-scrollbar">
                {/* Título de la Tarea */}
                <div>
                  <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Título de la Tarea</label>
                  <input
                    type="text"
                    required
                    value={newTaskTitle}
                    onChange={(e) => setNewTaskTitle(e.target.value)}
                    placeholder="Ej. Limpiar cristales frontales"
                    className="w-full border border-slate-200 rounded-xl px-3.5 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 font-semibold text-slate-800 placeholder-slate-400"
                  />
                </div>

                {/* Grid de Tiempo Estimado y Puntos */}
                <div className="grid grid-cols-2 gap-3.5">
                  <div>
                    <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Tiempo Estimado (Mins)</label>
                    <input
                      type="number"
                      required
                      min={1}
                      value={newTaskMins}
                      onChange={(e) => setNewTaskMins(Number(e.target.value))}
                      className="w-full border border-slate-200 rounded-xl px-3.5 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 font-semibold text-slate-800"
                    />
                  </div>
                  <div>
                    <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Puntos de Proactividad</label>
                    <input
                      type="number"
                      required
                      min={1}
                      value={newTaskPoints}
                      onChange={(e) => setNewTaskPoints(Number(e.target.value))}
                      className="w-full border border-slate-200 rounded-xl px-3.5 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 font-semibold text-slate-800"
                    />
                  </div>
                </div>

                {/* Grid de Prioridad y Categoría */}
                <div className="grid grid-cols-2 gap-3.5">
                  <div>
                    <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Nivel de Prioridad</label>
                    <select
                      value={newTaskPriority}
                      onChange={(e) => setNewTaskPriority(e.target.value)}
                      className="w-full border border-slate-200 rounded-xl px-3.5 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 font-semibold text-slate-800"
                    >
                      <option value="normal">Normal</option>
                      <option value="medium">Mediana</option>
                      <option value="high">Alta</option>
                      <option value="bloqueante">Bloqueante</option>
                    </select>
                  </div>
                  <div>
                    <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Categoría</label>
                    <select
                      value={newTaskCategory}
                      onChange={(e) => setNewTaskCategory(e.target.value)}
                      className="w-full border border-slate-200 rounded-xl px-3.5 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 font-semibold text-slate-800"
                    >
                      <option value="operativo">Operativo</option>
                      <option value="administrativo">Administrativo</option>
                      <option value="limpieza">Limpieza</option>
                      <option value="atencion">Atención al Cliente</option>
                    </select>
                  </div>
                </div>

                {/* Grid de Destinatario */}
                <div className="grid grid-cols-2 gap-3.5">
                  <div>
                    <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Tipo de Destinatario</label>
                    <select
                      value={newTaskTargetType}
                      onChange={(e) => {
                        setNewTaskTargetType(e.target.value as 'role' | 'user');
                        setNewTaskTargetId('');
                      }}
                      className="w-full border border-slate-200 rounded-xl px-3.5 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 font-semibold text-slate-800"
                    >
                      <option value="role">Por Puesto de Trabajo (Bolsa)</option>
                      <option value="user">Por Colaborador Específico (Directa)</option>
                    </select>
                  </div>
                  <div>
                    {newTaskTargetType === 'role' ? (
                      <>
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Puesto de Trabajo</label>
                        <select
                          value={newTaskTargetId}
                          onChange={(e) => setNewTaskTargetId(e.target.value)}
                          className="w-full border border-slate-200 rounded-xl px-3.5 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 font-semibold text-slate-800"
                        >
                          <option value="">Cualquiera / Todos los Puestos</option>
                          {(monitorData.job_roles || []).map((role) => (
                            <option key={role.id} value={role.id}>{role.name}</option>
                          ))}
                        </select>
                      </>
                    ) : (
                      <>
                        <label className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Colaborador</label>
                        <select
                          required
                          value={newTaskTargetId}
                          onChange={(e) => setNewTaskTargetId(e.target.value)}
                          className="w-full border border-slate-200 rounded-xl px-3.5 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 font-semibold text-slate-800"
                        >
                          <option value="">Selecciona un colaborador...</option>
                          {globalUsers.map((user) => (
                            <option key={user.id} value={user.id}>{user.name} ({user.role || 'Colaborador'})</option>
                          ))}
                        </select>
                      </>
                    )}
                  </div>
                </div>

                {/* Modo Autocaptura (IA) */}
                <div 
                  onClick={() => setNewTaskIsAutoCapture(!newTaskIsAutoCapture)}
                  className={`flex items-center justify-between p-3 rounded-xl border cursor-pointer select-none transition-all ${
                    newTaskIsAutoCapture 
                      ? 'bg-blue-50/50 border-blue-200' 
                      : 'bg-slate-50/50 border-slate-200 hover:bg-slate-50'
                  }`}
                >
                  <div className="flex items-center gap-2.5">
                    <Cpu size={14} className="text-blue-500" />
                    <div>
                      <span className="text-xs font-bold text-slate-700 block">Modo Autocaptura (IA)</span>
                      <span className="text-[9.5px] text-slate-400">Aprenderá automáticamente de la telemetría real del personal.</span>
                    </div>
                  </div>
                  <input
                    type="checkbox"
                    checked={newTaskIsAutoCapture}
                    onChange={() => {}}
                    className="w-4 h-4 rounded text-blue-600 focus:ring-blue-500 border-slate-300 pointer-events-none"
                  />
                </div>

                {/* Mini-Asistente Acoplado */}
                <div className="bg-slate-50 border border-slate-200 rounded-xl p-3.5">
                  <div className="flex items-center gap-1.5 text-xs font-bold text-slate-700 mb-2">
                    <Bot size={14} className="text-blue-500" />
                    Mini-Asistente Acoplado
                  </div>
                  <select
                    value={newTaskAssistantType}
                    onChange={(e) => setNewTaskAssistantType(e.target.value)}
                    className="w-full border border-slate-200 bg-white rounded-xl px-3.5 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 font-semibold text-slate-800"
                  >
                    <option value="ninguno">Ninguno</option>
                    <option value="evidencia_foto">Evidencia Fotográfica</option>
                    <option value="captura_numero">Captura de Cantidad / Número</option>
                    <option value="texto">Nota de Texto Corta</option>
                  </select>

                  {newTaskAssistantType !== 'ninguno' && (
                    <div className="mt-3 animate-in slide-in-from-top-2 duration-200">
                      <label className="text-[10px] font-bold uppercase tracking-wider text-slate-500 block mb-1">
                        ¿Qué le preguntará el asistente al empleado?
                      </label>
                      <input
                        type="text"
                        required
                        value={newTaskAssistantPrompt}
                        onChange={(e) => setNewTaskAssistantPrompt(e.target.value)}
                        placeholder={
                          newTaskAssistantType === 'evidencia_foto'
                            ? 'Ej. Toma una foto de tu estación limpia.'
                            : newTaskAssistantType === 'captura_numero'
                            ? 'Ej. ¿Cuántas bolsas contaste en el inventario?'
                            : 'Ej. Escribe observaciones o notas adicionales.'
                        }
                        className="w-full border border-slate-200 bg-white rounded-xl px-3.5 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500 font-semibold text-slate-800 placeholder-slate-400"
                      />
                    </div>
                  )}
                </div>

                {/* Acciones */}
                <div className="mt-6 flex justify-end gap-2 text-xs font-bold pt-2 border-t border-slate-100">
                  <button
                    type="button"
                    onClick={() => {
                      setTaskCreationMode(null);
                    }}
                    className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg transition-colors"
                  >
                    Atrás
                  </button>
                  <button
                    type="submit"
                    disabled={creatingTask || !newTaskTitle.trim()}
                    className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors disabled:opacity-50"
                  >
                    {creatingTask ? 'Creando...' : 'Guardar Tarea'}
                  </button>
                </div>
              </form>
            </div>
          )
}
        </div>
      )}

      {/* Modal de Asignación de Tarea Rápida */}
      {selectedUserForTask && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
          <div className="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-md w-full p-6 animate-in zoom-in-95 duration-200">
            <h3 className="text-base font-black text-slate-800">Asignar Tarea en Tiempo Real</h3>
            <p className="text-xs text-slate-500 mt-1">Selecciona la tarea que deseas asignar a <span className="font-bold text-slate-700">{selectedUserForTask.name}</span></p>

            <div className="mt-4 space-y-2.5 max-h-[220px] overflow-y-auto pr-1 custom-scrollbar">
              {monitorData.available_tasks.map((task) => (
                <label 
                  key={task.id}
                  onClick={() => setSelectedTaskId(task.id)}
                  className={`flex items-center justify-between p-3 rounded-xl border text-xs cursor-pointer transition-all ${selectedTaskId === task.id ? 'border-blue-500 bg-blue-50/50' : 'border-slate-200 bg-slate-50 hover:bg-slate-100/70'}`}
                >
                  <div className="flex items-center gap-2.5">
                    <input 
                      type="radio" 
                      name="assign_task_radio"
                      checked={selectedTaskId === task.id}
                      onChange={() => setSelectedTaskId(task.id)}
                      className="text-blue-600 focus:ring-blue-500"
                    />
                    <div>
                      <span className="font-bold text-slate-800 block">{task.title}</span>
                      <span className="text-[10px] text-slate-400">{task.estimated_mins} mins estimados</span>
                    </div>
                  </div>
                  <span className={`text-[9px] font-bold px-2 py-0.5 rounded capitalize ${
                    task.priority === 'high' ? 'bg-rose-100 text-rose-700' :
                    task.priority === 'medium' ? 'bg-amber-100 text-amber-700' :
                    'bg-slate-100 text-slate-600'
                  }`}>
                    {task.priority}
                  </span>
                </label>
              ))}
              
              {monitorData.available_tasks.length === 0 && (
                <div className="text-center py-6 text-slate-400 text-xs font-semibold">
                  No hay tareas creadas para asignar. Crea tareas primero con el botón "+ Crear Tarea".
                </div>
              )}
            </div>

            <div className="mt-6 flex justify-end gap-2 text-xs font-bold">
              <button
                onClick={() => { setSelectedUserForTask(null); setSelectedTaskId(null); }}
                className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg transition-colors"
              >
                Cancelar
              </button>
              <button
                onClick={handleAssignTask}
                disabled={!selectedTaskId || assigningTask}
                className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors disabled:opacity-50 flex items-center gap-1.5"
              >
                {assigningTask ? 'Asignando...' : 'Asignar Ahora'}
              </button>
            </div>
          </div>
        </div>
      )}

    </div>
  );
};

export default DashboardTalent360;
