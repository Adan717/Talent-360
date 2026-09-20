import { useCallback, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { AlertTriangle, CheckCircle2, CircleAlert, Info, X } from 'lucide-react';
import {
  registerDialogHandler,
  type AppDialogRequest,
  type AppNotification,
  type NotificationTone,
} from '../../lib/appDialogs';

const toneClasses: Record<NotificationTone, string> = {
  info: 'border-accent/25 bg-white text-text-1',
  success: 'border-success-text/25 bg-success-bg text-success-text',
  warning: 'border-warning-text/25 bg-warning-bg text-warning-text',
  error: 'border-danger-text/25 bg-danger-bg text-danger-text',
};

const ToneIcon = ({ tone }: { tone: NotificationTone }) => {
  if (tone === 'success') return <CheckCircle2 aria-hidden="true" size={20} />;
  if (tone === 'warning') return <AlertTriangle aria-hidden="true" size={20} />;
  if (tone === 'error') return <CircleAlert aria-hidden="true" size={20} />;
  return <Info aria-hidden="true" size={20} />;
};

function ActiveDialog({ request, onSettle }: {
  request: AppDialogRequest;
  onSettle: (value: boolean | string | null) => void;
}) {
  const [value, setValue] = useState(request.defaultValue ?? '');
  const confirmRef = useRef<HTMLButtonElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const titleId = `app-dialog-title-${request.id}`;
  const descriptionId = `app-dialog-description-${request.id}`;
  const isPrompt = request.kind === 'prompt';

  useEffect(() => {
    (isPrompt ? inputRef.current : confirmRef.current)?.focus();
  }, [isPrompt]);

  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onSettle(isPrompt ? null : false);
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isPrompt, onSettle]);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    onSettle(isPrompt ? value : true);
  };

  const destructive = request.options.tone === 'error';
  return (
    <div className="fixed inset-0 z-[10000] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm" role="presentation">
      <form
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={descriptionId}
        onSubmit={submit}
        className="w-full max-w-md rounded-2xl border border-border bg-white p-6 text-left shadow-2xl"
      >
        <div className="flex items-start gap-3">
          <span className={`mt-0.5 grid h-10 w-10 shrink-0 place-items-center rounded-xl ${destructive ? 'bg-danger-bg text-danger-text' : 'bg-navy-50 text-accent'}`}>
            <ToneIcon tone={request.options.tone ?? 'info'} />
          </span>
          <div className="min-w-0 flex-1">
            <h2 id={titleId} className="text-lg font-bold text-text-1">
              {request.options.title ?? (isPrompt ? 'Completa la información' : 'Confirma esta acción')}
            </h2>
            <p id={descriptionId} className="mt-2 whitespace-pre-line text-sm leading-6 text-text-2">{request.message}</p>
          </div>
        </div>
        {isPrompt && (
          <label className="mt-5 block text-sm font-semibold text-text-2">
            Respuesta
            <input
              ref={inputRef}
              value={value}
              onChange={event => setValue(event.target.value)}
              placeholder={request.options.placeholder}
              className="mt-2 min-h-11 w-full rounded-xl border border-border bg-white px-3 py-2 text-base text-text-1 outline-none focus:border-accent focus:ring-2 focus:ring-focus-ring/30"
            />
          </label>
        )}
        <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" onClick={() => onSettle(isPrompt ? null : false)} className="min-h-11 rounded-xl border border-border px-4 py-2 text-sm font-semibold text-text-2 hover:bg-page focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring">
            {request.options.cancelLabel ?? 'Cancelar'}
          </button>
          <button ref={confirmRef} type="submit" className={`min-h-11 rounded-xl px-4 py-2 text-sm font-bold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2 ${destructive ? 'bg-danger-text hover:bg-danger-icon' : 'bg-accent hover:bg-accent-hover'}`}>
            {request.options.confirmLabel ?? (isPrompt ? 'Continuar' : 'Confirmar')}
          </button>
        </div>
      </form>
    </div>
  );
}

export function AppDialogProvider({ children }: { children: ReactNode }) {
  const [notifications, setNotifications] = useState<AppNotification[]>([]);
  const [queue, setQueue] = useState<AppDialogRequest[]>([]);
  const active = queue[0] ?? null;

  useEffect(() => {
    registerDialogHandler(request => setQueue(current => [...current, request]));
    return () => {
      registerDialogHandler(null);
    };
  }, []);

  useEffect(() => {
    const handleNotification = (event: Event) => {
      const notification = (event as CustomEvent<AppNotification>).detail;
      setNotifications(current => [...current.slice(-3), notification]);
      window.setTimeout(() => {
        setNotifications(current => current.filter(item => item.id !== notification.id));
      }, 5500);
    };
    window.addEventListener('talent360:notify', handleNotification);
    return () => window.removeEventListener('talent360:notify', handleNotification);
  }, []);

  const settle = useCallback((value: boolean | string | null) => {
    if (!active) return;
    active.resolve(value);
    setQueue(current => current[0]?.id === active.id ? current.slice(1) : current.filter(item => item.id !== active.id));
  }, [active]);

  return (
    <>
      {children}
      <div className="pointer-events-none fixed right-4 top-4 z-[10001] flex w-[min(26rem,calc(100vw-2rem))] flex-col gap-2" aria-live="polite" aria-atomic="false">
        {notifications.map(notification => (
          <div key={notification.id} role={notification.tone === 'error' ? 'alert' : 'status'} className={`pointer-events-auto flex items-start gap-3 rounded-xl border p-4 shadow-lg ${toneClasses[notification.tone]}`}>
            <ToneIcon tone={notification.tone} />
            <p className="min-w-0 flex-1 whitespace-pre-line text-sm font-medium leading-5">{notification.message}</p>
            <button type="button" onClick={() => setNotifications(current => current.filter(item => item.id !== notification.id))} aria-label="Cerrar notificación" className="grid h-8 w-8 shrink-0 place-items-center rounded-lg hover:bg-black/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring">
              <X aria-hidden="true" size={16} />
            </button>
          </div>
        ))}
      </div>
      {active && <ActiveDialog key={active.id} request={active} onSettle={settle} />}
    </>
  );
}
