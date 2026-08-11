import React, { useState } from 'react';
import { Database, Download, Upload, CheckCircle2, AlertTriangle, RefreshCw } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';

export function BackupPanel() {
  const { currentUser, fetchState, isFeatureUnlocked } = useAppStore();
  const [loading, setLoading] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

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

        // Elegir el archivo en el explorador disparaba la operación directamente, sin una sola
        // pregunta. Ahora se muestra de qué respaldo se trata (el propio JSON lo dice) y se pide
        // confirmación antes de tocar la base.
        let meta: any = null;
        try { meta = JSON.parse(text)?.metadata; } catch { /* la firma lo rechazará abajo */ }
        const cuando = meta?.exported_at ? new Date(meta.exported_at).toLocaleString('es-MX') : 'fecha desconocida';
        const deQuien = meta?.company_name || 'empresa desconocida';

        if (!window.confirm(
          `Vas a reponer los datos del respaldo de "${deQuien}" del ${cuando}.\n\n` +
          `Los registros que estén en el respaldo se sobrescriben con la versión del archivo. ` +
          `Lo que se haya creado después NO se borra.\n\n¿Continuar?`
        )) {
          setLoading(false);
          return;
        }

        const res = await axiosInstance.post('/tenant/backup/import', {
          backup_json: text
        });
        setSuccessMsg(res.data.message || 'Datos repuestos desde el respaldo.');
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
          <p className="text-xs text-slate-500 font-medium">Descarga una copia firmada de los datos de tu empresa y reponla si hace falta.</p>
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
              Descargar y reponer copias de seguridad es exclusivo del Plan Profesional e Ilimitado.
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
            <h3 className="text-sm font-black text-slate-800 uppercase tracking-wider mb-2">Descargar Respaldo</h3>
            {/* Decía "archivo JSON cifrado": no está cifrado, va FIRMADO. Y el archivo llevaba en
                claro el hash de la contraseña de cada persona, el secreto de 2FA, la llave
                biométrica y el token del checador; ya no salen del servidor. */}
            <p className="text-xs text-slate-500 leading-relaxed mb-4">
              Un archivo JSON <span className="font-bold">firmado</span> (HMAC SHA-256 con la llave del servidor), para que no se pueda alterar sin que se note. No va cifrado: guárdalo en un lugar seguro.
            </p>
            <div className="text-[11px] text-slate-500 leading-relaxed mb-6 bg-slate-50 border border-slate-200 rounded-xl p-3">
              <span className="font-bold text-slate-700 block mb-1">Qué incluye</span>
              Colaboradores y sus expedientes, puestos y capacidades, cuentas de acceso, fichajes,
              rutinas y tareas, vacantes y candidatos, cursos y su avance, y la configuración.
              <span className="font-bold text-slate-700 block mt-2 mb-1">Qué NO incluye</span>
              Los archivos subidos (Archivo Digital y evidencias, que viven en disco), los recibos
              de nómina, y las contraseñas y PINs (nunca salen del servidor).
            </div>

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
            <h3 className="text-sm font-black text-slate-800 uppercase tracking-wider mb-2">Reponer desde un Respaldo</h3>
            {/* Se llamaba "Restaurar Estado" y el servidor BORRABA las tablas de la empresa antes
                de reinsertar. Eso dejaba a toda la plantilla sin cuenta y sin puesto (los
                expedientes no viajan atados a nada) y se llevaba por delante el chat, la
                auditoría, las eventualidades y el monedero, que no están en el respaldo. Ahora
                repone sin borrar, y el texto dice exactamente eso. */}
            <p className="text-xs text-slate-500 leading-relaxed mb-6">
              Sube el archivo que descargaste. Se comprueba la firma y luego cada registro del
              respaldo se vuelve a escribir sobre el actual. <span className="font-bold">No se borra nada</span>:
              lo que se haya creado después del respaldo se conserva.
            </p>

            <label className={`inline-flex items-center gap-2 px-6 py-3.5 border border-slate-300 hover:border-slate-400 bg-white text-slate-700 font-bold text-xs uppercase tracking-wider rounded-2xl shadow-sm transition-colors cursor-pointer ${loading || isFreemiumExpired ? 'opacity-50 cursor-not-allowed' : ''}`}>
              <Upload size={16} /> Cargar y Reponer
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

        {/* COLUMNA DE GOOGLE DRIVE ELIMINADA (2026-08-11): era ficción completa. "Vincular
            Cuenta de Google" no llamaba a ningún servidor —era un setTimeout que pintaba
            "Conectado", una cuenta escrita a mano y dos archivos con tamaños inventados—, y
            "Respaldar en Google Drive" llamaba a un endpoint que armaba el JSON, lo descartaba
            y respondía "subida con éxito". Nada subía a ninguna nube, y se vendía como función
            del Plan Profesional. */}
      </div>
    </div>
  );
}
