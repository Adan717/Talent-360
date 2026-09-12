import React, { useState, useEffect } from 'react';
import { EtiquetaCorregido, esFichajeCorregido } from './CorreccionDeFichaje';
import {
  DollarSign, CheckCircle2, AlertCircle, Calendar,
  Clock, Coffee, Fingerprint, RefreshCw, FileText,
  Trophy, AlertTriangle, Check
} from 'lucide-react';
import axiosInstance from '../../lib/axios';
import QuienEstaEnTienda from './QuienEstaEnTienda';

interface NominaColaboradorProps {
  isDark?: boolean;
}

export default function NominaColaborador({ isDark = false }: NominaColaboradorProps) {
  const [payroll, setPayroll] = useState<any>(null);
  // N3: lo firmable es la última semana CERRADA — se pide aparte (?period=closed).
  // `payroll` (semana en curso) es sólo la vista informativa en vivo.
  const [closedPayroll, setClosedPayroll] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');
  const [successMsg, setSuccessMsg] = useState('');

  // Estado para la aprobación diaria
  const [approvingDate, setApprovingDate] = useState<string | null>(null);

  // Estado para disputar/aclarar registro
  const [disputingDate, setDisputingDate] = useState<string | null>(null);
  const [disputeComment, setDisputeComment] = useState('');
  const [isSubmittingDispute, setIsSubmittingDispute] = useState(false);

  // Estado para la aceptación de nómina semanal
  const [isApprovingWeekly, setIsApprovingWeekly] = useState(false);

  const fetchPayroll = async () => {
    setIsLoading(true);
    setErrorMsg('');
    try {
      const [current, closed] = await Promise.all([
        axiosInstance.get('/employee/payroll-weekly'),
        axiosInstance.get('/employee/payroll-weekly?period=closed'),
      ]);
      if (current.data && current.data.success) {
        setPayroll(current.data.data);
      }
      if (closed.data && closed.data.success) {
        setClosedPayroll(closed.data.data);
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

  const handleDisputeDaily = async (date: string) => {
    if (!disputeComment.trim()) return;
    setIsSubmittingDispute(true);
    setErrorMsg('');
    try {
      const res = await axiosInstance.post('/employee/daily-records/approve', {
        date,
        status: 'disputed',
        comments: disputeComment
      });
      if (res.data && res.data.success) {
        setSuccessMsg(`Registro del día ${date} enviado a aclaración.`);
        setDisputeComment('');
        setDisputingDate(null);
        setTimeout(() => setSuccessMsg(''), 3000);
        fetchPayroll();
      }
    } catch (e) {
      console.error(e);
      setErrorMsg('Error al reportar inconformidad.');
    } finally {
      setIsSubmittingDispute(false);
    }
  };

  const handleApproveWeekly = async () => {
    setIsApprovingWeekly(true);
    setErrorMsg('');
    try {
      const res = await axiosInstance.post('/employee/payroll-weekly/approve');
      if (res.data && res.data.success) {
        setSuccessMsg('¡Nómina firmada de conformidad con éxito!');
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
        <div className="w-10 h-10 border-4 border-border border-t-accent rounded-full animate-spin mb-4"></div>
        <p className={`text-xs font-bold ${isDark ? 'text-slate-400' : 'text-text-3'}`}>Cargando desglose de pagos...</p>
      </div>
    );
  }

  if (errorMsg && !payroll) {
    return (
      <div className="p-8 text-center bg-danger-bg border border-danger-text/20 rounded-2xl max-w-sm mx-auto my-10">
        <AlertCircle className="text-danger-text mx-auto mb-3" size={32} />
        <h4 className="text-sm font-black text-danger-text mb-1">Error de conexión</h4>
        <p className="text-xs text-danger-text/80 mb-4">{errorMsg}</p>
        <button
          onClick={fetchPayroll}
          className="px-4 py-2 bg-danger-text text-white rounded-lg text-xs font-bold hover:bg-danger-text cursor-pointer border-none"
        >
          Reintentar
        </button>
      </div>
    );
  }

  if (!payroll) return null;

  // Candado de la firma semanal, sobre la semana CERRADA (N3):
  //  - un día ASISTIDO debe tener su firma diaria;
  //  - una FALTA no bloquea (no hay nada que firmar en un día sin asistencia — antes
  //    bloqueaba la firma para siempre, porque un día de falta no tiene botón de firma);
  //  - un día EN ACLARACIÓN sí bloquea hasta resolverse.
  const closedDays: any[] = closedPayroll?.days_details ?? [];
  const closedDaysReady = closedDays.every((d: any) =>
    d.is_rest_day || (d.attended ? d.approval_status === 'approved' : d.approval_status !== 'disputed')
  );
  const closedPeriodLabel = closedPayroll
    ? `${closedPayroll.period.start} al ${closedPayroll.period.end}`
    : '';

  return (
    <div className="space-y-4 pb-24 max-w-md mx-auto animate-in fade-in slide-in-from-bottom-4 duration-300">

      {/* Mensajes */}
      {successMsg && (
        <div className="p-3 bg-success-bg border border-success-text/20 text-success-text rounded-xl text-xs font-bold flex items-center gap-2">
          <CheckCircle2 size={16} className="text-success-text shrink-0" />
          {successMsg}
        </div>
      )}

      {/* Tarjeta de Nómina */}
      <div className={`p-4 rounded-2xl border shadow-xs relative overflow-hidden transition-all ${
        isDark
          ? 'bg-slate-900 border-slate-800 text-slate-100 shadow-[0_8px_32px_rgba(0,0,0,0.2)]'
          : 'bg-white border-border text-text-1 shadow-[0_8px_32px_rgba(124,58,237,0.04)]'
      }`}>
        <div className="absolute top-0 inset-x-0 h-1.5 bg-gradient-to-r from-accent to-accent"></div>

        <div className="flex items-center justify-between mb-3.5">
          <div>
            <h3 className="text-[10px] font-black uppercase tracking-wider text-slate-400">Periodo en Curso (estimado)</h3>
            <div className="flex items-baseline gap-0.5">
              <span className="text-2xl font-black">${payroll.salary.net.toFixed(2)}</span>
              <span className="text-[9px] text-slate-400 font-bold">MXN</span>
            </div>
            <p className="text-[9px] text-slate-400 font-semibold mt-0.5">
              {payroll.period.start} al {payroll.period.end} · el neto final se firma al cerrar el periodo
            </p>
          </div>
          <div className="p-2 bg-navy-50 text-accent rounded-xl border border-border/50">
            <DollarSign size={18} className="text-accent" />
          </div>
        </div>

        {/* Desglose */}
        <div className="space-y-2 border-t border-dashed border-border dark:border-slate-800 pt-3 text-[11px]">
          <div className="flex justify-between">
            <span className="text-slate-400 font-medium">Sueldo Base (6 días):</span>
            <span className="font-bold">${payroll.salary.base.toFixed(2)}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-slate-400 font-medium">
              Pago Séptimo Día{(payroll.incidents.rest_days_in_period ?? 1) > 1 ? `s (${payroll.incidents.rest_days_in_period} descansos)` : ' (Descanso)'}:
            </span>
            <span className="font-bold text-success-text">
              +${((payroll.incidents.rest_days_in_period ?? 1) * payroll.salary.daily * payroll.incidents.rest_day_proportion).toFixed(2)}
              <span className="text-[9px] text-slate-400 ml-1">({(payroll.incidents.rest_day_proportion * 100).toFixed(0)}%)</span>
            </span>
          </div>

          {payroll.deductions_breakdown.total > 0 && (
            <div className="border-t border-border dark:border-slate-800/55 pt-2 space-y-1">
              <div className="text-[9px] font-black uppercase text-danger-text tracking-wider">Deducciones LFT</div>

              {payroll.deductions_breakdown.absences > 0 && (
                <div className="flex justify-between text-[10.5px]">
                  <span className="text-slate-400 font-medium">Faltas ({payroll.incidents.total_absences} días):</span>
                  <span className="font-bold text-danger-text">-${payroll.deductions_breakdown.absences.toFixed(2)}</span>
                </div>
              )}
              {payroll.deductions_breakdown.rest_day > 0 && (
                <div className="flex justify-between text-[10.5px]">
                  <span className="text-slate-400 font-medium">Descanso Proporcional LFT:</span>
                  <span className="font-bold text-danger-text">-${payroll.deductions_breakdown.rest_day.toFixed(2)}</span>
                </div>
              )}
              {payroll.deductions_breakdown.lates > 0 && (
                <div className="flex justify-between text-[10.5px]">
                  <span className="text-slate-400 font-medium">Retardos ({payroll.incidents.lates} mins):</span>
                  <span className="font-bold text-danger-text">-${payroll.deductions_breakdown.lates.toFixed(2)}</span>
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      {/* Barra de Rendimiento Global Semanal (KPIs) */}
      <div className={`p-4 rounded-2xl border ${
        isDark ? 'bg-slate-900 border-slate-800' : 'bg-white border-border'
      }`}>
        <div className="flex items-center justify-between mb-2">
          <div className="flex items-center gap-1.5">
            <Trophy size={14} className="text-accent" />
            <h4 className="text-[11px] font-black uppercase tracking-wider text-slate-400">Rendimiento del Periodo</h4>
          </div>
          <span className={`text-sm font-black ${
            payroll.performance.performance_score >= 85
              ? 'text-success-text'
              : payroll.performance.performance_score >= 60
                ? 'text-warning-text'
                : 'text-danger-text'
          }`}>
            {payroll.performance.performance_score}%
          </span>
        </div>

        {/* Barra de Progreso */}
        <div className="w-full bg-page dark:bg-slate-800 h-2.5 rounded-full overflow-hidden mb-3">
          <div
            className={`h-full transition-all duration-500 rounded-full ${
              payroll.performance.performance_score >= 85
                ? 'bg-gradient-to-r from-success-icon to-success-icon shadow-[0_0_12px_rgba(16,185,129,0.3)]'
                : payroll.performance.performance_score >= 60
                  ? 'bg-gradient-to-r from-warning-icon to-warning-icon'
                  : 'bg-gradient-to-r from-danger-icon to-danger-icon'
            }`}
            style={{ width: `${payroll.performance.performance_score}%` }}
          />
        </div>

        {/* Métricas del Historial */}
        <div className="grid grid-cols-3 gap-2 text-center">
          <div className="bg-page dark:bg-slate-950/20 p-2 rounded-xl border border-border dark:border-slate-800/60">
            <span className="text-[9px] text-slate-400 font-bold block">Faltas / Retardos</span>
            <span className="text-[10.5px] font-black text-text-2 dark:text-slate-200">
              {payroll.incidents.total_absences} F / {payroll.incidents.lates} R
            </span>
          </div>
          <div className="bg-page dark:bg-slate-950/20 p-2 rounded-xl border border-border dark:border-slate-800/60">
            <span className="text-[9px] text-slate-400 font-bold block">Desvíos Comida</span>
            <span className="text-[10.5px] font-black text-text-2 dark:text-slate-200">
              {payroll.performance.meal_overtime_mins} min
            </span>
          </div>
          <div className="bg-page dark:bg-slate-950/20 p-2 rounded-xl border border-border dark:border-slate-800/60">
            <span className="text-[9px] text-slate-400 font-bold block">Tareas a Tiempo</span>
            <span className="text-[10.5px] font-black text-text-2 dark:text-slate-200">
              {payroll.performance.task_performance_pct}%
            </span>
          </div>
        </div>
      </div>

      {/* R95 (merge FE): widget "¿Quién está en tienda?" — presencia en vivo del equipo. */}
      <QuienEstaEnTienda isDark={isDark} />

      {/* Historial diario con firmas */}
      <div className={`p-4 rounded-2xl border ${
        isDark ? 'bg-slate-900 border-slate-800' : 'bg-white border-border'
      }`}>
        <h4 className="text-xs font-black uppercase tracking-wider text-slate-400 mb-3 flex items-center gap-1.5">
          <Calendar size={14} className="text-accent" />
          Aprobación Diaria de Registros
        </h4>

        <div className="divide-y divide-border dark:divide-slate-800">
          {payroll.days_details.map((day: any) => {
            const hasCheckIn = day.entries.some((e: any) => e.type === 'check_in');
            const hasCheckOut = day.entries.some((e: any) => e.type === 'check_out');
            // TRANSPARENCIA (2026-08-25): si a este día le corrigieron un registro, la persona
            // tiene que verlo aquí — es SU asistencia y es lo que la ley espera. El aviso privado
            // que el servidor le manda al reloj es la otra mitad; ésta es la que queda a la vista
            // cada vez que revisa sus horas antes de firmar.
            const diaCorregido = day.entries.some((e: any) => esFichajeCorregido(e));

            return (
              <div key={day.date} className="py-3 flex flex-col gap-2">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <div className="flex items-center gap-1.5">
                      <span className={`text-[12px] font-extrabold capitalize ${isDark ? 'text-slate-200' : 'text-text-1'}`}>{day.day_name}</span>
                      <span className="text-[10px] text-slate-400 font-medium">{day.date}</span>
                      {diaCorregido && <EtiquetaCorregido compacta />}
                    </div>

                    {day.is_rest_day ? (
                      <span className="text-[10px] text-success-text font-bold bg-success-bg dark:bg-success-text/20 px-1.5 py-0.5 rounded-md mt-1 inline-block">Día de Descanso</span>
                    ) : hasCheckIn ? (
                      <div className="flex items-center gap-2 mt-1 text-[10px] text-slate-400">
                        <span className="flex items-center gap-0.5"><Clock size={10} /> Ent: {day.entries.find((e: any) => e.type === 'check_in')?.time || '-'}</span>
                        <span className="flex items-center gap-0.5"><Clock size={10} /> Sal: {day.entries.find((e: any) => e.type === 'check_out')?.time || 'Faltante'}</span>
                      </div>
                    ) : day.day_over ? (
                      <span className="text-[10px] text-danger-text font-bold bg-danger-bg dark:bg-danger-text/20 px-1.5 py-0.5 rounded-md mt-1 inline-block">Falta Registrada</span>
                    ) : (
                      /* N3: un día que no ha terminado no es falta — todavía no ocurre. */
                      <span className="text-[10px] text-slate-400 font-bold bg-page dark:bg-slate-800/40 px-1.5 py-0.5 rounded-md mt-1 inline-block">Sin registro aún</span>
                    )}
                  </div>

                  {/* Acciones de Firma o Disputa */}
                  <div className="flex items-center gap-1 shrink-0">
                    {day.is_rest_day ? null : day.approval_status === 'approved' ? (
                      <span className="text-[10px] font-black text-success-text bg-success-bg dark:bg-success-text/30 px-2 py-0.5 rounded-full flex items-center gap-0.5">
                        <Check size={10} /> Firmado
                      </span>
                    ) : day.approval_status === 'disputed' ? (
                      <span className="text-[10px] font-black text-danger-text bg-danger-bg dark:bg-danger-text/30 px-2 py-0.5 rounded-full flex items-center gap-0.5">
                        <AlertTriangle size={10} /> En Aclaración
                      </span>
                    ) : hasCheckIn ? (
                      <div className="flex items-center gap-1.5">
                        <button
                          disabled={approvingDate === day.date}
                          onClick={() => handleApproveDaily(day.date)}
                          className="px-2 py-1 bg-accent hover:bg-accent-hover text-white rounded-lg text-[10.5px] font-black shadow-xs transition-all border-none cursor-pointer flex items-center gap-0.5 active:scale-95 disabled:opacity-50"
                        >
                          {approvingDate === day.date ? '...' : 'Firmar'}
                        </button>
                        <button
                          disabled={approvingDate === day.date}
                          onClick={() => {
                            setDisputingDate(day.date);
                            setDisputeComment('');
                          }}
                          className="px-2 py-1 bg-page hover:bg-danger-bg text-text-3 hover:text-danger-text rounded-lg text-[10.5px] font-black border border-border hover:border-danger-text/20 transition-all cursor-pointer active:scale-95"
                        >
                          Reclamar
                        </button>
                      </div>
                    ) : day.day_over ? (
                      <span className="text-[10.5px] font-bold text-danger-text bg-danger-bg dark:bg-danger-text/30 px-2 py-0.5 rounded-full flex items-center gap-0.5">
                        <AlertCircle size={10} /> Falta
                      </span>
                    ) : (
                      <span className="text-[10.5px] font-bold text-slate-400 bg-page dark:bg-slate-800/40 px-2 py-0.5 rounded-full">
                        Pendiente
                      </span>
                    )}
                  </div>
                </div>

                {/* Comentarios de aclaración */}
                {day.approval_status === 'disputed' && day.comments && (
                  <div className="text-[9.5px] bg-danger-bg/50 dark:bg-danger-text/10 text-danger-text p-2 rounded-xl border border-danger-text/50 font-medium">
                    <span className="font-extrabold block mb-0.5">Motivo reportado:</span>
                    "{day.comments}"
                  </div>
                )}

                {/* Formulario inline de disputa */}
                {disputingDate === day.date && (
                  <div className="bg-page dark:bg-slate-950/40 p-2.5 rounded-xl border border-border/60 flex flex-col gap-2 animate-fade-in text-left">
                    <span className="text-[9.5px] font-black text-text-3">¿Por qué no estás de acuerdo con este registro diario?</span>
                    <textarea
                      rows={2}
                      value={disputeComment}
                      onChange={(e) => setDisputeComment(e.target.value)}
                      placeholder="Describe el inconveniente o corrección necesaria (ej. Horario incorrecto)..."
                      className="w-full text-[11px] p-2 bg-white dark:bg-slate-900 border border-border dark:border-slate-800 rounded-lg text-text-1 dark:text-slate-100 focus:outline-none focus:border-danger-text font-medium resize-none"
                    />
                    <div className="flex justify-end gap-1.5">
                      <button
                        onClick={() => setDisputingDate(null)}
                        className="px-2 py-1 bg-white hover:bg-page text-text-3 rounded-lg text-[9.5px] font-bold border border-border transition-all cursor-pointer"
                      >
                        Cancelar
                      </button>
                      <button
                        disabled={isSubmittingDispute || !disputeComment.trim()}
                        onClick={() => handleDisputeDaily(day.date)}
                        className="px-2.5 py-1 bg-danger-text hover:bg-danger-text text-white rounded-lg text-[9.5px] font-black shadow-xs transition-all border-none cursor-pointer disabled:opacity-40"
                      >
                        {isSubmittingDispute ? 'Enviando...' : 'Enviar Aclaración'}
                      </button>
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>

      {/* Firma de la última semana CERRADA (N3: nunca se firma una semana en curso) */}
      {!closedPayroll ? null : closedPayroll.approval.is_approved ? (
        <div className="p-5 bg-success-bg border border-success-text/20 rounded-2xl text-center space-y-2">
          <div className="w-10 h-10 bg-success-bg text-success-text rounded-full flex items-center justify-center mx-auto">
            <CheckCircle2 size={24} />
          </div>
          <h4 className="text-sm font-black text-success-text">Pre-nómina aceptada</h4>
          <p className="text-[10.5px] text-success-text/80 leading-relaxed">
            Firmaste de conformidad el periodo del {closedPeriodLabel}
            {closedPayroll.approval.approved_at
              ? ` el ${new Date(closedPayroll.approval.approved_at).toLocaleString('es-MX')}`
              : ''}. Tu ticket impreso ya está disponible para recolección.
          </p>
        </div>
      ) : (
        <div className={`p-4 rounded-2xl border ${
          isDark ? 'bg-slate-900 border-slate-800' : 'bg-white border-border'
        } space-y-3.5`}>
          <div className="flex gap-2">
            <Fingerprint size={20} className="text-accent shrink-0" />
            <div>
              <h4 className={`text-xs font-black uppercase tracking-wider ${isDark ? 'text-slate-300' : 'text-text-2'}`}>Firma de pre-nómina del periodo</h4>
              <p className="text-[10.5px] text-slate-400 mt-0.5">
                Periodo cerrado del {closedPeriodLabel}. Revisa el desglose y firma de conformidad la liquidación calculada.
              </p>
            </div>
          </div>

          {/* Neto de la semana cerrada: ESTE es el importe que se firma */}
          <div className="flex items-baseline justify-between px-3 py-2 bg-navy-50/60 dark:bg-brand-dark/20 rounded-xl border border-border/60 dark:border-navy-800/40">
            <span className="text-[10px] font-black uppercase tracking-wider text-slate-400">Neto a Firmar</span>
            <span className="text-lg font-black text-accent">${closedPayroll.salary.net.toFixed(2)} <span className="text-[9px] text-slate-400">MXN</span></span>
          </div>

          {/* Días de la semana cerrada: qué se está firmando */}
          <div className="divide-y divide-border dark:divide-slate-800 border border-border dark:border-slate-800 rounded-xl overflow-hidden">
            {closedDays.map((d: any) => (
              <div key={d.date} className="flex items-center justify-between px-3 py-1.5 text-[10px]">
                <span className="font-bold text-text-3 capitalize">{d.day_name} <span className="text-slate-400 font-medium">{d.date}</span></span>
                {d.is_rest_day ? (
                  <span className="font-black text-success-text">Descanso</span>
                ) : d.approval_status === 'disputed' ? (
                  <span className="flex items-center gap-1.5">
                    <span className="font-black text-danger-text">En aclaración</span>
                    <button
                      disabled={approvingDate === d.date}
                      onClick={() => handleApproveDaily(d.date)}
                      className="px-1.5 py-0.5 bg-page hover:bg-navy-50 text-text-3 hover:text-accent rounded-md font-black border border-border cursor-pointer"
                    >
                      Retirar y firmar
                    </button>
                  </span>
                ) : !d.attended ? (
                  <span className="font-black text-danger-text">Falta</span>
                ) : d.approval_status === 'approved' ? (
                  <span className="font-black text-success-text flex items-center gap-0.5"><Check size={10} /> Firmado</span>
                ) : (
                  <button
                    disabled={approvingDate === d.date}
                    onClick={() => handleApproveDaily(d.date)}
                    className="px-2 py-0.5 bg-accent hover:bg-accent-hover text-white rounded-md font-black border-none cursor-pointer disabled:opacity-50"
                  >
                    {approvingDate === d.date ? '...' : 'Firmar día'}
                  </button>
                )}
              </div>
            ))}
          </div>

          <button
            disabled={isApprovingWeekly || !closedDaysReady}
            onClick={handleApproveWeekly}
            className={`w-full py-3 text-xs font-black uppercase tracking-wider rounded-xl shadow-md transition-all flex items-center justify-center gap-2 cursor-pointer border-none ${
              closedDaysReady
                ? 'bg-gradient-to-r from-accent to-accent text-white hover:opacity-90 active:scale-95'
                : 'bg-page text-slate-400 cursor-not-allowed'
            }`}
          >
            {isApprovingWeekly ? 'Procesando firma...' : 'Firmar Nómina de Conformidad'}
          </button>

          {!closedDaysReady && (
            <p className="text-[9.5px] text-danger-text font-bold text-center">
              *Firma tus días asistidos (y resuelve los días en aclaración) antes de firmar el periodo.
            </p>
          )}
        </div>
      )}

    </div>
  );
}
