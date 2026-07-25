import { useEffect } from 'react';
import { useAppStore } from '../store/useAppStore';

/**
 * Reloj R87 (Fase 3 / T3.2): Alarma de traslado.
 *
 * Aviso LOCAL del dispositivo ("Es hora de salir hacia la sucursal") N minutos ANTES del turno. La
 * preferencia (0/15/30/45/60; 0 = desactivada) vive en `currentUser.pre_shift_alarm_minutes` y el
 * turno en `currentUser.shiftStart` ('HH:MM:SS'), ambos servidos por `toAuthPayload` desde el
 * expediente.
 *
 * ALCANCE HONESTO: es una notificación LOCAL (Notification API), NO un push. El scheduler es un
 * `setInterval` que sólo corre mientras la pestaña esté viva; no hay garantía de entrega con la app
 * cerrada — eso exigiría un service worker con push/periodic-sync, fuera de T3.2. Se monta UNA vez
 * (en `App`, no en el motor del reloj) para no multiplicarse por cada teléfono del PanelSimulador,
 * que monta N `useClockEngine` sobre el mismo `localStorage`.
 *
 * El guard once-per-day (localStorage, por usuario+fecha) evita repetir el aviso el mismo día aunque
 * el intervalo cruce la ventana muchas veces. Usa hora REAL del dispositivo (no la hora simulada del
 * dial): la alarma es una comodidad del mundo real.
 */

// getDay() (0=Dom … 6=Sáb) → nombre en español, para saltar el día de descanso sin depender del
// locale de toLocaleDateString (que varía por navegador).
const WEEKDAY_ES = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

const CHECK_INTERVAL_MS = 30_000;

// El backend guarda `restDay` como texto libre y lo compara sin acentos ni mayúsculas
// (ClockService: mb_strtolower + strip). Igualamos esa tolerancia: 'Miercoles', 'miércoles' y
// 'MIÉRCOLES' deben contar como el mismo día, o la alarma sonaría en el descanso del colaborador.
function normalizeDay(s: string): string {
  return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

function localDateKey(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

/**
 * Pide permiso de notificaciones. DEBE llamarse desde un gesto del usuario (el click en el selector
 * de la alarma) — los navegadores bloquean la petición fuera de un gesto. Devuelve true si quedó
 * concedido. El scheduler nunca la pide por su cuenta (correría dentro de un setInterval, sin gesto).
 */
export async function ensureNotificationPermission(): Promise<boolean> {
  if (typeof Notification === 'undefined') return false;
  if (Notification.permission === 'granted') return true;
  if (Notification.permission === 'denied') return false;
  try {
    const result = await Notification.requestPermission();
    return result === 'granted';
  } catch {
    return false;
  }
}

export function usePreShiftAlarm(): void {
  const minutes = useAppStore(s => s.currentUser?.pre_shift_alarm_minutes);
  const shiftStart = useAppStore(s => s.currentUser?.shiftStart);
  const restDay = useAppStore(s => s.currentUser?.restDay);
  const userId = useAppStore(s => s.currentUser?.id);

  useEffect(() => {
    // Desactivada, sin turno, o navegador sin Notification API → nada que programar.
    if (!minutes || !shiftStart || !userId) return;
    if (typeof Notification === 'undefined') return;

    const [hh, mm] = String(shiftStart).split(':').map(Number);
    if (Number.isNaN(hh) || Number.isNaN(mm)) return;

    const check = () => {
      // El permiso se pide en el gesto (modal). Aquí sólo disparamos si ya está concedido.
      if (Notification.permission !== 'granted') return;

      const now = new Date();

      // No molestar en el día de descanso del colaborador (comparación tolerante a acentos/mayúsculas).
      if (restDay && normalizeDay(WEEKDAY_ES[now.getDay()]) === normalizeDay(String(restDay))) return;

      const shiftAt = new Date(now);
      shiftAt.setHours(hh, mm, 0, 0);
      const alarmAt = new Date(shiftAt.getTime() - minutes * 60_000);

      // Ventana: desde la hora de la alarma hasta el inicio del turno. Después del turno ya no avisa
      // (no tiene sentido "sal hacia la sucursal" cuando el turno ya empezó).
      // LIMITACIÓN CONOCIDA: `shiftAt` se ancla al día de HOY, así que un turno de madrugada (p.ej.
      // 00:30 con alarma de 60m) cuya ventana cae la noche ANTERIOR no dispara. El giro es comercio
      // con turnos de mañana (08:xx–09:xx); si algún día hay turnos nocturnos habría que anclar también
      // al día siguiente. No se maneja hoy a propósito (YAGNI), pero queda anotado.
      if (now < alarmAt || now >= shiftAt) return;

      const guardKey = `preShiftAlarmFired:${userId}:${localDateKey(now)}`;
      if (localStorage.getItem(guardKey)) return;
      localStorage.setItem(guardKey, '1');

      try {
        new Notification('Es hora de salir hacia la sucursal 🚶', {
          body: `Tu turno empieza a las ${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}. ¡Buen día!`,
          tag: guardKey, // colapsa duplicados a nivel del SO
        });
      } catch {
        // Notification puede lanzar en contextos no seguros; el guard ya quedó puesto, no reintentamos.
      }
    };

    check(); // chequeo inmediato al montar / cambiar preferencia
    const id = window.setInterval(check, CHECK_INTERVAL_MS);
    return () => window.clearInterval(id);
  }, [minutes, shiftStart, restDay, userId]);
}
