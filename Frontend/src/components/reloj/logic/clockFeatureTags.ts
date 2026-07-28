/**
 * Matriz Oficial de Etiquetas de Funciones y Eventos del Reloj Checador (Dialer).
 *
 * Utilizada por el Centro de Control General de Talent360 para definir qué
 * características del Dialer se incluyen en la versión Gratuita vs. Planes Pro/Enterprise,
 * permitiendo la activación/desactivación por checkboxes.
 */

export interface ClockFeatureTag {
  key: string;
  name: string;
  description: string;
  category: 'core' | 'opening' | 'keys' | 'meals' | 'breaks' | 'compliance' | 'security';
  defaultTier: 'free' | 'pro' | 'enterprise';
  isMandatory?: boolean; // true para funciones de seguridad legal/operativa que no deben ser desactivadas
}

export const CLOCK_FEATURE_TAGS_MATRIX: ClockFeatureTag[] = [
  {
    key: 'basic_punch',
    name: 'Fichaje Básico (Entrada / Salida)',
    description: 'Registro ordinario de marcas de entrada y salida con marca de tiempo UTC y geolocalización.',
    category: 'core',
    defaultTier: 'free',
    isMandatory: true,
  },
  {
    key: 'offline_contingency',
    name: 'Fichaje Criptográfico Offline (Sin Luz)',
    description: 'Protección legal LFT para fichar con HMAC local en IndexedDB durante fallas eléctricas o de red.',
    category: 'security',
    defaultTier: 'free',
    isMandatory: true,
  },
  {
    key: 'emergency_open',
    name: 'Apertura de Emergencia con 2 Testigos',
    description: 'Permite abrir sucursal con validación presencial de 2 compañeros si el encargado no se presenta.',
    category: 'security',
    defaultTier: 'free',
    isMandatory: true,
  },
  {
    key: 'store_closed_report',
    name: 'Reporte de Tienda Cerrada / Percance',
    description: 'Permite a los colaboradores reportar si la sucursal está cerrada o sufrieron percance en trayecto.',
    category: 'opening',
    defaultTier: 'free',
  },
  {
    key: 'store_opening',
    name: 'Gestión de Aperturas & Asignaciones',
    description: 'Horario oficial de apertura, ventanas de tolerancia, bonos y notificación automática a suplentes.',
    category: 'opening',
    defaultTier: 'pro',
  },
  {
    key: 'keys_control',
    name: 'Control y Delegación de Llaves',
    description: 'Botón "Entregar Turno", arqueo de caja al cierre y transferencias de llaves en tiempo real.',
    category: 'keys',
    defaultTier: 'pro',
  },
  {
    key: 'meal_reservation',
    name: 'Reserva & Slots de Comedor',
    description: 'Exige reservar un horario en el comedor ("Apartar Turno") antes de tomar alimentos.',
    category: 'meals',
    defaultTier: 'pro',
  },
  {
    key: 'meal_timers',
    name: 'Timers de Comida y Control de Regreso',
    description: 'Control de 90 min de espera post check-in y medición del tiempo de alimentos.',
    category: 'meals',
    defaultTier: 'pro',
  },
  {
    key: 'enable_ley_silla',
    name: 'Descanso Ergonomía Ley Silla (15 min)',
    description: 'Activa botón "Tomar Silla" post-comida para descanso ergonómico en jornadas de pie.',
    category: 'breaks',
    defaultTier: 'pro',
  },
  {
    key: 'roll_call',
    name: 'Pase de Lista & Salidas Temporales',
    description: 'Pases de salida por comisiones bancarias, trámites u oficiales con temporizador de reingreso.',
    category: 'compliance',
    defaultTier: 'pro',
  },
  {
    key: 'checklists_validation',
    name: 'Checklist Cierre Seguro (Luces / Arqueo)',
    description: 'Exige marcar validación de checklist antes de habilitar el botón "Fichar Salida".',
    category: 'compliance',
    defaultTier: 'pro',
  },
  {
    key: 'lates_academy_block',
    name: 'Bloqueo por Retardos (LMS Academia)',
    description: 'Bloquea el Dialer al acumular 3 retardos exigiendo aprobar curso en la Academia.',
    category: 'compliance',
    defaultTier: 'pro',
  },
  {
    key: 'door_amnesty',
    name: 'Amnistía por Llegada en Puerta',
    description: 'Muestra "📍 Ya llegué" a <= 15m asegurando amnistía si el encargado responsable llega tarde.',
    category: 'opening',
    defaultTier: 'pro',
  },
];
