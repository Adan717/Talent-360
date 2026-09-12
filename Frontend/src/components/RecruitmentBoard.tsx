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
  birth_certificate_url?: string | null;
  id_card_url?: string | null;
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
  const [verRechazados, setVerRechazados] = useState(false);
  const [interesados, setInteresados] = useState<{ id: number; email: string; job_role_name: string; created_at: string }[]>([]);

  useEffect(() => {
    fetchVacancies();
  }, []);

  const fetchVacancies = async () => {
    try {
      setLoading(true);
      const [vacRes, candRes, alertRes] = await Promise.all([
        axiosInstance.get('/admin/vacancies'),
        axiosInstance.get('/admin/candidates'),
        // Los que pidieron aviso desde el portal: la tabla existía desde siempre y NADIE la leía,
        // así que su interés se perdía mientras el portal les prometía un correo.
        axiosInstance.get('/admin/vacancy-alerts').catch(() => null)
      ]);
      setVacancies(vacRes.data || []);
      setCandidates(candRes.data || []);
      setInteresados(alertRes?.data || []);
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

  const confirmHire = async () => {
    if (candidateToHire) {
      try {
        const res = await axiosInstance.put(`/admin/candidates/${candidateToHire.id}`, { status: 'hired' });
        const pin = res.data?.pin_code;
        const avisos: string[] = res.data?.avisos || [];

        // Antes se llamaba a `hireEmployee` del store, que FABRICABA un colaborador en el
        // navegador: correo inventado, `tenant_id: 1` en duro y el id de la VACANTE usado como
        // id de PUESTO. Ese fantasma vivía toda la sesión y aparecía hasta en el selector de
        // asignar tareas. Se relee del servidor, que es quien sabe qué se creó de verdad.
        await fetchVacancies();
        await useAppStore.getState().fetchState?.();

        // El aviso prometía tres cosas que el servidor no hace: que el PIN es de fichaje (es el
        // de invitación; el de kiosko es otro), que quedó inscrito en cursos, y un "bloqueo
        // operativo" que no existe (el reloj avisa, no bloquea).
        // Bloque 5 / D2 (2026-08-13): lo de los cursos volvió, pero ahora es VERDAD — el
        // servidor cuenta los cursos de inducción de Academia que le aplican por su puesto,
        // y el aviso de "inducción pendiente" le corre desde su hire_date (fijado al contratar).
        const cursosInduccion: number = res.data?.induction_courses ?? 0;
        alert(
          `Contratado: ${candidateToHire.name}\n\n` +
          `Ya tiene expediente y acceso.${pin ? `\nPIN de invitación (para activar su cuenta): ${pin}` : ''}\n` +
          (cursosInduccion > 0 ? `\nQuedó inscrito en su inducción de la Academia (${cursosInduccion} curso${cursosInduccion === 1 ? '' : 's'}).\n` : '') +
          (avisos.length ? `\nFalta por hacer:\n• ${avisos.join('\n• ')}` : '')
        );
      } catch (err: any) {
        console.error(err);
        const detalle = err?.response?.data?.errors?.email?.[0] || err?.response?.data?.message;
        alert(detalle || "Hubo un error al dar de alta al empleado.");
      }
    }
    setShowHireModal(false);
    setCandidateToHire(null);
  };

  const Column = ({ title, status, color }: { title: string, status: Candidate['status'], color: string }) => {
    const colCandidates = candidates.filter(c => c.status === status);
    return (
      <div className="flex flex-col bg-page rounded-xl p-3 min-w-[280px] border border-border">
        <div className={`font-bold text-sm mb-3 flex justify-between items-center ${color}`}>
          {title}
          <span className="bg-white text-text-2 px-2 py-0.5 rounded-full text-xs shadow-sm">{colCandidates.length}</span>
        </div>
        <div className="flex flex-col gap-3 min-h-[300px]">
          {colCandidates.map(c => {
             const v = vacancies.find(v => v.id === c.applied_vacancy_id);
             return (
              <div key={c.id} className="bg-white p-3 rounded-lg shadow-sm border border-border hover:shadow-md transition-shadow cursor-grab">
                <div className="flex justify-between items-start mb-1">
                  <h4 className="font-bold text-text-1 text-sm">{c.name}</h4>
                  {c.is_ex_employee_fast_track && <span className="bg-warning-bg text-warning-text text-[10px] px-1.5 py-0.5 rounded font-bold">Fast-Track</span>}
                </div>
                <p className="text-xs text-text-3 mb-2">{v?.title}</p>
                <div className="flex gap-1 mt-2 flex-wrap">
                  {/* D2: vocabulario — esto es la EVALUACIÓN DE POSTULACIÓN (filtro
                      pre-contratación); "inducción" es el onboarding de Academia, que ocurre
                      ya contratado. El status 'induction' se queda (contrato de datos). */}
                  {status === 'prospect' && (
                    <button onClick={() => moveCandidate(c.id, 'induction')} className="text-[10px] bg-navy-50 text-accent px-2 py-1 rounded hover:bg-accent-soft">A Evaluación</button>
                  )}
                  {status === 'induction' && (
                    <button onClick={() => moveCandidate(c.id, 'interview')} className="text-[10px] bg-navy-50 text-accent px-2 py-1 rounded hover:bg-accent-soft">A Entrevista</button>
                  )}
                  {status === 'interview' && (
                    <button onClick={() => moveCandidate(c.id, 'training')} className="text-[10px] bg-warning-bg text-warning-text px-2 py-1 rounded hover:bg-warning-bg">A Prueba</button>
                  )}
                  {status === 'training' && (
                    <button
                      onClick={() => handleHireClick(c)}
                      className="text-[10px] bg-success-text hover:bg-success-text text-white font-bold px-2 py-1.5 rounded-lg transition-all shadow-sm flex items-center gap-1"
                    >
                      Contratar en 1-Click
                    </button>
                  )}
                  <button onClick={() => setSelectedCandidate(c)} className="text-[10px] bg-page text-text-2 px-2 py-1 rounded hover:bg-slate-200 flex items-center gap-1"><Eye size={12}/> Expediente</button>
                  {['prospect', 'induction', 'interview', 'training'].includes(status) && (
                    // Rechazar sacaba al candidato de TODAS las pantallas sin preguntar y sin
                    // vuelta atrás: el tablero no tenía columna de rechazados.
                    <button
                      onClick={() => {
                        if (window.confirm(`¿Rechazar a ${c.name}? Saldrá del tablero, pero puedes recuperarlo desde "Ver rechazados".`)) {
                          moveCandidate(c.id, 'rejected');
                        }
                      }}
                      className="text-[10px] bg-danger-bg text-danger-text px-2 py-1 rounded hover:bg-danger-bg"
                    >
                      Rechazar
                    </button>
                  )}
                  {status === 'rejected' && (
                    <button onClick={() => moveCandidate(c.id, 'prospect')} className="text-[10px] bg-page text-text-2 px-2 py-1 rounded hover:bg-slate-200">Devolver a Prospectos</button>
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
          <h3 className="text-2xl font-bold text-text-1">Atracción de Talento</h3>
          {/* La "vía rápida" no existía: `is_ex_employee_fast_track` no se leía en ninguna parte y
              nadie lo encendía nunca. Ahora el servidor SÍ marca a quien ya tuvo expediente en
              esta empresa, y la etiqueta significa eso: ya trabajó aquí. No salta ningún paso. */}
          <p className="text-text-3 text-sm">Gestiona el flujo de candidatos. Los que ya trabajaron aquí llegan marcados.</p>
        </div>
        <div className="flex gap-3 w-full sm:w-auto">
          {/* El botón "Configurar Inducciones" no tenía handler y no había pantalla a la que
              llevar: la inducción se configura en la Academia. */}
          <button
            onClick={() => setVerRechazados(v => !v)}
            className="w-full sm:w-auto justify-center bg-page text-text-2 hover:bg-slate-200 px-4 py-2.5 rounded-lg text-sm font-medium shadow-sm flex items-center gap-2"
          >
            <Settings size={16}/> {verRechazados ? 'Ocultar rechazados' : 'Ver rechazados'}
          </button>
        </div>
      </div>

      <div className="flex gap-4 overflow-x-auto pb-4 custom-scrollbar">
        <Column title="1. Prospectos" status="prospect" color="text-text-2" />
        <Column title="2. Evaluación de Postulación" status="induction" color="text-accent" />
        <Column title="3. Por Entrevistar" status="interview" color="text-accent" />
        <Column title="4. Entrenamiento Piso" status="training" color="text-warning-text" />
        <Column title="5. Contratación" status="hired" color="text-success-text" />
        {verRechazados && <Column title="Rechazados" status={'rejected' as Candidate['status']} color="text-danger-text" />}
      </div>

      {/* Interesados: los correos que dejó gente pidiendo aviso cuando la vacante se reabra.
          El portal se lo prometía y la lista no la veía nadie. */}
      {interesados.length > 0 && (
        <div className="mt-6 bg-white border border-border rounded-2xl p-4 sm:p-5">
          <h4 className="font-bold text-text-1 text-sm mb-1">Interesados en vacantes cerradas ({interesados.length})</h4>
          <p className="text-xs text-text-3 mb-3">Dejaron su correo en la bolsa de trabajo pidiendo aviso. No se les manda nada automáticamente: contáctalos tú cuando la posición se reabra.</p>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="text-left text-slate-400 uppercase text-[10px]">
                  <th className="py-2 pr-4 font-bold">Correo</th>
                  <th className="py-2 pr-4 font-bold">Puesto</th>
                  <th className="py-2 font-bold">Cuándo</th>
                </tr>
              </thead>
              <tbody>
                {interesados.slice(0, 25).map(i => (
                  <tr key={i.id} className="border-t border-border">
                    <td className="py-2 pr-4 font-semibold text-text-2">{i.email}</td>
                    <td className="py-2 pr-4 text-text-2">{i.job_role_name}</td>
                    <td className="py-2 text-slate-400">{i.created_at ? new Date(i.created_at).toLocaleDateString('es-MX') : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {selectedCandidate && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 overflow-y-auto">
          <div className="bg-white rounded-3xl w-full max-w-2xl p-4 sm:p-8 shadow-2xl relative max-h-[90vh] overflow-y-auto">
            <button onClick={() => setSelectedCandidate(null)} className="absolute top-6 right-6 text-slate-400 hover:text-text-2 text-xl font-bold">&times;</button>
            <h2 className="text-2xl font-extrabold text-text-1 mb-2">Expediente del Candidato</h2>
            <div className="flex items-center gap-4 mb-6">
              <div className="w-16 h-16 bg-slate-200 rounded-full flex items-center justify-center text-xl font-bold text-text-3 uppercase shrink-0">
                {selectedCandidate.name.substring(0, 2)}
              </div>
              <div>
                <h3 className="text-lg font-bold text-text-1">{selectedCandidate.name}</h3>
                <p className="text-text-3 text-sm">{selectedCandidate.email}</p>
                <span className="inline-block mt-1 bg-navy-50 text-accent text-xs px-2 py-0.5 rounded font-bold uppercase tracking-wider">Status: {selectedCandidate.status}</span>
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 mb-6">
              <div className="bg-page p-4 rounded-xl border border-border">
                <h4 className="font-bold text-text-2 mb-2 text-sm">Evaluación de Postulación</h4>
                {selectedCandidate.induction_score ? (
                  <div className="flex items-end gap-2">
                    <span className="text-3xl font-extrabold text-success-text">{selectedCandidate.induction_score}%</span>
                    <span className="text-sm text-text-3 pb-1">Aprobado</span>
                  </div>
                ) : (
                  <p className="text-sm text-text-3 italic">Examen no realizado aún.</p>
                )}
              </div>
              {/* Estos dos "adjuntos" eran decoración: dos <li> con aspecto de enlace, sin href y
                  sin onClick, pintados igual para todo el mundo. Las columnas existen en la tabla
                  pero nadie las escribe nunca — no hay subida de archivos en el portal. Se pinta
                  el estado REAL, como ya hacía el recuadro de al lado. */}
              <div className="bg-page p-4 rounded-xl border border-border">
                <h4 className="font-bold text-text-2 mb-2 text-sm">Documentación Adjunta</h4>
                {(selectedCandidate.birth_certificate_url || selectedCandidate.id_card_url) ? (
                  <ul className="space-y-3 text-sm">
                    {selectedCandidate.birth_certificate_url && (
                      <li className="flex items-center gap-2 text-accent"><FileText size={16}/> Acta de nacimiento</li>
                    )}
                    {selectedCandidate.id_card_url && (
                      <li className="flex items-center gap-2 text-accent"><UserSquare size={16}/> Identificación oficial (INE)</li>
                    )}
                  </ul>
                ) : (
                  <p className="text-sm text-text-3 italic">Sin documentos entregados — el portal de empleos todavía no permite adjuntarlos.</p>
                )}
              </div>
            </div>

            <div className="flex gap-3 border-t border-border pt-6">
              <button
                onClick={() => setSelectedCandidate(null)}
                className="flex-1 bg-page text-text-2 px-4 py-3 rounded-xl font-bold hover:bg-slate-200 transition-all text-sm"
              >
                Cerrar Expediente
              </button>
              {selectedCandidate.status !== 'hired' && selectedCandidate.status !== 'rejected' && (
                <button
                  onClick={() => {
                    handleHireClick(selectedCandidate);
                    setSelectedCandidate(null);
                  }}
                  className="flex-1 bg-success-text text-white px-4 py-3 rounded-xl font-bold hover:bg-success-text shadow-md transition-all flex items-center justify-center gap-1.5 text-sm"
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
          <div className="bg-white rounded-3xl w-full max-w-md p-6 sm:p-8 shadow-2xl relative text-center max-h-[90vh] overflow-y-auto border border-border animate-slide-up">
            <div className="w-16 h-16 bg-success-bg text-success-text rounded-2xl flex items-center justify-center mx-auto mb-4 border border-success-text/20 shadow-sm">
              <CheckCircle size={32} />
            </div>
            <h2 className="text-2xl font-black text-text-1 mb-2">Contratación 1-Click</h2>
            <p className="text-text-3 text-sm mb-6 font-medium">
              Vas a dar de alta a <strong className="text-text-1">{candidateToHire.name}</strong> como colaborador de la empresa.
            </p>

            {/* Esta lista prometía un "bloqueo operativo hasta completar inducción" que no existe
                —el reloj avisa, nunca bloquea, igual que se corrigió en la Academia— y llamaba
                "PIN móvil de fichaje" al PIN de invitación (el de kiosko es otro). Ahora dice lo
                que de verdad ocurre, incluido lo que queda pendiente. */}
            <div className="bg-page p-5 rounded-2xl border border-border/60 mb-6 text-left space-y-3">
              <div className="flex items-center gap-2 text-xs font-bold text-text-2">
                <span className="text-success-text text-sm">✓</span>
                <span>Se le crea su expediente de colaborador</span>
              </div>
              <div className="flex items-center gap-2 text-xs font-bold text-text-2">
                <span className="text-success-text text-sm">✓</span>
                <span>PIN de invitación para que active su cuenta y fije su contraseña</span>
              </div>
              <div className="flex items-center gap-2 text-xs font-bold text-text-2">
                <span className="text-success-text text-sm">✓</span>
                <span>Hereda el horario de la empresa</span>
              </div>
              <div className="flex items-center gap-2 text-xs font-bold text-warning-text">
                <span className="text-warning-text text-sm">⚠</span>
                <span>Su sueldo NO se captura aquí: hay que ponerlo en RRHH o la nómina usará un valor por defecto</span>
              </div>
            </div>

            <div className="flex gap-3">
              <button
                onClick={() => {setShowHireModal(false); setCandidateToHire(null);}}
                className="flex-1 bg-page hover:bg-slate-200 text-text-2 py-3 rounded-xl font-bold transition-all text-sm"
              >
                Cancelar
              </button>
              <button
                onClick={confirmHire}
                className="flex-1 bg-success-text hover:bg-success-text text-white py-3 rounded-xl font-bold transition-all shadow-md shadow-success-text/20 text-sm"
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
