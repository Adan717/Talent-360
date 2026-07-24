import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { LogIn, LogOut, Coffee, Utensils, Delete, X } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';

/**
 * Kiosko: tableta compartida en la entrada (Ronda 55, la UI; el backend es R54).
 *
 * Es un MODO distinto del reloj personal (`/empleado`), no una variante: la tableta la hospeda la
 * sesión de un encargado (que sólo ancla el TENANT, ver R54), y cada empleado se identifica con su
 * PIN, hace UNA acción y la pantalla vuelve a reposo. Por eso vive en un componente propio, tipado,
 * y NO toca los archivos @ts-nocheck del reloj.
 *
 * Flujo: reposo → elegir acción → teclear PIN (6 dígitos, auto-envía) → POST /kiosk/punch →
 * confirmación → reposo. Con timeout de inactividad en las pantallas intermedias.
 *
 * Todo el enforcement (identidad por PIN dentro del tenant, can_clock_in, archivado, rate-limit) es
 * server-side (R54): esta UI sólo recoge PIN + acción y muestra el resultado.
 */

type Mode = 'idle' | 'action' | 'pin' | 'result';

interface KioskAction {
  type: string;
  label: string;
  icon: React.ReactNode;
  color: string;
}

const ACTIONS: KioskAction[] = [
  { type: 'check_in', label: 'Entrada', icon: <LogIn size={28} />, color: 'bg-emerald-500 hover:bg-emerald-600' },
  { type: 'break_start', label: 'Iniciar comida', icon: <Utensils size={28} />, color: 'bg-amber-500 hover:bg-amber-600' },
  { type: 'break_end', label: 'Regresar de comida', icon: <Coffee size={28} />, color: 'bg-sky-500 hover:bg-sky-600' },
  { type: 'check_out', label: 'Salida', icon: <LogOut size={28} />, color: 'bg-rose-500 hover:bg-rose-600' },
];

const PIN_LENGTH = 6;
const INACTIVITY_MS = 20000; // vuelve a reposo si nadie interactúa
const RESULT_MS = 4500;      // cuánto se muestra la confirmación antes de volver a reposo

export const KioskScreen = () => {
  const navigate = useNavigate();
  const { currentUser } = useAppStore();
  const companyName = currentUser?.tenant?.name || 'Talent 360';

  const [mode, setMode] = useState<Mode>('idle');
  const [selectedAction, setSelectedAction] = useState<KioskAction | null>(null);
  const [pin, setPin] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; title: string; detail: string } | null>(null);
  const [now, setNow] = useState(() => new Date());

  const inactivityTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const resultTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Reloj en vivo para la pantalla de reposo.
  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(t);
  }, []);

  const goIdle = useCallback(() => {
    // Se cancelan AMBOS timers: si no, un `resultTimer` armado por el empleado anterior (que tocó
    // "Continuar" antes de los 4.5s) seguiría vivo y a media cola dispararía goIdle sobre el SIGUIENTE
    // empleado, borrándole el PIN a medio teclear.
    if (inactivityTimer.current) clearTimeout(inactivityTimer.current);
    if (resultTimer.current) clearTimeout(resultTimer.current);
    setMode('idle');
    setSelectedAction(null);
    setPin('');
    setSubmitting(false);
    setResult(null);
  }, []);

  // Timeout de inactividad: sólo corre en las pantallas intermedias (elegir acción / teclear PIN).
  // En reposo o mostrando resultado no aplica (el resultado tiene su propio temporizador).
  const armInactivity = useCallback(() => {
    if (inactivityTimer.current) clearTimeout(inactivityTimer.current);
    inactivityTimer.current = setTimeout(goIdle, INACTIVITY_MS);
  }, [goIdle]);

  useEffect(() => {
    if (mode === 'action' || mode === 'pin') {
      armInactivity();
    } else if (inactivityTimer.current) {
      clearTimeout(inactivityTimer.current);
    }
    return () => {
      if (inactivityTimer.current) clearTimeout(inactivityTimer.current);
    };
  }, [mode, armInactivity]);

  useEffect(() => () => {
    if (resultTimer.current) clearTimeout(resultTimer.current);
  }, []);

  const chooseAction = (action: KioskAction) => {
    setSelectedAction(action);
    setPin('');
    setMode('pin');
  };

  const submitPunch = useCallback(async (fullPin: string, action: KioskAction) => {
    setSubmitting(true);
    if (inactivityTimer.current) clearTimeout(inactivityTimer.current);

    try {
      const res = await axiosInstance.post('/kiosk/punch', { pin: fullPin, type: action.type });
      const data = res.data || {};
      if (data.success) {
        setResult({
          ok: true,
          title: `¡Listo, ${data.employee?.name || 'colaborador'}!`,
          detail: `${action.label} · ${data.message || 'Registro guardado.'}`,
        });
      } else {
        // 200 con success:false (raro; ClockService normalmente lanza), se trata como rechazo.
        setResult({ ok: false, title: 'No se registró', detail: data.message || 'Intenta de nuevo.' });
      }
    } catch (err: any) {
      const status = err?.response?.status;
      const message = err?.response?.data?.message;
      // Mensajes claros pero sin revelar si un PIN existe (el backend ya manda genérico en 422).
      const detail =
        status === 429 ? 'Demasiados intentos. Espera un momento.' :
        message || 'No se pudo registrar. Intenta de nuevo.';
      setResult({ ok: false, title: status === 422 ? 'PIN no reconocido' : 'No se registró', detail });
    } finally {
      // El PIN nunca se conserva tras el envío.
      setPin('');
      setSubmitting(false);
      setMode('result');
      if (resultTimer.current) clearTimeout(resultTimer.current);
      resultTimer.current = setTimeout(goIdle, RESULT_MS);
    }
  }, [goIdle]);

  const pressDigit = (d: string) => {
    if (submitting || pin.length >= PIN_LENGTH) return;
    armInactivity();
    const next = pin + d;
    setPin(next);
    // El auto-envío va FUERA del updater de setPin: un updater debe ser puro. Dentro, StrictMode lo
    // invoca dos veces en dev → doble POST. `pin` del closure es el estado del render actual (cada
    // tecla re-renderiza), así que `next` es correcto.
    if (next.length === PIN_LENGTH && selectedAction) {
      submitPunch(next, selectedAction);
    }
  };

  const pressBackspace = () => {
    if (submitting) return;
    armInactivity();
    setPin(prev => prev.slice(0, -1));
  };

  // ---- Render por modo -----------------------------------------------------------

  if (mode === 'idle') {
    return (
      <KioskFrame companyName={companyName} onExit={() => navigate('/app')}>
        <button
          onClick={() => setMode('action')}
          className="flex flex-col items-center justify-center gap-6 group focus:outline-none"
        >
          <div className="w-40 h-40 rounded-full bg-white/10 border-2 border-white/30 flex items-center justify-center group-hover:bg-white/20 group-active:scale-95 transition-all">
            <LogIn size={64} className="text-white" />
          </div>
          <div className="text-center">
            <div className="text-3xl font-black text-white">Toca para registrar</div>
            <div className="text-white/70 mt-2 text-lg tabular-nums">
              {now.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })}
              {' · '}
              {now.toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long' })}
            </div>
          </div>
        </button>
      </KioskFrame>
    );
  }

  if (mode === 'action') {
    return (
      <KioskFrame companyName={companyName} onExit={goIdle} exitLabel="Cancelar">
        <div className="w-full max-w-xl">
          <h2 className="text-2xl font-black text-white text-center mb-8">¿Qué quieres registrar?</h2>
          <div className="grid grid-cols-2 gap-4">
            {ACTIONS.map(a => (
              <button
                key={a.type}
                onClick={() => chooseAction(a)}
                className={`${a.color} text-white rounded-2xl p-6 flex flex-col items-center gap-3 font-bold text-lg shadow-lg active:scale-95 transition-all`}
              >
                {a.icon}
                {a.label}
              </button>
            ))}
          </div>
        </div>
      </KioskFrame>
    );
  }

  if (mode === 'pin') {
    return (
      <KioskFrame companyName={companyName} onExit={goIdle} exitLabel="Cancelar">
        <div className="w-full max-w-xs">
          <div className="text-center mb-2 text-white/80 font-semibold">{selectedAction?.label}</div>
          <h2 className="text-xl font-black text-white text-center mb-6">Ingresa tu PIN</h2>

          <div className="flex justify-center gap-3 mb-8" aria-label="PIN">
            {Array.from({ length: PIN_LENGTH }).map((_, i) => (
              <div
                key={i}
                className={`w-4 h-4 rounded-full border-2 transition-colors ${
                  i < pin.length ? 'bg-white border-white' : 'border-white/40'
                }`}
              />
            ))}
          </div>

          <div className="grid grid-cols-3 gap-3">
            {['1', '2', '3', '4', '5', '6', '7', '8', '9'].map(d => (
              <KeypadButton key={d} onClick={() => pressDigit(d)} disabled={submitting}>{d}</KeypadButton>
            ))}
            <KeypadButton onClick={pressBackspace} disabled={submitting} aria-label="Borrar">
              <Delete size={22} />
            </KeypadButton>
            <KeypadButton onClick={() => pressDigit('0')} disabled={submitting}>0</KeypadButton>
            <div />
          </div>

          {submitting && (
            <div className="text-center text-white/80 mt-6 font-semibold animate-pulse">Registrando…</div>
          )}
        </div>
      </KioskFrame>
    );
  }

  // mode === 'result'
  return (
    <KioskFrame companyName={companyName} onExit={goIdle} exitLabel="Continuar">
      <div className="text-center">
        <div
          className={`w-28 h-28 rounded-full mx-auto flex items-center justify-center mb-6 ${
            result?.ok ? 'bg-emerald-500' : 'bg-rose-500'
          }`}
        >
          {result?.ok ? <span className="text-6xl">✓</span> : <X size={56} className="text-white" />}
        </div>
        <div className="text-3xl font-black text-white mb-2">{result?.title}</div>
        <div className="text-white/80 text-lg max-w-md mx-auto">{result?.detail}</div>
        <button
          onClick={goIdle}
          className="mt-8 px-6 py-2.5 rounded-xl bg-white/15 hover:bg-white/25 text-white font-bold transition-colors"
        >
          Continuar
        </button>
      </div>
    </KioskFrame>
  );
};

// ---- Sub-componentes -------------------------------------------------------------

const KioskFrame = ({
  companyName,
  children,
  onExit,
  exitLabel = 'Salir del modo kiosko',
}: {
  companyName: string;
  children: React.ReactNode;
  onExit: () => void;
  exitLabel?: string;
}) => (
  <div className="w-screen h-[100dvh] bg-gradient-to-br from-slate-800 to-slate-900 flex flex-col items-center justify-center select-none overflow-hidden">
    <div className="absolute top-0 left-0 right-0 flex items-center justify-between p-5">
      <div className="text-white font-black tracking-wide">{companyName}</div>
      <button
        onClick={onExit}
        className="text-white/60 hover:text-white text-sm font-semibold px-3 py-1.5 rounded-lg hover:bg-white/10 transition-colors"
      >
        {exitLabel}
      </button>
    </div>
    {children}
  </div>
);

const KeypadButton = ({
  children,
  onClick,
  disabled,
  ...rest
}: {
  children: React.ReactNode;
  onClick: () => void;
  disabled?: boolean;
} & React.ButtonHTMLAttributes<HTMLButtonElement>) => (
  <button
    onClick={onClick}
    disabled={disabled}
    className="h-16 rounded-2xl bg-white/10 hover:bg-white/20 active:scale-95 text-white text-2xl font-bold flex items-center justify-center transition-all disabled:opacity-40 disabled:pointer-events-none"
    {...rest}
  >
    {children}
  </button>
);

export default KioskScreen;
