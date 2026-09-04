import React, { useState } from 'react';
import { X, Lock, Share2, Zap, CheckCircle2, Globe, MessageCircle, ArrowRight, Sparkles } from 'lucide-react';
import axiosInstance from '../lib/axios';

interface ModuleUnlockModalProps {
  isOpen: boolean;
  onClose: () => void;
  moduleData: {
    id: string;
    title: string;
    desc: string;
    icon: React.ReactNode;
    pricePerColab?: number;
  } | null;
  onSuccess?: () => void;
}

export const ModuleUnlockModal: React.FC<ModuleUnlockModalProps> = ({
  isOpen,
  onClose,
  moduleData,
  onSuccess
}) => {
  const [activeTab, setActiveTab] = useState<'social' | 'purchase'>('social');
  const [proofUrl, setProofUrl] = useState('');
  const [proofNote, setProofNote] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  if (!isOpen || !moduleData) return null;

  const handleShare = (platform: 'linkedin' | 'facebook' | 'whatsapp') => {
    const text = encodeURIComponent(`¡Estamos optimizando el control de nuestro equipo con Talent360! Recomiendo esta plataforma para gestión de personal y RRHH: https://talent360.com.mx`);
    let url = '';

    if (platform === 'linkedin') {
      url = `https://www.linkedin.com/sharing/share-offsite/?url=https://talent360.com.mx`;
    } else if (platform === 'facebook') {
      url = `https://www.facebook.com/sharer/sharer.php?u=https://talent360.com.mx`;
    } else if (platform === 'whatsapp') {
      url = `https://api.whatsapp.com/send?text=${text}`;
    }

    window.open(url, '_blank', 'width=600,height=500');
  };

  const handleSubmitSocialGrace = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    setErrorMessage(null);
    try {
      const res = await axiosInstance.post('/store/addons/claim-social-grace', {
        module_key: moduleData.id,
        proof_url: proofUrl,
        proof_note: proofNote
      });
      setSuccessMessage(res.data.message || 'Solicitud enviada con éxito. En breve se validará y desbloqueará el módulo.');
      setTimeout(() => {
        setSuccessMessage(null);
        if (onSuccess) onSuccess();
        onClose();
      }, 2500);
    } catch (err: any) {
      setErrorMessage(err.response?.data?.message || 'Error al procesar la solicitud.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleSubscribeAddon = async () => {
    setIsSubmitting(true);
    setErrorMessage(null);
    try {
      await axiosInstance.post('/store/addons/subscribe', {
        module_key: moduleData.id
      });
      setSuccessMessage('¡Módulo contratado con éxito! Se ha activado inmediatamente en tu cuenta.');
      setTimeout(() => {
        setSuccessMessage(null);
        if (onSuccess) onSuccess();
        onClose();
      }, 2000);
    } catch (err: any) {
      setErrorMessage(err.response?.data?.message || 'Error al contratar el módulo.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
      <div className="bg-white rounded-3xl shadow-2xl border border-slate-100 w-full max-w-xl overflow-hidden relative flex flex-col max-h-[90vh]">
        
        {/* Header */}
        <div className="p-6 bg-gradient-to-r from-slate-900 via-blue-950 to-slate-900 text-white relative">
          <button 
            onClick={onClose}
            className="absolute top-5 right-5 text-slate-400 hover:text-white p-2 rounded-full hover:bg-white/10 transition-colors"
          >
            <X size={20} />
          </button>
          
          <div className="flex items-center gap-4">
            <div className="p-3 bg-blue-500/20 text-blue-400 rounded-2xl border border-blue-400/30 shrink-0">
              {moduleData.icon || <Lock size={24} />}
            </div>
            <div>
              <div className="flex items-center gap-2">
                <span className="text-[10px] font-black uppercase tracking-widest px-2.5 py-0.5 rounded-full bg-blue-500/30 text-blue-300 border border-blue-400/30">
                  Desbloqueo Inteligente
                </span>
              </div>
              <h2 className="text-xl font-black tracking-tight mt-1">{moduleData.title}</h2>
              <p className="text-xs text-slate-300 mt-0.5">{moduleData.desc}</p>
            </div>
          </div>

          {/* Navigation Tabs */}
          <div className="flex gap-2 mt-6 p-1 bg-white/10 rounded-xl">
            <button
              onClick={() => setActiveTab('social')}
              className={`flex-1 py-2 text-xs font-extrabold rounded-lg transition-all flex items-center justify-center gap-2 ${
                activeTab === 'social' 
                  ? 'bg-blue-600 text-white shadow-md' 
                  : 'text-slate-300 hover:text-white hover:bg-white/5'
              }`}
            >
              <Share2 size={15} />
              Gratis por Difusión Social
            </button>
            <button
              onClick={() => setActiveTab('purchase')}
              className={`flex-1 py-2 text-xs font-extrabold rounded-lg transition-all flex items-center justify-center gap-2 ${
                activeTab === 'purchase' 
                  ? 'bg-blue-600 text-white shadow-md' 
                  : 'text-slate-300 hover:text-white hover:bg-white/5'
              }`}
            >
              <Zap size={15} />
              Contratar A la Carta
            </button>
          </div>
        </div>

        {/* Content Body */}
        <div className="p-6 overflow-y-auto space-y-5">
          {successMessage && (
            <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center gap-3 text-emerald-800 text-xs font-bold animate-in fade-in">
              <CheckCircle2 size={18} className="text-emerald-600 shrink-0" />
              <span>{successMessage}</span>
            </div>
          )}

          {errorMessage && (
            <div className="p-4 bg-rose-50 border border-rose-200 rounded-2xl text-rose-800 text-xs font-bold animate-in fade-in">
              {errorMessage}
            </div>
          )}

          {activeTab === 'social' ? (
            <form onSubmit={handleSubmitSocialGrace} className="space-y-4">
              <div className="p-4 bg-blue-50/60 rounded-2xl border border-blue-100 space-y-2">
                <div className="flex items-center gap-2 text-blue-900 font-extrabold text-xs">
                  <Sparkles size={16} className="text-blue-600" />
                  <span>Obtén 30 días de acceso completo totalmente GRATIS</span>
                </div>
                <p className="text-[11px] text-blue-700 leading-relaxed font-medium">
                  Comparte Talent360 o tus vacantes en redes sociales, pega aquí el enlace o captura de tu publicación y nuestro equipo activará tu periodo de gracia en minutos.
                </p>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-2">
                  1. Comparte en tu red social preferida:
                </label>
                <div className="grid grid-cols-3 gap-3">
                  <button
                    type="button"
                    onClick={() => handleShare('linkedin')}
                    className="p-2.5 rounded-xl border border-slate-200 hover:border-blue-500 hover:bg-blue-50 text-slate-700 text-xs font-bold flex items-center justify-center gap-2 transition-all"
                  >
                    <Share2 size={16} className="text-blue-600" />
                    LinkedIn
                  </button>
                  <button
                    type="button"
                    onClick={() => handleShare('facebook')}
                    className="p-2.5 rounded-xl border border-slate-200 hover:border-blue-500 hover:bg-blue-50 text-slate-700 text-xs font-bold flex items-center justify-center gap-2 transition-all"
                  >
                    <Globe size={16} className="text-blue-600" />
                    Facebook
                  </button>
                  <button
                    type="button"
                    onClick={() => handleShare('whatsapp')}
                    className="p-2.5 rounded-xl border border-slate-200 hover:border-emerald-500 hover:bg-emerald-50 text-slate-700 text-xs font-bold flex items-center justify-center gap-2 transition-all"
                  >
                    <MessageCircle size={16} className="text-emerald-600" />
                    WhatsApp
                  </button>
                </div>
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">
                  2. Enlace de la publicación o Evidencia (opcional):
                </label>
                <input
                  type="url"
                  value={proofUrl}
                  onChange={(e) => setProofUrl(e.target.value)}
                  placeholder="https://linkedin.com/posts/..."
                  className="w-full text-xs p-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-700 mb-1">
                  Comentario adicional:
                </label>
                <textarea
                  value={proofNote}
                  onChange={(e) => setProofNote(e.target.value)}
                  placeholder="Compartí en nuestro grupo de colaboradores..."
                  rows={2}
                  className="w-full text-xs p-3 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                />
              </div>

              <button
                type="submit"
                disabled={isSubmitting}
                className="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-md transition-all flex items-center justify-center gap-2"
              >
                {isSubmitting ? 'Enviando...' : 'Solicitar Acceso Gratuito por 30 Días'}
                <ArrowRight size={16} />
              </button>
            </form>
          ) : (
            <div className="space-y-5">
              <div className="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-3">
                <div className="flex justify-between items-baseline">
                  <span className="text-xs font-bold text-slate-500 uppercase tracking-wider">Tarifa A la Carta</span>
                  <div className="text-right">
                    <span className="text-2xl font-black text-slate-900">${moduleData.pricePerColab || 15} MXN</span>
                    <span className="text-[11px] text-slate-500 font-bold"> / colaborador / mes</span>
                  </div>
                </div>
                <p className="text-xs text-slate-600 leading-relaxed font-medium">
                  Adquiere acceso continuo e ilimitado para toda tu sucursal. Facturado mensualmente dentro de tu suscripción de Talent360.
                </p>
              </div>

              <button
                onClick={handleSubscribeAddon}
                disabled={isSubmitting}
                className="w-full py-3.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-extrabold text-xs rounded-xl shadow-lg transition-all flex items-center justify-center gap-2"
              >
                {isSubmitting ? 'Contratando...' : 'Contratar Módulo A la Carta'}
                <Zap size={16} />
              </button>

              <div className="p-4 bg-amber-50 border border-amber-200 rounded-2xl flex items-center justify-between gap-3">
                <div>
                  <h4 className="text-xs font-extrabold text-amber-900">¿Prefieres acceso total a todo la suite?</h4>
                  <p className="text-[11px] text-amber-700 mt-0.5">Actualiza al Plan PRO o Enterprise desde $29 MXN/colaborador.</p>
                </div>
                <button
                  onClick={() => {
                    onClose();
                    window.location.href = '/settings?tab=billing';
                  }}
                  className="px-3.5 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-extrabold rounded-xl shrink-0 transition-colors"
                >
                  Ver Planes
                </button>
              </div>
            </div>
          )}
        </div>

      </div>
    </div>
  );
};
