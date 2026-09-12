import React, { useState, useEffect } from 'react';
import { DollarSign, Save, RefreshCw, CheckCircle2, AlertCircle, CalendarClock } from 'lucide-react';
import axiosInstance from '../lib/axios';

/**
 * #17 — Periodicidad de pago de la empresa (semanal / quincenal / mensual).
 * Hasta 2026-08-07 este dato solo se podía fijar por API: el backend ya lo validaba y el
 * batch, la firma, las pantallas admin y el CFDI ya lo respetan — faltaba la pantalla.
 * Regla del jefe: el cambio de periodicidad aplica desde el SIGUIENTE periodo; los recibos
 * ya generados no se tocan.
 */
export default function NominaSettingsPanel() {
  const [periodicity, setPeriodicity] = useState('semanal');
  const [periodicityConfirmed, setPeriodicityConfirmed] = useState(false);
  const [weekStartDay, setWeekStartDay] = useState(1);
  const [payDay, setPayDay] = useState(5);
  const [isLoading, setIsLoading] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [successMsg, setSuccessMsg] = useState('');
  const [errorMsg, setErrorMsg] = useState('');

  const dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

  const fetchSettings = async () => {
    setIsLoading(true);
    setErrorMsg('');
    try {
      const res = await axiosInstance.get('/company/payroll-settings');
      if (res.data?.success) {
        setPeriodicity(res.data.periodicity || 'semanal');
        setPeriodicityConfirmed(!!res.data.periodicity_confirmed);
        setWeekStartDay(res.data.week_start_day ?? 1);
        setPayDay(res.data.pay_day ?? 5);
      }
    } catch (e) {
      console.error(e);
      setErrorMsg('No se pudo cargar la configuración de nómina.');
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => { fetchSettings(); }, []);

  const handleSave = async () => {
    setIsSaving(true);
    setSuccessMsg('');
    setErrorMsg('');
    try {
      const res = await axiosInstance.put('/company/payroll-settings', {
        periodicity,
        week_start_day: weekStartDay,
        pay_day: payDay,
      });
      if (res.data?.success) {
        setPeriodicityConfirmed(true);
        setSuccessMsg('Configuración de nómina guardada. Los cambios aplican a partir del siguiente periodo.');
        setTimeout(() => setSuccessMsg(''), 6000);
      }
    } catch (e: any) {
      console.error(e);
      setErrorMsg(e.response?.data?.message || 'Error al guardar la configuración de nómina.');
    } finally {
      setIsSaving(false);
    }
  };

  const opciones = [
    { id: 'semanal', titulo: 'Semanal', desc: 'Un recibo por semana laboral (el estándar LFT para operativos). El día de inicio de semana define el corte.', codigo: 'SAT 02' },
    { id: 'quincenal', titulo: 'Quincenal', desc: 'Dos recibos al mes: del 1 al 15 y del 16 al fin de mes (quincenas naturales).', codigo: 'SAT 04' },
    { id: 'mensual', titulo: 'Mensual', desc: 'Un recibo por mes calendario.', codigo: 'SAT 05' },
  ];

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center py-16">
        <div className="w-10 h-10 border-4 border-border border-t-accent rounded-full animate-spin mb-3"></div>
        <p className="text-xs font-semibold text-text-3">Cargando configuración de nómina...</p>
      </div>
    );
  }

  return (
    <div className="max-w-3xl space-y-6">
      <div>
        <h3 className="text-xl font-black text-text-1 tracking-tight flex items-center gap-2 mb-1">
          <DollarSign className="text-accent" size={24} />
          Pre-nómina y periodicidad de pago
        </h3>
        <p className="text-xs text-text-3 font-medium">
          Define cada cuánto paga tu empresa. El generador nocturno, la firma del colaborador y el
          CFDI usan esta configuración.
        </p>
      </div>

      {successMsg && (
        <div className="p-4 bg-success-bg border border-success-text/20 text-success-text rounded-2xl flex items-center gap-3">
          <CheckCircle2 size={18} className="shrink-0 text-success-text" />
          <span className="text-xs font-bold">{successMsg}</span>
        </div>
      )}
      {errorMsg && (
        <div className="p-4 bg-danger-bg border border-danger-text/20 text-danger-text rounded-2xl flex items-center gap-3">
          <AlertCircle size={18} className="shrink-0 text-danger-text" />
          <span className="text-xs font-bold">{errorMsg}</span>
        </div>
      )}

      {!periodicityConfirmed && (
        <div className="p-4 bg-warning-bg border border-warning-text/20 rounded-2xl flex items-start gap-3">
          <AlertCircle size={16} className="text-warning-text shrink-0 mt-0.5" />
          <p className="text-[11px] text-warning-text font-semibold leading-relaxed">
            Tu empresa aún no ha declarado su periodicidad: el sistema viene asumiendo
            <strong> semanal</strong>. Confírmala (o cámbiala) y guarda — a partir de ahí es un dato
            tuyo, no una suposición.
          </p>
        </div>
      )}

      {/* Periodicidad */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
        {opciones.map((op) => (
          <button
            key={op.id}
            type="button"
            onClick={() => setPeriodicity(op.id)}
            className={`p-4 rounded-2xl border text-left transition-all cursor-pointer ${
              periodicity === op.id
                ? 'bg-navy-50 border-navy-300 ring-1 ring-focus-ring'
                : 'bg-white border-border hover:border-slate-300'
            }`}
          >
            <div className="flex items-center justify-between mb-1">
              <span className={`text-sm font-black ${periodicity === op.id ? 'text-accent' : 'text-text-1'}`}>
                {op.titulo}
              </span>
              <span className="text-[9px] font-black uppercase px-1.5 py-0.5 rounded-md bg-page text-text-3">
                {op.codigo}
              </span>
            </div>
            <p className="text-[10.5px] text-text-3 font-medium leading-snug">{op.desc}</p>
          </button>
        ))}
      </div>

      {/* Semana laboral (aplica al corte semanal y al séptimo día por semana natural) */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div className="space-y-1.5">
          <label className="text-xs font-bold text-text-3">Día de inicio de la semana laboral</label>
          <select
            value={weekStartDay}
            onChange={(e) => setWeekStartDay(parseInt(e.target.value))}
            className="w-full px-4 py-2.5 bg-page border border-border rounded-xl text-text-1 font-semibold outline-none focus:border-accent"
          >
            {dias.map((d, i) => <option key={i} value={i}>{d}</option>)}
          </select>
          <p className="text-[10px] text-slate-400 font-medium">
            Define el corte semanal y las semanas del séptimo día en quincenal/mensual.
          </p>
        </div>
        <div className="space-y-1.5">
          <label className="text-xs font-bold text-text-3">Día de pago</label>
          <select
            value={payDay}
            onChange={(e) => setPayDay(parseInt(e.target.value))}
            className="w-full px-4 py-2.5 bg-page border border-border rounded-xl text-text-1 font-semibold outline-none focus:border-accent"
          >
            {dias.map((d, i) => <option key={i} value={i}>{d}</option>)}
          </select>
        </div>
      </div>

      {/* Regla de cambio hacia adelante */}
      <div className="p-4 bg-page border border-border rounded-2xl flex items-start gap-3">
        <CalendarClock size={16} className="text-text-3 shrink-0 mt-0.5" />
        <p className="text-[11px] text-text-2 font-medium leading-relaxed">
          Si cambias la periodicidad, el cambio aplica <strong>a partir del siguiente periodo</strong>:
          los recibos ya generados o firmados no se modifican, y el sistema no genera recibos nuevos
          sobre días que un recibo firmado ya cubre.
        </p>
      </div>

      <div className="flex justify-end">
        <button
          type="button"
          onClick={handleSave}
          disabled={isSaving}
          className="px-6 py-2.5 bg-accent hover:bg-accent-hover text-white rounded-xl text-xs font-black shadow-md transition-all cursor-pointer border-none flex items-center gap-2 disabled:opacity-50"
        >
          {isSaving ? <RefreshCw size={14} className="animate-spin" /> : <Save size={14} />}
          {isSaving ? 'Guardando...' : 'Guardar Configuración de Nómina'}
        </button>
      </div>
    </div>
  );
}
