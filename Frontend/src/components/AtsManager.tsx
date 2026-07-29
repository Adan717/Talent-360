import React, { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { RecruitmentBoard } from './RecruitmentBoard';
import GestorVacantes from './GestorVacantes';
import { WebPublica } from './WebPublica';
import AtsPortalSettings from './AtsPortalSettings';
import { Briefcase, ClipboardList, Calendar, Plus, Trash2, Clock, User, MessageSquare, X, Globe } from 'lucide-react';
import axiosInstance from '../lib/axios';

export function AtsManager() {
  const [searchParams, setSearchParams] = useSearchParams();
  const urlTab = searchParams.get('tab');
  const storedTab = localStorage.getItem('talent360_ats_active_tab');
  const [activeTab, setActiveTabState] = useState(urlTab || storedTab || 'vacantes');

  const setActiveTab = (tab: string) => {
    setActiveTabState(tab);
    try {
      localStorage.setItem('talent360_ats_active_tab', tab);
    } catch {}
    setSearchParams(prev => {
      const next = new URLSearchParams(prev);
      next.set('tab', tab);
      return next;
    }, { replace: true });
  };

  useEffect(() => {
    if (urlTab && urlTab !== activeTab) {
      setActiveTabState(urlTab);
      try {
        localStorage.setItem('talent360_ats_active_tab', urlTab);
      } catch {}
    } else if (!urlTab && activeTab) {
      setSearchParams(prev => {
        const next = new URLSearchParams(prev);
        next.set('tab', activeTab);
        return next;
      }, { replace: true });
    }
  }, [urlTab]);

  const [showFabMenu, setShowFabMenu] = useState(false);
  
  // States for Interviews
  const [interviews, setInterviews] = useState<any[]>([]);
  const [candidates, setCandidates] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const [showScheduleForm, setShowScheduleForm] = useState(false);
  
  // Form State
  const [candidateId, setCandidateId] = useState('');
  const [date, setDate] = useState('');
  const [time, setTime] = useState('');
  const [interviewer, setInterviewer] = useState('');
  const [notes, setNotes] = useState('');

  useEffect(() => {
    if (activeTab === 'entrevistas') {
      fetchInterviewsAndCandidates();
    }
  }, [activeTab]);

  const fetchInterviewsAndCandidates = async () => {
    try {
      setLoading(true);
      const [intRes, candRes] = await Promise.all([
        axiosInstance.get('/admin/interviews'),
        axiosInstance.get('/admin/candidates')
      ]);
      setInterviews(intRes.data || []);
      setCandidates(candRes.data || []);
    } catch (e) {
      console.error("Error al cargar agenda:", e);
    } finally {
      setLoading(false);
    }
  };

  const handleScheduleInterview = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!candidateId || !date || !time || !interviewer) {
      alert("Por favor complete todos los campos obligatorios.");
      return;
    }
    try {
      await axiosInstance.post('/admin/interviews', {
        candidate_id: Number(candidateId),
        interview_date: date,
        interview_time: time,
        interviewer_name: interviewer,
        notes: notes
      });
      alert("Entrevista programada exitosamente.");
      setShowScheduleForm(false);
      // Reset form
      setCandidateId('');
      setDate('');
      setTime('');
      setInterviewer('');
      setNotes('');
      // Reload
      fetchInterviewsAndCandidates();
    } catch (err) {
      console.error(err);
      alert("Error al programar la entrevista.");
    }
  };

  const handleCancelInterview = async (id: number) => {
    if (!window.confirm("¿Está seguro de que desea cancelar esta entrevista?")) return;
    try {
      await axiosInstance.delete(`/admin/interviews/${id}`);
      setInterviews(prev => prev.filter(i => i.id !== id));
      alert("Entrevista cancelada.");
    } catch (err) {
      console.error(err);
      alert("Error al cancelar la entrevista.");
    }
  };

  return (
    <div className="max-w-7xl mx-auto space-y-6 animate-fade-in">
      
      {/* HEADER ATS STICKY */}
      <div className="sticky -top-4 sm:-top-8 -mt-4 sm:-mt-8 -mx-4 sm:-mx-8 px-4 sm:px-8 pt-4 sm:pt-6 pb-2 sm:pb-3 bg-slate-50/90 backdrop-blur-md z-20 transition-all border-b border-slate-200/50 mb-4 sm:mb-6">
        <div className="bg-white rounded-3xl p-1.5 sm:p-2 shadow-sm border border-slate-200">
          {/* TABS */}
          <div className="flex items-center gap-1.5 sm:gap-2 bg-slate-50 p-1.5 rounded-3xl sm:rounded-2xl w-full overflow-x-auto whitespace-nowrap scrollbar-none">
            <button 
              onClick={() => setActiveTab('vacantes')}
              className={`flex-shrink-0 flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2 text-[10px] sm:text-sm font-bold p-3 sm:px-6 sm:py-2.5 rounded-2xl sm:rounded-xl min-w-[85px] sm:min-w-0 transition-all ${activeTab === 'vacantes' ? 'bg-white text-indigo-700 shadow-sm border border-slate-150' : 'text-slate-500 hover:text-slate-700 hover:bg-slate-100'}`}
            >
              <Briefcase size={18} className={activeTab === 'vacantes' ? 'text-indigo-600' : 'text-slate-400'} />
              <span className="whitespace-normal sm:whitespace-nowrap text-center leading-tight">Bolsa de Trabajo</span>
            </button>
            <button 
              onClick={() => setActiveTab('kanban')}
              className={`flex-shrink-0 flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2 text-[10px] sm:text-sm font-bold p-3 sm:px-6 sm:py-2.5 rounded-2xl sm:rounded-xl min-w-[85px] sm:min-w-0 transition-all ${activeTab === 'kanban' ? 'bg-white text-indigo-700 shadow-sm border border-slate-150' : 'text-slate-500 hover:text-slate-700 hover:bg-slate-100'}`}
            >
              <ClipboardList size={18} className={activeTab === 'kanban' ? 'text-indigo-600' : 'text-slate-400'} />
              <span className="whitespace-normal sm:whitespace-nowrap text-center leading-tight">Tablero Candidatos</span>
            </button>
            <button 
              onClick={() => setActiveTab('entrevistas')}
              className={`flex-shrink-0 flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2 text-[10px] sm:text-sm font-bold p-3 sm:px-6 sm:py-2.5 rounded-2xl sm:rounded-xl min-w-[85px] sm:min-w-0 transition-all ${activeTab === 'entrevistas' ? 'bg-white text-indigo-700 shadow-sm border border-slate-150' : 'text-slate-500 hover:text-slate-700 hover:bg-slate-100'}`}
            >
              <Calendar size={18} className={activeTab === 'entrevistas' ? 'text-indigo-600' : 'text-slate-400'} />
              <span className="whitespace-normal sm:whitespace-nowrap text-center leading-tight">
                <span className="block sm:hidden">Agenda</span>
                <span className="hidden sm:block">Agenda de Entrevistas</span>
              </span>
            </button>
            <button 
              onClick={() => setActiveTab('publico')}
              className={`flex-shrink-0 flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2 text-[10px] sm:text-sm font-bold p-3 sm:px-6 sm:py-2.5 rounded-2xl sm:rounded-xl min-w-[85px] sm:min-w-0 transition-all ${activeTab === 'publico' ? 'bg-white text-indigo-700 shadow-sm border border-slate-150' : 'text-slate-500 hover:text-slate-700 hover:bg-slate-100'}`}
            >
              <Globe size={18} className={activeTab === 'publico' ? 'text-indigo-600' : 'text-slate-400'} />
              <span className="whitespace-normal sm:whitespace-nowrap text-center leading-tight">
                <span className="block sm:hidden">Web Pública</span>
                <span className="hidden sm:block">Vista Pública (Web)</span>
              </span>
            </button>
          </div>
        </div>
      </div>

      <div className="bg-transparent">
        {activeTab === 'vacantes' && <GestorVacantes />}
        {activeTab === 'kanban' && <div className="bg-white rounded-3xl p-4 sm:p-8 shadow-sm border border-slate-200 min-h-[500px] animate-fade-in"><RecruitmentBoard /></div>}
        {activeTab === 'publico' && <div className="bg-white rounded-3xl p-4 sm:p-8 shadow-sm border border-slate-200 min-h-[500px] animate-fade-in"><AtsPortalSettings /></div>}
        {activeTab === 'entrevistas' && (
           <div className="bg-white rounded-3xl p-4 sm:p-8 shadow-sm border border-slate-200 min-h-[500px] animate-fade-in">
              <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                <div>
                  <h3 className="text-2xl font-bold text-slate-900">Agenda y Control de Entrevistas</h3>
                  <p className="text-slate-500 text-sm">Organiza citas de candidatos e invita a los evaluadores a participar.</p>
                </div>
                <button 
                  onClick={() => setShowScheduleForm(!showScheduleForm)} 
                  className="w-full sm:w-auto justify-center bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold shadow-sm flex items-center gap-2 transition-all"
                >
                  {showScheduleForm ? <><X size={16}/> Cerrar Formulario</> : <><Plus size={16}/> Programar Entrevista</>}
                </button>
              </div>

              {/* FORMULARIO AGENDAR */}
              {showScheduleForm && (
                <form onSubmit={handleScheduleInterview} className="bg-slate-50 border border-slate-200 p-6 rounded-2xl mb-8 space-y-4 animate-fade-in-up">
                  <h4 className="font-bold text-lg text-slate-900 mb-2">Programar Cita para Candidato</h4>
                  <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                    <div className="col-span-1 sm:col-span-2">
                      <label className="block text-xs font-bold text-slate-600 uppercase mb-2">Candidato *</label>
                      <select 
                        required 
                        value={candidateId} 
                        onChange={e => setCandidateId(e.target.value)} 
                        className="w-full px-4 py-2 bg-white border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500"
                      >
                        <option value="">-- Seleccionar Candidato --</option>
                        {candidates.filter((c: any) => c.status !== 'hired' && c.status !== 'rejected').map((c: any) => (
                          <option key={c.id} value={c.id}>{c.name} ({c.status.toUpperCase()})</option>
                        ))}
                      </select>
                    </div>

                    <div>
                      <label className="block text-xs font-bold text-slate-600 uppercase mb-2">Fecha *</label>
                      <input 
                        type="date" 
                        required 
                        value={date} 
                        onChange={e => setDate(e.target.value)} 
                        className="w-full px-4 py-2 bg-white border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500" 
                      />
                    </div>

                    <div>
                      <label className="block text-xs font-bold text-slate-600 uppercase mb-2">Hora *</label>
                      <input 
                        type="time" 
                        required 
                        value={time} 
                        onChange={e => setTime(e.target.value)} 
                        className="w-full px-4 py-2 bg-white border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500" 
                      />
                    </div>

                    <div className="col-span-1 sm:col-span-2">
                      <label className="block text-xs font-bold text-slate-600 uppercase mb-2">Entrevistador / Reclutador *</label>
                      <input 
                        type="text" 
                        required 
                        placeholder="Ej. Lic. Francisco"
                        value={interviewer} 
                        onChange={e => setInterviewer(e.target.value)} 
                        className="w-full px-4 py-2 bg-white border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500" 
                      />
                    </div>

                    <div className="col-span-1 sm:col-span-2">
                      <label className="block text-xs font-bold text-slate-600 uppercase mb-2">Notas / Observaciones</label>
                      <input 
                        type="text" 
                        placeholder="Instrucciones adicionales para la entrevista..."
                        value={notes} 
                        onChange={e => setNotes(e.target.value)} 
                        className="w-full px-4 py-2 bg-white border border-slate-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500" 
                      />
                    </div>
                  </div>

                  <div className="flex gap-3 justify-end pt-2">
                    <button 
                      type="button" 
                      onClick={() => setShowScheduleForm(false)} 
                      className="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-bold rounded-xl"
                    >
                      Cancelar
                    </button>
                    <button 
                      type="submit" 
                      className="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold rounded-xl shadow-sm shadow-indigo-600/35 transition-all"
                    >
                      Agendar Entrevista
                    </button>
                  </div>
                </form>
              )}

              {/* LISTADO DE ENTREVISTAS */}
              {loading ? (
                <div className="flex items-center justify-center min-h-[250px]">
                  <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-indigo-600"></div>
                </div>
              ) : interviews.length === 0 ? (
                <div className="text-center py-16 bg-slate-50 border border-dashed rounded-3xl">
                  <Calendar className="mx-auto text-slate-300 mb-4 animate-bounce" size={48} />
                  <p className="text-slate-500 font-medium">No hay entrevistas programadas en la agenda actualmente.</p>
                  <button 
                    onClick={() => setShowScheduleForm(true)} 
                    className="mt-4 px-4 py-2 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 text-indigo-700 text-xs font-bold rounded-xl transition-all"
                  >
                    Programar Cita de Candidato
                  </button>
                </div>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                  {interviews.map((item: any) => (
                    <div 
                      key={item.id} 
                      className="bg-white border border-slate-200 rounded-2xl p-4 sm:p-5 shadow-sm hover:shadow-md hover:border-slate-300 transition-all flex flex-col justify-between"
                    >
                      <div>
                        <div className="flex justify-between items-start gap-2 mb-3">
                          <span className="bg-indigo-50 text-indigo-700 text-[10px] uppercase font-black px-2.5 py-1 rounded-lg border border-indigo-100">
                            Entrevista ATS
                          </span>
                          <button 
                            onClick={() => handleCancelInterview(item.id)}
                            className="text-slate-400 hover:text-rose-600 p-1.5 rounded-lg hover:bg-rose-50 transition-colors"
                            title="Cancelar Entrevista"
                          >
                            <Trash2 size={16} />
                          </button>
                        </div>

                        <h4 className="font-extrabold text-slate-800 text-lg mb-4 flex items-center gap-2">
                          <User size={18} className="text-slate-400" /> {item.candidate?.name || 'Candidato Desconocido'}
                        </h4>

                        <div className="space-y-2 text-sm text-slate-600">
                          <div className="flex items-center gap-2">
                            <Calendar size={15} className="text-indigo-500" />
                            <span><strong>Fecha:</strong> {item.interview_date}</span>
                          </div>
                          <div className="flex items-center gap-2">
                            <Clock size={15} className="text-indigo-500" />
                            <span><strong>Hora:</strong> {item.interview_time.substring(0, 5)}</span>
                          </div>
                          <div className="flex items-center gap-2">
                            <User size={15} className="text-indigo-500" />
                            <span><strong>Entrevistador:</strong> {item.interviewer_name}</span>
                          </div>
                        </div>

                        {item.notes && (
                          <div className="mt-4 pt-3 border-t border-slate-100 flex items-start gap-2 text-xs text-slate-500 bg-slate-50 p-2.5 rounded-xl">
                            <MessageSquare size={14} className="text-slate-400 shrink-0 mt-0.5" />
                            <p className="italic">{item.notes}</p>
                          </div>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}
           </div>
        )}
      </div>

      {/* Botón de Acción Flotante (FAB) Responsivo */}
      <div className="fixed bottom-6 right-6 z-40 block sm:hidden">
        {showFabMenu && (
          <div className="flex flex-col items-center gap-3.5 mb-3.5">
            <button
              onClick={() => {
                setActiveTab('entrevistas');
                setShowScheduleForm(true);
                setShowFabMenu(false);
              }}
              className="w-12 h-12 bg-white hover:bg-slate-50 text-slate-600 border border-slate-200 rounded-full flex items-center justify-center shadow-lg active:scale-90 transition-all duration-300 animate-fade-in-up-2"
            >
              <Calendar size={20} className="text-slate-500" />
            </button>
            <button
              onClick={() => {
                setActiveTab('vacantes');
                setTimeout(() => {
                  window.dispatchEvent(new CustomEvent('trigger-new-vacancy'));
                }, 100);
                setShowFabMenu(false);
              }}
              className="w-12 h-12 bg-indigo-600 hover:bg-indigo-700 text-white rounded-full flex items-center justify-center shadow-lg shadow-indigo-600/20 active:scale-90 transition-all duration-300 animate-fade-in-up-1"
            >
              <Plus size={20} />
            </button>
          </div>
        )}
        <button 
          onClick={() => setShowFabMenu(!showFabMenu)}
          className="w-14 h-14 bg-indigo-600 hover:bg-indigo-700 text-white rounded-full flex items-center justify-center shadow-lg shadow-indigo-600/35 transition-transform active:scale-95 z-50 relative"
        >
          <Plus size={24} className={`transition-transform duration-300 ${showFabMenu ? 'rotate-45' : ''}`} />
        </button>
      </div>

    </div>
  );
}
