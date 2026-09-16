import { useState } from 'react';
import { ArrowUpRight, Bell, CalendarDays, Check, CheckCheck, ChevronDown, Clock3, FileText, Fingerprint, LayoutDashboard, ListChecks, MapPin, MoreHorizontal, Search, Users } from 'lucide-react';
import { TalentLogo } from '../ui/TalentLogo';

export type PreviewView = 'monitor' | 'asistencia' | 'equipo';

const people = [
  { initials: 'AM', name: 'Ana Martínez', role: 'Diseño de producto', area: 'Producto', time: '08:54' },
  { initials: 'CR', name: 'Carlos Ruiz', role: 'Ejecutivo de ventas', area: 'Comercial', time: '08:57' },
  { initials: 'LS', name: 'Lucía Sánchez', role: 'People & Culture', area: 'Personas', time: '08:59' },
  { initials: 'DP', name: 'Diego Pérez', role: 'Desarrollador', area: 'Tecnología', time: '09:00' },
];

/** Local illustration data only; this preview never reads or writes an app store. */
export function ProductPreview({ view = 'monitor', compact = false }: { view?: PreviewView | 'operaciones'; compact?: boolean }) {
  const [registered, setRegistered] = useState(false);
  const [query, setQuery] = useState('');
  const titles = { monitor: 'Monitor 360', asistencia: 'Asistencia', equipo: 'Directorio de equipo', operaciones: 'Tareas y rutinas' };
  const descriptions = { monitor: 'Todo lo que necesitas saber, hoy.', asistencia: 'Cada jornada, en el mismo lugar.', equipo: 'Las personas detrás de tu organización.', operaciones: 'Cada pendiente, con un siguiente paso.' };
  const normalizeSearch = (value: string) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const filtered = people.filter(p => normalizeSearch(`${p.name} ${p.area}`).includes(normalizeSearch(query)));

  return (
    <div className={`lab-product ${compact ? 'lab-product--compact' : ''}`}>
      <div className="lab-browser-bar">
        <div className="lab-browser-dots" aria-hidden="true"><i /><i /><i /></div>
        <span><LockIcon /> talent360.com.mx</span>
        <MoreHorizontal size={16} aria-hidden="true" />
      </div>
      <div className="lab-product-layout">
        <aside className="lab-product-rail" aria-hidden="true">
          <TalentLogo />
          {[LayoutDashboard, Fingerprint, Users, ListChecks, CalendarDays].map((Icon, i) => <span key={i} className={i === (view === 'monitor' ? 0 : view === 'asistencia' ? 1 : view === 'equipo' ? 2 : 3) ? 'is-current' : ''}><Icon size={17} /></span>)}
          <span className="lab-rail-avatar">AC</span>
        </aside>
        <div className="lab-product-workspace">
          <div className="lab-product-topbar"><span>Mi organización <ChevronDown size={12} /></span><span><Search size={14} /><Bell size={14} /><b>AC</b></span></div>
          <div className="lab-product-content" key={view}>
            <div className="lab-product-title"><div><h3>{titles[view]}</h3><p>{descriptions[view]}</p></div><span className="lab-mini-date"><CalendarDays size={12} /> Hoy</span></div>

            {view === 'monitor' && <>
              <div className="lab-preview-kpis">
                <div><span><Users size={13} /> Equipo activo</span><strong>124 <small>personas</small></strong><p>En 4 departamentos</p></div>
                <div><span><Clock3 size={13} /> Asistencia</span><strong>98<small>%</small></strong><p><Check size={10} /> Jornada en curso</p></div>
                <div><span><CheckCheck size={13} /> Tareas listas</span><strong>32<small> / 36</small></strong><p>4 por completar</p></div>
              </div>
              <div className="lab-preview-panels">
                <div className="lab-preview-chart"><div className="lab-preview-panel-title">Tu semana, en perspectiva <span>Asistencia</span></div><div className="lab-chart-area"><div className="lab-chart-grid"><span>100%</span><span>75%</span><span>50%</span></div><svg viewBox="0 0 300 100" role="img" aria-label="Ejemplo: asistencia estable durante la semana"><path d="M0 50 C24 50 28 23 60 27 S105 47 126 27 S164 14 188 20 S229 35 254 12 S281 13 300 7 L300 100 L0 100Z" fill="var(--lab-soft)" /><path d="M0 50 C24 50 28 23 60 27 S105 47 126 27 S164 14 188 20 S229 35 254 12 S281 13 300 7" fill="none" stroke="var(--lab-accent)" strokeWidth="2.5" /><circle cx="254" cy="12" r="4" fill="var(--lab-accent)" stroke="var(--lab-surface)" strokeWidth="2" /></svg></div><div className="lab-chart-days">{['Lun', 'Mar', 'Mié', 'Jue', 'Vie'].map(d => <span key={d}>{d}</span>)}</div></div>
                <div className="lab-preview-actions"><div className="lab-preview-panel-title">Por resolver <span>03</span></div>{[['Vacaciones', '2 solicitudes'], ['Evaluaciones', '1 por revisar'], ['Nómina', 'En preparación']].map(([a, b], i) => <div key={a}><span className="lab-action-number">0{i + 1}</span><p><b>{a}</b><small>{b}</small></p><ArrowUpRight size={13} /></div>)}</div>
              </div>
              <div className="lab-preview-activity"><div className="lab-preview-panel-title">Actividad reciente <span>Hoy</span></div>{people.slice(0, compact ? 2 : 3).map(p => <div className="lab-activity-row" key={p.initials}><span className="lab-avatar">{p.initials}</span><p><b>{p.name}</b><small>Registró su entrada</small></p><span className="lab-status"><Check size={11} /> Registrado</span><time>{p.time}</time></div>)}</div>
            </>}

            {view === 'asistencia' && <div className="lab-attendance-demo">
              <div className="lab-attendance-person"><span className="lab-avatar">AM</span><div><b>Ana Martínez</b><p>Diseño de producto · Oficina central</p></div></div>
              <div className={`lab-demo-clock ${registered ? 'is-registered' : ''}`}><span><Clock3 size={14} /> Mi jornada de hoy</span><strong>{registered ? '09:01' : '09:00'}<small>AM</small></strong><p><MapPin size={13} /> Oficina central</p><button className="lab-button lab-button--primary" onClick={() => setRegistered(!registered)}><Fingerprint size={17} />{registered ? 'Reiniciar ejemplo' : 'Registrar entrada de ejemplo'}</button><div className="lab-demo-feedback" role="status">{registered ? <><CheckCheck size={14} /> Entrada registrada. ¡Buen día, Ana!</> : 'Prueba el fichaje. Los datos son de ejemplo.'}</div></div>
              <div className="lab-demo-timeline"><span className={registered ? 'is-done' : ''}><Check size={12} /> Entrada <b>{registered ? '09:01' : 'Pendiente'}</b></span><span><Clock3 size={12} /> Descanso <b>Sin iniciar</b></span><span><ArrowUpRight size={12} /> Salida <b>Sin registrar</b></span></div>
            </div>}

            {view === 'operaciones' && <div className="lab-operations-preview">
              <div className="lab-preview-kpis"><div><span><ListChecks size={13} /> Asignadas</span><strong>12</strong><p>Para esta jornada</p></div><div><span><Clock3 size={13} /> En curso</span><strong>4</strong><p>Con responsable</p></div><div><span><CheckCheck size={13} /> Completadas</span><strong>8</strong><p>Equipo conectado</p></div></div>
              <div className="lab-routine-summary"><div><ListChecks size={17} /><span><b>Rutina de apertura</b><small>Oficina central · Hoy</small></span><strong>2 / 3</strong></div><div className="lab-routine-progress"><span /></div></div>
              <div className="lab-routine-list">{[
                { task: 'Verificar accesos y equipos', person: 'Ana Martínez', initials: 'AM', done: true },
                { task: 'Completar checklist de seguridad', person: 'Carlos Ruiz', initials: 'CR', done: true },
                { task: 'Confirmar agenda del equipo', person: 'Lucía Sánchez', initials: 'LS', done: false },
              ].map(task => <div className="lab-routine-row" key={task.task}><span className="lab-routine-state">{task.done ? <CheckCheck size={17} /> : <Clock3 size={17} />}</span><div><b>{task.task}</b><small><span className="lab-avatar">{task.initials}</span>{task.person}</small></div><span className="lab-status">{task.done ? 'Completada' : 'En curso'}</span></div>)}</div>
              <div className="lab-routine-note"><FileText size={13} /><span>El avance de tu operación, sin perder el detalle.</span></div>
            </div>}

            {view === 'equipo' && <div className="lab-directory-demo">
              <label className="lab-preview-search"><Search size={15} /><input value={query} onChange={e => setQuery(e.target.value)} placeholder="Buscar persona o departamento" aria-label="Buscar en el directorio de ejemplo" /></label>
              <div className="lab-directory-meta"><span>{filtered.length} colaboradores de ejemplo</span><span>Todos los departamentos</span></div>
              <div className="lab-directory-grid">{filtered.map(p => <article key={p.initials}><div><span className="lab-avatar">{p.initials}</span><span className="lab-status"><Check size={10} /> Activo</span></div><h4>{p.name}</h4><p>{p.role}</p><span className="lab-department"><Users size={11} /> {p.area}</span></article>)}</div>
              {filtered.length === 0 && <p className="lab-search-empty" role="status">No encontramos coincidencias. Prueba con “Ana” o “Producto”.</p>}
            </div>}
          </div>
          <div className="lab-product-status"><span><i /> Información centralizada</span><span>Datos de ejemplo</span></div>
        </div>
      </div>
    </div>
  );
}

function LockIcon() {
  return <svg width="10" height="10" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M5 7V4a3 3 0 0 1 6 0v3M3 7h10v7H3z" stroke="currentColor" strokeWidth="1.4" strokeLinejoin="round" /></svg>;
}
