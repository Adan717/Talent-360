import React, { useState, useEffect } from 'react';
import { Save, Image as ImageIcon, Video, Link, MessageSquare, Smartphone, CheckCircle2, ClipboardList, Check } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';

export const CompanyOnboardingSettings = ({ onComplete }: { onComplete?: () => void }) => {
  const [currentStep, setCurrentStep] = useState(1); // 1: Welcome Settings, 2: Import Job Roles
  
  // Step 1 State
  const [isSaving, setIsSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [formData, setFormData] = useState({
    welcomeTitle: '¡Bienvenido al Equipo!',
    welcomeMessage: 'Estamos muy emocionados de que te unas a nosotros. En esta plataforma podrás acceder a tus herramientas diarias.',
    welcomeImageUrl: '',
    welcomeVideoUrl: '',
    inviteLinkMode: 'whatsapp' // 'whatsapp' or 'email'
  });

  // Step 2 State
  const [templates, setTemplates] = useState<any[]>([]);
  const [loadingTemplates, setLoadingTemplates] = useState(false);
  const [selectedTemplates, setSelectedTemplates] = useState<number[]>([]);
  const [industryFilter, setIndustryFilter] = useState('retail'); // retail by default
  const [isImporting, setIsImporting] = useState(false);

  useEffect(() => {
    const loadSettings = async () => {
      try {
        const res = await axiosInstance.get('/admin/onboarding/settings');
        if (res.data) {
          setFormData(prev => ({
            ...prev,
            welcomeTitle: res.data.welcomeTitle || prev.welcomeTitle,
            welcomeMessage: res.data.welcomeMessage || prev.welcomeMessage,
            welcomeImageUrl: res.data.welcomeImageUrl || prev.welcomeImageUrl,
            welcomeVideoUrl: res.data.welcomeVideoUrl || prev.welcomeVideoUrl,
          }));
        }
      } catch (err) {
        console.error("Failed to load onboarding settings", err);
      }
    };
    loadSettings();
  }, []);

  const fetchTemplates = async (ind: string) => {
    try {
      setLoadingTemplates(true);
      const url = ind ? `/job-role-templates?industry=${ind}` : '/job-role-templates';
      const res = await axiosInstance.get(url);
      setTemplates(res.data || []);
      setSelectedTemplates([]); // reset selection
    } catch (e) {
      console.error("Failed to fetch templates", e);
    } finally {
      setLoadingTemplates(false);
    }
  };

  useEffect(() => {
    if (currentStep === 2) {
      fetchTemplates(industryFilter);
    }
  }, [currentStep, industryFilter]);

  const handleSave = async () => {
    setIsSaving(true);
    try {
      await axiosInstance.post('/admin/onboarding/settings', formData);
      setIsSaving(false);
      setSaved(true);
      setTimeout(() => {
        setSaved(false);
        setCurrentStep(2); // Go to step 2 instead of calling onComplete
      }, 1000);
    } catch (err) {
      console.error(err);
      setIsSaving(false);
      alert("Error al guardar la configuración de bienvenida");
    }
  };

  const toggleSelectTemplate = (id: number) => {
    if (selectedTemplates.includes(id)) {
      setSelectedTemplates(selectedTemplates.filter(item => item !== id));
    } else {
      setSelectedTemplates([...selectedTemplates, id]);
    }
  };

  const handleBulkImport = async () => {
    if (selectedTemplates.length === 0) return;
    try {
      setIsImporting(true);
      const appState = useAppStore.getState();
      if (appState.isSandboxMode) {
          alert(`Importación simulada: ${selectedTemplates.length} puestos agregados.`);
          if (onComplete) onComplete();
          return;
      }
      await Promise.all(
        selectedTemplates.map(id => 
          axiosInstance.post(`/job-role-templates/${id}/import`)
        )
      );
      alert("Puestos importados exitosamente.");
      if (onComplete) onComplete();
    } catch (e) {
      console.error("Failed to import templates", e);
      alert("Ocurrió un error al importar los puestos seleccionados.");
    } finally {
      setIsImporting(false);
    }
  };

  const handleSkipOrFinish = () => {
    if (onComplete) onComplete();
  };

  return (
    <div className="space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
      
      {/* Wizard Header / Steps Indicator */}
      <div className="bg-slate-50 px-6 py-4 border-b border-slate-200 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="w-8 h-8 rounded-lg bg-blue-600 text-white flex items-center justify-center font-bold text-sm">
            {currentStep}
          </div>
          <div>
            <h3 className="font-bold text-slate-800 text-sm">Asistente de Configuración</h3>
            <p className="text-slate-500 text-xs font-semibold">Paso {currentStep} de 2 &bull; {currentStep === 1 ? 'Personalizar Bienvenida' : 'Estructura Organizacional'}</p>
          </div>
        </div>
        <div className="flex gap-1">
          <div className={`h-1.5 w-6 rounded-full transition-all ${currentStep === 1 ? 'bg-blue-600' : 'bg-slate-200'}`} />
          <div className={`h-1.5 w-6 rounded-full transition-all ${currentStep === 2 ? 'bg-blue-600' : 'bg-slate-200'}`} />
        </div>
      </div>

      {currentStep === 1 && (
        <div className="p-6 pt-0 space-y-6">
          <div className="bg-white p-0">
            <h2 className="text-xl font-black text-slate-800 mb-2">Configuración de Onboarding</h2>
            <p className="text-sm text-slate-500 mb-6">Personaliza la experiencia de bienvenida para los nuevos empleados cuando abren la App por primera vez.</p>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
              
              {/* Formulario de Configuración */}
              <div className="space-y-5">
                <div>
                  <label className="block text-sm font-bold text-slate-700 mb-1">Título de Bienvenida</label>
                  <input 
                    type="text" 
                    value={formData.welcomeTitle}
                    onChange={(e) => setFormData({...formData, welcomeTitle: e.target.value})}
                    className="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:outline-none transition-all"
                    placeholder="Ej. ¡Bienvenido a Talent 360!"
                  />
                </div>

                <div>
                  <label className="block text-sm font-bold text-slate-700 mb-1">Mensaje de Bienvenida</label>
                  <textarea 
                    rows={4}
                    value={formData.welcomeMessage}
                    onChange={(e) => setFormData({...formData, welcomeMessage: e.target.value})}
                    className="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:outline-none transition-all resize-none"
                    placeholder="Escribe unas palabras motivadoras para los nuevos ingresos..."
                  />
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-bold text-slate-700 mb-1 flex items-center gap-2">
                      <ImageIcon size={16} className="text-slate-400" />
                      URL de Imagen
                    </label>
                    <input 
                      type="text" 
                      value={formData.welcomeImageUrl}
                      onChange={(e) => setFormData({...formData, welcomeImageUrl: e.target.value})}
                      className="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:outline-none transition-all text-sm"
                      placeholder="https://..."
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-bold text-slate-700 mb-1 flex items-center gap-2">
                      <Video size={16} className="text-slate-400" />
                      URL de Video
                    </label>
                    <input 
                      type="text" 
                      value={formData.welcomeVideoUrl}
                      onChange={(e) => setFormData({...formData, welcomeVideoUrl: e.target.value})}
                      className="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:outline-none transition-all text-sm"
                      placeholder="YouTube / Vimeo URL"
                    />
                  </div>
                </div>

                <div className="pt-4 border-t border-slate-100 flex items-center justify-between">
                  <button 
                    onClick={handleSave}
                    disabled={isSaving}
                    className="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-xl font-bold transition-all shadow-sm flex items-center gap-2 disabled:opacity-70 disabled:cursor-not-allowed"
                  >
                    {isSaving ? (
                      <span className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                    ) : (
                      <Save size={18} />
                    )}
                    {isSaving ? 'Guardando...' : 'Guardar y Continuar'}
                  </button>

                  {saved && (
                    <span className="text-emerald-600 text-sm font-bold flex items-center gap-1 animate-in fade-in">
                      <CheckCircle2 size={16} /> Guardado con éxito
                    </span>
                  )}
                </div>
              </div>

              {/* Vista Previa del Celular */}
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200 flex flex-col items-center justify-center">
                <h3 className="text-sm font-bold text-slate-500 mb-4 uppercase tracking-widest text-xs">Vista Previa Móvil</h3>
                
                <div className="w-[280px] h-[520px] bg-white rounded-[2.5rem] border-[8px] border-slate-900 shadow-xl overflow-hidden relative flex flex-col scale-95 origin-center">
                  {/* Notch */}
                  <div className="absolute top-0 inset-x-0 h-4 bg-slate-900 rounded-b-xl w-28 mx-auto z-10" />
                  
                  {/* Contenido Preview */}
                  <div className="flex-1 overflow-y-auto p-5 flex flex-col pt-10">
                    <div className="text-center mb-5">
                      <div className="w-12 h-12 bg-blue-100 text-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-3 shadow-sm">
                        <span className="text-xl font-black">T</span>
                      </div>
                      <h1 className="text-lg font-black text-slate-800 leading-tight">{formData.welcomeTitle || 'Título de Bienvenida'}</h1>
                    </div>

                    {formData.welcomeVideoUrl ? (
                      <div className="w-full aspect-video bg-slate-200 rounded-xl mb-4 flex items-center justify-center">
                        <Video size={28} className="text-slate-400" />
                      </div>
                    ) : formData.welcomeImageUrl ? (
                      <div className="w-full aspect-video bg-slate-200 rounded-xl mb-4 overflow-hidden">
                        <img src={formData.welcomeImageUrl} alt="Welcome" className="w-full h-full object-cover" />
                      </div>
                    ) : null}

                    <div className="bg-slate-50 p-4 rounded-xl border border-slate-100 text-center mb-auto">
                      <p className="text-xs text-slate-600 leading-relaxed">
                        {formData.welcomeMessage || 'El mensaje de bienvenida aparecerá aquí...'}
                      </p>
                    </div>

                    <div className="mt-4 pt-3 border-t border-slate-100">
                      <button className="w-full bg-blue-600 text-white py-2.5 rounded-xl font-bold shadow-sm opacity-50 cursor-default text-xs">
                        Comenzar
                      </button>
                    </div>
                  </div>
                </div>
              </div>

            </div>
          </div>

          {/* Enlaces de Invitación Masiva */}
          <div className="bg-white p-6 border border-slate-200 rounded-2xl">
             <h2 className="text-lg font-black text-slate-800 mb-2">Generar Enlaces de Invitación</h2>
             <p className="text-sm text-slate-500 mb-6">Envía a tus empleados su PIN y enlace único para activar su App de Empleado.</p>

             <div className="flex gap-4 flex-col sm:flex-row">
                <button className="flex-1 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-700 p-4 rounded-xl flex flex-col items-center justify-center gap-2 transition-colors group">
                   <div className="w-10 h-10 bg-emerald-100 rounded-full flex items-center justify-center group-hover:scale-110 transition-transform">
                      <MessageSquare size={20} className="text-emerald-600" />
                   </div>
                   <span className="font-bold text-sm">Enviar vía WhatsApp</span>
                   <span className="text-xs text-emerald-600 text-center px-4">Utiliza la API oficial de WhatsApp para envíos masivos.</span>
                </button>
                <button className="flex-1 bg-blue-50 hover:bg-blue-100 border border-blue-200 text-blue-700 p-4 rounded-xl flex flex-col items-center justify-center gap-2 transition-colors group">
                   <div className="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center group-hover:scale-110 transition-transform">
                      <Link size={20} className="text-blue-600" />
                   </div>
                   <span className="font-bold text-sm">Enviar vía Email</span>
                   <span className="text-xs text-blue-600 text-center px-4">Utiliza SendGrid para notificaciones masivas.</span>
                </button>
             </div>
          </div>
        </div>
      )}

      {currentStep === 2 && (
        <div className="p-6 pt-0 space-y-6">
          <div>
            <h2 className="text-xl font-black text-slate-800 mb-2 flex items-center gap-2">
              <ClipboardList className="text-blue-600" size={24} />
              Importar Estructura de Puestos
            </h2>
            <p className="text-sm text-slate-500 mb-6">Para arrancar de inmediato, te sugerimos importar puestos del catálogo global. Así tendrás horarios, tolerancias y configuraciones listas.</p>
            
            {/* Industry Selector */}
            <div className="mb-6 bg-slate-50 p-4 rounded-2xl border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div>
                <span className="text-sm font-bold text-slate-800 block">Tu Industria</span>
                <span className="text-xs text-slate-500 font-semibold">Selecciona tu industria para cargar plantillas especializadas.</span>
              </div>
              <select 
                value={industryFilter} 
                onChange={(e) => setIndustryFilter(e.target.value)}
                className="bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all cursor-pointer"
              >
                <option value="retail">Retail (Recomendado)</option>
                <option value="oficina">Oficinas / Corporativos</option>
                <option value="restaurante">Restaurantes / Alimentos</option>
                <option value="manufactura">Manufactura / Taller</option>
                <option value="salud">Servicios de Salud</option>
                <option value="educacion">Educación</option>
              </select>
            </div>

            {/* Templates List */}
            <div className="min-h-[250px] max-h-[350px] overflow-y-auto border border-slate-200 rounded-2xl p-4 bg-slate-50/50 space-y-3 custom-scrollbar mb-8">
              {loadingTemplates ? (
                <div className="h-40 flex items-center justify-center">
                  <span className="w-8 h-8 border-4 border-blue-600/30 border-t-blue-600 rounded-full animate-spin" />
                </div>
              ) : templates.length === 0 ? (
                <div className="h-40 flex flex-col items-center justify-center text-slate-400 font-bold">
                  <span>No hay plantillas disponibles para esta industria.</span>
                </div>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {templates.map(tpl => {
                    const isSelected = selectedTemplates.includes(tpl.id);
                    return (
                      <div 
                        key={tpl.id}
                        onClick={() => toggleSelectTemplate(tpl.id)}
                        className={`p-4 border rounded-2xl cursor-pointer transition-all flex flex-col justify-between h-[150px] ${
                          isSelected 
                            ? 'border-blue-500 bg-blue-50/30 shadow-sm' 
                            : 'border-slate-200 bg-white hover:border-slate-350 hover:shadow-sm'
                        }`}
                      >
                        <div className="flex justify-between items-start">
                          <div>
                            <h4 className="font-bold text-slate-800 text-sm leading-snug">{tpl.name}</h4>
                            <p className="text-slate-400 text-[10px] font-bold uppercase tracking-wider mt-0.5">{tpl.area}</p>
                          </div>
                          <div className={`w-5 h-5 rounded-full border flex items-center justify-center transition-all ${
                            isSelected ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300'
                          }`}>
                            {isSelected && <Check size={12} strokeWidth={3} />}
                          </div>
                        </div>

                        <div className="space-y-1 mt-auto">
                          <div className="flex justify-between text-[11px] text-slate-500 font-semibold">
                            <span>Horario:</span>
                            <span className="text-slate-700 font-bold">{tpl.default_schedule_start} - {tpl.default_schedule_end}</span>
                          </div>
                          <div className="flex justify-between text-[11px] text-slate-500 font-semibold">
                            <span>Tolerancia / Comida:</span>
                            <span className="text-slate-700 font-bold">{tpl.default_tolerance_mins} / {tpl.default_meal_mins}m</span>
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>

            {/* Actions */}
            <div className="flex gap-4 border-t border-slate-100 pt-6">
              <button 
                onClick={handleSkipOrFinish}
                disabled={isImporting}
                className="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-3 rounded-xl transition-all disabled:opacity-50"
              >
                Saltar y Finalizar
              </button>
              <button 
                onClick={handleBulkImport}
                disabled={selectedTemplates.length === 0 || isImporting}
                className={`flex-1 text-white font-bold py-3 rounded-xl transition-all flex items-center justify-center gap-2 ${
                  selectedTemplates.length === 0 || isImporting
                    ? 'bg-slate-300 cursor-not-allowed'
                    : 'bg-blue-600 hover:bg-blue-700 shadow-md shadow-blue-500/20'
                }`}
              >
                {isImporting ? (
                  <span className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                ) : null}
                {isImporting ? 'Importando...' : `Importar y Finalizar (${selectedTemplates.length})`}
              </button>
            </div>

          </div>
        </div>
      )}

    </div>
  );
};
