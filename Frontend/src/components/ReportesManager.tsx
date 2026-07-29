import React, { useState, useEffect } from 'react';
import { 
  FileText, Download, Filter, Calendar, BarChart3, 
  Lock, Zap, Table, FileSpreadsheet, FileOutput, CheckCircle2, AlertCircle, Bot, DollarSign
} from 'lucide-react';
import axiosInstance from '../lib/axios';

export default function ReportesManager() {
  const [activeTab, setActiveTab] = useState<'basicos' | 'avanzados'>('basicos');
  // [MODO DEMO]: Permite alternar la suscripción desde la UI para mostrar ambas caras a los clientes.
  const [demoTier, setDemoTier] = useState<'freemium' | 'pro'>('freemium');
  
  const [showInvoiceModal, setShowInvoiceModal] = useState(false);
  const [payrollData, setPayrollData] = useState<any[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [expandedEmpId, setExpandedEmpId] = useState<number | null>(null);

  const totalBase = payrollData.reduce((acc, curr) => acc + (curr.base || 0), 0);
  const totalPenalties = payrollData.reduce((acc, curr) => acc + (curr.penalty || 0), 0);
  const totalNet = totalBase - totalPenalties;

  const fetchPayroll = async () => {
    setIsLoading(true);
    setError(null);
    try {
      const res = await axiosInstance.get('/admin/payroll?detailed=true');
      setPayrollData(res.data || []);
    } catch (e) {
      console.error(e);
      setError("No se pudieron cargar los datos de la nómina. Por favor, intenta de nuevo.");
    } finally {
      setIsLoading(false);
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
      link.setAttribute('download', `Prenomina_${new Date().toISOString().slice(0, 10)}.${format}`);
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
    if (activeTab === 'avanzados' && demoTier === 'pro') {
      fetchPayroll();
    }
  }, [activeTab, demoTier]);

  return (
    <div className="h-full flex flex-col bg-white border border-slate-200 shadow-sm rounded-2xl animate-in fade-in slide-in-from-bottom-4 duration-500 overflow-hidden relative">
      
      {/* Controles Ocultos para Demostración */}
      <div className="absolute top-4 right-6 z-10 flex items-center gap-2 bg-slate-900/5 backdrop-blur-sm p-1.5 rounded-lg border border-slate-200/50">
        <span className="text-[10px] font-bold text-slate-500 uppercase tracking-wider ml-2">DEMO TIER:</span>
        <button onClick={() => {setDemoTier('freemium'); setActiveTab('basicos')}} className={`px-2 py-1 text-xs font-bold rounded-md transition-colors ${demoTier === 'freemium' ? 'bg-white shadow text-slate-800' : 'text-slate-500 hover:bg-slate-200'}`}>Freemium</button>
        <button onClick={() => setDemoTier('pro')} className={`px-2 py-1 text-xs font-bold rounded-md transition-colors ${demoTier === 'pro' ? 'bg-blue-600 shadow text-white' : 'text-slate-500 hover:bg-slate-200'}`}>PRO</button>
      </div>

      {/* Header (Escritorio) */}
      <div className="hidden sm:block sticky -top-8 -mt-8 -mx-8 px-8 pt-6 pb-3 bg-slate-50/90 backdrop-blur-md z-20 transition-all border-b border-slate-200/50 mb-6">
        <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
          <div className="flex items-center gap-3">
            <div className="p-3 bg-emerald-100 text-emerald-700 rounded-xl">
              <FileText size={24} />
            </div>
            <div>
              <h1 className="text-xl font-bold text-slate-800">Módulo de Reportes IA</h1>
              <p className="text-sm text-slate-500">Exportación de datos y cálculo inteligente de pagos</p>
            </div>
          </div>

          {/* Tabs */}
          <div className="flex items-center gap-2 border-b border-slate-200 mt-4 overflow-x-auto whitespace-nowrap scrollbar-none">
            <button 
              onClick={() => setActiveTab('basicos')}
              className={`px-4 py-2 text-sm font-semibold border-b-2 transition-colors ${activeTab === 'basicos' ? 'border-emerald-600 text-emerald-700 font-bold' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'}`}
            >
              Reportes Básicos (Gratis)
            </button>
            <button 
              onClick={() => setActiveTab('avanzados')}
              className={`px-4 py-2 text-sm font-semibold border-b-2 transition-colors flex items-center gap-2 ${activeTab === 'avanzados' ? 'border-amber-500 text-amber-700 font-bold' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'}`}
            >
              Nómina y Avanzados
              {demoTier === 'freemium' && <Lock size={14} className="text-amber-500" />}
            </button>
          </div>
        </div>
      </div>

      {/* DOCK FLOTANTE INFERIOR MÓVIL (Estilo Reloj Checador) */}
      <div className="fixed bottom-3 left-1/2 -translate-x-1/2 w-[calc(100%-2rem)] max-w-md bg-white/95 backdrop-blur-2xl border border-slate-200/90 rounded-3xl p-1.5 shadow-[0_10px_25px_rgba(0,0,0,0.15)] z-40 sm:hidden flex items-center justify-around">
        <button
          onClick={() => setActiveTab('basicos')}
          className="flex flex-col items-center justify-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer py-0.5 px-1"
        >
          <div className={`w-10 h-10 rounded-full flex items-center justify-center transition-all ${
            activeTab === 'basicos' 
              ? 'bg-emerald-500/15 border-2 border-emerald-500 shadow-md shadow-emerald-500/20 scale-105' 
              : 'bg-slate-100 border border-slate-200/80 hover:bg-slate-200/60'
          }`}>
            <FileText size={19} className={activeTab === 'basicos' ? 'animate-pulse text-emerald-600 font-bold' : 'text-slate-400'} />
          </div>
          <span className={`text-[8.5px] uppercase tracking-wider font-extrabold mt-0.5 ${
            activeTab === 'basicos' ? 'font-black text-emerald-600' : 'text-slate-400'
          }`}>
            Básicos
          </span>
        </button>

        <button
          onClick={() => setActiveTab('avanzados')}
          className="flex flex-col items-center justify-center gap-0.5 focus:outline-none transition-all active:scale-95 border-none bg-transparent cursor-pointer py-0.5 px-1"
        >
          <div className={`w-10 h-10 rounded-full flex items-center justify-center transition-all ${
            activeTab === 'avanzados' 
              ? 'bg-emerald-500/15 border-2 border-emerald-500 shadow-md shadow-emerald-500/20 scale-105' 
              : 'bg-slate-100 border border-slate-200/80 hover:bg-slate-200/60'
          }`}>
            <DollarSign size={19} className={activeTab === 'avanzados' ? 'animate-pulse text-emerald-600 font-bold' : 'text-slate-400'} />
          </div>
          <span className={`text-[8.5px] uppercase tracking-wider font-extrabold mt-0.5 ${
            activeTab === 'avanzados' ? 'font-black text-emerald-600' : 'text-slate-400'
          }`}>
            Avanzados
          </span>
        </button>
      </div>

      {/* Content Area */}
      <div className="flex-1 overflow-y-auto p-6 bg-slate-50 pb-24 sm:pb-6">
        
        {/* TABS: FREEMIUM */}
        {activeTab === 'basicos' && (
          <div className="max-w-4xl space-y-6">
            <div className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between hover:border-blue-200 transition-colors">
              <div className="flex items-start gap-4">
                <div className="p-3 bg-blue-50 text-blue-600 rounded-lg"><Table size={24} /></div>
                <div>
                  <h3 className="font-bold text-slate-800">Asistencia del Día</h3>
                  <p className="text-sm text-slate-500 mt-1">Exporta un CSV plano con las horas de entrada y salida de hoy.</p>
                </div>
              </div>
              <button className="px-4 py-2 bg-white border border-slate-200 text-slate-700 font-medium rounded-lg hover:bg-slate-50 flex items-center gap-2 text-sm transition-colors shadow-sm">
                <Download size={16} /> Descargar CSV
              </button>
            </div>

            <div className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm flex items-center justify-between hover:border-blue-200 transition-colors">
              <div className="flex items-start gap-4">
                <div className="p-3 bg-purple-50 text-purple-600 rounded-lg"><FileSpreadsheet size={24} /></div>
                <div>
                  <h3 className="font-bold text-slate-800">Tareas Completadas</h3>
                  <p className="text-sm text-slate-500 mt-1">Listado básico de tareas cerradas por los empleados.</p>
                </div>
              </div>
              <button className="px-4 py-2 bg-white border border-slate-200 text-slate-700 font-medium rounded-lg hover:bg-slate-50 flex items-center gap-2 text-sm transition-colors shadow-sm">
                <Download size={16} /> Descargar CSV
              </button>
            </div>
          </div>
        )}

        {/* TABS: AVANZADOS (FREEMIUM VIEW -> UPSELL) */}
        {activeTab === 'avanzados' && demoTier === 'freemium' && (
          <div className="h-full flex flex-col items-center justify-center animate-in fade-in zoom-in-95 duration-500 pb-10">
            <div className="max-w-lg w-full bg-white rounded-2xl border border-amber-200 shadow-xl overflow-hidden relative">
              <div className="absolute top-0 inset-x-0 h-2 bg-gradient-to-r from-amber-400 to-yellow-500"></div>
              
              <div className="p-8 text-center">
                <div className="w-20 h-20 mx-auto bg-amber-50 rounded-full flex items-center justify-center mb-6 border border-amber-100">
                  <BarChart3 size={40} className="text-amber-500" />
                </div>
                
                <h3 className="text-2xl font-black text-slate-800 mb-2">Reportes Analíticos y Nómina</h3>
                <p className="text-slate-500 mb-8 leading-relaxed">
                  Calcula descuentos automáticos por retardos, genera la prenómina con IA y timbra (CFDI) a un clic. Exclusivo del plan PRO.
                </p>

                <div className="space-y-3 mb-8 text-left max-w-sm mx-auto">
                  <div className="flex items-center gap-3 text-sm font-medium text-slate-700 bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                    <FileOutput size={18} className="text-emerald-500 shrink-0" /> Generación de Nómina en PDF.
                  </div>
                  <div className="flex items-center gap-3 text-sm font-medium text-slate-700 bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                    <Zap size={18} className="text-emerald-500 shrink-0" /> Cálculo de Penalizaciones Automático.
                  </div>
                  <div className="flex items-center gap-3 text-sm font-medium text-slate-700 bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                    <CheckCircle2 size={18} className="text-emerald-500 shrink-0" /> Conexión a PAC (Facturación CFDI).
                  </div>
                </div>

                <button onClick={() => setDemoTier('pro')} className="w-full py-3.5 bg-gradient-to-r from-slate-900 to-slate-800 hover:from-slate-800 hover:to-slate-700 text-white font-bold rounded-xl shadow-md transition-all flex items-center justify-center gap-2 group">
                  <Lock size={18} className="text-slate-400 group-hover:text-white transition-colors" />
                  [DEMO] Simular Mejora a PRO
                </button>
              </div>
            </div>
          </div>
        )}

        {/* TABS: AVANZADOS (PRO VIEW -> SIMULADOR IA) */}
        {activeTab === 'avanzados' && demoTier === 'pro' && (
          <div className="max-w-5xl mx-auto space-y-6 animate-in fade-in slide-in-from-bottom-4 duration-500 pb-12">
            {isLoading ? (
               <div className="bg-white border border-slate-200 shadow-sm rounded-2xl p-16 text-center flex flex-col items-center justify-center min-h-[300px]">
                  <div className="w-12 h-12 border-4 border-slate-200 border-t-emerald-600 rounded-full animate-spin mb-4"></div>
                  <p className="text-slate-600 font-bold text-sm">Cargando registros operativos y calculando nómina...</p>
               </div>
            ) : error ? (
               <div className="bg-rose-50 border border-rose-200 rounded-2xl p-8 text-center max-w-lg mx-auto shadow-sm my-10">
                  <AlertCircle className="text-rose-600 mx-auto mb-4" size={40} />
                  <h3 className="text-lg font-bold text-rose-900 mb-2">Error de Conexión</h3>
                  <p className="text-sm text-rose-700/80 mb-6">{error}</p>
                  <button onClick={fetchPayroll} className="bg-rose-600 hover:bg-rose-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm transition-colors shadow-sm">
                     Reintentar Petición
                  </button>
               </div>
            ) : (
              <div className="space-y-6 animate-in zoom-in-95 duration-500">
                {/* Resumen Superior */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                    <p className="text-sm font-bold text-slate-500 mb-1">Nómina Base Bruta</p>
                    <h4 className="text-3xl font-black text-slate-800">${totalBase.toLocaleString('es-MX')}</h4>
                  </div>
                  <div className="bg-rose-50 p-6 rounded-2xl border border-rose-100 shadow-sm">
                    <p className="text-sm font-bold text-rose-600 mb-1 flex items-center gap-2"><AlertCircle size={14}/> Deducciones (Retardos)</p>
                    <h4 className="text-3xl font-black text-rose-700">-${totalPenalties.toLocaleString('es-MX')}</h4>
                  </div>
                  <div className="bg-emerald-50 p-6 rounded-2xl border border-emerald-100 shadow-sm">
                    <p className="text-sm font-bold text-emerald-600 mb-1">Total a Pagar (Neto)</p>
                    <h4 className="text-3xl font-black text-emerald-700">${totalNet.toLocaleString('es-MX')}</h4>
                  </div>
                </div>

                {/* Tabla de Detalle */}
                <div className="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                  <div className="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                     <h3 className="font-bold text-slate-800 flex items-center gap-2"><Table size={18}/> Desglose Analítico por Empleado</h3>
                     <span className="px-3 py-1 bg-blue-100 text-blue-700 text-xs font-bold rounded-full uppercase tracking-wider">Quincena Actual</span>
                  </div>
                  <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                      <thead className="bg-white text-slate-400 font-medium border-b border-slate-100">
                        <tr>
                          <th className="px-6 py-4">Colaborador</th>
                          <th className="px-6 py-4">Puesto</th>
                          <th className="px-6 py-4 text-center">Retardos</th>
                          <th className="px-6 py-4 text-center">Faltas</th>
                          <th className="px-6 py-4 text-right">Salario Base</th>
                          <th className="px-6 py-4 text-right">Penalización</th>
                          <th className="px-6 py-4 text-right font-bold text-slate-900">Neto a Pagar</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-50">
                        {payrollData.map((emp) => (
                          <React.Fragment key={emp.id}>
                            <tr 
                              onClick={() => setExpandedEmpId(expandedEmpId === emp.id ? null : emp.id)}
                              className="hover:bg-slate-50 transition-colors cursor-pointer"
                            >
                              <td className="px-6 py-4 font-bold text-slate-800 flex items-center gap-2">
                                <span className="text-[10px] text-slate-400">{expandedEmpId === emp.id ? '▼' : '▶'}</span>
                                {emp.name}
                              </td>
                              <td className="px-6 py-4 text-slate-500">{emp.role}</td>
                              <td className="px-6 py-4 text-center">
                                {emp.lates > 0 ? <span className="bg-amber-100 text-amber-700 px-2 py-0.5 rounded font-bold">{emp.lates}</span> : <span className="text-slate-300">-</span>}
                              </td>
                              <td className="px-6 py-4 text-center">
                                {emp.absences > 0 ? <span className="bg-rose-100 text-rose-700 px-2 py-0.5 rounded font-bold">{emp.absences}</span> : <span className="text-slate-300">-</span>}
                              </td>
                              <td className="px-6 py-4 text-right text-slate-600">
                                {emp.salary_pending ? (
                                  <span className="text-rose-500 font-semibold text-xs bg-rose-50 px-2 py-1 rounded">Pendiente</span>
                                ) : (
                                  `$${emp.base?.toLocaleString('es-MX')}`
                                )}
                              </td>
                              <td className="px-6 py-4 text-right text-rose-600 font-medium">
                                {emp.salary_pending ? '-' : (emp.penalty > 0 ? `-$${emp.penalty.toLocaleString('es-MX')}` : '-')}
                              </td>
                              <td className="px-6 py-4 text-right font-black text-emerald-600 text-base">
                                {emp.salary_pending ? (
                                  <span className="text-slate-400 text-xs italic">Ajustar Salario</span>
                                ) : (
                                  `$${emp.net?.toLocaleString('es-MX')}`
                                )}
                              </td>
                            </tr>
                            {expandedEmpId === emp.id && emp.days_details && (
                              <tr>
                                <td colSpan={7} className="px-8 py-5 bg-slate-50/50 border-t border-b border-slate-100">
                                  <div className="space-y-4">
                                    <h4 className="text-xs font-black uppercase text-slate-400 tracking-wider flex items-center gap-1.5">
                                      <Bot size={14} className="text-emerald-500 animate-pulse" /> Detalle Diario de Asistencia LFT
                                    </h4>
                                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                      {emp.days_details.map((day: any) => {
                                        const hasCheckIn = day.entries.some((e: any) => e.type === 'check_in');
                                        const hasCheckOut = day.entries.some((e: any) => e.type === 'check_out');
                                        return (
                                          <div key={day.date} className="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm text-xs space-y-3">
                                            <div className="flex justify-between items-center border-b border-slate-50 pb-2">
                                              <span className="font-extrabold text-slate-700 capitalize">{day.day_name}</span>
                                              <span className="text-[10px] text-slate-400 font-bold">{day.date}</span>
                                            </div>
                                            
                                            {day.is_rest_day ? (
                                              <div className="text-[10.5px] text-emerald-600 font-bold bg-emerald-50 px-2 py-0.5 rounded inline-block">Día de Descanso</div>
                                            ) : hasCheckIn ? (
                                              <div className="space-y-1.5">
                                                <div className="flex flex-col gap-1 text-slate-500 text-[10.5px]">
                                                  <span>🕒 Entrada: <strong>{day.entries.find((e: any) => e.type === 'check_in')?.time || '-'}</strong></span>
                                                  <span>🕒 Salida: <strong>{day.entries.find((e: any) => e.type === 'check_out')?.time || 'Faltante'}</strong></span>
                                                </div>
                                                {/* Excesos */}
                                                {day.entries.filter((e: any) => e.type === 'meal_end').map((e: any) => {
                                                  const d = JSON.parse(e.details || '{}');
                                                  if (d.duration_minutes && d.duration_minutes > 60) {
                                                    return (
                                                      <div key={e.id} className="text-[9.5px] text-rose-500 font-bold bg-rose-50/50 p-1.5 rounded-lg border border-rose-100">
                                                        ⚠️ Exceso Comida: {d.duration_minutes - 60} min
                                                      </div>
                                                    );
                                                  }
                                                  return null;
                                                })}
                                              </div>
                                            ) : (
                                              <div className="text-[10.5px] text-rose-500 font-bold bg-rose-50 px-2 py-0.5 rounded inline-block">Falta / Inasistencia</div>
                                            )}
                                            
                                            <div className="flex justify-between items-center text-[10px] pt-2 border-t border-slate-100">
                                              <span className="text-slate-400">Firma Diaria:</span>
                                              <span className={`font-extrabold px-2 py-0.5 rounded-full ${
                                                day.approval_status === 'approved' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'
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
                    className="px-5 py-3 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 flex items-center gap-2 transition-colors shadow-sm"
                  >
                    <FileSpreadsheet size={18} className="text-emerald-600" /> Exportar Excel
                  </button>
                  <button 
                    onClick={() => handleExport('pdf')}
                    className="px-5 py-3 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 flex items-center gap-2 transition-colors shadow-sm"
                  >
                    <FileText size={18} className="text-rose-600" /> Exportar PDF
                  </button>
                  <button 
                    onClick={async () => {
                      try {
                        await axiosInstance.post('/admin/payroll/approve');
                        setShowInvoiceModal(true);
                      } catch (err) {
                        console.error(err);
                        alert("Error al timbrar la nómina");
                      }
                    }}
                    className="px-8 py-3 bg-emerald-600 text-white font-black rounded-xl hover:bg-emerald-700 shadow-md shadow-emerald-600/20 flex items-center gap-2 transition-all hover:-translate-y-0.5"
                  >
                    <DollarSign size={20} /> Aprobar y Timbrar (CFDI)
                  </button>
                </div>
              </div>
            )}
          </div>
        )}

      </div>

      {/* Modal de Timbrado Exitoso */}
      {showInvoiceModal && (
        <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 animate-in fade-in duration-300">
           <div className="bg-white rounded-3xl p-10 max-w-sm w-full text-center shadow-2xl relative overflow-hidden animate-in zoom-in-95 duration-500">
              <div className="w-24 h-24 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-6">
                <CheckCircle2 size={48} />
              </div>
              <h2 className="text-2xl font-black text-slate-800 mb-2">¡Nómina Timbrada!</h2>
              <p className="text-slate-500 mb-8 text-sm">
                Se han generado 3 facturas XML y PDFs de nómina (CFDI 4.0). Los recibos han sido enviados automáticamente al portal de los colaboradores.
              </p>
              <button 
                onClick={() => {setShowInvoiceModal(false);}} 
                className="w-full bg-slate-900 text-white font-bold py-3.5 rounded-xl hover:bg-slate-800 transition-colors"
              >
                Cerrar y Finalizar
              </button>
           </div>
        </div>
      )}

    </div>
  );
}
