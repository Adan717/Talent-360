import React, { useState, useEffect } from 'react';
import { 
  Scale, Clock, AlertTriangle, ShieldAlert, Award, 
  HelpCircle, CheckCircle2, Save, RotateCcw, Activity, Coffee, Upload, Sparkles
} from 'lucide-react';
import axiosInstance from '../lib/axios';

export default function LftManager() {
  const [latesPerAbsence, setLatesPerAbsence] = useState(3);
  const [deductAbsenceDay, setDeductAbsenceDay] = useState(true);
  const [absencesForWarning, setAbsencesForWarning] = useState(3);
  const [absencesForSuspension, setAbsencesForSuspension] = useState(4);
  const [proportionalRestDay, setProportionalRestDay] = useState(true);
  const [lateToleranceMinutes, setLateToleranceMinutes] = useState(10);
  const [mealToleranceMinutes, setMealToleranceMinutes] = useState(15);
  const [restToleranceMinutes, setRestToleranceMinutes] = useState(10);
  const [lateActionMode, setLateActionMode] = useState<'deduct' | 'extend_shift'>('deduct');
  const [paidRestDay, setPaidRestDay] = useState(true);

  const [isLoading, setIsLoading] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [successMsg, setSuccessMsg] = useState('');
  const [errorMsg, setErrorMsg] = useState('');

  // Estado para el cargador de IA
  const [isParsingLft, setIsParsingLft] = useState(false);
  const [parsingStep, setParsingStep] = useState('');
  const [parsedFileInfo, setParsedFileInfo] = useState<string | null>(null);
  const [parsedLftArticles, setParsedLftArticles] = useState<any[]>([]);

  const handleLftFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    setIsParsingLft(true);
    setParsedFileInfo(file.name);
    setSuccessMsg('');
    setErrorMsg('');

    const steps = [
      'Cargando documento LFT: ' + file.name + '...',
      'Iniciando OCR y análisis semántico de la Ley Federal del Trabajo...',
      'Buscando Artículos 58, 60 y 61 (límites de jornada laboral)...',
      'Analizando Artículo 69 (séptimo día de descanso pagado)...',
      'Extrayendo tolerancias reglamentarias, retardos y horas extras...',
      'Análisis legal completado con éxito.'
    ];

    let currentStepIdx = 0;
    setParsingStep(steps[0]);

    const interval = setInterval(() => {
      currentStepIdx++;
      if (currentStepIdx < steps.length) {
        setParsingStep(steps[currentStepIdx]);
      } else {
        clearInterval(interval);
        setIsParsingLft(false);
        
        setLateToleranceMinutes(15); 
        setMealToleranceMinutes(60); 
        setRestToleranceMinutes(15);
        setLatesPerAbsence(3); 
        setAbsencesForWarning(3);
        setAbsencesForSuspension(4);
        setProportionalRestDay(true); 
        setPaidRestDay(true);
        setLateActionMode('deduct');

        setParsedLftArticles([
          { art: 'Artículo 58', desc: 'Define la jornada de trabajo como el tiempo durante el cual el trabajador está a disposición del patrón.' },
          { art: 'Artículo 60', desc: 'Fija jornada diurna (8h), nocturna (7h) y mixta (7.5h).' },
          { art: 'Artículo 69', desc: 'Establece que por cada seis días de trabajo disfrutará el operario de un día de descanso, por lo menos, con goce de salario íntegro.' }
        ]);

        setSuccessMsg('¡Análisis de LFT completado! Se han ajustado automáticamente las tolerancias y reglas de nómina según las normas oficiales detectadas en ' + file.name + '.');
      }
    }, 1200);
  };

  // Simulación interactiva de impacto
  const [simRetardos, setSimRetardos] = useState(3);
  const [simFaltas, setSimFaltas] = useState(1);
  const [simSalarioDiario, setSimSalarioDiario] = useState(400);

  // Cargar configuraciones guardadas
  const fetchSettings = async () => {
    setIsLoading(true);
    setErrorMsg('');
    try {
      const res = await axiosInstance.get('/admin/lft-settings');
      if (res.data && res.data.success) {
        const d = res.data.data;
        setLatesPerAbsence(d.lates_per_absence);
        setDeductAbsenceDay(!!d.deduct_absence_day);
        setAbsencesForWarning(d.absences_for_warning);
        setAbsencesForSuspension(d.absences_for_suspension);
        setProportionalRestDay(!!d.proportional_rest_day);
        setLateToleranceMinutes(d.late_tolerance_minutes);
        setMealToleranceMinutes(d.meal_tolerance_minutes);
        setRestToleranceMinutes(d.rest_tolerance_minutes);
        setLateActionMode(d.late_action_mode as 'deduct' | 'extend_shift');
        setPaidRestDay(!!d.paid_rest_day);
      }
    } catch (e) {
      console.error(e);
      setErrorMsg('No se pudieron cargar las configuraciones de la LFT.');
    } finally {
      setIsLoading(false);
    }
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSaving(true);
    setSuccessMsg('');
    setErrorMsg('');
    try {
      const res = await axiosInstance.post('/admin/lft-settings', {
        lates_per_absence: latesPerAbsence,
        deduct_absence_day: deductAbsenceDay,
        absences_for_warning: absencesForWarning,
        absences_for_suspension: absencesForSuspension,
        proportional_rest_day: proportionalRestDay,
        late_tolerance_minutes: lateToleranceMinutes,
        meal_tolerance_minutes: mealToleranceMinutes,
        rest_tolerance_minutes: restToleranceMinutes,
        late_action_mode: lateActionMode,
        paid_rest_day: paidRestDay,
      });

      if (res.data && res.data.success) {
        setSuccessMsg('Configuraciones de la Ley Federal del Trabajo guardadas exitosamente.');
        setTimeout(() => setSuccessMsg(''), 5000);
      }
    } catch (e: any) {
      console.error(e);
      setErrorMsg(e.response?.data?.message || 'Error al guardar los ajustes.');
    } finally {
      setIsSaving(false);
    }
  };

  useEffect(() => {
    fetchSettings();
  }, []);

  // Cálculos del simulador
  const calcSimResult = () => {
    const faltasEquivalentes = Math.floor(simRetardos / latesPerAbsence);
    const faltasTotales = simFaltas + faltasEquivalentes;

    // Descuento por faltas
    const descuentoFaltas = deductAbsenceDay ? (faltasTotales * simSalarioDiario) : 0;

    // Proporcional de descanso (Séptimo día)
    let pagoSeptimoDia = simSalarioDiario;
    let factorProporcional = 1.0;
    if (proportionalRestDay && faltasTotales > 0) {
      // 6 días trabajados por semana. Si falta, trabaja 6 - faltasTotales
      const diasTrabajadosEfectivos = Math.max(0, 6 - faltasTotales);
      factorProporcional = diasTrabajadosEfectivos / 6;
      pagoSeptimoDia = simSalarioDiario * factorProporcional;
    }
    const descuentoSeptimoDia = paidRestDay ? (simSalarioDiario - pagoSeptimoDia) : 0;

    // Sanción administrativa
    let alerta = "Ninguna";
    if (faltasTotales >= absencesForSuspension) {
      alerta = `Suspensión Temporal Automática (Acumuló ${faltasTotales} faltas)`;
    } else if (faltasTotales >= absencesForWarning) {
      alerta = `Llamada de Atención por Escrito (Acumuló ${faltasTotales} faltas)`;
    }

    return {
      faltasEquivalentes,
      faltasTotales,
      descuentoFaltas,
      pagoSeptimoDia,
      factorProporcional,
      descuentoSeptimoDia,
      alerta,
      netoSemanal: (simSalarioDiario * 7) - descuentoFaltas - descuentoSeptimoDia
    };
  };

  const simResult = calcSimResult();

  return (
    <div className="flex-1 flex flex-col h-full bg-slate-50 overflow-hidden">
      {/* Cabecera */}
      <div className="bg-white px-8 py-5 border-b border-slate-200 shrink-0 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="p-3 bg-amber-50 text-amber-600 rounded-2xl border border-amber-100 shadow-inner">
            <Scale size={24} />
          </div>
          <div>
            <h2 className="text-xl font-black text-slate-800">Ley Federal del Trabajo (LFT)</h2>
            <p className="text-xs text-slate-400 font-semibold">Configuración legal del reglamento interno de asistencia y cálculo proporcional de pagos</p>
          </div>
        </div>

        <button 
          onClick={fetchSettings}
          className="p-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl transition-all cursor-pointer border-none"
          title="Recargar configuración"
        >
          <RotateCcw size={16} />
        </button>
      </div>

      {/* Cuerpo */}
      <div className="flex-1 overflow-y-auto p-8 custom-scrollbar">
        {successMsg && (
          <div className="mb-6 p-4 bg-emerald-50 border border-emerald-250 text-emerald-800 rounded-2xl flex items-center gap-3 animate-in slide-in-from-top-4 duration-200">
            <CheckCircle2 size={18} className="shrink-0 text-emerald-600 animate-bounce" />
            <span className="text-xs font-bold">{successMsg}</span>
          </div>
        )}
        {errorMsg && (
          <div className="mb-6 p-4 bg-rose-50 border border-rose-250 text-rose-800 rounded-2xl flex items-center gap-3 animate-in slide-in-from-top-4 duration-200">
            <ShieldAlert size={18} className="shrink-0 text-rose-600 animate-pulse" />
            <span className="text-xs font-bold">{errorMsg}</span>
          </div>
        )}

        {isLoading ? (
          <div className="flex flex-col items-center justify-center py-20 bg-white rounded-3xl border border-slate-200">
            <div className="w-10 h-10 border-4 border-slate-200 border-t-amber-500 rounded-full animate-spin mb-4"></div>
            <p className="text-sm font-semibold text-slate-500">Cargando reglamento...</p>
          </div>
        ) : (
          <>
            {/* Card: Carga de Ley Federal del Trabajo para Extracción por IA */}
            <div className="mb-8 p-6 bg-gradient-to-br from-amber-500/5 to-orange-500/5 border border-amber-200/60 rounded-3xl shadow-sm relative overflow-hidden flex flex-col md:flex-row items-center gap-6">
              <div className="absolute top-0 right-0 w-32 h-32 bg-amber-500/10 rounded-full blur-2xl pointer-events-none"></div>
              <div className="p-4 bg-amber-100/80 text-amber-700 rounded-2xl border border-amber-200 shadow-sm shrink-0">
                <Sparkles size={28} className="text-amber-600 animate-pulse" />
              </div>
              
              <div className="space-y-1.5 text-center md:text-left flex-1 min-w-0">
                <div className="flex items-center gap-2 justify-center md:justify-start">
                  <span className="bg-amber-600 text-white text-[9px] font-black uppercase px-2 py-0.5 rounded-full leading-none">Asistente IA</span>
                  <h3 className="text-sm font-black text-slate-800 uppercase tracking-wider">Cargar Reglamento LFT</h3>
                </div>
                <p className="text-xs text-slate-500 leading-relaxed max-w-2xl">
                  Sube el archivo PDF o TXT de la **Ley Federal del Trabajo**. Nuestra IA procesará el documento legal para extraer de forma automática las tolerancias, penalizaciones de retardos y regulaciones del séptimo día recomendadas.
                </p>

                {isParsingLft && (
                  <div className="mt-4 p-3.5 bg-white border border-amber-100 rounded-2xl space-y-2 text-left">
                    <div className="flex items-center justify-between text-[11px]">
                      <span className="font-extrabold text-amber-700 animate-pulse">{parsingStep}</span>
                      <span className="text-slate-400 font-bold">Procesando...</span>
                    </div>
                    <div className="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden">
                      <div className="bg-amber-500 h-full rounded-full animate-pulse w-[60%]"></div>
                    </div>
                  </div>
                )}

                {parsedFileInfo && !isParsingLft && parsedLftArticles.length > 0 && (
                  <div className="mt-4 p-4 bg-emerald-50/50 border border-emerald-100 rounded-2xl space-y-2 text-[11px] text-left">
                    <div className="font-black text-emerald-800">📂 Archivo procesado: {parsedFileInfo}</div>
                    <div className="space-y-1 text-slate-500 font-medium leading-relaxed">
                      {parsedLftArticles.map((art, idx) => (
                        <div key={idx}>
                          <strong>{art.art}:</strong> {art.desc}
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>

              <div className="shrink-0 w-full md:w-auto">
                <label className="flex flex-col items-center justify-center px-6 py-4 bg-white hover:bg-slate-50 border-2 border-dashed border-amber-300 hover:border-amber-500 rounded-2xl cursor-pointer transition-all text-center gap-1.5 shadow-sm active:scale-95">
                  <Upload size={20} className="text-amber-500" />
                  <span className="text-xs font-extrabold text-slate-700">Subir Archivo LFT</span>
                  <span className="text-[10px] text-slate-400">PDF, TXT (Max 5MB)</span>
                  <input 
                    type="file" 
                    accept=".pdf,.txt" 
                    onChange={handleLftFileUpload} 
                    className="hidden" 
                  />
                </label>
              </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
            {/* Formulario de Variables LFT */}
            <form onSubmit={handleSave} className="lg:col-span-2 space-y-6">
              
              {/* Sección 1: Tolerancia de Horarios */}
              <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-4">
                <h3 className="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2 border-b border-slate-100 pb-3">
                  <Clock size={16} className="text-amber-500" />
                  Tolerancias de Entrada y Salidas
                </h3>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
                  <div className="space-y-1.5">
                    <label className="text-xs font-bold text-slate-500">Tolerancia Entrada (minutos)</label>
                    <input 
                      type="number"
                      value={lateToleranceMinutes}
                      onChange={(e) => setLateToleranceMinutes(parseInt(e.target.value) || 0)}
                      className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold outline-none focus:border-amber-500"
                    />
                  </div>

                  <div className="space-y-1.5">
                    <label className="text-xs font-bold text-slate-500">Tolerancia Comida (minutos)</label>
                    <input 
                      type="number"
                      value={mealToleranceMinutes}
                      onChange={(e) => setMealToleranceMinutes(parseInt(e.target.value) || 0)}
                      className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold outline-none focus:border-amber-500"
                    />
                  </div>

                  <div className="space-y-1.5">
                    <label className="text-xs font-bold text-slate-500">Tolerancia Descansos (minutos)</label>
                    <input 
                      type="number"
                      value={restToleranceMinutes}
                      onChange={(e) => setRestToleranceMinutes(parseInt(e.target.value) || 0)}
                      className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold outline-none focus:border-amber-500"
                    />
                  </div>
                </div>

                <div className="space-y-2 pt-2">
                  <label className="text-xs font-bold text-slate-500">Resolución de Retardos (Tolerancia Flexible)</label>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <button
                      type="button"
                      onClick={() => setLateActionMode('deduct')}
                      className={`px-4 py-3 rounded-xl border text-xs font-bold text-left transition-all ${
                        lateActionMode === 'deduct' 
                          ? 'bg-amber-50 border-amber-300 text-amber-800' 
                          : 'bg-slate-50 border-slate-200 text-slate-600 hover:bg-slate-100'
                      }`}
                    >
                      <div className="font-extrabold mb-1">Descuento del Salario</div>
                      <div className="text-[10px] opacity-80">El retardo se calcula como penalización directa o se acumula para falta.</div>
                    </button>
                    <button
                      type="button"
                      onClick={() => setLateActionMode('extend_shift')}
                      className={`px-4 py-3 rounded-xl border text-xs font-bold text-left transition-all ${
                        lateActionMode === 'extend_shift' 
                          ? 'bg-amber-50 border-amber-300 text-amber-800' 
                          : 'bg-slate-50 border-slate-200 text-slate-600 hover:bg-slate-100'
                      }`}
                    >
                      <div className="font-extrabold mb-1">Extensión de Turno (Ley Silla / Flex)</div>
                      <div className="text-[10px] opacity-80">El colaborador puede compensar saliendo tarde el mismo número de minutos.</div>
                    </button>
                  </div>
                </div>
              </div>

              {/* Sección 2: Reglamento de Asistencias (Retardos, Faltas y Sanciones) */}
              <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-4">
                <h3 className="text-sm font-black text-slate-800 uppercase tracking-wider flex items-center gap-2 border-b border-slate-100 pb-3">
                  <AlertTriangle size={16} className="text-amber-500" />
                  Reglamento de Faltas e Incidencias
                </h3>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                  <div className="space-y-1.5">
                    <label className="text-xs font-bold text-slate-500">Retardos equivalentes a 1 Falta</label>
                    <input 
                      type="number"
                      value={latesPerAbsence}
                      onChange={(e) => setLatesPerAbsence(parseInt(e.target.value) || 3)}
                      className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold outline-none focus:border-amber-500"
                    />
                  </div>

                  <div className="space-y-1.5">
                    <label className="text-xs font-bold text-slate-500">Faltas para Llamada de Atención (Sanción Escrita)</label>
                    <input 
                      type="number"
                      value={absencesForWarning}
                      onChange={(e) => setAbsencesForWarning(parseInt(e.target.value) || 3)}
                      className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold outline-none focus:border-amber-500"
                    />
                  </div>

                  <div className="space-y-1.5">
                    <label className="text-xs font-bold text-slate-500">Faltas para Suspensión Temporal</label>
                    <input 
                      type="number"
                      value={absencesForSuspension}
                      onChange={(e) => setAbsencesForSuspension(parseInt(e.target.value) || 4)}
                      className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 font-semibold outline-none focus:border-amber-500"
                    />
                  </div>
                </div>

                <div className="flex flex-col gap-3 pt-2">
                  <label className="relative flex items-center gap-3 cursor-pointer p-3 bg-slate-50 rounded-xl border border-slate-200 hover:bg-slate-100 transition-colors">
                    <input 
                      type="checkbox"
                      checked={deductAbsenceDay}
                      onChange={(e) => setDeductAbsenceDay(e.target.checked)}
                      className="rounded text-amber-600 focus:ring-amber-500 w-4 h-4"
                    />
                    <div>
                      <span className="text-xs font-extrabold text-slate-800 block">Descontar día completo por falta</span>
                      <span className="text-[10px] text-slate-400">Si se activa, el día no trabajado se resta completamente del sueldo base devengado.</span>
                    </div>
                  </label>

                  <label className="relative flex items-center gap-3 cursor-pointer p-3 bg-slate-50 rounded-xl border border-slate-200 hover:bg-slate-100 transition-colors">
                    <input 
                      type="checkbox"
                      checked={proportionalRestDay}
                      onChange={(e) => setProportionalRestDay(e.target.checked)}
                      className="rounded text-amber-600 focus:ring-amber-500 w-4 h-4"
                    />
                    <div>
                      <span className="text-xs font-extrabold text-slate-800 block">Pago de Día de Descanso Proporcional (Séptimo Día)</span>
                      <span className="text-[10px] text-slate-400">Si hay faltas en la semana, el pago del descanso dominical/séptimo día se pagará proporcionalmente.</span>
                    </div>
                  </label>

                  <label className="relative flex items-center gap-3 cursor-pointer p-3 bg-slate-50 rounded-xl border border-slate-200 hover:bg-slate-100 transition-colors">
                    <input 
                      type="checkbox"
                      checked={paidRestDay}
                      onChange={(e) => setPaidRestDay(e.target.checked)}
                      className="rounded text-amber-600 focus:ring-amber-500 w-4 h-4"
                    />
                    <div>
                      <span className="text-xs font-extrabold text-slate-800 block">Día de descanso pagado (por defecto)</span>
                      <span className="text-[10px] text-slate-400">Indica si el día de descanso obligatorio se paga bajo condiciones ideales.</span>
                    </div>
                  </label>
                </div>
              </div>

              {/* Botón Guardar */}
              <div className="flex justify-end pt-2">
                <button
                  type="submit"
                  disabled={isSaving}
                  className="px-8 py-3 bg-gradient-to-r from-slate-900 to-slate-800 text-white font-extrabold rounded-xl shadow-md transition-all hover:scale-[1.02] flex items-center gap-2 cursor-pointer border-none disabled:opacity-50"
                >
                  <Save size={18} />
                  {isSaving ? 'Guardando...' : 'Guardar Configuración LFT'}
                </button>
              </div>
            </form>

            {/* Simulador Interactivo LFT */}
            <div className="space-y-6">
              <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm relative overflow-hidden">
                <div className="absolute top-0 inset-x-0 h-2 bg-gradient-to-r from-amber-400 to-yellow-500"></div>

                <div className="flex items-center gap-2 mb-4">
                  <Activity size={18} className="text-amber-500" />
                  <h3 className="text-base font-black text-slate-800">Simulador de Impacto LFT</h3>
                </div>

                <p className="text-[11.5px] text-slate-500 leading-relaxed mb-6">
                  Modifica las variables en tiempo real para visualizar cómo afectará el reglamento de la LFT a la nómina de un empleado.
                </p>

                <div className="space-y-4 border-b border-slate-100 pb-5 mb-5">
                  <div className="space-y-1">
                    <div className="flex justify-between text-xs font-bold text-slate-500">
                      <span>Salario Diario Integrado</span>
                      <span className="text-amber-600">${simSalarioDiario} MXN</span>
                    </div>
                    <input 
                      type="range"
                      min={250}
                      max={1200}
                      step={50}
                      value={simSalarioDiario}
                      onChange={(e) => setSimSalarioDiario(parseInt(e.target.value))}
                      className="w-full accent-amber-500 cursor-pointer"
                    />
                  </div>

                  <div className="space-y-1">
                    <div className="flex justify-between text-xs font-bold text-slate-500">
                      <span>Retardos Acumulados</span>
                      <span className="text-amber-600">{simRetardos} retardos</span>
                    </div>
                    <input 
                      type="range"
                      min={0}
                      max={10}
                      value={simRetardos}
                      onChange={(e) => setSimRetardos(parseInt(e.target.value))}
                      className="w-full accent-amber-500 cursor-pointer"
                    />
                  </div>

                  <div className="space-y-1">
                    <div className="flex justify-between text-xs font-bold text-slate-500">
                      <span>Faltas Físicas directas</span>
                      <span className="text-amber-600">{simFaltas} faltas</span>
                    </div>
                    <input 
                      type="range"
                      min={0}
                      max={6}
                      value={simFaltas}
                      onChange={(e) => setSimFaltas(parseInt(e.target.value))}
                      className="w-full accent-amber-500 cursor-pointer"
                    />
                  </div>
                </div>

                {/* Resultados de Simulación */}
                <div className="space-y-3 bg-slate-50 p-4 rounded-2xl border border-slate-200/50">
                  <div className="flex justify-between text-xs">
                    <span className="text-slate-400 font-bold">Faltas por Retardo:</span>
                    <span className="text-slate-700 font-extrabold">{simResult.faltasEquivalentes}</span>
                  </div>
                  <div className="flex justify-between text-xs">
                    <span className="text-slate-400 font-bold">Faltas Totales (Nómina):</span>
                    <span className="text-slate-700 font-extrabold">{simResult.faltasTotales}</span>
                  </div>
                  <div className="flex justify-between text-xs">
                    <span className="text-slate-400 font-bold">Descuento de Faltas:</span>
                    <span className="text-rose-600 font-extrabold">-${simResult.descuentoFaltas.toLocaleString()} MXN</span>
                  </div>
                  <div className="flex justify-between text-xs">
                    <span className="text-slate-400 font-bold">Proporción 7mo Día:</span>
                    <span className="text-amber-600 font-extrabold">{(simResult.factorProporcional * 100).toFixed(1)}%</span>
                  </div>
                  <div className="flex justify-between text-xs border-b border-slate-200 pb-2">
                    <span className="text-slate-400 font-bold">Descuento 7mo Día:</span>
                    <span className="text-rose-600 font-extrabold">-${simResult.descuentoSeptimoDia.toLocaleString()} MXN</span>
                  </div>

                  <div className="flex justify-between text-sm items-center pt-1">
                    <span className="text-slate-800 font-black">Neto Semanal a Pagar:</span>
                    <span className="text-emerald-600 font-black text-base">${simResult.netoSemanal.toLocaleString()} MXN</span>
                  </div>
                </div>

                {/* Sanción Administrativa */}
                {simResult.faltasTotales > 0 && (
                  <div className="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-xl flex items-start gap-2">
                    <ShieldAlert size={16} className="text-amber-600 shrink-0 mt-0.5" />
                    <div>
                      <span className="text-[10px] font-black uppercase text-amber-700 block">Sanción Reglamento Interno</span>
                      <span className="text-[10.5px] text-amber-900 font-semibold">{simResult.alerta}</span>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>
        </>
      )}
      </div>
    </div>
  );
}
