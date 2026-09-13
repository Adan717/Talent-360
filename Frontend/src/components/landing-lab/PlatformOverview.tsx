import { BarChart3, ClipboardList, FileText, Fingerprint, LayoutDashboard, UsersRound } from 'lucide-react';

const modules = [
  { name: 'Monitor 360', detail: 'Supervisión operativa', Icon: LayoutDashboard },
  { name: 'Reloj Checador', detail: 'Asistencia', Icon: Fingerprint },
  { name: 'Directorio Digital', detail: 'Personas y puestos', Icon: FileText },
  { name: 'Tareas IA', detail: 'Rutinas del equipo', Icon: ClipboardList },
  { name: 'Reportes IA', detail: 'Información del equipo', Icon: BarChart3 },
  { name: 'Organigrama y SOP', detail: 'Áreas y estructura', Icon: UsersRound },
] as const;

/** A product index, deliberately not a fabricated application screenshot. */
export function PlatformOverview() {
  return (
    <aside className="lab-hero-showcase lab-platform-overview" aria-label="Módulos de Talent 360">
      <div className="lab-platform-window">
        <div className="lab-platform-titlebar"><span><i /><i /><i /></span><strong>Talent 360</strong><small>PLATAFORMA</small></div>
        <div className="lab-platform-content">
          <p className="lab-platform-kicker">HERRAMIENTAS CONECTADAS</p>
          <h2>Todo tu equipo.<br /><span>Un mismo lugar.</span></h2>
          <p className="lab-platform-intro">Módulos reales para organizar personas, asistencia y la operación diaria.</p>
          <ul>
            {modules.map(({ name, detail, Icon }) => <li key={name}><span><Icon size={17} /></span><div><strong>{name}</strong><small>{detail}</small></div></li>)}
          </ul>
        </div>
        <div className="lab-platform-foot"><span>Conoce la plataforma por dentro</span><b>↓</b></div>
      </div>
    </aside>
  );
}
