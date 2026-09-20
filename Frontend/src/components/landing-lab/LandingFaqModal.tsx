import { useEffect, useRef } from 'react';
import { HelpCircle, Plus, X } from 'lucide-react';

const faqs = [
  ['¿Puedo empezar con un plan gratuito?', 'Sí. Puedes crear una cuenta y comenzar con las funciones del plan Gratuito. Cuando necesites más herramientas, podrás elegir otro plan.'],
  ['¿Mi equipo puede registrar asistencia desde el celular?', 'Sí. Talent 360 permite registrar asistencia desde web y móvil. Las funciones disponibles dependen del plan y de la configuración de tu empresa.'],
  ['¿Puedo organizar diferentes áreas y sucursales?', 'Sí. Puedes configurar la estructura de tu empresa, sus áreas, puestos y colaboradores desde la misma plataforma.'],
  ['¿Cómo se calcula el costo de mi plan?', 'Los planes de pago se cotizan con la tarifa vigente, el número de colaboradores y el ciclo de facturación elegido. Antes de contratar verás el total correspondiente.'],
] as const;

interface LandingFaqModalProps {
  isOpen: boolean;
  onClose: () => void;
}

/** Compact FAQ access from the footer so the landing can retain its visual rhythm. */
export function LandingFaqModal({ isOpen, onClose }: LandingFaqModalProps) {
  const closeButtonRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!isOpen) return;
    const focusBeforeOpen = document.activeElement as HTMLElement | null;
    closeButtonRef.current?.focus();
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      focusBeforeOpen?.focus();
    };
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  return (
    <div
      className="fixed inset-0 z-[60] flex items-center justify-center overflow-y-auto bg-brand-dark/70 p-4 backdrop-blur-sm"
      onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}
    >
      <section role="dialog" aria-modal="true" aria-labelledby="landing-faq-title" className="w-full max-w-2xl overflow-hidden rounded-2xl border border-navy-100 bg-white shadow-[0_1px_3px_rgba(16,24,40,0.08)]">
        <header className="flex items-start justify-between gap-6 border-b border-border bg-navy-50 px-6 py-5">
          <div className="flex items-start gap-3">
            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white text-accent ring-1 ring-navy-100"><HelpCircle size={20} /></span>
            <div>
              <p className="text-xs font-bold tracking-[.14em] text-text-3">TALENT 360</p>
              <h2 id="landing-faq-title" className="mt-1 text-xl font-semibold tracking-tight text-text-1">Preguntas frecuentes</h2>
              <p className="mt-1 text-sm text-text-2">Lo esencial antes de crear tu cuenta.</p>
            </div>
          </div>
          <button ref={closeButtonRef} type="button" aria-label="Cerrar preguntas frecuentes" onClick={onClose} className="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-text-2 transition hover:bg-white hover:text-text-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"><X size={20} /></button>
        </header>
        <div className="max-h-[60vh] overflow-y-auto px-6 py-2">
          {faqs.map(([question, answer]) => (
            <details key={question} className="group border-b border-border last:border-0">
              <summary className="flex cursor-pointer list-none items-center justify-between gap-5 py-5 text-sm font-semibold text-text-1 marker:hidden focus:outline-none">
                {question}
                <Plus size={18} className="shrink-0 text-text-3 transition-transform group-open:rotate-45" />
              </summary>
              <p className="max-w-xl pb-5 pr-8 text-sm leading-6 text-text-2">{answer}</p>
            </details>
          ))}
        </div>
        <footer className="flex justify-end border-t border-border px-6 py-4">
          <button type="button" onClick={onClose} className="rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-navy-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2">Entendido</button>
        </footer>
      </section>
    </div>
  );
}
