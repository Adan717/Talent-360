import React, { useState, useEffect } from 'react';
import { Settings, Eye, FileText, UserSquare, CheckCircle } from 'lucide-react';
import { useAppStore } from '../store/useAppStore';
import axiosInstance from '../lib/axios';

// Tipos base para Reclutamiento
interface Candidate {
  id: number;
  name: string;
  email: string;
  applied_vacancy_id: number;
  status: 'prospect' | 'induction' | 'interview' | 'training' | 'evaluation' | 'hired' | 'rejected';
  induction_score?: number;
  is_ex_employee_fast_track: boolean;
}

interface Vacancy {
  id: number;
  title: string;
  job_role_id: number;
}

export const RecruitmentBoard: React.FC = () => {
  const [candidates, setCandidates] = useState<Candidate[]>([]);
  const [vacancies, setVacancies] = useState<Vacancy[]>([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    fetchVacancies();
  }, []);

  const fetchVacancies = async () => {
    try {
      setLoading(true);
      const [vacRes, candRes] = await Promise.all([
        axiosInstance.get('/admin/vacancies'),
        axiosInstance.get('/admin/candidates')
      ]);
      setVacancies(vacRes.data || []);
      setCandidates(candRes.data || []);
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  };

  const [selectedCandidate, setSelectedCandidate] = useState<Candidate | null>(null);
  const [showHireModal, setShowHireModal] = useState(false);
  const [candidateToHire, setCandidateToHire] = useState<Candidate | null>(null);

  const moveCandidate = async (candidateId: number, newStatus: Candidate['status']) => {
    try {
      await axiosInstance.put(`/admin/candidates/${candidateId}`, { status: newStatus });
      setCandidates(prev => prev.map(c => c.id === candidateId ? { ...c, status: newStatus } : c));
    } catch (e) {
      console.error(e);
      alert("Hubo un error al mover el candidato.");
    }
  };

  const handleHireClick = (candidate: Candidate) => {
    setCandidateToHire(candidate);
    setShowHireModal(true);
  };

  const { hireEmployee } = useAppStore();

  const confirmHire = async () => {
    if (candidateToHire) {
      try {
        const res = await axiosInstance.put(`/admin/candidates/${candidateToHire.id}`, { status: 'hired' });
        const pinGenerated = res.data?.pin_code || "Generado";
        
        // Sincronizar localmente en store global
        hireEmployee(candidateToHire);
        setCandidates(prev => prev.map(c => c.id === candidateToHire.id ? { ...c, status: 'hired' } : c));
        
        alert(`🎉 ¡Contratación en 1-Click Exitosa!\n\nColaborador: ${candidateToHire.name}\nPIN Móvil único de fichaje: ${pinGenerated}\n\nSe ha insertado directamente en la base de datos de empleados (Postgres), se ha activado su perfil de inicio de sesión e inscrito a sus Cursos de Inducción obligatorios.`);
      } catch (err) {
        console.error(err);
        alert("Hubo un error al dar de alta al empleado.");
      }
    }
    setShowHireModal(false);
    setCandidateToHire(null);
  };

  const Column = ({ title, status, color }: { title: string, status: Candidate['status'], color: string }) => {
    const colCandidates = candidates.filter(c => c.status === status);
    return (
      <div className="flex flex-col bg-slate-50 rounded-xl p-3 min-w-[280px] border border-slate-200">
        <div className={`font-bold text-sm mb-3 flex justify-between items-center ${color}`}>
          {title}
          <span className="bg-white text-slate-600 px-2 py-0.5 rounded-full text-xs shadow-sm">{colCandidates.length}</span>
        </div>
        <div className="flex flex-col gap-3 min-h-[300px]">
          {colCandidates.map(c => {
             const v = vacancies.find(v => v.id === c.applied_vacancy_id);
             return (
              <div key={c.id} className="bg-white p-3 rounded-lg shadow-sm border border-slate-100 hover:shadow-md transition-shadow cursor-grab">
                <div className="flex justify-between items-start mb-1">
                  <h4 className="font-bold text-slate-800 text-sm">{c.name}</h4>
                  {c.is_ex_employee_fast_track && <span className="bg-amber-100 text-amber-700 text-[10px] px-1.5 py-0.5 rounded font-bold">Fast-Track</span>}
                </div>
                <p className="text-xs text-slate-500 mb-2">{v?.title}</p>
                <div className="flex gap-1 mt-2 flex-wrap">
                  {status === 'prospect' && (
                    <button onClick={() => moveCandidate(c.id, 'induction')} className="text-[10px] bg-blue-50 text-blue-600 px-2 py-1 rounded hover:bg-blue-100">A Inducción</button>
                  )}
                  {status === 'induction' && (
                    <button onClick={() => moveCandidate(c.id, 'interview')} className="text-[10px] bg-purple-50 text-purple-600 px-2 py-1 rounded hover:bg-purple-100">A Entrevista</button>
                  )}
                  {status === 'interview' && (
                    <button onClick={() => moveCandidate(c.id, 'training')} className="text-[10px] bg-orange-50 text-orange-600 px-2 py-1 rounded hover:bg-orange-100">A Prueba</button>
                  )}
                  {status === 'training' && (
                    <button 
                      onClick={() => handleHireClick(c)} 
                      className="text-[10px] bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-2 py-1.5 rounded-lg transition-all shadow-sm flex items-center gap-1"
                    >
                      Contratar en 1-Click
                    </button>
                  )}
                  <button onClick={() => setSelectedCandidate(c)} className="text-[10px] bg-slate-100 text-slate-700 px-2 py-1 rounded hover:bg-slate-200 flex items-center gap-1"><Eye size={12}/> Expediente</button>
                  {['prospect', 'induction', 'interview', 'training'].includes(status) && (
                    <button onClick={() => moveCandidate(c.id, 'rejected')} className="text-[10px] bg-rose-50 text-rose-600 px-2 py-1 rounded hover:bg-rose-100">Rechazar</button>
                  )}
                </div>
              </div>
            );
          })}
          {colCandidates.length === 0 && <div className="text-center text-xs text-slate-400 mt-10">Sin candidatos</div>}
        </div>
      </div>
    );
  };
  return (
    <div className="w-full relative">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
        <div>
          <h3 className="text-2xl font-bold text-slate-900">Atracción de Talento</h3>
          <p className="text-slate-500 text-sm">Gestiona el flujo de candidatos. Ex-empleados tienen vía rápida (Fast-Track).</p>
        </div>
        <div className="flex gap-3 w-full sm:w-auto">
            <button className="w-full sm:w-auto justify-center bg-slate-100 text-slate-700 hover:bg-slate-200 px-4 py-2.5 rounded-lg text-sm font-medium shadow-sm flex items-center gap-2"><Settings size={16}/> Configurar Inducciones</button>
        </div>
      </div>

      <div className="flex gap-4 overflow-x-auto pb-4 custom-scrollbar">
        <Column title="1. Prospectos" status="prospect" color="text-slate-600" />
        <Column title="2. Inducción / Quiz" status="induction" color="text-blue-600" />
        <Column title="3. Por Entrevistar" status="interview" color="text-purple-600" />
        <Column title="4. Entrenamiento Piso" status="training" color="text-orange-600" />
        <Column title="5. Contratación" status="hired" color="text-emerald-600" />
      </div>

      {selectedCandidate && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 overflow-y-auto">
          <div className="bg-white rounded-3xl w-full max-w-2xl p-4 sm:p-8 shadow-2xl relative max-h-[90vh] overflow-y-auto">
            <button onClick={() => setSelectedCandidate(null)} className="absolute top-6 right-6 text-slate-400 hover:text-slate-700 text-xl font-bold">&times;</button>
            <h2 className="text-2xl font-extrabold text-slate-800 mb-2">Expediente del Candidato</h2>
            <div className="flex items-center gap-4 mb-6">
              <div className="w-16 h-16 bg-slate-200 rounded-full flex items-center justify-center text-xl font-bold text-slate-500 uppercase shrink-0">
                {selectedCandidate.name.substring(0, 2)}
              </div>
              <div>
                <h3 className="text-lg font-bold text-slate-800">{selectedCandidate.name}</h3>
                <p className="text-slate-500 text-sm">{selectedCandidate.email}</p>
                <span className="inline-block mt-1 bg-indigo-50 text-indigo-700 text-xs px-2 py-0.5 rounded font-bold uppercase tracking-wider">Status: {selectedCandidate.status}</span>
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 mb-6">
              <div className="bg-slate-50 p-4 rounded-xl border border-slate-100">
                <h4 className="font-bold text-slate-700 mb-2 text-sm">Resultados de Inducción</h4>
                {selectedCandidate.induction_score ? (
                  <div className="flex items-end gap-2">
                    <span className="text-3xl font-extrabold text-emerald-600">{selectedCandidate.induction_score}%</span>
                    <span className="text-sm text-slate-500 pb-1">Aprobado</span>
                  </div>
                ) : (
                  <p className="text-sm text-slate-500 italic">Examen no realizado aún.</p>
                )}
              </div>
              <div className="bg-slate-50 p-4 rounded-xl border border-slate-100">
                <h4 className="font-bold text-slate-700 mb-2 text-sm">Documentación Adjunta</h4>
                <ul className="space-y-3 text-sm">
                  <li className="flex items-center gap-2 text-blue-600 hover:underline cursor-pointer"><FileText size={16}/> Currículum Vitae (PDF)</li>
                  <li className="flex items-center gap-2 text-blue-600 hover:underline cursor-pointer"><UserSquare size={16}/> Identificación Oficial (INE)</li>
                </ul>
              </div>
            </div>

            <div className="flex gap-3 border-t border-slate-100 pt-6">
              <button 
                onClick={() => setSelectedCandidate(null)} 
                className="flex-1 bg-slate-100 text-slate-700 px-4 py-3 rounded-xl font-bold hover:bg-slate-200 transition-all text-sm"
              >
                Cerrar Expediente
              </button>
              {selectedCandidate.status !== 'hired' && selectedCandidate.status !== 'rejected' && (
                <button 
                  onClick={() => {
                    handleHireClick(selectedCandidate);
                    setSelectedCandidate(null);
                  }}
                  className="flex-1 bg-emerald-600 text-white px-4 py-3 rounded-xl font-bold hover:bg-emerald-700 shadow-md transition-all flex items-center justify-center gap-1.5 text-sm"
                >
                  <CheckCircle size={16} /> Contratar en 1-Click
                </button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Modal de Confirmación de Contratación */}
      {showHireModal && candidateToHire && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/80 backdrop-blur-sm p-4 overflow-y-auto">
          <div className="bg-white rounded-3xl w-full max-w-md p-6 sm:p-8 shadow-2xl relative text-center max-h-[90vh] overflow-y-auto border border-slate-100 animate-slide-up">
            <div className="w-16 h-16 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-emerald-100 shadow-sm">
              <CheckCircle size={32} />
            </div>
            <h2 className="text-2xl font-black text-slate-900 mb-2">Contratación 1-Click</h2>
            <p className="text-slate-500 text-sm mb-6 font-medium">
              ¿Deseas dar de alta a <strong className="text-slate-800">{candidateToHire.name}</strong> en Postgres, generar su PIN móvil e iniciar su enrolamiento académico?
            </p>
            
            <div className="bg-slate-50 p-5 rounded-2xl border border-slate-200/60 mb-6 text-left space-y-3">
              <div className="flex items-center gap-2 text-xs font-bold text-slate-700">
                <span className="text-emerald-500 text-sm">✓</span>
                <span>Inserción directa en base de datos real</span>
              </div>
              <div className="flex items-center gap-2 text-xs font-bold text-slate-700">
                <span className="text-emerald-500 text-sm">✓</span>
                <span>Generación de PIN móvil de fichaje único</span>
              </div>
              <div className="flex items-center gap-2 text-xs font-bold text-slate-700">
                <span className="text-emerald-500 text-sm">✓</span>
                <span>Perfil web activo para inicio de sesión inmediato</span>
              </div>
              <div className="flex items-center gap-2 text-xs font-bold text-red-500">
                <span className="text-red-500 text-sm">🔒</span>
                <span>Bloqueo operativo hasta completar inducción</span>
              </div>
            </div>

            <div className="flex gap-3">
              <button 
                onClick={() => {setShowHireModal(false); setCandidateToHire(null);}} 
                className="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 py-3 rounded-xl font-bold transition-all text-sm"
              >
                Cancelar
              </button>
              <button 
                onClick={confirmHire} 
                className="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white py-3 rounded-xl font-bold transition-all shadow-md shadow-emerald-600/20 text-sm"
              >
                Contratar
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
