import { create } from 'zustand';
import axiosInstance from '../lib/axios';
import { useTaskStore } from './useTaskStore';
import type { User, Tenant } from '../types';
import { fichajesDeHoy, hoyEnZona, fechaDeFichaje } from '../lib/jornadaDelDia';
import { avatarDe } from '../lib/avatar';

interface AppState {
  isLoadingDB: boolean;
  globalUsers: User[];
  currentUser: User | null;
  currentTier: 'freemium' | 'pro' | 'enterprise'; // SaaS Tier
  systemSettings: any;
  roleClockPolicies: any[];
  storeStatus: 'open' | 'closed';
  globalClockStates: Record<number, string>;
  globalCheckInTimes: Record<number, number>;
  globalArrivalTimes: Record<number, number>;
  globalSimTime: number;
  globalSimRunning: boolean;
  globalSimSpeed: number;
  matrixTimeline: any[];
  isSandboxMode: boolean;
  activeEncargadoId: number;
  /** H6: ids de colaboradores con autorización de entrada tardía APROBADA hoy (del backend). */
  lateAuthorizedUserIds: number[];
  hasAlertedStoreDelay: boolean;
  globalSimDay: string;
  setGlobalSimDay: (day: string) => void;
  globalBreakStartTimes?: Record<number, number>;
  globalBreakEndTimes?: Record<number, number>;
  globalMealStartTimes?: Record<number, number>;
  globalMealEndTimes?: Record<number, number>;
  globalCheckOutTimes?: Record<number, number>;
  globalPendingBreakRequests?: Record<number, any>;
  
  // Comida State
  reservedMeals: Record<string, { userId: number, role: string }[]>;
  userReservedMealSlots: Record<number, string[]>;
  hasReservedMeal: Record<number, boolean>;

  // SaaS State
  saasTenants: Tenant[];
  saasPricing: Record<string, number>;
  saasAlerts: any[];
  globalRoles: any[];
  dbPermissions: any[];
  dbRolePermissions: any[];
  allowedModules: string[];
  allowedFeatures: string[];
  simulatedTierOverride: 'freemium' | 'pro' | 'enterprise' | null;

  // Auditoría reloj checador (2026-07-22), Hallazgo 1 / punto 1 del plan de acción:
  // estatus real de puntualidad desde el backend (GET /me/punctuality-status), cacheado
  // SOLO en memoria (nunca localStorage — así no es evadible borrando datos del navegador).
  punctualityStatus: { blocked: boolean; lates_count: number; required_course_id: number | null; course_completed: boolean } | null;

  /**
   * Mensajes PRIVADOS dirigidos a quien está en sesión, tal como llegan en `/sync/state`.
   *
   * Los escribe un mando desde el Chat Operativo del Monitor eligiendo destinatario. Antes esto
   * no existía: el reloj tenía un mapa de mensajes privados que NADIE llenaba, así que el admin
   * creía haber escrito y el colaborador no veía nunca nada.
   */
  misMensajesPrivados: { id: number; content: string; sender_id: number | null; created_at?: string }[];

  // Setters
  setIsLoadingDB: (loading: boolean) => void;
  setGlobalUsers: (users: User[]) => void;
  setCurrentUser: (user: User | null) => void;
  setCurrentTier: (tier: 'freemium' | 'pro' | 'enterprise') => void;
  setSimulatedTierOverride: (tier: 'freemium' | 'pro' | 'enterprise' | null) => void;
  setSystemSettings: (settings: any) => void;
  setStoreStatus: (status: 'open' | 'closed') => void;
  setGlobalClockState: (userId: number, state: string) => void;
  setGlobalCheckInTime: (userId: number, time: number) => void;
  setGlobalArrivalTime: (userId: number, time: number) => void;
  setGlobalSimTime: (time: number | ((prev: number) => number)) => void;
  setGlobalSimRunning: (running: boolean) => void;
  setGlobalSimSpeed: (speed: number) => void;
  addMatrixEvent: (title: string, description: string, type: 'success'|'warning'|'error'|'info'|'system', actorId?: number) => void;
  resetGlobalSimulation: () => Promise<void>;
  setHasAlertedStoreDelay: (val: boolean) => void;
  setIsSandboxMode: (val: boolean) => void;
  setActiveEncargadoId: (id: number) => void;
  setReservedMeals: (meals: Record<string, { userId: number, role: string }[]>) => void;
  setUserReservedMealSlots: (slots: Record<number, string[]>) => void;
  setHasReservedMeal: (hasMeals: Record<number, boolean>) => void;
  setGlobalRoles: (roles: any[]) => void;
  setDbPermissions: (perms: any[]) => void;
  setDbRolePermissions: (rolePerms: any[]) => void;
  
  // Actions
  fetchState: (explicitSimSessionId?: number | string | null) => Promise<void>;
  updateSetting: (key: string, value: any) => Promise<void>;
  fetchPunctualityStatus: () => Promise<void>;

  // SaaS Actions
  addSaaSTenant: (tenant: Tenant) => void;
  updateSaaSPricing: (moduleId: string, price: number) => void;
  resolveSaaSAlert: (alertId: string) => Promise<void>;
  
  // HR Workflow Actions
  // `hireEmployee` se eliminó (2026-08-11): fabricaba un colaborador en el navegador con correo
  // inventado, `tenant_id: 1` en duro y el id de la VACANTE como id de PUESTO. El tablero del ATS
  // ahora relee del servidor después de contratar.
  completeInduction: (userId: number) => void;
  
  isModuleUnlocked: (moduleId: string) => boolean;
  isFeatureUnlocked: (featureId: string) => boolean;
}

export const useAppStore = create<AppState>((set, get) => ({
  isLoadingDB: true,
  storeStatus: 'closed',
  globalClockStates: {},
  globalCheckInTimes: {},
  globalArrivalTimes: {},
  globalSimTime: 450, // 7:30 AM
  globalSimRunning: false,
  globalSimSpeed: 5,
  matrixTimeline: [],
  hasAlertedStoreDelay: false,
  // FIX H9 (prueba en vivo 2026-07-29): arrancaba en `true`, y con el sandbox encendido
  // `syncToBackend` y `syncAssignmentRow` (useTaskStore) retornan SIN escribir ("guardado en
  // RAM solamente"). El ÚNICO lugar que lo apagaba era el módulo Matrix QA al montarse
  // (PanelSimulador), así que un usuario normal —que nunca entra ahí— trabajaba todo el día
  // en sandbox: al completar una tarea el TaskRunner mostraba "¡Recompensa Obtenida! +$3.00 /
  // +30 XP" y el monedero subía en pantalla, pero en la base la asignación seguía `pending`
  // con 0 monedas y sin `wallet_transactions`; al recargar, todo perdido.
  // El default seguro es NO-sandbox: se persiste de verdad. El modo de pruebas se enciende
  // explícitamente (toggle de RelojVisual o al entrar a Matrix QA).
  isSandboxMode: false,
  activeEncargadoId: 1,
  lateAuthorizedUserIds: [],
  globalSimDay: 'Sábado',
  globalBreakStartTimes: {},
  globalBreakEndTimes: {},
  globalMealStartTimes: {},
  globalMealEndTimes: {},
  globalCheckOutTimes: {},
  globalPendingBreakRequests: {},
  reservedMeals: {},
  userReservedMealSlots: {},
  hasReservedMeal: {},
  allowedModules: (typeof localStorage !== 'undefined' && localStorage.getItem('qa_simulated_tier_override') === 'freemium') 
    ? ['reloj', 'rrhh', 'operativo'] 
    : ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'academia', 'documentos', 'portal'],
  allowedFeatures: (typeof localStorage !== 'undefined' && localStorage.getItem('qa_simulated_tier_override') === 'freemium') 
    ? [] 
    : ['keys_control', 'meal_timers', 'checklists_validation', 'voice_commands', 'store_opening', 'meal_reservation', 'enable_ley_silla'],
  // 2026-07-26 (auditoría en vivo, hallazgo grave): esto tenía `|| 'pro'` como valor por defecto.
  // `simulatedTierOverride` es una herramienta de QA (Matrix) y `activeTier` se resuelve como
  // `simulatedTierOverride || currentTier` — es decir, con el default en 'pro' la simulación estaba
  // ENCENDIDA para todos, siempre, y el plan real del tenant nunca se usaba: una empresa Enterprise
  // que paga quedaba degradada a las funciones de Pro, y una freemium quedaba ascendida a Pro.
  // El valor correcto en ausencia de simulación es `null` (= "sin override, usa el plan real").
  simulatedTierOverride: ((typeof localStorage !== 'undefined' && localStorage.getItem('qa_simulated_tier_override')) as any) || null,
  punctualityStatus: null,
  misMensajesPrivados: [],

  // Initial SaaS State
  saasTenants: [],
  saasPricing: {
    'reclutamiento': 29,
    'academia': 49,
    'reportes': 19,
    'rutinas': 0
  },
  saasAlerts: [
    { type: 'error', message: 'Fallo de conexión a la base de datos de réplica en GKE.', time: 'Hace 10 min' },
    { type: 'warning', message: 'Alta latencia en el envío masivo de WhatsApp (API Meta).', time: 'Hace 45 min' },
  ],

  globalUsers: [], // Inicialmente vacío para force state de carga
  roleClockPolicies: [],
  globalRoles: [],
  dbPermissions: [],
  dbRolePermissions: [],
  currentUser: { id: 1, name: 'Loading...', role: 'Loading', system_role: 'Loading', email: '', tenant_id: 1, avatar: '', mealMinutes: 60, job_role_id: 1 },
  currentTier: ((typeof localStorage !== 'undefined' && localStorage.getItem('qa_simulated_tier_override')) as any) || 'pro', // Inicializado en freemium real
  systemSettings: {
    leySillaConfig: { enabled: true, consecutiveMinutes: 120, breakMinutes: 15 },
    featureFlags: { 
      paseDeLista: true, forzosa: true, cctv: true, comidas: true, 
      amnistia_global: false, control_llaves: true, evaluacion_salida: false, kiosk_mode: true 
    },
    mealSettings: { maxChairs: 4, startHour: 12, endHour: 17, hideFullSlots: true, stepMins: 15, preventRoleOverlap: true, delayMinutos: 5 },
    timeBankConfigs: { maxLateMinsAllowed: 15, mealMinutes: 60 },
    adminConfigs: { allowAmnesty: true, showHistory: true, allowThemes: true },
    tasksConfig: {
      requireSupervisorValidation: true,
      validationThreshold: 'all_tasks',
      allowTaskRejection: true,
      aiAutoApproveIfValid: false
    },
    clockOpConfig: {
      gpsValidationEnabled: false,
      allowManualCheckIn: true,
      allow_floating_push_notifications: false
    },
    globalStoreShiftStart: '09:00',
    globalStoreShiftEnd: '18:00',
    uiState: { menuCollapsed: false, currentTheme: 'light' }
  },

  setIsLoadingDB: (loading) => set({ isLoadingDB: loading }),
  setGlobalUsers: (users) => set({ globalUsers: users }),
  setCurrentUser: (user) => set({ currentUser: user }),
  setCurrentTier: (tier) => {
    set({ currentTier: tier });
    // (2026-08-22) Todo login (contraseña, Google, 2FA, cambio forzado) pasa por aquí ya con el
    // token guardado, así que es el único punto común para cargar el estado real. Antes
    // fetchState() sólo corría si había token AL MONTAR la app: tras un login fresco el admin
    // caía en el Monitor (que no lo llama) y Configuración mostraba los valores por defecto del
    // store —horario 08:00-18:00, tolerancia 15— en vez de los de su empresa; guardar ahí los
    // pisaba. Si la app ya cargó el estado al montar, esta segunda llamada sólo lo refresca.
    if (localStorage.getItem('talent_auth_token')) {
      get().fetchState().catch(() => { /* sin red se trabaja con lo que haya */ });
    }
  },
  setSimulatedTierOverride: (tier) => {
    if (tier) {
      localStorage.setItem('qa_simulated_tier_override', tier);
    } else {
      localStorage.removeItem('qa_simulated_tier_override');
    }
    set({ simulatedTierOverride: tier });
    if (tier) {
      set({ currentTier: tier });
      if (tier === 'freemium') {
        set({ allowedModules: ['reloj', 'rrhh', 'operativo'], allowedFeatures: [] });
      } else if (tier === 'pro' || tier === 'enterprise') {
        set({ 
          allowedModules: ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'academia', 'documentos', 'portal'], 
          allowedFeatures: ['keys_control', 'meal_timers', 'checklists_validation', 'voice_commands', 'store_opening', 'meal_reservation', 'enable_ley_silla'] 
        });
      }
    } else {
      get().fetchState();
    }
  },
  setSystemSettings: (settings) => set({ systemSettings: settings }),
  setStoreStatus: (status) => set({ storeStatus: status }),
  setGlobalClockState: (userId, state) => set((s) => {
    if (s.globalClockStates[userId] === state) return {};
    return { globalClockStates: { ...s.globalClockStates, [userId]: state } };
  }),
  setGlobalCheckInTime: (userId, time) => set((s) => {
    if (s.globalCheckInTimes[userId] === time) return {};
    return { globalCheckInTimes: { ...s.globalCheckInTimes, [userId]: time } };
  }),
  setGlobalArrivalTime: (userId, time) => set((s) => {
    if (s.globalArrivalTimes[userId] === time) return {};
    return { globalArrivalTimes: { ...s.globalArrivalTimes, [userId]: time } };
  }),
  setGlobalSimTime: (updater: any) => set((state: any) => ({ 
    globalSimTime: typeof updater === 'function' ? updater(state.globalSimTime) : updater 
  })),
  setGlobalSimRunning: (running) => set({ globalSimRunning: running }),
  setGlobalSimSpeed: (speed) => set({ globalSimSpeed: speed }),
  setReservedMeals: (updater: any) => set((s) => ({ reservedMeals: typeof updater === 'function' ? updater(s.reservedMeals) : updater })),
  setUserReservedMealSlots: (updater: any) => set((s) => ({ userReservedMealSlots: typeof updater === 'function' ? updater(s.userReservedMealSlots) : updater })),
  setHasReservedMeal: (updater: any) => set((s) => ({ hasReservedMeal: typeof updater === 'function' ? updater(s.hasReservedMeal) : updater })),
  setGlobalRoles: (roles) => set({ globalRoles: roles }),
  setDbPermissions: (perms) => set({ dbPermissions: perms }),
  setDbRolePermissions: (rolePerms) => set({ dbRolePermissions: rolePerms }),
  setIsSandboxMode: (val) => set({ isSandboxMode: val }),
  setActiveEncargadoId: (id) => set({ activeEncargadoId: id }),
  setGlobalSimDay: (day) => set({ globalSimDay: day }),

  addSaaSTenant: (tenant) => set((s) => ({ saasTenants: [tenant, ...s.saasTenants] })),
  updateSaaSPricing: (moduleId, price) => set((s) => ({ saasPricing: { ...s.saasPricing, [moduleId]: price } })),
  resolveSaaSAlert: async (alertId: string) => {
    try {
      const res = await axiosInstance.post('/platform/alerts/resolve', { id: alertId });
      if (res.status === 200) {
        set({ saasAlerts: res.data });
      }
    } catch (e) {
      console.error("Error resolving SaaS alert:", e);
      // Fallback local filter
      set((s) => ({ saasAlerts: s.saasAlerts.filter((a) => a.id !== alertId) }));
    }
  },

  completeInduction: (userId) => set((s) => ({
    globalUsers: s.globalUsers.map(u => u.id === userId ? { ...u, has_completed_induction: true } : u),
    // También si el currentUser es el mismo, actualizarlo en la sesión
    currentUser: s.currentUser && s.currentUser.id === userId ? { ...s.currentUser, has_completed_induction: true } : s.currentUser
  })),

  setHasAlertedStoreDelay: (val) => set({ hasAlertedStoreDelay: val }),
  resetGlobalSimulation: async () => {
    // AQUÍ SE LLAMABA A `/sync/reset_day` (2026-08-11: quitado).
    //
    // El panel del Simulador afirma en su propio diálogo que "los datos de la sesión anterior NO
    // se borran" y que "no afecta fichajes reales", y su comentario de código dice "nunca borra
    // datos". Pero esta función posteaba a `/sync/reset_day`, que borra la jornada REAL del día
    // —fichajes, bitácora de tienda, eventualidades y auditoría— sin distinguir simulación de
    // realidad (no filtra `simulation_session_id`). Y el error se tragaba en un console.error,
    // así que la pantalla decía "sesión iniciada" pasara lo que pasara.
    //
    // El trabajo de esta función es reiniciar el estado VISUAL, que es lo que hace justo debajo.
    // Para borrar datos de prueba ya existe un botón propio y correctamente acotado
    // (`/sync/reset` con session_id, que sólo toca filas de simulación).
    const initialClockStates = get().globalUsers.reduce((acc, user) => ({ ...acc, [user.id]: 'inactive' }), {});
    set({
      globalClockStates: initialClockStates,
      globalCheckInTimes: {},
      globalArrivalTimes: {},
      globalSimTime: 450, // 7:30 AM
      globalSimRunning: false,
      globalSimDay: 'Sábado',
      storeStatus: 'closed',
      hasAlertedStoreDelay: false,
      matrixTimeline: [{
        id: Date.now() + Math.random(),
        timeStr: '7:30 am',
        simTime: 450,
        title: 'Sistema Reiniciado',
        description: get().isSandboxMode
          ? 'Se ha restablecido la simulación a su estado original para todos los usuarios.'
          : 'Se ha restablecido la simulación y depurado los registros del día de la base de datos.',
        type: 'system'
      }]
    });

    await get().fetchState();
  },
  addMatrixEvent: (title, description, type, actorId) => set((state) => {
    const currentSimMins = state.globalSimTime || 0;
    const hours = Math.floor(currentSimMins / 60);
    const mins = currentSimMins % 60;
    const ampm = hours >= 12 ? 'pm' : 'am';
    const displayHours = hours > 12 ? hours - 12 : hours;
    const timeStr = `${displayHours}:${mins.toString().padStart(2, '0')} ${ampm}`;
    return {
      matrixTimeline: [{
        id: Date.now() + Math.random(),
        timeStr,
        simTime: currentSimMins,
        title,
        description,
        type,
        actorId,
        isLocal: true // Preservar en el filtrado de fetchState
      }, ...state.matrixTimeline]
    };
  }),


  fetchState: async (explicitSimSessionId?: number | string | null) => {
    try {
      const hasToken = !!localStorage.getItem('talent_auth_token');
      const isUserLoaded = get().currentUser && get().currentUser?.role !== 'Loading';
      if (hasToken && !isUserLoaded) {
        try {
          const meRes = await axiosInstance.get('/me');
          if (meRes.status === 200 && meRes.data.user) {
            const meUser = meRes.data.user;
            set({ currentUser: { ...meUser, system_role: meUser.role } });
            const tenant = meUser.tenant || meRes.data.tenant;
            if (!get().simulatedTierOverride) {
              if (meUser.tenant_id === 1) {
                set({ currentTier: 'enterprise' });
              } else if (tenant?.plan) {
                set({ currentTier: tenant.plan.toLowerCase() as any });
              }
            }
            if (meUser.tenant_id === null && meUser.role !== 'platform_admin' && meUser.role !== 'support_agent') {
              return;
            }
            if (meUser.role === 'platform_admin') {
              try {
                const alertsRes = await axiosInstance.get('/platform/alerts');
                if (alertsRes.status === 200) {
                  set({ saasAlerts: alertsRes.data });
                }
              } catch (alertError) {
                console.error("Error fetching SaaS alerts:", alertError);
              }
            }
          }
        } catch (e) {
          console.error("Error fetching me:", e);
        }
      }

      const currentUser = get().currentUser;
      if (currentUser && currentUser.tenant_id === null && currentUser.system_role !== 'platform_admin' && currentUser.system_role !== 'support_agent') {
        return;
      }

      const activeSimSession = explicitSimSessionId || localStorage.getItem('matrix_active_sim_session_id');
      const syncParams: any = {};
      if (activeSimSession) {
        syncParams.simulation_session_id = activeSimSession;
      }

      const res = await axiosInstance.get('/sync/state', { params: syncParams, timeout: 15000 });

      if (res.status === 200) {
        const data = res.data;
        const state = get();
        
        const toArr = (val: any) => Array.isArray(val) ? val : (val && typeof val === 'object' ? Object.values(val) : []);
        const rawUsers = toArr(data.users);
        if (rawUsers.length > 0) {
            const activeUsers = rawUsers.filter((u: any) => u.is_active_employee !== false && u.is_active_employee !== 0 && u.is_active_employee !== '0');
            const mappedUsers = activeUsers.map((u: any) => {
              // Buscar el nombre del puesto usando job_role_id
              let roleName = 'Empleado';
              let esAperturador = false;
              let jerarquiaLlaves = 0;
              const rolesList = toArr(data.job_roles || data.jobRoles);
              if (rolesList.length > 0) {
                const foundRole = rolesList.find((r: any) => r.id === u.job_role_id);
                if (foundRole) {
                  roleName = foundRole.name;
                  esAperturador = foundRole.esAperturador ? true : false;
                  jerarquiaLlaves = foundRole.jerarquiaLlaves || 0;
                }
              }

              return {
                id: u.id,
                employee_id: u.employee_id,
                name: u.name,
                email: u.email || '',
                tenant_id: u.tenant_id ?? 1,
                role: roleName,
                system_role: u.role,
                avatar: avatarDe(u),
                job_role_id: u.job_role_id,
                shiftStart: u.shiftStart || '09:00',
                shiftEnd: u.shiftEnd || '18:00',
                restDay: u.restDay || 'Domingo',
                mealMinutes: u.mealMinutes || 60,
                portadorLlaves: u.portadorLlaves || 'ninguno',
                esAperturador,
                jerarquiaLlaves,
                is_active_employee: true,
                has_completed_induction: u.has_completed_induction ? true : false,
                phone: u.phone || '',
                pin_code: u.pin_code || ''
              };
            });
            
            set({ globalUsers: mappedUsers });
            
            // El servidor decide si la tienda está abierta con los registros de HOY. Antes se
            // tomaba el último registro de la semana: una apertura de anoche sin cierre dejaba
            // la tienda "abierta" al día siguiente, en contra del día de apertura (pendiente).
            if (data.store_status === 'open' || data.store_status === 'closed') {
               set({ storeStatus: data.store_status });
            } else if (data.store_logs && data.store_logs.length > 0) {
               const latestLog = data.store_logs[0];
               set({ storeStatus: latestLog.type === 'open' ? 'open' : 'closed' });
            } else {
               set({ storeStatus: 'closed' });
            }
          
          // Actualizar currentUser (evitar sobreescribir administradores/supervisores con roles de empleados)
          if (state.currentUser && state.currentUser.id !== 0) {
             const currentUserId = state.currentUser.id;
             const sysRole = state.currentUser.system_role || state.currentUser.role;
             const isEmployee = sysRole === 'empleado' || sysRole === 'employee';
             
             if (isEmployee) {
                const me = mappedUsers.find((x: any) => x.id === currentUserId);
                if (me) {
                   const updatedUser = { ...state.currentUser, ...me, system_role: state.currentUser.system_role || me.system_role };
                   set({ currentUser: updatedUser });
                }
             }
          } else if (mappedUsers.length > 0) {
             const hasAdminToken = !!localStorage.getItem('talent_auth_token');
             if (!hasAdminToken) {
                set({ currentUser: mappedUsers[0] });
             }
          }
        }
        
        // Cargar Configuraciones Globales si existen en DB
        if (data.system_settings) {
           set((prevState) => ({
             systemSettings: {
               ...prevState.systemSettings,
               ...data.system_settings // Sobrescribe con lo que viene del backend
             }
           }));
        }

        if (data.role_clock_policies) {
           set({ roleClockPolicies: toArr(data.role_clock_policies) });
        }

        if (data.active_encargado_id) {
           set({ activeEncargadoId: data.active_encargado_id });
        }

        // H6: ids con autorización de entrada tardía APROBADA hoy. El backend ya deja fichar
        // a esta gente pese al Retardo Extremo; el dial necesita saberlo para no seguir
        // mostrando "ACCESO BLOQUEADO" a quien ya fue autorizado.
        set({ lateAuthorizedUserIds: Array.isArray(data.late_authorized_user_ids) ? data.late_authorized_user_ids.map(Number) : [] });

        if (data.job_roles) {
           set({ globalRoles: toArr(data.job_roles) });
        }
        if (data.permissions) {
           set({ dbPermissions: toArr(data.permissions) });
        }
        if (data.role_permissions) {
           set({ dbRolePermissions: toArr(data.role_permissions) });
        }

        if (!state.isSandboxMode) {
          const tierOverride = get().simulatedTierOverride;
          if (tierOverride) {
            set({ currentTier: tierOverride });
            if (tierOverride === 'freemium') {
              set({ allowedModules: ['reloj', 'rrhh', 'operativo'], allowedFeatures: [] });
            } else if (tierOverride === 'pro' || tierOverride === 'enterprise') {
              set({ 
                allowedModules: ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'academia', 'documentos', 'portal'], 
                allowedFeatures: ['keys_control', 'meal_timers', 'checklists_validation', 'voice_commands', 'store_opening', 'meal_reservation', 'enable_ley_silla'] 
              });
            }
          } else {
            if (get().currentUser?.tenant_id === 1) {
              set({ currentTier: 'enterprise' });
            } else if (data.tenant_plan) {
              set({ currentTier: data.tenant_plan.toLowerCase() as any });
            }
            if (data.tenant_allowed_modules) {
              set({ allowedModules: data.tenant_allowed_modules });
            }
            if (data.tenant_allowed_features) {
              set({ allowedFeatures: data.tenant_allowed_features });
            }
          }

          if (data.time_entries) {
            // H10: el filtro usaba la fecha del DISPOSITIVO (`new Date()`), pero el backend
            // fecha cada ponche con la zona horaria del TENANT. Si no coinciden —colaborador
            // de viaje, dispositivo con la zona mal puesta, empresa de otra región— se
            // descartaban TODOS los fichajes del día: sin `check_in` el motor caía a
            // `inactive` y, con retardo, aparecía el candado sin salida. Misma familia que el
            // corte del día por tenant ya corregido en el backend (A5/M5).
            // H21: la jornada se resuelve con el turno de CADA colaborador. Con un turno que
            // cruza medianoche el backend fecha los ponches por el día en que EMPEZÓ la jornada,
            // así que a la 01:00 sus fichajes viven bajo la fecha de ayer; filtrarlos por "hoy"
            // los descartaría y el motor lo daría por no fichado a media noche de trabajo.
            const turnoPorUsuario = new Map<number, { shiftStart?: string | null; shiftEnd?: string | null }>(
              (data.users || []).map((u: any) => [Number(u.id), { shiftStart: u.shiftStart, shiftEnd: u.shiftEnd }])
            );
            const todayEntries = fichajesDeHoy(
              data.time_entries,
              data.system_settings?.timezone,
              (e: any) => turnoPorUsuario.get(Number(e.user_id)),
            );

            const clockStates: Record<number, string> = {};
            const checkInTimes: Record<number, number> = {};
            const arrivalTimes: Record<number, number> = {};
            const breakStartTimes: Record<number, number> = {};
            const breakEndTimes: Record<number, number> = {};
            const mealStartTimes: Record<number, number> = {};
            const mealEndTimes: Record<number, number> = {};
            const checkOutTimes: Record<number, number> = {};
            const pendingBreakRequests: Record<number, any> = {};
            // (2026-08-22) Reservas de comedor: vivían sólo en memoria del navegador. Al recargar
            // la página (o cambiar de dispositivo) la reserva "desaparecía" —el dial volvía a
            // "Apartar comida" con la reserva ya en la base— y el aforo se contaba vacío.
            const reservas: Record<number, { mins: number }> = {};
            let ultimoSwap: string | null = null;

            const parseTimeToMins = (timeStr: string) => {
              if (!timeStr) return 0;
              const parts = timeStr.split(':');
              const h = parseInt(parts[0], 10) || 0;
              const m = parseInt(parts[1], 10) || 0;
              return h * 60 + m;
            };

            const sortedEntries = [...todayEntries].sort((a: any, b: any) => a.id - b.id);

            sortedEntries.forEach((entry: any) => {
              const userId = entry.user_id;
              const timeMins = parseTimeToMins(entry.time);

              if (entry.type === 'waiting') {
                clockStates[userId] = 'waiting_room';
              } else if (entry.type === 'check_in') {
                clockStates[userId] = 'active';
                checkInTimes[userId] = timeMins;
                arrivalTimes[userId] = timeMins;
              } else if (entry.type === 'meal_start') {
                clockStates[userId] = 'meal';
                mealStartTimes[userId] = timeMins;
              } else if (entry.type === 'meal_end') {
                clockStates[userId] = 'active';
                mealEndTimes[userId] = timeMins;
              } else if (entry.type === 'break_request') {
                pendingBreakRequests[userId] = { time: timeMins, details: entry.details };
              } else if (entry.type === 'break_start') {
                clockStates[userId] = 'short_break';
                breakStartTimes[userId] = timeMins;
                delete pendingBreakRequests[userId];
              } else if (entry.type === 'break_rejected') {
                delete pendingBreakRequests[userId];
              } else if (entry.type === 'break_end') {
                clockStates[userId] = 'active';
                breakEndTimes[userId] = timeMins;
              } else if (entry.type === 'silla_start') {
                // (2026-08-22) Con "requiere aprobación" encendido el descanso se ficha como
                // silla_start/silla_end, y aquí no se reconocían: el poll de 5 s reconstruía el
                // estado, no encontraba el descanso y devolvía a la persona a 'active' pisando el
                // estado local. El dial salía del descanso solo, y como breakStartTimes nunca se
                // llenaba, la silla se podía volver a tomar sin límite.
                clockStates[userId] = 'short_break';
                breakStartTimes[userId] = timeMins;
              } else if (entry.type === 'silla_end') {
                clockStates[userId] = 'active';
                breakEndTimes[userId] = timeMins;
              } else if (entry.type === 'temp_exit_start') {
                // Antes no se manejaba este tipo aquí: al refrescar la página o en el poll de
                // 5s, un usuario en "Salida Temporal" perdía ese estado (auditoría dialer Jul 2026).
                clockStates[userId] = 'temp_exit';
              } else if (entry.type === 'temp_exit_end') {
                clockStates[userId] = 'active';
              } else if (entry.type === 'absent') {
                // Igual que temp_exit: faltaba por completo, se perdía al refrescar.
                clockStates[userId] = 'absent';
              } else if (entry.type === 'check_out') {
                // BUG FIX (auditoría dialer Jul 2026): esto decía 'inactive', el mismo bug que ya
                // se había corregido en useClockEngine.tsx::syncToDB() (3 lugares, ver comentarios
                // "BUG FIX" ahí) pero que sobrevivía aquí. Como fetchState() se ejecuta cada 5s Y
                // justo después de cada fichaje (evento 'db_sync_updated'), el estado correcto
                // 'finished' que syncToDB() acababa de fijar se pisaba solo segundos después,
                // reabriendo el dial a "Registrar Entrada" tras cada salida real.
                clockStates[userId] = 'finished';
                checkOutTimes[userId] = timeMins;
                delete pendingBreakRequests[userId];
              } else if (entry.type === 'contingency') {
                clockStates[userId] = 'contingency';
              } else if (entry.type === 'meal_reservation') {
                reservas[Number(userId)] = { mins: timeMins };
              } else if (entry.type === 'meal_cancel') {
                delete reservas[Number(userId)];
              } else if (entry.type === 'meal_swap') {
                // El intercambio se guarda como DOS filas (una por persona, "Swapped meal slots
                // with user N"); se aplica una sola vez por pareja consecutiva.
                const m = /user (\d+)/.exec(String(entry.details || ''));
                const otro = m ? Number(m[1]) : NaN;
                const par = [Number(userId), otro].sort((a, b) => a - b).join('-');
                if (!Number.isNaN(otro) && par !== ultimoSwap) {
                  const a = reservas[Number(userId)];
                  const b = reservas[otro];
                  if (a) reservas[otro] = a; else delete reservas[otro];
                  if (b) reservas[Number(userId)] = b; else delete reservas[Number(userId)];
                }
                ultimoSwap = par;
              }
              if (entry.type !== 'meal_swap') ultimoSwap = null;
            });

            const stepMins = Number(get().systemSettings?.mealSettings?.stepMins) || 15;
            const etiquetaSlot = (mins: number) => {
              const h = Math.floor(mins / 60);
              const mm = mins % 60;
              const ampm = h >= 12 ? 'PM' : 'AM';
              return `${h > 12 ? h - 12 : h}:${mm.toString().padStart(2, '0')} ${ampm}`;
            };
            const reservedMeals: Record<string, { userId: number; role: string }[]> = {};
            const hasReservedMeal: Record<number, boolean> = {};
            const userReservedMealSlots: Record<number, string[]> = {};
            Object.entries(reservas).forEach(([uidStr, r]) => {
              const uid = Number(uidStr);
              const u = (data.users || []).find((x: any) => Number(x.id) === uid);
              const bloques = Math.max(1, Math.ceil((Number(u?.mealMinutes) || 60) / stepMins));
              const slots: string[] = [];
              for (let j = 0; j < bloques; j++) slots.push(etiquetaSlot(r.mins + j * stepMins));
              userReservedMealSlots[uid] = slots;
              hasReservedMeal[uid] = true;
              slots.forEach((slot) => {
                if (!reservedMeals[slot]) reservedMeals[slot] = [];
                reservedMeals[slot].push({ userId: uid, role: u?.role || 'Colaborador' });
              });
            });

            set({
              globalClockStates: clockStates,
              globalCheckInTimes: checkInTimes,
              globalArrivalTimes: arrivalTimes,
              globalBreakStartTimes: breakStartTimes,
              globalBreakEndTimes: breakEndTimes,
              globalMealStartTimes: mealStartTimes,
              globalMealEndTimes: mealEndTimes,
              globalCheckOutTimes: checkOutTimes,
              globalPendingBreakRequests: pendingBreakRequests,
              reservedMeals,
              hasReservedMeal,
              userReservedMealSlots,
            });
          }
        }
 
        // Initialize TaskStore with data from backend
        if (data.tasks) {
           const camelCaseTasks = data.tasks.map((t: any) => ({
               id: t.id,
               title: t.title,
               estimatedMins: t.estimated_mins,
               points: t.points ?? 10,
               priority: t.priority,
               category: t.category,
               targetType: t.target_type,
               targetId: t.target_id,
               assistantType: t.assistant_type,
               assistantPrompt: t.assistant_prompt,
               isAutoCapture: t.is_auto_capture ? true : false,
               validationMode: t.validation_mode,
               canBeDoneSitting: t.can_be_done_sitting ? true : false,
               scheduledTime: t.scheduled_time,
               academyLessonId: t.academy_lesson_id ?? null,
               academyLessonVideoUrl: t.academy_lesson_video_url ?? null,
               historicalMins: [],
               description: t.description,
               procedureSteps: typeof t.procedure_steps === 'string' ? JSON.parse(t.procedure_steps) : (t.procedure_steps || []),
               validationCriteria: typeof t.validation_criteria === 'string' ? JSON.parse(t.validation_criteria) : (t.validation_criteria || []),
               frequency: t.frequency,
               evidenceType: t.evidence_type,
               is_validated: t.is_validated,
               // §35: modo de validación "Comparación (IA)" — solo aplica a assistantType 'evidencia_foto'.
               aiComparisonEnabled: t.ai_comparison_enabled ? true : false,
               aiReferenceImages: typeof t.ai_reference_images === 'string' ? JSON.parse(t.ai_reference_images) : (t.ai_reference_images || []),
               aiToleranceDescription: t.ai_tolerance_description ?? null
           }));
           useTaskStore.getState().setTasks(camelCaseTasks);
        }
        if (data.routines) {
           const camelCaseRoutines = data.routines.map((r: any) => ({
               id: r.id,
               title: r.title,
               targetRoleId: r.target_role_id,
               assignMode: r.assign_mode,
               trigger: r.trigger,
               triggerTime: r.trigger_time,
               taskIds: r.task_ids ? JSON.parse(r.task_ids) : []
           }));
           useTaskStore.getState().setRoutines(camelCaseRoutines);
        }
        if (data.assignments) {
           const camelCaseAssignments = data.assignments.map((a: any) => ({
               id: a.id,
               taskId: a.task_id,
               userId: a.user_id,
               status: a.status,
               startedAtMins: a.started_at_mins,
               expectedEndTimeMins: a.expected_end_time_mins,
               completedAtMins: a.completed_at_mins,
               assignedFromRoutineId: a.assigned_from_routine_id,
               assistantData: a.assistant_data ? (typeof a.assistant_data === 'string' ? JSON.parse(a.assistant_data) : a.assistant_data) : null,
               accumulatedMins: a.accumulated_mins || 0,
               reservedAtMins: a.reserved_at_mins,
               // BUG FIX (auditoría Reloj+Tareas, 2026-07-22): el backend ya puebla `date` y
               // `points_awarded` desde §14.1, pero esta hidratación nunca los leía — TaskRunner.tsx
               // filtra "Historial de Hoy" y "puntos de hoy" con a.date, y como siempre llegaba
               // undefined, mostraba el historial completo de TODOS los días como si fuera el de hoy.
               date: a.date,
               pointsAwarded: a.points_awarded,
               origin: a.origin || 'planned',
               aiValidationResult: typeof a.ai_validation_result === 'string' ? JSON.parse(a.ai_validation_result) : (a.ai_validation_result || null)
           }));
           useTaskStore.getState().setAssignments(camelCaseAssignments);
        }

         // Mensajes privados dirigidos a mí. El backend ya filtra por destinatario, pero aquí se
         // vuelve a comprobar antes de pintarlos: un mensaje de otra persona en la pantalla de
         // alguien es de las cosas que no se pueden permitir ni por descuido.
         if (Array.isArray(data.internal_messages)) {
           const yo = get().currentUser?.id;
           set({
             misMensajesPrivados: yo
               ? data.internal_messages.filter((m: any) => Number(m.receiver_id) === Number(yo))
               : [],
           });
         }

         // Cargar QA Matrix desde audit_logs del Backend (solo si no estamos en Sandbox)
         if (data.audit_logs && !get().isSandboxMode) {
            // H10 (mismo patrón que los fichajes): el día se corta con la zona del TENANT,
            // no con la del dispositivo, o la bitácora del día sale vacía en cuanto ambas
            // difieren.
            const todayStr = hoyEnZona(data.system_settings?.timezone);
            // Si hay sesión de simulación activa, incluir todos los logs traídos para esa sesión simulada
            const targetLogs = activeSimSession
              ? data.audit_logs
              : data.audit_logs.filter((log: any) => fechaDeFichaje(log.date) === todayStr);

            const mappedLogs = targetLogs.map((log: any) => ({
                id: log.id,
                timeStr: log.timestamp_str ? (log.timestamp_str.includes(' ') ? log.timestamp_str.split(' ')[1] : log.timestamp_str) : log.date,
                simTime: 0,
                title: 'Registro de Auditoría',
                description: log.reason,
                type: log.punishment_amount > 0 ? 'warning' : (log.type === 'check_in' || log.type === 'check_out' ? 'success' : 'info'),
                actorId: log.user_id,
                isLocal: false
            })).reverse(); // Mostrar más recientes primero

            const existingIds = new Set(mappedLogs.map((l: any) => l.id));
            const localEvents = get().matrixTimeline.filter(e => (e.type === 'system' || e.isLocal) && !existingIds.has(e.id));
            set({ matrixTimeline: [...localEvents, ...mappedLogs] });
         }

      }
    } catch (e) {
      console.error("Error fetching state:", e);
      // Error manejado silenciosamente o mostrar alert
    } finally {
      set({ isLoadingDB: false });
    }
  },

  updateSetting: async (key: string, value: any) => {
    // 1. Optimistic update en Frontend
    set((state) => ({
      systemSettings: {
        ...state.systemSettings,
        [key]: value
      }
    }));
    
    // 2. Disparar API para guardar en base de datos
    try {
      await axiosInstance.post('/sync/settings', { key, value });
    } catch(e) {
      console.error("Error guardando configuración:", e);
    }
  },

  fetchPunctualityStatus: async () => {
    try {
      const res = await axiosInstance.get('/me/punctuality-status');
      set({
        punctualityStatus: {
          blocked: !!res.data?.blocked,
          lates_count: Number(res.data?.lates_count) || 0,
          required_course_id: res.data?.required_course_id ?? null,
          course_completed: !!res.data?.course_completed,
        },
      });
    } catch (e) {
      console.warn('No se pudo obtener el estatus de puntualidad (GET /me/punctuality-status):', e);
    }
  },

  isModuleUnlocked: (moduleId: string) => {
    const { currentTier, currentUser, systemSettings, isSandboxMode, allowedModules } = get();
    if (moduleId === 'dashboard' || moduleId === 'settings' || moduleId === 'organizacion') return true;

    // 1. Si existe una lista de módulos explícitamente permitidos/desactivados para este tenant, esta manda con prioridad
    const tenantAllowedModules = systemSettings?.tenant_allowed_modules || systemSettings?.allowed_modules || allowedModules;
    if (Array.isArray(tenantAllowedModules) && tenantAllowedModules.length > 0) {
      return tenantAllowedModules.includes(moduleId);
    }

    if (currentUser?.system_role === 'platform_admin' || currentUser?.role === 'platform_admin') return true;
    if (currentTier === 'enterprise') return true;
    
    // Check if trial is active
    const tenant = currentUser?.tenant;
    let trialActive = false;
    if (tenant) {
      if (tenant.subscription_status === 'trial' || !tenant.subscription_status) {
        if (tenant.trial_ends_at) {
          const endsAt = new Date(tenant.trial_ends_at);
          trialActive = endsAt.getTime() > Date.now();
        }
      }
    }
    if (trialActive) return true;
    
    const activeMods = systemSettings?.active_modules || [];

    if (currentTier === 'freemium') {
      const allowed = systemSettings?.freemium_allowed_modules || ['reloj', 'rrhh', 'operativo', 'lft', 'organizacion'];
      return allowed.includes(moduleId) || activeMods.includes(moduleId);
    }
    
    if (currentTier === 'pro') {
      const basePro = ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'academia', 'lft', 'organizacion'];
      return basePro.includes(moduleId) || activeMods.includes(moduleId);
    }
    
    return false;
  },
 
  isFeatureUnlocked: (featureId: string) => {
    const { currentTier, currentUser, systemSettings, isSandboxMode, allowedFeatures } = get();

    // 1. Si existe una lista de funciones explícitamente permitidas/desactivadas para este tenant, esta manda con prioridad
    const tenantAllowedFeatures = systemSettings?.tenant_allowed_features || systemSettings?.allowed_features || allowedFeatures;
    if (Array.isArray(tenantAllowedFeatures) && tenantAllowedFeatures.length > 0) {
      return tenantAllowedFeatures.includes(featureId);
    }

    if (currentUser?.system_role === 'platform_admin' || currentUser?.role === 'platform_admin') return true;
    if (currentTier === 'pro' || currentTier === 'enterprise') return true;
    
    const tenant = currentUser?.tenant;
    let trialActive = false;
    if (tenant) {
      if (tenant.subscription_status === 'trial' || !tenant.subscription_status) {
        if (tenant.trial_ends_at) {
          const endsAt = new Date(tenant.trial_ends_at);
          trialActive = endsAt.getTime() > Date.now();
        }
      }
    }
    if (trialActive) return true;
    
    const allowed = systemSettings?.freemium_allowed_features || [];
    return allowed.includes(featureId);
  }
}));
