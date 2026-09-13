import { useRef, useState } from 'react';
import { ArrowUpRight, Building2, Check, CirclePlay, Fingerprint, Play, Users, Video } from 'lucide-react';
import { TalentLogo } from '../ui/TalentLogo';
import { moduleVideos, youtubeEmbedUrl, type ModuleVideo, type VideoModuleId } from './moduleVideos';

const moduleIcons = { onboarding: Building2, asistencia: Fingerprint, reclutamiento: Users };

export function ModuleVideoShowcase({ selected, onSelect, videos = moduleVideos }: {
  selected: VideoModuleId; onSelect: (id: VideoModuleId) => void; videos?: readonly ModuleVideo[];
}) {
  const current = videos.find(video => video.id === selected) ?? videos[0];
  const index = videos.indexOf(current);
  const [playing, setPlaying] = useState<string | null>(null);
  const buttons = useRef<Array<HTMLButtonElement | null>>([]);
  const screenRef = useRef<HTMLDivElement>(null);
  const embedUrl = youtubeEmbedUrl(current.youtubeUrl);
  const playbackKey = `${current.id}:${embedUrl}`;
  const isPlaying = Boolean(embedUrl && playing === playbackKey);
  const choose = (id: VideoModuleId, showScreen = false) => {
    setPlaying(null);
    onSelect(id);
    if (showScreen) screenRef.current?.scrollIntoView?.({ block: 'center', behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
  };

  return <section id="lab-producto" className="lab-section lab-video-section" aria-labelledby="lab-product-title"><div className="lab-container">
    <div className="lab-section-heading"><div><span className="lab-eyebrow">UNA VENTANA A TU PRÓXIMA FORMA DE TRABAJAR</span><h2 id="lab-product-title">Conoce tu próximo<br /><span>centro de operaciones.</span></h2></div><p>Descubre los módulos desde adentro. Elige un recorrido y mira cómo las herramientas de Talent 360 conectan el trabajo de tu equipo.</p></div>
    <div className="lab-video-scene">
      <div className="lab-video-orbit" aria-hidden="true" />
      <div className="lab-video-floating-tag" aria-hidden="true"><span><Video size={17} /></span><div>EL PRODUCTO, POR DENTRO<strong>{String(index + 1).padStart(2, '0')} / {current.category}</strong></div></div>
      <div className="lab-laptop">
        <div className="lab-laptop-lid"><div className="lab-laptop-camera" aria-hidden="true"><i /></div><div ref={screenRef} id="lab-video-screen" className="lab-laptop-screen" role="tabpanel" aria-labelledby={`lab-video-tab-${current.id}`} tabIndex={0}>
          {isPlaying ? <iframe key={playbackKey} src={embedUrl!} title={`Video: ${current.title}`} allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowFullScreen referrerPolicy="strict-origin-when-cross-origin" /> : <>
            <div className="lab-video-cover"><span className="lab-video-cover-label"><TalentLogo /> TALENT 360 / RECORRIDOS</span><div><span className="lab-video-cover-category">{current.category}</span><h3>{current.title}</h3><p>{embedUrl ? 'Conoce este módulo en el video oficial.' : 'Aquí podrás reproducir el video real de este módulo.'}</p><button type="button" disabled={!embedUrl} className="lab-video-play" onClick={() => setPlaying(playbackKey)} aria-label={embedUrl ? `Reproducir video de ${current.title}` : `Video de ${current.title} próximamente`}><span>{embedUrl ? <Play size={22} fill="currentColor" /> : <Video size={22} />}</span>{embedUrl ? 'Reproducir video' : 'Video próximamente'}</button></div><span className="lab-video-cover-bottom">{embedUrl ? 'Se reproducirá desde YouTube al pulsar play.' : 'Espacio reservado para un enlace de YouTube.'}</span></div>
          </>}
        </div><span className="lab-laptop-wordmark" aria-hidden="true">TALENT 360</span></div>
        <div className="lab-laptop-deck" aria-hidden="true"><div className="lab-laptop-keys">{Array.from({ length: 36 }, (_, i) => <i key={i} />)}</div><div className="lab-laptop-trackpad" /></div><div className="lab-laptop-lip" aria-hidden="true"><span /></div>
      </div>
      <div className="lab-video-scene-note"><span>PERSONAS</span><i /><span>PROCESOS</span><i /><span>UN MISMO LUGAR</span></div>
    </div>
    <div className="lab-video-modules-heading"><div><span className="lab-eyebrow">ELIGE TU RECORRIDO</span><h3>Módulos en Acción</h3></div><p>Explora el ecosistema operativo diseñado para dar la mejor experiencia a tus equipos.</p></div>
    <div className="lab-video-module-grid" role="tablist" aria-label="Videos de los módulos">{videos.map((video, i) => { const Icon = moduleIcons[video.id]; const available = Boolean(youtubeEmbedUrl(video.youtubeUrl)); return <button type="button" role="tab" ref={element => { buttons.current[i] = element; }} key={video.id} id={`lab-video-tab-${video.id}`} aria-selected={video.id === current.id} aria-controls="lab-video-screen" tabIndex={video.id === current.id ? 0 : -1} onClick={() => choose(video.id, true)} onKeyDown={event => { let next = i; if (event.key === 'ArrowRight') next = (i + 1) % videos.length; else if (event.key === 'ArrowLeft') next = (i - 1 + videos.length) % videos.length; else if (event.key === 'Home') next = 0; else if (event.key === 'End') next = videos.length - 1; else return; event.preventDefault(); choose(videos[next].id); buttons.current[next]?.focus(); }} className={`lab-video-module ${video.id === current.id ? 'is-selected' : ''}`}><span className="lab-video-module-top"><span><Icon size={23} /></span><small>{String(i + 1).padStart(2, '0')}</small></span><span className="lab-video-module-category">{video.category}</span><strong>{video.title}</strong><span className="lab-video-module-description">{video.description}</span><span className="lab-video-module-bottom"><span>{available ? <><CirclePlay size={15} /> Video disponible</> : <><Video size={15} /> Próximamente</>}</span>{video.id === current.id ? <Check size={17} /> : <ArrowUpRight size={17} />}</span></button>; })}</div>
  </div></section>;
}
