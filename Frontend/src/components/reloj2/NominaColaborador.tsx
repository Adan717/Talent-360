import React, { useState, useEffect } from 'react';
import { 
  DollarSign, CheckCircle2, AlertCircle, Calendar, 
  Clock, Coffee, Fingerprint, RefreshCw, FileText
} from 'lucide-react';
import axiosInstance from '../../lib/axios';

interface NominaColaboradorProps {
  isDark?: boolean;
}

export default function NominaColaborador({ isDark = false }: NominaColaboradorProps) {
  const [payroll, setPayroll] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');
  const [successMsg, setSuccessMsg] = useState('');
  
  // Estado para la aprobación diaria
  const [approvingDate, setApprovingDate] = useState<string | null>(null);
  
  // Estado para la aceptación de nómina semanal
  const [isApprovingWeekly, setIsApprovingWeekly] = useState(false);

  const fetchPayroll = async () => {
    setIsLoading(true);
    setErrorMsg('');
    try {
      const res = await axiosInstance.get('/employee/payroll-weekly');
      if (res.data && res.data.success) {
        setPayroll(res.data.data);
      }
    } catch (e) {
      console.error(e);
      setErrorMsg('No se pudieron cargar los datos de nómina.');
    } finally {
      setIsLoading(false);
    }
  };

  const handleApproveDaily = async (date: string) => {
    setApprovingDate(date);
    setErrorMsg('');
    try {
      const res = await axiosInstance.post('/employee/daily-records/approve', {
        date,
        status: 'approved'
      });
      if (res.data && res.data.success) {
        setSuccessMsg(`Registro del día ${date} aprobado con éxito.`);
        setTimeout(() => setSuccessMsg(''), 3000);
        // Actualizar datos locales
        fetchPayroll();
      }
    } catch (e) {
      console.error(e);
      setErrorMsg('Error al aprobar el registro diario.');
    } finally {
      setApprovingDate(null);
    }
  };

  const handleApproveWeekly = async () => {
    setIsApprovingWeekly(true);
    setErrorMsg('');
    try {
      const res = await axiosInstance.post('/employee/payroll-weekly/approve');
      if (res.data && res.data.success) {
        setSuccessMsg('¡Nómina semanal firmada de conformidad con éxito!');
        setTimeout(() => setSuccessMsg(''), 5000);
        fetchPayroll();
      }
    } catch (e) {
      console.error(e);
      setErrorMsg('Error al firmar la nómina semanal.');
    } finally {
      setIsApprovingWeekly(false);
    }
  };

  useEffect(() => {
    fetchPayroll();
  }, []);

  if (isLoading && !payroll) {
    return (
      <div className="flex flex-col items-center justify-center p-12 text-center h-[400px]">
        <div className="w-10 h-10 border-4 border-slate-200 border-t-violet-600 rounded-full animate-spin mb-4"></div>
        <p className={`text-xs font-bold ${isDark ? 'text-slate-400' : 'text-slate-500'}`}>Cargando desglose de pagos...</p>
      </div>
    );
  }

  if (errorMsg && !payroll) {
    return (
      <div className="p-8 text-center bg-rose-50 border border-rose-100 rounded-2xl max-w-sm mx-auto my-10">
        <AlertCircle className="text-rose-600 mx-auto mb-3" size={32} />
        <h4 className="text-sm font-black text-rose-900 mb-1">Error de conexión</h4>
        <p className="text-xs text-rose-700/80 mb-4">{errorMsg}</p>
        <button 
          onClick={fetchPayroll}
          className="px-4 py-2 bg-rose-600 text-white rounded-lg text-xs font-bold hover:bg-rose-700 cursor-pointer border-none"
        >
          Reintentar
        </button>
      </div>
    );
  }

  if (!payroll) return null;

  const isSaturday = new Date().getDay() === 6; // Sábado
  const allDaysApproved = payroll.days_details.every((d: any) => d.is_rest_day || d.approval_status === 'approved');

  return (
    <div className="space-y-5 pb-24 max-w-md mx-auto animate-in fade-in slide-in-from-bottom-4 duration-300">
      
      {/* Mensajes */}
      {successMsg && (
        <div className="p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-xs font-bold flex items-center gap-2">
          <CheckCircle2 size={16} className="text-emerald-600 shrink-0" />
          {successMsg}
        </div>
      )}

      {/* Tarjeta de Nómina */}
      <div className={`p-5 rounded-2xl border shadow-sm relative overflow-hidden transition-all ${
        isDark 
          ? 'bg-slate-900 border-slate-800 text-slate-100 shadow-[0_8px_32px_rgba(0,0,0,0.2)]' 
          : 'bg-white border-slate-200 text-slate-800 shadow-[0_8px_32px_rgba(124,58,237,0.04)]'
      }`}>
        <div className="absolute top-0 inset-x-0 h-1.5 bg-gradient-to-r from-violet-500 to-[#8a2be2]"></div>
        
        <div className="flex items-center justify-between mb-4">
          <div>
            <h3 className="text-xs font-black uppercase tracking-wider text-slate-400">Neto a Recibir</h3>
            <div className="flex items-baseline gap-0.5">
              <span className="text-2xl font-black">${payroll.salary.net.toFixed(2)}</span>
              <span className="text-[10px] text-slate-400 font-bold">MXN</span>
            </div>
          </div>
          <div className="p-2.5 bg-violet-55 text-violet-600 rounded-xl border border-violet-100/50">
            <DollarSign size={20} className="text-[#8a2be2]" />
          </div>
        </div>

        {/* Desglose */}
        <div className="space-y-2 border-t border-dashed border-slate-200 dark:border-slate-800 pt-3 text-xs">
          <div className="flex justify-between">
            <span className="text-slate-400 font-medium">Sueldo Base (6 días):</span>
            <span className="font-bold">${payroll.salary.base.toFixed(2)}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-slate-400 font-medium">Pago Séptimo Día (Descanso):</span>
            <span className="font-bold text-emerald-600">
              +${(payroll.salary.daily * payroll.incidents.rest_day_proportion).toFixed(2)}
              <span className="text-[9px] text-slate-400 ml-1">({(payroll.incidents.rest_day_proportion * 100).toFixed(0)}%)</span>
            </span>
          </div>

          {payroll.deductions_breakdown.total > 0 && (
            <div className="border-t border-slate-100 dark:border-slate-800/55 pt-2 space-y-1">
              <div className="text-[10px] font-black uppercase text-rose-500 tracking-wider">Deducciones LFT</div>
              
              {payroll.deductions_breakdown.absences > 0 && (
                <div className="flex justify-between text-[11px]">
                  <span className="text-slate-400 font-medium">Faltas ({payroll.incidents.total_absences} días):</span>
                  <span className="font-bold text-rose-600">-${payroll.deductions_breakdown.absences.toFixed(2)}</span>
                </div>
              )}
              {payroll.deductions_breakdown.rest_day > 0 && (
                <div className="flex justify-between text-[11px]">
                  <span className="text-slate-400 font-medium">Descuento Descanso Proporcional:</span>
                  <span className="font-bold text-rose-600">-${payroll.deductions_breakdown.rest_day.toFixed(2)}</span>
                </div>
              )}
              {payroll.deductions_breakdown.lates > 0 && (
                <div className="flex justify-between text-[11px]">
                  <span className="text-slate-400 font-medium">Retardos ({payroll.incidents.lates} mins):</span>
                  <span className="font-bold text-rose-600">-${payroll.deductions_breakdown.lates.toFixed(2)}</span>
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      {/* Historial diario con firmas */}
      <div className={`p-4 rounded-2xl border ${
        isDark ? 'bg-slate-900 border-slate-800' : 'bg-white border-slate-200'
      }`}>
        <h4 className="text-xs font-black uppercase tracking-wider text-slate-400 mb-3 flex items-center gap-1.5">
          <Calendar size={14} className="text-[#8a2be2]" />
          Aprobación Diaria de Registros
        </h4>

        <div className="divide-y divide-slate-100 dark:divide-slate-800">
          {payroll.days_details.map((day: any) => {
            const hasCheckIn = day.entries.some((e: any) => e.type === 'check_in');
            const hasCheckOut = day.entries.some((e: any) => e.type === 'check_out');
            
            return (
              <div key={day.date} className="py-3 flex items-center justify-between gap-3">
                <div>
                  <div className="flex items-center gap-1.5">
                    <span className={`text-[12px] font-extrabold capitalize ${isDark ? 'text-slate-200' : 'text-slate-800'}`}>{day.day_name}</span>
                    <span className="text-[10px] text-slate-400 font-medium">{day.date}</span>
                  </div>
                  
                  {day.is_rest_day ? (
                    <span className="text-[10px] text-emerald-600 font-bold bg-emerald-50 dark:bg-emerald-950/20 px-1.5 py-0.5 rounded-md mt-1 inline-block">Día de Descanso</span>
                  ) : hasCheckIn ? (
                    <div className="flex items-center gap-2 mt-1 text-[10px] text-slate-400">
                      <span className="flex items-center gap-0.5"><Clock size={10} /> Ent: {day.entries.find((e: any) => e.type === 'check_in')?.time || '-'}</span>
                      <span className="flex items-center gap-0.5"><Clock size={10} /> Sal: {day.entries.find((e: any) => e.type === 'check_out')?.time || 'Faltante'}</span>
                    </div>
                  ) : (
                    <span className="text-[10px] text-rose-500 font-bold bg-rose-50 dark:bg-rose-950/20 px-1.5 py-0.5 rounded-md mt-1 inline-block">Falta Registrada</span>
                  )}
                </div>

                {/* Acción de Firma diaria */}
                {day.is_rest_day ? null : day.approval_status === 'approved' ? (
                  <span className="text-[10.5px] font-extrabold text-emerald-600 bg-emerald-50 dark:bg-emerald-950/30 px-2.5 py-1 rounded-full flex items-center gap-1">
                    <CheckCircle2 size={12} /> Firmado
                  </span>
                ) : hasCheckIn ? (
                  <button
                    disabled={approvingDate === day.date}
                    onClick={() => handleApproveDaily(day.date)}
                    className="px-3 py-1.5 bg-[#8a2be2] hover:bg-violet-750 text-white rounded-lg text-[10.5px] font-extrabold shadow-sm transition-all border-none cursor-pointer flex items-center gap-1 active:scale-95 disabled:opacity-50"
                  >
                    {approvingDate === day.date ? 'Firmando...' : 'Firmar Registro'}
                  </button>
                ) : (
                  <span className="text-[10.5px] font-bold text-rose-500 bg-rose-50 dark:bg-rose-950/30 px-2.5 py-1 rounded-full flex items-center gap-1">
                    <AlertCircle size={12} /> Falta
                  </span>
                )}
              </div>
            );
          })}
        </div>
      </div>

      {/* Firmar Nómina Semanal (Sábado de corte) */}
      {payroll.approval.is_approved ? (
        <div className="p-5 bg-emerald-50 border border-emerald-100 rounded-2xl text-center space-y-2">
          <div className="w-10 h-10 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto">
            <CheckCircle2 size={24} />
          </div>
          <h4 className="text-sm font-black text-emerald-900">Nómina Aceptada</h4>
          <p className="text-[10.5px] text-emerald-700/80 leading-relaxed">
            Has firmado de conformidad esta nómina el {new Date(payroll.approval.approved_at).toLocaleString('es-MX')}. Tu ticket impreso ya está disponible para recolección.
          </p>
        </div>
      ) : (
        <div className={`p-5 rounded-2xl border ${
          isDark ? 'bg-slate-900 border-slate-800' : 'bg-white border-slate-200'
        } space-y-4`}>
          <div className="flex gap-2">
            <Fingerprint size={20} className="text-[#8a2be2] shrink-0" />
            <div>
              <h4 className={`text-xs font-black uppercase tracking-wider ${isDark ? 'text-slate-350' : 'text-slate-700'}`}>Firma de Nómina Semanal</h4>
              <p className="text-[10.5px] text-slate-400 mt-0.5">
                Para cerrar el periodo del sábado, primero debes firmar de conformidad tus asistencias diarias y la liquidación calculada.
              </p>
            </div>
          </div>

          <button
            disabled={isApprovingWeekly || !allDaysApproved}
            onClick={handleApproveWeekly}
            className={`w-full py-3 text-xs font-black uppercase tracking-wider rounded-xl shadow-md transition-all flex items-center justify-center gap-2 cursor-pointer border-none ${
              allDaysApproved 
                ? 'bg-gradient-to-r from-violet-600 to-[#8a2be2] text-white hover:opacity-90 active:scale-95' 
                : 'bg-slate-150 text-slate-400 cursor-not-allowed'
            }`}
          >
            {isApprovingWeekly ? 'Procesando firma...' : 'Firmar Nómina de Conformidad'}
          </button>

          {!allDaysApproved && (
            <p className="text-[9.5px] text-rose-500 font-bold text-center">
              *Debes firmar todos tus registros de asistencia diarios antes de firmar la nómina semanal.
            </p>
          )}
        </div>
      )}
      
    </div>
  );
}
