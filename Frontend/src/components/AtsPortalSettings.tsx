import React, { useState, useEffect } from 'react';
import { Globe, Link as LinkIcon, Copy, ExternalLink, QrCode, Check, RefreshCw, Save, Image, Palette, Eye } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { WebPublica } from './WebPublica';

export default function AtsPortalSettings() {
  const [settings, setSettings] = useState({
    name: 'DecorArte 360',
    public_slug: 'decorarte360',
    brand_color: '#8b5cf6',
    logo_url: '',
    public_portal_enabled: true,
  });

  const [initialSettings, setInitialSettings] = useState({ ...settings });
  const [vacancies, setVacancies] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [copied, setCopied] = useState(false);

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
        setSettings(settingsRes.data);
        setInitialSettings(settingsRes.data);

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
      setSettings(res.data.settings);
      setInitialSettings(res.data.settings);
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
        <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-6">
          <div className="flex items-center gap-3 border-b border-slate-100 pb-4">
            <Globe className="text-indigo-600" size={24} />
            <div>
              <h3 className="font-bold text-slate-800 text-lg">Configuración de Bolsa de Trabajo</h3>
              <p className="text-slate-500 text-xs mt-0.5">Personaliza y gestiona tu portal público de vacantes.</p>
            </div>
          </div>

          <form onSubmit={handleSave} className="space-y-5">
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
