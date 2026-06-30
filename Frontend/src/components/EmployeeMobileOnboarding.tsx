import React, { useState } from 'react';
import { Smartphone, CheckCircle2, ChevronRight, Lock, Image as ImageIcon, Video, User, Star } from 'lucide-react';
import axiosInstance from '../lib/axios';

// Tipos de paso en el flujo
type Step = 'pin' | 'welcome' | 'profile' | 'ready';

export const EmployeeMobileOnboarding = ({ onComplete }: { onComplete: () => void }) => {
  const [currentStep, setCurrentStep] = useState<Step>('pin');
  const [pin, setPin] = useState(() => {
    const urlPin = new URLSearchParams(window.location.search).get('pin') || '';
    if (urlPin.length === 6) {
      return urlPin.split('');
    }
    return ['', '', '', '', '', ''];
  });
  const [isLoading, setIsLoading] = useState(false);
  const [verifiedUser, setVerifiedUser] = useState<any>(null);
  const [aliasName, setAliasName] = useState('');
  const [companyConfig, setCompanyConfig] = useState({
    welcomeTitle: '¡Bienvenido a Talent 360!',
    welcomeMessage: 'Estamos muy emocionados de que te unas a nuestro equipo. Aquí encontrarás tus herramientas, rutinas y medios de comunicación oficiales.',
    welcomeVideoUrl: '', 
    welcomeImageUrl: 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80'
  });

  const handlePinChange = (index: number, value: string) => {
    if (value.length > 1) return; // Solo un dígito
    const newPin = [...pin];
    newPin[index] = value;
    setPin(newPin);

    // Autoadelantar al siguiente input
    if (value && index < 5) {
      const nextInput = document.getElementById(`pin-${index + 1}`);
      nextInput?.focus();
    }
  };

  const verifyPin = async () => {
    setIsLoading(true);
    try {
      const pinString = pin.join('');
      const res = await axiosInstance.post('/public/onboarding/verify', { pin: pinString });
      setIsLoading(false);
      setVerifiedUser(res.data.user);
      setAliasName(res.data.user.name || '');
      setCompanyConfig(res.data.company);
      setCurrentStep('welcome');
    } catch (err: any) {
      setIsLoading(false);
      alert(err.response?.data?.message || "Error al verificar el PIN. Intente de nuevo.");
    }
  };

  const completeActivation = async () => {
    setIsLoading(true);
    try {
      await axiosInstance.post('/public/onboarding/complete', {
        user_id: verifiedUser?.id,
        pin: pin.join(''),
        name: aliasName,
        avatar: null
      });
      setIsLoading(false);
      setCurrentStep('ready');
    } catch (err: any) {
      setIsLoading(false);
      alert(err.response?.data?.message || "Error al activar la cuenta.");
    }
  };

  return (
    <div className="fixed inset-0 bg-slate-900 flex items-center justify-center p-4 z-50">
      
      {/* Contenedor tipo Celular para forzar la vista móvil */}
      <div className="w-full max-w-[400px] h-[800px] max-h-[90vh] bg-white rounded-[2.5rem] relative overflow-hidden shadow-2xl flex flex-col">
        {/* StatusBar falso para simular entorno nativo */}
        <div className="h-7 bg-white w-full flex items-center px-6 justify-between text-[10px] font-medium text-slate-800 z-10">
           <span>9:41</span>
           <div className="flex items-center gap-1">
              <span className="w-4 h-3 rounded-sm border border-slate-800 flex items-center justify-center">
                 <span className="w-2.5 h-1.5 bg-slate-800 rounded-sm"></span>
              </span>
           </div>
        </div>

        {/* --- PASO 1: Ingreso de PIN --- */}
        {currentStep === 'pin' && (
          <div className="flex-1 flex flex-col items-center justify-center p-8 animate-in fade-in slide-in-from-bottom-4">
            <div className="w-16 h-16 bg-blue-100 rounded-2xl flex items-center justify-center mb-6 shadow-sm">
              <Lock size={32} className="text-blue-600" />
            </div>
            
            <h1 className="text-2xl font-black text-slate-800 mb-2 text-center">Activación de Cuenta</h1>
            <p className="text-sm text-slate-500 text-center mb-8">
              Ingresa el PIN de 6 dígitos que recibiste por WhatsApp o Correo.
            </p>

            <div className="flex gap-2 mb-8">
              {pin.map((digit, i) => (
                <input
                  key={i}
                  id={`pin-${i}`}
                  type="text"
                  inputMode="numeric"
                  value={digit}
                  onChange={(e) => handlePinChange(i, e.target.value.replace(/[^0-9]/g, ''))}
                  className="w-10 h-12 border-2 border-slate-200 rounded-xl text-center text-xl font-bold focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all"
                  maxLength={1}
                />
              ))}
            </div>

            <button 
              onClick={verifyPin}
              disabled={pin.some(d => d === '') || isLoading}
              className="w-full bg-blue-600 text-white font-bold py-3.5 rounded-xl flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-blue-700 transition-colors shadow-lg shadow-blue-500/30"
            >
              {isLoading ? (
                <span className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
              ) : 'Verificar y Entrar'}
            </button>
          </div>
        )}


        {/* --- PASO 2: Pantalla de Bienvenida Corporativa --- */}
        {currentStep === 'welcome' && (
          <div className="flex-1 flex flex-col animate-in fade-in zoom-in-95 duration-500">
            {companyConfig.welcomeImageUrl ? (
               <div className="w-full h-64 bg-slate-200 relative">
                  <img src={companyConfig.welcomeImageUrl} alt="Welcome" className="w-full h-full object-cover" />
                  <div className="absolute inset-0 bg-gradient-to-t from-slate-900 to-transparent"></div>
               </div>
            ) : (
               <div className="w-full h-64 bg-gradient-to-br from-blue-600 to-indigo-800 relative flex items-center justify-center">
                  <Star size={48} className="text-white/20 absolute" />
               </div>
            )}

            <div className="flex-1 bg-white p-6 -mt-6 rounded-t-[2rem] flex flex-col relative z-10 shadow-[0_-10px_20px_rgba(0,0,0,0.1)]">
               <h2 className="text-2xl font-black text-slate-800 mb-3">{companyConfig.welcomeTitle}</h2>
               
               <div className="bg-slate-50 p-4 rounded-xl border border-slate-100 mb-auto">
                  <p className="text-slate-600 text-sm leading-relaxed font-medium">
                     {companyConfig.welcomeMessage}
                  </p>
               </div>

               <button 
                  onClick={() => setCurrentStep('profile')}
                  className="w-full bg-slate-900 text-white font-bold py-4 rounded-xl flex items-center justify-center gap-2 hover:bg-slate-800 transition-colors mt-6"
               >
                  Continuar
                  <ChevronRight size={20} />
               </button>
            </div>
          </div>
        )}


        {/* --- PASO 3: Configurar Perfil --- */}
        {currentStep === 'profile' && (
          <div className="flex-1 flex flex-col p-8 animate-in slide-in-from-right-4">
             <h2 className="text-2xl font-black text-slate-800 mb-2">Prepara tu perfil</h2>
             <p className="text-sm text-slate-500 mb-8">Sube una foto clara para que tus compañeros puedan reconocerte.</p>

             <div className="flex-1 flex flex-col items-center">
                <div className="w-32 h-32 bg-slate-100 rounded-full border-4 border-white shadow-xl flex flex-col items-center justify-center text-slate-400 mb-6 relative group overflow-hidden cursor-pointer">
                   <User size={40} className="mb-1" />
                   <span className="text-[10px] font-bold">Subir Foto</span>
                   <div className="absolute inset-0 bg-black/40 hidden group-hover:flex items-center justify-center">
                      <ImageIcon className="text-white" />
                   </div>
                </div>

                <div className="w-full space-y-4">
                   <div>
                      <label className="text-xs font-bold text-slate-500 uppercase tracking-widest ml-1 mb-1 block">Tu Nombre (Alias)</label>
                      <input 
                        type="text" 
                        value={aliasName} 
                        onChange={(e) => setAliasName(e.target.value)}
                        className="w-full bg-slate-50 border border-slate-200 px-4 py-3 rounded-xl font-bold text-slate-800 focus:ring-2 focus:ring-blue-500 focus:outline-none" 
                      />
                   </div>
                </div>
             </div>

             <button 
               onClick={completeActivation}
               disabled={!aliasName || isLoading}
               className="w-full bg-blue-600 text-white font-bold py-4 rounded-xl flex items-center justify-center gap-2 shadow-lg shadow-blue-500/30 disabled:opacity-50"
             >
                {isLoading ? (
                  <span className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                ) : 'Guardar y Finalizar'}
             </button>
          </div>
        )}

        {/* --- PASO 4: Listo --- */}
        {currentStep === 'ready' && (
          <div className="flex-1 flex flex-col items-center justify-center p-8 text-center animate-in zoom-in">
             <div className="w-24 h-24 bg-emerald-100 text-emerald-500 rounded-full flex items-center justify-center mb-6">
                <CheckCircle2 size={48} />
             </div>
             <h2 className="text-3xl font-black text-slate-800 mb-2">¡Todo Listo!</h2>
             <p className="text-slate-500 mb-10">Tu aplicación está configurada y conectada a la red de tu empresa.</p>

             <button 
               onClick={onComplete}
               className="w-full bg-slate-900 text-white font-bold py-4 rounded-xl flex items-center justify-center gap-2"
             >
                Ir a mi espacio de trabajo
                <ChevronRight size={20} />
             </button>
          </div>
        )}

      </div>
    </div>
  );
};
