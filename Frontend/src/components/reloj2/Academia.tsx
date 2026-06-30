import React, { useState, useEffect } from 'react';
import { Lock, Star, Briefcase, Crown, Trophy, Map, GraduationCap, ShieldCheck } from 'lucide-react';
import { useAppStore } from '../../store/useAppStore';
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

function AcademiaContent({ onBack }: { onBack: () => void }) {
  const [courses, setCourses] = useState<any[]>([]);
  const [roles, setRoles] = useState<any[]>([]);
  const [userProgress, setUserProgress] = useState<any[]>([]);
  const [selectedAnswers, setSelectedAnswers] = useState<Record<number, number>>({});
  const [targetRoleId, setTargetRoleId] = useState<number | null>(null);
  const [activeTab, setActiveTab] = useState<'plan' | 'logros'>('plan');
  const [loading, setLoading] = useState(true);
  const [activeCourse, setActiveCourse] = useState<any>(null);
  const [showQuiz, setShowQuiz] = useState(false);
  const [videoFinished, setVideoFinished] = useState(false);
  const [failedAttempts, setFailedAttempts] = useState(0);
  const [selectedPrintTemplate, setSelectedPrintTemplate] = useState<any>(null);

  const { currentUser: loggedUser, systemSettings, completeInduction } = useAppStore();

  useEffect(() => {
    axiosInstance.get('/academy/courses')
      .then(res => {
        const data = res.data;
        if (data && data.courses && Array.isArray(data.courses)) {
          setCourses(data.courses);
          setRoles(Array.isArray(data.job_roles) ? data.job_roles : []);
          setUserProgress(Array.isArray(data.user_progress) ? data.user_progress : []);
        } else if (Array.isArray(data)) {
          setCourses(data); // Legacy fallback
        } else {
          console.error("API Error or Invalid format:", data);
          setCourses([]);
          setRoles([]);
          setUserProgress([]);
        }
        setLoading(false);
      })
      .catch(err => {
        console.error('Error fetching courses', err);
        setCourses([]);
        setRoles([]);
        setUserProgress([]);
        setLoading(false);
      });
  }, []);

  const handleStartCourse = (course: any) => {
    setActiveCourse(course);
    setShowQuiz(false);
    setVideoFinished(false);
    setFailedAttempts(0);
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

  const submitQuiz = () => {
    const questions = activeCourse.quiz_data || [];
    let passed = true;

    if (questions.length > 0) {
      for (let i = 0; i < questions.length; i++) {
        if (selectedAnswers[i] === undefined) {
          alert("Por favor responde todas las preguntas antes de enviar.");
          return;
        }
      }

      let correctCount = 0;
      questions.forEach((q: any, i: number) => {
        const selected = selectedAnswers[i];
        let correctOptionIndex = q.correctAnswer;
        if (correctOptionIndex === undefined && q.answer !== undefined) {
          if (typeof q.answer === 'string') {
            correctOptionIndex = q.options.indexOf(q.answer);
          } else if (typeof q.answer === 'number') {
            correctOptionIndex = q.answer;
          }
        }
        if (selected === correctOptionIndex) {
          correctCount++;
        }
      });
      passed = correctCount === questions.length;
    }

    if (passed) {
      axiosInstance.post(`/academy/courses/${activeCourse.id}/progress`, {
        status: 'completed',
        score: 100
      })
      .then(() => {
        alert('¡Felicidades! Aprobaste el examen con 100%. Nivel Completado.');
        setShowQuiz(false);
        setActiveCourse(null);
        return axiosInstance.get('/academy/courses');
      })
      .then(res => {
        const data = res.data;
        if (data && data.courses) {
          setCourses(data.courses);
          setUserProgress(data.user_progress || []);
        }
        if (activeCourse.course_type === 'induction' && loggedUser) {
          completeInduction(loggedUser.id);
          alert('Recursos Humanos ha sido notificado. Tu BLOQUEO OPERATIVO ha sido levantado. Ya puedes registrar tu entrada en el Reloj Checador.');
        }
      })
      .catch(err => {
        console.error("Error saving progress:", err);
        alert("Error al guardar tu progreso en el servidor.");
      });
    } else {
      const newAttempts = failedAttempts + 1;
      if (newAttempts >= 2) {
        alert('Has reprobado por segunda vez. Tu curso ha sido bloqueado temporalmente y se ha notificado a tu Administrador.');
        setShowQuiz(false);
        setActiveCourse(null);
      } else {
        alert('Respuesta incorrecta. Tu progreso ha sido reiniciado por seguridad. Debes volver a ver el video y prestar más atención.');
        setShowQuiz(false);
        setVideoFinished(false);
        setFailedAttempts(newAttempts);
      }
    }
  };

  if (loading) {
    return (
      <div className="bg-white h-full flex items-center justify-center text-slate-800">
        <div className="flex flex-col items-center animate-pulse">
          <GraduationCap size={48} className="text-slate-300 mb-4" />
          <p className="font-semibold text-slate-500">Cargando Plan de Carrera...</p>
        </div>
      </div>
    );
  }

  // Reproductor de Curso
  if (activeCourse) {
    const ytId = extractYouTubeId(activeCourse.video_url);

    return (
      <div className="bg-slate-50 text-slate-800 h-full flex flex-col relative overflow-hidden overflow-y-auto custom-scrollbar">
        <div className="sticky top-0 z-20 bg-white/90 backdrop-blur-md p-4 border-b border-slate-200 flex items-center justify-between shadow-sm">
          <button onClick={() => setActiveCourse(null)} className="flex items-center text-indigo-600 font-bold hover:text-indigo-800 transition-colors">
            <span className="text-xl mr-2">←</span> Volver al Plan
          </button>
          <div className="flex gap-2">
            {failedAttempts > 0 && <span className="text-[10px] font-bold px-2 py-1 bg-rose-100 text-rose-700 rounded-md border border-rose-200">Vidas: {2 - failedAttempts}/2</span>}
            <span className="text-[10px] font-bold px-2 py-1 bg-indigo-50 text-indigo-700 rounded-md border border-indigo-200 uppercase tracking-widest">EN CURSO</span>
          </div>
        </div>

        <div className="p-4 md:p-6 flex-1 pb-20 max-w-3xl mx-auto w-full">
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
          <div className="absolute inset-0 bg-slate-50 z-50 flex flex-col overflow-y-auto">
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

  // Línea de tiempo Corporativa (Vertical)
  return (
    <div className="bg-slate-50 h-full flex flex-col text-slate-800 relative overflow-hidden overflow-y-auto custom-scrollbar">
      {/* Cabecera con Pestañas */}
      <div className="sticky top-0 z-20 bg-white/90 backdrop-blur-md border-b border-slate-200 shadow-sm">
        <div className="p-6 pb-4">
          <h2 className="text-xl font-black text-slate-900 tracking-tight">Academia 360</h2>
          <p className="text-indigo-600 text-xs mt-0.5 font-bold uppercase tracking-widest">Plan de Desarrollo y Logros</p>
        </div>
        <div className="flex gap-4 px-6 border-b border-slate-100">
          <button 
            onClick={() => setActiveTab('plan')}
            className={`pb-3 text-sm font-bold border-b-2 transition-colors ${activeTab === 'plan' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-800'}`}
          >
            Ruta de Carrera
          </button>
          <button 
            onClick={() => setActiveTab('logros')}
            className={`pb-3 text-sm font-bold border-b-2 transition-colors ${activeTab === 'logros' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-800'}`}
          >
            Mis Logros
          </button>
        </div>
      </div>

      <div className="p-6 relative z-10 flex flex-col max-w-md mx-auto w-full pb-32">
        {activeTab === 'plan' && !targetRoleId && (
          <div className="animate-fade-in-up">
            <div className="text-center mb-8 mt-4">
              <Crown size={48} className="text-amber-400 mx-auto mb-4" />
              <h3 className="font-black text-2xl text-slate-800 mb-2">Elige tu Meta Profesional</h3>
              <p className="text-sm text-slate-500">¿A qué puesto deseas ascender? Selecciona tu objetivo para personalizar tu plan de entrenamiento.</p>
            </div>
            <div className="space-y-4">
              {roles.filter((role: any) => role.is_active !== false).map(role => (
                <button 
                  key={role.id}
                  onClick={() => setTargetRoleId(role.id)}
                  className="w-full bg-white p-5 rounded-2xl border border-slate-200 shadow-sm hover:border-indigo-400 hover:shadow-md transition-all text-left flex items-center justify-between group"
                >
                  <div className="flex items-center gap-4">
                    <div className="w-12 h-12 bg-indigo-50 rounded-full flex items-center justify-center text-indigo-600 group-hover:scale-110 transition-transform">
                      <Briefcase size={24} />
                    </div>
                    <div>
                      <h4 className="font-bold text-slate-800 text-lg">{role.name}</h4>
                      <p className="text-xs text-slate-500 mt-1">Ruta de certificación requerida</p>
                    </div>
                  </div>
                  <span className="text-indigo-400 font-bold text-xl group-hover:translate-x-1 transition-transform">→</span>
                </button>
              ))}
            </div>
          </div>
        )}

        {activeTab === 'plan' && targetRoleId && (
          <div className="animate-fade-in">
            <button 
              onClick={() => setTargetRoleId(null)}
              className="mb-6 text-sm font-bold text-slate-500 hover:text-indigo-600 flex items-center gap-2"
            >
              ← Cambiar Meta Profesional
            </button>

            {/* Línea conectora central (Timeline) */}
            <div className="absolute top-20 bottom-10 left-10 w-[2px] bg-slate-200 z-0"></div>

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
              
              return (
                <div key={course.id} className="w-full flex items-start mb-8 relative z-10 group">
                  
                  {/* Nodo Circular (Indicador) */}
                  <div className="flex-shrink-0 w-8 h-8 mt-1 rounded-full flex items-center justify-center bg-white border-4 border-slate-50 shadow-sm relative z-10 transition-transform">
                    {isCompleted ? (
                       <div className="w-3 h-3 bg-emerald-500 rounded-full shadow-sm"></div>
                    ) : isBlocked ? (
                       <div className="w-3 h-3 bg-slate-300 rounded-full"></div>
                    ) : (
                       <div className={`w-3 h-3 rounded-full shadow-sm ${course.course_type === 'induction' ? 'bg-indigo-500' : course.course_type === 'training' ? 'bg-emerald-500' : 'bg-blue-500'}`}></div>
                    )}
                  </div>

                  {/* Tarjeta del Curso */}
                  <div 
                    className={`ml-6 flex-1 bg-white border rounded-2xl p-4 shadow-sm transition-all cursor-pointer 
                      ${isCompleted ? 'border-emerald-200 bg-emerald-50/20' : isBlocked ? 'border-slate-200 opacity-60' : 'border-slate-200 hover:border-indigo-300 hover:shadow-md'}`}
                    onClick={() => !isBlocked && handleStartCourse(course)}
                  >
                    <div className="flex items-center justify-between mb-2">
                      <span className="text-[10px] font-extrabold uppercase tracking-widest text-slate-400">
                        Módulo {index + 1} {isCompleted && '✓ Completado'}
                      </span>
                      {isBlocked && <Lock size={12} className="text-slate-400" />}
                    </div>
                    
                    <h3 className={`font-bold text-sm leading-tight mb-1 ${isBlocked ? 'text-slate-500' : 'text-slate-800'}`}>
                      {course.title}
                    </h3>
                    
                    {isBlocked && (
                      <span className="inline-block mt-2 text-[9px] text-rose-600 font-bold uppercase tracking-wider bg-rose-50 px-2 py-0.5 rounded-md border border-rose-100">
                        Completa el módulo anterior
                      </span>
                    )}
                  </div>
                </div>
              )
            })}

            {filteredCourses.length === 0 && !loading && (
              <div className="text-center py-12 bg-white rounded-2xl p-6 border border-slate-200 relative z-10 w-full flex flex-col items-center shadow-sm">
                <Map size={40} className="text-slate-300 mb-4" />
                <p className="text-slate-800 font-bold mb-1">Sin módulos aún.</p>
                <p className="text-slate-500 text-xs font-medium">No hay entrenamientos para esta ruta todavía.</p>
              </div>
            )}
          </div>
        )}

        {activeTab === 'logros' && (
          <div className="animate-fade-in-up">
            <h3 className="font-black text-2xl text-slate-800 mb-6">Mis Certificados</h3>
            <div className="space-y-4">
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
                   <div key={idx} className="bg-gradient-to-br from-indigo-900 to-slate-900 rounded-3xl p-6 shadow-xl relative overflow-hidden flex items-center justify-between">
                     <div className="absolute top-0 right-0 p-8 opacity-10">
                       <Trophy size={100} />
                     </div>
                     <div className="relative z-10">
                       <h4 className="text-xl font-black text-white mb-1">{course.title}</h4>
                       <p className="text-indigo-200 text-sm mb-0">Completado con Excelencia</p>
                     </div>
                     <button 
                       onClick={() => {
                         setSelectedPrintTemplate({ course, template });
                         setTimeout(() => {
                           window.print();
                         }, 100);
                       }}
                       className="relative z-10 bg-white/10 hover:bg-white/20 text-white p-4 rounded-xl backdrop-blur-sm border border-white/20 transition-all shadow-lg flex items-center gap-2 font-bold"
                     >
                       <span className="text-xl">🖨️</span> Imprimir
                     </button>
                   </div>
                 );
               })}
               
               {filteredCourses.filter(course => {
                 const prog = userProgress.find((p: any) => p.course_id === course.id);
                 return prog?.status === 'completed' && course.certificate_template_id;
               }).length === 0 ? (
                 <div className="bg-slate-100 rounded-3xl p-8 text-center border border-slate-200">
                   <Trophy size={48} className="text-slate-300 mx-auto mb-4" />
                   <p className="text-slate-500 font-bold">Aún no tienes certificados disponibles.</p>
                   <p className="text-xs text-slate-400 mt-2">Completa cursos con diplomas para verlos aquí.</p>
                 </div>
               ) : null}
            </div>

            <h4 className="font-bold text-slate-800 mt-8 mb-4">Insignias Obtenidas</h4>
            <div className="grid grid-cols-3 gap-4">
               {/* Insignia Mock */}
               <div className="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center justify-center text-center opacity-50 grayscale">
                  <div className="w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mb-2">
                     <Star size={24} />
                  </div>
                  <span className="text-[10px] font-bold text-slate-500">Servicio al Cliente</span>
               </div>
               <div className="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col items-center justify-center text-center opacity-50 grayscale">
                  <div className="w-12 h-12 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center mb-2">
                     <ShieldCheck size={24} />
                  </div>
                  <span className="text-[10px] font-bold text-slate-500">Seguridad</span>
               </div>
            </div>
          </div>
        )}
      </div>

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
    </div>
  );
}

export default function Academia(props: { onBack: () => void }) {

  return (
    <AcademiaErrorBoundary>
      <AcademiaContent {...props} />
    </AcademiaErrorBoundary>
  );
}
