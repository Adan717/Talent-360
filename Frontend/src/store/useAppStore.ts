import { create } from 'zustand';
import axiosInstance from '../lib/axios';
import { useTaskStore } from './useTaskStore';
import type { User, Tenant } from '../types';

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
  setGlobalSimTime: (time: number) => void;
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
  fetchState: () => Promise<void>;
  updateSetting: (key: string, value: any) => Promise<void>;
  
  // SaaS Actions
  addSaaSTenant: (tenant: Tenant) => void;
  updateSaaSPricing: (moduleId: string, price: number) => void;
  resolveSaaSAlert: (alertId: string) => Promise<void>;
  
  // HR Workflow Actions
  hireEmployee: (candidate: any) => void;
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
  isSandboxMode: false,
  activeEncargadoId: 1,
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
  simulatedTierOverride: ((typeof localStorage !== 'undefined' && localStorage.getItem('qa_simulated_tier_override')) as any) || 'pro',
  
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
    globalStoreShiftStart: '09:00',
    globalStoreShiftEnd: '18:00',
    uiState: { menuCollapsed: false, currentTheme: 'light' }
  },

  setIsLoadingDB: (loading) => set({ isLoadingDB: loading }),
  setGlobalUsers: (users) => set({ globalUsers: users }),
  setCurrentUser: (user) => set({ currentUser: user }),
  setCurrentTier: (tier) => set({ currentTier: tier }),
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
  setGlobalClockState: (userId, state) => set((s) => ({ globalClockStates: { ...s.globalClockStates, [userId]: state } })),
  setGlobalCheckInTime: (userId, time) => set((s) => ({ globalCheckInTimes: { ...s.globalCheckInTimes, [userId]: time } })),
  setGlobalArrivalTime: (userId, time) => set((s) => ({ globalArrivalTimes: { ...s.globalArrivalTimes, [userId]: time } })),
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

  hireEmployee: (candidate) => set((s) => {
    const newId = s.globalUsers.length > 0 ? Math.max(...s.globalUsers.map(u => u.id)) + 1 : 1;
    let subdomain = s.currentUser?.tenant?.subdomain;

    if (!subdomain && s.currentUser?.email) {
      const emailParts = s.currentUser.email.split('@');
      if (emailParts.length === 2) {
        const domainParts = emailParts[1].split('.');
        if (domainParts.length >= 2) {
          const domainName = domainParts[0];
          const commonProviders = ['gmail', 'yahoo', 'outlook', 'hotmail', 'live', 'icloud', 'talent360'];
          if (!commonProviders.includes(domainName.toLowerCase())) {
            subdomain = domainName;
          }
        }
      }
    }

    if (!subdomain && s.systemSettings?.company_name) {
      const cleanCompName = s.systemSettings.company_name
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/[^a-z0-9]/g, '');
      if (cleanCompName.length > 0) {
        subdomain = cleanCompName;
      }
    }

    subdomain = subdomain || 'decorarte360';

    const newUser = {
      id: newId,
      name: candidate.name,
      role: 'Recién Contratado',
      system_role: 'Recién Contratado',
      email: `${candidate.name.toLowerCase().replace(/\s/g, '')}@${subdomain}.com`,
      tenant_id: 1,
      job_role_id: candidate.applied_vacancy_id || 5, // Fallback
      avatar: '',
      has_completed_induction: false,
      mealMinutes: 60
    };
    return { globalUsers: [...s.globalUsers, newUser] };
  }),
  completeInduction: (userId) => set((s) => ({
    globalUsers: s.globalUsers.map(u => u.id === userId ? { ...u, has_completed_induction: true } : u),
    // También si el currentUser es el mismo, actualizarlo en la sesión
    currentUser: s.currentUser && s.currentUser.id === userId ? { ...s.currentUser, has_completed_induction: true } : s.currentUser
  })),

  setHasAlertedStoreDelay: (val) => set({ hasAlertedStoreDelay: val }),
  resetGlobalSimulation: async () => {
    if (!get().isSandboxMode) {
      try {
        await axiosInstance.post('/sync/reset_day', { date: new Date().toLocaleDateString('sv-SE') });
      } catch (e) {
        console.error("Error resetting day in database:", e);
      }
    }
    
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
        actorId
      }, ...state.matrixTimeline]
    };
  }),


  fetchState: async () => {
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

      const res = await axiosInstance.get('/sync/state', { timeout: 5000 });

      if (res.status === 200) {
        const data = res.data;
        const state = get();
        
        if (data.users && data.users.length > 0) {
            const activeUsers = data.users.filter((u: any) => u.is_active_employee !== false && u.is_active_employee !== 0 && u.is_active_employee !== '0');
            const mappedUsers = activeUsers.map((u: any) => {
              // Buscar el nombre del puesto usando job_role_id
              let roleName = 'Empleado';
              let esAperturador = false;
              let jerarquiaLlaves = 0;
              const rolesList = data.job_roles || data.jobRoles;
              if (rolesList && rolesList.length > 0) {
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
                role: roleName,
                system_role: u.role,
                avatar: u.avatar || 'https://api.dicebear.com/7.x/avataaars/svg?seed=' + u.name,
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
            
            if (data.store_logs && data.store_logs.length > 0) {
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
           set({ roleClockPolicies: data.role_clock_policies });
        }

        if (data.active_encargado_id) {
           set({ activeEncargadoId: data.active_encargado_id });
        }

        if (data.job_roles) {
           set({ globalRoles: data.job_roles });
        }
        if (data.permissions) {
           set({ dbPermissions: data.permissions });
        }
        if (data.role_permissions) {
           set({ dbRolePermissions: data.role_permissions });
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
            const todayStr = new Date().toLocaleDateString('sv-SE');
            const todayEntries = data.time_entries.filter((entry: any) => entry.date === todayStr);

            const clockStates: Record<number, string> = {};
            const checkInTimes: Record<number, number> = {};
            const arrivalTimes: Record<number, number> = {};
            const breakStartTimes: Record<number, number> = {};
            const breakEndTimes: Record<number, number> = {};
            const mealStartTimes: Record<number, number> = {};
            const mealEndTimes: Record<number, number> = {};
            const checkOutTimes: Record<number, number> = {};
            const pendingBreakRequests: Record<number, any> = {};

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
              } else if (entry.type === 'check_out') {
                clockStates[userId] = 'inactive';
                checkOutTimes[userId] = timeMins;
                delete pendingBreakRequests[userId];
              } else if (entry.type === 'contingency') {
                clockStates[userId] = 'contingency';
              }
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
               historicalMins: []
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
               reservedAtMins: a.reserved_at_mins
           }));
           useTaskStore.getState().setAssignments(camelCaseAssignments);
        }

        // Cargar QA Matrix desde audit_logs del Backend (solo si no estamos en Sandbox)
         if (data.audit_logs && !get().isSandboxMode) {
            const todayStr = new Date().toLocaleDateString('sv-SE');
            // Filtrar solo los registros de la jornada de hoy para la bitácora del simulador
            const todayLogs = data.audit_logs.filter((log: any) => log.date === todayStr);
            
            const mappedLogs = todayLogs.map((log: any) => ({
                id: log.id,
                timeStr: log.timestamp_str ? log.timestamp_str.split(' ')[1] : log.date,
                simTime: 0,
                title: 'Registro de Auditoría',
                description: log.reason,
                type: log.punishment_amount > 0 ? 'warning' : (log.type === 'check_in' || log.type === 'check_out' ? 'success' : 'info'),
                actorId: log.user_id
            })).reverse(); // Mostrar más recientes primero
            
            // Preservar eventos del sistema local (como "Sistema Reiniciado")
            const localSystemEvents = get().matrixTimeline.filter(e => e.type === 'system');
            set({ matrixTimeline: [...localSystemEvents, ...mappedLogs] });
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
 
  isModuleUnlocked: (moduleId: string) => {
    const { currentTier, currentUser, systemSettings, isSandboxMode, allowedModules } = get();
    if (moduleId === 'dashboard' || moduleId === 'settings' || moduleId === 'matrix') return true;
    if (currentUser?.system_role === 'platform_admin' || currentUser?.role === 'platform_admin') return true;
    if (currentTier === 'enterprise' || currentUser?.tenant_id === 1) return true;
    
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
    
    if (!isSandboxMode) {
      return allowedModules.includes(moduleId);
    }
    
    if (currentTier === 'freemium') {
      const allowed = systemSettings?.freemium_allowed_modules || ['reloj', 'rrhh', 'operativo'];
      return allowed.includes(moduleId);
    }
    
    if (currentTier === 'pro') {
      const activeMods = systemSettings?.active_modules || ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'academia', 'documentos', 'portal'];
      return activeMods.includes(moduleId);
    }
    
    return false;
  },
 
  isFeatureUnlocked: (featureId: string) => {
    const { currentTier, currentUser, systemSettings, isSandboxMode, allowedFeatures } = get();
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
    
    if (!isSandboxMode) {
      return allowedFeatures.includes(featureId);
    }
    
    const allowed = systemSettings?.freemium_allowed_features || [];
    return allowed.includes(featureId);
  }
}));
