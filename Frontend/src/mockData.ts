export interface CollaboratorProfile {
  id: number;
  name: string;
  role: string;
  role_id: number;
  area: string;
  avatar: string;
  
  // -- VARIABLES DE JORNADA --
  shiftStart: string; 
  shiftEnd: string;   
  mealMinutes: number;
  restDay: string;
  
  // -- SWITCHES DE PERMISOS --
  esAperturador: boolean;
  portadorLlaves: 'apertura' | 'cierre' | 'ambos' | 'ninguno';
  jerarquiaLlaves: number; // 0 if not applicable
  tiempoTolerancia: number;
  requiereJustificante: boolean;
  puedeEmitirAvisos: boolean;
  aplicaLeySilla: boolean;
  evaluacion360Activa: boolean;
  
  reliefBuddyId?: number;
}

export const MOCK_USERS: CollaboratorProfile[] = [
  {
    id: 1,
    name: "Francisco",
    role: "Administrador / Gerente",
    role_id: 1,
    area: "Administración",
    avatar: "https://i.pravatar.cc/150?u=admin",
    shiftStart: "08:20",
    shiftEnd: "18:00",
    mealMinutes: 60,
    restDay: "Domingo",
    esAperturador: true,
    portadorLlaves: "ambos",
    jerarquiaLlaves: 1,
    tiempoTolerancia: 10,
    requiereJustificante: true,
    puedeEmitirAvisos: true,
    aplicaLeySilla: false,
    evaluacion360Activa: false,
    reliefBuddyId: 2
  },
  {
    id: 2,
    name: "Liz",
    role: "Sup. Tienda y Compras",
    role_id: 2,
    area: "Piso",
    avatar: "https://i.pravatar.cc/150?u=suptienda",
    shiftStart: "08:20",
    shiftEnd: "18:00",
    mealMinutes: 60,
    restDay: "Lunes",
    esAperturador: true,
    portadorLlaves: "apertura",
    jerarquiaLlaves: 2,
    tiempoTolerancia: 10,
    requiereJustificante: true,
    puedeEmitirAvisos: true,
    aplicaLeySilla: false,
    evaluacion360Activa: false,
    reliefBuddyId: 1
  },
  {
    id: 3,
    name: "Joseline",
    role: "Sup. Cajas",
    role_id: 3,
    area: "Cajas",
    avatar: "https://i.pravatar.cc/150?u=supcajas",
    shiftStart: "08:20",
    shiftEnd: "18:00",
    mealMinutes: 60,
    restDay: "Martes",
    esAperturador: true,
    portadorLlaves: "apertura",
    jerarquiaLlaves: 3,
    tiempoTolerancia: 10,
    requiereJustificante: true,
    puedeEmitirAvisos: true,
    aplicaLeySilla: false,
    evaluacion360Activa: false,
    reliefBuddyId: 2
  },
  {
    id: 4,
    name: "Hiraym",
    role: "Sup. Producción",
    role_id: 4,
    area: "Producción",
    avatar: "https://i.pravatar.cc/150?u=produccion",
    shiftStart: "09:00",
    shiftEnd: "18:00",
    mealMinutes: 60,
    restDay: "Miércoles",
    esAperturador: false,
    portadorLlaves: "ninguno",
    jerarquiaLlaves: 0,
    tiempoTolerancia: 10,
    requiereJustificante: true,
    puedeEmitirAvisos: false,
    aplicaLeySilla: false,
    evaluacion360Activa: false,
    reliefBuddyId: 1
  },
  {
    id: 5,
    name: "Agnela",
    role: "Cajeros",
    role_id: 5,
    area: "Cajas",
    avatar: "https://i.pravatar.cc/150?u=cajero1",
    shiftStart: "08:30",
    shiftEnd: "17:00",
    mealMinutes: 30,
    restDay: "Domingo",
    esAperturador: false,
    portadorLlaves: "ninguno",
    jerarquiaLlaves: 0,
    tiempoTolerancia: 10,
    requiereJustificante: false,
    puedeEmitirAvisos: false,
    aplicaLeySilla: true,
    evaluacion360Activa: true,
    reliefBuddyId: 6
  },
  {
    id: 6,
    name: "Adriana",
    role: "Cajeros",
    role_id: 5,
    area: "Cajas",
    avatar: "https://i.pravatar.cc/150?u=cajero2",
    shiftStart: "08:30",
    shiftEnd: "17:00",
    mealMinutes: 30,
    restDay: "Lunes",
    esAperturador: false,
    portadorLlaves: "ninguno",
    jerarquiaLlaves: 0,
    tiempoTolerancia: 10,
    requiereJustificante: false,
    puedeEmitirAvisos: false,
    aplicaLeySilla: true,
    evaluacion360Activa: true,
    reliefBuddyId: 5
  },
  {
    id: 7,
    name: "Cristina",
    role: "Ayudante Integral",
    role_id: 6,
    area: "Piso",
    avatar: "https://i.pravatar.cc/150?u=ayudante1",
    shiftStart: "08:30",
    shiftEnd: "17:00",
    mealMinutes: 30,
    restDay: "Martes",
    esAperturador: false,
    portadorLlaves: "ninguno",
    jerarquiaLlaves: 0,
    tiempoTolerancia: 10,
    requiereJustificante: false,
    puedeEmitirAvisos: false,
    aplicaLeySilla: true,
    evaluacion360Activa: true,
    reliefBuddyId: 8
  },
  {
    id: 8,
    name: "Valeria",
    role: "Ayudante Integral",
    role_id: 6,
    area: "Piso",
    avatar: "https://i.pravatar.cc/150?u=ayudante2",
    shiftStart: "09:00",
    shiftEnd: "17:30",
    mealMinutes: 30,
    restDay: "Miércoles",
    esAperturador: false,
    portadorLlaves: "ninguno",
    jerarquiaLlaves: 0,
    tiempoTolerancia: 10,
    requiereJustificante: false,
    puedeEmitirAvisos: false,
    aplicaLeySilla: true,
    evaluacion360Activa: true,
    reliefBuddyId: 7
  },
  {
    id: 9,
    name: "Manuel",
    role: "Cajeros",
    role_id: 5,
    area: "Cajas",
    avatar: "https://i.pravatar.cc/150?u=cajero3",
    shiftStart: "08:30",
    shiftEnd: "17:00",
    mealMinutes: 30,
    restDay: "Jueves",
    esAperturador: false,
    portadorLlaves: "ninguno",
    jerarquiaLlaves: 0,
    tiempoTolerancia: 10,
    requiereJustificante: false,
    puedeEmitirAvisos: false,
    aplicaLeySilla: true,
    evaluacion360Activa: true,
    reliefBuddyId: 5
  }
];

export const MOCK_STORE = {
  id: 101,
  name: "Sucursal Centro",
  status: "closed", // 'closed', 'open'
  activeEncargadoId: 1, // Por defecto es el Admin (ID 1)
  hasAmnesty: false,
  requireEvaluation: true // Simula que hoy es día de evaluación 360
};
