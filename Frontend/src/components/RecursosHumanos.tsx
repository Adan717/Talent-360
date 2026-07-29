import React, { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useAppStore } from '../store/useAppStore';
import { Briefcase, Users, FileText, Shield, Clock, Plus, Pencil, X, Lock, Save, Scale, ClipboardList, User, Trash2, Search, RotateCcw, Network, MessageSquare, Zap, Sparkles, Phone, Coffee, UserPlus, DollarSign, Mic, ZoomIn, ZoomOut, UserMinus } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { isLocalhost, getQrOrigin } from '../lib/qrHelper';
import { useVoiceFormAssistant } from './ui/useVoiceFormAssistant';
import { VoiceAssistantOverlay } from './ui/VoiceAssistantOverlay';
import OrganigramaPuestos from './OrganigramaPuestos';
import { JobRoleIconBadge, JOB_ROLE_ICON_OPTIONS, JOB_ROLE_PROFESSIONS_MATRIX, renderJobRoleIcon, resolveJobRoleIconKey, getRoleSmartDescription } from '../lib/jobRoleIcons';

interface RecursosHumanosProps {
  readOnly?: boolean;
  initialTab?: string;
}

export default function RecursosHumanos({ readOnly = false, initialTab = 'directorio' }: RecursosHumanosProps) {
  const [searchParams, setSearchParams] = useSearchParams();
  const urlTab = searchParams.get('tab');
  const storedTab = localStorage.getItem('talent360_rrhh_active_tab');
  const [activeTab, setActiveTabState] = useState(urlTab || storedTab || initialTab);

  const setActiveTab = (tab: string) => {
    setActiveTabState(tab);
    try {
      localStorage.setItem('talent360_rrhh_active_tab', tab);
    } catch {}
    setSearchParams(prev => {
      const next = new URLSearchParams(prev);
      next.set('tab', tab);
      return next;
    }, { replace: true });
  };

  useEffect(() => {
    if (urlTab && urlTab !== activeTab) {
      setActiveTabState(urlTab);
      try {
        localStorage.setItem('talent360_rrhh_active_tab', urlTab);
      } catch {}
    } else if (!urlTab && activeTab) {
      setSearchParams(prev => {
        const next = new URLSearchParams(prev);
        next.set('tab', activeTab);
        return next;
      }, { replace: true });
    }
  }, [urlTab]);

  const getUserKeysIcon = (userId: number) => {
    try {
      const isSandbox = useAppStore.getState().isSandboxMode;
      const savedAss = localStorage.getItem('store_opening_assignments');
      const assignments = savedAss ? JSON.parse(savedAss) : (
        isSandbox ? [
          { id: 1, employee_id: 1, priority_order: 1, can_open_store: true, has_keys: true, is_active: true },
          { id: 2, employee_id: 2, priority_order: 2, can_open_store: true, has_keys: true, is_active: true },
          { id: 3, employee_id: 3, priority_order: 3, can_open_store: true, has_keys: true, is_active: true }
        ] : [
          { id: 11, employee_id: 11, priority_order: 1, can_open_store: true, has_keys: true, is_active: true },
          { id: 12, employee_id: 12, priority_order: 2, can_open_store: true, has_keys: true, is_active: true },
          { id: 13, employee_id: 13, priority_order: 3, can_open_store: true, has_keys: true, is_active: true }
        ]
      );
      // §29 (docs/BACKEND_INTERFACES.md): preferir resolved_user_id (users.id, ya resuelto por
      // backend) sobre el employee_id crudo (employees.id) que trae /store-opening/assignments.
      const match = assignments.find((a: any) => Number(a.resolved_user_id ?? a.employee_id) === Number(userId) && a.is_active && a.can_open_store);
      if (match) {
        return match.priority_order === 1 ? ' 🔑' : ' 🔑🔑';
      }
    } catch {}
    return '';
  };

  const getJobRoleKeysIcon = (roleId: number) => {
    try {
      const isSandbox = useAppStore.getState().isSandboxMode;
      const savedAss = localStorage.getItem('store_opening_assignments');
      const assignments = savedAss ? JSON.parse(savedAss) : (
        isSandbox ? [
          { id: 1, employee_id: 1, priority_order: 1, can_open_store: true, has_keys: true, is_active: true },
          { id: 2, employee_id: 2, priority_order: 2, can_open_store: true, has_keys: true, is_active: true },
          { id: 3, employee_id: 3, priority_order: 3, can_open_store: true, has_keys: true, is_active: true }
        ] : [
          { id: 11, employee_id: 11, priority_order: 1, can_open_store: true, has_keys: true, is_active: true },
          { id: 12, employee_id: 12, priority_order: 2, can_open_store: true, has_keys: true, is_active: true },
          { id: 13, employee_id: 13, priority_order: 3, can_open_store: true, has_keys: true, is_active: true }
        ]
      );
      // §29: preferir resolved_user_id (users.id) sobre employee_id crudo (employees.id).
      const authorizedUserIds = assignments
        .filter((a: any) => a.is_active && a.can_open_store)
        .map((a: any) => Number(a.resolved_user_id ?? a.employee_id));

      const roleUsers = users.filter((u: any) => u.job_role_id === roleId && authorizedUserIds.includes(u.employee_id ? Number(u.employee_id) : Number(u.id)));
      if (roleUsers.length > 0) {
        const roleAssignments = assignments.filter((a: any) => a.is_active && a.can_open_store && roleUsers.some((u: any) => Number(u.employee_id ? u.employee_id : u.id) === Number(a.resolved_user_id ?? a.employee_id)));
        const minPriority = Math.min(...roleAssignments.map((a: any) => a.priority_order));
        return minPriority === 1 ? ' 🔑' : ' 🔑🔑';
      }
    } catch {}
    return '';
  };
  const [showFabMenu, setShowFabMenu] = useState(false);
  const searchInputRef = React.useRef<HTMLInputElement>(null);
  const [qrIpOverride, setQrIpOverride] = useState(localStorage.getItem('qr_origin_override') || '');
  const handleQrIpChange = (val: string) => {
    setQrIpOverride(val);
    localStorage.setItem('qr_origin_override', val);
  };

  const getRoleColor = (roleName: string) => {
    const name = (roleName || '').toLowerCase();
    if (name.includes('admin') || name.includes('gerente') || name.includes('director')) return { border: 'border-l-indigo-500', text: 'text-indigo-600', bg: 'bg-indigo-50' };
    if (name.includes('ventas') || name.includes('comercial') || name.includes('marketing')) return { border: 'border-l-emerald-500', text: 'text-emerald-600', bg: 'bg-emerald-50' };
    if (name.includes('soporte') || name.includes('sistemas') || name.includes('dev') || name.includes('programador')) return { border: 'border-l-sky-500', text: 'text-sky-600', bg: 'bg-sky-50' };
    if (name.includes('operaciones') || name.includes('taller') || name.includes('ensamble') || name.includes('operativo')) return { border: 'border-l-amber-500', text: 'text-amber-600', bg: 'bg-amber-50' };
    if (name.includes('diseño') || name.includes('creat') || name.includes('media')) return { border: 'border-l-purple-500', text: 'text-purple-600', bg: 'bg-purple-50' };
    
    // fallback based on character hashing
    const hash = name.split('').reduce((acc, char) => acc + char.charCodeAt(0), 0);
    const colors = [
      { border: 'border-l-blue-500', text: 'text-blue-600', bg: 'bg-blue-50' },
      { border: 'border-l-rose-500', text: 'text-rose-600', bg: 'bg-rose-50' },
      { border: 'border-l-teal-500', text: 'text-teal-600', bg: 'bg-teal-50' },
      { border: 'border-l-violet-500', text: 'text-violet-600', bg: 'bg-violet-50' }
    ];
    return colors[hash % colors.length];
  };

  // Helpers to format and clean phone numbers (prefixed with Mexican country code 52)
  const formatPhoneVisual = (val: string) => {
    if (!val) return '';
    let clean = val.replace(/\D/g, '');
    if (clean.startsWith('52')) {
      clean = clean.slice(2);
    }
    clean = clean.slice(0, 10);
    if (clean.length <= 3) return clean;
    if (clean.length <= 6) return `${clean.slice(0, 3)} ${clean.slice(3)}`;
    return `${clean.slice(0, 3)} ${clean.slice(3, 6)} ${clean.slice(6)}`;
  };

  const getCleanDbPhone = (val: string) => {
    const clean = val.replace(/\D/g, '');
    if (!clean) return '';
    if (clean.length === 10) return `52${clean}`;
    if (clean.startsWith('52') && clean.length > 10) return clean;
    return clean;
  };

  const formatTimeVisual = (timeStr: string | null | undefined): string => {
    if (!timeStr) return '';
    const parts = timeStr.split(':');
    if (parts.length < 2) return timeStr;
    let hours = parseInt(parts[0], 10);
    let minutes = parseInt(parts[1], 10);
    if (isNaN(hours)) return timeStr;
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    const minutesStr = minutes > 0 ? `:${minutes.toString().padStart(2, '0')}` : '';
    return `${hours}${minutesStr} ${ampm}`;
  };

  const formatEmployeeDisplayName = (fullName: string): string => {
    if (!fullName) return '';
    const parts = fullName.trim().split(/\s+/);
    if (parts.length <= 2) return fullName;
    
    const commonSecondNames = new Set([
      'carlos', 'maría', 'maria', 'josé', 'jose', 'luis', 'antonio', 'manuel', 
      'francisco', 'eduardo', 'alejandro', 'javier', 'andrés', 'andres', 'miguel', 
      'ángel', 'angel', 'alberto', 'enrique', 'fernando', 'guadalupe', 'jesús', 
      'jesus', 'ramón', 'ramon', 'rafael', 'david', 'daniel', 'jorge', 'arturo', 
      'roberto', 'patricia', 'leticia', 'elena', 'isabel', 'gabriela', 'alejandra', 
      'sofía', 'sofia', 'carmen', 'juana', 'ana', 'rosa', 'beatriz'
    ]);

    const first = parts[0];
    
    if (parts.length === 3) {
      const second = parts[1].toLowerCase();
      if (commonSecondNames.has(second)) {
        return `${first} ${parts[2]}`;
      } else {
        return `${first} ${parts[1]}`;
      }
    }
    
    if (parts.length >= 4) {
      return `${first} ${parts[2]}`;
    }

    return fullName;
  };
  const [users, setUsers] = useState<any[]>([]);
  const [jobRoles, setJobRoles] = useState<any[]>([]);
  const uniqueAreas = Array.from(new Set(jobRoles.flatMap(r => {
    let area = r.area || '';
    area = area.replace(/Administraci\?\?n/g, 'Administración').trim();
    return area.split(',').map((s: string) => s.trim()).filter(Boolean);
  })));
  const [rbacConfig, setRbacConfig] = useState<any>({});
  const [globalSettings, setGlobalSettings] = useState<any>({
    mealSettings: { startHour: 13, endHour: 17, stepMins: 15, maxChairs: 3 },
    timeBankConfigs: { mealMinutes: 60, mealMinMandatory: 30 }
  });
  const [permissionsList, setPermissionsList] = useState<any[]>([]);
  const [rolePermissionsConfig, setRolePermissionsConfig] = useState<any>({});
  const [roleClockPolicies, setRoleClockPolicies] = useState<any[]>([]);
  const [selectedRolePolicy, setSelectedRolePolicy] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  // Importar desde plantillas
  const [showTemplateModal, setShowTemplateModal] = useState(false);
  const [templates, setTemplates] = useState<any[]>([]);
  const [loadingTemplates, setLoadingTemplates] = useState(false);
  const [selectedTemplate, setSelectedTemplate] = useState<any>(null);
  const [directorioSubTab, setDirectorioSubTab] = useState<'activos' | 'inactivos'>('activos');
  const [searchQuery, setSearchQuery] = useState('');
  const [showMobileSearch, setShowMobileSearch] = useState(false);
  const [selectedRoleFilter, setSelectedRoleFilter] = useState('');
  const [templateIndustryFilter, setTemplateIndustryFilter] = useState('');
  const [selectedIndustryFilter, setSelectedIndustryFilter] = useState('decorarte');
  const [importingTemplate, setImportingTemplate] = useState(false);

  const [orgViewMode, setOrgViewMode] = useState<'tree' | 'levels'>('tree');
  const [zoomLevel, setZoomLevel] = useState(1);
  const [hoveredRoleId, setHoveredRoleId] = useState<number | null>(null);
  const [draggedOverRoleId, setDraggedOverRoleId] = useState<number | null>(null);

  // Estados del Drawer de Puesto interactivo
  const [selectedRoleForDrawer, setSelectedRoleForDrawer] = useState<any>(null);
  const [isRoleDrawerOpen, setIsRoleDrawerOpen] = useState(false);
  const [drawerDescription, setDrawerDescription] = useState('');
  const [drawerResponsibilities, setDrawerResponsibilities] = useState('');
  const [drawerManualName, setDrawerManualName] = useState('');
  const [drawerManualUrl, setDrawerManualUrl] = useState('');
  const [drawerParentId, setDrawerParentId] = useState<number | ''>('');
  const [isSavingDrawer, setIsSavingDrawer] = useState(false);

  const wouldCreateCycleLocal = (draggedId: number, targetId: number): boolean => {
    if (draggedId === targetId) return true;
    let currentParentId = targetId;
    const maxDepth = 100;
    let depth = 0;
    while (currentParentId && depth < maxDepth) {
      const parentRole = jobRoles.find((r: any) => r.id === currentParentId);
      if (!parentRole) break;
      const parentId = parentRole.org_parent_role_id || parentRole.reports_to_role_id || parentRole.parent_role_id;
      if (parentId === draggedId) {
        return true;
      }
      currentParentId = parentId;
      depth++;
    }
    return false;
  };

  const handleRoleDrop = async (draggedId: number, targetParentId: number | null) => {
    if (draggedId === targetParentId) return;
    
    if (targetParentId !== null && wouldCreateCycleLocal(draggedId, targetParentId)) {
      alert("Operación inválida: No puedes reportar un puesto a sí mismo o a uno de sus subordinados.");
      return;
    }

    try {
      const appState = useAppStore.getState();
      const draggedRole = jobRoles.find((r: any) => r.id === draggedId);
      if (!draggedRole) return;

      const updatedRole = {
        ...draggedRole,
        org_parent_role_id: targetParentId
      };

      if (appState.isSandboxMode) {
        setJobRoles(jobRoles.map(r => r.id === draggedId ? updatedRole : r));
        return;
      }

      const res = await axiosInstance.put(`/job-roles/${draggedId}`, updatedRole);
      if (res.status === 200) {
        await fetchData();
        window.dispatchEvent(new Event('db_sync_updated'));
      } else {
        throw new Error("Failed to update role");
      }
    } catch (err: any) {
      console.error("Error updating role hierarchy:", err);
      alert(err.response?.data?.message || "Error al actualizar la jerarquía del puesto.");
    }
  };

  // NUEVO (organigrama interactivo, 2026-07-21): callback que le paso a <OrganigramaPuestos />
  // para que persista cualquier conexión que el usuario dibuje o borre en el chart (jerarquía
  // visual u operativa). Reutiliza exactamente el mismo patrón que ya usaba handleRoleDrop:
  // mergea el patch sobre el registro completo del puesto y hace PUT /job-roles/{id}. La
  // validación de ciclos ya se hizo del lado de OrganigramaPuestos antes de llamar esto.
  const handleUpdateRoleFromChart = async (roleId: number, patch: Record<string, any>) => {
    try {
      const appState = useAppStore.getState();
      const role = jobRoles.find((r: any) => r.id === roleId);
      if (!role) return;

      const updatedRole = { ...role, ...patch };

      if (appState.isSandboxMode) {
        setJobRoles(jobRoles.map((r: any) => (r.id === roleId ? updatedRole : r)));
        return;
      }

      const res = await axiosInstance.put(`/job-roles/${roleId}`, updatedRole);
      if (res.status === 200) {
        await fetchData();
        window.dispatchEvent(new Event('db_sync_updated'));
      } else {
        throw new Error('Failed to update role hierarchy from chart');
      }
    } catch (err: any) {
      console.error('Error updating role hierarchy from chart:', err);
      alert(err.response?.data?.message || 'Error al actualizar la relación en el organigrama.');
    }
  };

  const handleCollaboratorRoleDrop = async (employeeId: number, targetRoleId: number) => {
    try {
      const appState = useAppStore.getState();
      const employee = users.find((u: any) => u.id === employeeId);
      if (!employee) return;

      const updatedUser = {
        ...employee,
        job_role_id: targetRoleId
      };

      if (appState.isSandboxMode) {
        setUsers(users.map(u => u.id === employeeId ? updatedUser : u));
        appState.setGlobalUsers(appState.globalUsers.map(u => u.id === employeeId ? updatedUser : u));
        return;
      }

      // BUG FIX (2026-07-21): antes se hacía un PUT PARCIAL con solo { job_role_id }, y el backend
      // `/employees/{id}` (igual que en handleEditUser) espera/valida el registro completo del empleado,
      // así que ese PUT parcial se rechazaba y la reasignación no se persistía — al salir y volver a
      // entrar al organigrama, fetchData recargaba el puesto viejo. Ahora se envía el payload COMPLETO
      // saneado, exactamente como handleEditUser, con job_role_id ya cambiado.
      const payload: any = { ...updatedUser, job_role_id: parseInt(String(targetRoleId), 10) };
      Object.keys(payload).forEach((key) => {
        if (payload[key] === '') payload[key] = null;
      });
      if (payload.base_salary) payload.base_salary = parseFloat(payload.base_salary);
      if (payload.salary) payload.salary = parseFloat(payload.salary);
      if (payload.mealMinutes) payload.mealMinutes = parseInt(payload.mealMinutes, 10);

      const res = await axiosInstance.put(`/employees/${employee.employee_id || employeeId}`, payload);

      if (res.status === 200) {
        await fetchData();
        window.dispatchEvent(new Event('db_sync_updated'));
      } else {
        throw new Error("Failed to update employee role");
      }
    } catch (err: any) {
      console.error("Error updating employee role:", err);
      alert(err.response?.data?.message || "Error al reasignar el puesto del colaborador.");
    }
  };

  const fetchTemplates = async (industry = '') => {
    try {
      setLoadingTemplates(true);
      const url = industry ? `/job-role-templates?industry=${industry}` : '/job-role-templates';
      const res = await axiosInstance.get(url);
      setTemplates(res.data || []);
    } catch (e) {
      console.error("Error fetching templates", e);
    } finally {
      setLoadingTemplates(false);
    }
  };

  const handleImportTemplate = async () => {
    if (!selectedTemplate) return;
    try {
      setImportingTemplate(true);
      const appState = useAppStore.getState();
      if (appState.isSandboxMode) {
          const mockNewRole = {
              id: Date.now(),
              name: selectedTemplate.name,
              area: selectedTemplate.area,
              esAperturador: selectedTemplate.is_opener,
              tiempoTolerancia: selectedTemplate.default_tolerance_mins,
              is_active: true
          };
          setJobRoles([...jobRoles, mockNewRole]);
          alert("Puesto importado exitosamente (Simulado en modo Sandbox).");
          setShowTemplateModal(false);
          setSelectedTemplate(null);
          return;
      }
      await axiosInstance.post(`/job-role-templates/${selectedTemplate.id}/import`);
      alert("Puesto importado exitosamente.");
      setShowTemplateModal(false);
      setSelectedTemplate(null);
      await fetchData();
      window.dispatchEvent(new Event('db_sync_updated'));
    } catch (e) {
      console.error("Error importing template", e);
      alert("Ocurrió un error al importar la plantilla de puesto.");
    } finally {
      setImportingTemplate(false);
    }
  };

  useEffect(() => {
    if (showTemplateModal) {
      fetchTemplates(templateIndustryFilter);
    }
  }, [templateIndustryFilter, showTemplateModal]);

  const [showUpgradeModal, setShowUpgradeModal] = useState(false);
  const [upgradeModalMessage, setUpgradeModalMessage] = useState('');

  const handleUpgradePlan = async () => {
    try {
      const response = await axiosInstance.post('/subscriptions/create-preference', {
        plan: 'pro'
      });
      if (response.data.init_point) {
        window.location.href = response.data.init_point;
      } else {
        alert('Error al generar la preferencia de pago.');
      }
    } catch (e) {
      console.error(e);
      alert('Error al conectar con la pasarela de pagos.');
    }
  };

  // Formulario nuevo colaborador
  const [showForm, setShowForm] = useState(false);
  const [newUserName, setNewUserName] = useState('');
  const [newUserSalary, setNewUserSalary] = useState('');
  const [editingUser, setEditingUser] = useState<any>(null);
  const [editingUserTab, setEditingUserTab] = useState<'personal'|'laboral'|'accesos'|'expediente'>('personal');
  const [editingJobRole, setEditingJobRole] = useState<any>(null);
  const [editingJobRoleTab, setEditingJobRoleTab] = useState<'perfil'|'reglas'>('perfil');
  const [newUserRole, setNewUserRole] = useState('');
  const [contractType, setContractType] = useState('Fijo'); // Fijo o Destajo
  const [vacancies, setVacancies] = useState<any[]>([]);
  const [selectedRoleForUsersModal, setSelectedRoleForUsersModal] = useState<any | null>(null);
  const [selectedRoleForVacanciesModal, setSelectedRoleForVacanciesModal] = useState<any | null>(null);

  // Voice Assistant Hook Setup
  const { currentTier } = useAppStore();
  const isPremium = currentTier === 'pro' || currentTier === 'enterprise';

  const voiceFields = [
    {
      id: 'name',
      label: 'Nombre Completo',
      type: 'text' as const,
      value: newUserName,
      setValue: setNewUserName,
    },
    {
      id: 'role',
      label: 'Puesto',
      type: 'select' as const,
      value: newUserRole,
      setValue: setNewUserRole,
      options: jobRoles
        .filter((role: any) => role.is_active !== false)
        .map((role: any) => ({ value: role.id, label: role.name })),
    },
    {
      id: 'contract',
      label: 'Tipo de Contrato',
      type: 'select' as const,
      value: contractType,
      setValue: setContractType,
      options: [
        { value: 'Fijo', label: 'Sueldo Fijo / Base' },
        { value: 'Destajo', label: 'A Destajo (Comisiones)' },
      ],
    },
    {
      id: 'salary',
      label: 'Salario Base',
      type: 'number' as const,
      value: newUserSalary,
      setValue: setNewUserSalary,
    },
  ];

  const voiceAssistant = useVoiceFormAssistant({
    fields: voiceFields,
    onSave: () => {
      const dummyEvent = { preventDefault: () => {} } as React.FormEvent;
      handleAddUser(dummyEvent);
    },
    onCancel: () => setShowForm(false),
    isPremium,
    onUpgradeRequired: () => {
      setUpgradeModalMessage('El Asistente de Voz para llenado de formularios es una función exclusiva del Plan Profesional (PRO). ¡Mejora tu plan hoy para desbloquear el llenado automático y comandos de voz!');
      setShowUpgradeModal(true);
    },
  });

  useEffect(() => {
    fetchData();
  }, []);

  const saveRolePolicy = async () => {
      if(!selectedRolePolicy) return;
      try {
          await axiosInstance.put(`/sync/role-policies/${selectedRolePolicy.job_role_id}`, selectedRolePolicy.config);
          alert('Política actualizada exitosamente en la base de datos.');
          fetchData();
          window.dispatchEvent(new Event('db_sync_updated'));
      } catch(e) {
          console.error(e);
      }
  };

  const fetchData = async () => {
    try {
      setLoading(true);
      
      const [stateRes, empRes, rolesRes, vacRes, assRes] = await Promise.all([
          axiosInstance.get('/sync/state').catch(err => {
              console.error("Error al cargar /sync/state en RRHH:", err);
              return { data: {} };
          }),
          axiosInstance.get('/employees').catch(err => {
              console.error("Error al cargar /employees en RRHH:", err);
              return { data: [] };
          }),
          axiosInstance.get('/job-roles').catch(err => {
              console.error("Error al cargar /job-roles en RRHH:", err);
              return { data: [] };
          }),
          axiosInstance.get('/admin/vacancies').catch(vacError => {
              console.error("Error al cargar vacantes para RRHH:", vacError);
              return { data: [] };
          }),
          axiosInstance.get('/store-opening/assignments').catch(assError => {
              console.error("Error al cargar asignaciones de llaves para RRHH:", assError);
              return { data: null };
          })
      ]);

      if (assRes && assRes.data) {
        localStorage.setItem('store_opening_assignments', JSON.stringify(assRes.data));
      }

      const data = stateRes.data || {};
      setUsers(empRes.data || []);
      const cleanRoles = (rolesRes.data || []).map((r: any) => ({
        ...r,
        area: r.area ? r.area.replace(/Administraci\?\?n/g, 'Administración').trim() : ''
      }));
      setJobRoles(cleanRoles);
      setVacancies(vacRes.data || []);

      if (data.system_settings) {
        setGlobalSettings((prev: any) => ({...prev, ...data.system_settings}));
      }

      if (data.role_clock_policies) {
        const parsedPolicies = data.role_clock_policies.map((p: any) => ({
           ...p,
           config: typeof p.config === 'string' ? JSON.parse(p.config) : p.config
        }));
        setRoleClockPolicies(parsedPolicies);
      }

      // Build RBAC Config from DB
      const rules = data.ui_rbac_rules || [];
      const newConfig: any = {};
      
      // Initialize with empty arrays for all roles
      (rolesRes.data || []).forEach((r: any) => {
         newConfig[r.name] = { active: [], rest: [], absent: [] };
      });

      rules.forEach((rule: any) => {
         const role = (rolesRes.data || []).find((r: any) => r.id === rule.job_role_id);
         if (role) {
            newConfig[role.name][rule.state].push(rule.module);
         }
      });
      setRbacConfig(newConfig);

      setPermissionsList(data.permissions || []);
      const newPermConfig: any = {};
      (rolesRes.data || []).forEach((r: any) => {
         newPermConfig[r.id] = [];
      });
      (data.role_permissions || []).forEach((rp: any) => {
         if (newPermConfig[rp.job_role_id]) {
            newPermConfig[rp.job_role_id].push(rp.permission_id);
         }
      });
      setRolePermissionsConfig(newPermConfig);

    } catch(e) {
      console.error("Error al cargar desde DB en RRHH:", e);
    } finally {
      setLoading(false);
    }
  };

  const getCompanyDomain = () => {
    const appState = useAppStore.getState();
    let subdomain = appState.currentUser?.tenant?.subdomain;

    if (!subdomain && appState.currentUser?.email) {
      const emailParts = appState.currentUser.email.split('@');
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

    if (!subdomain && appState.systemSettings?.company_name) {
      const cleanCompName = appState.systemSettings.company_name
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/[^a-z0-9]/g, '');
      if (cleanCompName.length > 0) {
        subdomain = cleanCompName;
      }
    }

    return `@${subdomain || 'decorarte360'}.com`;
  };

  const handleAddUser = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newUserName || !newUserRole) return;
    try {
      const companyDomain = getCompanyDomain();
      const res = await axiosInstance.post('/employees', {
        name: newUserName,
        job_role_id: newUserRole,
        contract_type: contractType,
        email: `${newUserName.toLowerCase().replace(/\s/g, '')}${companyDomain}`,
        password: 'password123',
        role: 'empleado',
        salary: newUserSalary ? parseFloat(newUserSalary) : null
      });
      setShowForm(false);
      setNewUserName('');
      setNewUserSalary('');
      await fetchData(); // Recargar
      window.dispatchEvent(new Event('db_sync_updated'));
    } catch (e: any) {
      console.error(e);
      if (e.response && e.response.status === 403) {
          const isLimit = e.response.data?.error === 'Admin Limit Exceeded' || e.response.data?.message?.includes('límite');
          if (isLimit) {
              setUpgradeModalMessage(e.response.data.message || 'Has alcanzado el límite de administradores.');
              setShowUpgradeModal(true);
          } else {
              alert(e.response.data?.message || "Acceso prohibido.");
          }
      } else if (e.response && e.response.status === 422) {
          const errors = e.response.data.errors ? Object.values(e.response.data.errors).flat().join("\n") : "Datos inválidos";
          alert(`Error de validación al crear empleado:\n${errors}`);
      } else {
          alert("Error al guardar en la base de datos.");
      }
    }
  };

  const handleCreateJobRoleClick = () => {
    setEditingJobRole({
      id: 0,
      name: '',
      area: 'Administración',
      icon: 'auto',
      description: '',
      responsibilities: '',
      reports_to_role_id: null,
      org_parent_role_id: null,
      nivel_mando: 4,
      reports_to_role_ids: [],
      esAperturador: false,
      portadorLlaves: 'ninguno',
      tiempoTolerancia: 10,
      requiereJustificante: true,
      puedeEmitirAvisos: false,
      aplicaLeySilla: false,
      evaluacion360Activa: false,
      late_penalty_multiplier: 1,
      required_equipment: ''
    });
    setEditingJobRoleTab('perfil');
  };

  const handleDeleteJobRole = async (id: number) => {
    if (!window.confirm("¿Seguro que deseas eliminar este puesto de trabajo? Para proceder, el puesto no debe tener colaboradores ni vacantes activas vinculadas.")) return;
    try {
      const appState = useAppStore.getState();
      if (appState.isSandboxMode) {
          setJobRoles(jobRoles.filter(r => r.id !== id));
          return;
      }
      const res = await axiosInstance.delete('/job-roles/' + id);
      if (res.status !== 200) throw new Error("Failed to delete job role");
      setJobRoles(prev => prev.filter(r => r.id !== id));
      alert(res.data.message || "Puesto de trabajo eliminado correctamente.");
      await fetchData();
      window.dispatchEvent(new Event('db_sync_updated'));
    } catch (err: any) {
      console.error(err);
      const msg = err.response?.data?.message || "No se pudo eliminar el puesto de trabajo. Asegúrate de reasignar a todos los colaboradores y vacantes vinculados a este puesto en sus respectivos módulos antes de intentar de nuevo.";
      alert(`No se pudo eliminar el puesto:\n\n${msg}`);
    }
  };

  const handleRemoveAreaFromRoles = async (areaName: string) => {
    if (!window.confirm(`¿Deseas desvincular y eliminar el área "${areaName}"? Se quitará de todos los puestos de trabajo que la tengan asignada.`)) return;
    try {
      const appState = useAppStore.getState();
      
      const rolesToUpdate = jobRoles.filter(r => {
         const list = (r.area || '').split(',').map((s: string) => s.trim()).filter(Boolean);
         return list.includes(areaName);
      });
      
      if (appState.isSandboxMode) {
          const updated = jobRoles.map(r => {
             const list = (r.area || '').split(',').map((s: string) => s.trim()).filter(Boolean);
             if (list.includes(areaName)) {
                const newList = list.filter((a: string) => a !== areaName);
                return { ...r, area: newList.join(', ') || 'General' };
             }
             return r;
          });
          setJobRoles(updated);
          
          if (editingJobRole) {
             const list = (editingJobRole.area || '').split(',').map((s: string) => s.trim()).filter(Boolean);
             if (list.includes(areaName)) {
                const newList = list.filter((a: string) => a !== areaName);
                setEditingJobRole({ ...editingJobRole, area: newList.join(', ') || 'General' });
             }
          }
          alert(`Área "${areaName}" eliminada correctamente.`);
          return;
      }

      await Promise.all(rolesToUpdate.map(r => {
         const list = (r.area || '').split(',').map((s: string) => s.trim()).filter(Boolean);
         const newList = list.filter((a: string) => a !== areaName);
         return axiosInstance.put('/job-roles/' + r.id, { 
            ...r, 
            area: newList.join(', ')
         });
      }));

      if (editingJobRole) {
         const list = (editingJobRole.area || '').split(',').map((s: string) => s.trim()).filter(Boolean);
         if (list.includes(areaName)) {
            const newList = list.filter((a: string) => a !== areaName);
            setEditingJobRole({ ...editingJobRole, area: newList.join(', ') });
         }
      }

      alert(`Área "${areaName}" desvinculada y eliminada exitosamente de todos los puestos.`);
      await fetchData();
      window.dispatchEvent(new Event('db_sync_updated'));
    } catch (err) {
      console.error(err);
      alert("Error al eliminar el área.");
    }
  };

  const handleEditJobRole = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingJobRole) return;
    try {
      const appState = useAppStore.getState();
      if (appState.isSandboxMode) {
          if (editingJobRole.id === 0) {
              const newId = Date.now();
              setJobRoles([...jobRoles, { ...editingJobRole, id: newId }]);
          } else {
              setJobRoles(jobRoles.map(r => r.id === editingJobRole.id ? editingJobRole : r));
          }
          setEditingJobRole(null);
          return;
      }
      if (editingJobRole.id === 0) {
          const res = await axiosInstance.post('/job-roles', editingJobRole);
          if (res.status !== 201 && res.status !== 200) throw new Error("Failed to create job role");
          alert("Puesto de trabajo creado exitosamente.");
      } else {
          const res = await axiosInstance.put('/job-roles/' + editingJobRole.id, editingJobRole);
          if (res.status !== 200) throw new Error("Failed to save job role");
          alert("Puesto de trabajo actualizado exitosamente.");
      }
      setEditingJobRole(null);
      await fetchData();
      window.dispatchEvent(new Event('db_sync_updated'));
    } catch(err: any) { 
        console.error(err); 
        const msg = err.response?.data?.message || "Error al guardar el puesto.";
        alert(msg);
    }
  };
  
  const handleToggleJobRoleActive = async (rol: any) => {
    try {
      const appState = useAppStore.getState();
      const newActiveStatus = rol.is_active !== false ? false : true;
      if (appState.isSandboxMode) {
          setJobRoles(jobRoles.map(r => r.id === rol.id ? { ...r, is_active: newActiveStatus } : r));
          return;
      }
      const res = await axiosInstance.put('/job-roles/' + rol.id, {
          ...rol,
          is_active: newActiveStatus
      });
      if (res.status === 200) {
          await fetchData();
          window.dispatchEvent(new Event('db_sync_updated'));
      }
    } catch (err: any) {
        console.error(err);
        alert(err.response?.data?.message || "Error al cambiar el estado del puesto.");
    }
  };

  const handleEditUser = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingUser) return;
    try {
      // Saneamiento de datos: convertir strings vacíos a null y forzar números
      const payload = { ...editingUser };
      Object.keys(payload).forEach(key => {
          if (payload[key] === "") payload[key] = null;
      });
      if (payload.base_salary) payload.base_salary = parseFloat(payload.base_salary);
      if (payload.salary) payload.salary = parseFloat(payload.salary);
      if (payload.mealMinutes) payload.mealMinutes = parseInt(payload.mealMinutes, 10);
      if (payload.job_role_id) payload.job_role_id = parseInt(payload.job_role_id, 10);

      const res = await axiosInstance.put('/employees/' + (editingUser.employee_id || editingUser.id), payload);
      
      if (res.status !== 200) {
          throw new Error("Failed to save user");
      }
      
      setEditingUser(null);
      await fetchData();
      window.dispatchEvent(new Event('db_sync_updated'));
    } catch(e: any) { 
        console.error(e); 
        if (e.response && e.response.status === 403) {
            const isLimit = e.response.data?.error === 'Admin Limit Exceeded' || e.response.data?.message?.includes('límite');
            if (isLimit) {
                setUpgradeModalMessage(e.response.data.message || 'Has alcanzado el límite de administradores.');
                setShowUpgradeModal(true);
            } else {
                alert(e.response.data?.message || "Acceso prohibido.");
            }
        } else if (e.response && e.response.status === 422) {
            const errors = e.response.data.errors ? Object.values(e.response.data.errors).flat().join("\n") : "Datos inválidos";
            alert(`Validación fallida:\n${errors}`);
        } else {
            alert(e.message || "Error al guardar la ficha. Verifique que el servidor backend esté encendido.");
        }
    }
  };

  const handleDeleteUser = async (id: number) => {
    if(!window.confirm('¿Deseas enviar a este empleado como inactivo? Su historial de asistencias se mantendrá intacto, pero ya no aparecerá en las listas activas.')) return;
    try {
      const res = await axiosInstance.delete(`/employees/${id}`);
      if (res.status !== 200 && res.status !== 204) throw new Error("Failed to delete user");
      await fetchData();
      window.dispatchEvent(new Event('db_sync_updated'));
    } catch(e: any) {
      console.error("Error al enviar a inactivo:", e);
      const errMsg = e.response?.data?.error || e.response?.data?.message || "Error al desactivar la ficha.";
      alert(errMsg);
    }
  };

  const handleForceDeleteUser = async (id: number) => {
    if(!window.confirm('¿Seguro que deseas eliminar definitivamente a este colaborador? Esta acción no se puede deshacer y borrará permanentemente sus registros de la base de datos.')) return;
    try {
      const res = await axiosInstance.delete(`/employees/${id}/force`);
      if (res.status !== 200) throw new Error("Failed to force delete user");
      await fetchData();
      window.dispatchEvent(new Event('db_sync_updated'));
    } catch(e: any) {
      console.error(e);
      alert(e.response?.data?.error || "Error al eliminar definitivamente la ficha.");
    }
  };

  const handleRestoreUser = async (id: number) => {
    if(!window.confirm('¿Deseas restaurar a este colaborador? Volverá a aparecer en el directorio activo.')) return;
    try {
      const res = await axiosInstance.put(`/employees/${id}`, { is_active_employee: true });
      if (res.status !== 200) throw new Error("Failed to restore user");
      await fetchData();
      window.dispatchEvent(new Event('db_sync_updated'));
    } catch(e) {
      console.error(e);
      alert("Error al restaurar la ficha.");
    }
  };

  const handleNodeClick = (role: any) => {
    setSelectedRoleForDrawer(role);
    setDrawerDescription(role.description || '');
    setDrawerResponsibilities(role.responsibilities || '');
    setDrawerManualName(role.manual_name || '');
    setDrawerManualUrl(role.manual_url || '');
    setDrawerParentId(role.org_parent_role_id || '');
    setIsRoleDrawerOpen(true);
  };

  const saveDrawerRole = async () => {
    if (!selectedRoleForDrawer) return;
    setIsSavingDrawer(true);
    try {
      const appState = useAppStore.getState();
      const updatedData = {
        description: drawerDescription,
        responsibilities: drawerResponsibilities,
        manual_name: drawerManualName,
        manual_url: drawerManualUrl || (drawerManualName ? `/storage/manuals/${drawerManualName}` : ''),
        org_parent_role_id: drawerParentId === '' ? null : Number(drawerParentId)
      };

      if (appState.isSandboxMode) {
        setJobRoles(jobRoles.map(r => r.id === selectedRoleForDrawer.id ? { ...r, ...updatedData } : r));
        alert("Puesto actualizado con éxito (Modo Simulación).");
        setIsRoleDrawerOpen(false);
        return;
      }

      const res = await axiosInstance.put(`/job-roles/${selectedRoleForDrawer.id}`, updatedData);
      if (res.status === 200 || res.status === 201) {
        alert("Puesto de trabajo actualizado en Postgres con éxito.");
        // Refetch roles
        const freshRoles = await axiosInstance.get('/job-roles');
        setJobRoles(freshRoles.data);
        setIsRoleDrawerOpen(false);
      } else {
        throw new Error("No se pudo actualizar el puesto.");
      }
    } catch (e) {
      console.error(e);
      alert("Error al actualizar los detalles del puesto.");
    } finally {
      setIsSavingDrawer(false);
    }
  };

  const filteredUsers = users.filter((u: any) => {
    const isActive = u.is_active_employee !== false && u.is_active_employee !== 0;
    const matchesStatus = directorioSubTab === 'activos' ? isActive : !isActive;
    const matchesSearch = searchQuery.trim() 
      ? u.name.toLowerCase().includes(searchQuery.toLowerCase())
      : true;
    const matchesRole = selectedRoleFilter
      ? u.job_role_id === Number(selectedRoleFilter)
      : true;
    return matchesStatus && matchesSearch && matchesRole;
  });

  // Interfaces y lógica para el organigrama
  interface OrgNode {
    role: any;
    collaborators: any[];
    children: OrgNode[];
  }

  const buildOrgTree = (rolesList: any[], employeesList: any[]): OrgNode[] => {
    const activeRoles = rolesList.filter((r: any) => r.is_active !== false);
    const activeEmployees = employeesList.filter((e: any) => e.is_active_employee !== false && e.job_role_id);
    const activeRoleIds = new Set(activeRoles.map(r => r.id));

    const getParentId = (r: any): number | null => {
      if (r.org_parent_role_id !== undefined && r.org_parent_role_id !== null) {
        return Number(r.org_parent_role_id);
      }
      if (r.reports_to_role_id !== undefined && r.reports_to_role_id !== null) {
        return Number(r.reports_to_role_id);
      }
      if (r.parent_role_id !== undefined && r.parent_role_id !== null) {
        return Number(r.parent_role_id);
      }
      return null;
    };

    const getChildrenRoles = (parentId: number) => {
      return activeRoles
        .filter(r => getParentId(r) === parentId)
        .sort((a, b) => (a.nivel_mando ?? 4) - (b.nivel_mando ?? 4));
    };

    const buildSubtree = (r: any, path: Set<number> = new Set()): OrgNode | null => {
      if (path.has(r.id)) {
        return null;
      }
      const newPath = new Set(path);
      newPath.add(r.id);

      const childrenRoles = getChildrenRoles(r.id);
      const childrenNodes: OrgNode[] = [];
      
      childrenRoles.forEach(childRole => {
        const childNode = buildSubtree(childRole, newPath);
        if (childNode) {
          childrenNodes.push(childNode);
        }
      });

      return {
        role: r,
        collaborators: activeEmployees.filter((e: any) => e.job_role_id === r.id),
        children: childrenNodes
      };
    };

    const isRoot = (r: any) => {
      const parentId = getParentId(r);
      return parentId === null || !activeRoleIds.has(parentId);
    };

    const rootRoles = activeRoles.filter(isRoot);
    const roots: OrgNode[] = [];
    
    rootRoles.forEach(r => {
      const node = buildSubtree(r);
      if (node) {
        roots.push(node);
      }
    });

    return roots;
  };

  const getLevelBadge = (level: number) => {
    switch (level) {
      case 1: return { text: '👑 Dirección', bg: 'bg-amber-100 text-amber-800 border-amber-200' };
      case 2: return { text: '⭐ Jefatura', bg: 'bg-blue-100 text-blue-800 border-blue-200' };
      case 3: return { text: '📈 Supervisión', bg: 'bg-emerald-100 text-emerald-800 border-emerald-200' };
      case 4: return { text: '👤 Operativo', bg: 'bg-indigo-100 text-indigo-800 border-indigo-200' };
      case 5: return { text: '🔧 Auxiliar', bg: 'bg-slate-100 text-slate-800 border-slate-200' };
      case 6: return { text: '🚫 Inactivo/Apoyo', bg: 'bg-rose-100 text-rose-800 border-rose-200' };
      default: return { text: '👤 Puesto', bg: 'bg-slate-100 text-slate-800 border-slate-200' };
    }
  };

  const renderTreeNode = (node: OrgNode, parentKey = 'root') => {
    const nodeKey = `${node.role.id}-${parentKey}`;
    const level = node.role.nivel_mando ?? 4;
    const levelInfo = getLevelBadge(level);

    // Hover Highlight calculation
    const hoveredRole = hoveredRoleId ? jobRoles.find((r: any) => r.id === hoveredRoleId) : null;
    const isHovered = hoveredRoleId === node.role.id;
    
    const isRelatedParent = hoveredRole ? (
      (hoveredRole.reports_to_role_ids || []).includes(node.role.id) ||
      hoveredRole.org_parent_role_id === node.role.id
    ) : false;

    const isRelatedChild = hoveredRoleId ? (
      (node.role.reports_to_role_ids || []).includes(hoveredRoleId) ||
      node.role.org_parent_role_id === hoveredRoleId
    ) : false;

    const isDraggedOver = draggedOverRoleId === node.role.id;

    // Card border styling
    let cardBorderClass = "border-slate-200";
    if (isDraggedOver) {
      cardBorderClass = "border-dashed border-indigo-500 bg-indigo-50/50 scale-105 shadow-indigo-100";
    } else if (isHovered) {
      cardBorderClass = "border-indigo-500 ring-4 ring-indigo-500/20 scale-102 shadow-indigo-100";
    } else if (isRelatedParent) {
      cardBorderClass = "border-emerald-500 ring-4 ring-emerald-500/20 scale-102 shadow-emerald-100";
    } else if (isRelatedChild) {
      cardBorderClass = "border-blue-500 ring-4 ring-blue-500/20 scale-102 shadow-blue-100";
    } else {
      switch (level) {
        case 1: cardBorderClass = "border-amber-400 bg-amber-50/5"; break;
        case 2: cardBorderClass = "border-blue-400 bg-blue-50/5"; break;
        case 3: cardBorderClass = "border-emerald-400 bg-emerald-50/5"; break;
        case 4: cardBorderClass = "border-indigo-400"; break;
        case 5: cardBorderClass = "border-slate-300"; break;
        case 6: cardBorderClass = "border-dashed border-slate-300 opacity-80"; break;
      }
    }

    return (
      <li key={nodeKey} className="relative px-2">
        <div 
          draggable={!readOnly}
          onDragStart={(e) => {
            if (readOnly) return;
            e.dataTransfer.setData('type', 'role');
            e.dataTransfer.setData('text/plain', node.role.id.toString());
          }}
          onDragOver={(e) => {
            if (readOnly) return;
            e.preventDefault();
            if (draggedOverRoleId !== node.role.id) {
              setDraggedOverRoleId(node.role.id);
            }
          }}
          onDragLeave={() => {
            if (readOnly) return;
            if (draggedOverRoleId === node.role.id) {
              setDraggedOverRoleId(null);
            }
          }}
          onDrop={async (e) => {
            if (readOnly) return;
            e.preventDefault();
            setDraggedOverRoleId(null);
            const draggedType = e.dataTransfer.getData('type') || 'role';
            const draggedId = Number(e.dataTransfer.getData('text/plain'));
            if (draggedType === 'collaborator') {
              await handleCollaboratorRoleDrop(draggedId, node.role.id);
            } else {
              handleRoleDrop(draggedId, node.role.id);
            }
          }}
          onMouseEnter={() => setHoveredRoleId(node.role.id)}
          onMouseLeave={() => setHoveredRoleId(null)}
          onClick={() => handleNodeClick(node.role)}
          className={`inline-block bg-white dark:bg-slate-900 border-2 rounded-3xl p-5 text-center min-w-[240px] max-w-[280px] shadow-sm transition-all duration-300 relative z-10 ${readOnly ? 'cursor-pointer hover:shadow-md' : 'cursor-grab active:cursor-grabbing'} ${cardBorderClass}`}
        >
          {/* Rango Badge */}
          <div className="mb-2">
            <span className={`text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full border ${levelInfo.bg}`}>
              {levelInfo.text}
            </span>
          </div>

          <div className="font-black text-xs text-slate-800 uppercase tracking-widest mb-1 flex items-center justify-center gap-1">{node.role.name}{getJobRoleKeysIcon(node.role.id)}</div>
          <div className="text-[9px] font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-md inline-block mb-3">{node.role.area || 'General'}</div>
          
          <div className="space-y-2 mt-1">
            {node.collaborators.length > 0 ? (
              node.collaborators.map((c: any) => (
                <div 
                  key={c.id} 
                  draggable
                  onDragStart={(e) => {
                    e.stopPropagation();
                    e.dataTransfer.setData('type', 'collaborator');
                    e.dataTransfer.setData('text/plain', c.id.toString());
                  }}
                  className="flex items-center gap-2 bg-slate-50 border border-slate-100 p-2 rounded-2xl hover:bg-indigo-50/50 hover:border-indigo-100 transition-all duration-200 cursor-grab active:cursor-grabbing select-none"
                >
                  <img 
                    src={c.avatar || `https://api.dicebear.com/7.x/avataaars/svg?seed=${c.name}`} 
                    alt={c.name} 
                    className="w-8 h-8 rounded-full border-2 border-white shadow-sm flex-shrink-0"
                  />
                  <div className="text-left overflow-hidden">
                    <div className="text-[11px] font-black text-slate-800 truncate leading-tight">{c.name}{getUserKeysIcon(c.employee_id ? Number(c.employee_id) : Number(c.id))}</div>
                    <div className="text-[8px] font-medium text-slate-400 truncate">{c.email}</div>
                  </div>
                </div>
              ))
            ) : (
              <div className="text-[10px] font-bold italic text-slate-400 bg-slate-50 border border-dashed border-slate-200 py-2.5 rounded-2xl">
                Vacante / Sin asignar
              </div>
            )}
          </div>
        </div>

        {node.children.length > 0 && (
          <ul className="flex justify-center relative">
            {node.children.map((child, idx) => renderTreeNode(child, `${nodeKey}-${idx}`))}
          </ul>
        )}
      </li>
    );
  };

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      
      {/* HEADER RRHH */}
      {!readOnly && (
        <div className="bg-transparent sm:bg-white rounded-3xl p-0 sm:p-8 shadow-none sm:shadow-sm border-none sm:border sm:border-slate-200">
          {/* TABS */}
          <div className="flex items-center gap-1.5 sm:gap-2 bg-slate-100/60 sm:bg-slate-50 p-1.5 rounded-3xl sm:rounded-2xl w-full overflow-x-auto whitespace-nowrap scrollbar-none border border-slate-200">
            <button 
              onClick={() => setActiveTab('directorio')}
              className={`flex-shrink-0 flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2 text-[10px] sm:text-sm font-bold p-3 sm:px-6 sm:py-2.5 rounded-2xl sm:rounded-xl min-w-[85px] sm:min-w-0 transition-all relative ${
                activeTab === 'directorio' 
                  ? 'bg-white text-blue-700 shadow-sm border border-slate-150' 
                  : 'bg-transparent border-transparent text-slate-500 hover:text-slate-700 hover:bg-slate-100'
              }`}
            >
              <Users size={18} className={activeTab === 'directorio' ? 'text-blue-600' : 'text-slate-400'} />
              <span className="whitespace-normal sm:whitespace-nowrap text-center leading-tight">Colaboradores</span>
              {/* Counter Badge */}
              <span className={`absolute top-1 sm:top-auto sm:relative right-1.5 sm:right-auto px-1.5 py-0.5 rounded-full text-[9px] font-black leading-none ${
                activeTab === 'directorio' ? 'bg-blue-100 text-blue-800 border border-blue-200' : 'bg-slate-200 text-slate-600 border border-slate-300'
              }`}>
                {users.filter((u: any) => u.is_active_employee !== false && u.is_active_employee !== 0).length}
              </span>
            </button>
            <button 
              onClick={() => setActiveTab('puestos')}
              className={`flex-shrink-0 flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2 text-[10px] sm:text-sm font-bold p-3 sm:px-6 sm:py-2.5 rounded-2xl sm:rounded-xl min-w-[85px] sm:min-w-0 transition-all relative ${
                activeTab === 'puestos' 
                  ? 'bg-white text-emerald-700 shadow-sm border border-slate-150' 
                  : 'bg-transparent border-transparent text-slate-500 hover:text-slate-700 hover:bg-slate-100'
              }`}
            >
              <Briefcase size={18} className={activeTab === 'puestos' ? 'text-emerald-600' : 'text-slate-400'} />
              <span className="whitespace-normal sm:whitespace-nowrap text-center leading-tight">Puestos</span>
              {/* Counter Badge */}
              <span className={`absolute top-1 sm:top-auto sm:relative right-1.5 sm:right-auto px-1.5 py-0.5 rounded-full text-[9px] font-black leading-none ${
                activeTab === 'puestos' ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-slate-200 text-slate-600 border border-slate-300'
              }`}>
                {jobRoles.filter((role: any) => role.is_active !== false).length}
              </span>
            </button>
            <button 
              onClick={() => setActiveTab('organigrama')}
              className={`flex-shrink-0 flex flex-col sm:flex-row items-center justify-center gap-1.5 sm:gap-2 text-[10px] sm:text-sm font-bold p-3 sm:px-6 sm:py-2.5 rounded-2xl sm:rounded-xl min-w-[85px] sm:min-w-0 transition-all relative ${
                activeTab === 'organigrama' 
                  ? 'bg-white text-purple-700 shadow-sm border border-slate-150' 
                  : 'bg-transparent border-transparent text-slate-500 hover:text-slate-700 hover:bg-slate-100'
              }`}
            >
              <Network size={18} className={activeTab === 'organigrama' ? 'text-purple-600' : 'text-slate-400'} />
              <span className="whitespace-normal sm:whitespace-nowrap text-center leading-tight">Organigrama</span>
            </button>
          </div>
        </div>
      )}

      {/* CONTENIDO TABS */}
      <div className="bg-white rounded-3xl p-4 sm:p-8 shadow-sm border border-slate-200 min-h-[500px]">
        {activeTab === 'directorio' && (
          <div>
                {/* SUB-TABS y botón Alta */}
                <div className="hidden sm:flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6 border-b border-slate-100 pb-4 w-full">
                   <div className="flex gap-4 sm:gap-6 w-full overflow-x-auto whitespace-nowrap scrollbar-none">
                      <button
                        type="button"
                        onClick={() => {
                           setDirectorioSubTab('activos');
                           setSearchQuery('');
                           setSelectedRoleFilter('');
                        }}
                        className={`flex-shrink-0 pb-2.5 font-bold text-base sm:text-lg relative transition-colors ${
                           directorioSubTab === 'activos' 
                           ? 'text-slate-900 border-b-2 border-indigo-600' 
                           : 'text-slate-400 hover:text-slate-600'
                        }`}
                      >
                         Directorio Activo
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                           setDirectorioSubTab('inactivos');
                           setSearchQuery('');
                           setSelectedRoleFilter('');
                        }}
                        className={`flex-shrink-0 pb-2.5 font-bold text-base sm:text-lg relative transition-colors ${
                           directorioSubTab === 'inactivos' 
                           ? 'text-slate-900 border-b-2 border-indigo-600' 
                           : 'text-slate-400 hover:text-slate-600'
                        }`}
                      >
                         Directorio Inactivo (Archivados)
                      </button>
                   </div>
                   
                   {directorioSubTab === 'activos' && (
                      <button onClick={() => setShowForm(true)} className="w-full md:w-auto justify-center bg-blue-600 text-white px-5 py-2.5 rounded-xl text-sm font-bold hover:bg-blue-700 transition-colors shadow-sm flex items-center gap-2">
                        <Plus size={16}/> Alta de Colaborador
                      </button>
                   )}
                </div>

                {/* BUSCADOR Y FILTROS */}
                <div className="hidden sm:flex flex-col sm:flex-row gap-4 mb-6">
                   <div className="flex-1 relative">
                      <input
                        ref={searchInputRef}
                        type="text"
                        value={searchQuery}
                        onChange={e => setSearchQuery(e.target.value)}
                        placeholder="Buscar colaborador por nombre..."
                        className="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none text-sm bg-white"
                      />
                      <div className="absolute left-3.5 top-3.5 text-slate-400">
                         <Search size={16} />
                      </div>
                   </div>
                   <div className="w-full sm:w-64">
                      <select
                        value={selectedRoleFilter}
                        onChange={e => setSelectedRoleFilter(e.target.value)}
                        className="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none text-sm bg-white"
                      >
                         <option value="">Todos los puestos</option>
                         {jobRoles.map((r: any) => (
                            <option key={r.id} value={r.id}>{r.name}</option>
                         ))}
                      </select>
                   </div>
                </div>

                {/* BUSCADOR MÓVIL CONDICIONAL */}
                {showMobileSearch && (
                  <div className="block sm:hidden mb-6 relative">
                    <input
                      ref={searchInputRef}
                      type="text"
                      value={searchQuery}
                      onChange={e => setSearchQuery(e.target.value)}
                      placeholder="Buscar colaborador..."
                      className="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none text-sm bg-white"
                    />
                    <div className="absolute left-3.5 top-3.5 text-slate-400">
                       <Search size={16} />
                    </div>
                  </div>
                )}
                
                {/* LISTA DE EMPLEADOS */}
                {loading ? (
                   <p className="text-center text-slate-400 py-10 font-bold animate-pulse">Cargando base de datos...</p>
                ) : filteredUsers.length === 0 ? (
                   <div className="text-center text-slate-400 py-16 bg-slate-50 border border-dashed border-slate-200 rounded-2xl w-full col-span-full">
                      <Users className="mx-auto text-slate-300 mb-3" size={40} />
                      <p className="font-bold text-slate-500">No se encontraron colaboradores</p>
                      <p className="text-xs text-slate-400 mt-1">Prueba cambiando los filtros o el término de búsqueda.</p>
                   </div>
                ) : (
                   <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                     {filteredUsers.map((u: any) => {
                       const userRole = jobRoles.find((r: any) => r.id === u.job_role_id);
                       return (
                         <div key={u.id} className={`bg-white border rounded-2xl p-4 sm:p-6 shadow-sm hover:shadow-md transition-shadow relative ${directorioSubTab === 'inactivos' ? 'border-slate-200 opacity-85 bg-slate-50/50 shadow-none' : 'border-slate-200'}`}>
                           {directorioSubTab === 'activos' ? (
                              <div className="absolute top-3 right-3 sm:top-4 sm:right-4 flex gap-1.5">
                                <button onClick={() => setEditingUser(u)} className="w-7 h-7 sm:w-8 sm:h-8 rounded-full bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white flex items-center justify-center transition-colors font-bold" title="Editar"><Pencil size={14}/></button>
                                <button onClick={() => handleDeleteUser(u.id)} className="w-7 h-7 sm:w-8 sm:h-8 rounded-full bg-slate-50 text-slate-400 hover:bg-rose-500 hover:text-white flex items-center justify-center transition-colors font-bold" title="Enviar a inactivo">
                                  <UserMinus size={14}/>
                                </button>
                              </div>
                            ) : (
                              <div className="absolute top-3 right-3 sm:top-4 sm:right-4 flex gap-1.5">
                                <button onClick={() => handleRestoreUser(u.id)} className="w-7 h-7 sm:w-8 sm:h-8 rounded-full bg-emerald-50 text-emerald-600 hover:bg-emerald-600 hover:text-white flex items-center justify-center transition-colors font-bold" title="Re-activar Colaborador">
                                  <RotateCcw size={14}/>
                                </button>
                                <button onClick={() => handleForceDeleteUser(u.id)} className="w-7 h-7 sm:w-8 sm:h-8 rounded-full bg-slate-50 text-slate-400 hover:bg-rose-600 hover:text-white flex items-center justify-center transition-colors font-bold" title="Eliminar Definitivamente">
                                  <Trash2 size={14}/>
                                </button>
                              </div>
                            )}
                           <div className="flex items-center gap-3 sm:gap-4 mb-4">
                             <img src={u.avatar || `https://api.dicebear.com/7.x/avataaars/svg?seed=${u.name}`} alt={u.name} className="w-12 h-12 sm:w-16 sm:h-16 rounded-full object-cover border-2 border-slate-100" />
                             <div className="pr-16 sm:pr-20">
                               <h4 className="font-bold text-slate-800 text-base sm:text-lg leading-tight">{formatEmployeeDisplayName(u.name)}{getUserKeysIcon(u.employee_id ? Number(u.employee_id) : Number(u.id))}</h4>
                               <div className="flex gap-1.5 items-center mt-1 flex-wrap">
                                 <p className="text-indigo-600 font-bold text-xs sm:text-sm">{userRole?.name || 'Sin Puesto'}{userRole && getJobRoleKeysIcon(userRole.id)}</p>
                                 {directorioSubTab === 'inactivos' && (
                                   <span className="text-[9px] font-bold bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded-md border border-slate-200 uppercase tracking-wider">Archivado</span>
                                 )}
                               </div>
                             </div>
                           </div>
                           
                           {/* Horario/Jornada: Vista Escritorio */}
                           <div className="hidden sm:block bg-slate-50 rounded-xl p-3 text-sm border border-slate-100">
                             <div className="flex justify-between mb-1"><span className="text-slate-500">Horario:</span> <span className="font-bold text-slate-700">{formatTimeVisual(u.shiftStart)} - {formatTimeVisual(u.shiftEnd)}</span></div>
                             <div className="flex justify-between mb-1"><span className="text-slate-500">Comida:</span> <span className="font-bold text-slate-700">{u.mealMinutes} min</span></div>
                             <div className="flex justify-between"><span className="text-slate-500">Descanso:</span> <span className="font-bold text-slate-700">{u.restDay}</span></div>
                           </div>
                            
                            {/* Horario/Jornada: Vista Móvil Condensada */}
                            <div className="block sm:hidden bg-slate-50 rounded-xl p-2.5 text-[11px] border border-slate-100">
                              <div className="flex items-center justify-between flex-wrap gap-1 text-slate-700 font-semibold">
                                <div className="flex items-center gap-1.5">
                                  <Clock size={12} className="text-slate-400 shrink-0" />
                                  <span>{formatTimeVisual(u.shiftStart)} - {formatTimeVisual(u.shiftEnd)}</span>
                                </div>
                                <div className="flex items-center gap-1.5">
                                  <Coffee size={12} className="text-slate-400 shrink-0" />
                                  <span>{u.restDay} / {u.mealMinutes}m</span>
                                </div>
                              </div>
                              {u.phone && (
                                <div className="flex items-center justify-between pt-1.5 border-t border-slate-200/50 mt-1.5">
                                  <span className="text-slate-500 font-normal">Contacto:</span>
                                  <a href={`tel:${u.phone}`} className="text-indigo-600 font-bold hover:underline flex items-center gap-1">
                                    <Phone size={10} /> {u.phone}
                                  </a>
                                </div>
                              )}
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  )}
              {/* <!-- EDIT MODAL --> */}
              {editingUser && (
                <div className="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex flex-col items-center justify-start overflow-y-auto p-3 sm:p-6 pt-20 sm:pt-10 pb-10">
                  <div className="bg-white rounded-3xl p-5 sm:p-7 max-w-2xl w-full shadow-2xl relative my-auto border border-slate-100/80 animate-scale-up">
                     <button type="button" onClick={() => setEditingUser(null)} className="absolute top-4 right-4 sm:top-6 sm:right-6 text-slate-400 hover:text-slate-600 bg-slate-50 hover:bg-slate-100 p-2 rounded-full transition-colors"><X size={18}/></button>
                     <h2 className="text-xl sm:text-2xl font-extrabold text-slate-900 mb-4 sm:mb-6 pr-8">Ficha del Colaborador</h2>
                     
                     {/* TABS */}
                     <div className="flex sm:grid sm:grid-cols-4 gap-1.5 p-1 bg-slate-100 rounded-2xl mb-4 sm:mb-6 overflow-x-auto whitespace-nowrap scrollbar-none flex-nowrap">
                        <button 
                          type="button" 
                          onClick={() => setEditingUserTab('personal')} 
                          className={`flex-1 flex-shrink-0 py-2.5 px-2 sm:px-4 rounded-xl font-black text-[10px] sm:text-xs uppercase tracking-wider flex flex-col sm:flex-row items-center justify-center gap-1 sm:gap-1.5 transition-all ${
                            editingUserTab === 'personal' 
                              ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/20' 
                              : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200/50'
                          }`}
                        >
                          <User size={15} className={editingUserTab === 'personal' ? 'text-white' : 'text-slate-400'} />
                          <span>Personal</span>
                        </button>

                        <button 
                          type="button" 
                          onClick={() => setEditingUserTab('laboral')} 
                          className={`flex-1 flex-shrink-0 py-2.5 px-2 sm:px-4 rounded-xl font-black text-[10px] sm:text-xs uppercase tracking-wider flex flex-col sm:flex-row items-center justify-center gap-1 sm:gap-1.5 transition-all ${
                            editingUserTab === 'laboral' 
                              ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/20' 
                              : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200/50'
                          }`}
                        >
                          <Briefcase size={15} className={editingUserTab === 'laboral' ? 'text-white' : 'text-slate-400'} />
                          <span>Laboral</span>
                        </button>

                        <button 
                          type="button" 
                          onClick={() => setEditingUserTab('accesos')} 
                          className={`flex-1 flex-shrink-0 py-2.5 px-2 sm:px-4 rounded-xl font-black text-[10px] sm:text-xs uppercase tracking-wider flex flex-col sm:flex-row items-center justify-center gap-1 sm:gap-1.5 transition-all ${
                            editingUserTab === 'accesos' 
                              ? 'bg-amber-500 text-white shadow-md shadow-amber-500/20' 
                              : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200/50'
                          }`}
                        >
                          <Lock size={15} className={editingUserTab === 'accesos' ? 'text-white' : 'text-slate-400'} />
                          <span>Accesos</span>
                        </button>

                        <button 
                          type="button" 
                          onClick={() => setEditingUserTab('expediente')} 
                          className={`flex-1 flex-shrink-0 py-2.5 px-2 sm:px-4 rounded-xl font-black text-[10px] sm:text-xs uppercase tracking-wider flex flex-col sm:flex-row items-center justify-center gap-1 sm:gap-1.5 transition-all ${
                            editingUserTab === 'expediente' 
                              ? 'bg-purple-600 text-white shadow-md shadow-purple-600/20' 
                              : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200/50'
                          }`}
                        >
                          <FileText size={15} className={editingUserTab === 'expediente' ? 'text-white' : 'text-slate-400'} />
                          <span>Expediente</span>
                        </button>
                     </div>

                     <form onSubmit={handleEditUser}>
                        {editingUserTab === 'personal' && (
                           <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                              <div className="col-span-1 sm:col-span-2">
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Nombre Completo</label>
                                 <input type="text" value={editingUser.name || ''} onChange={e => setEditingUser({...editingUser, name: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" />
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">CURP</label>
                                 <input type="text" value={editingUser.curp || ''} onChange={e => setEditingUser({...editingUser, curp: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" />
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">RFC</label>
                                 <input type="text" value={editingUser.rfc || ''} onChange={e => setEditingUser({...editingUser, rfc: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" />
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Teléfono Celular</label>
                                 <div className="flex border border-slate-200 rounded-xl overflow-hidden focus-within:ring-2 focus-within:ring-blue-500/20 focus-within:border-blue-500 bg-slate-50 transition-all text-xs sm:text-sm">
                                   <div className="bg-slate-100/85 px-2.5 sm:px-3 py-1.5 sm:py-2 text-xs sm:text-sm text-slate-500 font-bold border-r border-slate-200 flex items-center gap-1.5 select-none">
                                     <span>🇲🇽</span>
                                     <span>+52</span>
                                   </div>
                                   <input 
                                     type="text" 
                                     value={formatPhoneVisual(editingUser.phone || '')} 
                                     onChange={e => setEditingUser({...editingUser, phone: getCleanDbPhone(e.target.value)})} 
                                     className="w-full px-3 sm:px-4 py-2 sm:py-2.5 outline-none font-mono text-xs sm:text-sm bg-transparent"
                                     placeholder="10 dígitos (ej: 55 1234 5678)"
                                   />
                                 </div>
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">NSS (Seguro Social)</label>
                                 <input type="text" value={editingUser.nss || ''} onChange={e => setEditingUser({...editingUser, nss: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" />
                              </div>
                              <div className="col-span-1 sm:col-span-2">
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Dirección</label>
                                 <input type="text" value={editingUser.address || ''} onChange={e => setEditingUser({...editingUser, address: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" />
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Contacto Emergencia (Nombre)</label>
                                 <input type="text" value={editingUser.emergency_contact_name || ''} onChange={e => setEditingUser({...editingUser, emergency_contact_name: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" />
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Tel. Emergencia</label>
                                 <input type="text" value={editingUser.emergency_contact_phone || ''} onChange={e => setEditingUser({...editingUser, emergency_contact_phone: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" />
                              </div>
                           </div>
                        )}

                        {editingUserTab === 'laboral' && (
                           <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Puesto de Trabajo</label>
                                 <select value={editingUser.job_role_id} onChange={e => setEditingUser({...editingUser, job_role_id: Number(e.target.value)})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200">
                                    {jobRoles.filter((r: any) => r.is_active !== false || r.id === editingUser.job_role_id).map((r: any) => <option key={r.id} value={r.id}>{r.name}</option>)}
                                 </select>
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Núm. Empleado</label>
                                 <input type="text" value={editingUser.employee_id || ''} onChange={e => setEditingUser({...editingUser, employee_id: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" />
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Hora Entrada</label>
                                 <input type="time" value={editingUser.shiftStart || ''} onChange={e => setEditingUser({...editingUser, shiftStart: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" />
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Hora Salida</label>
                                 <input type="time" value={editingUser.shiftEnd || ''} onChange={e => setEditingUser({...editingUser, shiftEnd: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" />
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Min. Comida</label>
                                 <input type="number" value={editingUser.mealMinutes || 60} onChange={e => setEditingUser({...editingUser, mealMinutes: Number(e.target.value)})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" />
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Día Descanso</label>
                                 <select value={editingUser.restDay || 'Domingo'} onChange={e => setEditingUser({...editingUser, restDay: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200">
                                    {['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'].map(d => <option key={d} value={d}>{d}</option>)}
                                 </select>
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Permiso de Llaves</label>
                                 <select value={editingUser.portadorLlaves || 'Ninguno'} onChange={e => setEditingUser({...editingUser, portadorLlaves: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200">
                                    {['Ninguno','Titular','Suplente'].map(d => <option key={d} value={d}>{d}</option>)}
                                 </select>
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Fecha Ingreso</label>
                                 <input type="date" value={editingUser.hire_date || ''} onChange={e => setEditingUser({...editingUser, hire_date: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" />
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Tipo Contrato</label>
                                 <select value={editingUser.contract_type || 'Fijo'} onChange={e => setEditingUser({...editingUser, contract_type: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200">
                                    <option value="Fijo">Fijo</option><option value="Destajo">Destajo</option><option value="Temporal">Temporal</option>
                                 </select>
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Salario Base (por período)</label>
                                 <input type="number" value={editingUser.salary || ''} onChange={e => setEditingUser({...editingUser, salary: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" placeholder="Ej. 12000" />
                              </div>
                           </div>
                        )}

                        {editingUserTab === 'accesos' && (
                           <div className="grid grid-cols-1 md:grid-cols-2 gap-3 sm:gap-4">
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Estatus Operativo</label>
                                 <label className="flex items-center gap-2.5 sm:gap-3 cursor-pointer p-2.5 sm:p-3 bg-slate-50 border border-slate-200 rounded-xl hover:bg-slate-100/80 transition-colors text-xs sm:text-sm">
                                    <input type="checkbox" checked={editingUser.is_active_employee !== false} onChange={e => setEditingUser({...editingUser, is_active_employee: e.target.checked})} className="w-5 h-5 text-indigo-600 rounded border-slate-300 focus:ring-indigo-500/20" />
                                    <span className="font-semibold text-slate-700">Colaborador en Activo</span>
                                 </label>
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Acceso al Panel Web</label>
                                 <label className="flex items-center gap-2.5 sm:gap-3 cursor-pointer p-2.5 sm:p-3 bg-slate-50 border border-slate-200 rounded-xl hover:bg-slate-100/80 transition-colors text-xs sm:text-sm">
                                    <input 
                                      type="checkbox" 
                                      checked={editingUser.role === 'admin' || editingUser.role === 'supervisor'} 
                                      onChange={e => {
                                        const allow = e.target.checked;
                                        setEditingUser({
                                          ...editingUser, 
                                          role: allow ? 'supervisor' : 'empleado',
                                          is_active: true
                                        });
                                      }} 
                                      className="w-5 h-5 text-indigo-600 rounded border-slate-300 focus:ring-indigo-500/20" 
                                    />
                                    <span className="font-semibold text-slate-700">Permitir acceso web</span>
                                 </label>
                              </div>

                              {(editingUser.role === 'admin' || editingUser.role === 'supervisor') && (
                                <>
                                  <div className="col-span-1 md:col-span-2">
                                     <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Correo de Acceso</label>
                                     <input type="email" value={editingUser.email || ''} onChange={e => setEditingUser({...editingUser, email: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" placeholder={`usuario${getCompanyDomain()}`} required />
                                  </div>
                                  <div>
                                     <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Rol de Acceso</label>
                                     <select 
                                       value={editingUser.role} 
                                       onChange={e => setEditingUser({...editingUser, role: e.target.value})} 
                                       className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200"
                                     >
                                       <option value="supervisor">Supervisor de Confianza</option>
                                       <option value="admin">Administrador General</option>
                                     </select>
                                  </div>
                                  <div>
                                     <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">Nueva Contraseña (Opcional)</label>
                                     <input type="password" value={editingUser.password || ''} onChange={e => setEditingUser({...editingUser, password: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" placeholder="Dejar en blanco para conservar actual" />
                                  </div>
                                </>
                              )}

                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">ID Google (Enlace Social)</label>
                                 <input type="text" value={editingUser.google_id || ''} onChange={e => setEditingUser({...editingUser, google_id: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" placeholder="No vinculado" />
                              </div>
                              <div>
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">ID Apple (Enlace Social)</label>
                                 <input type="text" value={editingUser.apple_id || ''} onChange={e => setEditingUser({...editingUser, apple_id: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" placeholder="No vinculado" />
                              </div>
                              <div className="col-span-1 md:col-span-2">
                                 <label className="block text-[11px] font-black text-slate-500 uppercase tracking-wider mb-1.5">ID Samsung (Enlace Social)</label>
                                 <input type="text" value={editingUser.samsung_id || ''} onChange={e => setEditingUser({...editingUser, samsung_id: e.target.value})} className="w-full px-3 sm:px-4 py-2 sm:py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition-all duration-200" placeholder="No vinculado" />
                              </div>
                              
                              <div className="border-t border-slate-100 pt-4 mt-2 col-span-1 md:col-span-2">
                                 <label className="block text-sm font-bold text-slate-700 mb-2">Invitación y Activación Móvil</label>
                                 {editingUser.pin_code ? (
                                    <div className="bg-slate-50 border border-slate-200 p-3 sm:p-4 rounded-xl space-y-3">
                                       <div className="flex justify-between items-center text-xs sm:text-sm">
                                          <span className="text-slate-500">PIN de Activación:</span>
                                          <span className="text-base sm:text-lg font-black text-indigo-600 tracking-widest">{editingUser.pin_code}</span>
                                       </div>
                                       <div className="space-y-1">
                                          <span className="text-[10px] sm:text-xs text-slate-500 block">Enlace de Activación:</span>
                                          <div className="flex gap-2">
                                             <input 
                                               type="text" 
                                               readOnly 
                                               value={`${getQrOrigin(qrIpOverride)}/invite?pin=${editingUser.pin_code}`} 
                                               className="w-full text-[10px] sm:text-xs bg-white border border-slate-200 p-2 rounded-lg text-slate-600 select-all" 
                                             />
                                             <button 
                                               type="button"
                                               onClick={() => {
                                                  navigator.clipboard.writeText(`${getQrOrigin(qrIpOverride)}/invite?pin=${editingUser.pin_code}`);
                                                  alert("Enlace copiado al portapapeles");
                                               }}
                                               className="px-3 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 text-[10px] sm:text-xs font-bold rounded-lg border border-indigo-200 transition-colors"
                                             >
                                                Copiar
                                             </button>
                                          </div>
                                       </div>

                                       {/* Sección Código QR */}
                                       <div className="flex items-center gap-4 mt-3 bg-white p-3 rounded-xl border border-slate-100">
                                          <img 
                                            src={`https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(getQrOrigin(qrIpOverride) + '/invite?pin=' + editingUser.pin_code)}`} 
                                            alt="Código QR de Activación" 
                                            className="w-16 h-16 sm:w-20 sm:h-20 shadow-sm border border-slate-100 rounded-lg flex-shrink-0"
                                          />
                                          <div className="text-left">
                                            <span className="text-[11px] sm:text-xs font-bold text-slate-700 block mb-1">Código QR de Activación</span>
                                            <p className="text-[9px] sm:text-[10px] text-slate-400 leading-normal font-sans">
                                               El colaborador puede escanear este código QR para abrir el reloj checador PWA e iniciar su activación en su teléfono móvil.
                                            </p>
                                          </div>
                                       </div>

                                       {/* Sección WhatsApp */}
                                       <div className="pt-3 border-t border-slate-200/80">
                                          <label className="text-[10px] font-bold text-slate-500 uppercase block mb-1.5">
                                             Enviar invitación por WhatsApp
                                          </label>
                                          <div className="flex gap-2">
                                             <div className="flex-1 flex border border-slate-200 rounded-lg overflow-hidden focus-within:ring-1 focus-within:ring-blue-500 focus-within:border-blue-500 bg-white text-xs sm:text-sm">
                                               <div className="bg-slate-50 px-2 sm:px-2.5 py-1.5 text-[10px] sm:text-xs text-slate-500 font-bold border-r flex items-center gap-1 select-none">
                                                 <span>🇲🇽</span>
                                                 <span>+52</span>
                                               </div>
                                               <input 
                                                 type="text" 
                                                 placeholder="10 dígitos (ej: 55 1234 5678)"
                                                 value={formatPhoneVisual(editingUser.phone || '')}
                                                 onChange={e => setEditingUser({...editingUser, phone: getCleanDbPhone(e.target.value)})}
                                                 className="w-full text-[11px] sm:text-xs bg-transparent px-3 py-1.5 text-slate-600 font-mono focus:outline-none"
                                               />
                                             </div>
                                             <button 
                                               type="button"
                                               onClick={async () => {
                                                  if (!editingUser.phone?.trim()) {
                                                     alert("Por favor ingresa un número de celular de WhatsApp.");
                                                     return;
                                                  }
                                                  
                                                  // Guardar el teléfono en el colaborador en base de datos de fondo
                                                  try {
                                                     await axiosInstance.put(`/employees/${editingUser.employee_id || editingUser.id}`, {
                                                        phone: editingUser.phone
                                                     });
                                                  } catch (e) {
                                                     console.error("Error al actualizar el teléfono en la BD:", e);
                                                  }
                                                  
                                                  // Formatear el mensaje
                                                  const inviteUrl = `${getQrOrigin(qrIpOverride)}/invite?pin=${editingUser.pin_code}`;
                                                  const message = `¡Hola, ${editingUser.name}! 👋\n\nTe damos la bienvenida a *Talent 360* de parte de tu empresa. 🏢\n\nA partir de hoy registrarás tu asistencia y verás tus tareas desde tu celular. Para activar tu Reloj Checador PWA en tu móvil, haz clic en el siguiente enlace:\n\n🔗 ${inviteUrl}\n\n🔑 Tu PIN temporal de acceso es: *${editingUser.pin_code}*\n\n¡Mucho éxito en tu primer día! 🚀`;
                                                  
                                                  const waUrl = `https://wa.me/${editingUser.phone.replace(/[^0-9]/g, '')}?text=${encodeURIComponent(message)}`;
                                                  window.open(waUrl, '_blank');
                                               }}
                                               className="px-3 sm:px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] sm:text-xs font-bold rounded-lg transition-colors flex items-center gap-1.5 flex-shrink-0 shadow-sm"
                                             >
                                                <MessageSquare size={13} />
                                                Enviar
                                             </button>
                                          </div>
                                       </div>

                                       {isLocalhost() && (
                                         <div className="p-3 bg-amber-50 border border-amber-200/80 rounded-xl text-left">
                                           <div className="flex items-center gap-1.5 text-amber-800 font-bold text-[10px] sm:text-xs mb-1">
                                             <Network size={14} className="text-amber-600" />
                                             <span>🔌 Desarrollo Local: Configuración de QR</span>
                                           </div>
                                           <p className="text-[9px] sm:text-[10px] text-amber-700 leading-relaxed mb-2">
                                             Al desarrollar localmente, el celular no puede acceder a <code className="bg-amber-100 px-1 rounded font-mono text-[9px]">localhost</code>. Ingresa la dirección IP local de tu PC (ej: <code className="bg-amber-100 px-1 rounded font-mono text-[9px]">192.168.1.75:5173</code>) para que tu cel pueda abrirlo:
                                           </p>
                                           <div className="flex gap-2">
                                             <input
                                               type="text"
                                               placeholder="ej: 192.168.1.75:5173"
                                               value={qrIpOverride}
                                               onChange={(e) => handleQrIpChange(e.target.value)}
                                               className="w-full text-[10px] sm:text-xs bg-white border border-amber-300 px-2.5 py-1 rounded-lg text-slate-700 focus:outline-none focus:ring-1 focus:ring-amber-500 placeholder-slate-400 font-mono shadow-sm"
                                             />
                                           </div>
                                         </div>
                                       )}
                                    </div>
                                 ) : (
                                    <div className="text-center py-2">
                                       <button
                                         type="button"
                                         onClick={async () => {
                                            try {
                                               const res = await axiosInstance.post(`/admin/employees/${editingUser.employee_id || editingUser.id}/generate-pin`);
                                               setEditingUser({ ...editingUser, pin_code: res.data.pin });
                                               alert("PIN generado exitosamente.");
                                            } catch (err) {
                                               console.error(err);
                                               alert("Error al generar el PIN de invitación.");
                                            }
                                         }}
                                         className="w-full py-2 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded-xl text-xs sm:text-sm font-bold transition-all"
                                       >
                                         Generar Código de Invitación
                                       </button>
                                    </div>
                                 )}
                              </div>
                           </div>
                        )}

                        {editingUserTab === 'expediente' && (
                           <div className="grid grid-cols-1 md:grid-cols-2 gap-3 sm:gap-4">
                              <div className="bg-slate-50 p-4 sm:p-6 rounded-2xl border border-slate-100">
                                 <h4 className="font-bold text-slate-800 mb-3 flex items-center gap-2 text-xs sm:text-sm">
                                    <Shield size={18} className="text-slate-500" /> Resultados de Inducción
                                 </h4>
                                 <p className="text-slate-500 italic text-xs sm:text-sm">Examen no realizado aún.</p>
                              </div>
                              <div className="bg-slate-50 p-4 sm:p-6 rounded-2xl border border-slate-100">
                                 <h4 className="font-bold text-slate-800 mb-3 text-xs sm:text-sm">Documentación Adjunta</h4>
                                 <div className="space-y-3">
                                    <a href="#" className="flex items-center gap-2 text-blue-600 hover:underline text-xs sm:text-sm font-medium">
                                       <FileText size={16} /> Currículum Vitae (PDF)
                                    </a>
                                    <a href="#" className="flex items-center gap-2 text-blue-600 hover:underline text-xs sm:text-sm font-medium">
                                       <User size={16} /> Identificación Oficial (INE)
                                    </a>
                                 </div>
                              </div>
                           </div>
                        )}

                        <div className="mt-6 sm:mt-8 flex gap-3 sm:gap-4">
                           <button type="button" onClick={() => setEditingUser(null)} className="flex-1 bg-slate-100 text-slate-700 font-bold py-2.5 sm:py-3 rounded-xl hover:bg-slate-200 text-xs sm:text-sm">Cancelar</button>
                           <button type="submit" className="flex-1 bg-indigo-600 text-white font-bold py-2.5 sm:py-3 rounded-xl hover:bg-indigo-700 text-xs sm:text-sm">Guardar Ficha</button>
                        </div>
                     </form>
                  </div>
                </div>
              )}

              {/* <!-- REGISTRATION/ADD MODAL --> */}
              {showForm && (
                <div className="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex flex-col items-center justify-start overflow-y-auto p-3 sm:p-6 pt-20 sm:pt-10 pb-10">
                  <div className="bg-white rounded-3xl p-6 sm:p-8 max-w-xl w-full shadow-2xl relative my-auto border border-slate-100/80 animate-scale-up">
                     <button type="button" onClick={() => setShowForm(false)} className="absolute top-4 right-4 sm:top-6 sm:right-6 text-slate-400 hover:text-slate-600 bg-slate-50 hover:bg-slate-100 p-2 rounded-full transition-all duration-200 hover:rotate-90"><X size={18}/></button>
                     <button 
                        type="button" 
                        onClick={voiceAssistant.startAssistant} 
                        title="Asistente de Voz"
                        className="absolute top-4 right-14 sm:top-6 sm:right-16 text-emerald-600 hover:text-emerald-700 bg-emerald-50 hover:bg-emerald-100 p-2 rounded-full transition-all duration-200 flex items-center justify-center hover:scale-105 active:scale-95 shadow-sm"
                     >
                        <Mic size={18} className={voiceAssistant.isListening ? 'animate-pulse' : ''} />
                     </button>
                     
                     {/* Header Artístico UX/UI */}
                     <div className="flex flex-col mb-5 mt-2">
                        <h2 className="text-xl sm:text-2xl font-extrabold text-slate-900 tracking-tight leading-tight flex items-center gap-2.5">
                           <span className="p-1.5 bg-gradient-to-tr from-emerald-400 to-teal-500 text-white rounded-lg inline-flex items-center justify-center shadow-sm shadow-emerald-500/20 animate-bounce-subtle shrink-0">
                              <UserPlus size={18} />
                           </span>
                           <span>Alta de Colaborador</span>
                        </h2>
                        <p className="text-xs text-slate-400 mt-1.5">Registra a un nuevo integrante en tu equipo y configura su esquema de pago básico de forma simple.</p>
                     </div>

                     <form onSubmit={handleAddUser} className="space-y-5">
                        <div className="space-y-4">
                           {/* Campo: Nombre Completo */}
                           <div className="group">
                              <label className="block text-[11px] font-black text-slate-400 group-focus-within:text-blue-600 uppercase tracking-wider mb-1.5 transition-colors">Nombre Completo</label>
                              <div className="relative">
                                 <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 group-focus-within:text-blue-500 transition-colors">
                                    <User size={18} />
                                 </div>
                                 <input 
                                    type="text" 
                                    value={newUserName} 
                                    onChange={e => setNewUserName(e.target.value)} 
                                    required 
                                    className="pl-10 w-full px-4 py-3 bg-slate-50/80 hover:bg-slate-50 border border-slate-200 rounded-2xl text-sm focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 focus:bg-white outline-none transition-all duration-300 placeholder-slate-400/60" 
                                    placeholder="Ej. Juan Pérez Maldonado" 
                                 />
                              </div>
                           </div>

                           {/* Fila de 2 Columnas para Puesto y Contrato */}
                           <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                              {/* Campo: Puesto */}
                              <div className="group">
                                 <label className="block text-[11px] font-black text-slate-400 group-focus-within:text-blue-600 uppercase tracking-wider mb-1.5 transition-colors">Puesto (Rol)</label>
                                 <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 group-focus-within:text-blue-500 transition-colors">
                                       <Briefcase size={18} />
                                    </div>
                                    <select 
                                       value={newUserRole} 
                                       onChange={e => setNewUserRole(e.target.value)} 
                                       required 
                                       className="pl-10 w-full px-4 py-3 bg-slate-50/80 hover:bg-slate-50 border border-slate-200 rounded-2xl text-sm focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 focus:bg-white outline-none transition-all duration-300 appearance-none cursor-pointer"
                                    >
                                       <option value="">Selecciona un puesto...</option>
                                       {jobRoles.filter((role: any) => role.is_active !== false).map((role: any) => (
                                          <option key={role.id} value={role.id}>{role.name}</option>
                                       ))}
                                    </select>
                                    <div className="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                                       <span className="text-[10px]">▼</span>
                                    </div>
                                 </div>
                              </div>

                              {/* Campo: Tipo de Contrato */}
                              <div className="group">
                                 <label className="block text-[11px] font-black text-slate-400 group-focus-within:text-blue-600 uppercase tracking-wider mb-1.5 transition-colors">Tipo de Contrato</label>
                                 <div className="relative">
                                    <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 group-focus-within:text-blue-500 transition-colors">
                                       <FileText size={18} />
                                    </div>
                                    <select 
                                       value={contractType} 
                                       onChange={e => setContractType(e.target.value)} 
                                       className="pl-10 w-full px-4 py-3 bg-slate-50/80 hover:bg-slate-50 border border-slate-200 rounded-2xl text-sm focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 focus:bg-white outline-none transition-all duration-300 appearance-none cursor-pointer"
                                    >
                                       <option value="Fijo">Sueldo Fijo / Base</option>
                                       <option value="Destajo">A Destajo (Comisiones)</option>
                                    </select>
                                    <div className="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none text-slate-400">
                                       <span className="text-[10px]">▼</span>
                                    </div>
                                 </div>
                              </div>
                           </div>

                           {/* Campo: Salario Base */}
                           <div className="group">
                              <label className="block text-[11px] font-black text-slate-400 group-focus-within:text-blue-600 uppercase tracking-wider mb-1.5 transition-colors">Salario Base (por período)</label>
                              <div className="relative">
                                 <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400 group-focus-within:text-blue-500 transition-colors">
                                    <DollarSign size={18} />
                                 </div>
                                 <input 
                                    type="number" 
                                    value={newUserSalary} 
                                    onChange={e => setNewUserSalary(e.target.value)} 
                                    className="pl-10 w-full px-4 py-3 bg-slate-50/80 hover:bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 focus:bg-white outline-none transition-all duration-300 placeholder-slate-400/60" 
                                    placeholder="Ej. 12000" 
                                 />
                              </div>
                           </div>
                        </div>

                        {/* Botones del pie */}
                        <div className="mt-8 flex gap-3 sm:gap-4 pt-2">
                           <button 
                              type="button" 
                              onClick={() => setShowForm(false)} 
                              className="flex-1 bg-slate-100 text-slate-700 font-bold py-3.5 px-4 rounded-2xl hover:bg-slate-200/80 transition-all duration-200 active:scale-95 text-xs sm:text-sm"
                           >
                              Cancelar
                           </button>
                           <button 
                              type="submit" 
                              className="flex-1 bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-600 hover:to-teal-700 text-white font-black py-3.5 px-4 rounded-2xl transition-all duration-200 shadow-md shadow-emerald-500/10 hover:shadow-lg active:scale-95 text-xs sm:text-sm flex items-center justify-center gap-2"
                           >
                              <Sparkles size={16} /> Guardar
                           </button>
                        </div>
                     </form>

                     {/* Voice Assistant Visual Overlay Console */}
                     <VoiceAssistantOverlay
                        isListening={voiceAssistant.isListening}
                        activeFieldIndex={voiceAssistant.activeFieldIndex}
                        totalFields={voiceFields.length}
                        activeFieldLabel={voiceAssistant.activeField ? voiceAssistant.activeField.label : ''}
                        transcript={voiceAssistant.transcript}
                        feedback={voiceAssistant.feedback}
                        onClose={voiceAssistant.stopAssistant}
                     />
                  </div>
                </div>
              )}
          </div>
        )}

        {activeTab === 'puestos' && (
          <div>
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
              <h3 className="text-2xl font-extrabold text-slate-800">Catálogo de Puestos de Trabajo</h3>
              <div className="flex flex-col sm:flex-row gap-2 sm:gap-3 w-full md:w-auto">
                <button 
                  type="button"
                  onClick={() => {
                    setShowTemplateModal(true);
                    setSelectedTemplate(null);
                  }} 
                  className="w-full sm:w-auto justify-center bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 px-5 py-2.5 rounded-xl text-sm font-bold shadow-sm flex items-center gap-2 transition-all"
                >
                   <ClipboardList size={16}/> Importar desde Plantillas
                </button>
                <button 
                  type="button"
                  onClick={handleCreateJobRoleClick} 
                  className="w-full sm:w-auto justify-center bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold shadow-sm flex items-center gap-2 transition-all"
                >
                   <Plus size={16}/> Crear Nuevo Puesto
                </button>
              </div>
            </div>
            
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              {jobRoles.map((rol: any) => {
                const employeesWithRole = users.filter((u: any) => u.job_role_id === rol.id || (Array.isArray(u.job_role_ids) && u.job_role_ids.includes(rol.id))).length;
                const roleVacancies = vacancies.filter((v: any) => v.job_role_id === rol.id);
                const isAutoActive = employeesWithRole > 0;
                
                // Determinar Jerarquía de Mando
                const iconKey = resolveJobRoleIconKey(rol);
                const matchedItem = JOB_ROLE_PROFESSIONS_MATRIX.find(p => p.key === iconKey);
                const nivel = rol.nivel_mando ?? matchedItem?.nivel_mando ?? (
                  iconKey === 'monito-gerente' || iconKey === 'shield-check' || iconKey === 'crown' ? 1 :
                  iconKey === 'monito-compras' || iconKey === 'monito-ventas' || iconKey === 'monito-produccion' ? 2 : 3
                );

                // Estilos Lumínicos y Bordes Distintivos de un solo color por Jerarquía de Mando (N1 a N5)
                const hierarchyCardStyles: Record<number, { container: string; titleText: string; descText: string; pillClass: string; label: string; watermarkColor: string }> = {
                  1: {
                    container: 'bg-gradient-to-br from-amber-50/90 via-white to-amber-100/30 border-2 border-amber-400 text-slate-900 shadow-md hover:border-amber-500 hover:shadow-xl',
                    titleText: 'text-amber-950 font-black',
                    descText: 'text-slate-600',
                    pillClass: 'bg-amber-100 text-amber-900 border-amber-300 font-extrabold',
                    label: 'N1 • Dirección General',
                    watermarkColor: 'text-amber-500/15'
                  },
                  2: {
                    container: 'bg-gradient-to-br from-indigo-50/80 via-white to-slate-50 border-2 border-indigo-400 text-slate-900 shadow-md hover:border-indigo-500 hover:shadow-xl',
                    titleText: 'text-indigo-950 font-bold',
                    descText: 'text-slate-600',
                    pillClass: 'bg-indigo-100 text-indigo-900 border-indigo-300 font-bold',
                    label: 'N2 • Supervisión / Jefatura',
                    watermarkColor: 'text-indigo-600/15'
                  },
                  3: {
                    container: 'bg-gradient-to-br from-sky-50/70 via-white to-slate-50 border-2 border-sky-400 text-slate-900 shadow-sm hover:border-sky-500 hover:shadow-md',
                    titleText: 'text-slate-900 font-bold',
                    descText: 'text-slate-600',
                    pillClass: 'bg-sky-100 text-sky-900 border-sky-300 font-bold',
                    label: 'N3 • Especialista / Piso',
                    watermarkColor: 'text-sky-600/15'
                  },
                  4: {
                    container: 'bg-gradient-to-br from-emerald-50/50 via-white to-slate-50 border-2 border-emerald-400 text-slate-900 shadow-xs hover:border-emerald-500 hover:shadow-md',
                    titleText: 'text-slate-800 font-bold',
                    descText: 'text-slate-500',
                    pillClass: 'bg-emerald-100 text-emerald-900 border-emerald-300 font-bold',
                    label: 'N4 • Auxiliar Operativo',
                    watermarkColor: 'text-emerald-500/15'
                  },
                  5: {
                    container: 'bg-gradient-to-br from-purple-50/40 via-white to-slate-50 border-2 border-purple-300 text-slate-900 shadow-xs hover:border-purple-400 hover:shadow-md',
                    titleText: 'text-slate-700 font-semibold',
                    descText: 'text-slate-500',
                    pillClass: 'bg-purple-100 text-purple-900 border-purple-300 font-bold',
                    label: 'N5 • Apoyo Eventual',
                    watermarkColor: 'text-purple-400/15'
                  }
                };

                const cardStyle = hierarchyCardStyles[nivel] || hierarchyCardStyles[3];

                return (
                  <div 
                    key={rol.id} 
                    onClick={() => setEditingJobRole({
                      ...rol,
                      icon: rol.icon || 'auto',
                      reports_to_role_ids: rol.reports_to_role_ids || (rol.reports_to_role_id ? [rol.reports_to_role_id] : []),
                      org_parent_role_id: rol.org_parent_role_id || null,
                      nivel_mando: rol.nivel_mando ?? 4
                    })}
                    className={`group relative overflow-hidden rounded-2xl sm:rounded-3xl border p-4 sm:p-5 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl flex flex-col justify-between cursor-pointer ${
                      isAutoActive 
                        ? cardStyle.container 
                        : 'bg-slate-50/80 border-slate-250 text-slate-700 opacity-90'
                    }`}
                  >
                     {/* Marca de Agua (Watermark Vectorial del Monito Alusivo) */}
                     <div className="absolute -right-4 -bottom-4 opacity-15 group-hover:opacity-25 group-hover:scale-110 group-hover:-rotate-6 transition-all duration-500 pointer-events-none">
                        {renderJobRoleIcon(rol, 110, cardStyle.watermarkColor)}
                     </div>

                     <div>
                        {/* Header con Jerarquía, Estado Automático y Botón Tachita (X) Flotante */}
                        <div className="flex items-center justify-between mb-3 relative z-10">
                           <div className="flex items-center gap-1.5 flex-wrap">
                              <span className={`text-[10px] uppercase tracking-wider px-2.5 py-0.5 rounded-full border ${cardStyle.pillClass}`}>
                                 {cardStyle.label}
                              </span>
                              <span className={`text-[10px] font-bold px-2 py-0.5 rounded-full border ${
                                 isAutoActive ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : 'bg-slate-100 text-slate-600 border-slate-200'
                              }`}>
                                 {isAutoActive ? '● Activo' : '○ Inactivo'}
                              </span>
                           </div>

                           {/* Tachita Flotante (X) para Eliminar Puesto */}
                           <button 
                             type="button"
                             onClick={(e) => {
                               e.stopPropagation();
                               handleDeleteJobRole(rol.id);
                             }} 
                             className="w-7 h-7 rounded-full bg-slate-100/90 hover:bg-rose-600 hover:text-white text-slate-400 flex items-center justify-center transition-colors border border-slate-200/80 shadow-2xs group/del shrink-0" 
                             title="Eliminar Puesto"
                           >
                             <X size={14} className="group-hover/del:scale-110 transition-transform"/>
                           </button>
                        </div>

                        {/* Ficha Principal con Puro Monito Icono (Sin recuadro) */}
                        <div className="flex items-center gap-3 relative z-10 mb-2">
                           <div className="shrink-0 transition-transform duration-300 group-hover:scale-115 group-hover:rotate-3">
                              <JobRoleIconBadge role={rol} isActive={isAutoActive} size={36} />
                           </div>
                           <div className="min-w-0 flex-1">
                              <h4 className={`text-base sm:text-lg leading-snug tracking-tight ${cardStyle.titleText}`}>
                                 {rol.name}{getJobRoleKeysIcon(rol.id)}
                              </h4>
                              <div className="flex gap-1.5 mt-1 flex-wrap">
                                 {(rol.area || 'General').split(',').map((s: string) => s.trim()).filter(Boolean).map((a: string) => (
                                     <span key={a} className="text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-100/90 text-slate-700 border border-slate-200">{a}</span>
                                  ))}
                              </div>
                           </div>
                        </div>
                     </div>
                     
                     {/* Footer KPI con números destacados y diseño responsivo móvil */}
                     <div className="flex items-center justify-between pt-3 border-t border-slate-200/80 mt-2 relative z-10 text-xs gap-2">
                        <button 
                          type="button"
                          onClick={(e) => {
                            e.stopPropagation();
                            setSelectedRoleForUsersModal(rol);
                          }} 
                          className="flex items-center gap-1.5 bg-indigo-50/90 hover:bg-indigo-100/90 text-indigo-950 px-2.5 py-1.5 rounded-xl border border-indigo-200/80 transition-all shrink-0 group/stat"
                          title="Ver colaboradores en este puesto"
                        >
                           <Users size={14} className="text-indigo-600 group-hover/stat:scale-110 transition-transform" />
                           <span className="text-[11px] font-bold text-slate-700">Equipo</span>
                           <span className="bg-indigo-600 text-white font-black text-xs px-2 py-0.5 rounded-full shadow-2xs">
                              {employeesWithRole}
                           </span>
                        </button>

                        <button 
                          type="button"
                          onClick={(e) => {
                            e.stopPropagation();
                            setSelectedRoleForVacanciesModal(rol);
                          }}
                          className="flex items-center gap-1.5 bg-sky-50 hover:bg-sky-100 text-sky-950 px-2.5 py-1.5 rounded-xl border border-sky-200 transition-all shrink-0 group/vac"
                          title="Ver vacantes para este puesto"
                        >
                           <ClipboardList size={14} className="text-sky-600 group-hover/vac:scale-110 transition-transform" />
                           <span className="text-[11px] font-bold text-slate-700">Vacantes</span>
                           <span className="bg-sky-600 text-white font-black text-xs px-2 py-0.5 rounded-full shadow-2xs">
                              {roleVacancies.length}
                           </span>
                        </button>
                     </div>
                  </div>
                );
              })}
            </div>
          </div>
        )}

        {activeTab === 'organigrama' && (
          <div>
            <div className="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div>
                <h3 className="text-2xl font-extrabold text-slate-800 flex items-center gap-2">
                  <Network className="text-indigo-600" /> Organigrama de la Empresa
                </h3>
                <p className="text-slate-500 text-sm mt-1">
                  Arrastra y suelta las fichas para reorganizar la estructura jerárquica y los rangos de mando interactivos.
                </p>
              </div>

              {/* Sub-tabs selector */}
              <div className="flex bg-slate-100 p-1 rounded-2xl border border-slate-200/80 w-fit self-start md:self-auto shrink-0 shadow-sm">
                <button
                  onClick={() => setOrgViewMode('tree')}
                  className={`px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-wider transition-all flex items-center gap-1.5 ${
                    orgViewMode === 'tree'
                      ? 'bg-white text-indigo-600 shadow-sm border border-slate-150'
                      : 'text-slate-500 hover:text-slate-800'
                  }`}
                >
                  🌳 Árbol Conectado
                </button>
                <button
                  onClick={() => setOrgViewMode('levels')}
                  className={`px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-wider transition-all flex items-center gap-1.5 ${
                    orgViewMode === 'levels'
                      ? 'bg-white text-indigo-600 shadow-sm border border-slate-150'
                      : 'text-slate-500 hover:text-slate-800'
                  }`}
                >
                  📊 Carriles de Mando
                </button>
              </div>
            </div>

            {orgViewMode === 'tree' ? (
              <OrganigramaPuestos
                jobRoles={jobRoles}
                employees={users}
                readOnly={readOnly}
                onUpdateRole={handleUpdateRoleFromChart}
                onNodeClick={handleNodeClick}
                onCollaboratorDrop={handleCollaboratorRoleDrop}
              />
            ) : (
              <div className="space-y-6">
                {[1, 2, 3, 4, 5, 6].map(level => {
                  const levelInfo = getLevelBadge(level);
                  const levelRoles = jobRoles.filter((r: any) => r.is_active !== false && (r.nivel_mando ?? 4) === level);
                  
                  return (
                    <div 
                      key={level}
                      onDragOver={(e) => {
                        e.preventDefault();
                        e.currentTarget.classList.add('border-indigo-500', 'bg-indigo-50/10');
                      }}
                      onDragLeave={(e) => {
                        e.currentTarget.classList.remove('border-indigo-500', 'bg-indigo-50/10');
                      }}
                      onDrop={async (e) => {
                        e.preventDefault();
                        e.currentTarget.classList.remove('border-indigo-500', 'bg-indigo-50/10');
                        const draggedId = Number(e.dataTransfer.getData('text/plain'));
                        try {
                          const appState = useAppStore.getState();
                          const draggedRole = jobRoles.find((r: any) => r.id === draggedId);
                          if (!draggedRole) return;

                          const updatedRole = {
                            ...draggedRole,
                            nivel_mando: level
                          };

                          if (appState.isSandboxMode) {
                            setJobRoles(jobRoles.map(r => r.id === draggedId ? updatedRole : r));
                            return;
                          }

                          const res = await axiosInstance.put(`/job-roles/${draggedId}`, updatedRole);
                          if (res.status === 200) {
                            await fetchData();
                            window.dispatchEvent(new Event('db_sync_updated'));
                          }
                        } catch (err) {
                          console.error("Error updating level:", err);
                        }
                      }}
                      className="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm hover:shadow-md transition-all duration-300"
                    >
                      <div className="flex items-center gap-3 mb-4 border-b border-slate-100 pb-3">
                        <span className={`px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider border ${levelInfo.bg}`}>
                          {levelInfo.text}
                        </span>
                        <span className="text-xs text-slate-400 font-bold">
                          {levelRoles.length} {levelRoles.length === 1 ? 'puesto' : 'puestos'}
                        </span>
                      </div>
                      
                      <div className="flex flex-wrap gap-4 min-h-[100px] items-center justify-start p-3 bg-slate-50/50 rounded-2xl border border-dashed border-slate-200">
                        {levelRoles.length === 0 ? (
                          <div className="w-full text-center text-xs text-slate-400 italic py-6">
                            Arrastra puestos aquí para asignarlos a este rango
                          </div>
                        ) : (
                          levelRoles.map(role => {
                            const collaborators = users.filter((e: any) => e.is_active_employee !== false && e.job_role_id === role.id);
                            
                            // Highlight relations on hover
                            const isHovered = hoveredRoleId === role.id;
                            const hoveredRole = hoveredRoleId ? jobRoles.find((r: any) => r.id === hoveredRoleId) : null;
                            const isRelatedParent = hoveredRole ? (
                              (hoveredRole.reports_to_role_ids || []).includes(role.id) ||
                              hoveredRole.org_parent_role_id === role.id
                            ) : false;
                            const isRelatedChild = hoveredRoleId ? (
                              (role.reports_to_role_ids || []).includes(hoveredRoleId) ||
                              role.org_parent_role_id === hoveredRoleId
                            ) : false;
                            
                            let borderClass = "border-slate-250";
                            if (isHovered) borderClass = "border-indigo-500 ring-4 ring-indigo-500/10 scale-102 shadow-indigo-100";
                            else if (isRelatedParent) borderClass = "border-emerald-500 ring-4 ring-emerald-500/10 scale-102 shadow-emerald-100";
                            else if (isRelatedChild) borderClass = "border-blue-500 ring-4 ring-blue-500/10 scale-102 shadow-blue-100";

                            return (
                              <div
                                key={role.id}
                                draggable
                                onDragStart={(e) => {
                                  e.dataTransfer.setData('type', 'role');
                                  e.dataTransfer.setData('text/plain', role.id.toString());
                                }}
                                onDragOver={(e) => {
                                  e.preventDefault();
                                  e.currentTarget.classList.add('border-indigo-500', 'ring-4', 'ring-indigo-500/10');
                                }}
                                onDragLeave={(e) => {
                                  e.currentTarget.classList.remove('border-indigo-500', 'ring-4', 'ring-indigo-500/10');
                                }}
                                onDrop={async (e) => {
                                  e.preventDefault();
                                  e.currentTarget.classList.remove('border-indigo-500', 'ring-4', 'ring-indigo-500/10');
                                  const draggedType = e.dataTransfer.getData('type') || 'role';
                                  const draggedId = Number(e.dataTransfer.getData('text/plain'));
                                  if (draggedType === 'collaborator') {
                                    await handleCollaboratorRoleDrop(draggedId, role.id);
                                  } else {
                                    handleRoleDrop(draggedId, role.id);
                                  }
                                }}
                                onMouseEnter={() => setHoveredRoleId(role.id)}
                                onMouseLeave={() => setHoveredRoleId(null)}
                                className={`bg-white border-2 ${borderClass} rounded-2xl p-4 shadow-sm min-w-[220px] max-w-[260px] cursor-grab active:cursor-grabbing transition-all select-none hover:-translate-y-0.5 duration-200`}
                              >
                                <div className="font-black text-xs text-slate-800 uppercase tracking-wider truncate mb-1 flex items-center gap-1">{role.name}{getJobRoleKeysIcon(role.id)}</div>
                                <div className="text-[9px] font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-md inline-block mb-3">{role.area || 'General'}</div>
                                
                                <div className="space-y-1">
                                  {collaborators.length > 0 ? (
                                    collaborators.slice(0, 2).map((c: any) => (
                                      <div 
                                        key={c.id} 
                                        draggable
                                        onDragStart={(e) => {
                                          e.stopPropagation();
                                          e.dataTransfer.setData('type', 'collaborator');
                                          e.dataTransfer.setData('text/plain', c.id.toString());
                                        }}
                                        className="flex items-center gap-1.5 bg-slate-50 p-1.5 rounded-xl border border-slate-100 cursor-grab active:cursor-grabbing hover:bg-indigo-50/50 transition-all duration-200"
                                      >
                                        <img src={c.avatar || `https://api.dicebear.com/7.x/avataaars/svg?seed=${c.name}`} className="w-5 h-5 rounded-full border border-white" alt={c.name} />
                                        <span className="text-[10px] font-bold text-slate-700 truncate">{c.name} {getUserKeysIcon(c.employee_id ? Number(c.employee_id) : Number(c.id))}</span>
                                      </div>
                                    ))
                                  ) : (
                                    <div className="text-[9px] text-slate-400 italic text-center py-1">Vacante</div>
                                  )}
                                  {collaborators.length > 2 && (
                                    <div className="text-[8px] text-indigo-500 font-black text-center">+ {collaborators.length - 2} más</div>
                                  )}
                                </div>
                              </div>
                            );
                          })
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}

            {/* Tree CSS styling auto-injected */}
            <style>{`
              .org-tree ul {
                padding-top: 20px;
                position: relative;
                transition: all 0.3s;
                display: flex;
                justify-content: center;
              }
              .org-tree li {
                text-align: center;
                list-style-type: none;
                position: relative;
                padding: 20px 8px 0 8px;
                transition: all 0.3s;
              }
              .org-tree li::before, .org-tree li::after {
                content: '';
                position: absolute;
                top: 0;
                right: 50%;
                border-top: 2px solid #cbd5e1;
                width: 50%;
                height: 20px;
              }
              .org-tree li::after {
                right: auto;
                left: 50%;
                border-left: 2px solid #cbd5e1;
              }
              .org-tree li:only-child::after, .org-tree li:only-child::before {
                display: none;
              }
              .org-tree li:only-child {
                padding-top: 0;
              }
              .org-tree li:first-child::before, .org-tree li:last-child::after {
                border: 0 none;
              }
              .org-tree li:last-child::before {
                border-right: 2px solid #cbd5e1;
                border-radius: 0 8px 0 0;
              }
              .org-tree li:first-child::after {
                border-radius: 8px 0 0 0;
              }
              .org-tree ul ul::before {
                content: '';
                position: absolute;
                top: 0;
                left: 50%;
                border-left: 2px solid #cbd5e1;
                width: 0;
                height: 20px;
              }
            `}</style>
          </div>
        )}

        {activeTab === 'politicas_reloj' && (
          <div className="flex gap-6 h-full">
            {/* Panel Izquierdo: Puestos */}
            <div className="w-1/3 bg-slate-50 border border-slate-200 rounded-2xl p-4 overflow-y-auto max-h-[600px]">
              <h3 className="text-xl font-bold text-slate-800 mb-4">Jerarquías</h3>
              <div className="flex flex-col gap-3">
                {roleClockPolicies.map((policy: any) => {
                  const roleName = jobRoles.find(r => r.id === policy.job_role_id)?.name || 'Desconocido';
                  const isSelected = selectedRolePolicy?.job_role_id === policy.job_role_id;
                  return (
                    <button
                      key={policy.id}
                      onClick={() => setSelectedRolePolicy({...policy})}
                      className={`text-left p-4 rounded-xl border transition-all ${isSelected ? 'bg-indigo-600 text-white border-indigo-600 shadow-md' : 'bg-white text-slate-700 border-slate-200 hover:border-indigo-300'}`}
                    >
                      <div className="font-bold">{roleName}</div>
                      <div className={`text-xs mt-1 ${isSelected ? 'text-indigo-200' : 'text-slate-500'}`}>
                        {policy.config.tolerancia_retardo_mins}m tolerancia • {policy.config.minutos_comida}m comida
                      </div>
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Panel Derecho: Editor */}
            <div className="w-2/3 bg-white border border-slate-200 rounded-2xl p-6 relative min-h-[500px]">
              {!selectedRolePolicy ? (
                <div className="flex items-center justify-center h-full text-slate-400">
                  Selecciona una jerarquía a la izquierda para editar sus reglas
                </div>
              ) : (
                <div>
                  <div className="flex justify-between items-center mb-6">
                    <div>
                      <h3 className="text-2xl font-bold text-slate-800">Reglas de Reloj</h3>
                      <p className="text-sm text-slate-500">Editando reglas para {jobRoles.find(r => r.id === selectedRolePolicy.job_role_id)?.name}</p>
                    </div>
                    <button onClick={saveRolePolicy} className="bg-emerald-500 hover:bg-emerald-600 text-white px-5 py-2 rounded-xl font-bold transition-colors shadow-md flex items-center gap-2">
                      <span>💾</span> Guardar Cambios
                    </button>
                  </div>
                  
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    {/* Campos Numéricos */}
                    <div className="bg-slate-50 p-4 rounded-xl border border-slate-200">
                      <label className="block text-sm font-bold text-slate-700 mb-2">Tolerancia de Retardo (mins)</label>
                      <input type="number" value={selectedRolePolicy.config.tolerancia_retardo_mins || 0} onChange={e => setSelectedRolePolicy({...selectedRolePolicy, config: {...selectedRolePolicy.config, tolerancia_retardo_mins: Number(e.target.value)}})} className="w-full p-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                    </div>
                    <div className="bg-slate-50 p-4 rounded-xl border border-slate-200">
                      <label className="block text-sm font-bold text-slate-700 mb-2">Minutos de Comida</label>
                      <input type="number" value={selectedRolePolicy.config.minutos_comida || 0} onChange={e => setSelectedRolePolicy({...selectedRolePolicy, config: {...selectedRolePolicy.config, minutos_comida: Number(e.target.value)}})} className="w-full p-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                    </div>

                    {/* Switches */}
                    <div className="bg-slate-50 p-4 rounded-xl border border-slate-200 flex items-center justify-between">
                      <div>
                        <div className="font-bold text-slate-700">Pase de Lista Forzoso</div>
                        <div className="text-xs text-slate-500">¿Debe registrar entrada?</div>
                      </div>
                      <button onClick={() => setSelectedRolePolicy({...selectedRolePolicy, config: {...selectedRolePolicy.config, paseDeLista: !selectedRolePolicy.config.paseDeLista}})} className={`w-14 h-8 rounded-full transition-colors relative ${selectedRolePolicy.config.paseDeLista ? 'bg-indigo-500' : 'bg-slate-300'}`}>
                        <div className={`w-6 h-6 bg-white rounded-full absolute top-1 transition-all ${selectedRolePolicy.config.paseDeLista ? 'right-1' : 'left-1'}`} />
                      </button>
                    </div>

                    <div className="bg-slate-50 p-4 rounded-xl border border-slate-200 flex items-center justify-between">
                      <div>
                        <div className="font-bold text-slate-700">Evaluación de Salida</div>
                        <div className="text-xs text-slate-500">¿Obligar al checklist?</div>
                      </div>
                      <button onClick={() => setSelectedRolePolicy({...selectedRolePolicy, config: {...selectedRolePolicy.config, requiere_evaluacion_salida: !selectedRolePolicy.config.requiere_evaluacion_salida}})} className={`w-14 h-8 rounded-full transition-colors relative ${selectedRolePolicy.config.requiere_evaluacion_salida ? 'bg-indigo-500' : 'bg-slate-300'}`}>
                        <div className={`w-6 h-6 bg-white rounded-full absolute top-1 transition-all ${selectedRolePolicy.config.requiere_evaluacion_salida ? 'right-1' : 'left-1'}`} />
                      </button>
                    </div>

                    <div className="bg-slate-50 p-4 rounded-xl border border-slate-200 flex items-center justify-between">
                      <div>
                        <div className="font-bold text-slate-700">Abrir Sucursal</div>
                        <div className="text-xs text-slate-500">¿Puede iniciar operación?</div>
                      </div>
                      <button onClick={() => setSelectedRolePolicy({...selectedRolePolicy, config: {...selectedRolePolicy.config, puede_abrir_sucursal: !selectedRolePolicy.config.puede_abrir_sucursal}})} className={`w-14 h-8 rounded-full transition-colors relative ${selectedRolePolicy.config.puede_abrir_sucursal ? 'bg-indigo-500' : 'bg-slate-300'}`}>
                        <div className={`w-6 h-6 bg-white rounded-full absolute top-1 transition-all ${selectedRolePolicy.config.puede_abrir_sucursal ? 'right-1' : 'left-1'}`} />
                      </button>
                    </div>

                    <div className="bg-slate-50 p-4 rounded-xl border border-slate-200 flex items-center justify-between">
                      <div>
                        <div className="font-bold text-slate-700">Uso de Kiosko</div>
                        <div className="text-xs text-slate-500">¿Puede usar terminal física?</div>
                      </div>
                      <button onClick={() => setSelectedRolePolicy({...selectedRolePolicy, config: {...selectedRolePolicy.config, puede_usar_kiosko: !selectedRolePolicy.config.puede_usar_kiosko}})} className={`w-14 h-8 rounded-full transition-colors relative ${selectedRolePolicy.config.puede_usar_kiosko ? 'bg-indigo-500' : 'bg-slate-300'}`}>
                        <div className={`w-6 h-6 bg-white rounded-full absolute top-1 transition-all ${selectedRolePolicy.config.puede_usar_kiosko ? 'right-1' : 'left-1'}`} />
                      </button>
                    </div>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}
      </div>

      {/* MODALES DE CONEXION DE PUESTOS */}
      {selectedRoleForUsersModal && (
        <div className="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl p-6 max-w-md w-full shadow-xl relative border border-slate-100 animate-fade-in-up">
            <button 
              type="button"
              onClick={() => setSelectedRoleForUsersModal(null)} 
              className="absolute top-6 right-6 text-slate-400 hover:text-slate-600 bg-slate-50 p-2 rounded-full transition-colors"
            >
              <X size={18}/>
            </button>
            <h3 className="text-xl font-black text-slate-900 mb-4 flex items-center gap-2">
              <Users size={22} className="text-indigo-600" />
              Colaboradores: {selectedRoleForUsersModal.name}
            </h3>
            <div className="space-y-3 max-h-[300px] overflow-y-auto pr-2 custom-scrollbar">
              {users.filter((u: any) => u.job_role_id === selectedRoleForUsersModal.id).length === 0 ? (
                <p className="text-slate-500 text-sm italic text-center py-6">No hay colaboradores asignados a este puesto actualmente.</p>
              ) : (
                users.filter((u: any) => u.job_role_id === selectedRoleForUsersModal.id).map((u: any) => (
                  <div key={u.id} className="flex items-center gap-3 p-2.5 hover:bg-slate-50 rounded-xl transition-colors border border-transparent hover:border-slate-100">
                    <img 
                      src={u.avatar || `https://api.dicebear.com/7.x/avataaars/svg?seed=${u.name}`} 
                      alt={u.name} 
                      className="w-10 h-10 rounded-full border border-slate-200 shrink-0"
                    />
                    <div>
                      <p className="font-bold text-slate-800 text-sm leading-snug">{u.name}</p>
                      <p className="text-slate-500 text-xs">{u.email}</p>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      )}

      {selectedRoleForVacanciesModal && (
        <div className="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl p-6 max-w-lg w-full shadow-xl relative border border-slate-100 animate-fade-in-up">
            <button 
              type="button"
              onClick={() => setSelectedRoleForVacanciesModal(null)} 
              className="absolute top-6 right-6 text-slate-400 hover:text-slate-600 bg-slate-50 p-2 rounded-full transition-colors"
            >
              <X size={18}/>
            </button>
            <h3 className="text-xl font-black text-slate-900 mb-4 flex items-center gap-2">
              <Briefcase size={22} className="text-blue-600" />
              Vacantes: {selectedRoleForVacanciesModal.name}
            </h3>
            <div className="space-y-3 max-h-[300px] overflow-y-auto pr-2 custom-scrollbar">
              {vacancies.filter((v: any) => v.job_role_id === selectedRoleForVacanciesModal.id).length === 0 ? (
                <div className="text-center py-6 text-slate-400">
                  <p className="text-slate-500 text-sm italic mb-2">No hay vacantes activas vinculadas a este puesto.</p>
                  <p className="text-xs">Puedes crearlas en el Tablero ATS.</p>
                </div>
              ) : (
                vacancies.filter((v: any) => v.job_role_id === selectedRoleForVacanciesModal.id).map((v: any) => (
                  <div key={v.id} className="border border-slate-200 p-3 rounded-xl bg-slate-50 hover:bg-white transition-all flex justify-between items-center">
                    <div>
                      <p className="font-bold text-slate-800 text-sm leading-snug">{v.title}</p>
                      <p className="text-slate-500 text-xs mt-1 flex gap-2">
                        <span>{v.work_type || 'Presencial'}</span> • <span>{v.salary_range || 'Sueldo competitivo'}</span>
                      </p>
                    </div>
                    <span className={`text-[10px] font-black px-2 py-0.5 rounded-md uppercase tracking-wider ${v.is_active ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-500'}`}>
                      {v.is_active ? 'Activa' : 'Inactiva'}
                    </span>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      )}

      {/* MODAL EDITAR PUESTO (GLOBAL) */}
      {editingJobRole && (
        <div className="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white rounded-3xl p-8 max-w-2xl w-full shadow-xl relative max-h-[90vh] overflow-y-auto border border-slate-100">
             <button onClick={() => setEditingJobRole(null)} className="absolute top-6 right-6 text-slate-400 hover:text-slate-600 bg-slate-50 p-2 rounded-full"><X size={20}/></button>
             <h2 className="text-2xl font-bold text-slate-900 mb-6">Ficha del Puesto: {editingJobRole.name}</h2>
             
             <div className="flex gap-6 border-b border-slate-200 mb-6">
                <button type="button" onClick={() => setEditingJobRoleTab('perfil')} className={`pb-3 font-medium text-sm flex items-center gap-2 ${editingJobRoleTab==='perfil' ? 'text-blue-600 border-b-2 border-blue-600' : 'text-slate-500 hover:text-slate-700'}`}><FileText size={16}/> Perfil</button>
                <button type="button" onClick={() => setEditingJobRoleTab('reglas')} className={`pb-3 font-medium text-sm flex items-center gap-2 ${editingJobRoleTab==='reglas' ? 'text-blue-600 border-b-2 border-blue-600' : 'text-slate-500 hover:text-slate-700'}`}><Scale size={16}/> Reglas de Negocio</button>
             </div>

             <form onSubmit={handleEditJobRole}>
                {editingJobRoleTab === 'perfil' && (
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                     <div className="col-span-1 sm:col-span-2">
                        <label className="block text-sm font-bold text-slate-600 mb-2">Nombre del Puesto</label>
                        <input type="text" value={editingJobRole.name || ''} onChange={e => setEditingJobRole({...editingJobRole, name: e.target.value})} className="w-full px-4 py-2 border rounded-xl" />
                     </div>
                     <div className="col-span-1 sm:col-span-2 bg-slate-50 p-4 rounded-2xl border border-slate-200">
                        <div className="flex items-center justify-between mb-2">
                           <label className="block text-sm font-bold text-slate-700">Icono Alusivo del Puesto</label>
                           <span className="text-[10px] font-extrabold uppercase px-2 py-0.5 rounded-md bg-indigo-100 text-indigo-700">
                             Monito Alusivo Automático
                           </span>
                        </div>
                        <p className="text-[11px] text-slate-500 mb-3">
                           Se asigna automáticamente el Monito Alusivo según el título e industria de tu empresa:
                        </p>
                        
                        <div className="flex items-center gap-3 bg-white p-3 rounded-xl border border-slate-200 mb-3 shadow-xs">
                           <JobRoleIconBadge role={editingJobRole} iconKey={editingJobRole.icon} size={26} />
                           <div className="flex-1 min-w-0">
                              <div className="text-xs font-bold text-slate-800 truncate">
                                {editingJobRole.name || 'Sin nombre asignado'}
                              </div>
                              <div className="text-[11px] text-slate-500 font-medium truncate">
                                {editingJobRole.icon && editingJobRole.icon !== 'auto'
                                  ? `Icono asignado: ${editingJobRole.icon}`
                                  : `Monito alusivo sugerido automáticamente para este puesto`}
                              </div>
                           </div>
                           {(editingJobRole.icon && editingJobRole.icon !== 'auto') && (
                              <button
                                type="button"
                                onClick={() => setEditingJobRole({ ...editingJobRole, icon: 'auto' })}
                                className="text-xs text-indigo-600 font-bold hover:underline shrink-0"
                              >
                                Restablecer Auto
                              </button>
                           )}
                        </div>

                        {/* Selector colapsable por Giro de Empresa */}
                        <details className="group">
                           <summary className="text-xs font-bold text-indigo-600 cursor-pointer hover:text-indigo-800 select-none flex items-center gap-1">
                              <span>⚙️ Personalizar o explorar catálogo de personajes por Giro de Empresa...</span>
                           </summary>
                           <div className="mt-3 pt-3 border-t border-slate-200">
                              <div className="flex gap-1.5 overflow-x-auto pb-2 mb-2 no-scrollbar">
                                 {[
                                   { id: 'decorarte', label: '🎨 Decorarte 360' },
                                   { id: 'automotriz', label: '🚗 Automotriz / Mecánica' },
                                   { id: 'legal', label: '⚖️ Jurídico & Legal' },
                                   { id: 'salud', label: '🏥 Salud & Clínicas' },
                                   { id: 'construccion', label: '🏗️ Construcción & Obra' },
                                   { id: 'servicios', label: '⚡ Servicios & Mantenimiento' },
                                   { id: 'educacion', label: '🎓 Educación & Colegios' },
                                   { id: 'belleza', label: '💇 Estética & Spa' },
                                   { id: 'retail', label: '🏪 Retail & Tiendas' },
                                   { id: 'oficina', label: '🏢 Oficina & Corp' },
                                   { id: 'tecnologia', label: '💻 Tecnología' },
                                   { id: 'restaurante', label: '🍽️ Restaurantes' },
                                   { id: 'all', label: '🌐 Todos' },
                                 ].map((cat) => (
                                    <button
                                      key={cat.id}
                                      type="button"
                                      onClick={() => setSelectedIndustryFilter(cat.id)}
                                      className={`px-3 py-1 rounded-lg text-[11px] font-bold shrink-0 transition-all ${
                                        selectedIndustryFilter === cat.id
                                          ? 'bg-indigo-600 text-white shadow-xs'
                                          : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100'
                                      }`}
                                    >
                                       {cat.label}
                                    </button>
                                 ))}
                              </div>

                              <div className="grid grid-cols-1 sm:grid-cols-2 gap-1.5 max-h-36 overflow-y-auto pr-1">
                                 {JOB_ROLE_ICON_OPTIONS
                                   .filter(opt => selectedIndustryFilter === 'all' || opt.industry === selectedIndustryFilter || opt.industry === 'all')
                                   .map((opt) => {
                                      const isSelected = (editingJobRole.icon || 'auto') === opt.key;
                                      return (
                                         <button
                                           key={opt.key}
                                           type="button"
                                           onClick={() => setEditingJobRole({ ...editingJobRole, icon: opt.key })}
                                           className={`flex items-center gap-2 px-2.5 py-1.5 rounded-lg text-xs font-semibold border transition-all text-left truncate ${
                                              isSelected
                                                ? 'bg-indigo-600 border-indigo-600 text-white shadow-xs'
                                                : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-100'
                                           }`}
                                         >
                                            <div className="shrink-0">
                                              {renderJobRoleIcon(opt.key === 'auto' ? editingJobRole : opt.key, 16, isSelected ? 'text-white' : 'text-indigo-600')}
                                            </div>
                                            <span className="truncate text-[11px]">{opt.label}</span>
                                         </button>
                                      );
                                   })}
                              </div>
                           </div>
                        </details>
                     </div>
                     <div className="col-span-1 sm:col-span-2 bg-slate-50 p-4 rounded-xl border border-slate-200 flex justify-between items-center">
                        <div>
                           <label className="block text-sm font-bold text-slate-700">Estado del Puesto</label>
                           <span className="text-xs text-slate-500">Determina si este puesto estará disponible para colaboradores y vacantes</span>
                        </div>
                        <button 
                          type="button" 
                          onClick={() => setEditingJobRole({...editingJobRole, is_active: editingJobRole.is_active !== false ? false : true})} 
                          className={`w-14 h-8 rounded-full transition-colors relative ${editingJobRole.is_active !== false ? 'bg-indigo-600' : 'bg-slate-350'}`}
                        >
                          <div className={`w-6 h-6 bg-white rounded-full absolute top-1 transition-all ${editingJobRole.is_active !== false ? 'right-1' : 'left-1'}`} />
                        </button>
                     </div>
                      <div className="col-span-1 sm:col-span-2 bg-slate-50 p-4 rounded-2xl border border-slate-200">
                         <label className="block text-sm font-bold text-slate-700 mb-1">Áreas Organizacionales (Selección Múltiple)</label>
                         <p className="text-[11px] text-slate-500 mb-3">Haz clic en las áreas que pertenecen a este puesto de trabajo:</p>
                         
                         <div className="flex flex-wrap gap-2 mb-3">
                            {(() => {
                               const selectedList = (editingJobRole.area || '').split(',').map((s: string) => s.trim()).filter(Boolean);
                               const allAvailableAreas = Array.from(new Set([
                                  ...uniqueAreas,
                                  ...selectedList
                               ]));
                               
                               if (allAvailableAreas.length === 0) {
                                  return <p className="text-xs text-slate-400 italic">No hay áreas registradas. Crea una a continuación.</p>;
                               }

                               return allAvailableAreas.map((area: string) => {
                                  const isSelected = selectedList.includes(area);
                                  return (
                                     <div key={area} className="relative inline-flex items-center">
                                        <button
                                          type="button"
                                          onClick={() => {
                                             let newList;
                                             if (isSelected) {
                                                newList = selectedList.filter((a: string) => a !== area);
                                             } else {
                                                newList = [...selectedList, area];
                                             }
                                             setEditingJobRole({
                                                ...editingJobRole,
                                                area: newList.join(', ')
                                             });
                                          }}
                                          className={`pl-3 pr-7 py-1.5 rounded-xl text-xs font-semibold border transition-all duration-200 shadow-sm flex items-center gap-1.5 ${
                                             isSelected 
                                             ? 'bg-indigo-600 border-indigo-600 text-white hover:bg-indigo-700' 
                                             : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                                          }`}
                                        >
                                           {area}
                                           {isSelected && <span className="text-[10px] font-bold">✓</span>}
                                        </button>
                                        
                                        <button
                                          type="button"
                                          onClick={(e) => {
                                             e.stopPropagation();
                                             handleRemoveAreaFromRoles(area);
                                          }}
                                          className={`absolute right-1.5 p-0.5 rounded-md transition-colors ${
                                             isSelected 
                                             ? 'text-indigo-200 hover:text-white hover:bg-indigo-500/50' 
                                             : 'text-slate-400 hover:text-rose-600 hover:bg-rose-50'
                                          }`}
                                          title={`Eliminar área "${area}" globalmente`}
                                        >
                                           <Trash2 size={11} />
                                        </button>
                                     </div>
                                  );
                               });
                            })()}
                         </div>

                         <button
                           type="button"
                           onClick={() => {
                              const newArea = prompt("Escribe el nombre de la nueva área organizativa:");
                              if (newArea && newArea.trim()) {
                                 const trimmed = newArea.trim();
                                 const selectedList = (editingJobRole.area || '').split(',').map((s: string) => s.trim()).filter(Boolean);
                                 if (!selectedList.includes(trimmed)) {
                                    const newList = [...selectedList, trimmed];
                                    setEditingJobRole({
                                       ...editingJobRole,
                                       area: newList.join(', ')
                                    });
                                 }
                              }
                           }}
                           className="text-xs text-indigo-600 font-bold hover:text-indigo-800 transition-colors flex items-center gap-1 mt-2 w-fit"
                         >
                            <Plus size={14} /> + Crear nueva área...
                         </button>
                      </div>
                      {/* NOTA (2026-07-21): "Reporta A" y "Puesto Superior en Organigrama (Visual)" se editan
                          ahora directamente desde el organigrama interactivo (pestaña 🌳 Árbol Conectado),
                          arrastrando una línea entre dos puestos — ya no se configuran aquí, para que esta
                          ficha se mantenga simple. Nivel de Mando sí se queda en el modal porque es un
                          atributo del puesto (su rango), no una conexión entre dos puestos. */}
                      <div className="col-span-1 sm:col-span-2 flex items-start gap-2 bg-indigo-50 border border-indigo-100 rounded-xl px-4 py-3 text-xs text-indigo-700 font-semibold">
                         <Network size={16} className="shrink-0 mt-0.5" />
                         <span>Las relaciones de jerarquía ("Reporta A" y la posición en el árbol visual) ahora se configuran arrastrando una línea entre dos puestos directamente en el organigrama interactivo, en la pestaña 🌳 Árbol Conectado.</span>
                      </div>
                       <div className="col-span-1 sm:col-span-2">
                           <label className="block text-sm font-bold text-slate-700 mb-2">Nivel de Mando / Rango de Autoridad</label>
                           <p className="text-[11px] text-slate-500 mb-3">Define la jerarquía absoluta del puesto en la empresa (para ordenamiento y diseño de tarjeta):</p>
                           <select
                             value={editingJobRole.nivel_mando ?? 4}
                             onChange={(e) => {
                               setEditingJobRole({
                                  ...editingJobRole,
                                  nivel_mando: Number(e.target.value)
                               });
                             }}
                             className="w-full px-4 py-2.5 border rounded-xl bg-white text-slate-700 font-semibold focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                           >
                             <option value={1}>Nivel 1 - Alta Dirección (CEO, Gerente)</option>
                             <option value={2}>Nivel 2 - Gerencias de Área / Jefaturas</option>
                             <option value={3}>Nivel 3 - Supervisión / Mandos Medios</option>
                             <option value={4}>Nivel 4 - Personal Operativo / Asesores</option>
                             <option value={5}>Nivel 5 - Auxiliares y Apoyo General</option>
                             <option value={6}>Nivel 6 - Roles Inactivos / Ex-colaboradores</option>
                           </select>
                        </div>
                      <div className="col-span-1 sm:col-span-2">
                        <label className="block text-sm font-bold text-slate-600 mb-2">Descripción General</label>
                        <textarea value={editingJobRole.description || ''} onChange={e => setEditingJobRole({...editingJobRole, description: e.target.value})} className="w-full px-4 py-2 border rounded-xl h-24" />
                     </div>
                     <div className="col-span-1 sm:col-span-2">
                        <label className="block text-sm font-bold text-slate-600 mb-2">Responsabilidades y Equipo Requerido</label>
                        <textarea value={editingJobRole.required_equipment || ''} onChange={e => setEditingJobRole({...editingJobRole, required_equipment: e.target.value})} className="w-full px-4 py-2 border rounded-xl h-24" placeholder="Ej. Computadora, Gafete, Llaves de caja..." />
                     </div>
                  </div>
                )}

                {editingJobRoleTab === 'reglas' && (
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                     <div>
                        <label className="block text-sm font-bold text-slate-600 mb-2">Minutos de Tolerancia</label>
                        <input type="number" value={editingJobRole.tiempoTolerancia || 10} onChange={e => setEditingJobRole({...editingJobRole, tiempoTolerancia: Number(e.target.value)})} className="w-full px-4 py-2 border rounded-xl" />
                     </div>
                     <div>
                        <label className="block text-sm font-bold text-slate-600 mb-2">Multiplicador Retardo</label>
                        <input type="number" step="0.1" value={editingJobRole.late_penalty_multiplier || 1} onChange={e => setEditingJobRole({...editingJobRole, late_penalty_multiplier: Number(e.target.value)})} className="w-full px-4 py-2 border rounded-xl" title="Ej. 1 = un minuto descontado por minuto tarde." />
                     </div>
                     <div className="col-span-1 sm:col-span-2">
                        <label className="block text-sm font-bold text-slate-600 mb-2">Portador de Llaves Físicas</label>
                        <select value={editingJobRole.portadorLlaves || 'ninguno'} onChange={e => setEditingJobRole({...editingJobRole, portadorLlaves: e.target.value})} className="w-full px-4 py-2 border rounded-xl">
                           <option value="ninguno">Ninguno</option>
                           <option value="apertura">Solo Apertura</option>
                           <option value="cierre">Solo Cierre</option>
                           <option value="ambos">Apertura y Cierre</option>
                        </select>
                     </div>
                     <div className="col-span-1 sm:col-span-2 mt-4 space-y-2">
                        <label className="flex items-center gap-2 cursor-pointer">
                           <input type="checkbox" checked={editingJobRole.requiereJustificante} onChange={e => setEditingJobRole({...editingJobRole, requiereJustificante: e.target.checked})} className="w-5 h-5 text-indigo-600 rounded" />
                           <span className="text-slate-700 font-bold">Inasistencias requieren Justificante Médico/Oficial</span>
                        </label>
                        <label className="flex items-center gap-2 cursor-pointer">
                           <input type="checkbox" checked={editingJobRole.puedeEmitirAvisos} onChange={e => setEditingJobRole({...editingJobRole, puedeEmitirAvisos: e.target.checked})} className="w-5 h-5 text-indigo-600 rounded" />
                           <span className="text-slate-700 font-bold">Autorizado para emitir Avisos Globales en Checador</span>
                        </label>
                        <label className="flex items-center gap-2 cursor-pointer">
                           <input type="checkbox" checked={editingJobRole.aplicaLeySilla} onChange={e => setEditingJobRole({...editingJobRole, aplicaLeySilla: e.target.checked})} className="w-5 h-5 text-indigo-600 rounded" />
                           <span className="text-slate-700 font-bold">Aplica 'Ley Silla' (Descansos intermedios obligatorios)</span>
                        </label>
                     </div>
                  </div>
                )}

                <div className="mt-8 flex gap-4">
                   <button type="button" onClick={() => setEditingJobRole(null)} className="flex-1 bg-slate-100 text-slate-700 font-bold py-3 rounded-xl hover:bg-slate-200">Cancelar</button>
                   <button type="submit" className="flex-1 bg-indigo-600 text-white font-bold py-3 rounded-xl hover:bg-indigo-700">Guardar Ficha</button>
                </div>
             </form>
          </div>
        </div>
      )}

      {/* MODAL IMPORTAR DESDE PLANTILLAS */}
      {showTemplateModal && (
        <div className="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 flex items-center justify-center p-4 animate-fade-in">
          <div className="bg-white rounded-3xl p-8 max-w-2xl w-full shadow-xl relative max-h-[90vh] flex flex-col border border-slate-100 animate-slide-up">
            <button 
              type="button"
              onClick={() => { setShowTemplateModal(false); setSelectedTemplate(null); }} 
              className="absolute top-6 right-6 text-slate-400 hover:text-slate-600 bg-slate-50 p-2 rounded-full transition-colors"
            >
              <X size={20}/>
            </button>
            
            <h2 className="text-2xl font-black text-slate-900 mb-2 flex items-center gap-2">
              <ClipboardList className="text-blue-600" />
              Importar desde Plantillas Globales
            </h2>
            <p className="text-slate-500 text-sm mb-6">Selecciona una plantilla del sistema para crear automáticamente el puesto en tu catálogo local.</p>
            
            {/* FILTRO DE INDUSTRIA */}
            <div className="mb-6 flex items-center gap-3">
              <label className="text-sm font-bold text-slate-600 shrink-0">Filtrar por Industria:</label>
              <select 
                value={templateIndustryFilter} 
                onChange={e => setTemplateIndustryFilter(e.target.value)}
                className="bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 text-sm font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all cursor-pointer"
              >
                <option value="">Todas las Industrias</option>
                <option value="retail">Retail</option>
                <option value="oficina">Oficina</option>
                <option value="restaurante">Restaurante</option>
                <option value="manufactura">Manufactura</option>
                <option value="salud">Salud</option>
                <option value="educacion">Educación</option>
              </select>
            </div>
            
            {/* LISTA DE PLANTILLAS */}
            <div className="flex-1 overflow-y-auto min-h-[250px] max-h-[400px] border border-slate-200 rounded-2xl p-4 bg-slate-50/50 space-y-2.5 custom-scrollbar mb-6">
              {loadingTemplates ? (
                <div className="h-full flex items-center justify-center py-12">
                  <div className="animate-spin text-blue-600 font-bold">Cargando plantillas...</div>
                </div>
              ) : templates.length === 0 ? (
                <div className="text-center py-12 text-slate-400 font-bold animate-pulse">
                  No se encontraron plantillas para esta industria.
                </div>
              ) : (
                templates.map((tpl: any) => (
                  <div 
                    key={tpl.id}
                    onClick={() => setSelectedTemplate(tpl)}
                    className={`p-4 border rounded-xl cursor-pointer transition-all flex justify-between items-center ${
                      selectedTemplate?.id === tpl.id 
                        ? 'border-blue-500 bg-blue-50/50 shadow-sm' 
                        : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-sm'
                    }`}
                  >
                    <div>
                      <h4 className="font-bold text-slate-800 text-base">{tpl.name}</h4>
                      <p className="text-slate-500 text-xs font-semibold mt-0.5">{tpl.area} &bull; <span className="uppercase tracking-wider text-[10px] bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded-md font-bold">{tpl.industry}</span></p>
                      
                      <div className="flex gap-4 mt-2 text-xs text-slate-600 font-medium bg-slate-50 p-2 rounded-lg border border-slate-100">
                        <span>Horario: {tpl.default_schedule_start} - {tpl.default_schedule_end}</span>
                        <span>Comida: {tpl.default_meal_mins} min</span>
                        <span>Tolerancia: {tpl.default_tolerance_mins} min</span>
                      </div>
                    </div>
                    
                    <div className="flex items-center gap-2">
                      {tpl.is_opener === 1 || tpl.is_opener === true ? (
                        <span className="text-[10px] bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 rounded-lg font-bold">
                          Aperturador
                        </span>
                      ) : null}
                      <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center transition-all ${
                        selectedTemplate?.id === tpl.id ? 'border-blue-600 bg-blue-600' : 'border-slate-300'
                      }`}>
                        {selectedTemplate?.id === tpl.id && <div className="w-2 h-2 bg-white rounded-full" />}
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>
            
            {/* BOTONES ACCION */}
            <div className="flex gap-4 border-t border-slate-100 pt-6">
              <button 
                type="button" 
                onClick={() => { setShowTemplateModal(false); setSelectedTemplate(null); }} 
                className="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-3 rounded-xl transition-all"
                disabled={importingTemplate}
              >
                Cancelar
              </button>
              <button 
                type="button" 
                onClick={handleImportTemplate} 
                disabled={!selectedTemplate || importingTemplate}
                className={`flex-1 text-white font-bold py-3 rounded-xl transition-all flex items-center justify-center gap-2 ${
                  !selectedTemplate || importingTemplate
                    ? 'bg-slate-300 cursor-not-allowed'
                    : 'bg-blue-600 hover:bg-blue-700 shadow-md'
                }`}
              >
                {importingTemplate ? 'Importando...' : 'Confirmar e Importar'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL DE CAMBIO DE PLAN / UPGRADE */}
      {showUpgradeModal && (
        <div className="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-[100] flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-lg rounded-3xl overflow-hidden shadow-2xl relative border border-slate-100 animate-in zoom-in-95">
            <button 
              onClick={() => setShowUpgradeModal(false)} 
              className="absolute top-4 right-4 w-8 h-8 flex items-center justify-center bg-slate-100 text-slate-500 hover:text-slate-800 rounded-full transition-colors z-10"
            >
              <X size={18} />
            </button>
            
            <div className="bg-gradient-to-br from-slate-900 to-indigo-950 p-8 text-center relative overflow-hidden">
              <div className="absolute top-0 right-0 w-64 h-64 bg-indigo-500/20 rounded-full blur-[80px]"></div>
              <div className="relative z-10">
                <div className="w-16 h-16 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-2xl mx-auto flex items-center justify-center text-white mb-4 shadow-xl transform rotate-3">
                  <Zap size={28} className="fill-current" />
                </div>
                <h2 className="text-xl font-black text-white mb-1">Actualizar Suscripción</h2>
                <p className="text-indigo-200 font-medium text-xs">Límite de Cuentas Administrativas Excedido</p>
              </div>
            </div>

            <div className="p-8">
              <p className="text-slate-600 text-sm leading-relaxed mb-6 text-center font-sans">
                {upgradeModalMessage || "Has alcanzado el límite máximo de cuentas administrativas permitido en tu plan de suscripción actual."}
              </p>

              <div className="bg-indigo-50 border border-indigo-100 rounded-2xl p-4 mb-6 text-left">
                <h4 className="font-bold text-indigo-900 text-xs uppercase tracking-wider mb-2 flex items-center gap-1.5">
                  <Sparkles size={14} /> Ventajas del Plan Profesional (Pro):
                </h4>
                <ul className="space-y-2 text-xs text-indigo-800 font-medium list-disc pl-4 leading-normal">
                  <li>Soporte para hasta <strong>3 cuentas administrativas</strong> (dueño y 2 supervisores).</li>
                  <li>Desbloqueo completo de todos los módulos del panel de control web.</li>
                  <li>Límite de empleados ampliado y soporte técnico premium.</li>
                </ul>
              </div>

              <div className="flex gap-3">
                <button 
                  onClick={() => setShowUpgradeModal(false)} 
                  className="flex-1 py-3 px-4 rounded-xl font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition-colors text-xs"
                >
                  Regresar
                </button>
                <button 
                  onClick={async () => {
                    setShowUpgradeModal(false);
                    await handleUpgradePlan();
                  }} 
                  className="flex-1 py-3 px-4 rounded-xl font-black text-white bg-indigo-600 hover:bg-indigo-700 shadow-lg shadow-indigo-600/30 transition-all flex items-center justify-center gap-2 text-xs"
                >
                  <Zap size={14} className="fill-current" /> Mejorar Plan
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Botón de Acción Flotante (FAB) Responsivo */}
      <div className="fixed bottom-6 right-6 z-40 block sm:hidden">
        {showFabMenu && (
          <div className="flex flex-col items-center gap-3.5 mb-3.5">
            {activeTab === 'directorio' && (
              <>
                {directorioSubTab === 'activos' && (
                  <button
                    onClick={() => {
                      setShowForm(true);
                      setShowFabMenu(false);
                    }}
                    className="w-12 h-12 bg-emerald-500 hover:bg-emerald-600 text-white rounded-full flex items-center justify-center shadow-lg shadow-emerald-500/20 active:scale-90 transition-all duration-300 animate-fade-in-up-1"
                  >
                    <UserPlus size={20} />
                  </button>
                )}
                <button
                  onClick={() => {
                    setShowMobileSearch(!showMobileSearch);
                    setTimeout(() => searchInputRef.current?.focus(), 300);
                    setShowFabMenu(false);
                  }}
                  className="w-12 h-12 bg-white hover:bg-slate-50 text-slate-600 border border-slate-200 rounded-full flex items-center justify-center shadow-lg active:scale-90 transition-all duration-300 animate-fade-in-up-2"
                >
                  <Search size={20} />
                </button>
              </>
            )}
            {activeTab === 'puestos' && (
              <>
                <button
                  onClick={() => {
                    handleCreateJobRoleClick();
                    setShowFabMenu(false);
                  }}
                  className="w-12 h-12 bg-blue-600 hover:bg-blue-700 text-white rounded-full flex items-center justify-center shadow-lg shadow-blue-600/20 active:scale-90 transition-all duration-300 animate-fade-in-up-1"
                >
                  <Plus size={20} />
                </button>
                <button
                  onClick={() => {
                    setShowTemplateModal(true);
                    setShowFabMenu(false);
                  }}
                  className="w-12 h-12 bg-white hover:bg-slate-50 text-amber-500 border border-slate-200 rounded-full flex items-center justify-center shadow-lg active:scale-90 transition-all duration-300 animate-fade-in-up-2"
                >
                  <Sparkles size={20} />
                </button>
              </>
            )}
            {activeTab === 'organigrama' && (
              <button
                onClick={() => {
                  setActiveTab('directorio');
                  setShowFabMenu(false);
                }}
                className="w-12 h-12 bg-white hover:bg-slate-50 text-slate-600 border border-slate-200 rounded-full flex items-center justify-center shadow-lg active:scale-90 transition-all duration-300 animate-fade-in-up-1"
              >
                <Users size={20} />
              </button>
            )}
          </div>
        )}
        <button 
          onClick={() => setShowFabMenu(!showFabMenu)}
          className="w-14 h-14 bg-blue-600 hover:bg-blue-700 text-white rounded-full flex items-center justify-center shadow-lg shadow-blue-600/35 transition-transform active:scale-95 z-50 relative"
        >
          <Plus size={24} className={`transition-transform duration-300 ${showFabMenu ? 'rotate-45' : ''}`} />
        </button>
      </div>

      {/* Drawer lateral de Detalles del Puesto */}
      {isRoleDrawerOpen && selectedRoleForDrawer && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex justify-end">
          <div className="bg-white dark:bg-slate-950 w-full max-w-lg h-full flex flex-col shadow-2xl animate-slide-in-right overflow-hidden">
            {/* Header del Drawer */}
            <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50 dark:bg-slate-900">
              <div className="flex items-center gap-3">
                <div className="p-2.5 rounded-2xl bg-indigo-50 dark:bg-indigo-950/40 text-indigo-650">
                  <Briefcase size={20} />
                </div>
                <div>
                  <h3 className="font-extrabold text-base text-slate-850 dark:text-slate-100 uppercase tracking-wider truncate max-w-[280px]">
                    {selectedRoleForDrawer.name}
                  </h3>
                  <span className="text-[10px] font-bold text-slate-400 bg-slate-200/60 dark:bg-slate-800 px-2 py-0.5 rounded-md inline-block mt-0.5">
                    {selectedRoleForDrawer.area || 'Área General'}
                  </span>
                </div>
              </div>
              <button 
                onClick={() => setIsRoleDrawerOpen(false)}
                className="p-2 rounded-full hover:bg-slate-200 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 transition-colors"
              >
                <X size={20} />
              </button>
            </div>

            {/* Contenido del Drawer */}
            <div className="flex-1 overflow-y-auto p-6 space-y-6">
              {readOnly ? (
                /* VISTA EMPLEADO / SÓLO LECTURA */
                <div className="space-y-6">
                  <div>
                    <h4 className="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Descripción General del Puesto</h4>
                    <p className="text-sm font-medium text-slate-700 dark:text-slate-350 leading-relaxed bg-slate-50 dark:bg-slate-900/40 p-4 rounded-2xl border border-slate-100 dark:border-slate-800">
                      {selectedRoleForDrawer.description || 'Sin descripción asignada.'}
                    </p>
                  </div>

                  <div>
                    <h4 className="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Responsabilidades y Funciones</h4>
                    <p className="text-sm font-medium text-slate-700 dark:text-slate-350 leading-relaxed bg-slate-50 dark:bg-slate-900/40 p-4 rounded-2xl border border-slate-100 dark:border-slate-800 whitespace-pre-line">
                      {selectedRoleForDrawer.responsibilities || 'Sin responsabilidades listadas.'}
                    </p>
                  </div>

                  <div>
                    <h4 className="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">Puesto al que Reporta (Jefe Directo)</h4>
                    <div className="flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-slate-300">
                      <span className="p-1 rounded bg-slate-100 dark:bg-slate-800 text-indigo-500">🏢</span>
                      {jobRoles.find(r => r.id === selectedRoleForDrawer.org_parent_role_id)?.name || 'Directores / Asamblea'}
                    </div>
                  </div>

                  {selectedRoleForDrawer.manual_name ? (
                    <div className="bg-emerald-50/60 dark:bg-emerald-950/20 border border-emerald-100 dark:border-emerald-900 p-5 rounded-2xl">
                      <h4 className="text-xs font-black text-emerald-800 dark:text-emerald-300 flex items-center gap-2 mb-2">
                        <span>📄</span> Protocolo Documental Asociado
                      </h4>
                      <p className="text-[11px] text-slate-500 dark:text-slate-400 mb-4 truncate">
                        Archivo: {selectedRoleForDrawer.manual_name}
                      </p>
                      <a 
                        href={selectedRoleForDrawer.manual_url || `/storage/manuals/${selectedRoleForDrawer.manual_name}`}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center justify-center gap-2 bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-xs uppercase tracking-wider px-5 py-3 rounded-xl transition-all shadow-md active:scale-95 text-center w-full"
                      >
                        Visualizar / Descargar PDF
                      </a>
                    </div>
                  ) : (
                    <div className="bg-slate-50 dark:bg-slate-900/40 border border-dashed border-slate-200 dark:border-slate-800 p-4 rounded-2xl text-center text-xs text-slate-400 font-bold italic">
                      No hay manuales de inducción o protocolos PDF asignados a este puesto.
                    </div>
                  )}
                </div>
              ) : (
                /* VISTA ADMINISTRATIVO / EDICIÓN */
                <div className="space-y-5">
                  <div>
                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Descripción General del Puesto</label>
                    <textarea
                      rows={3}
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white text-slate-800"
                      value={drawerDescription}
                      onChange={e => setDrawerDescription(e.target.value)}
                      placeholder="Ej. Encargado de liderar el equipo de ventas..."
                    />
                  </div>

                  <div>
                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Responsabilidades detalladas</label>
                    <textarea
                      rows={5}
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white text-slate-800 whitespace-pre-line"
                      value={drawerResponsibilities}
                      onChange={e => setDrawerResponsibilities(e.target.value)}
                      placeholder="Ingresa cada responsabilidad en una línea nueva..."
                    />
                  </div>

                  <div>
                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Puesto Jerárquico Superior (Reporta a)</label>
                    <select
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white text-slate-800"
                      value={drawerParentId}
                      onChange={e => setDrawerParentId(e.target.value === '' ? '' : Number(e.target.value))}
                    >
                      <option value="">-- Ninguno (Puesto Dirección Raíz) --</option>
                      {jobRoles
                        .filter(r => r.id !== selectedRoleForDrawer.id)
                        .map(r => (
                          <option key={r.id} value={r.id}>{r.name} ({r.area})</option>
                        ))
                      }
                    </select>
                  </div>

                  <div className="border-t border-slate-100 pt-5">
                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Asociar Protocolo PDF</label>
                    <div className="space-y-3">
                      <div>
                        <span className="text-[10px] text-slate-400 block mb-1">Nombre del Archivo PDF</span>
                        <input
                          type="text"
                          className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white text-slate-800"
                          value={drawerManualName}
                          onChange={e => setDrawerManualName(e.target.value)}
                          placeholder="Ej. manual_ventas_decorarte.pdf"
                        />
                      </div>
                      <div>
                        <span className="text-[10px] text-slate-400 block mb-1">URL / Ruta de Descarga</span>
                        <input
                          type="text"
                          className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white text-slate-800"
                          value={drawerManualUrl}
                          onChange={e => setDrawerManualUrl(e.target.value)}
                          placeholder="Dejar vacío para usar ruta por defecto (/storage/manuals/...)"
                        />
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </div>

            {/* Footer del Drawer */}
            {!readOnly && (
              <div className="p-6 border-t border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-900 flex justify-end gap-3">
                <button
                  onClick={() => setIsRoleDrawerOpen(false)}
                  className="px-5 py-3 rounded-xl border border-slate-200 dark:border-slate-800 text-xs font-bold uppercase tracking-wider text-slate-500 hover:text-slate-700 transition-colors"
                >
                  Cancelar
                </button>
                <button
                  disabled={isSavingDrawer}
                  onClick={saveDrawerRole}
                  className="px-6 py-3 rounded-xl bg-indigo-650 hover:bg-indigo-700 disabled:bg-slate-300 text-white text-xs font-bold uppercase tracking-wider transition-colors shadow-md shadow-indigo-650/15"
                >
                  {isSavingDrawer ? 'Guardando...' : 'Guardar Puesto'}
                </button>
              </div>
            )}
          </div>
        </div>
      )}

    </div>
  );
}
