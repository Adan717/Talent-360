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
        <div className="w-10 h-10 border-4 border-slate-200 border-t-indigo-600 rounded-full animate-spin mb-3"></div>
        <p className="text-xs font-semibold text-slate-500">Cargando configuración de nómina...</p>
      </div>
    );
  }

  return (
    <div className="max-w-3xl space-y-6">
      <div>
        <h3 className="text-xl font-black text-slate-800 tracking-tight flex items-center gap-2 mb-1">
          <DollarSign className="text-indigo-600" size={24} />
          Nómina y Periodicidad de Pago
        </h3>
        <p className="text-xs text-slate-500 font-medium">
          Define cada cuánto paga tu empresa. El generador nocturno, la firma del colaborador y el
          CFDI usan esta configuración.
        </p>
      </div>

      {successMsg && (
        <div className="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl flex items-center gap-3">
          <CheckCircle2 size={18} className="shrink-0 text-emerald-600" />
          <span className="text-xs font-bold">{successMsg}</span>
        </div>
      )}
      {errorMsg && (
        <div className="p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl flex items-center gap-3">
          <AlertCircle size={18} className="shrink-0 text-rose-600" />
          <span className="text-xs font-bold">{errorMsg}</span>
        </div>
      )}

      {!periodicityConfirmed && (
        <div className="p-4 bg-amber-50 border border-amber-200 rounded-2xl flex items-start gap-3">
          <AlertCircle size={16} className="text-amber-600 shrink-0 mt-0.5" />
          <p className="text-[11px] text-amber-900 font-semibold leading-relaxed">
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
                ? 'bg-indigo-50 border-indigo-300 ring-1 ring-indigo-300'
                : 'bg-white border-slate-200 hover:border-slate-300'
            }`}
          >
            <div className="flex items-center justify-between mb-1">
              <span className={`text-sm font-black ${periodicity === op.id ? 'text-indigo-700' : 'text-slate-800'}`}>
                {op.titulo}
              </span>
              <span className="text-[9px] font-black uppercase px-1.5 py-0.5 rounded-md bg-slate-100 text-slate-500">
                {op.codigo}
              </span>
            </div>
            <p className="text-[10.5px] text-slate-500 font-medium leading-snug">{op.desc}</p>
          </button>
        ))}
      </div>

      {/* Semana laboral (aplica al corte semanal y al séptimo día por semana natural) */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div className="space-y-1.5">
          <label className="text-xs font-bold text-slate-500">Día de inicio de la semana laboral</label>
          <select
            value={weekStartDay}
            onChange={(e) => setWeekStartDay(parseInt(e.target.value))}
            className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold outline-none focus:border-indigo-500"
          >
            {dias.map((d, i) => <option key={i} value={i}>{d}</option>)}
          </select>
          <p className="text-[10px] text-slate-400 font-medium">
            Define el corte semanal y las semanas del séptimo día en quincenal/mensual.
          </p>
        </div>
        <div className="space-y-1.5">
          <label className="text-xs font-bold text-slate-500">Día de pago</label>
          <select
            value={payDay}
            onChange={(e) => setPayDay(parseInt(e.target.value))}
            className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold outline-none focus:border-indigo-500"
          >
            {dias.map((d, i) => <option key={i} value={i}>{d}</option>)}
          </select>
        </div>
      </div>

      {/* Regla de cambio hacia adelante */}
      <div className="p-4 bg-slate-50 border border-slate-200 rounded-2xl flex items-start gap-3">
        <CalendarClock size={16} className="text-slate-500 shrink-0 mt-0.5" />
        <p className="text-[11px] text-slate-600 font-medium leading-relaxed">
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
          className="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-black shadow-md transition-all cursor-pointer border-none flex items-center gap-2 disabled:opacity-50"
        >
          {isSaving ? <RefreshCw size={14} className="animate-spin" /> : <Save size={14} />}
          {isSaving ? 'Guardando...' : 'Guardar Configuración de Nómina'}
        </button>
      </div>
    </div>
  );
}
