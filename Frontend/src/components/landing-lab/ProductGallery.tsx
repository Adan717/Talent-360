import { useRef, useState, type KeyboardEvent, type PointerEvent } from 'react';
import { ArrowLeft, ArrowRight, CheckCheck, Fingerprint, ListChecks, Users } from 'lucide-react';
import { ProductPreview } from './ProductPreview';

const slides = [
  { view: 'monitor', title: 'Monitor 360', icon: CheckCheck, headline: 'Todo en su lugar.', description: 'Tu equipo conectado, tu día bajo control.' },
  { view: 'asistencia', title: 'Asistencia', icon: Fingerprint, headline: 'Cada jornada cuenta.', description: 'Entradas, salidas y horarios, en una sola vista.' },
  { view: 'equipo', title: 'Directorio', icon: Users, headline: 'Conoce a tu equipo.', description: 'Las personas y sus áreas, siempre a mano.' },
  { view: 'operaciones', title: 'Tareas y rutinas', icon: ListChecks, headline: 'Del pendiente al listo.', description: 'Cada tarea con un responsable y un siguiente paso.' },
] as const;

/** Manual, local-only gallery. The full interactive demo remains below the hero. */
export function ProductGallery() {
  const [active, setActive] = useState(0);
  const gesture = useRef<{ x: number; y: number; id: number } | null>(null);
  const current = slides[active];
  const Icon = current.icon;
  const move = (step: number) => setActive(index => (index + step + slides.length) % slides.length);

  const onKeyDown = (event: KeyboardEvent<HTMLElement>) => {
    if (event.altKey || event.ctrlKey || event.metaKey) return;
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    if (event.key === 'Home') setActive(0);
    else if (event.key === 'End') setActive(slides.length - 1);
    else move(event.key === 'ArrowRight' ? 1 : -1);
  };

  const onPointerDown = (event: PointerEvent<HTMLDivElement>) => {
    if (!event.isPrimary || event.button !== 0) return;
    gesture.current = { x: event.clientX, y: event.clientY, id: event.pointerId };
    event.currentTarget.setPointerCapture(event.pointerId);
  };

  const onPointerUp = (event: PointerEvent<HTMLDivElement>) => {
    const start = gesture.current;
    if (!start || start.id !== event.pointerId) return;
    gesture.current = null;
    if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
    const dx = event.clientX - start.x;
    const dy = event.clientY - start.y;
    if (Math.abs(dx) >= 45 && Math.abs(dx) > Math.abs(dy) * 1.4) move(dx < 0 ? 1 : -1);
  };

  return (
    <section className="lab-hero-showcase lab-gallery" aria-label="Galería del producto Talent 360" aria-roledescription="carrusel" onKeyDown={onKeyDown}>
      <div className="lab-showcase-label"><span className="lab-live-dot" /> EXPLORA TALENT 360<span>{current.title.toUpperCase()}</span></div>
      <div className="lab-gallery-viewport" tabIndex={0} aria-label="Pantallas del producto. Usa las flechas del teclado o desliza para cambiar." onPointerDown={onPointerDown} onPointerUp={onPointerUp} onPointerCancel={() => { gesture.current = null; }} onLostPointerCapture={() => { gesture.current = null; }}>
        <div className="lab-gallery-track" style={{ transform: `translateX(-${active * 100}%)` }}>
          {slides.map((slide, index) => (
            <div className="lab-gallery-slide" id={`lab-gallery-${slide.view}`} key={slide.view} role="group" aria-roledescription="diapositiva" aria-label={`${index + 1} de ${slides.length}: ${slide.title}`} aria-hidden={index !== active} inert={index !== active}>
              <div className="lab-gallery-image" role="img" aria-label={`Vista ilustrativa de ${slide.title}. ${slide.description} Datos de ejemplo.`}>
                <div inert aria-hidden="true"><ProductPreview compact view={slide.view} /></div>
              </div>
            </div>
          ))}
        </div>
      </div>
      <div className="lab-gallery-footer">
        <div className="lab-gallery-caption"><span className="lab-note-icon"><Icon size={21} /></span><div><strong>{current.headline}</strong><p>{current.description}</p></div></div>
        <div className="lab-gallery-controls">
          <button type="button" className="lab-gallery-arrow" aria-label="Pantalla anterior" onClick={() => move(-1)}><ArrowLeft size={18} /></button>
          <div className="lab-gallery-dots" role="group" aria-label="Elegir pantalla">{slides.map((slide, index) => <button type="button" key={slide.view} aria-label={`Ver ${slide.title}`} aria-current={index === active ? 'true' : undefined} aria-controls={`lab-gallery-${slide.view}`} title={slide.title} onClick={() => setActive(index)}><span /></button>)}</div>
          <span className="lab-gallery-count" aria-hidden="true">{String(active + 1).padStart(2, '0')} / {String(slides.length).padStart(2, '0')}</span>
          <button type="button" className="lab-gallery-arrow" aria-label="Pantalla siguiente" onClick={() => move(1)}><ArrowRight size={18} /></button>
        </div>
      </div>
      <p className="lab-gallery-announcement" role="status" aria-live="polite" aria-atomic="true">Pantalla {active + 1} de {slides.length}: {current.title}. {current.description}</p>
    </section>
  );
}
