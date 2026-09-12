import React from 'react';
import { X, Building2, ShieldCheck, Sparkles } from 'lucide-react';
import { SaaSAccountSettings } from './SaaSAccountSettings';

interface CompanyProfileModalProps {
  isOpen: boolean;
  onClose: () => void;
  initialTab?: 'profile' | 'billing' | 'modules' | 'backups';
}

export const CompanyProfileModal: React.FC<CompanyProfileModalProps> = ({
  isOpen,
  onClose,
  initialTab = 'profile'
}) => {
  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200 overflow-y-auto">
      <div className="bg-page border border-border w-full max-w-6xl max-h-[92vh] rounded-3xl shadow-2xl flex flex-col overflow-hidden my-auto">
        {/* Modal Header */}
        <div className="px-6 py-4 bg-white border-b border-border flex items-center justify-between shrink-0">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-2xl bg-accent/10 border border-border flex items-center justify-center text-accent shadow-sm">
              <Building2 size={22} />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h2 className="text-lg font-black text-text-1 tracking-tight">Perfil de la Empresa & Licencias</h2>
                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-accent-soft text-accent border border-border">
                  <ShieldCheck size={11} /> Enterprise Tenant
                </span>
              </div>
              <p className="text-xs text-text-3 font-medium">Gestión institucional de cuenta, facturación, activación de módulos y respaldos del sistema.</p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <button
              onClick={() => {
                onClose();
                window.dispatchEvent(new CustomEvent('open-onboarding-wizard'));
              }}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-navy-50 text-accent hover:bg-accent-soft border border-border transition-colors shadow-sm"
              title="Reconfigurar giro comercial, puestos y tareas"
            >
              <Sparkles size={14} /> Wizard de Giro
            </button>

            <button
              onClick={onClose}
              className="p-2 rounded-xl text-slate-400 hover:text-text-2 hover:bg-page transition-colors"
              title="Cerrar"
            >
              <X size={20} />
            </button>
          </div>
        </div>

        {/* Modal Body */}
        <div className="flex-1 overflow-y-auto p-4 sm:p-6 custom-scrollbar bg-page">
          <SaaSAccountSettings initialTab={initialTab} />
        </div>
      </div>
    </div>
  );
};
