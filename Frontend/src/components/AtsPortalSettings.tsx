import React, { useState, useEffect } from 'react';
import { Globe, Link as LinkIcon, Copy, ExternalLink, QrCode, Check, RefreshCw, Save, Image, Palette, Eye, Type, FileText, Phone, Mail } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { WebPublica } from './WebPublica';

const Facebook = ({ size = 16 }: { size?: number }) => (
  <svg xmlns="http://www.w3.org/2000/svg" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
);
const Instagram = ({ size = 16 }: { size?: number }) => (
  <svg xmlns="http://www.w3.org/2000/svg" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg>
);
const Linkedin = ({ size = 16 }: { size?: number }) => (
  <svg xmlns="http://www.w3.org/2000/svg" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect width="4" height="12" x="2" y="9"/><circle cx="4" cy="4" r="2"/></svg>
);

export default function AtsPortalSettings() {
  const [settings, setSettings] = useState({
    // H12: el estado inicial traía el nombre y el slug de OTRA empresa. Si la carga de la
    // configuración fallaba, el admin veía —y podía GUARDAR— esa marca ajena como suya.
    // En blanco: se rellenan con lo que responda el backend.
    name: '',
    public_slug: '',
    brand_color: '#8b5cf6',
    logo_url: '',
    public_portal_enabled: true,
    custom_settings: {
      header_title: '',
      header_subtitle: '',
      header_bg_url: '',
      footer_text: '',
      contact_email: '',
      contact_phone: '',
      social_facebook: '',
      social_instagram: '',
      social_linkedin: '',
    }
  });

  const [initialSettings, setInitialSettings] = useState(JSON.parse(JSON.stringify(settings)));
  const [vacancies, setVacancies] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [copied, setCopied] = useState(false);
  const [activeFormTab, setActiveFormTab] = useState<'basico' | 'diseno'>('basico');

  // Paleta de colores predefinida y elegante
  const presetColors = [
    { name: 'Morado/Lila', value: '#8b5cf6' },
    { name: 'Azul Real', value: '#3b82f6' },
    { name: 'Esmeralda', value: '#10b981' },
    { name: 'Vino/Terracota', value: '#b91c1c' },
    { name: 'Oro/Ámbar', value: '#d97706' },
    { name: 'Slate oscuro', value: '#1e293b' },
  ];

  useEffect(() => {
    const fetchData = async () => {
      try {
        setLoading(true);
        // 1. Obtener configuraciones del portal
        const settingsRes = await axiosInstance.get('/admin/tenant/portal-settings');
        const fetched = settingsRes.data;
        if (!fetched.custom_settings) {
          fetched.custom_settings = {
            header_title: '',
            header_subtitle: '',
            header_bg_url: '',
            footer_text: '',
            contact_email: '',
            contact_phone: '',
            social_facebook: '',
            social_instagram: '',
            social_linkedin: '',
          };
        }
        setSettings(fetched);
        setInitialSettings(JSON.parse(JSON.stringify(fetched)));

        // 2. Obtener vacantes de la empresa para la vista previa
        const vacanciesRes = await axiosInstance.get('/admin/vacancies');
        setVacancies(vacanciesRes.data || []);
      } catch (err) {
        console.error('Error al cargar datos del portal:', err);
      } finally {
        setLoading(false);
      }
    };
    fetchData();
  }, []);

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      const res = await axiosInstance.put('/admin/tenant/portal-settings', settings);
      const updated = res.data.settings;
      if (!updated.custom_settings) {
        updated.custom_settings = {
          header_title: '',
          header_subtitle: '',
          header_bg_url: '',
          footer_text: '',
          contact_email: '',
          contact_phone: '',
          social_facebook: '',
          social_instagram: '',
          social_linkedin: '',
        };
      }
      setSettings(updated);
      setInitialSettings(JSON.parse(JSON.stringify(updated)));
      alert('Configuración guardada exitosamente.');
    } catch (err: any) {
      console.error('Error al guardar configuración:', err);
      const errMsg = err.response?.data?.message || 'Error al guardar la configuración del portal.';
      alert(errMsg);
    } finally {
      setSaving(false);
    }
  };

  const getPublicLink = () => {
    return `${window.location.origin}/vacantes/${settings.public_slug}`;
  };

  const handleCopyLink = () => {
    navigator.clipboard.writeText(getPublicLink());
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const handleCustomSettingChange = (field: string, value: string) => {
    setSettings(prev => ({
      ...prev,
      custom_settings: {
        ...prev.custom_settings,
        [field]: value
      }
    }));
  };

  const hasChanges = JSON.stringify(settings) !== JSON.stringify(initialSettings);

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[400px] text-slate-500 font-bold gap-3">
        <RefreshCw className="animate-spin text-indigo-600" size={24} />
        Cargando configuración del portal...
      </div>
    );
  }

  return (
    <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 text-left">
      {/* Formulario de Configuración (Columna Izquierda) */}
      <div className="lg:col-span-5 space-y-6">
        <div className="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
          {/* Header */}
          <div className="p-6 border-b border-slate-100 flex items-center gap-3">
            <Globe className="text-indigo-600" size={24} />
            <div>
              <h3 className="font-bold text-slate-800 text-lg">Configuración de Bolsa de Trabajo</h3>
              <p className="text-slate-500 text-xs mt-0.5">Personaliza y gestiona tu portal público de vacantes.</p>
            </div>
          </div>

          {/* Sub-Tabs de Formulario */}
          <div className="flex border-b border-slate-150 bg-slate-50/50">
            <button
              type="button"
              onClick={() => setActiveFormTab('basico')}
              className={`flex-1 text-center py-3 text-xs font-bold border-b-2 transition-all ${activeFormTab === 'basico' ? 'border-indigo-650 text-indigo-650 bg-white font-extrabold' : 'border-transparent text-slate-500 hover:text-slate-700 hover:bg-slate-100/50'}`}
            >
              Ajustes Básicos
            </button>
            <button
              type="button"
              onClick={() => setActiveFormTab('diseno')}
              className={`flex-1 text-center py-3 text-xs font-bold border-b-2 transition-all ${activeFormTab === 'diseno' ? 'border-indigo-650 text-indigo-650 bg-white font-extrabold' : 'border-transparent text-slate-500 hover:text-slate-700 hover:bg-slate-100/50'}`}
            >
              Asistente de Diseño (Banner/Footer)
            </button>
          </div>

          <form onSubmit={handleSave} className="p-6 space-y-5">
            {activeFormTab === 'basico' ? (
              <>
                {/* Activar/Desactivar Portal */}
                <div className="flex items-center justify-between p-4 bg-slate-50 rounded-2xl border border-slate-100">
                  <div className="text-left pr-2">
                    <span className="block font-bold text-slate-800 text-sm">Habilitar Portal Web</span>
                    <span className="block text-[11px] text-slate-400">Si se desactiva, los candidatos verán un mensaje de mantenimiento.</span>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer shrink-0">
                    <input 
                      type="checkbox" 
                      checked={settings.public_portal_enabled}
                      onChange={(e) => setSettings({ ...settings, public_portal_enabled: e.target.checked })}
                      className="sr-only peer"
                    />
                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                  </label>
                </div>

                {/* Slug Público */}
                <div className="space-y-1.5">
                  <label className="block text-xs font-bold text-slate-600 uppercase tracking-wider pl-1">Ruta del Enlace (Slug)</label>
                  <div className="flex rounded-xl overflow-hidden border border-slate-200 shadow-sm focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500">
                    <span className="bg-slate-50 text-slate-400 text-xs px-3 flex items-center border-r border-slate-200 select-none font-mono">
                      /vacantes/
                    </span>
                    <input 
                      type="text" 
                      value={settings.public_slug}
                      onChange={(e) => setSettings({ ...settings, public_slug: e.target.value.toLowerCase().replace(/[^a-z0-9-_]/g, '') })}
                      className="w-full px-3 py-2.5 text-slate-800 text-sm focus:outline-none font-mono"
                      placeholder="decorarte360"
                      required
                    />
                  </div>
                  <span className="text-[10px] text-slate-400 block pl-1">Solo minúsculas, números y guiones. Define la URL del portal.</span>
                </div>

                {/* Logotipo URL */}
                <div className="space-y-1.5">
                  <label className="block text-xs font-bold text-slate-600 uppercase tracking-wider pl-1 flex items-center gap-1.5">
                    <Image size={13} className="text-slate-400" /> URL del Logotipo
                  </label>
                  <input 
                    type="url" 
                    value={settings.logo_url}
                    onChange={(e) => setSettings({ ...settings, logo_url: e.target.value })}
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-200 shadow-sm text-slate-800 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                    placeholder="https://ejemplo.com/logo.png"
                  />
                  <span className="text-[10px] text-slate-400 block pl-1">URL de una imagen PNG/JPG (preferentemente horizontal o cuadrada).</span>
                </div>

                {/* Color de Marca */}
                <div className="space-y-3">
                  <label className="block text-xs font-bold text-slate-600 uppercase tracking-wider pl-1 flex items-center gap-1.5">
                    <Palette size={13} className="text-slate-400" /> Color de Marca
                  </label>
                  
                  {/* Presets */}
                  <div className="grid grid-cols-6 gap-2">
                    {presetColors.map((color) => (
                      <button
                        key={color.value}
                        type="button"
                        onClick={() => setSettings({ ...settings, brand_color: color.value })}
                        className={`h-8 rounded-lg border-2 transition-all flex items-center justify-center ${settings.brand_color.toLowerCase() === color.value.toLowerCase() ? 'border-slate-800 scale-105 shadow-sm' : 'border-transparent hover:scale-102'}`}
                        style={{ backgroundColor: color.value }}
                        title={color.name}
                      >
                        {settings.brand_color.toLowerCase() === color.value.toLowerCase() && (
                          <Check className="text-white drop-shadow-md" size={14} />
                        )}
                      </button>
                    ))}
                  </div>

                  {/* Selector Personalizado */}
                  <div className="flex items-center gap-3 mt-2">
                    <input 
                      type="color" 
                      value={settings.brand_color}
                      onChange={(e) => setSettings({ ...settings, brand_color: e.target.value })}
                      className="w-10 h-8 rounded-lg cursor-pointer border border-slate-200"
                    />
                    <input 
                      type="text" 
                      value={settings.brand_color}
                      onChange={(e) => setSettings({ ...settings, brand_color: e.target.value })}
                      className="w-full px-3 py-1.5 rounded-lg border border-slate-200 text-slate-800 text-xs font-mono"
                      placeholder="#8b5cf6"
                    />
                  </div>
                </div>
              </>
            ) : (
              <>
                {/* Cabecera (Banner) */}
                <div className="space-y-4">
                  <div className="flex items-center gap-1.5 pl-1 border-l-2 border-indigo-500">
                    <span className="text-xs font-bold text-indigo-750 uppercase tracking-wide">Banner Superior (Hero)</span>
                  </div>

                  <div className="space-y-1.5">
                    <label className="block text-[11px] font-bold text-slate-500 uppercase tracking-wide pl-1">Título del Banner</label>
                    <input 
                      type="text" 
                      value={settings.custom_settings.header_title}
                      onChange={(e) => handleCustomSettingChange('header_title', e.target.value)}
                      className="w-full px-4 py-2.5 rounded-xl border border-slate-200 shadow-sm text-slate-800 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                      placeholder={`Únete a ${settings.name}`}
                    />
                  </div>

                  <div className="space-y-1.5">
                    <label className="block text-[11px] font-bold text-slate-500 uppercase tracking-wide pl-1">Subtítulo del Banner</label>
                    <textarea 
                      value={settings.custom_settings.header_subtitle}
                      onChange={(e) => handleCustomSettingChange('header_subtitle', e.target.value)}
                      className="w-full px-4 py-2 rounded-xl border border-slate-200 shadow-sm text-slate-800 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 h-16 resize-none"
                      placeholder="No solo ofrecemos trabajo, construimos carreras. Encuentra tu lugar en nuestra familia."
                    />
                  </div>

                  <div className="space-y-1.5">
                    <label className="block text-[11px] font-bold text-slate-500 uppercase tracking-wide pl-1 flex items-center gap-1">
                      <Image size={11} /> Imagen de Fondo del Banner (URL)
                    </label>
                    <input 
                      type="url" 
                      value={settings.custom_settings.header_bg_url}
                      onChange={(e) => handleCustomSettingChange('header_bg_url', e.target.value)}
                      className="w-full px-4 py-2.5 rounded-xl border border-slate-200 shadow-sm text-slate-800 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                      placeholder="https://ejemplo.com/background.jpg"
                    />
                    <span className="text-[9px] text-slate-400 block pl-1">Se aplicará un sombreado oscuro para asegurar la legibilidad del texto en blanco.</span>
                  </div>
                </div>

                {/* Pie de Página (Footer) */}
                <div className="space-y-4 pt-2 border-t border-slate-100">
                  <div className="flex items-center gap-1.5 pl-1 border-l-2 border-indigo-500">
                    <span className="text-xs font-bold text-indigo-755 uppercase tracking-wide">Pie de Página (Footer)</span>
                  </div>

                  <div className="space-y-1.5">
                    <label className="block text-[11px] font-bold text-slate-500 uppercase tracking-wide pl-1">Texto de Copyright</label>
                    <input 
                      type="text" 
                      value={settings.custom_settings.footer_text}
                      onChange={(e) => handleCustomSettingChange('footer_text', e.target.value)}
                      className="w-full px-4 py-2.5 rounded-xl border border-slate-200 shadow-sm text-slate-800 text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                      placeholder={`© ${new Date().getFullYear()} ${settings.name}. Todos los derechos reservados.`}
                    />
                  </div>

                  <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-1.5">
                      <label className="block text-[11px] font-bold text-slate-500 uppercase tracking-wide pl-1 flex items-center gap-1"><Mail size={11}/> Correo</label>
                      <input 
                        type="email" 
                        value={settings.custom_settings.contact_email}
                        onChange={(e) => handleCustomSettingChange('contact_email', e.target.value)}
                        className="w-full px-3 py-2 rounded-xl border border-slate-200 shadow-sm text-slate-800 text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                        placeholder="contacto@empresa.com"
                      />
                    </div>
                    <div className="space-y-1.5">
                      <label className="block text-[11px] font-bold text-slate-500 uppercase tracking-wide pl-1 flex items-center gap-1"><Phone size={11}/> Teléfono</label>
                      <input 
                        type="text" 
                        value={settings.custom_settings.contact_phone}
                        onChange={(e) => handleCustomSettingChange('contact_phone', e.target.value)}
                        className="w-full px-3 py-2 rounded-xl border border-slate-200 shadow-sm text-slate-800 text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
                        placeholder="+52 55 1234 5678"
                      />
                    </div>
                  </div>

                  {/* Redes Sociales */}
                  <div className="space-y-2">
                    <label className="block text-[11px] font-bold text-slate-550 uppercase tracking-wide pl-1">Redes Sociales (Enlaces)</label>
                    <div className="space-y-2">
                      <div className="flex rounded-xl overflow-hidden border border-slate-200 shadow-sm focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500">
                        <span className="bg-slate-50 text-slate-400 px-3 flex items-center border-r border-slate-200"><Facebook size={12}/></span>
                        <input 
                          type="url" 
                          value={settings.custom_settings.social_facebook}
                          onChange={(e) => handleCustomSettingChange('social_facebook', e.target.value)}
                          className="w-full px-3 py-2 text-slate-800 text-xs focus:outline-none"
                          placeholder="https://facebook.com/pagina"
                        />
                      </div>
                      <div className="flex rounded-xl overflow-hidden border border-slate-200 shadow-sm focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500">
                        <span className="bg-slate-50 text-slate-400 px-3 flex items-center border-r border-slate-200"><Instagram size={12}/></span>
                        <input 
                          type="url" 
                          value={settings.custom_settings.social_instagram}
                          onChange={(e) => handleCustomSettingChange('social_instagram', e.target.value)}
                          className="w-full px-3 py-2 text-slate-800 text-xs focus:outline-none"
                          placeholder="https://instagram.com/perfil"
                        />
                      </div>
                      <div className="flex rounded-xl overflow-hidden border border-slate-200 shadow-sm focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500">
                        <span className="bg-slate-50 text-slate-400 px-3 flex items-center border-r border-slate-200"><Linkedin size={12}/></span>
                        <input 
                          type="url" 
                          value={settings.custom_settings.social_linkedin}
                          onChange={(e) => handleCustomSettingChange('social_linkedin', e.target.value)}
                          className="w-full px-3 py-2 text-slate-800 text-xs focus:outline-none"
                          placeholder="https://linkedin.com/in/perfil"
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </>
            )}

            {/* Botón Guardar */}
            <button
              type="submit"
              disabled={saving || !hasChanges}
              className={`w-full py-3 rounded-xl font-bold text-sm transition-all flex justify-center items-center gap-2 shadow-sm ${
                hasChanges 
                  ? 'bg-indigo-600 hover:bg-indigo-700 text-white cursor-pointer' 
                  : 'bg-slate-100 text-slate-450 border border-slate-200 cursor-not-allowed'
              }`}
            >
              {saving ? <RefreshCw className="animate-spin" size={16} /> : <Save size={16} />}
              Guardar Configuración
            </button>
          </form>
        </div>

        {/* Panel de Enlaces y QR */}
        <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-5">
          <div className="flex items-center gap-2 text-slate-800 font-bold text-sm border-b border-slate-100 pb-3">
            <LinkIcon size={18} className="text-indigo-600" />
            <span>Compartir Portal de Empleos</span>
          </div>

          <div className="space-y-4">
            <div className="p-3 bg-slate-50 border border-slate-200/60 rounded-2xl flex flex-col gap-2">
              <span className="text-[10px] font-bold text-slate-455 uppercase pl-1">Enlace del Portal</span>
              <div className="flex items-center justify-between bg-white px-3 py-2 rounded-xl border border-slate-150 gap-2 overflow-hidden">
                <span className="text-xs text-slate-600 font-mono truncate">{getPublicLink()}</span>
                <div className="flex gap-1.5 shrink-0">
                  <button 
                    onClick={handleCopyLink}
                    className="p-2 bg-slate-50 hover:bg-indigo-50 hover:text-indigo-600 text-slate-500 rounded-lg border border-slate-200 transition-all"
                    title="Copiar Enlace"
                  >
                    {copied ? <Check size={14} className="text-green-600" /> : <Copy size={14} />}
                  </button>
                  <a 
                    href={getPublicLink()}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="p-2 bg-slate-50 hover:bg-indigo-50 hover:text-indigo-600 text-slate-500 rounded-lg border border-slate-200 transition-all flex items-center justify-center"
                    title="Visitar Portal"
                  >
                    <ExternalLink size={14} />
                  </a>
                </div>
              </div>
            </div>

            {/* Código QR */}
            <div className="flex flex-col items-center p-4 bg-slate-50 border border-slate-200/60 rounded-2xl text-center">
              <QrCode className="text-indigo-600 mb-2" size={24} />
              <span className="text-xs font-bold text-slate-800 mb-1">Código QR del Portal</span>
              <p className="text-[10px] text-slate-400 max-w-[200px] mb-3">Imprime este código para colocarlo en tu tienda física o sucursal.</p>
              
              <div className="bg-white p-3 rounded-2xl border border-slate-200 shadow-sm mix-blend-multiply mb-3">
                <img 
                  src={`https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(getPublicLink())}`} 
                  alt="QR Portal" 
                  className="w-36 h-36"
                />
              </div>

              <a 
                href={`https://api.qrserver.com/v1/create-qr-code/?size=1000x1000&data=${encodeURIComponent(getPublicLink())}`}
                target="_blank"
                rel="noopener noreferrer"
                className="text-[10px] font-bold text-indigo-600 hover:text-indigo-800 transition-colors flex items-center gap-1"
              >
                Descargar QR Alta Resolución <ExternalLink size={10} />
              </a>
            </div>
          </div>
        </div>
      </div>

      {/* Vista Previa Interactiva (Columna Derecha) */}
      <div className="lg:col-span-7 space-y-4 flex flex-col">
        <div className="flex items-center justify-between pl-1">
          <div className="flex items-center gap-2 text-slate-800 font-bold">
            <Eye size={18} className="text-indigo-600" />
            <span>Vista Previa del Portal en Vivo</span>
          </div>
          <span className="text-[10px] bg-emerald-50 text-emerald-700 border border-emerald-100 px-2 py-0.5 rounded-full font-bold uppercase tracking-wider animate-pulse flex items-center gap-1">
            <span className="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span> Interactiva
          </span>
        </div>

        {/* Simulador de Navegador */}
        <div className="bg-white border border-slate-200 rounded-3xl overflow-hidden shadow-md flex-grow flex flex-col min-h-[500px]">
          {/* Cabecera del Simulador */}
          <div className="bg-slate-100 border-b border-slate-200 px-4 py-3 flex items-center gap-2 shrink-0 select-none">
            <div className="flex gap-1.5">
              <span className="w-2.5 h-2.5 bg-slate-300 rounded-full"></span>
              <span className="w-2.5 h-2.5 bg-slate-300 rounded-full"></span>
              <span className="w-2.5 h-2.5 bg-slate-300 rounded-full"></span>
            </div>
            <div className="bg-white rounded-lg border border-slate-200/80 px-3 py-1 flex items-center gap-1.5 text-[10px] text-slate-450 font-mono w-full max-w-sm mx-auto justify-center select-all">
              <Globe size={10} className="text-slate-400" />
              <span>talent360.com/vacantes/{settings.public_slug}</span>
            </div>
          </div>

          {/* Contenido Renderizado */}
          <div className="flex-grow overflow-y-auto p-6 bg-slate-50 relative max-h-[70vh]">
            <WebPublica 
              previewTenant={settings} 
              previewVacancies={vacancies}
            />
          </div>
        </div>
      </div>
    </div>
  );
}
