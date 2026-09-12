import React, { useState, useEffect } from 'react';
import {
  FileText, Download, Filter, Calendar, BarChart3,
  Lock, Zap, Table, FileSpreadsheet, FileOutput, CheckCircle2, AlertCircle, Bot, DollarSign
} from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';
import { MobileModuleBottomDock } from './common/MobileModuleBottomDock';

// El id es el nombre del archivo que sirve el servidor (/admin/reports/<id>.csv) y el que
// devuelve el asistente. La LISTA ya no vive aquí: la manda el servidor desde el catálogo
// único (App\Support\CatalogoDeReportes) — con doce reportes, tenerla duplicada en la
// pantalla garantizaba una tarjeta que el backend no sabe servir.
type ReporteId = string;

type ReporteDelCatalogo = { id: ReporteId; titulo: string; descripcion: string };

// Los tres formatos son la misma ruta con `?formato=`. Excel es el predeterminado porque es lo
// que la gente hace con un reporte: trabajarlo. El CSV queda para quien lo va a meter en otro
// sistema, y el PDF para lo que se entrega o se archiva.
type Formato = 'xlsx' | 'csv' | 'pdf';

const FORMATOS: { id: Formato; etiqueta: string; ayuda: string }[] = [
  { id: 'xlsx', etiqueta: 'Excel', ayuda: 'Hoja de cálculo con filtros, el encabezado fijo, y el resumen y las notas en sus propias pestañas' },
  { id: 'csv',  etiqueta: 'CSV',   ayuda: 'Texto plano, para cargarlo en otro sistema' },
  { id: 'pdf',  etiqueta: 'PDF',   ayuda: 'Documento para entregar, imprimir o archivar' },
];

const TIPO_MIME: Record<Formato, string> = {
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  csv: 'text/csv;charset=utf-8',
  pdf: 'application/pdf',
};

// Sólo el adorno vive en el cliente; si llega un reporte nuevo, se pinta con el ícono neutro.
const ADORNO: Record<string, { color: string; Icono: any }> = {
  asistencia:    { color: 'bg-navy-50 text-accent',     Icono: Table },
  retardos:      { color: 'bg-danger-bg text-danger-text',     Icono: AlertCircle },
  horas:         { color: 'bg-warning-bg text-warning-text',   Icono: Calendar },
  rutinas:       { color: 'bg-navy-50 text-accent', Icono: CheckCircle2 },
  tareas:        { color: 'bg-navy-50 text-accent', Icono: FileSpreadsheet },
  justificantes: { color: 'bg-navy-50 text-accent',     Icono: FileText },
  aperturas:     { color: 'bg-success-bg text-success-text', Icono: Calendar },
  comedor:       { color: 'bg-warning-bg text-warning-text', Icono: Table },
  academia:      { color: 'bg-navy-50 text-accent', Icono: CheckCircle2 },
  expedientes:   { color: 'bg-page text-text-2',  Icono: FileText },
  reclutamiento: { color: 'bg-navy-50 text-accent',     Icono: BarChart3 },
  monedero:      { color: 'bg-warning-bg text-warning-text', Icono: DollarSign },
};

export default function ReportesManager() {
  const [activeTab, setActiveTab] = useState<'basicos' | 'avanzados'>('basicos');

  // El plan REAL del tenant. Antes esto era `demoTier`, un estado local que arrancaba en
  // 'freemium' con un botón "[DEMO] Simular Mejora a PRO" en la pantalla: a un cliente
  // enterprise se le mostraba un candado vendiéndole el plan que YA TIENE, y para ver su
  // propia nómina tenía que pulsar un botón de demostración.
  const { currentTier, isModuleUnlocked, currentUser } = useAppStore();

  // Decisión del dueño (2026-08-13): el supervisor ve los reportes BÁSICOS (asistencia y
  // tareas, sin un dato salarial) y el asistente; la pestaña de nómina es solo de admin.
  // El candado real de los datos vive en el servidor (permission:manage_payroll).
  const esAdmin = currentUser?.role === 'admin' || currentUser?.role === 'platform_admin';
  const tieneAvanzados = esAdmin && (currentTier === 'pro' || currentTier === 'enterprise' || isModuleUnlocked('reportes'));

  const [descargando, setDescargando] = useState<string | null>(null);

  // Resultado REAL de la autorización (cuántas se autorizaron, cuántas faltan de firma):
  // el modal viejo inventaba "3 facturas XML generadas" sin que nada se hubiera timbrado.
  const [approveResult, setApproveResult] = useState<{ approved: number; pending: number; message: string } | null>(null);
  const [payrollData, setPayrollData] = useState<any[]>([]);
  // Periodo operativo que reporta el backend: la última semana CERRADA del tenant (N3).
  const [period, setPeriod] = useState<{ start_date: string; end_date: string } | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [expandedEmpId, setExpandedEmpId] = useState<number | null>(null);

  // Bloque 6: asistente por frase. SOLO llena el formulario; la descarga la confirma el
  // humano por los botones de siempre. Si la instancia no tiene llave, no se ofrece.
  const [asistenteDisponible, setAsistenteDisponible] = useState(false);
  const [frase, setFrase] = useState('');
  const [interpretando, setInterpretando] = useState(false);
  const [asistenteError, setAsistenteError] = useState<string | null>(null);
  const [propuesta, setPropuesta] = useState<{ reporte: ReporteId; desde: string; hasta: string; etiqueta: string } | null>(null);
  // El catálogo lo manda el servidor: una sola lista para la pantalla, el asistente y las rutas.
  const [catalogo, setCatalogo] = useState<ReporteDelCatalogo[]>([]);

  useEffect(() => {
    axiosInstance.get('/admin/reports/asistente/estado')
      .then(res => {
        setAsistenteDisponible(res.data?.disponible === true);
        setCatalogo(res.data?.catalogo || []);
      })
      .catch(() => setAsistenteDisponible(false));
  }, []);

  const handleInterpretar = async () => {
    if (!frase.trim() || interpretando) return;
    setInterpretando(true);
    setAsistenteError(null);
    setPropuesta(null);
    try {
      const res = await axiosInstance.post('/admin/reports/asistente/interpretar', { frase: frase.trim() });
      setPropuesta(res.data);
    } catch (err: any) {
      setAsistenteError(err?.response?.data?.message || 'No se pudo interpretar la frase. Usa los botones de abajo.');
    } finally {
      setInterpretando(false);
    }
  };

  // Cada tarjeta es la SUMA de su columna, no una resta derivada aquí. Antes el neto se
  // calculaba en el navegador (Σbase − Σpenalty) y no coincidía con la columna "Neto a
  // Pagar" de la tabla de abajo: el bruto del periodo no es `base` (que es el sueldo del
  // expediente), y el neto del backend además tiene tope en 0 y suma el bono. Dos cifras
  // distintas para el mismo dinero, y la grande no era la que se paga.
  const soloConSalario = payrollData.filter((e) => !e.salary_pending);
  const totalBase = soloConSalario.reduce((acc, curr) => acc + (curr.gross || 0), 0);
  const totalPenalties = soloConSalario.reduce((acc, curr) => acc + (curr.penalty || 0), 0);
  const totalBonos = soloConSalario.reduce((acc, curr) => acc + (curr.compliance_bonus || 0), 0);
  const totalNet = soloConSalario.reduce((acc, curr) => acc + (curr.net || 0), 0);
  const pendientesDeSalario = payrollData.filter((e) => e.salary_pending).length;

  const fetchPayroll = async () => {
    setIsLoading(true);
    setError(null);
    try {
      const res = await axiosInstance.get('/admin/payroll?detailed=true');
      setPayrollData(res.data?.employees || []);
      setPeriod(res.data?.period || null);
    } catch (e: any) {
      console.error(e);
      // El mensaje del backend importa: explica si el periodo pedido no es un periodo de
      // nómina de la empresa, o si al puesto le falta la capacidad. Antes todo se pintaba
      // como "Error de Conexión" y el dueño reintentaba en bucle algo que no iba a funcionar.
      setError(
        e?.response?.data?.message ||
        (e?.response?.status === 403
          ? 'Tu puesto no tiene permiso para ver la nómina. Pídeselo al administrador.'
          : 'No se pudieron cargar los datos de la nómina. Por favor, intenta de nuevo.')
      );
    } finally {
      setIsLoading(false);
    }
  };

  const handleApprovePayroll = async () => {
    try {
      const res = await axiosInstance.post('/admin/payroll/approve');
      setApproveResult({
        approved: res.data?.approved ?? 0,
        pending: res.data?.pending_employee_signature ?? 0,
        message: res.data?.message || 'Autorización procesada.'
      });
      fetchPayroll(); // refrescar los estados de firma/autorización en la tabla
    } catch (err: any) {
      console.error(err);
      // El backend explica el candado (p. ej. "no se puede recalcular una nómina firmada"):
      // taparlo con un "Error al autorizar" genérico deja al dueño sin saber qué hacer.
      alert(err?.response?.data?.message || 'No se pudo autorizar la nómina.');
    }
  };

  // Los botones existían SIN onClick: no bajaban nada y tampoco avisaban de nada.
  //
  // Los tres formatos son la MISMA ruta con `?formato=`: el servidor los arma del mismo arreglo
  // de filas, así que el documento que se entrega no puede decir algo distinto al Excel.
  const handleDescargar = async (reporte: ReporteId, formato: Formato = 'xlsx', rango?: { from: string; to: string }) => {
    setDescargando(`${reporte}:${formato}`);
    try {
      const query = new URLSearchParams(rango ? { from: rango.from, to: rango.to } : {});
      if (formato !== 'csv') query.set('formato', formato);
      const qs = query.toString();
      const res = await axiosInstance.get(`/admin/reports/${reporte}.csv${qs ? `?${qs}` : ''}`, { responseType: 'blob' });
      // El archivo se nombra con el PERIODO que contiene (ronda adversarial: con rango, el
      // nombre decía el día de la descarga y mentía sobre el contenido).
      const sufijo = rango ? (rango.from === rango.to ? rango.from : `${rango.from}_a_${rango.to}`) : new Date().toISOString().slice(0, 10);
      const url = window.URL.createObjectURL(new Blob([res.data], { type: TIPO_MIME[formato] }));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `${reporte}_${sufijo}.${formato}`);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    } catch (err: any) {
      console.error(err);
      // El error del servidor viaja como blob por `responseType: 'blob'`: leerlo como si fuera
      // JSON deja `undefined` y el dueño ve "no se pudo" en vez del motivo real (p. ej. que el
      // periodo pasa de 92 días).
      let motivo = '';
      try { motivo = JSON.parse(await err?.response?.data?.text?.() || '{}')?.message || ''; } catch { /* no era JSON */ }
      alert(motivo || 'No se pudo descargar el reporte. Intenta de nuevo.');
    } finally {
      setDescargando(null);
    }
  };

  const handleExport = async (format: 'xlsx' | 'pdf') => {
    try {
      const response = await axiosInstance.get(`/admin/reports/export?format=${format}`, {
        responseType: 'blob'
      });
      const blob = new Blob([response.data], {
        type: format === 'xlsx'
          ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
          : 'application/pdf'
      });
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      // El archivo se nombra con el PERIODO que contiene, no con el día de la descarga
      // (que además salía en UTC): el contenido es la última semana cerrada, así que
      // "Prenomina_2026-08-08.xlsx" archivaba un recibo del 27-jul al 02-ago con fecha
      // equivocada y dos descargas del mismo periodo no se distinguían.
      const etiqueta = period ? `${period.start_date}_a_${period.end_date}` : 'periodo';
      link.setAttribute('download', `Prenomina_${etiqueta}.${format}`);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    } catch (err) {
      console.error(err);
      alert('Error al descargar el reporte.');
    }
  };

  useEffect(() => {
    if (activeTab === 'avanzados' && tieneAvanzados) {
      fetchPayroll();
    }
  }, [activeTab, tieneAvanzados]);

  return (
    <div className="h-full flex flex-col bg-white border border-border shadow-sm rounded-2xl animate-in fade-in slide-in-from-bottom-4 duration-500 overflow-hidden relative">

      {/* El conmutador "DEMO TIER" (Freemium/PRO) vivía aquí, en la pantalla del cliente:
          cualquiera podía fingir el plan y, peor, arrancaba en 'freemium' para todos. El
          plan sale del tenant. */}

      {/* Header (Escritorio) */}
      <div className="hidden sm:block sticky -top-8 -mt-8 -mx-8 px-8 pt-6 pb-3 bg-page/90 backdrop-blur-md z-20 transition-all border-b border-border/50 mb-6">
        <div className="bg-white rounded-3xl p-6 border border-border shadow-sm">
          <div className="flex items-center gap-3">
            <div className="p-3 bg-success-bg text-success-text rounded-xl">
              <FileText size={24} />
            </div>
            <div>
              <h1 className="text-xl font-bold text-text-1">Módulo de Reportes IA</h1>
              {/* Decía "cálculo inteligente de pagos": el cálculo es determinista (asistencia
                  + reglamento de la empresa), no hay ninguna IA de por medio en este módulo. */}
              <p className="text-sm text-text-3">Exportación de datos y prenómina calculada con tu reglamento</p>
            </div>
          </div>

          {/* Tabs */}
          <div className="flex items-center gap-2 border-b border-border mt-4 overflow-x-auto whitespace-nowrap scrollbar-none">
            <button
              onClick={() => setActiveTab('basicos')}
              className={`px-4 py-2 text-sm font-semibold border-b-2 transition-colors ${activeTab === 'basicos' ? 'border-success-text text-success-text font-bold' : 'border-transparent text-text-3 hover:text-text-2 hover:border-slate-300'}`}
            >
              {/* Decía "(Gratis)", pero el módulo entero exige plan PRO (minTier en App.tsx):
                  la única pestaña rotulada como gratuita solo la ve quien ya pagó. */}
              Reportes Operativos
            </button>
            {esAdmin && (
              <button
                onClick={() => setActiveTab('avanzados')}
                className={`px-4 py-2 text-sm font-semibold border-b-2 transition-colors flex items-center gap-2 ${activeTab === 'avanzados' ? 'border-warning-text text-warning-text font-bold' : 'border-transparent text-text-3 hover:text-text-2 hover:border-slate-300'}`}
              >
                Nómina y Avanzados
                {!tieneAvanzados && <Lock size={14} className="text-warning-text" />}
              </button>
            )}
          </div>
        </div>
      </div>

      {/* DOCK FLOTANTE INFERIOR MÓVIL (Estilo Reloj Checador con muesca SVG y FAB verde) */}
      <MobileModuleBottomDock
        colorTheme="emerald"
        activeTab={activeTab}
        onSelectTab={(tab) => setActiveTab(tab as any)}
        fabIcon={<Bot size={28} className="text-white relative z-10 animate-pulse" />}
        onFabClick={() => setActiveTab(esAdmin ? 'avanzados' : 'basicos')}
        fabTitle="Generar Reportes Inteligentes IA"
        items={[
          { id: 'basicos', label: 'Básicos', icon: <FileText /> },
          ...(esAdmin ? [{ id: 'avanzados', label: 'Avanzados', icon: <DollarSign /> }] : [])
        ]}
      />

      {/* Content Area */}
      <div className="flex-1 overflow-y-auto p-6 bg-page pb-24 sm:pb-6">

        {/* TABS: FREEMIUM */}
        {activeTab === 'basicos' && (
          <div className="max-w-4xl space-y-6">

            {/* Bloque 6: asistente por frase. Llena el formulario; el humano confirma y
                descarga por la puerta de siempre. Sin llave configurada, no existe. */}
            {asistenteDisponible && (
              <div className="bg-white p-6 rounded-xl border border-border shadow-sm">
                <div className="flex items-start gap-4 mb-4">
                  <div className="p-3 bg-navy-50 text-accent rounded-lg"><Bot size={24} /></div>
                  <div className="flex-1">
                    <h3 className="font-bold text-text-1">Pídelo con tus palabras</h3>
                    <p className="text-sm text-text-3 mt-1">
                      Ej. “quién llega tarde seguido este mes”, “horas trabajadas de la semana
                      pasada” o “cumplimiento de rutinas de julio”. La nómina se consulta en su pestaña.
                    </p>
                  </div>
                </div>

                <div className="flex flex-col sm:flex-row gap-2">
                  <input
                    type="text"
                    maxLength={300}
                    value={frase}
                    onChange={e => setFrase(e.target.value)}
                    onKeyDown={e => e.key === 'Enter' && handleInterpretar()}
                    placeholder="Escribe qué reporte necesitas…"
                    className="flex-1 px-4 py-2.5 bg-page border border-border rounded-lg text-sm focus:outline-none focus:ring-2 focus-visible:ring-focus-ring focus:bg-white"
                  />
                  <button
                    onClick={handleInterpretar}
                    disabled={interpretando || !frase.trim()}
                    className="px-4 py-2.5 bg-accent text-white font-bold rounded-lg text-sm hover:bg-accent-hover disabled:opacity-50 flex items-center justify-center gap-2"
                  >
                    <Zap size={15} /> {interpretando ? 'Interpretando…' : 'Interpretar'}
                  </button>
                </div>

                {asistenteError && (
                  <p className="mt-3 text-sm text-danger-text bg-danger-bg border border-danger-text/20 rounded-lg px-3 py-2">{asistenteError}</p>
                )}

                {propuesta && (
                  <div className="mt-4 bg-navy-50/60 border border-border rounded-xl p-4">
                    <p className="text-sm font-bold text-text-2 mb-3">
                      Entendí: <span className="text-accent">{catalogo.find(r => r.id === propuesta.reporte)?.titulo || propuesta.reporte}</span>{' '}
                      {/* Si el humano editó las fechas, la etiqueta original ya no aplica y
                          mentiría (ronda adversarial): se cambia por el rango literal. */}
                      de <span className="text-accent">{propuesta.etiqueta || `del ${propuesta.desde} al ${propuesta.hasta}`}</span>. Revisa y confirma:
                    </p>
                    <div className="flex flex-col sm:flex-row sm:items-end gap-3">
                      <div>
                        <label className="text-[10px] font-bold text-text-3 uppercase block mb-1">Del</label>
                        <input type="date" value={propuesta.desde} onChange={e => setPropuesta({ ...propuesta, desde: e.target.value, etiqueta: '' })} className="px-3 py-2 border border-border rounded-lg text-sm bg-white" />
                      </div>
                      <div>
                        <label className="text-[10px] font-bold text-text-3 uppercase block mb-1">Al</label>
                        <input type="date" value={propuesta.hasta} onChange={e => setPropuesta({ ...propuesta, hasta: e.target.value, etiqueta: '' })} className="px-3 py-2 border border-border rounded-lg text-sm bg-white" />
                      </div>
                      <button
                        onClick={() => handleDescargar(propuesta.reporte, 'xlsx', { from: propuesta.desde, to: propuesta.hasta })}
                        disabled={!!descargando}
                        className="px-4 py-2 bg-success-text text-white font-bold rounded-lg text-sm hover:bg-success-text disabled:opacity-50 flex items-center gap-2"
                      >
                        <Download size={15} /> {descargando === `${propuesta.reporte}:xlsx` ? 'Generando…' : 'Descargar Excel'}
                      </button>
                      {FORMATOS.filter(f => f.id !== 'xlsx').map(f => (
                        <button
                          key={f.id}
                          onClick={() => handleDescargar(propuesta.reporte, f.id, { from: propuesta.desde, to: propuesta.hasta })}
                          disabled={!!descargando}
                          title={f.ayuda}
                          className="px-3 py-2 bg-white border border-slate-300 text-text-2 font-bold rounded-lg text-sm hover:bg-page disabled:opacity-50"
                        >
                          {descargando === `${propuesta.reporte}:${f.id}` ? '…' : f.etiqueta}
                        </button>
                      ))}
                    </div>
                  </div>
                )}

                {/* LFPDPPP: la transferencia se declara donde ocurre, antes de la primera frase. */}
                <p className="mt-3 text-[11px] text-slate-400 leading-relaxed">
                  Tu frase se envía a OpenAI (EE. UU.) únicamente para interpretarla, y se guarda
                  aquí junto con tu usuario según la retención configurada de tu empresa. Evita
                  escribir datos personales que no hagan falta.
                </p>
              </div>
            )}

            {catalogo.map(({ id, titulo, descripcion }) => {
              const { color, Icono } = ADORNO[id] || { color: 'bg-page text-text-2', Icono: FileText };
              return (
              <div key={id} className="bg-white p-6 rounded-xl border border-border shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:border-border transition-colors">
                <div className="flex items-start gap-4">
                  <div className={`p-3 rounded-lg shrink-0 ${color}`}><Icono size={24} /></div>
                  <div>
                    <h3 className="font-bold text-text-1">{titulo}</h3>
                    <p className="text-sm text-text-3 mt-1 leading-relaxed">{descripcion}</p>
                  </div>
                </div>
                {/* Tres formatos, un mismo contenido: Excel para trabajarlo, CSV para cargarlo
                    en otro sistema, PDF para entregar o archivar. */}
                <div className="flex items-center gap-2 shrink-0">
                  <button
                    onClick={() => handleDescargar(id, 'xlsx')}
                    disabled={!!descargando}
                    title={FORMATOS[0].ayuda}
                    className="px-4 py-2 bg-white border border-border text-text-2 font-medium rounded-lg hover:bg-page flex items-center justify-center gap-2 text-sm transition-colors shadow-sm disabled:opacity-50"
                  >
                    <Download size={16} /> {descargando === `${id}:xlsx` ? 'Generando…' : 'Descargar Excel'}
                  </button>
                  {FORMATOS.filter(f => f.id !== 'xlsx').map(f => (
                    <button
                      key={f.id}
                      onClick={() => handleDescargar(id, f.id)}
                      disabled={!!descargando}
                      title={f.ayuda}
                      className="px-3 py-2 bg-white border border-border text-text-3 font-medium rounded-lg hover:bg-page hover:text-text-2 text-sm transition-colors shadow-sm disabled:opacity-50"
                    >
                      {descargando === `${id}:${f.id}` ? '…' : f.etiqueta}
                    </button>
                  ))}
                </div>
              </div>
              );
            })}
          </div>
        )}

        {/* TABS: AVANZADOS (FREEMIUM VIEW -> UPSELL) */}
        {activeTab === 'avanzados' && !tieneAvanzados && (
          <div className="h-full flex flex-col items-center justify-center animate-in fade-in zoom-in-95 duration-500 pb-10">
            <div className="max-w-lg w-full bg-white rounded-2xl border border-warning-text/20 shadow-xl overflow-hidden relative">
              <div className="absolute top-0 inset-x-0 h-2 bg-gradient-to-r from-warning-icon to-warning-icon"></div>

              <div className="p-8 text-center">
                <div className="w-20 h-20 mx-auto bg-warning-bg rounded-full flex items-center justify-center mb-6 border border-warning-text/20">
                  <BarChart3 size={40} className="text-warning-text" />
                </div>

                <h3 className="text-2xl font-black text-text-1 mb-2">Reportes analíticos y pre-nómina</h3>
                {/* La promesa decía "genera la prenómina con IA y timbra (CFDI) a un clic".
                    El cálculo de nómina es determinista (reglamento + asistencia), no IA, y
                    el timbrado exige dar de alta al PAC antes. Se describe lo que hace. */}
                <p className="text-text-3 mb-8 leading-relaxed">
                  Prenómina calculada con la asistencia y el reglamento de tu empresa, exportable
                  a Excel y PDF, con recibos y timbrado CFDI una vez conectado tu PAC.
                </p>

                <div className="space-y-3 mb-8 text-left max-w-sm mx-auto">
                  <div className="flex items-center gap-3 text-sm font-medium text-text-2 bg-page p-2.5 rounded-lg border border-border">
                    <FileOutput size={18} className="text-success-text shrink-0" /> Prenómina en Excel y PDF.
                  </div>
                  <div className="flex items-center gap-3 text-sm font-medium text-text-2 bg-page p-2.5 rounded-lg border border-border">
                    <Zap size={18} className="text-success-text shrink-0" /> Descuentos por retardos y faltas según tu reglamento.
                  </div>
                  <div className="flex items-center gap-3 text-sm font-medium text-text-2 bg-page p-2.5 rounded-lg border border-border">
                    <CheckCircle2 size={18} className="text-success-text shrink-0" /> Timbrado CFDI (requiere conectar tu PAC).
                  </div>
                </div>

                {/* El botón era "[DEMO] Simular Mejora a PRO" y solo cambiaba un estado local:
                    fingía la contratación. La adopción de módulos ya vive en el tablero. */}
                <div className="w-full py-3.5 px-4 bg-page border border-border rounded-xl text-sm text-text-2 font-medium flex items-center justify-center gap-2">
                  <Lock size={16} className="text-slate-400 shrink-0" />
                  Actívalo en el tablero, en "Nuevos Módulos Disponibles".
                </div>
              </div>
            </div>
          </div>
        )}

        {/* TABS: AVANZADOS (PRO VIEW -> SIMULADOR IA) */}
        {activeTab === 'avanzados' && tieneAvanzados && (
          <div className="max-w-5xl mx-auto space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500 pb-12">
            {isLoading ? (
               <div className="bg-white border border-border shadow-sm rounded-2xl p-16 text-center flex flex-col items-center justify-center min-h-[300px]">
                  <div className="w-12 h-12 border-4 border-border border-t-success-text rounded-full animate-spin mb-4"></div>
                  <p className="text-text-2 font-bold text-sm">Cargando registros operativos y calculando nómina...</p>
               </div>
            ) : error ? (
               <div className="bg-danger-bg border border-danger-text/20 rounded-2xl p-8 text-center max-w-lg mx-auto shadow-sm my-10">
                  <AlertCircle className="text-danger-text mx-auto mb-4" size={40} />
                  <h3 className="text-lg font-bold text-danger-text mb-2">Error de Conexión</h3>
                  <p className="text-sm text-danger-text/80 mb-6">{error}</p>
                  <button onClick={fetchPayroll} className="bg-danger-text hover:bg-danger-text text-white font-bold px-6 py-2.5 rounded-xl text-sm transition-colors shadow-sm">
                     Reintentar Petición
                  </button>
               </div>
            ) : (
              <div className="space-y-6 animate-in zoom-in-95 duration-500">
                {/* Resumen Superior */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div className="bg-white p-6 rounded-2xl border border-border shadow-sm">
                    <p className="text-sm font-bold text-text-3 mb-1">Pre-nómina bruta del periodo</p>
                    <h4 className="text-3xl font-black text-text-1">${totalBase.toLocaleString('es-MX', { maximumFractionDigits: 2 })}</h4>
                    {totalBonos > 0 && (
                      <p className="text-[11px] text-slate-400 font-semibold mt-1">
                        + ${totalBonos.toLocaleString('es-MX', { maximumFractionDigits: 2 })} en bonos de cumplimiento
                      </p>
                    )}
                  </div>
                  <div className="bg-danger-bg p-6 rounded-2xl border border-danger-text/20 shadow-sm">
                    <p className="text-sm font-bold text-danger-text mb-1 flex items-center gap-2"><AlertCircle size={14}/> Deducciones (Retardos y Faltas)</p>
                    <h4 className="text-3xl font-black text-danger-text">-${totalPenalties.toLocaleString('es-MX', { maximumFractionDigits: 2 })}</h4>
                  </div>
                  <div className="bg-success-bg p-6 rounded-2xl border border-success-text/20 shadow-sm">
                    <p className="text-sm font-bold text-success-text mb-1">Total a Pagar (Neto)</p>
                    <h4 className="text-3xl font-black text-success-text">${totalNet.toLocaleString('es-MX', { maximumFractionDigits: 2 })}</h4>
                    {pendientesDeSalario > 0 && (
                      <p className="text-[11px] text-warning-text font-bold mt-1">
                        No incluye {pendientesDeSalario} colaborador{pendientesDeSalario === 1 ? '' : 'es'} sin sueldo capturado
                      </p>
                    )}
                  </div>
                </div>

                {/* Tabla de Detalle */}
                <div className="bg-white border border-border rounded-2xl shadow-sm overflow-hidden">
                  <div className="px-6 py-4 border-b border-border bg-page flex items-center justify-between">
                     <h3 className="font-bold text-text-1 flex items-center gap-2"><Table size={18}/> Desglose Analítico por Empleado</h3>
                     <span className="px-3 py-1 bg-accent-soft text-accent text-xs font-bold rounded-full uppercase tracking-wider">
                       {period ? `Periodo del ${period.start_date} al ${period.end_date}` : 'Cargando periodo...'}
                     </span>
                  </div>
                  <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                      <thead className="bg-white text-slate-400 font-medium border-b border-border">
                        <tr>
                          <th className="px-6 py-4">Colaborador</th>
                          <th className="px-6 py-4">Puesto</th>
                          <th className="px-6 py-4 text-center">Retardos</th>
                          <th className="px-6 py-4 text-center">Faltas</th>
                          <th className="px-6 py-4 text-right">Salario Base</th>
                          <th className="px-6 py-4 text-right">Penalización</th>
                          <th className="px-6 py-4 text-right font-bold text-text-1">Neto a Pagar</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-border">
                        {/* Sin esta fila, "cero empleados" se veía IGUAL que una nómina
                            calculada que da cero: tres tarjetas en $0 y una tabla vacía. El
                            dueño podía concluir que su semana valía cero y autorizarla. */}
                        {payrollData.length === 0 && (
                          <tr>
                            <td colSpan={7} className="px-6 py-10 text-center">
                              <p className="text-sm font-bold text-text-2">No hay colaboradores en este periodo de nómina.</p>
                              <p className="text-xs text-text-3 mt-1">
                                Da de alta a tu equipo en Recursos Humanos; aquí no hay nada que autorizar todavía.
                              </p>
                            </td>
                          </tr>
                        )}
                        {payrollData.map((emp) => (
                          <React.Fragment key={emp.id}>
                            <tr
                              onClick={() => setExpandedEmpId(expandedEmpId === emp.id ? null : emp.id)}
                              className="hover:bg-page transition-colors cursor-pointer"
                            >
                              <td className="px-6 py-4 font-bold text-text-1 flex items-center gap-2">
                                <span className="text-[10px] text-slate-400">{expandedEmpId === emp.id ? '▼' : '▶'}</span>
                                {emp.name}
                              </td>
                              <td className="px-6 py-4 text-text-3">{emp.role}</td>
                              <td className="px-6 py-4 text-center">
                                {emp.lates > 0 ? <span className="bg-warning-bg text-warning-text px-2 py-0.5 rounded font-bold">{emp.lates}</span> : <span className="text-slate-300">-</span>}
                              </td>
                              <td className="px-6 py-4 text-center">
                                {emp.absences > 0 ? <span className="bg-danger-bg text-danger-text px-2 py-0.5 rounded font-bold">{emp.absences}</span> : <span className="text-slate-300">-</span>}
                              </td>
                              <td className="px-6 py-4 text-right text-text-2">
                                {emp.salary_pending ? (
                                  <span className="text-danger-text font-semibold text-xs bg-danger-bg px-2 py-1 rounded">Pendiente</span>
                                ) : (
                                  `$${emp.base?.toLocaleString('es-MX')}`
                                )}
                              </td>
                              <td className="px-6 py-4 text-right text-danger-text font-medium">
                                {emp.salary_pending ? '-' : (emp.penalty > 0 ? `-$${emp.penalty.toLocaleString('es-MX')}` : '-')}
                              </td>
                              <td className="px-6 py-4 text-right font-black text-success-text text-base">
                                {emp.salary_pending ? (
                                  <span className="text-slate-400 text-xs italic">Ajustar Salario</span>
                                ) : (
                                  `$${emp.net?.toLocaleString('es-MX')}`
                                )}
                              </td>
                            </tr>
                            {expandedEmpId === emp.id && emp.days_details && (
                              <tr>
                                <td colSpan={7} className="px-8 py-5 bg-page/50 border-t border-b border-border">
                                  <div className="space-y-4">
                                    <h4 className="text-xs font-black uppercase text-slate-400 tracking-wider flex items-center gap-1.5">
                                      <Bot size={14} className="text-success-text animate-pulse" /> Detalle Diario de Asistencia LFT
                                    </h4>
                                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                      {emp.days_details.map((day: any) => {
                                        const hasCheckIn = day.entries.some((e: any) => e.type === 'check_in');
                                        const hasCheckOut = day.entries.some((e: any) => e.type === 'check_out');
                                        return (
                                          <div key={day.date} className="bg-white p-4 rounded-2xl border border-border shadow-sm text-xs space-y-3">
                                            <div className="flex justify-between items-center border-b border-border pb-2">
                                              <span className="font-extrabold text-text-2 capitalize">{day.day_name}</span>
                                              <span className="text-[10px] text-slate-400 font-bold">{day.date}</span>
                                            </div>

                                            {day.is_rest_day ? (
                                              <div className="text-[10.5px] text-success-text font-bold bg-success-bg px-2 py-0.5 rounded inline-block">Día de Descanso</div>
                                            ) : hasCheckIn ? (
                                              <div className="space-y-1.5">
                                                <div className="flex flex-col gap-1 text-text-3 text-[10.5px]">
                                                  <span>🕒 Entrada: <strong>{day.entries.find((e: any) => e.type === 'check_in')?.time || '-'}</strong></span>
                                                  <span>🕒 Salida: <strong>{day.entries.find((e: any) => e.type === 'check_out')?.time || 'Faltante'}</strong></span>
                                                </div>
                                                {/* Exceso de comida. Antes se leía `duration_minutes`
                                                    de los details del ponche —un campo que NADIE
                                                    escribe— contra un umbral fijo de 60 min: el
                                                    aviso no podía encenderse nunca y, de haberlo
                                                    hecho, habría ignorado la comida contratada de
                                                    cada quien. `meal_makeup_minutes` es el exceso
                                                    que YA calcula la nómina, con los minutos de
                                                    comida del empleado y la tolerancia de la LFT. */}
                                                {day.meal_makeup_minutes > 0 && (
                                                  <div className="text-[9.5px] text-danger-text font-bold bg-danger-bg/50 p-1.5 rounded-lg border border-danger-text/20">
                                                    ⚠️ Exceso de comida: {day.meal_makeup_minutes} min
                                                    {day.required_exit_time && <> · salida requerida {day.required_exit_time}</>}
                                                  </div>
                                                )}
                                              </div>
                                            ) : day.day_over ? (
                                              <div className="text-[10.5px] text-danger-text font-bold bg-danger-bg px-2 py-0.5 rounded inline-block">Falta / Inasistencia</div>
                                            ) : (
                                              /* N3: día aún no terminado — no es falta */
                                              <div className="text-[10.5px] text-slate-400 font-bold bg-page px-2 py-0.5 rounded inline-block">Sin registro aún</div>
                                            )}

                                            <div className="flex justify-between items-center text-[10px] pt-2 border-t border-border">
                                              <span className="text-slate-400">Firma Diaria:</span>
                                              <span className={`font-extrabold px-2 py-0.5 rounded-full ${
                                                day.approval_status === 'approved' ? 'bg-success-bg text-success-text' : 'bg-warning-bg text-warning-text'
                                              }`}>
                                                {day.approval_status === 'approved' ? 'Firmado' : 'Pendiente'}
                                              </span>
                                            </div>
                                          </div>
                                        );
                                      })}
                                    </div>
                                  </div>
                                </td>
                              </tr>
                            )}
                          </React.Fragment>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>

                {/* Actions */}
                <div className="flex justify-end gap-4 mt-6">
                  <button
                    onClick={() => handleExport('xlsx')}
                    className="px-5 py-3 bg-white border border-border text-text-2 font-bold rounded-xl hover:bg-page flex items-center gap-2 transition-colors shadow-sm"
                  >
                    <FileSpreadsheet size={18} className="text-success-text" /> Exportar Excel
                  </button>
                  <button
                    onClick={() => handleExport('pdf')}
                    className="px-5 py-3 bg-white border border-border text-text-2 font-bold rounded-xl hover:bg-page flex items-center gap-2 transition-colors shadow-sm"
                  >
                    <FileText size={18} className="text-danger-text" /> Exportar PDF
                  </button>
                  {/* Sin nada calculado no hay nada que autorizar: el botón estaba activo
                      sobre una tabla vacía. */}
                  <button
                    onClick={handleApprovePayroll}
                    disabled={payrollData.length === 0}
                    className="px-8 py-3 bg-success-text text-white font-black rounded-xl hover:bg-success-text shadow-md shadow-success-text/20 flex items-center gap-2 transition-all hover:-translate-y-0.5 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:translate-y-0"
                  >
                    <DollarSign size={20} /> Autorizar Pago de Nómina
                  </button>
                </div>
              </div>
            )}
          </div>
        )}

      </div>

      {/* Resultado REAL de la autorización: qué se autorizó y qué quedó pendiente. El
          timbrado CFDI es un paso aparte, en Facturación Electrónica. */}
      {approveResult && (
        <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 animate-in fade-in duration-300">
           <div className="bg-white rounded-3xl p-10 max-w-sm w-full text-center shadow-2xl relative overflow-hidden animate-in zoom-in-95 duration-500">
              <div className={`w-24 h-24 rounded-full flex items-center justify-center mx-auto mb-6 ${
                approveResult.approved > 0 ? 'bg-success-bg text-success-text' : 'bg-warning-bg text-warning-text'
              }`}>
                {approveResult.approved > 0 ? <CheckCircle2 size={48} /> : <AlertCircle size={48} />}
              </div>
              <h2 className="text-2xl font-black text-text-1 mb-2">
                {approveResult.approved > 0 ? 'Pago Autorizado' : 'Nada que Autorizar'}
              </h2>
              <p className="text-text-3 mb-2 text-sm">
                {approveResult.approved} nómina(s) autorizada(s) para pago.
              </p>
              {approveResult.pending > 0 && (
                <p className="text-warning-text mb-2 text-xs font-bold">
                  {approveResult.pending} sin autorizar: el colaborador aún no firma de conformidad.
                </p>
              )}
              <p className="text-slate-400 mb-8 text-xs">
                El timbrado CFDI se hace en el módulo de Facturación Electrónica, sobre las nóminas ya autorizadas.
              </p>
              <button
                onClick={() => setApproveResult(null)}
                className="w-full bg-slate-900 text-white font-bold py-3.5 rounded-xl hover:bg-slate-800 transition-colors"
              >
                Entendido
              </button>
           </div>
        </div>
      )}

    </div>
  );
}
