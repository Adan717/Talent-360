import React, { useState, useEffect } from 'react';
import { Settings, Clock, Coffee, ListTodo, Users, Save, CheckCircle2, Building2, Key, ArrowUp, ArrowDown, Trash2, Plus, Briefcase, GraduationCap, FileText, FileSpreadsheet, Receipt } from 'lucide-react';
import { useAppStore } from '../store/useAppStore';
import axiosInstance from '../lib/axios';


export const CompanySettingsPanel = ({ initialTab = 'general', hideSidebar = false }: { initialTab?: string; hideSidebar?: boolean }) => {
  const { systemSettings, updateSetting } = useAppStore();
  const currentTier = useAppStore(state => state.currentTier);
  const [activeTab, setActiveTab] = useState(initialTab);
  const [isSaving, setIsSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    setActiveTab(initialTab);
  }, [initialTab]);
  
  // Estado local para los formularios antes de guardar
  const [formData, setFormData] = useState<any>({});

  // Estados para Apertura de Tienda
  const [openingSettings, setOpeningSettings] = useState<any>({
    is_enabled: true,
    pre_opening_window_minutes: 15,
    absence_late_report_window_minutes: 5,
    early_clock_in_allowed_minutes: 10,
    allow_automatic_handoff: true,
    allow_late_if_before_opening: true,
    allow_store_closed_report: true,
    enable_amnesty_if_store_closed: true,
    require_opening_checklist: true,
    require_opening_roll_call: true,
    notify_admin_on_handoff: true,
    notify_supervisor_on_handoff: true,
    require_key_delegation_on_rest: true,
  });
  const [assignments, setAssignments] = useState<any[]>([]);
  const [selectedUserForAss, setSelectedUserForAss] = useState('');
  const isSandbox = useAppStore(state => state.isSandboxMode);
  const globalUsers = useAppStore(state => state.globalUsers);

  useEffect(() => {
    if (activeTab === 'apertura') {
      const loadAperturaData = async () => {
        if (isSandbox) {
          const localSettings = localStorage.getItem('store_opening_settings');
          if (localSettings) {
            setOpeningSettings({ require_key_delegation_on_rest: true, ...JSON.parse(localSettings) });
          }
          const localAss = localStorage.getItem('store_opening_assignments');
          if (localAss) {
            setAssignments(JSON.parse(localAss));
          } else {
            const defaultAss = [
              { id: 1, employee_id: 1, priority_order: 1, can_open_store: true, can_close_store: true, has_keys: true, is_active: true, employee: globalUsers.find(u => u.id === 1) || { name: 'Francisco', role: 'Administrador / Gerente' } },
              { id: 2, employee_id: 2, priority_order: 2, can_open_store: true, can_close_store: true, has_keys: true, is_active: true, employee: globalUsers.find(u => u.id === 2) || { name: 'Liz', role: 'Sup. Tienda y Compras' } },
              { id: 3, employee_id: 3, priority_order: 3, can_open_store: true, can_close_store: true, has_keys: true, is_active: true, employee: globalUsers.find(u => u.id === 3) || { name: 'Joseline', role: 'Sup. Cajas' } },
            ];
            setAssignments(defaultAss);
            localStorage.setItem('store_opening_assignments', JSON.stringify(defaultAss));
          }
        } else {
          try {
            const settingsRes = await axiosInstance.get('/store-opening/settings');
            setOpeningSettings({ require_key_delegation_on_rest: true, ...settingsRes.data });
            const assRes = await axiosInstance.get('/store-opening/assignments');
            setAssignments(assRes.data);
            localStorage.setItem('store_opening_assignments', JSON.stringify(assRes.data));
          } catch (e) {
            console.error("Error loading opening settings:", e);
          }
        }
      };
      loadAperturaData();
    }
  }, [activeTab, isSandbox, globalUsers]);

  const handleSaveSettings = async () => {
    setIsSaving(true);
    setSaved(false);
    try {
      if (isSandbox) {
        localStorage.setItem('store_opening_settings', JSON.stringify(openingSettings));
      } else {
        await axiosInstance.put('/store-opening/settings', openingSettings);
      }
      setSaved(true);
    } catch (error) {
      console.error("Error al guardar ajustes de apertura:", error);
    } finally {
      setIsSaving(false);
      setTimeout(() => setSaved(false), 3000);
    }
  };

  const handleAddAssignment = async () => {
    if (!selectedUserForAss) return;
    const userId = parseInt(selectedUserForAss);
    const userObj = globalUsers.find(u => u.id === userId);
    if (!userObj) return;

    const nextOrder = assignments.length > 0 ? Math.max(...assignments.map(a => a.priority_order)) + 1 : 1;

    if (isSandbox) {
      const newAss = {
        id: Date.now(),
        employee_id: userId,
        priority_order: nextOrder,
        can_open_store: true,
        can_close_store: true,
        has_keys: true,
        is_active: true,
        employee: { id: userObj.id, name: userObj.name, email: userObj.email, role: userObj.role || 'empleado' }
      };
      const updated = [...assignments, newAss];
      setAssignments(updated);
      localStorage.setItem('store_opening_assignments', JSON.stringify(updated));
    } else {
      try {
        const res = await axiosInstance.post('/store-opening/assignments', {
          employee_id: userId,
          priority_order: nextOrder,
          can_open_store: true,
          can_close_store: true,
          has_keys: true,
          is_active: true
        });
        setAssignments([...assignments, res.data.assignment]);
      } catch (e) {
        console.error(e);
      }
    }
    setSelectedUserForAss('');
  };

  const handleDeleteAssignment = async (id: any) => {
    if (isSandbox) {
      const updated = assignments.filter(a => a.id !== id);
      setAssignments(updated);
      localStorage.setItem('store_opening_assignments', JSON.stringify(updated));
    } else {
      try {
        await axiosInstance.delete(`/store-opening/assignments/${id}`);
        setAssignments(assignments.filter(a => a.id !== id));
      } catch (e) {
        console.error(e);
      }
    }
  };

  const handleMovePriority = async (index: number, direction: 'up' | 'down') => {
    if (direction === 'up' && index === 0) return;
    if (direction === 'down' && index === assignments.length - 1) return;

    const targetIndex = direction === 'up' ? index - 1 : index + 1;
    const listCopy = [...assignments];
    const temp = listCopy[index];
    listCopy[index] = listCopy[targetIndex];
    listCopy[targetIndex] = temp;

    const updated = listCopy.map((item, idx) => ({
      ...item,
      priority_order: idx + 1
    }));

    setAssignments(updated);

    if (isSandbox) {
      localStorage.setItem('store_opening_assignments', JSON.stringify(updated));
    } else {
      try {
        for (const item of updated) {
          await axiosInstance.put(`/store-opening/assignments/${item.id}`, {
            priority_order: item.priority_order,
            can_open_store: item.can_open_store,
            can_close_store: item.can_close_store,
            has_keys: item.has_keys,
            is_active: item.is_active
          });
        }
      } catch (e) {
        console.error(e);
      }
    }
  };

  const handleToggleAssignmentField = async (id: any, field: string) => {
    const updated = assignments.map(a => {
      if (a.id === id) {
        const newVal = !a[field];
        if (!isSandbox) {
          axiosInstance.put(`/store-opening/assignments/${id}`, {
            ...a,
            [field]: newVal
          }).catch(e => console.error(e));
        }
        return { ...a, [field]: newVal };
      }
      return a;
    });

    setAssignments(updated);
    if (isSandbox) {
      localStorage.setItem('store_opening_assignments', JSON.stringify(updated));
    }
  };

  // Cargar estado inicial
  useEffect(() => {
    if (systemSettings) {
      setFormData({
        company_name: systemSettings.company_name || 'Mi Sucursal Talent360',
        company_address: systemSettings.company_address || '',
        company_phone: systemSettings.company_phone || '',
        storeSchedule: systemSettings.storeSchedule || { openTime: '08:00', closeTime: '18:00' },
        leySillaConfig: systemSettings.leySillaConfig || { enabled: true, consecutiveMinutes: 120, breakMinutes: 15 },
        mealSettings: {
          maxChairs: 4,
          startHour: 12,
          endHour: 17,
          preventRoleOverlap: true,
          mealStartToleranceMins: 5,
          ...(systemSettings.mealSettings || {})
        },
        timeBankConfigs: systemSettings.timeBankConfigs || { maxLateMinsAllowed: 15 },
        onboarding: systemSettings.onboarding || {
          welcomeTitle: '¡Bienvenido al Equipo!',
          welcomeMessage: 'Estamos muy emocionados de que te unas a nosotros.',
          inviteLinkMode: 'whatsapp'
        },
        tasksConfig: systemSettings.tasksConfig || {
          requireSupervisorValidation: true,
          validationThreshold: 'all_tasks',
          allowTaskRejection: true,
          aiAutoApproveIfValid: false
        },
        atsConfig: systemSettings.atsConfig || {
          defaultInterviewer: 'Lic. Francisco',
          notificationChannel: 'whatsapp',
          autoPublish: true
        },
        academyConfig: systemSettings.academyConfig || {
          minPassingGrade: 80,
          allowSelfEnrollment: true,
          certificateSignatures: {
            directorName: 'Francisco',
            instructorName: 'Liz'
          }
        },
        docsConfig: systemSettings.docsConfig || {
          maxFileSizeMb: 10,
          allowCollabUpload: true,
          requiredDocs: ['ine', 'rfc', 'curp']
        },
        reportsConfig: systemSettings.reportsConfig || {
          defaultFormat: 'xlsx',
          payrollPeriod: 'quincenal',
          sendDailySummary: false
        },
        clockOpConfig: systemSettings.clockOpConfig || {
          arrivalWindowMins: 30,
          storeClosedReportDelayMins: 0,
          requireExitEvaluation: true,
          allowManualCheckIn: false,
          gpsValidationEnabled: true
        }
      });
    }
  }, [systemSettings]);

  const handleSave = async (section?: string) => {
    setIsSaving(true);
    setSaved(false);
    try {
      if (activeTab === 'general') {
        await updateSetting('company_name', formData.company_name);
        await updateSetting('company_address', formData.company_address);
        await updateSetting('company_phone', formData.company_phone);
        await updateSetting('storeSchedule', formData.storeSchedule);
      } else if (activeTab === 'reloj' || section === 'timeBankConfigs' || section === 'leySillaConfig') {
        await updateSetting('leySillaConfig', formData.leySillaConfig);
        await updateSetting('timeBankConfigs', formData.timeBankConfigs);
        await updateSetting('storeSchedule', formData.storeSchedule); // Guardar también el horario de la sucursal
      } else if (activeTab === 'comidas' || section === 'mealSettings') {
        await updateSetting('mealSettings', formData.mealSettings);
      } else if (activeTab === 'onboarding' || section === 'onboarding') {
        await updateSetting('onboarding', formData.onboarding);
      } else if (activeTab === 'tareas' || section === 'tasksConfig') {
        await updateSetting('tasksConfig', formData.tasksConfig);
      } else if (activeTab === 'ats' || section === 'atsConfig') {
        await updateSetting('atsConfig', formData.atsConfig);
      } else if (activeTab === 'academia' || section === 'academyConfig') {
        await updateSetting('academyConfig', formData.academyConfig);
      } else if (activeTab === 'documentos' || section === 'docsConfig') {
        await updateSetting('docsConfig', formData.docsConfig);
      } else if (activeTab === 'reportes' || section === 'reportsConfig') {
        await updateSetting('reportsConfig', formData.reportsConfig);
      } else if (activeTab === 'reloj_operacion' || section === 'clockOpConfig') {
        await updateSetting('clockOpConfig', formData.clockOpConfig);
      }
      setSaved(true);
    } catch (error) {
      console.error("Error al guardar:", error);
    } finally {
      setIsSaving(false);
      setTimeout(() => setSaved(false), 3000);
    }
  };

  const handleNestedChange = (section: string, key: string, value: any) => {
    setFormData((prev: any) => ({
      ...prev,
      [section]: {
        ...prev[section],
        [key]: value
      }
    }));
  };

  if (!formData.leySillaConfig) return <div className="p-8 text-slate-500">Cargando configuraciones...</div>;

  return (
    <div className={`bg-white overflow-hidden flex animate-in fade-in ${
      hideSidebar ? 'min-h-[auto] w-full' : 'rounded-2xl shadow-sm border border-slate-200 min-h-[600px]'
    }`}>
      
      {/* Sidebar Izquierdo */}
      {!hideSidebar && (
        <div className="w-64 bg-slate-50 border-r border-slate-200 flex flex-col shrink-0">
        <div className="p-6 pb-2">
          <h2 className="text-lg font-black text-slate-800 flex items-center gap-2">
            <Settings size={20} className="text-indigo-600" />
            Ajustes Globales
          </h2>
        </div>
        <div className="p-4 flex-1 space-y-1 overflow-y-auto max-h-[550px] custom-scrollbar">
          <button 
            onClick={() => setActiveTab('general')}
            className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-colors ${activeTab === 'general' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
          >
            <Building2 size={18} />
            Datos Generales
          </button>
          <button 
            onClick={() => setActiveTab('reloj')}
            className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-colors ${activeTab === 'reloj' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
          >
            <Clock size={18} />
            Reloj y Ley Silla
          </button>
          <button 
            onClick={() => setActiveTab('reloj_operacion')}
            className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-colors ${activeTab === 'reloj_operacion' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
          >
            <Settings size={18} />
            Operación del Reloj
          </button>
          <button 
            onClick={() => setActiveTab('comidas')}
            className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-colors ${activeTab === 'comidas' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
          >
            <Coffee size={18} />
            Módulo Comedor
          </button>
          <button 
            onClick={() => setActiveTab('tareas')}
            className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-colors ${activeTab === 'tareas' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
          >
            <ListTodo size={18} />
            Rutinas y Tareas
          </button>
          <button 
            onClick={() => setActiveTab('onboarding')}
            className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-colors ${activeTab === 'onboarding' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
          >
            <Users size={18} />
            Onboarding
          </button>
          <button 
            onClick={() => setActiveTab('ats')}
            className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-colors ${activeTab === 'ats' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
          >
            <Briefcase size={18} />
            Bolsa y ATS
          </button>
          <button 
            onClick={() => setActiveTab('academia')}
            className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-colors ${activeTab === 'academia' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
          >
            <GraduationCap size={18} />
            Academia LMS
          </button>
          <button 
            onClick={() => setActiveTab('documentos')}
            className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-colors ${activeTab === 'documentos' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
          >
            <FileText size={18} />
            Gestor Documental
          </button>
          <button 
            onClick={() => setActiveTab('facturacion')}
            className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-colors ${activeTab === 'facturacion' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
          >
            <Receipt size={18} />
            Facturación SAT
          </button>
          
          {useAppStore.getState().isFeatureUnlocked('store_opening') && (
            <button 
              onClick={() => setActiveTab('apertura')}
              className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-colors ${activeTab === 'apertura' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
            >
              <Key size={18} />
              Apertura de Tienda
            </button>
          )}
        </div>
      </div>
      )}

      {/* Contenido Principal */}
      <div className="flex-1 p-8 bg-white relative">

        {/* Sub-navegación local para pestañas agrupadas del Reloj Checador cuando se oculta el Sidebar */}
        {hideSidebar && (activeTab === 'reloj' || activeTab === 'reloj_operacion') && (
          <div className="flex gap-2 border-b border-slate-200 pb-4 mb-6 shrink-0">
            <button 
              onClick={() => setActiveTab('reloj')}
              className={`px-4 py-2 rounded-xl text-xs font-bold transition-all border-none cursor-pointer ${
                activeTab === 'reloj' ? 'bg-indigo-600 text-white shadow-md shadow-indigo-650/10' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              🕒 Reloj y Ley Silla
            </button>
            <button 
              onClick={() => setActiveTab('reloj_operacion')}
              className={`px-4 py-2 rounded-xl text-xs font-bold transition-all border-none cursor-pointer ${
                activeTab === 'reloj_operacion' ? 'bg-indigo-600 text-white shadow-md shadow-indigo-650/10' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              ⚙️ Operación del Reloj
            </button>
          </div>
        )}
        
        {/* Toast Notificación */}
        {saved && (
          <div className="absolute top-4 right-8 bg-emerald-100 text-emerald-800 px-4 py-2 rounded-xl font-bold flex items-center gap-2 animate-in slide-in-from-top-2">
            <CheckCircle2 size={18} /> Ajustes Guardados
          </div>
        )}

        {/* --- PESTAÑA: DATOS GENERALES --- */}
        {activeTab === 'general' && (
          <div className="max-w-2xl animate-in slide-in-from-right-4">
            <h3 className="text-2xl font-black text-slate-800 mb-2">Datos Generales de la Empresa</h3>
            <p className="text-slate-500 mb-8 text-sm">Configura la identidad y los datos de contacto generales de tu sucursal o establecimiento.</p>
            
            <div className="space-y-6">
              {/* Nombre Comercial */}
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-2">Nombre Comercial</h4>
                <input 
                  type="text" 
                  className="w-full px-4 py-2.5 border border-slate-300 rounded-xl font-medium text-slate-800 focus:outline-none focus:border-indigo-650"
                  value={formData.company_name}
                  onChange={(e) => setFormData((prev: any) => ({ ...prev, company_name: e.target.value }))}
                  placeholder="Ej. DecorArte 360"
                />
              </div>

              {/* Dirección de la Tienda */}
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-2">Dirección de la Sucursal</h4>
                <input 
                  type="text" 
                  className="w-full px-4 py-2.5 border border-slate-300 rounded-xl font-medium text-slate-800 focus:outline-none focus:border-indigo-650"
                  value={formData.company_address}
                  onChange={(e) => setFormData((prev: any) => ({ ...prev, company_address: e.target.value }))}
                  placeholder="Calle, Número, Colonia, C.P., Ciudad"
                />
              </div>

              {/* Teléfono de la Tienda */}
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-2">Teléfono de Contacto</h4>
                <div className="flex border border-slate-300 rounded-xl overflow-hidden bg-white">
                  <div className="bg-slate-100 px-3 py-2.5 text-sm text-slate-500 font-bold border-r border-slate-200 flex items-center gap-1 select-none">
                    <span>🇲🇽</span>
                    <span>+52</span>
                  </div>
                  <input 
                    type="text" 
                    className="w-full px-4 py-2.5 outline-none font-medium text-slate-800 placeholder-transparent"
                    value={formData.company_phone}
                    onChange={(e) => {
                      const digitsOnly = e.target.value.replace(/\D/g, '').slice(0, 10);
                      setFormData((prev: any) => ({ ...prev, company_phone: digitsOnly }));
                    }}
                    placeholder="Teléfono de la Tienda (10 dígitos)"
                  />
                </div>
              </div>

              {/* Horario de la Tienda */}
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-4">Horario de Operación (Tienda)</h4>
                <div className="grid grid-cols-2 gap-6">
                  <div>
                    <label className="text-xs font-bold text-slate-500 uppercase block mb-1.5">Hora de Apertura</label>
                    <input 
                      type="time" 
                      className="w-full px-4 py-2 border border-slate-300 rounded-xl font-bold text-slate-700 focus:outline-none focus:border-indigo-650"
                      value={formData.storeSchedule?.openTime || '08:00'}
                      onChange={(e) => setFormData((prev: any) => ({ 
                        ...prev, 
                        storeSchedule: { ...(prev.storeSchedule || { openTime: '08:00', closeTime: '18:00' }), openTime: e.target.value } 
                      }))}
                    />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-slate-500 uppercase block mb-1.5">Hora de Cierre</label>
                    <input 
                      type="time" 
                      className="w-full px-4 py-2 border border-slate-300 rounded-xl font-bold text-slate-700 focus:outline-none focus:border-indigo-650"
                      value={formData.storeSchedule?.closeTime || '18:00'}
                      onChange={(e) => setFormData((prev: any) => ({ 
                        ...prev, 
                        storeSchedule: { ...(prev.storeSchedule || { openTime: '08:00', closeTime: '18:00' }), closeTime: e.target.value } 
                      }))}
                    />
                  </div>
                </div>
              </div>
            </div>

            <button 
              onClick={() => handleSave()} 
              disabled={isSaving}
              className="mt-8 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-400 text-white px-6 py-3.5 rounded-xl font-extrabold flex items-center gap-2 transition-colors shadow-lg shadow-indigo-600/10 cursor-pointer"
            >
              <Save size={18} /> {isSaving ? 'Guardando...' : 'Guardar Ajustes Generales'}
            </button>
          </div>
        )}

        {/* --- PESTAÑA: RELOJ Y LEY SILLA --- */}
        {activeTab === 'reloj' && (
          <div className="max-w-2xl animate-in slide-in-from-right-4">
            <h3 className="text-2xl font-black text-slate-800 mb-2">Reloj y Ley Silla</h3>
            <p className="text-slate-500 mb-8 text-sm">Configura las tolerancias de tiempo y los descansos obligatorios por jornada.</p>
            
            <div className="space-y-6">
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-4">Tolerancia de Retardos</h4>
                <div className="flex items-center gap-4">
                  <input 
                    type="number" 
                    className="w-24 px-4 py-2 border border-slate-300 rounded-xl font-bold"
                    value={formData.timeBankConfigs.maxLateMinsAllowed}
                    onChange={(e) => handleNestedChange('timeBankConfigs', 'maxLateMinsAllowed', parseInt(e.target.value))}
                  />
                  <span className="text-slate-600">Minutos de gracia antes de marcar retardo.</span>
                </div>
              </div>

              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-4">Jornada de la Sucursal (Horario Oficial)</h4>
                <div className="grid grid-cols-2 gap-6">
                  <div>
                    <label className="text-xs font-bold text-slate-500 uppercase block mb-1.5">Hora de Apertura</label>
                    <input 
                      type="time" 
                      className="w-full px-4 py-2 border border-slate-300 rounded-xl font-bold text-slate-700 focus:outline-none focus:border-indigo-650 bg-white"
                      value={formData.storeSchedule?.openTime || '08:00'}
                      onChange={(e) => setFormData((prev: any) => ({ 
                        ...prev, 
                        storeSchedule: { ...(prev.storeSchedule || { openTime: '08:00', closeTime: '18:00' }), openTime: e.target.value } 
                      }))}
                    />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-slate-500 uppercase block mb-1.5">Hora de Cierre</label>
                    <input 
                      type="time" 
                      className="w-full px-4 py-2 border border-slate-300 rounded-xl font-bold text-slate-700 focus:outline-none focus:border-indigo-650 bg-white"
                      value={formData.storeSchedule?.closeTime || '18:00'}
                      onChange={(e) => setFormData((prev: any) => ({ 
                        ...prev, 
                        storeSchedule: { ...(prev.storeSchedule || { openTime: '08:00', closeTime: '18:00' }), closeTime: e.target.value } 
                      }))}
                    />
                  </div>
                </div>
              </div>

              <div className="bg-indigo-50 p-6 rounded-2xl border border-indigo-100">
                <div className="flex justify-between items-center mb-4">
                  <h4 className="font-bold text-indigo-900">Aplicación de Ley Silla</h4>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" className="sr-only peer" checked={formData.leySillaConfig.enabled} onChange={(e) => handleNestedChange('leySillaConfig', 'enabled', e.target.checked)} />
                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                  </label>
                </div>
                
                {formData.leySillaConfig.enabled && (
                  <div className="grid grid-cols-2 gap-4 mt-4">
                    <div>
                      <label className="text-xs font-bold text-indigo-800 uppercase">Minutos Continuos</label>
                      <input type="number" className="w-full mt-1 px-4 py-2 border border-indigo-200 rounded-xl bg-white" value={formData.leySillaConfig.consecutiveMinutes} onChange={(e) => handleNestedChange('leySillaConfig', 'consecutiveMinutes', parseInt(e.target.value))} />
                    </div>
                    <div>
                      <label className="text-xs font-bold text-indigo-800 uppercase">Minutos de Descanso</label>
                      <input type="number" className="w-full mt-1 px-4 py-2 border border-indigo-200 rounded-xl bg-white" value={formData.leySillaConfig.breakMinutes} onChange={(e) => handleNestedChange('leySillaConfig', 'breakMinutes', parseInt(e.target.value))} />
                    </div>
                  </div>
                )}
              </div>
            </div>

            <button onClick={() => { handleSave('timeBankConfigs'); handleSave('leySillaConfig'); handleSave('general'); }} className="mt-8 bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors border-none cursor-pointer">
              <Save size={18} /> Guardar Ajustes de Reloj
            </button>
          </div>
        )}

        {/* --- PESTAÑA: COMIDAS --- */}
        {activeTab === 'comidas' && (
          <div className="max-w-2xl animate-in slide-in-from-right-4">
            <h3 className="text-2xl font-black text-slate-800 mb-2">Módulo de Comedor</h3>
            <p className="text-slate-500 mb-8 text-sm">Reglas y restricciones para el área de comedor o descansos largos.</p>
            
            <div className="space-y-6">
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-4">Capacidad y Límite</h4>
                <div className="flex items-center gap-4">
                  <input 
                    type="number" 
                    className="w-24 px-4 py-2 border border-slate-300 rounded-xl font-bold"
                    value={formData.mealSettings.maxChairs}
                    onChange={(e) => handleNestedChange('mealSettings', 'maxChairs', parseInt(e.target.value))}
                  />
                  <span className="text-slate-600">Sillas máximas simultáneas.</span>
                </div>
              </div>

              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <div className="flex justify-between items-center">
                  <div>
                    <h4 className="font-bold text-slate-800">Restricción de Puestos Múltiples</h4>
                    <p className="text-sm text-slate-500 mt-1">Impedir que dos empleados del mismo puesto tomen comida a la misma hora.</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" className="sr-only peer" checked={formData.mealSettings.preventRoleOverlap} onChange={(e) => handleNestedChange('mealSettings', 'preventRoleOverlap', e.target.checked)} />
                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                  </label>
                </div>
              </div>

              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-1">Margen de Entrada a Comida</h4>
                <p className="text-xs text-slate-500 mb-3">Minutos de gracia previos al slot reservado en comedor que permiten iniciar la comida (en plan PRO).</p>
                <div className="flex items-center gap-4">
                  <input 
                    type="number" 
                    min="0" 
                    max="30"
                    className="w-24 px-4 py-2 border border-slate-300 rounded-xl font-bold bg-white text-slate-800 focus:outline-none focus:border-indigo-650"
                    value={formData.mealSettings?.mealStartToleranceMins || 5}
                    onChange={(e) => handleNestedChange('mealSettings', 'mealStartToleranceMins', parseInt(e.target.value))}
                  />
                  <span className="text-sm text-slate-650 font-bold">Minutos previos permitidos.</span>
                </div>
              </div>
            </div>

            <button onClick={() => handleSave('mealSettings')} className="mt-8 bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors">
              <Save size={18} /> Guardar Ajustes de Comida
            </button>
          </div>
        )}

        {/* --- PESTAÑA: ONBOARDING --- */}
        {activeTab === 'onboarding' && (
          <div className="max-w-2xl animate-in slide-in-from-right-4">
            <h3 className="text-2xl font-black text-slate-800 mb-2">Mensaje de Onboarding</h3>
            <p className="text-slate-500 mb-8 text-sm">El texto que ven tus empleados al unirse a la plataforma.</p>
            
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-bold text-slate-700 mb-1">Título</label>
                <input type="text" className="w-full px-4 py-2 border border-slate-300 rounded-xl" value={formData.onboarding.welcomeTitle} onChange={(e) => handleNestedChange('onboarding', 'welcomeTitle', e.target.value)} />
              </div>
              <div>
                <label className="block text-sm font-bold text-slate-700 mb-1">Mensaje Principal</label>
                <textarea rows={4} className="w-full px-4 py-2 border border-slate-300 rounded-xl" value={formData.onboarding.welcomeMessage} onChange={(e) => handleNestedChange('onboarding', 'welcomeMessage', e.target.value)}></textarea>
              </div>
              <div>
                <label className="block text-sm font-bold text-slate-700 mb-2">Canal de Invitación Predeterminado</label>
                <div className="flex gap-4">
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="inviteMode" value="whatsapp" checked={formData.onboarding.inviteLinkMode === 'whatsapp'} onChange={(e) => handleNestedChange('onboarding', 'inviteLinkMode', e.target.value)} />
                    <span className="text-slate-700 font-bold">WhatsApp</span>
                  </label>
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="inviteMode" value="email" checked={formData.onboarding.inviteLinkMode === 'email'} onChange={(e) => handleNestedChange('onboarding', 'inviteLinkMode', e.target.value)} />
                    <span className="text-slate-700 font-bold">Correo Electrónico (Email)</span>
                  </label>
                </div>
              </div>
              <div>
                <label className="block text-sm font-bold text-slate-700 mb-1">URL del Video de Bienvenida (Opcional)</label>
                <input type="text" placeholder="https://youtube.com/..." className="w-full px-4 py-2 border border-slate-300 rounded-xl" value={formData.onboarding.welcomeVideo || ''} onChange={(e) => handleNestedChange('onboarding', 'welcomeVideo', e.target.value)} />
              </div>
            </div>

            <button onClick={() => handleSave('onboarding')} className="mt-8 bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors">
              <Save size={18} /> Guardar Mensaje
            </button>
          </div>
        )}

        {/* --- PESTAÑA: TAREAS --- */}
        {activeTab === 'tareas' && (
          <div className="max-w-2xl animate-in slide-in-from-right-4">
            <h3 className="text-2xl font-black text-slate-800 mb-2">Rutinas y Asignaciones</h3>
            <p className="text-slate-500 mb-8 text-sm">Configura la asignación automática, las validaciones de supervisores y las reglas de auditoría para las tareas.</p>
            
            <div className="space-y-6">
              <div className="bg-indigo-50 p-6 rounded-2xl border border-indigo-100">
                <div className="flex justify-between items-center mb-4">
                  <div>
                    <h4 className="font-bold text-indigo-900">Validación por Supervisor Inmediato</h4>
                    <p className="text-xs text-indigo-700 mt-1">Requerir que las tareas hechas por subordinados sean aprobadas por su jefe directo.</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input 
                      type="checkbox" 
                      className="sr-only peer" 
                      checked={formData.tasksConfig?.requireSupervisorValidation || false} 
                      onChange={(e) => handleNestedChange('tasksConfig', 'requireSupervisorValidation', e.target.checked)} 
                    />
                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                  </label>
                </div>

                {formData.tasksConfig?.requireSupervisorValidation && (
                  <div className="space-y-4 mt-6 pt-4 border-t border-indigo-200/50">
                    <div>
                      <label className="text-xs font-bold text-indigo-800 uppercase block mb-2">Umbral de Validación</label>
                      <select 
                        className="w-full px-4 py-2 border border-indigo-200 rounded-xl bg-white text-slate-700 font-medium focus:outline-none"
                        value={formData.tasksConfig?.validationThreshold || 'all_tasks'}
                        onChange={(e) => handleNestedChange('tasksConfig', 'validationThreshold', e.target.value)}
                      >
                        <option value="all_tasks">Todas las tareas (Operativas y Administrativas)</option>
                        <option value="blockers_only">Solo tareas críticas (Bloqueantes)</option>
                      </select>
                    </div>

                    <div className="flex justify-between items-center pt-2">
                      <div>
                        <h5 className="font-semibold text-sm text-indigo-900">Permitir Devolución/Rechazo de Tareas</h5>
                        <p className="text-xs text-indigo-700 mt-0.5">El supervisor puede rechazar tareas con comentarios y regresarlas a "En Progreso".</p>
                      </div>
                      <label className="relative inline-flex items-center cursor-pointer">
                        <input 
                          type="checkbox" 
                          className="sr-only peer" 
                          checked={formData.tasksConfig?.allowTaskRejection || false} 
                          onChange={(e) => handleNestedChange('tasksConfig', 'allowTaskRejection', e.target.checked)} 
                        />
                        <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                      </label>
                    </div>

                    <div className="flex justify-between items-center pt-2">
                      <div>
                        <h5 className="font-semibold text-sm text-indigo-900">Auto-Aprobación Inteligente por IA</h5>
                        <p className="text-xs text-indigo-700 mt-0.5">Auto-aprobar si la validación visual de fotos por IA es exitosa (&gt;85% coincidencia).</p>
                      </div>
                      <label className="relative inline-flex items-center cursor-pointer">
                        <input 
                          type="checkbox" 
                          className="sr-only peer" 
                          checked={formData.tasksConfig?.aiAutoApproveIfValid || false} 
                          onChange={(e) => handleNestedChange('tasksConfig', 'aiAutoApproveIfValid', e.target.checked)} 
                        />
                        <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                      </label>
                    </div>
                  </div>
                )}
              </div>
            </div>

            <button onClick={() => handleSave('tasksConfig')} className="mt-8 bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors">
              <Save size={18} /> Guardar Ajustes de Tareas
            </button>
          </div>
        )}

        {/* --- PESTAÑA: APERTURA DE TIENDA --- */}
        {activeTab === 'apertura' && (
          <div className="flex flex-col gap-6 animate-in fade-in duration-200">
            <div className="flex justify-between items-center pb-4 border-b border-slate-100">
              <div className="text-left">
                <h3 className="text-lg font-black text-slate-800">Apertura de Tienda</h3>
                <p className="text-xs text-slate-500 mt-1">Configura las jerarquías de responsables, tolerancias y ventanas de apertura de la sucursal.</p>
              </div>
            </div>

            <div className="grid grid-cols-1 xl:grid-cols-12 gap-8 items-start">
              {/* Columna Izquierda: Ajustes de Apertura */}
              <div className="xl:col-span-5 bg-slate-50/50 p-6 rounded-2xl border border-slate-200/80 flex flex-col gap-4.5">
                <div className="flex items-center justify-between pb-3 border-b border-slate-200">
                  <span className="font-bold text-sm text-slate-800 flex items-center gap-2">⚙️ Parámetros de Operación</span>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input 
                      type="checkbox" 
                      className="sr-only peer" 
                      checked={openingSettings.is_enabled}
                      onChange={(e) => setOpeningSettings({ ...openingSettings, is_enabled: e.target.checked })} 
                    />
                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                  </label>
                </div>

                <div className="flex flex-col gap-4 text-left">
                  <div>
                    <label className="block text-xs font-bold text-slate-600 mb-1.5">Ventana Previa Apertura (Minutos)</label>
                    <input 
                      type="number"
                      className="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-medium text-slate-800"
                      value={openingSettings.pre_opening_window_minutes}
                      onChange={(e) => setOpeningSettings({ ...openingSettings, pre_opening_window_minutes: parseInt(e.target.value) })}
                    />
                    <p className="text-[10px] text-slate-400 mt-1">Tiempo antes de la apertura en que se activa el botón de abrir para el primer encargado.</p>
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-600 mb-1.5">Ventana para Ausencia / Retardo (Minutos)</label>
                    <input 
                      type="number"
                      className="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-medium text-slate-800"
                      value={openingSettings.absence_late_report_window_minutes}
                      onChange={(e) => setOpeningSettings({ ...openingSettings, absence_late_report_window_minutes: parseInt(e.target.value) })}
                    />
                    <p className="text-[10px] text-slate-400 mt-1">Límite para que el encargado reporte inasistencia o retardo antes de transferir la responsabilidad.</p>
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-600 mb-1.5">Margen de Entrada Anticipada (Minutos)</label>
                    <input 
                      type="number"
                      className="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-medium text-slate-800"
                      value={openingSettings.early_clock_in_allowed_minutes}
                      onChange={(e) => setOpeningSettings({ ...openingSettings, early_clock_in_allowed_minutes: parseInt(e.target.value) })}
                    />
                    <p className="text-[10px] text-slate-400 mt-1">Minutos permitidos antes de la apertura oficial para registrar entrada laboral normal.</p>
                  </div>

                  <div className="h-[1px] bg-slate-200 my-1"></div>

                  <div className="flex justify-between items-center">
                    <div>
                      <h5 className="font-semibold text-xs text-slate-700">Cesión Jerárquica Automática</h5>
                      <p className="text-[10px] text-slate-400">Transferir automáticamente al suplente si el responsable actual no responde en la ventana.</p>
                    </div>
                    <label className="relative inline-flex items-center cursor-pointer shrink-0 ml-4">
                      <input 
                        type="checkbox" 
                        className="sr-only peer" 
                        checked={openingSettings.allow_automatic_handoff}
                        onChange={(e) => setOpeningSettings({ ...openingSettings, allow_automatic_handoff: e.target.checked })} 
                      />
                      <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                    </label>
                  </div>

                  <div className="flex justify-between items-center">
                    <div>
                      <h5 className="font-semibold text-xs text-slate-700">Permitir Reportes de Tienda Cerrada</h5>
                      <p className="text-[10px] text-slate-400">Permite a los colaboradores notificar que la sucursal sigue cerrada para aplicar amnistía.</p>
                    </div>
                    <label className="relative inline-flex items-center cursor-pointer shrink-0 ml-4">
                      <input 
                        type="checkbox" 
                        className="sr-only peer" 
                        checked={openingSettings.allow_store_closed_report}
                        onChange={(e) => setOpeningSettings({ ...openingSettings, allow_store_closed_report: e.target.checked })} 
                      />
                      <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                    </label>
                  </div>

                  <div className="flex justify-between items-center">
                    <div>
                      <h5 className="font-semibold text-xs text-slate-700">Checklist de Apertura Obligatorio</h5>
                      <p className="text-[10px] text-slate-400">Asigna y requiere completar rutinas críticas al abrir la sucursal.</p>
                    </div>
                    <label className="relative inline-flex items-center cursor-pointer shrink-0 ml-4">
                      <input 
                        type="checkbox" 
                        className="sr-only peer" 
                        checked={openingSettings.require_opening_checklist}
                        onChange={(e) => setOpeningSettings({ ...openingSettings, require_opening_checklist: e.target.checked })} 
                      />
                      <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                    </label>
                  </div>

                  <div className="flex justify-between items-center">
                    <div>
                      <h5 className="font-semibold text-xs text-slate-700">Pase de Lista de Apertura Obligatorio</h5>
                      <p className="text-[10px] text-slate-400">Habilita y exige pase de lista rápido para los colaboradores del primer turno.</p>
                    </div>
                    <label className="relative inline-flex items-center cursor-pointer shrink-0 ml-4">
                      <input 
                        type="checkbox" 
                        className="sr-only peer" 
                        checked={openingSettings.require_opening_roll_call}
                        onChange={(e) => setOpeningSettings({ ...openingSettings, require_opening_roll_call: e.target.checked })} 
                      />
                      <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                    </label>
                  </div>

                  <div className="flex justify-between items-center">
                    <div>
                      <h5 className="font-semibold text-xs text-slate-700">Delegación de Llaves Obligatoria</h5>
                      <p className="text-[10px] text-slate-400">Exigir al encargado ceder las llaves a un relevo si el día siguiente es su descanso.</p>
                    </div>
                    <label className="relative inline-flex items-center cursor-pointer shrink-0 ml-4">
                      <input 
                        type="checkbox" 
                        className="sr-only peer" 
                        checked={openingSettings.require_key_delegation_on_rest ?? true}
                        onChange={(e) => setOpeningSettings({ ...openingSettings, require_key_delegation_on_rest: e.target.checked })} 
                      />
                      <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                    </label>
                  </div>
                </div>

                <button onClick={handleSaveSettings} className="mt-4 bg-indigo-600 hover:bg-indigo-700 text-white py-3.5 rounded-xl font-bold flex items-center justify-center gap-2 transition-colors border-none cursor-pointer">
                  <Save size={16} /> Guardar Ajustes
                </button>
              </div>

              {/* Columna Derecha: Jerarquía de Encargados */}
              <div className="xl:col-span-7 bg-white border border-slate-200 p-6 rounded-2xl flex flex-col gap-5 text-left">
                <div>
                  <h4 className="font-bold text-sm text-slate-800 mb-1">🔑 Jerarquía de Encargados de Sucursal</h4>
                  <p className="text-xs text-slate-500">Determina el orden en que se asigna la apertura de tienda y se ceden las llaves.</p>
                </div>

                {/* Lista de Responsables */}
                <div className="space-y-3.5 max-h-[380px] overflow-y-auto pr-1">
                  {assignments.map((ass, index) => (
                    <div key={ass.id} className="flex items-center justify-between p-4 rounded-2xl border border-slate-150 bg-slate-50/20 hover:bg-slate-50/50 transition-colors">
                      <div className="flex items-center gap-3">
                        {/* Indicador de Prioridad con Flechas */}
                        <div className="flex flex-col items-center justify-center gap-0.5 bg-slate-100 px-2 py-1.5 rounded-xl shrink-0">
                          <button 
                            disabled={index === 0}
                            onClick={() => handleMovePriority(index, 'up')}
                            className="p-0.5 text-slate-500 hover:text-indigo-600 disabled:opacity-30 disabled:hover:text-slate-500 border-none bg-transparent cursor-pointer"
                          >
                            <ArrowUp size={14} strokeWidth={2.5} />
                          </button>
                          <span className="text-xs font-black text-slate-800">{index + 1}°</span>
                          <button 
                            disabled={index === assignments.length - 1}
                            onClick={() => handleMovePriority(index, 'down')}
                            className="p-0.5 text-slate-500 hover:text-indigo-600 disabled:opacity-30 disabled:hover:text-slate-500 border-none bg-transparent cursor-pointer"
                          >
                            <ArrowDown size={14} strokeWidth={2.5} />
                          </button>
                        </div>

                        {/* Nombre y Cargo */}
                        <div>
                          <p className="font-bold text-sm text-slate-800 leading-tight">{ass.employee?.name || 'Cargando...'}</p>
                          <p className="text-[10px] text-slate-455 font-extrabold uppercase mt-0.5 tracking-wider">{ass.employee?.role || 'Colaborador'}</p>
                        </div>
                      </div>

                      {/* Toggles Rápidos & Borrar */}
                      <div className="flex items-center gap-5">
                        <div className="flex flex-col items-center">
                          <span className="text-[9px] text-slate-400 font-extrabold uppercase mb-1">Llaves</span>
                          <input 
                            type="checkbox"
                            checked={ass.has_keys}
                            onChange={() => handleToggleAssignmentField(ass.id, 'has_keys')}
                            className="rounded text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                          />
                        </div>

                        <div className="flex flex-col items-center">
                          <span className="text-[9px] text-slate-400 font-extrabold uppercase mb-1">Activo</span>
                          <input 
                            type="checkbox"
                            checked={ass.is_active}
                            onChange={() => handleToggleAssignmentField(ass.id, 'is_active')}
                            className="rounded text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                          />
                        </div>

                        <button 
                          onClick={() => handleDeleteAssignment(ass.id)}
                          className="p-2 text-rose-500 hover:text-rose-700 bg-rose-50/55 hover:bg-rose-50 rounded-xl transition-colors border-none cursor-pointer"
                        >
                          <Trash2 size={16} />
                        </button>
                      </div>
                    </div>
                  ))}

                  {assignments.length === 0 && (
                    <div className="text-center p-8 bg-slate-50 rounded-2xl text-slate-400 text-xs italic font-medium border border-dashed border-slate-200">
                      No hay encargados registrados en la jerarquía. Agrega uno abajo.
                    </div>
                  )}
                </div>

                {/* Agregar Nuevo Encargado */}
                <div className="pt-4 border-t border-slate-100 flex items-center gap-3">
                  <select 
                    value={selectedUserForAss}
                    onChange={(e) => setSelectedUserForAss(e.target.value)}
                    className="flex-1 bg-slate-50 border border-slate-200 rounded-xl px-3 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-bold text-slate-700 cursor-pointer"
                  >
                    <option value="">-- Selecciona Colaborador --</option>
                    {globalUsers
                      .filter(u => (u as any).is_active_employee !== false && !assignments.some(a => a.employee_id === u.id))
                      .map(u => (
                        <option key={u.id} value={u.id}>{u.name} ({u.role || 'Colaborador'})</option>
                      ))}
                  </select>

                  <button 
                    onClick={handleAddAssignment}
                    disabled={!selectedUserForAss}
                    className="bg-indigo-600 hover:bg-indigo-750 text-white font-extrabold px-4.5 py-3 rounded-xl flex items-center gap-1.5 transition-all disabled:opacity-40 disabled:hover:bg-indigo-600 border-none cursor-pointer select-none"
                  >
                    <Plus size={16} strokeWidth={2.5} />
                    <span>Agregar</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* --- PESTAÑA: BOLSA Y ATS --- */}
        {activeTab === 'ats' && (
          <div className="max-w-2xl animate-in slide-in-from-right-4">
            <div className="flex justify-between items-center mb-6">
              <div>
                <h3 className="text-2xl font-black text-slate-800">Ajustes de Reclutamiento ATS</h3>
                <p className="text-slate-500 text-sm">Gestiona los parámetros para el portal público de empleo y entrevistas.</p>
              </div>
              {!useAppStore.getState().isModuleUnlocked('ats') && (
                <span className="bg-amber-100 text-amber-800 text-xs font-extrabold uppercase px-3 py-1.5 rounded-full flex items-center gap-1">
                  <Key size={12} /> Plan PRO requerido
                </span>
              )}
            </div>

            <div className={`space-y-6 ${!useAppStore.getState().isModuleUnlocked('ats') && 'opacity-60 pointer-events-none'}`}>
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-2">Entrevistador / Reclutador por Defecto</h4>
                <input 
                  type="text" 
                  className="w-full px-4 py-2.5 border border-slate-300 rounded-xl font-medium text-slate-800 focus:outline-none focus:border-indigo-650 bg-white"
                  value={formData.atsConfig?.defaultInterviewer || ''}
                  onChange={(e) => handleNestedChange('atsConfig', 'defaultInterviewer', e.target.value)}
                  placeholder="Ej. Lic. Francisco"
                />
              </div>

              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-3">Canal de Notificaciones de Cita</h4>
                <div className="flex gap-6">
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input 
                      type="radio" 
                      name="atsNotifyMode" 
                      value="whatsapp" 
                      checked={formData.atsConfig?.notificationChannel === 'whatsapp'} 
                      onChange={(e) => handleNestedChange('atsConfig', 'notificationChannel', e.target.value)} 
                    />
                    <span className="text-slate-700 font-bold">WhatsApp API</span>
                  </label>
                  <label className="flex items-center gap-2 cursor-pointer">
                    <input 
                      type="radio" 
                      name="atsNotifyMode" 
                      value="email" 
                      checked={formData.atsConfig?.notificationChannel === 'email'} 
                      onChange={(e) => handleNestedChange('atsConfig', 'notificationChannel', e.target.value)} 
                    />
                    <span className="text-slate-700 font-bold">Correo (Email)</span>
                  </label>
                </div>
              </div>

              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <div className="flex justify-between items-center">
                  <div>
                    <h4 className="font-bold text-slate-800">Publicar automáticamente vacantes</h4>
                    <p className="text-sm text-slate-500 mt-1">Sincroniza y publica tus vacantes activas en el portal público de inmediato.</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input 
                      type="checkbox" 
                      className="sr-only peer" 
                      checked={formData.atsConfig?.autoPublish || false} 
                      onChange={(e) => handleNestedChange('atsConfig', 'autoPublish', e.target.checked)} 
                    />
                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                  </label>
                </div>
              </div>
            </div>

            <button 
              onClick={() => handleSave('atsConfig')} 
              disabled={isSaving || !useAppStore.getState().isModuleUnlocked('ats')}
              className="mt-8 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-400 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors cursor-pointer"
            >
              <Save size={18} /> Guardar Ajustes de ATS
            </button>
          </div>
        )}

        {/* --- PESTAÑA: ACADEMIA LMS --- */}
        {activeTab === 'academia' && (
          <div className="max-w-2xl animate-in slide-in-from-right-4">
            <div className="flex justify-between items-center mb-6">
              <div>
                <h3 className="text-2xl font-black text-slate-800">Academia LMS y Capacitación</h3>
                <p className="text-slate-500 text-sm">Configura las reglas de aprobación de cursos y diplomas de los colaboradores.</p>
              </div>
              {!useAppStore.getState().isModuleUnlocked('academia') && (
                <span className="bg-purple-100 text-purple-800 text-xs font-extrabold uppercase px-3 py-1.5 rounded-full flex items-center gap-1">
                  <Key size={12} /> Plan ENTERPRISE requerido
                </span>
              )}
            </div>

            <div className={`space-y-6 ${!useAppStore.getState().isModuleUnlocked('academia') && 'opacity-60 pointer-events-none'}`}>
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-4">Calificación de Aprobación Mínima (%)</h4>
                <div className="flex items-center gap-4">
                  <input 
                    type="number" 
                    min="50" 
                    max="100" 
                    className="w-24 px-4 py-2 border border-slate-300 rounded-xl font-bold bg-white text-slate-850"
                    value={formData.academyConfig?.minPassingGrade || 80}
                    onChange={(e) => handleNestedChange('academyConfig', 'minPassingGrade', parseInt(e.target.value))}
                  />
                  <span className="text-slate-600">Porcentaje mínimo de respuestas correctas en exámenes.</span>
                </div>
              </div>

              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <div className="flex justify-between items-center">
                  <div>
                    <h4 className="font-bold text-slate-800">Permitir Auto-inscripción a Cursos</h4>
                    <p className="text-sm text-slate-500 mt-1">Los colaboradores pueden cursar asignaturas no obligatorias libremente.</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input 
                      type="checkbox" 
                      className="sr-only peer" 
                      checked={formData.academyConfig?.allowSelfEnrollment || false} 
                      onChange={(e) => handleNestedChange('academyConfig', 'allowSelfEnrollment', e.target.checked)} 
                    />
                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                  </label>
                </div>
              </div>

              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-4">Firmas Autorizadas para Constancias</h4>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="text-xs font-bold text-slate-500 uppercase block mb-1">Nombre del Director</label>
                    <input 
                      type="text" 
                      className="w-full px-4 py-2 border border-slate-300 rounded-xl font-medium bg-white text-slate-800" 
                      value={formData.academyConfig?.certificateSignatures?.directorName || ''} 
                      onChange={(e) => {
                        const updatedSignatures = { ...(formData.academyConfig?.certificateSignatures || {}), directorName: e.target.value };
                        handleNestedChange('academyConfig', 'certificateSignatures', updatedSignatures);
                      }}
                    />
                  </div>
                  <div>
                    <label className="text-xs font-bold text-slate-500 uppercase block mb-1">Nombre del Instructor</label>
                    <input 
                      type="text" 
                      className="w-full px-4 py-2 border border-slate-300 rounded-xl font-medium bg-white text-slate-800" 
                      value={formData.academyConfig?.certificateSignatures?.instructorName || ''} 
                      onChange={(e) => {
                        const updatedSignatures = { ...(formData.academyConfig?.certificateSignatures || {}), instructorName: e.target.value };
                        handleNestedChange('academyConfig', 'certificateSignatures', updatedSignatures);
                      }}
                    />
                  </div>
                </div>
              </div>
            </div>

            <button 
              onClick={() => handleSave('academyConfig')} 
              disabled={isSaving || !useAppStore.getState().isModuleUnlocked('academia')}
              className="mt-8 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-400 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors cursor-pointer"
            >
              <Save size={18} /> Guardar Ajustes de LMS
            </button>
          </div>
        )}

        {/* --- PESTAÑA: GESTOR DOCUMENTAL --- */}
        {activeTab === 'documentos' && (
          <div className="max-w-2xl animate-in slide-in-from-right-4">
            <div className="flex justify-between items-center mb-6">
              <div>
                <h3 className="text-2xl font-black text-slate-800">Expedientes y Gestor Documental</h3>
                <p className="text-slate-500 text-sm">Define las reglas para el expediente de personal y la carga de archivos.</p>
              </div>
              {!useAppStore.getState().isModuleUnlocked('documentos') && (
                <span className="bg-purple-100 text-purple-800 text-xs font-extrabold uppercase px-3 py-1.5 rounded-full flex items-center gap-1">
                  <Key size={12} /> Plan ENTERPRISE requerido
                </span>
              )}
            </div>

            <div className={`space-y-6 ${!useAppStore.getState().isModuleUnlocked('documentos') && 'opacity-60 pointer-events-none'}`}>
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-2">Tamaño Máximo por Archivo</h4>
                <select 
                  className="w-full px-4 py-2.5 border border-slate-300 rounded-xl bg-white text-slate-700 font-bold focus:outline-none"
                  value={formData.docsConfig?.maxFileSizeMb || 10}
                  onChange={(e) => handleNestedChange('docsConfig', 'maxFileSizeMb', parseInt(e.target.value))}
                >
                  <option value="5">5 MB (Límite estándar)</option>
                  <option value="10">10 MB (Recomendado)</option>
                  <option value="20">20 MB (Carga alta de PDFs)</option>
                  <option value="50">50 MB (Archivos pesados/Videos)</option>
                </select>
              </div>

              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <div className="flex justify-between items-center">
                  <div>
                    <h4 className="font-bold text-slate-800">Permitir Carga a Colaboradores</h4>
                    <p className="text-sm text-slate-500 mt-1">Los colaboradores pueden subir sus documentos oficiales directamente desde su móvil.</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input 
                      type="checkbox" 
                      className="sr-only peer" 
                      checked={formData.docsConfig?.allowCollabUpload || false} 
                      onChange={(e) => handleNestedChange('docsConfig', 'allowCollabUpload', e.target.checked)} 
                    />
                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                  </label>
                </div>
              </div>

              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-3">Documentación Obligatoria del Expediente</h4>
                <div className="space-y-2">
                  {[
                    { id: 'ine', label: 'Identificación Oficial (INE / Pasaporte)' },
                    { id: 'rfc', label: 'Cédula de Identificación Fiscal (RFC)' },
                    { id: 'curp', label: 'Clave Única de Registro de Población (CURP)' },
                    { id: 'domicilio', label: 'Comprobante de Domicilio (Reciente)' }
                  ].map((doc) => {
                    const isChecked = (formData.docsConfig?.requiredDocs || []).includes(doc.id);
                    const handleDocCheckboxChange = (checked: boolean) => {
                      const current = formData.docsConfig?.requiredDocs || [];
                      const updated = checked 
                        ? [...current, doc.id]
                        : current.filter((x: string) => x !== doc.id);
                      handleNestedChange('docsConfig', 'requiredDocs', updated);
                    };

                    return (
                      <label key={doc.id} className="flex items-center gap-2.5 cursor-pointer text-sm font-semibold text-slate-700 bg-white p-2.5 rounded-xl border border-slate-200">
                        <input 
                          type="checkbox" 
                          checked={isChecked} 
                          onChange={(e) => handleDocCheckboxChange(e.target.checked)} 
                          className="rounded text-indigo-650 focus:ring-indigo-500 cursor-pointer"
                        />
                        <span>{doc.label}</span>
                      </label>
                    );
                  })}
                </div>
              </div>
            </div>

            <button 
              onClick={() => handleSave('docsConfig')} 
              disabled={isSaving || !useAppStore.getState().isModuleUnlocked('documentos')}
              className="mt-8 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-400 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors cursor-pointer"
            >
              <Save size={18} /> Guardar Ajustes de Expedientes
            </button>
          </div>
        )}

        {/* --- PESTAÑA: CENTRO DE REPORTES --- */}
        {activeTab === 'reportes' && (
          <div className="max-w-2xl animate-in slide-in-from-right-4">
            <div className="flex justify-between items-center mb-6">
              <div>
                <h3 className="text-2xl font-black text-slate-800">Centro de Reportes y Nómina</h3>
                <p className="text-slate-500 text-sm">Configura la generación e informes ejecutivos de asistencia.</p>
              </div>
              {!useAppStore.getState().isModuleUnlocked('reportes') && (
                <span className="bg-amber-100 text-amber-800 text-xs font-extrabold uppercase px-3 py-1.5 rounded-full flex items-center gap-1">
                  <Key size={12} /> Plan PRO requerido
                </span>
              )}
            </div>

            <div className={`space-y-6 ${!useAppStore.getState().isModuleUnlocked('reportes') && 'opacity-60 pointer-events-none'}`}>
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-2">Formato Predeterminado de Descarga</h4>
                <select 
                  className="w-full px-4 py-2.5 border border-slate-300 rounded-xl bg-white text-slate-700 font-bold focus:outline-none"
                  value={formData.reportsConfig?.defaultFormat || 'xlsx'}
                  onChange={(e) => handleNestedChange('reportsConfig', 'defaultFormat', e.target.value)}
                >
                  <option value="xlsx">Excel (.xlsx) - Para cálculos de nómina</option>
                  <option value="pdf">PDF (.pdf) - Para impresión y firmas</option>
                </select>
              </div>

              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-2">Ciclo de Corte de Pre-Nómina</h4>
                <select 
                  className="w-full px-4 py-2.5 border border-slate-300 rounded-xl bg-white text-slate-700 font-bold focus:outline-none"
                  value={formData.reportsConfig?.payrollPeriod || 'quincenal'}
                  onChange={(e) => handleNestedChange('reportsConfig', 'payrollPeriod', e.target.value)}
                >
                  <option value="semanal">Corte Semanal (Lunes a Domingo)</option>
                  <option value="quincenal">Corte Quincenal (1 al 15 y 16 al fin de mes)</option>
                  <option value="mensual">Corte Mensual (Mes Calendario)</option>
                </select>
              </div>

              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <div className="flex justify-between items-center">
                  <div>
                    <h4 className="font-bold text-slate-800">Enviar Resumen Diario por Email</h4>
                    <p className="text-sm text-slate-500 mt-1">Recibe un resumen con la lista de retardos e inasistencias del día en tu correo.</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input 
                      type="checkbox" 
                      className="sr-only peer" 
                      checked={formData.reportsConfig?.sendDailySummary || false} 
                      onChange={(e) => handleNestedChange('reportsConfig', 'sendDailySummary', e.target.checked)} 
                    />
                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                  </label>
                </div>
              </div>
            </div>

            <button 
              onClick={() => handleSave('reportsConfig')} 
              disabled={isSaving || !useAppStore.getState().isModuleUnlocked('reportes')}
              className="mt-8 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-400 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors cursor-pointer"
            >
              <Save size={18} /> Guardar Ajustes de Reportes
            </button>
          </div>
        )}

        {activeTab === 'facturacion' && (
          <div className="max-w-2xl animate-in slide-in-from-right-4">
            <div className="flex justify-between items-center mb-6">
              <div>
                <h3 className="text-2xl font-black text-slate-800">Ajustes de Facturación SAT</h3>
                <p className="text-slate-500 text-sm">Gestiona el comportamiento operativo de la facturación y timbrado.</p>
              </div>
              {!useAppStore.getState().isModuleUnlocked('facturacion') && (
                <span className="bg-amber-100 text-amber-800 text-xs font-extrabold uppercase px-3 py-1.5 rounded-full flex items-center gap-1">
                  <Key size={12} /> Plan PRO requerido
                </span>
              )}
            </div>

            <div className={`space-y-6 ${!useAppStore.getState().isModuleUnlocked('facturacion') && 'opacity-60 pointer-events-none'}`}>
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <div className="flex justify-between items-center">
                  <div>
                    <h4 className="font-bold text-slate-800">Timbrado de Nómina Automático</h4>
                    <p className="text-sm text-slate-500 mt-1">Timbra automáticamente los recibos CFDI 4.0 al procesar el cierre del periodo quincenal.</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input 
                      type="checkbox" 
                      className="sr-only peer" 
                      checked={formData.facturacionConfig?.autoTimbrar || false} 
                      onChange={(e) => handleNestedChange('facturacionConfig', 'autoTimbrar', e.target.checked)} 
                    />
                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                  </label>
                </div>
              </div>

              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-2">Ambiente de Operación SAT</h4>
                <select 
                  className="w-full px-4 py-2.5 border border-slate-300 rounded-xl bg-white text-slate-700 font-bold focus:outline-none"
                  value={formData.facturacionConfig?.ambienteSat || 'sandbox'}
                  onChange={(e) => handleNestedChange('facturacionConfig', 'ambienteSat', e.target.value)}
                >
                  <option value="sandbox">Pruebas SAT (Sandbox Facturapi)</option>
                  <option value="production">Producción Fiscal (Timbrado Real)</option>
                </select>
              </div>

              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-2">Email para Recepción de Avisos CFDIs</h4>
                <input 
                  type="email"
                  placeholder="ejemplo@decorarte.com"
                  className="w-full px-4 py-2.5 border border-slate-300 rounded-xl bg-white text-slate-800 font-semibold focus:border-indigo-500 focus:outline-none"
                  value={formData.facturacionConfig?.correoFiscal || ''}
                  onChange={(e) => handleNestedChange('facturacionConfig', 'correoFiscal', e.target.value)}
                />
              </div>
            </div>

            <button 
              onClick={() => handleSave('facturacionConfig')} 
              disabled={isSaving || !useAppStore.getState().isModuleUnlocked('facturacion')}
              className="mt-8 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-400 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors cursor-pointer border-none"
            >
              <Save size={18} /> Guardar Ajustes de Facturación
            </button>
          </div>
        )}

        {/* --- PESTAÑA: OPERACIÓN DEL RELOJ --- */}
        {activeTab === 'reloj_operacion' && (
          <div className="max-w-2xl animate-in slide-in-from-right-4">
            <h3 className="text-2xl font-black text-slate-800 mb-2">Operación del Reloj</h3>
            <p className="text-slate-500 mb-8 text-sm">Gestiona los parámetros de las acciones y tiempos operativos del Reloj Checador.</p>

            <div className="space-y-6">
              {/* Ventana de Arribo Anticipado */}
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-1">Ventana de Arribo Anticipado ("Ya Llegué")</h4>
                <p className="text-xs text-slate-500 mb-3">Minutos antes del turno en que un colaborador puede registrar su llegada física en el perímetro.</p>
                <div className="flex items-center gap-4">
                  <input 
                    type="number" 
                    min="5" 
                    max="120"
                    className="w-24 px-4 py-2 border border-slate-300 rounded-xl font-bold bg-white text-slate-800 focus:outline-none focus:border-indigo-650"
                    value={formData.clockOpConfig?.arrivalWindowMins || 30}
                    onChange={(e) => handleNestedChange('clockOpConfig', 'arrivalWindowMins', parseInt(e.target.value))}
                  />
                  <span className="text-sm text-slate-650 font-bold">Minutos de anticipación permitidos.</span>
                </div>
              </div>

              {/* Tiempo Reporte Tienda Cerrada */}
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-1">Tiempo de Gracia para Tienda Cerrada</h4>
                <p className="text-xs text-slate-500 mb-3">Minutos tras el inicio oficial del turno antes de activar el botón de "Reportar Tienda Cerrada" a los colaboradores.</p>
                <div className="flex items-center gap-4">
                  <input 
                    type="number" 
                    min="0" 
                    max="60"
                    className="w-24 px-4 py-2 border border-slate-300 rounded-xl font-bold bg-white text-slate-800 focus:outline-none focus:border-indigo-650"
                    value={formData.clockOpConfig?.storeClosedReportDelayMins || 0}
                    onChange={(e) => handleNestedChange('clockOpConfig', 'storeClosedReportDelayMins', parseInt(e.target.value))}
                  />
                  <span className="text-sm text-slate-650 font-bold">Minutos de retraso antes del reporte.</span>
                </div>
              </div>

              {/* Ventana de Habilitación Previa del Reloj */}
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200">
                <h4 className="font-bold text-slate-800 mb-1">Ventana de Acceso Previo al Reloj (Minutos)</h4>
                <p className="text-xs text-slate-500 mb-3">Minutos antes de la hora oficial de apertura de la tienda en que se habilitará el Reloj Checador para registrar asistencia e incidencias.</p>
                <div className="flex items-center gap-4">
                  <input 
                    type="number" 
                    min="0" 
                    max="180"
                    className="w-24 px-4 py-2 border border-slate-300 rounded-xl font-bold bg-white text-slate-800 focus:outline-none focus:border-indigo-650"
                    value={formData.clockOpConfig?.preOpeningAccessMins ?? 60}
                    onChange={(e) => handleNestedChange('clockOpConfig', 'preOpeningAccessMins', parseInt(e.target.value) || 0)}
                  />
                  <span className="text-sm text-slate-650 font-bold">Minutos previos permitidos.</span>
                </div>
              </div>

                {/* Ajustes modularizados y premium */}
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200 space-y-6">
                <h4 className="font-extrabold text-slate-800 text-sm border-b pb-2">🔒 Configuración de Red Wi-Fi e IP Lock (Básico / Gratis)</h4>
                
                <div className="flex justify-between items-center">
                  <div>
                    <h5 className="font-bold text-slate-700 text-xs">Bloqueo por IP (IP Lock)</h5>
                    <p className="text-[10px] text-slate-500 mt-0.5">Exigir que los colaboradores estén conectados al Wi-Fi de la tienda (IP coincidente) para fichar.</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input 
                      type="checkbox" 
                      className="sr-only peer" 
                      checked={formData.clockOpConfig?.ip_lock_enabled ?? false} 
                      onChange={(e) => handleNestedChange('clockOpConfig', 'ip_lock_enabled', e.target.checked)} 
                    />
                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                  </label>
                </div>

                {formData.clockOpConfig?.ip_lock_enabled && (
                  <div className="space-y-2 animate-in slide-in-from-top-2 duration-200">
                    <label className="text-[10px] font-black text-slate-550 uppercase tracking-wider block text-left">Dirección IP de la Red Wi-Fi Autorizada</label>
                    <input 
                      type="text" 
                      className="w-full px-4 py-2.5 border border-slate-300 rounded-xl font-bold bg-white text-slate-800 focus:outline-none focus:border-indigo-650 text-xs"
                      placeholder="Ej. 192.168.1.254 o IP pública"
                      value={formData.clockOpConfig?.store_ip_address || ''}
                      onChange={(e) => handleNestedChange('clockOpConfig', 'store_ip_address', e.target.value)}
                    />
                  </div>
                )}

                <div className="h-[1px] bg-slate-200"></div>

                <h4 className="font-extrabold text-slate-800 text-sm border-b pb-2 flex items-center justify-between">
                  <span>⭐️ Controles y Módulos del Reloj (Modular Pro)</span>
                  <span className="text-[10px] bg-indigo-100 text-indigo-750 px-2 py-0.5 rounded-full font-black uppercase">Plan Pro</span>
                </h4>

                <div className="space-y-4">
                  {/* Slider Tiempo de Traslado del Suplente */}
                  <div className="space-y-2">
                    <div className="flex justify-between items-center">
                      <div>
                        <h5 className="font-bold text-slate-700 text-xs">Tiempo de Traslado del Suplente</h5>
                        <p className="text-[10px] text-slate-505 mt-0.5">Margen de anticipación límite en minutos para alertar/delegar al suplente.</p>
                      </div>
                      <span className="text-xs font-black text-indigo-600 bg-indigo-50 px-2 py-1 rounded-lg">
                        {formData.clockOpConfig?.suplente_travel_time_mins || 45} minutos
                      </span>
                    </div>
                    <input 
                      type="range" 
                      min="10" 
                      max="120" 
                      step="5"
                      className="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-indigo-600"
                      value={formData.clockOpConfig?.suplente_travel_time_mins || 45} 
                      onChange={(e) => handleNestedChange('clockOpConfig', 'suplente_travel_time_mins', parseInt(e.target.value))} 
                    />
                  </div>

                  <div className="h-[1px] bg-slate-200 my-2"></div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Switch 1: allow_employee_incidences */}
                    <div className="flex justify-between items-center bg-white p-3 rounded-xl border border-slate-100">
                      <div>
                        <h5 className="font-bold text-slate-700 text-[11px]">Incidencias Colaborador</h5>
                        <p className="text-[9px] text-slate-400">Reporte de retardo a las 7:00 AM</p>
                      </div>
                      <label className="relative inline-flex items-center cursor-pointer scale-90">
                        <input 
                          type="checkbox" 
                          className="sr-only peer" 
                          checked={formData.clockOpConfig?.enabledDialerFeatures?.allow_employee_incidences ?? true} 
                          onChange={(e) => {
                            const prev = formData.clockOpConfig?.enabledDialerFeatures || {};
                            handleNestedChange('clockOpConfig', 'enabledDialerFeatures', { ...prev, allow_employee_incidences: e.target.checked });
                          }} 
                        />
                        <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                      </label>
                    </div>

                    {/* Switch 2: allow_manager_incidences */}
                    <div className="flex justify-between items-center bg-white p-3 rounded-xl border border-slate-100">
                      <div>
                        <h5 className="font-bold text-slate-700 text-[11px]">Incidencias Encargado</h5>
                        <p className="text-[9px] text-slate-400">Límite dinámico suplente</p>
                      </div>
                      <label className="relative inline-flex items-center cursor-pointer scale-90">
                        <input 
                          type="checkbox" 
                          className="sr-only peer" 
                          checked={formData.clockOpConfig?.enabledDialerFeatures?.allow_manager_incidences ?? true} 
                          onChange={(e) => {
                            const prev = formData.clockOpConfig?.enabledDialerFeatures || {};
                            handleNestedChange('clockOpConfig', 'enabledDialerFeatures', { ...prev, allow_manager_incidences: e.target.checked });
                          }} 
                        />
                        <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                      </label>
                    </div>

                    {/* Switch 3: enable_proximity_check */}
                    <div className="flex justify-between items-center bg-white p-3 rounded-xl border border-slate-100">
                      <div>
                        <h5 className="font-bold text-slate-700 text-[11px]">Ya estoy aquí (Cercanía)</h5>
                        <p className="text-[9px] text-slate-400">Marcaje de cercanía 8:15-8:30</p>
                      </div>
                      <label className="relative inline-flex items-center cursor-pointer scale-90">
                        <input 
                          type="checkbox" 
                          className="sr-only peer" 
                          checked={formData.clockOpConfig?.enabledDialerFeatures?.enable_proximity_check ?? true} 
                          onChange={(e) => {
                            const prev = formData.clockOpConfig?.enabledDialerFeatures || {};
                            handleNestedChange('clockOpConfig', 'enabledDialerFeatures', { ...prev, enable_proximity_check: e.target.checked });
                          }} 
                        />
                        <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                      </label>
                    </div>

                    {/* Switch 4: allow_store_closed_report */}
                    <div className="flex justify-between items-center bg-white p-3 rounded-xl border border-slate-100">
                      <div>
                        <h5 className="font-bold text-slate-700 text-[11px]">Reportar Tienda Cerrada</h5>
                        <p className="text-[9px] text-slate-400">Reporte preventivo en apertura tardía</p>
                      </div>
                      <label className="relative inline-flex items-center cursor-pointer scale-90">
                        <input 
                          type="checkbox" 
                          className="sr-only peer" 
                          checked={formData.clockOpConfig?.enabledDialerFeatures?.allow_store_closed_report ?? true} 
                          onChange={(e) => {
                            const prev = formData.clockOpConfig?.enabledDialerFeatures || {};
                            handleNestedChange('clockOpConfig', 'enabledDialerFeatures', { ...prev, allow_store_closed_report: e.target.checked });
                          }} 
                        />
                        <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                      </label>
                    </div>

                    {/* Switch 5: revalidate_gps_on_punch */}
                    <div className="flex justify-between items-center bg-white p-3 rounded-xl border border-slate-100">
                      <div>
                        <h5 className="font-bold text-slate-700 text-[11px]">Re-validar GPS al Marcar</h5>
                        <p className="text-[9px] text-slate-400">Exigir geocerca en entrada/salida final</p>
                      </div>
                      <label className="relative inline-flex items-center cursor-pointer scale-90">
                        <input 
                          type="checkbox" 
                          className="sr-only peer" 
                          checked={formData.clockOpConfig?.enabledDialerFeatures?.revalidate_gps_on_punch ?? true} 
                          onChange={(e) => {
                            const prev = formData.clockOpConfig?.enabledDialerFeatures || {};
                            handleNestedChange('clockOpConfig', 'enabledDialerFeatures', { ...prev, revalidate_gps_on_punch: e.target.checked });
                          }} 
                        />
                        <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                      </label>
                    </div>

                    {/* Switch 6: enable_meal_slots */}
                    <div className="flex justify-between items-center bg-white p-3 rounded-xl border border-slate-100">
                      <div>
                        <h5 className="font-bold text-slate-700 text-[11px]">Slots de Almuerzo</h5>
                        <p className="text-[9px] text-slate-400">Reservación y control de horarios</p>
                      </div>
                      <label className="relative inline-flex items-center cursor-pointer scale-90">
                        <input 
                          type="checkbox" 
                          className="sr-only peer" 
                          checked={formData.clockOpConfig?.enabledDialerFeatures?.enable_meal_slots ?? true} 
                          onChange={(e) => {
                            const prev = formData.clockOpConfig?.enabledDialerFeatures || {};
                            handleNestedChange('clockOpConfig', 'enabledDialerFeatures', { ...prev, enable_meal_slots: e.target.checked });
                          }} 
                        />
                        <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                      </label>
                    </div>

                    {/* Switch 7: enable_ley_silla */}
                    <div className="flex justify-between items-center bg-white p-3 rounded-xl border border-slate-100">
                      <div>
                        <h5 className="font-bold text-slate-700 text-[11px]">Descanso Ley Silla</h5>
                        <p className="text-[9px] text-slate-400">Desencadenar descanso tras comida</p>
                      </div>
                      <label className="relative inline-flex items-center cursor-pointer scale-90">
                        <input 
                          type="checkbox" 
                          className="sr-only peer" 
                          checked={formData.clockOpConfig?.enabledDialerFeatures?.enable_ley_silla ?? true} 
                          onChange={(e) => {
                            const prev = formData.clockOpConfig?.enabledDialerFeatures || {};
                            handleNestedChange('clockOpConfig', 'enabledDialerFeatures', { ...prev, enable_ley_silla: e.target.checked });
                          }} 
                        />
                        <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                      </label>
                    </div>

                    {/* Switch 8: enable_early_departure */}
                    <div className="flex justify-between items-center bg-white p-3 rounded-xl border border-slate-100">
                      <div>
                        <h5 className="font-bold text-slate-700 text-[11px]">Salida Anticipada</h5>
                        <p className="text-[9px] text-slate-400">Botón secundario de salida bajo dial</p>
                      </div>
                      <label className="relative inline-flex items-center cursor-pointer scale-90">
                        <input 
                          type="checkbox" 
                          className="sr-only peer" 
                          checked={formData.clockOpConfig?.enabledDialerFeatures?.enable_early_departure ?? true} 
                          onChange={(e) => {
                            const prev = formData.clockOpConfig?.enabledDialerFeatures || {};
                            handleNestedChange('clockOpConfig', 'enabledDialerFeatures', { ...prev, enable_early_departure: e.target.checked });
                          }} 
                        />
                        <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                      </label>
                    </div>

                    {/* Switch 9: enable_panic_button */}
                    <div className="flex justify-between items-center bg-white p-3 rounded-xl border border-slate-100">
                      <div>
                        <h5 className="font-bold text-slate-700 text-[11px]">Botón de Pánico</h5>
                        <p className="text-[9px] text-slate-400">Llamada y alerta de emergencia</p>
                      </div>
                      <label className="relative inline-flex items-center cursor-pointer scale-90">
                        <input 
                          type="checkbox" 
                          className="sr-only peer" 
                          checked={formData.clockOpConfig?.enabledDialerFeatures?.enable_panic_button ?? true} 
                          onChange={(e) => {
                            const prev = formData.clockOpConfig?.enabledDialerFeatures || {};
                            handleNestedChange('clockOpConfig', 'enabledDialerFeatures', { ...prev, enable_panic_button: e.target.checked });
                          }} 
                        />
                        <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                      </label>
                    </div>

                    {/* Switch 10: enable_temp_exit */}
                    <div className="flex justify-between items-center bg-white p-3 rounded-xl border border-slate-100">
                      <div>
                        <h5 className="font-bold text-slate-700 text-[11px]">Salidas Temporales</h5>
                        <p className="text-[9px] text-slate-400">Registrar reingresos intermedios</p>
                      </div>
                      <label className="relative inline-flex items-center cursor-pointer scale-90">
                        <input 
                          type="checkbox" 
                          className="sr-only peer" 
                          checked={formData.clockOpConfig?.enabledDialerFeatures?.enable_temp_exit ?? true} 
                          onChange={(e) => {
                            const prev = formData.clockOpConfig?.enabledDialerFeatures || {};
                            handleNestedChange('clockOpConfig', 'enabledDialerFeatures', { ...prev, enable_temp_exit: e.target.checked });
                          }} 
                        />
                        <div className="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                      </label>
                    </div>
                  </div>
                </div>
              </div>

              {/* Toggles booleanos */}
              <div className="bg-slate-50 p-6 rounded-2xl border border-slate-200 space-y-4">
                <div className="flex justify-between items-center">
                  <div>
                    <h4 className="font-bold text-slate-800">Evaluación Obligatoria al Salir</h4>
                    <p className="text-xs text-slate-500 mt-1">Exigir retroalimentación de clima laboral al final de la jornada de trabajo.</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input 
                      type="checkbox" 
                      className="sr-only peer" 
                      checked={formData.clockOpConfig?.requireExitEvaluation ?? true} 
                      onChange={(e) => handleNestedChange('clockOpConfig', 'requireExitEvaluation', e.target.checked)} 
                    />
                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-650"></div>
                  </label>
                </div>

                <div className="flex justify-between items-center">
                  <div>
                    <h4 className="font-bold text-slate-800">Validación de GPS Obligatoria</h4>
                    <p className="text-xs text-slate-500 mt-1">Requerir que los colaboradores estén dentro del perímetro de la sucursal para poder registrar su asistencia.</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input 
                      type="checkbox" 
                      className="sr-only peer" 
                      checked={formData.clockOpConfig?.gpsValidationEnabled ?? true} 
                      onChange={(e) => handleNestedChange('clockOpConfig', 'gpsValidationEnabled', e.target.checked)} 
                    />
                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-650"></div>
                  </label>
                </div>

                <div className="h-[1px] bg-slate-200"></div>

                <div className="flex justify-between items-center">
                  <div>
                    <h4 className="font-bold text-slate-800">Distancia Máxima de Alerta GPS (Metros)</h4>
                    <p className="text-xs text-slate-505 mt-1">Radio máximo permitido en metros antes de alertar al supervisor en salidas temporales.</p>
                  </div>
                  <input 
                    type="number" 
                    className="w-24 p-2 border border-slate-200 rounded-lg text-sm font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500" 
                    value={formData.clockOpConfig?.gpsAlertRangeMeters ?? 100} 
                    onChange={(e) => handleNestedChange('clockOpConfig', 'gpsAlertRangeMeters', parseInt(e.target.value) || 0)} 
                  />
                </div>

                <div className="h-[1px] bg-slate-200"></div>

                <div className="flex justify-between items-center">
                  <div>
                    <h4 className="font-bold text-slate-800">Permitir Fichajes Manuales</h4>
                    <p className="text-xs text-slate-500 mt-1">Habilitar botones de Entrada/Salida manuales sin validación de geolocalización GPS.</p>
                  </div>
                  <label className="relative inline-flex items-center cursor-pointer">
                    <input 
                      type="checkbox" 
                      className="sr-only peer" 
                      checked={formData.clockOpConfig?.allowManualCheckIn ?? false} 
                      onChange={(e) => handleNestedChange('clockOpConfig', 'allowManualCheckIn', e.target.checked)} 
                    />
                    <div className="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-650"></div>
                  </label>
                </div>
              </div>
            </div>

            <button onClick={() => handleSave('clockOpConfig')} className="mt-8 bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-xl font-bold flex items-center gap-2 transition-colors border-none cursor-pointer">
              <Save size={18} /> Guardar Ajustes Operativos
            </button>
          </div>
        )}

      </div>
    </div>
  );
};
