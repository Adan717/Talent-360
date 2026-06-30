import React, { useState, useEffect } from 'react';
import axiosInstance from '../../lib/axios';

export default function Evaluacion360({ onBack }: { onBack: () => void }) {
  const [step, setStep] = useState(1);
  const [peers, setPeers] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const [selectedPeer, setSelectedPeer] = useState<any | null>(null);
  
  // Form states
  const [teamworkScore, setTeamworkScore] = useState<number>(5);
  const [attitudeScore, setAttitudeScore] = useState<number>(5);
  const [performanceScore, setPerformanceScore] = useState<number>(5);
  const [comments, setComments] = useState<string>('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    fetchPeers();
  }, []);

  const fetchPeers = async () => {
    try {
      setLoading(true);
      const res = await axiosInstance.get('/clock/peers');
      setPeers(res.data || []);
    } catch (e) {
      console.error("Error al cargar colaboradores:", e);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedPeer) return;
    try {
      setSubmitting(true);
      await axiosInstance.post('/clock/evaluations', {
        evaluated_user_id: selectedPeer.id,
        teamwork_score: teamworkScore,
        attitude_score: attitudeScore,
        performance_score: performanceScore,
        comments: comments
      });
      alert('Evaluación guardada confidencialmente.');
      onBack();
    } catch (err) {
      console.error(err);
      alert('Error al registrar la evaluación. Por favor intente de nuevo.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleSelectPeer = (peer: any) => {
    setSelectedPeer(peer);
    setTeamworkScore(5);
    setAttitudeScore(5);
    setPerformanceScore(5);
    setComments('');
    setStep(2);
  };

  if (step === 1) {
    return (
      <div className="bg-slate-900 rounded-3xl p-8 border border-white/10 text-white min-h-[500px] flex flex-col shadow-2xl relative overflow-hidden">
        <div className="absolute inset-0 bg-gradient-to-br from-indigo-900/40 to-slate-900 z-0"></div>
        <div className="relative z-10 flex justify-between items-center mb-8">
          <div>
            <h2 className="text-3xl font-extrabold bg-gradient-to-r from-emerald-400 to-teal-400 bg-clip-text text-transparent">Evaluación 360°</h2>
            <p className="text-slate-400 mt-1">Califica el desempeño de tus compañeros (100% Confidencial)</p>
          </div>
          <button onClick={onBack} className="bg-white/10 hover:bg-white/20 text-white px-6 py-2.5 rounded-xl font-bold transition-all backdrop-blur-sm border border-white/10">
            Cerrar
          </button>
        </div>

        <div className="relative z-10 space-y-4 max-w-2xl mx-auto w-full mt-8 flex-1 flex flex-col">
          {loading ? (
            <div className="flex-1 flex items-center justify-center">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-emerald-500"></div>
            </div>
          ) : peers.length === 0 ? (
            <div className="flex-1 flex flex-col items-center justify-center text-slate-400 py-12">
              <span className="text-5xl mb-4">👥</span>
              <p className="text-center font-semibold">No hay otros colaboradores disponibles para evaluar en este turno.</p>
            </div>
          ) : (
            <>
              <p className="text-center text-slate-300 mb-6">Selecciona al compañero que deseas evaluar al cierre de este turno:</p>
              <div className="space-y-3 max-h-[300px] overflow-y-auto pr-2 custom-scrollbar">
                {peers.map(peer => (
                  <button 
                    key={peer.id}
                    onClick={() => handleSelectPeer(peer)}
                    className="w-full bg-white/5 hover:bg-white/10 border border-white/10 hover:border-emerald-500/50 p-4 rounded-2xl flex items-center justify-between transition-all group text-left"
                  >
                    <div className="flex items-center space-x-4">
                      <div className="w-12 h-12 rounded-full bg-emerald-500/20 flex items-center justify-center text-xl">👤</div>
                      <span className="font-bold text-lg">{peer.name}</span>
                    </div>
                    <span className="text-slate-500 group-hover:text-emerald-400 transition-colors">Evaluar &rarr;</span>
                  </button>
                ))}
              </div>
            </>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="bg-slate-900 rounded-3xl p-8 border border-white/10 text-white min-h-[500px] flex flex-col shadow-2xl relative overflow-hidden">
      <div className="relative z-10 flex justify-between items-center mb-8">
        <div>
          <h2 className="text-3xl font-extrabold">Evaluando a <span className="text-emerald-400">{selectedPeer?.name.split(' (')[0]}</span></h2>
        </div>
        <button onClick={() => setStep(1)} className="text-slate-400 hover:text-white transition-colors">
          Cancelar
        </button>
      </div>

      <form onSubmit={handleSubmit} className="relative z-10 max-w-2xl mx-auto w-full space-y-6 flex-1 flex flex-col justify-between">
        <div className="space-y-6">
          <div>
            <label className="block text-slate-300 font-bold mb-3">1. ¿Cómo calificarías su Trabajo en Equipo hoy?</label>
            <div className="flex space-x-4 justify-center">
              {[1, 2, 3, 4, 5].map(star => (
                <button 
                  type="button" 
                  key={star} 
                  onClick={() => setTeamworkScore(star)}
                  className={`text-4xl transition-colors ${star <= teamworkScore ? 'text-yellow-400' : 'text-slate-600'}`}
                >
                  ★
                </button>
              ))}
            </div>
          </div>

          <div>
            <label className="block text-slate-300 font-bold mb-3">2. ¿Cómo fue su Actitud hoy?</label>
            <div className="flex space-x-6 justify-center">
              {[
                { emoji: '😞', score: 2, label: 'Deficiente' },
                { emoji: '😐', score: 3, label: 'Regular' },
                { emoji: '🙂', score: 4, label: 'Buena' },
                { emoji: '🤩', score: 5, label: 'Excelente' }
              ].map(opt => (
                <button 
                  type="button"
                  key={opt.score}
                  onClick={() => setAttitudeScore(opt.score)}
                  title={opt.label}
                  className={`text-4xl transition-all ${attitudeScore === opt.score ? 'scale-125 grayscale-0' : 'grayscale hover:grayscale-0 opacity-60 hover:opacity-100'}`}
                >
                  {opt.emoji}
                </button>
              ))}
            </div>
          </div>

          <div>
            <label className="block text-slate-300 font-bold mb-2">3. Desempeño General del Turno</label>
            <div className="flex space-x-4 justify-center">
              {[1, 2, 3, 4, 5].map(star => (
                <button 
                  type="button" 
                  key={star} 
                  onClick={() => setPerformanceScore(star)}
                  className={`text-3xl transition-colors ${star <= performanceScore ? 'text-emerald-400' : 'text-slate-600'}`}
                >
                  ★
                </button>
              ))}
            </div>
          </div>

          <div>
            <label className="block text-slate-300 font-bold mb-2">4. Comentarios Adicionales (Confidencial)</label>
            <textarea 
              value={comments} 
              onChange={e => setComments(e.target.value)} 
              className="w-full bg-white/5 border border-white/10 rounded-xl p-3 text-white focus:outline-none focus:border-emerald-500 text-sm" 
              placeholder="Escribe comentarios u observaciones sobre su desempeño en el turno..." 
              rows={3} 
            />
          </div>
        </div>

        <div className="pt-6 flex justify-center">
          <button 
            type="submit"
            disabled={submitting}
            className="bg-emerald-600 hover:bg-emerald-500 disabled:bg-emerald-800 text-white px-8 py-3 rounded-xl font-bold transition-all shadow-[0_0_20px_rgba(16,185,129,0.4)] hover:shadow-[0_0_30px_rgba(16,185,129,0.6)] w-full max-w-xs"
          >
            {submitting ? 'Enviando...' : 'Enviar Evaluación'}
          </button>
        </div>
      </form>
    </div>
  );
}
