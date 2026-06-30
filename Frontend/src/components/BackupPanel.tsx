import React, { useState } from 'react';
import { Database, Download, Upload, CloudLightning, ShieldAlert, CheckCircle2, AlertTriangle, RefreshCw, LogIn } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';

export function BackupPanel() {
  const { currentTier, currentUser, fetchState, isFeatureUnlocked } = useAppStore();
  const [loading, setLoading] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);
  
  // Google Drive Simulation States
  const [gdriveConnected, setGdriveConnected] = useState(false);
  const [gdriveAutoSync, setGdriveAutoSync] = useState(false);
  const [syncingGdrive, setSyncingGdrive] = useState(false);
  const [gdriveFiles, setGdriveFiles] = useState<Array<{ id: string, name: string, date: string, size: string }>>([]);

  const isTrialActive = () => {
    const tenant = currentUser?.tenant;
    if (!tenant) return false;
    if (tenant.subscription_status === 'trial' || !tenant.subscription_status) {
      if (tenant.trial_ends_at) {
        const endsAt = new Date(tenant.trial_ends_at);
        return endsAt.getTime() > Date.now();
      }
    }
    return false;
  };

  const isFreemiumExpired = !isFeatureUnlocked('system_backups');

  const handleExport = async () => {
    setLoading(true);
    setErrorMsg(null);
    setSuccessMsg(null);
    try {
      const res = await axiosInstance.get('/tenant/backup/export');
      // Create blob and download
      const jsonStr = JSON.stringify(res.data, null, 2);
      const blob = new Blob([jsonStr], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `talent360_backup_${new Date().toISOString().slice(0,10)}_${Math.floor(Date.now() / 1000)}.json`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      setSuccessMsg('Copia de seguridad exportada con éxito.');
    } catch (e: any) {
      console.error(e);
      setErrorMsg(e.response?.data?.message || 'Error al exportar los datos.');
    } finally {
      setLoading(false);
    }
  };

  const handleImport = async (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) return;

    setLoading(true);
    setErrorMsg(null);
    setSuccessMsg(null);

    const reader = new FileReader();
    reader.onload = async (e) => {
      try {
        const text = e.target?.result as string;
        const res = await axiosInstance.post('/tenant/backup/import', {
          backup_json: text
        });
        setSuccessMsg(res.data.message || 'Copia de seguridad restaurada con éxito.');
        // Refresh local store state after import
        await fetchState();
      } catch (err: any) {
        console.error(err);
        setErrorMsg(err.response?.data?.message || 'Firma digital corrupta o archivo inválido.');
      } finally {
        setLoading(false);
      }
    };
    reader.readAsText(file);
    // Reset file input value
    event.target.value = '';
  };

  const connectGoogleDrive = () => {
    setLoading(true);
    setTimeout(() => {
      setGdriveConnected(true);
      setLoading(false);
      setGdriveFiles([
        { id: '1', name: 'talent360_backup_initial.json', date: new Date(Date.now() - 86400000 * 3).toISOString().slice(0, 10), size: '142 KB' },
        { id: '2', name: 'talent360_backup_weekly.json', date: new Date(Date.now() - 86400000).toISOString().slice(0, 10), size: '205 KB' }
      ]);
      setSuccessMsg('Conectado a Google Drive exitosamente (Simulado).');
    }, 1000);
  };

  const handleGoogleSync = async () => {
    setSyncingGdrive(true);
    setErrorMsg(null);
    setSuccessMsg(null);
    try {
      const res = await axiosInstance.post('/tenant/backup/google-sync', {
        google_token: 'simulated_oauth2_token_abc123'
      });
      if (res.data && res.data.status === 'success') {
        setGdriveFiles(prev => [
          { 
            id: res.data.file_id, 
            name: res.data.filename, 
            date: res.data.synced_at.slice(0, 10), 
            size: '248 KB' 
          },
          ...prev
        ]);
        setSuccessMsg(res.data.message);
      }
    } catch (e: any) {
      console.error(e);
      setErrorMsg(e.response?.data?.message || 'Error en la sincronización con Google Drive.');
    } finally {
      setSyncingGdrive(false);
    }
  };

  return (
    <div className="relative w-full">
      {/* Background Glow */}
      <div className="absolute -top-10 -right-10 w-80 h-80 bg-blue-500/5 rounded-full blur-[80px] pointer-events-none"></div>

      <div className="flex items-center gap-3 mb-6">
        <div className="p-3 bg-blue-50 text-blue-600 rounded-2xl border border-blue-100 shadow-sm">
          <Database size={24} />
        </div>
        <div className="text-left">
          <h1 className="text-2xl font-black text-slate-800 tracking-tight">Respaldos y Seguridad</h1>
          <p className="text-xs text-slate-500 font-medium">Exporta tu información con firma criptográfica HMAC y sincroniza tu base de datos con la nube.</p>
        </div>
      </div>

      {errorMsg && (
        <div className="mb-6 p-4 bg-rose-50 border border-rose-200 rounded-2xl flex gap-3 items-start animate-in fade-in">
          <AlertTriangle className="text-rose-500 shrink-0 mt-0.5" size={18} />
          <div className="text-left">
            <span className="font-bold text-rose-800 text-sm">Fallo de seguridad o integridad</span>
            <p className="text-rose-700 text-xs mt-0.5 leading-relaxed">{errorMsg}</p>
          </div>
        </div>
      )}

      {successMsg && (
        <div className="mb-6 p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex gap-3 items-start animate-in fade-in">
          <CheckCircle2 className="text-emerald-500 shrink-0 mt-0.5" size={18} />
          <div className="text-left">
            <span className="font-bold text-emerald-800 text-sm">Operación exitosa</span>
            <p className="text-emerald-700 text-xs mt-0.5 leading-relaxed">{successMsg}</p>
          </div>
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 relative">
        {/* Enforce Freemium Locked Overlay */}
        {isFreemiumExpired && (
          <div className="absolute inset-0 bg-slate-900/10 backdrop-blur-md rounded-3xl z-40 flex flex-col items-center justify-center p-6 text-center border border-slate-200/50 shadow-sm">
            <div className="w-14 h-14 bg-white border border-slate-200 rounded-2xl flex items-center justify-center mb-4 text-slate-700 shadow-sm animate-bounce">
              <Database size={28} />
            </div>
            <h2 className="text-lg font-black text-slate-800 tracking-tight leading-none mb-1">
              Copias de Seguridad Bloqueadas
            </h2>
            <p className="text-slate-500 text-xs max-w-sm leading-relaxed mb-5">
              Los respaldos firmados localmente y la sincronización en la nube con Google Drive son exclusivos del Plan Profesional e Ilimitado.
            </p>
            <button
              onClick={() => window.dispatchEvent(new CustomEvent('open-pricing-modal'))}
              className="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider rounded-xl transition-all duration-200 shadow-md"
            >
              Ver Planes de Actualización
            </button>
          </div>
        )}

        {/* Column 1: Local Backup Actions */}
        <div className="lg:col-span-2 space-y-6">
          <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm text-left">
            <h3 className="text-sm font-black text-slate-800 uppercase tracking-wider mb-2">Exportar Respaldo Completo</h3>
            <p className="text-xs text-slate-500 leading-relaxed mb-6">
              Descarga un archivo JSON cifrado y firmado con un código HMAC SHA-256 generado por tu llave privada del servidor (`APP_KEY`). Esto impide la alteración o inyección maliciosa de registros antes de la importación.
            </p>

            <button
              disabled={loading || isFreemiumExpired}
              onClick={handleExport}
              className="flex items-center justify-center gap-2 px-6 py-3.5 bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs uppercase tracking-wider rounded-2xl shadow-sm transition-colors disabled:opacity-50"
            >
              {loading ? (
                <>
                  <RefreshCw size={16} className="animate-spin" /> Procesando...
                </>
              ) : (
                <>
                  <Download size={16} /> Descargar Respaldo Firmado (.json)
                </>
              )}
            </button>
          </div>

          <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm text-left">
            <h3 className="text-sm font-black text-slate-800 uppercase tracking-wider mb-2">Restaurar Copia de Seguridad</h3>
            <p className="text-xs text-slate-500 leading-relaxed mb-6">
              Sube el archivo JSON que exportaste anteriormente. El sistema verificará de forma segura la firma digital antes de limpiar las tablas del tenant y reinsertar los datos estructurados.
            </p>

            <label className={`inline-flex items-center gap-2 px-6 py-3.5 border border-slate-300 hover:border-slate-400 bg-white text-slate-700 font-bold text-xs uppercase tracking-wider rounded-2xl shadow-sm transition-colors cursor-pointer ${loading || isFreemiumExpired ? 'opacity-50 cursor-not-allowed' : ''}`}>
              <Upload size={16} /> Cargar y Restaurar Estado
              <input
                type="file"
                accept=".json"
                onChange={handleImport}
                disabled={loading || isFreemiumExpired}
                className="hidden"
              />
            </label>
          </div>
        </div>

        {/* Column 2: Google Drive Sim Panel */}
        <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm text-left flex flex-col justify-between">
          <div>
            <div className="flex justify-between items-start mb-4">
              <h3 className="text-sm font-black text-slate-800 uppercase tracking-wider">Google Drive Cloud</h3>
              <span className={`text-[10px] font-black px-2 py-0.5 rounded-full ${gdriveConnected ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500'}`}>
                {gdriveConnected ? 'Conectado' : 'Desconectado'}
              </span>
            </div>

            <p className="text-xs text-slate-500 leading-relaxed mb-6">
              Almacena automáticamente una copia de tus registros en la carpeta segura de Google Drive asociada a la cuenta de tu organización.
            </p>

            {!gdriveConnected ? (
              <button
                disabled={loading || isFreemiumExpired}
                onClick={connectGoogleDrive}
                className="w-full flex items-center justify-center gap-2 px-4 py-3 bg-blue-50 text-blue-600 hover:bg-blue-100 border border-blue-200 font-bold text-xs uppercase tracking-wider rounded-xl transition-all"
              >
                {loading ? <RefreshCw size={16} className="animate-spin" /> : <LogIn size={16} />}
                Vincular Cuenta de Google
              </button>
            ) : (
              <div className="space-y-4">
                <div className="flex items-center justify-between p-3 bg-slate-50 border border-slate-150 rounded-xl">
                  <div className="text-[11px] font-bold text-slate-700 truncate max-w-[150px]">talent360@organizacion.com</div>
                  <button 
                    onClick={() => { setGdriveConnected(false); setGdriveFiles([]); }}
                    className="text-[10px] font-black text-rose-600 hover:text-rose-800 uppercase tracking-wider"
                  >
                    Desconectar
                  </button>
                </div>

                <div className="flex items-center justify-between">
                  <label className="text-[11px] font-bold text-slate-600">Sincronización automática</label>
                  <input
                    type="checkbox"
                    checked={gdriveAutoSync}
                    onChange={(e) => setGdriveAutoSync(e.target.checked)}
                    className="w-4 h-4 rounded text-blue-600 border-slate-300 focus:ring-blue-500"
                  />
                </div>

                <button
                  disabled={syncingGdrive}
                  onClick={handleGoogleSync}
                  className="w-full flex items-center justify-center gap-2 px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider rounded-xl shadow-sm transition-all"
                >
                  {syncingGdrive ? (
                    <>
                      <RefreshCw size={14} className="animate-spin" /> Sincronizando...
                    </>
                  ) : (
                    <>
                      <CloudLightning size={14} /> Respaldar en Google Drive
                    </>
                  )}
                </button>
              </div>
            )}
          </div>

          {gdriveConnected && gdriveFiles.length > 0 && (
            <div className="mt-6 pt-6 border-t border-slate-100">
              <h4 className="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-3">Archivos en la Nube</h4>
              <div className="space-y-2.5 max-h-36 overflow-y-auto pr-1">
                {gdriveFiles.map(file => (
                  <div key={file.id} className="flex justify-between items-center text-[11px]">
                    <div className="truncate pr-2 text-left">
                      <p className="font-bold text-slate-700 truncate">{file.name}</p>
                      <span className="text-slate-400 text-[10px]">{file.date}</span>
                    </div>
                    <span className="font-bold text-slate-500 whitespace-nowrap shrink-0">{file.size}</span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
