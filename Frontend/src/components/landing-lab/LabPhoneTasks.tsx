import { Check, ClipboardList, Clock3 } from 'lucide-react';

/** Small local task flow; deliberately does not mount the live TaskRunner. */
export function LabPhoneTasks({ firstDone, secondDone, onFirstDone, onSecondDone }: {
  firstDone: boolean; secondDone: boolean;
  onFirstDone: (done: boolean) => void; onSecondDone: (done: boolean) => void;
}) {
  return <div className="lab-phone-tasks"><span>TABLERO DE EJEMPLO</span><h4>Mis tareas de hoy</h4><p>{Number(firstDone) + Number(secondDone)} de 2 completadas</p>{[
    { title: 'Limpieza General Sucursal', description: 'Sanitizar mostradores y barrer entrada.', minutes: 15, done: firstDone, setDone: onFirstDone },
    { title: 'Arqueo de Caja y Cierre', description: 'Conciliar ventas del día en terminal.', minutes: 30, done: secondDone, setDone: onSecondDone },
  ].map(task => <article key={task.title}><ClipboardList size={18} /><h5>{task.title}</h5><p>{task.description}</p><small><Clock3 size={12} /> {task.minutes} min estimados</small><button type="button" aria-pressed={task.done} onClick={() => task.setDone(!task.done)}>{task.done ? <><Check size={13} /> Completada</> : 'Completar tarea de ejemplo'}</button></article>)}<p role="status">Esta demo no modifica las tareas de tu empresa.</p></div>;
}
