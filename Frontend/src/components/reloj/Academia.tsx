import React, { useState, useEffect } from 'react';
import { 
  Lock, Star, Briefcase, Crown, Trophy, Map, GraduationCap, ShieldCheck,
  BookOpen, Wrench, Laptop, ClipboardList, Target, Award, Key, DollarSign, 
  Store, Coffee, Package, Settings, Truck, HeartHandshake, Check, Sparkles, ChevronRight, X 
} from 'lucide-react';
import { useAppStore } from '../../store/useAppStore';
import { ColorMap } from '../SaaSAccountSettings';
import YouTube from 'react-youtube';
import { CertificadoImprimible } from './CertificadoImprimible';
import axiosInstance from '../../lib/axios';

class AcademiaErrorBoundary extends React.Component<{children: React.ReactNode}, {hasError: boolean, error: any}> {
  constructor(props: any) { super(props); this.state = { hasError: false, error: null }; }
  static getDerivedStateFromError(error: any) { return { hasError: true, error }; }
  componentDidCatch(error: any, errorInfo: any) { console.error("Academia Error:", error, errorInfo); }
  render() {
    if (this.state.hasError) {
      return (
        <div className="p-6 bg-rose-50 text-rose-800 h-full overflow-auto">
          <h2 className="font-bold text-xl mb-4">Error Inesperado</h2>
          <pre className="text-xs break-words whitespace-pre-wrap">{this.state.error?.toString()}</pre>
          <pre className="text-xs break-words whitespace-pre-wrap mt-4">{this.state.error?.stack}</pre>
        </div>
      );
    }
    return this.props.children;
  }
}

const getRoleIcon = (roleName: string) => {
  const name = roleName.toLowerCase();
  if (name.includes('gerente') || name.includes('director') || name.includes('administrad')) {
    return <Crown className="w-6 h-6 text-amber-500" />;
  }
  if (name.includes('cajer') || name.includes('caja') || name.includes('cobro')) {
    return <DollarSign className="w-6 h-6 text-emerald-500" />;
  }
  if (name.includes('ventas') || name.includes('asesor') || name.includes('comercial') || name.includes('piso')) {
    return <Store className="w-6 h-6 text-blue-500" />;
  }
  if (name.includes('almacen') || name.includes('bodega') || name.includes('inventario') || name.includes('recibo') || name.includes('logist')) {
    return <Package className="w-6 h-6 text-indigo-500" />;
  }
  if (name.includes('supervisor') || name.includes('sup.') || name.includes('coordinador')) {
    return <Target className="w-6 h-6 text-rose-500" />;
  }
  if (name.includes('ayudante') || name.includes('auxiliar') || name.includes('general') || name.includes('integral')) {
    return <HeartHandshake className="w-6 h-6 text-teal-500" />;
  }
  if (name.includes('mantenimiento') || name.includes('limpieza') || name.includes('aseo')) {
    return <Sparkles className="w-6 h-6 text-yellow-500" />;
  }
  if (name.includes('seguridad') || name.includes('vigilante')) {
    return <ShieldCheck className="w-6 h-6 text-cyan-500" />;
  }
  if (name.includes('repartidor') || name.includes('chofer')) {
    return <Truck className="w-6 h-6 text-amber-500" />;
  }
  return <Briefcase className="w-6 h-6 text-slate-500" />;
};

function ProgressRing({ percentage, color = '#6366F1', size = 96, strokeWidth = 6 }: { percentage: number; color?: string; size?: number; strokeWidth?: number }) {
  const radius = (size - strokeWidth) / 2;
  const circumference = radius * 2 * Math.PI;
  const strokeDashoffset = circumference - (percentage / 100) * circumference;

  return (
    <svg className="absolute inset-0 transform -rotate-90 pointer-events-none" width={size} height={size}>
      <circle
        className="text-slate-200/60"
        strokeWidth={strokeWidth}
        stroke="currentColor"
        fill="transparent"
        r={radius}
        cx={size / 2}
        cy={size / 2}
      />
      <circle
        stroke={color}
        strokeWidth={strokeWidth}
        strokeDasharray={circumference}
        strokeDashoffset={strokeDashoffset}
        strokeLinecap="round"
        fill="transparent"
        r={radius}
        cx={size / 2}
        cy={size / 2}
        className="transition-all duration-500 ease-out"
      />
    </svg>
  );
}

function AcademiaContent({ onBack, autoOpenCourseId }: { onBack: () => void; autoOpenCourseId?: number | null }) {
  const [courses, setCourses] = useState<any[]>([]);
  // Academia AC10 (auditoría 2026-08-04): aquí se inyectaba un "Curso de Puntualidad" que solo
  // existía en el navegador (id 999) cuando ningún curso real llevaba "puntualidad" en el
  // título — que es el caso de las empresas creadas por el wizard. Se retiró porque estaba
  // roto de tres formas a la vez: su `quiz_data` era un TEXTO JSON y no un arreglo, así que
  // abrir su evaluación reventaba la pantalla en el `.map`; no podía desbloquear el checador,
  // porque el bloqueo se levanta con el progreso del curso REAL que configure la empresa
  // (`punctuality_course_id`) y un id de mentira no puede tener progreso; y le mostraba a
  // cualquier empresa un texto con la marca DecorArte y, como video, un rickroll de prueba.
  // Si la empresa no ha configurado su curso de puntualidad, el reloj ya lo dice por su lado.
  const setCoursesSafe = (rawList: any[]) => {
    setCourses(Array.isArray(rawList) ? rawList : []);
  };
  const [roles, setRoles] = useState<any[]>([]);
  const [userProgress, setUserProgress] = useState<any[]>([]);
  const [selectedAnswers, setSelectedAnswers] = useState<Record<number, number>>({});
  const [targetRoleId, setTargetRoleId] = useState<number | null>(() => useAppStore.getState().currentUser?.job_role_id || null);
  const [activeTab, setActiveTab] = useState<'plan' | 'logros'>('plan');
  const [loading, setLoading] = useState(true);
  const [activeCourse, setActiveCourse] = useState<any>(null);
  const [showQuiz, setShowQuiz] = useState(false);
  const [videoFinished, setVideoFinished] = useState(false);
  const [failedAttempts, setFailedAttempts] = useState(0);
  const [selectedPrintTemplate, setSelectedPrintTemplate] = useState<any>(null);
  const [selectedCourseForDrawer, setSelectedCourseForDrawer] = useState<any | null>(null);
  const [showWelcomeModal, setShowWelcomeModal] = useState(() => localStorage.getItem('decorarte_academy_welcome_dismissed') !== 'true');

  const { currentUser: loggedUser, systemSettings, completeInduction } = useAppStore();

  const getModuleColor = (modId: string) => {
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
  const activeColor = getModuleColor('academia');

  // Calcular conteo de carreras
  const pendingCareersCount = roles.filter(role => {
    const roleCourses = courses.filter(c => c.target_job_role_id === role.id);
    if (roleCourses.length === 0) return false;
    const completedRoleCourses = roleCourses.filter(c => {
      const p = userProgress.find(up => up.course_id === c.id);
      return p?.status === 'completed';
    });
    return completedRoleCourses.length < roleCourses.length;
  }).length;

  const completedCareersCount = roles.filter(role => {
    const roleCourses = courses.filter(c => c.target_job_role_id === role.id);
    if (roleCourses.length === 0) return false;
    const completedRoleCourses = roleCourses.filter(c => {
      const p = userProgress.find(up => up.course_id === c.id);
      return p?.status === 'completed';
    });
    return completedRoleCourses.length === roleCourses.length;
  }).length;

  useEffect(() => {
    axiosInstance.get('/academy/courses')
      .then(res => {
        const data = res.data;
        if (data && data.courses && Array.isArray(data.courses)) {
          setCoursesSafe(data.courses);
          setRoles(Array.isArray(data.job_roles) ? data.job_roles : []);
          setUserProgress(Array.isArray(data.user_progress) ? data.user_progress : []);
        } else if (Array.isArray(data)) {
          setCoursesSafe(data); // Legacy fallback
        } else {
          console.error("API Error or Invalid format:", data);
          setCoursesSafe([]);
          setRoles([]);
          setUserProgress([]);
        }
        setLoading(false);
      })
      .catch(err => {
        console.error('Error fetching courses', err);
        setCoursesSafe([]);
        setRoles([]);
        setUserProgress([]);
        setLoading(false);
      });
  }, []);

  // Auditoría reloj checador (2026-07-22), Hallazgo 1: el botón "Ir a la Academia" del estado #1
  // (Fichaje Bloqueado) navega aquí con el required_course_id real del backend — este efecto abre
  // ese curso directo en cuanto la lista de cursos termina de cargar, en vez de dejar al empleado
  // buscarlo manualmente.
  useEffect(() => {
    if (autoOpenCourseId && !activeCourse && courses.length > 0) {
      const target = courses.find((c: any) => Number(c.id) === Number(autoOpenCourseId));
      if (target) setActiveCourse(target);
    }
  }, [autoOpenCourseId, courses]);

  const handleStartCourse = (course: any) => {
    setActiveCourse(course);
    setShowQuiz(false);
    setVideoFinished(false);
    // AC3: los intentos fallidos vienen del servidor. Antes se reiniciaban aquí, así que
    // cerrar y reabrir el curso devolvía las dos "vidas" y el conteo no significaba nada.
    const progreso = userProgress.find((p: any) => p.course_id === course.id);
    setFailedAttempts(Number(progreso?.failed_attempts ?? 0));
    setSelectedAnswers({});
  };

  const extractYouTubeId = (url: string) => {
    if (!url) return null;
    const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|&v=)([^#&?]*).*/;
    const match = url.match(regExp);
    return (match && match[2].length === 11) ? match[2] : null;
  };

  const onVideoEnd = () => {
    setVideoFinished(true);
  };

  // Academia AC3 (auditoría 2026-08-04): antes este mismo método comparaba las respuestas AQUÍ
  // y, si le salía que el alumno aprobaba, posteaba `{status:'completed', score:100}`. Las
  // respuestas correctas venían en `quiz_data`, o sea que estaban a la vista en el navegador
  // antes de contestar, y el backend no tenía con qué recalificar. Ahora se mandan las
  // respuestas y el servidor dice si aprobó y con cuánto; el frontend solo obedece.
  const submitQuiz = () => {
    const questions = activeCourse.quiz_data || [];

    for (let i = 0; i < questions.length; i++) {
      if (selectedAnswers[i] === undefined) {
        alert("Por favor responde todas las preguntas antes de enviar.");
        return;
      }
    }

    // Sin evaluación, el curso se completa con solo verlo, como siempre.
    const veredicto: Promise<{ passed: boolean; score: number; failed_attempts?: number }> =
      questions.length === 0
        ? axiosInstance
            .post(`/academy/courses/${activeCourse.id}/progress`, { status: 'completed' })
            .then(() => ({ passed: true, score: 100 }))
        : axiosInstance
            .post(`/academy/courses/${activeCourse.id}/quiz-attempt`, {
              answers: questions.map((_q: any, i: number) => selectedAnswers[i])
            })
            .then(res => res.data);

    veredicto
      .then(resultado => {
        if (!resultado.passed) {
          // El conteo de intentos ahora lo lleva el servidor: reabrir el curso ya no lo borra.
          const intentos = resultado.failed_attempts ?? failedAttempts + 1;
          setFailedAttempts(intentos);
          setShowQuiz(false);
          setVideoFinished(false);
          alert(
            intentos >= 2
              ? `Volviste a reprobar (${intentos} intentos, ${resultado.score}% en el último). Repasa el video con calma antes de intentarlo de nuevo; tu administrador puede ver tus intentos.`
              : `Respuesta incorrecta (${resultado.score}%). Vuelve a ver el video y préstale más atención antes de reintentar.`
          );
          return null;
        }

        alert(`¡Felicidades! Aprobaste el examen con ${resultado.score}%. Nivel Completado.`);
        setShowQuiz(false);
        setActiveCourse(null);
        return axiosInstance.get('/academy/courses');
      })
      .then(res => {
        if (!res) return;

        const data = res.data;
        if (data && data.courses) {
          setCoursesSafe(data.courses);
          setUserProgress(data.user_progress || []);
        }
        if (activeCourse.course_type === 'induction' && loggedUser) {
          completeInduction(loggedUser.id);
          alert('Recursos Humanos ha sido notificado. Tu BLOQUEO OPERATIVO ha sido levantado. Ya puedes registrar tu entrada en el Reloj Checador.');
        }
        // Auditoría reloj checador (2026-07-22), Hallazgo 1: ya no se detecta el curso de puntualidad
        // por coincidencia de texto en el título (frágil) sino por el id real configurado por el
        // tenant (systemSettings.punctuality_course_id, §12 de docs/BACKEND_INTERFACES.md). El estatus
        // se refresca desde el backend (course_completed pasa a true ahí), no tocando localStorage.
        if (loggedUser && Number(activeCourse.id) === Number(systemSettings?.punctuality_course_id)) {
          useAppStore.getState().fetchPunctualityStatus();
          alert('¡Excelente! Tu checador ha sido desbloqueado tras completar tu capacitación de Puntualidad.');
        }
      })
      .catch(err => {
        console.error("Error saving progress:", err);
        alert(err?.response?.data?.message || "Error al guardar tu progreso en el servidor.");
      });
  };

  if (loading) {
    return (
      <div className="bg-white h-full flex items-center justify-center text-slate-800">
        <div className="flex flex-col items-center animate-pulse">
          <GraduationCap size={48} className="text-slate-300 mb-4 animate-bounce" />
          <p className="font-semibold text-slate-500">Cargando Plan de Carrera...</p>
        </div>
      </div>
    );
  }

  // Reproductor de Curso
  if (activeCourse) {
    const ytId = extractYouTubeId(activeCourse.video_url);

    return (
      <div className="bg-slate-50 text-slate-800 h-full flex flex-col relative overflow-hidden overflow-y-auto scrollbar-none">
        <div className="sticky top-0 z-20 bg-white/90 backdrop-blur-md p-4 border-b border-slate-200 flex items-center justify-between shadow-sm">
          <button onClick={() => setActiveCourse(null)} className="flex items-center text-indigo-600 font-bold hover:text-indigo-800 transition-colors">
            <span className="text-xl mr-2">←</span> Volver al Plan
          </button>
          <div className="flex gap-2">
            {/* AC3: el conteo es el del servidor, así que ya no se puede pasar de 2 "vidas"
                cerrando el curso. Se muestra el número real de intentos fallidos. */}
            {failedAttempts > 0 && <span className="text-[10px] font-bold px-2 py-1 bg-rose-100 text-rose-700 rounded-md border border-rose-200">{failedAttempts === 1 ? '1 intento fallido' : `${failedAttempts} intentos fallidos`}</span>}
            <span className="text-[10px] font-bold px-2 py-1 bg-indigo-50 text-indigo-700 rounded-md border border-indigo-200 uppercase tracking-widest">EN CURSO</span>
          </div>
        </div>

        <div className="p-4 md:p-6 flex-1 pb-20 max-w-3xl mx-auto w-full animate-fade-in">
          <div className="w-full rounded-2xl overflow-hidden shadow-lg border border-slate-200 bg-black mb-6 flex justify-center items-center aspect-video relative">
            {!ytId ? (
              <p className="text-slate-400 text-sm font-medium">Este módulo no tiene video configurado.</p>
            ) : (
              <YouTube 
                videoId={ytId} 
                opts={{ width: '100%', height: '100%', playerVars: { autoplay: 1, controls: 1, disablekb: 1, rel: 0 } }} 
                onEnd={onVideoEnd}
                className="absolute inset-0 w-full h-full"
              />
            )}
          </div>

          <h2 className="text-2xl font-black text-slate-900 mb-2 leading-tight tracking-tight">
            {activeCourse.title}
          </h2>
          
          <div className="bg-white border border-slate-200 rounded-2xl p-5 mb-8 shadow-sm">
            <h4 className="text-xs font-black text-indigo-600 mb-2 uppercase tracking-widest flex items-center gap-2">
              <Briefcase size={14} />
              Objetivo del Módulo
            </h4>
            <p className="text-slate-600 text-sm leading-relaxed font-medium">{activeCourse.description}</p>
          </div>

          <button 
            disabled={!videoFinished && ytId !== null}
            onClick={() => setShowQuiz(true)}
            className={`w-full py-4 rounded-2xl font-black shadow-md transition-all flex items-center justify-center gap-2 tracking-wide uppercase text-sm
              ${videoFinished || !ytId 
                ? 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-indigo-600/20 active:scale-[0.98]' 
                : 'bg-slate-200 text-slate-400 cursor-not-allowed border border-slate-300 shadow-none'
              }`}
          >
            {videoFinished || !ytId ? <span>📝 Presentar Evaluación</span> : <span>🔒 Completar video para evaluar</span>}
          </button>
        </div>

        {/* Modal de Quiz */}
        {showQuiz && (
          <div className="absolute inset-0 bg-slate-50 z-50 flex flex-col overflow-y-auto" role="dialog" aria-modal="true" aria-label="Evaluación Corporativa">
            <div className="sticky top-0 bg-white/90 backdrop-blur-md p-4 border-b border-slate-200 flex justify-between items-center z-10 shadow-sm">
              <h3 className="font-black text-lg text-slate-800 tracking-tight">Evaluación Corporativa</h3>
              <button onClick={() => setShowQuiz(false)} className="text-slate-400 hover:text-slate-800 bg-slate-100 w-8 h-8 rounded-full flex items-center justify-center transition-colors">✕</button>
            </div>
            
            <div className="p-4 md:p-6 pb-24 max-w-3xl mx-auto w-full">
              {activeCourse.quiz_data && activeCourse.quiz_data.length > 0 ? (
                activeCourse.quiz_data.map((q: any, i: number) => (
                  <div key={i} className="mb-6 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                    <p className="font-bold mb-5 text-slate-800 leading-snug">{i + 1}. {q.question}</p>
                    <div className="space-y-3">
                      {q.options.map((opt: string, j: number) => (
                        <label key={j} className="flex items-center space-x-3 p-4 rounded-xl bg-slate-50 hover:bg-indigo-50 cursor-pointer border border-slate-200 hover:border-indigo-300 transition-colors group">
                          <input 
                            type="radio" 
                            name={`q_${i}`} 
                            value={j} 
                            checked={selectedAnswers[i] === j}
                            onChange={() => setSelectedAnswers(prev => ({ ...prev, [i]: j }))}
                            className="form-radio text-indigo-600 bg-white border-slate-300 focus:ring-indigo-500 h-5 w-5" 
                          />
                          <span className="text-sm font-semibold text-slate-700 group-hover:text-indigo-900">{opt}</span>
                        </label>
                      ))}
                    </div>
                  </div>
                ))
              ) : (
                <div className="text-center py-12 bg-white rounded-2xl border border-slate-200">
                  <p className="text-slate-500 font-medium">Este módulo no requiere evaluación formal.</p>
                </div>
              )}
              
              <button 
                onClick={submitQuiz}
                className="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-4 rounded-2xl font-black uppercase tracking-wider text-sm shadow-lg shadow-indigo-600/20 active:scale-[0.98] transition-all"
              >
                Enviar Respuestas
              </button>
            </div>
          </div>
        )}
      </div>
    );
  }

  const filteredCourses = courses.filter(c => !c.target_job_role_id || c.target_job_role_id === targetRoleId);

  // Math config for Duolingo snake line
  const H = 140; // Vertical gap between nodes
  let pathD = '';
  let pathDCompleted = '';

  // Determine indices for completion tracking
  let lastCompletedIndex = -1;
  filteredCourses.forEach((course, index) => {
    const prog = userProgress.find((p: any) => p.course_id === course.id);
    if (prog?.status === 'completed') {
      lastCompletedIndex = index;
    }
  });

  const activeIndex = lastCompletedIndex + 1;

  filteredCourses.forEach((course, index) => {
    const cx = 150 + Math.sin(index * 1.25) * 60;
    const cy = 60 + index * H;
    if (index === 0) {
      pathD += `M ${cx} ${cy}`;
    } else {
      const prevX = 150 + Math.sin((index - 1) * 1.25) * 60;
      const prevY = 60 + (index - 1) * H;
      const cp1x = prevX;
      const cp1y = prevY + H / 2;
      const cp2x = cx;
      const cp2y = cy - H / 2;
      pathD += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${cx} ${cy}`;
    }
  });

  const completedLimit = Math.min(activeIndex, filteredCourses.length - 1);
  filteredCourses.forEach((course, index) => {
    if (index > completedLimit) return;
    const cx = 150 + Math.sin(index * 1.25) * 60;
    const cy = 60 + index * H;
    if (index === 0) {
      pathDCompleted += `M ${cx} ${cy}`;
    } else {
      const prevX = 150 + Math.sin((index - 1) * 1.25) * 60;
      const prevY = 60 + (index - 1) * H;
      const cp1x = prevX;
      const cp1y = prevY + H / 2;
      const cp2x = cx;
      const cp2y = cy - H / 2;
      pathDCompleted += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${cx} ${cy}`;
    }
  });

  return (
    <div className="bg-slate-50 h-full flex flex-col text-slate-800 relative overflow-hidden select-none">
      <style>{`
        @keyframes slideUp {
          from { transform: translateY(100%); }
          to { transform: translateY(0); }
        }
        .animate-slide-up {
          animation: slideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes bounceSlow {
          0%, 100% { transform: translateY(0) scale(1); }
          50% { transform: translateY(-6px) scale(1.03); }
        }
        .animate-bounce-slow {
          animation: bounceSlow 2.2s ease-in-out infinite;
        }
        .custom-shadow-3d {
          box-shadow: 0 8px 16px -4px rgba(99, 102, 241, 0.15);
        }
      `}</style>

      {/* Cabecera con Pestañas (Grid de navegación sincronizado y elevado) */}
      <div className="pt-1.5 px-4 mb-3 shrink-0 select-none">
        <div className="grid grid-cols-2 gap-1.5">
          {/* Botón 1: Carrera */}
          <button
            type="button"
            onClick={() => setActiveTab('plan')}
            className={`flex flex-col items-center justify-center py-2 px-1 rounded-2xl border transition-all cursor-pointer relative ${
              activeTab === 'plan'
                ? 'text-white shadow-md scale-[1.02]'
                : 'bg-white text-slate-500 border-slate-200/85 hover:bg-slate-50'
            }`}
            style={activeTab === 'plan' ? { backgroundColor: activeColor.hex, borderColor: activeColor.hex } : {}}
          >
            <Map size={18} className={activeTab === 'plan' ? 'text-white' : 'text-slate-400'} />
            <span className="text-[9px] font-black uppercase mt-1">Carrera</span>
            <span className={`absolute -top-1 -right-1 text-[8px] font-black px-1.5 py-0.2 rounded-full shadow-xs border ${
              activeTab === 'plan' 
                ? 'bg-white border-white' 
                : 'bg-slate-100 text-slate-600 border-slate-200'
            }`}
              style={activeTab === 'plan' ? { color: activeColor.hex } : {}}
            >
              {pendingCareersCount}
            </span>
          </button>

          {/* Botón 2: Logros */}
          <button
            type="button"
            onClick={() => setActiveTab('logros')}
            className={`flex flex-col items-center justify-center py-2 px-1 rounded-2xl border transition-all cursor-pointer relative ${
              activeTab === 'logros'
                ? 'text-white shadow-md scale-[1.02]'
                : 'bg-white text-slate-500 border-slate-200/85 hover:bg-slate-50'
            }`}
            style={activeTab === 'logros' ? { backgroundColor: activeColor.hex, borderColor: activeColor.hex } : {}}
          >
            <Trophy size={18} className={activeTab === 'logros' ? 'text-white' : 'text-slate-400'} />
            <span className="text-[9px] font-black uppercase mt-1">Logros</span>
            <span className={`absolute -top-1 -right-1 text-[8px] font-black px-1.5 py-0.2 rounded-full shadow-xs border ${
              activeTab === 'logros' 
                ? 'bg-white border-white' 
                : 'bg-slate-100 text-slate-600 border-slate-200'
            }`}
              style={activeTab === 'logros' ? { color: activeColor.hex } : {}}
            >
              {completedCareersCount}
            </span>
          </button>
        </div>
      </div>

      <div className="flex-1 overflow-y-auto scrollbar-none px-6 pb-28 pt-1 relative z-10 flex flex-col max-w-md mx-auto w-full">
        {/* TAB 1: PLAN (Elección de Puesto) */}
        {activeTab === 'plan' && !targetRoleId && (
          <div className="animate-fade-in-up">
            <div className="text-center mb-8 mt-4">
              <div className="w-16 h-16 bg-gradient-to-tr from-amber-400 to-yellow-300 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg shadow-amber-400/20">
                <Crown size={32} className="text-amber-950 animate-pulse" />
              </div>
              <h3 className="font-black text-2xl text-slate-900 mb-2 tracking-tight">Elige tu Meta Profesional</h3>
              <p className="text-sm text-slate-500 font-medium">¿A qué puesto deseas ascender? Selecciona tu objetivo para personalizar tu plan de entrenamiento.</p>
            </div>
            
            <div className="grid grid-cols-1 gap-2.5">
              {roles.filter((role: any) => {
                if (role.is_active === false) return false;
                if (loggedUser && role.id === loggedUser.job_role_id) return true;
                const roleCourses = courses.filter(c => c.target_job_role_id === role.id);
                if (roleCourses.length === 0) return false;
                const completedRoleCourses = roleCourses.filter(c => {
                  const p = userProgress.find(up => up.course_id === c.id);
                  return p?.status === 'completed';
                });
                return completedRoleCourses.length < roleCourses.length;
              }).map(role => {
                const IconComponent = getRoleIcon(role.name);
                return (
                  <button 
                    key={role.id}
                    onClick={() => setTargetRoleId(role.id)}
                    className="w-full bg-white py-2.5 px-3.5 rounded-2xl border border-slate-100 shadow-xs hover:border-violet-400 hover:shadow-sm transition-all text-left flex items-center gap-3 group animate-fade-in cursor-pointer"
                  >
                    <div className="w-10 h-10 bg-slate-50 rounded-xl flex items-center justify-center group-hover:bg-violet-50 group-hover:scale-105 transition-all shadow-xs border border-slate-100 shrink-0">
                      {IconComponent && React.cloneElement(IconComponent as React.ReactElement<any>, { className: `w-5.5 h-5.5 ${IconComponent.props.className?.replace('w-6 h-6', '') || ''}` })}
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center justify-between">
                        <h4 className="font-black text-slate-800 text-sm truncate group-hover:text-violet-900 transition-colors">
                          {role.name}
                        </h4>
                        <ChevronRight className="text-slate-400 group-hover:text-violet-600 group-hover:translate-x-0.5 transition-all shrink-0" size={16} />
                      </div>
                      <p className="text-[10.5px] text-slate-500 font-medium truncate mt-0.5">
                        {role.description || "Ruta de certificación requerida"}
                      </p>
                      
                      {(() => {
                        const roleCourses = courses.filter(c => c.target_job_role_id === role.id);
                        const completedRoleCourses = roleCourses.filter(c => {
                          const p = userProgress.find(up => up.course_id === c.id);
                          return p?.status === 'completed';
                        });
                        if (roleCourses.length > 0) {
                          return (
                            <div className="flex items-center gap-2 mt-1.5">
                              <div className="flex-1 bg-slate-100 h-1 rounded-full overflow-hidden">
                                <div 
                                  className="bg-emerald-500 h-full rounded-full transition-all"
                                  style={{ width: `${(completedRoleCourses.length / roleCourses.length) * 100}%` }}
                                ></div>
                              </div>
                              <span className="text-[9px] font-extrabold text-slate-400 shrink-0">
                                {completedRoleCourses.length}/{roleCourses.length} mod.
                              </span>
                            </div>
                          );
                        }
                        return (
                          <span className="text-[9px] font-extrabold text-slate-400 block mt-1.5">
                            Módulos generales únicamente
                          </span>
                        );
                      })()}
                    </div>
                  </button>
                );
              })}
            </div>
          </div>
        )}

        {/* TAB 1: PLAN (Ruta estilo Duolingo) */}
        {activeTab === 'plan' && targetRoleId && (
          <div className="relative animate-fade-in flex flex-col items-center">
            {/* Header info card */}
            {(() => {
              const selectedRole = roles.find(r => r.id === targetRoleId);
              const roleCourses = filteredCourses;
              const completedRoleCourses = roleCourses.filter(c => {
                const p = userProgress.find(up => up.course_id === c.id);
                return p?.status === 'completed';
              });
              const totalCoursesCount = roleCourses.length;
              const completedCoursesCount = completedRoleCourses.length;
              const overallPercent = totalCoursesCount > 0 ? Math.round((completedCoursesCount / totalCoursesCount) * 100) : 0;

              return (
                <div className="w-full bg-white border border-slate-200 rounded-2xl p-4 mb-6 shadow-sm text-slate-800 relative overflow-hidden animate-fade-in text-left">
                  <div className="flex items-center justify-between mb-3.5">
                    <div className="flex items-center gap-2.5">
                      <div className="w-9 h-9 bg-violet-50 text-violet-600 rounded-xl flex items-center justify-center shadow-xs border border-violet-100/50">
                        {selectedRole ? React.cloneElement(getRoleIcon(selectedRole.name) as React.ReactElement<any>, { className: 'w-5 h-5' }) : <Briefcase size={16} />}
                      </div>
                      <div>
                        <h4 className="font-black text-[13.5px] leading-tight text-slate-900">{selectedRole?.name || 'Ruta de Carrera'}</h4>
                        <p className="text-[9px] text-slate-400 font-extrabold uppercase tracking-wider">Meta Profesional</p>
                      </div>
                    </div>

                    <button 
                      onClick={() => {
                        setTargetRoleId(null);
                        setSelectedCourseForDrawer(null);
                      }}
                      className="text-[9.5px] font-black uppercase tracking-wider bg-slate-50 hover:bg-slate-100 text-slate-500 hover:text-slate-800 px-2.5 py-1.5 rounded-xl border border-slate-200 transition-all cursor-pointer border-none bg-transparent"
                    >
                      Cambiar
                    </button>
                  </div>

                  <div className="mt-3 bg-slate-50/50 p-2.5 rounded-xl border border-slate-100/50">
                    <div className="flex justify-between items-center text-[10px] font-extrabold text-slate-500 mb-1">
                      <span>Progreso de Certificación</span>
                      <span className="text-emerald-600">{overallPercent}% ({completedCoursesCount}/{totalCoursesCount})</span>
                    </div>
                    <div className="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                      <div 
                        className="bg-gradient-to-r from-emerald-400 to-teal-400 h-full rounded-full transition-all duration-500 ease-out"
                        style={{ width: `${overallPercent}%` }}
                      ></div>
                    </div>
                  </div>
                </div>
              );
            })()}

            {/* Winding Duolingo Path Area */}
            {filteredCourses.length > 0 ? (
              <div 
                className="relative w-[300px] mb-8 select-none"
                style={{ height: `${(filteredCourses.length - 1) * H + 120}px` }}
              >
                {/* SVG connection lines */}
                <svg 
                  className="absolute top-0 left-0 w-full pointer-events-none" 
                  style={{ height: `${(filteredCourses.length - 1) * H + 120}px` }} 
                  viewBox={`0 0 300 ${(filteredCourses.length - 1) * H + 120}`}
                >
                  {/* Underlay / Inactive line */}
                  <path 
                    d={pathD} 
                    fill="none" 
                    stroke="#E2E8F0" 
                    strokeWidth="12" 
                    strokeLinecap="round" 
                    strokeLinejoin="round" 
                  />
                  {/* Progress Line */}
                  <path 
                    d={pathDCompleted} 
                    fill="none" 
                    stroke="#6366F1" 
                    strokeWidth="8" 
                    strokeLinecap="round" 
                    strokeLinejoin="round" 
                  />
                </svg>

                {/* Path Nodes */}
                {filteredCourses.map((course, index) => {
                  const prog = userProgress.find((p: any) => p.course_id === course.id);
                  const isCompleted = prog?.status === 'completed';

                  let isBlocked = false;
                  if (course.prerequisite_course_id) {
                    const prereqProgress = userProgress.find((p: any) => p.course_id === course.prerequisite_course_id);
                    isBlocked = prereqProgress?.status !== 'completed';
                  } else if (index > 0) {
                    const prevCourse = filteredCourses[index - 1];
                    const prevProgress = userProgress.find((p: any) => p.course_id === prevCourse.id);
                    isBlocked = prevProgress?.status !== 'completed';
                  }

                  let percentage = 0;
                  if (isCompleted) percentage = 100;
                  else if (prog?.status === 'in_progress') percentage = 50;
                  else if (prog?.status === 'enrolled') percentage = 25;

                  const cx = 150 + Math.sin(index * 1.25) * 60;
                  const cy = 60 + index * H;

                  const isActive = !isBlocked && !isCompleted;

                  let CourseIcon = BookOpen;
                  if (course.course_type === 'induction') CourseIcon = GraduationCap;
                  else if (course.course_type === 'training') CourseIcon = Laptop;
                  else if (course.course_type === 'promotion') CourseIcon = Trophy;

                  return (
                    <div 
                      key={course.id} 
                      className="absolute"
                      style={{ left: `${cx - 48}px`, top: `${cy - 48}px`, width: '96px', height: '96px' }}
                    >
                      {/* Progress Ring around the node */}
                      {!isBlocked && (
                        <ProgressRing 
                          percentage={percentage} 
                          color={isCompleted ? '#10B981' : '#6366F1'} 
                          size={96} 
                          strokeWidth={6} 
                        />
                      )}

                      {/* Main circular button */}
                      <button
                        onClick={() => {
                          if (!isBlocked) {
                            setSelectedCourseForDrawer(course);
                          }
                        }}
                        disabled={isBlocked}
                        className={`absolute inset-2 rounded-full flex items-center justify-center transition-all duration-200 select-none
                          ${isCompleted 
                            ? 'bg-emerald-500 border-b-[6px] border-emerald-700 hover:brightness-105 active:border-b-0 active:translate-y-[6px] text-white shadow-lg shadow-emerald-500/20' 
                            : isBlocked 
                              ? 'bg-slate-200 border-b-[6px] border-slate-400 text-slate-400 cursor-not-allowed shadow-none' 
                              : 'bg-indigo-600 border-b-[6px] border-indigo-800 hover:brightness-105 active:border-b-0 active:translate-y-[6px] text-white shadow-lg shadow-indigo-600/30'
                          }
                          ${isActive ? 'animate-bounce-slow ring-4 ring-indigo-500/30' : ''}
                        `}
                      >
                        {isBlocked ? (
                          <Lock className="w-6 h-6" />
                        ) : isCompleted ? (
                          <Check className="w-8 h-8 stroke-[3]" />
                        ) : (
                          <CourseIcon className="w-7 h-7" />
                        )}
                      </button>

                      {/* Star floating marker for the active node */}
                      {isActive && (
                        <div className="absolute -top-3 left-1/2 -translate-x-1/2 bg-amber-400 text-amber-950 text-[9px] font-black px-2 py-0.5 rounded-full shadow-sm border border-amber-300 uppercase tracking-wider animate-pulse flex items-center gap-0.5 z-20">
                          <Star className="w-2.5 h-2.5 fill-amber-950 text-amber-950" />
                          Siguiente
                        </div>
                      )}

                      {/* Small index badge below the node */}
                      <div className="absolute -bottom-5 left-0 right-0 text-center pointer-events-none">
                        <span className="text-[10px] font-extrabold text-slate-500 bg-slate-100/90 backdrop-blur-xs px-2 py-0.5 rounded-full border border-slate-200">
                          Módulo {index + 1}
                        </span>
                      </div>
                    </div>
                  );
                })}
              </div>
            ) : (
              !loading && (
                <div className="text-center py-12 bg-white rounded-3xl p-6 border border-slate-200 relative z-10 w-full flex flex-col items-center shadow-xs">
                  <Map size={40} className="text-slate-300 mb-4 animate-pulse" />
                  <p className="text-slate-800 font-bold mb-1">Sin módulos aún.</p>
                  <p className="text-slate-500 text-xs font-medium">No hay entrenamientos para esta ruta todavía.</p>
                </div>
              )
            )}
          </div>
        )}

        {/* TAB 2: LOGROS */}
        {activeTab === 'logros' && (
          <div className="animate-fade-in-up space-y-6">
            {/* Carreras Culminadas */}
            <div>
              <h3 className="font-black text-xl text-slate-900 mb-4 tracking-tight">Metas Profesionales Alcanzadas</h3>
              <div className="space-y-3">
                {roles.filter((role: any) => {
                  if (role.is_active === false) return false;
                  const roleCourses = courses.filter(c => c.target_job_role_id === role.id);
                  if (roleCourses.length === 0) return false;
                  const completedRoleCourses = roleCourses.filter(c => {
                    const p = userProgress.find(up => up.course_id === c.id);
                    return p?.status === 'completed';
                  });
                  return completedRoleCourses.length === roleCourses.length;
                }).map((role) => {
                  const IconComponent = getRoleIcon(role.name);
                  return (
                    <div key={role.id} className="bg-gradient-to-r from-violet-500 to-indigo-600 rounded-3xl p-4 text-white shadow-lg relative overflow-hidden flex items-center gap-3.5 animate-fade-in text-left">
                      <div className="absolute top-0 right-0 p-6 opacity-10 text-white pointer-events-none">
                        <Crown size={80} className="rotate-12" />
                      </div>
                      <div className="w-12 h-12 bg-white/20 rounded-2xl flex items-center justify-center backdrop-blur-md shadow-inner shrink-0">
                        {IconComponent && React.cloneElement(IconComponent as React.ReactElement<any>, { className: "w-6 h-6 text-white" })}
                      </div>
                      <div className="relative z-10 flex-1 min-w-0 pr-4">
                        <div className="flex items-center gap-1.5">
                          <span className="text-[8px] font-black tracking-widest bg-amber-400 text-amber-950 px-2 py-0.5 rounded-full uppercase">Puesto Alcanzado</span>
                        </div>
                        <h4 className="text-sm font-black truncate mt-1 leading-tight">{role.name}</h4>
                        <p className="text-[10px] text-white/80 font-bold truncate mt-0.5">{role.description || "Ruta culminada con éxito"}</p>
                      </div>
                    </div>
                  );
                })}

                {roles.filter((role: any) => {
                  if (role.is_active === false) return false;
                  const roleCourses = courses.filter(c => c.target_job_role_id === role.id);
                  if (roleCourses.length === 0) return false;
                  const completedRoleCourses = roleCourses.filter(c => {
                    const p = userProgress.find(up => up.course_id === c.id);
                    return p?.status === 'completed';
                  });
                  return completedRoleCourses.length === roleCourses.length;
                }).length === 0 ? (
                  <div className="bg-slate-100/60 border border-slate-200/50 rounded-2xl p-4 text-center">
                    <p className="text-slate-400 font-bold text-[10.5px]">Ninguna meta profesional completada aún.</p>
                  </div>
                ) : null}
              </div>
            </div>

            {/* Certificados individuales */}
            <div>
              <h3 className="font-black text-xl text-slate-900 mb-4 tracking-tight">Mis Certificados</h3>
              <div className="space-y-3">
                {filteredCourses.filter(course => {
                  const prog = userProgress.find((p: any) => p.course_id === course.id);
                  return prog?.status === 'completed' && course.certificate_template_id;
                }).map((course, idx) => {
                  let template = null;
                  if (systemSettings?.certificate_templates) {
                    try {
                      const parsed = JSON.parse(systemSettings.certificate_templates);
                      template = parsed.find((t: any) => t.id === course.certificate_template_id);
                    } catch { }
                  }
                  
                  return (
                    <div key={idx} className="bg-white border border-slate-200/80 rounded-2xl p-3.5 shadow-xs relative overflow-hidden flex items-center justify-between animate-fade-in text-left">
                      <div className="absolute top-0 right-0 p-6 opacity-5 text-slate-900 pointer-events-none">
                        <Trophy size={60} />
                      </div>
                      <div className="relative z-10 flex-1 min-w-0 pr-4">
                        <h4 className="text-xs font-black text-slate-800 mb-0.5 leading-tight truncate">{course.title}</h4>
                        <p className="text-[9.5px] text-emerald-600 font-extrabold uppercase tracking-wider">Completado con Excelencia ✓</p>
                      </div>
                      <button 
                        onClick={() => {
                          setSelectedPrintTemplate({ course, template });
                          setTimeout(() => {
                            window.print();
                          }, 100);
                        }}
                        className="relative z-10 bg-violet-50 hover:bg-violet-100 text-violet-700 px-3 py-2 rounded-xl border border-violet-100 font-black text-[11px] transition-all shadow-xs flex items-center gap-1.5 cursor-pointer shrink-0"
                      >
                        <span>🖨️</span> Imprimir
                      </button>
                    </div>
                  );
                })}
                
                {filteredCourses.filter(course => {
                  const prog = userProgress.find((p: any) => p.course_id === course.id);
                  return prog?.status === 'completed' && course.certificate_template_id;
                }).length === 0 ? (
                  <div className="bg-slate-100/60 rounded-2xl p-5 text-center border border-slate-200/50 shadow-inner">
                    <Trophy size={36} className="text-slate-300 mx-auto mb-2" />
                    <p className="text-slate-400 font-bold text-[10.5px]">Aún no tienes certificados disponibles.</p>
                  </div>
                ) : null}
              </div>
            </div>

            {/* Insignias Obtenidas */}
            <div>
              <h4 className="font-black text-slate-800 mb-3.5 tracking-tight flex items-center justify-between">
                <span>Insignias de Desempeño</span>
                <span className="text-[10px] text-violet-600 bg-violet-50 px-2.5 py-0.5 rounded-full font-black">
                  {(() => {
                    const is1Done = courses.some(c => c.title.toLowerCase().includes('atención') && userProgress.some(up => up.course_id === c.id && up.status === 'completed'));
                    const is2Done = courses.some(c => c.title.toLowerCase().includes('seguridad') && userProgress.some(up => up.course_id === c.id && up.status === 'completed'));
                    const isAllInductionsDone = courses.filter(c => c.course_type === 'induction').length > 0 && courses.filter(c => c.course_type === 'induction').every(c => userProgress.some(up => up.course_id === c.id && up.status === 'completed'));
                    
                    return (is1Done ? 1 : 0) + (is2Done ? 1 : 0) + (isAllInductionsDone ? 1 : 0);
                  })()} / 3 obtenidas
                </span>
              </h4>
              
              {/* Carrusel Horizontal de Insignias */}
              <div className="flex overflow-x-auto gap-3.5 pb-4 pt-1 px-1 scrollbar-none snap-x snap-mandatory">
                {(() => {
                  const is1Done = courses.some(c => c.title.toLowerCase().includes('atención') && userProgress.some(up => up.course_id === c.id && up.status === 'completed'));
                  const is2Done = courses.some(c => c.title.toLowerCase().includes('seguridad') && userProgress.some(up => up.course_id === c.id && up.status === 'completed'));
                  const isAllInductionsDone = courses.filter(c => c.course_type === 'induction').length > 0 && courses.filter(c => c.course_type === 'induction').every(c => userProgress.some(up => up.course_id === c.id && up.status === 'completed'));

                  return (
                    <>
                      {/* Insignia 1: Atención de Excelencia */}
                      <div className={`snap-center flex flex-col items-center justify-center p-3 rounded-2xl border transition-all duration-300 w-28 shrink-0 relative bg-white shadow-xs ${
                        is1Done 
                          ? 'border-amber-200 shadow-sm scale-100 hover:scale-105' 
                          : 'border-slate-200/60 opacity-40 grayscale'
                      }`}>
                        <div className={`w-14 h-14 rounded-full flex items-center justify-center mb-2 shadow-inner ${
                          is1Done ? 'bg-amber-50 text-amber-500' : 'bg-slate-100 text-slate-400'
                        }`}>
                          <Star size={26} className={is1Done ? 'animate-pulse' : ''} />
                        </div>
                        <span className="text-[10px] font-black text-slate-800 leading-tight">Atención Excelencia</span>
                        <span className="text-[8px] text-slate-400 font-bold mt-1 text-center leading-none">Curso Atención</span>
                        {!is1Done && <Lock size={12} className="absolute top-2 right-2 text-slate-400" />}
                      </div>

                      {/* Insignia 2: Guardián de Seguridad */}
                      <div className={`snap-center flex flex-col items-center justify-center p-3 rounded-2xl border transition-all duration-300 w-28 shrink-0 relative bg-white shadow-xs ${
                        is2Done 
                          ? 'border-blue-200 shadow-sm scale-100 hover:scale-105' 
                          : 'border-slate-200/60 opacity-40 grayscale'
                      }`}>
                        <div className={`w-14 h-14 rounded-full flex items-center justify-center mb-2 shadow-inner ${
                          is2Done ? 'bg-blue-50 text-blue-600' : 'bg-slate-100 text-slate-400'
                        }`}>
                          <ShieldCheck size={26} />
                        </div>
                        <span className="text-[10px] font-black text-slate-800 leading-tight">Guardián de Seguridad</span>
                        <span className="text-[8px] text-slate-400 font-bold mt-1 text-center leading-none">Curso Seguridad</span>
                        {!is2Done && <Lock size={12} className="absolute top-2 right-2 text-slate-400" />}
                      </div>

                      {/* Insignia 3: Líder Multitareas */}
                      <div className={`snap-center flex flex-col items-center justify-center p-3 rounded-2xl border transition-all duration-300 w-28 shrink-0 relative bg-white shadow-xs ${
                        isAllInductionsDone 
                          ? 'border-violet-200 shadow-sm scale-100 hover:scale-105' 
                          : 'border-slate-200/60 opacity-40 grayscale'
                      }`}>
                        <div className={`w-14 h-14 rounded-full flex items-center justify-center mb-2 shadow-inner ${
                          isAllInductionsDone ? 'bg-violet-50 text-violet-600' : 'bg-slate-100 text-slate-400'
                        }`}>
                          <Crown size={26} />
                        </div>
                        <span className="text-[10px] font-black text-slate-800 leading-tight">Líder Multitareas</span>
                        <span className="text-[8px] text-slate-400 font-bold mt-1 text-center leading-none">Inducción Completa</span>
                        {!isAllInductionsDone && <Lock size={12} className="absolute top-2 right-2 text-slate-400" />}
                      </div>
                    </>
                  );
                })()}
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Backdrop for Course Details Drawer */}
      {selectedCourseForDrawer && (
        <div 
          className="absolute inset-0 bg-slate-900/40 backdrop-blur-xs z-30 transition-all duration-300 animate-fade-in"
          onClick={() => setSelectedCourseForDrawer(null)}
        />
      )}

      {/* Course Details Bottom Drawer */}
      {selectedCourseForDrawer && (() => {
        const course = selectedCourseForDrawer;
        const prog = userProgress.find((p: any) => p.course_id === course.id);
        const isCompleted = prog?.status === 'completed';
        
        let progressText = 'Pendiente de iniciar';
        let progressColor = 'text-slate-400';
        let percentage = 0;
        if (isCompleted) {
          progressText = '¡Completado!';
          progressColor = 'text-emerald-500';
          percentage = 100;
        } else if (prog?.status === 'in_progress') {
          progressText = 'En curso';
          progressColor = 'text-indigo-500';
          percentage = 50;
        } else if (prog?.status === 'enrolled') {
          progressText = 'Inscrito';
          progressColor = 'text-blue-500';
          percentage = 25;
        }

        let courseTypeLabel = 'Entrenamiento';
        let courseTypeColor = 'bg-blue-50 text-blue-700 border-blue-200';
        if (course.course_type === 'induction') {
          courseTypeLabel = 'Inducción Obligatoria';
          courseTypeColor = 'bg-indigo-50 text-indigo-700 border-indigo-200';
        } else if (course.course_type === 'promotion') {
          courseTypeLabel = 'Ascenso a Puesto';
          courseTypeColor = 'bg-amber-50 text-amber-700 border-amber-200';
        }

        return (
          <div className="absolute bottom-0 left-0 right-0 bg-white rounded-t-[32px] border-t border-slate-200 shadow-2xl p-6 z-40 transition-all duration-300 transform translate-y-0 animate-slide-up select-none">
            <div className="w-12 h-1.5 bg-slate-200 rounded-full mx-auto mb-4 cursor-pointer" onClick={() => setSelectedCourseForDrawer(null)}></div>
            
            <div className="flex justify-between items-start mb-4">
              <div>
                <span className={`inline-block px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase border ${courseTypeColor} tracking-wider`}>
                  {courseTypeLabel}
                </span>
                <h3 className="text-lg font-black text-slate-800 mt-2 leading-tight">
                  {course.title}
                </h3>
              </div>
              <button 
                onClick={() => setSelectedCourseForDrawer(null)}
                className="bg-slate-100 hover:bg-slate-200 text-slate-400 hover:text-slate-600 w-8 h-8 rounded-full flex items-center justify-center transition-colors"
              >
                <X size={16} />
              </button>
            </div>

            <div className="space-y-4 mb-6">
              <div>
                <h4 className="text-[10px] font-extrabold uppercase tracking-widest text-slate-400 mb-1">Objetivo de este tema</h4>
                <p className="text-slate-600 text-sm leading-relaxed font-medium">
                  {course.description || 'Sin descripción disponible para este módulo de entrenamiento.'}
                </p>
              </div>

              {course.incentive_bonus_cents > 0 && (
                <div className="bg-emerald-50 border border-emerald-100 rounded-2xl p-3.5 flex items-center gap-3">
                  <span className="text-2xl">🎁</span>
                  <div>
                    <p className="text-xs font-black text-emerald-800 uppercase tracking-wider">¡Premio por Certificación!</p>
                    <p className="text-sm font-extrabold text-emerald-600">
                      Bono de incentivo de ${(course.incentive_bonus_cents / 100).toFixed(2)} MXN al completarlo.
                    </p>
                  </div>
                </div>
              )}

              <div>
                <div className="flex justify-between items-center text-xs font-bold mb-1.5">
                  <span className="text-slate-400 font-bold">Progreso en este módulo</span>
                  <span className={`${progressColor} font-black uppercase tracking-wider text-[10px]`}>{progressText}</span>
                </div>
                <div className="w-full bg-slate-100 h-2.5 rounded-full overflow-hidden">
                  <div 
                    className={`h-full rounded-full transition-all duration-500 ${isCompleted ? 'bg-emerald-500' : 'bg-indigo-600'}`}
                    style={{ width: `${percentage}%` }}
                  ></div>
                </div>
              </div>
            </div>

            <button
              onClick={() => {
                handleStartCourse(course);
                setSelectedCourseForDrawer(null);
              }}
              className={`w-full py-4 rounded-2xl font-black text-sm uppercase tracking-wider transition-all duration-200 active:scale-[0.98] shadow-md
                ${isCompleted 
                  ? 'bg-emerald-500 hover:bg-emerald-600 text-white shadow-emerald-500/20' 
                  : 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-indigo-600/20'
                }
              `}
            >
              {isCompleted ? 'Repasar Módulo' : 'Comenzar a Estudiar'}
            </button>
          </div>
        );
      })()}

      {/* Certificado Oculto para Impresión */}
      <div className="fixed inset-0 pointer-events-none opacity-0 print:opacity-100 print:z-[9999] bg-white flex items-center justify-center">
        <CertificadoImprimible 
           participantName={loggedUser?.name || 'Empleado de Prueba'}
           courseName={selectedPrintTemplate?.course?.title || 'Programa Integral de Capacitación'}
           startDate="01"
           endDate="15"
           month="Agosto"
           year="2026"
           template={selectedPrintTemplate?.template || null}
        />
      </div>

      {/* Modal de Bienvenida motivacional */}
      {showWelcomeModal && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[110] flex items-center justify-center p-4">
          <div 
            className="absolute inset-0" 
            onClick={() => setShowWelcomeModal(false)}
          />
          <div 
            className="bg-white rounded-3xl p-6 shadow-2xl relative z-10 w-full max-w-sm text-center border-3 animate-fade-in-up"
            style={{ borderColor: activeColor.hex }}
          >
            {/* Botón Cerrar */}
            <button 
              onClick={() => setShowWelcomeModal(false)}
              className="absolute top-4 right-4 p-1 hover:bg-slate-100 rounded-full text-slate-400 border-none cursor-pointer transition-colors"
            >
              <X size={18} />
            </button>

            {/* Icono animado */}
            <div 
              className="w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4 animate-bounce-slow shadow-lg shadow-violet-100"
              style={{ backgroundColor: `${activeColor.hex}15`, color: activeColor.hex }}
            >
              <GraduationCap size={44} />
            </div>

            {/* Título y texto */}
            <h3 className="font-black text-xl text-slate-900 mb-1.5 tracking-tight">Elige tu Meta Profesional</h3>
            <p className="text-[11.5px] text-slate-500 font-bold mb-4">¿A qué puesto deseas ascender? Selecciona tu objetivo para personalizar tu plan de entrenamiento.</p>
            
            {/* Mensaje motivacional */}
            <div 
              className="p-3.5 rounded-2xl border mb-4 text-[11px] font-medium leading-relaxed"
              style={{ backgroundColor: `${activeColor.hex}08`, borderColor: `${activeColor.hex}25`, color: activeColor.hex }}
            >
              🚀 <strong>¡Hola, {loggedUser?.name?.split(' ')[0]}!</strong> En DecorArte, cada paso de aprendizaje te acerca al puesto de tus sueños. ¡Sigue adelante, certifícate hoy y alcanza tu máximo potencial!
            </div>

            {/* Checkbox No volver a mostrar */}
            <div className="flex items-center justify-center gap-2 mb-4 select-none">
              <input 
                type="checkbox" 
                id="dontShowAgain" 
                onChange={(e) => {
                  if (e.target.checked) {
                    localStorage.setItem('decorarte_academy_welcome_dismissed', 'true');
                  } else {
                    localStorage.removeItem('decorarte_academy_welcome_dismissed');
                  }
                }}
                className="w-3.5 h-3.5 text-violet-600 border-slate-300 rounded focus:ring-violet-500 cursor-pointer"
              />
              <label htmlFor="dontShowAgain" className="text-[10px] text-slate-500 font-bold cursor-pointer">
                No volver a mostrar este mensaje
              </label>
            </div>

            <button 
              onClick={() => setShowWelcomeModal(false)}
              className="w-full py-3 text-white rounded-xl text-xs font-black uppercase tracking-wider shadow-sm transition-all border-none cursor-pointer hover:opacity-90 active:scale-95"
              style={{ backgroundColor: activeColor.hex }}
            >
              Comenzar a Aprender
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

export default function Academia(props: { onBack: () => void; autoOpenCourseId?: number | null }) {
  return (
    <AcademiaErrorBoundary>
      <AcademiaContent {...props} />
    </AcademiaErrorBoundary>
  );
}
