import React, { useState, useEffect } from 'react';
import { Sparkles, ChevronDown, ChevronUp, Tag, ArrowRight, X } from 'lucide-react';
import axiosInstance from '../lib/axios';

interface PromotionStoreDockProps {
  onOpenStore?: () => void;
}

export const PromotionStoreDock: React.FC<PromotionStoreDockProps> = ({ onOpenStore }) => {
  const [promotion, setPromotion] = useState<any>(null);
  const [isMinimized, setIsMinimized] = useState<boolean>(() => {
    return localStorage.getItem('talent360_store_dock_minimized') === 'true';
  });

  useEffect(() => {
    const fetchPromotion = async () => {
      try {
        const res = await axiosInstance.get('/store/promotions/active');
        if (res.data?.promotion) {
          setPromotion(res.data.promotion);
        }
      } catch (err) {
        // Silent catch fallback
      }
    };
    fetchPromotion();
  }, []);

  const toggleMinimize = () => {
    const nextState = !isMinimized;
    setIsMinimized(nextState);
    localStorage.setItem('talent360_store_dock_minimized', String(nextState));
  };

  if (!promotion) {
    // Default fallback seasonal promotion if none active in DB
    return (
      <div className="fixed bottom-4 left-1/2 -translate-x-1/2 z-40 w-[92%] max-w-2xl animate-in slide-in-from-bottom-4 duration-300">
        <div className="bg-gradient-to-r from-slate-900 via-blue-950 to-slate-900 text-white rounded-2xl shadow-xl border border-slate-700/60 p-3.5 flex items-center justify-between gap-4 backdrop-blur-md">
          
          <div className="flex items-center gap-3 overflow-hidden">
            <div className="p-2 bg-gradient-to-tr from-amber-500 to-amber-300 text-slate-950 rounded-xl font-black text-[10px] shrink-0 uppercase tracking-widest shadow-md flex items-center gap-1">
              <Sparkles size={12} />
              Add-ons A la Carta
            </div>
            {!isMinimized && (
              <div className="truncate">
                <h4 className="text-xs font-black truncate">Desbloquea Módulos Gratis o a la Carta</h4>
                <p className="text-[11px] text-slate-300 truncate font-medium">Comparte en redes sociales para obtener 30 días gratis o contrata desde $10 MXN/colab</p>
              </div>
            )}
          </div>

          <div className="flex items-center gap-2 shrink-0">
            {!isMinimized && (
              <button 
                onClick={onOpenStore || (() => window.location.href = '/settings?tab=billing')}
                className="px-3.5 py-1.5 bg-blue-600 hover:bg-blue-500 text-white font-extrabold text-xs rounded-xl shadow-md transition-all flex items-center gap-1.5"
              >
                Explorar Tienda <ArrowRight size={13} />
              </button>
            )}
            <button 
              onClick={toggleMinimize}
              className="p-1.5 hover:bg-white/10 text-slate-300 hover:text-white rounded-lg transition-colors"
              title={isMinimized ? 'Expandir' : 'Minimizar'}
            >
              {isMinimized ? <ChevronUp size={16} /> : <ChevronDown size={16} />}
            </button>
          </div>

        </div>
      </div>
    );
  }

  return (
    <div className="fixed bottom-4 left-1/2 -translate-x-1/2 z-40 w-[92%] max-w-2xl animate-in slide-in-from-bottom-4 duration-300">
      <div className={`bg-gradient-to-r ${promotion.banner_bg_color || 'from-slate-900 via-blue-950 to-slate-900'} ${promotion.banner_text_color || 'text-white'} rounded-2xl shadow-2xl border border-white/10 p-3.5 flex items-center justify-between gap-4 backdrop-blur-md`}>
        
        <div className="flex items-center gap-3 overflow-hidden">
          <div className="p-2 bg-gradient-to-tr from-amber-500 to-amber-300 text-slate-950 rounded-xl font-black text-[10px] shrink-0 uppercase tracking-widest shadow-md flex items-center gap-1">
            <Tag size={12} />
            {promotion.badge_text || '20% OFF'}
          </div>
          {!isMinimized && (
            <div className="truncate">
              <h4 className="text-xs font-black truncate">{promotion.title}</h4>
              {promotion.subtitle && (
                <p className="text-[11px] opacity-90 truncate font-medium">{promotion.subtitle}</p>
              )}
            </div>
          )}
        </div>

        <div className="flex items-center gap-2 shrink-0">
          {!isMinimized && (
            <button 
              onClick={onOpenStore || (() => window.location.href = '/settings?tab=billing')}
              className="px-3.5 py-1.5 bg-white text-slate-900 hover:bg-slate-100 font-extrabold text-xs rounded-xl shadow-md transition-all flex items-center gap-1.5"
            >
              {promotion.cta_label || 'Ver Oferta'} <ArrowRight size={13} />
            </button>
          )}
          <button 
            onClick={toggleMinimize}
            className="p-1.5 hover:bg-white/10 opacity-80 hover:opacity-100 rounded-lg transition-colors"
            title={isMinimized ? 'Expandir' : 'Minimizar'}
          >
            {isMinimized ? <ChevronUp size={16} /> : <ChevronDown size={16} />}
          </button>
        </div>

      </div>
    </div>
  );
};
