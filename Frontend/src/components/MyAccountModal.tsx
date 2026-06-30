import React, { useState, useEffect } from 'react';
import { 
  X, User, Lock, Clock, Check, AlertTriangle, 
  Camera, Sparkles, Key, Calendar, Send
} from 'lucide-react';
import { useAppStore } from '../store/useAppStore';
import axiosInstance from '../lib/axios';

interface MyAccountModalProps {
  isOpen: boolean;
  onClose: () => void;
}

interface RestDayRequest {
  id: number;
  type: string;
  status: 'pending' | 'approved' | 'rejected';
  justification_text: string;
  created_at: string;
  updated_at: string;
}

const PRESET_AVATARS = [
  'https://i.pravatar.cc/150?img=11', // Original CEO
  'https://i.pravatar.cc/150?img=12', // Professional Female
  'https://i.pravatar.cc/150?img=33', // Professional Male
  'https://i.pravatar.cc/150?img=47', // Creative Female
  'https://i.pravatar.cc/150?img=60', // Young Male
  'https://i.pravatar.cc/150?img=5',  // Young Female
  'https://i.pravatar.cc/150?img=68', // Senior Male
  'https://i.pravatar.cc/150?img=41'  // Friendly Female
];

export const MyAccountModal = ({ isOpen, onClose }: MyAccountModalProps) => {
  const { currentUser, setCurrentUser } = useAppStore();
  const [activeTab, setActiveTab] = useState<'profile' | 'security' | 'rest_day'>('profile');
  
  // Profile State
  const [name, setName] = useState(currentUser?.name || '');
  const [avatar, setAvatar] = useState(currentUser?.avatar || '');
  const [isUpdatingProfile, setIsUpdatingProfile] = useState(false);
  const [profileSuccess, setProfileSuccess] = useState(false);

  // Security State
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [newPasswordConfirmation, setNewPasswordConfirmation] = useState('');
  const [isChangingPassword, setIsChangingPassword] = useState(false);
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [passwordSuccess, setPasswordSuccess] = useState(false);

  // Rest Day Request State
  const [requestedDay, setRequestedDay] = useState('Domingo');
  const [justification, setJustification] = useState('');
  const [isSubmittingRestDay, setIsSubmittingRestDay] = useState(false);
  const [restDayRequests, setRestDayRequests] = useState<RestDayRequest[]>([]);
  const [restDaySuccess, setRestDaySuccess] = useState(false);
  const [restDayError, setRestDayError] = useState<string | null>(null);

  // Toast feedback
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  useEffect(() => {
    if (isOpen) {
      setName(currentUser?.name || '');
      setAvatar(currentUser?.avatar || '');
      fetchRestDayRequests();
      // Reset success/error states
      setProfileSuccess(false);
      setPasswordSuccess(false);
      setRestDaySuccess(false);
      setPasswordError(null);
      setRestDayError(null);
    }
  }, [isOpen, currentUser]);

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 3000);
  };

  const fetchRestDayRequests = async () => {
    try {
      const res = await axiosInstance.get('/me/rest-day-requests');
      setRestDayRequests(res.data.requests || []);
    } catch (err) {
      console.error("Error fetching rest day requests", err);
    }
  };

  const handleUpdateProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsUpdatingProfile(true);
    setProfileSuccess(false);
    try {
      const res = await axiosInstance.post('/me/update-profile', { name, avatar });
      const updatedUser = res.data.user;
      setCurrentUser({ ...updatedUser, system_role: updatedUser.role });
      setProfileSuccess(true);
      showToast('Perfil actualizado correctamente.');
    } catch (err: any) {
      alert(err.response?.data?.error || 'Error al actualizar el perfil.');
    } finally {
      setIsUpdatingProfile(false);
    }
  };

  const handleChangePassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (newPassword !== newPasswordConfirmation) {
      setPasswordError('La confirmación de la nueva contraseña no coincide.');
      return;
    }
    setIsChangingPassword(true);
    setPasswordError(null);
    setPasswordSuccess(false);
    try {
      await axiosInstance.post('/me/change-password', {
        current_password: currentPassword,
        new_password: newPassword,
        new_password_confirmation: newPasswordConfirmation
      });
      setPasswordSuccess(true);
      setCurrentPassword('');
      setNewPassword('');
      setNewPasswordConfirmation('');
      showToast('Contraseña actualizada correctamente.');
    } catch (err: any) {
      setPasswordError(err.response?.data?.error || 'Error al cambiar la contraseña.');
    } finally {
      setIsChangingPassword(false);
    }
  };

  const handleRequestRestDay = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!justification.trim()) {
      setRestDayError('Por favor redacta una justificación.');
      return;
    }
    setIsSubmittingRestDay(true);
    setRestDayError(null);
    setRestDaySuccess(false);
    try {
      await axiosInstance.post('/me/request-rest-day', {
        requested_day: requestedDay,
        justification: justification
      });
      setRestDaySuccess(true);
      setJustification('');
      showToast('Solicitud enviada correctamente.');
      fetchRestDayRequests();
    } catch (err: any) {
      setRestDayError(err.response?.data?.error || 'Error al enviar la solicitud.');
    } finally {
      setIsSubmittingRestDay(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 selection:bg-blue-100 animate-in fade-in duration-200">
      
      {/* Toast Notification */}
      {toastMessage && (
        <div className="fixed top-6 right-6 bg-slate-900 text-white font-semibold text-xs px-4 py-3 rounded-2xl shadow-xl z-50 flex items-center gap-2 animate-in slide-in-from-top-4 duration-300">
          <Check size={14} className="text-emerald-400" />
          {toastMessage}
        </div>
      )}

      <div className="bg-white w-full max-w-2xl rounded-3xl shadow-2xl border border-slate-100 overflow-hidden flex flex-col max-h-[85vh] animate-in zoom-in-95 duration-200">
        
        {/* Header */}
        <div className="bg-slate-900 text-white p-6 relative overflow-hidden shrink-0">
          <div className="absolute top-0 left-0 w-full h-full bg-blue-600/10 blur-3xl"></div>
          <div className="relative z-10 flex justify-between items-center">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center shadow-lg shadow-blue-500/20">
                <User size={20} className="text-white" />
              </div>
              <div>
                <h2 className="text-lg font-black tracking-tight">Mi Cuenta</h2>
                <p className="text-slate-400 text-xs mt-0.5">Administra tus datos de perfil, accesos y horarios</p>
              </div>
            </div>
            <button 
              onClick={onClose}
              className="p-2 hover:bg-white/10 rounded-xl transition-colors text-slate-400 hover:text-white"
            >
              <X size={20} />
            </button>
          </div>
        </div>

        {/* Tabs Bar */}
        <div className="flex border-b border-slate-100 bg-slate-50/50 p-2 shrink-0 overflow-x-auto custom-scrollbar">
          <button
            onClick={() => setActiveTab('profile')}
            className={`flex items-center gap-2 px-4 py-2.5 rounded-xl font-extrabold text-xs transition-all ${activeTab === 'profile' ? 'bg-white text-blue-600 shadow-sm border border-slate-100' : 'text-slate-500 hover:text-slate-700'}`}
          >
            <User size={14} />
            Mi Perfil
          </button>
          <button
            onClick={() => setActiveTab('security')}
            className={`flex items-center gap-2 px-4 py-2.5 rounded-xl font-extrabold text-xs transition-all ${activeTab === 'security' ? 'bg-white text-blue-600 shadow-sm border border-slate-100' : 'text-slate-500 hover:text-slate-700'}`}
          >
            <Key size={14} />
            Seguridad y Accesos
          </button>
          <button
            onClick={() => setActiveTab('rest_day')}
            className={`flex items-center gap-2 px-4 py-2.5 rounded-xl font-extrabold text-xs transition-all ${activeTab === 'rest_day' ? 'bg-white text-blue-600 shadow-sm border border-slate-100' : 'text-slate-500 hover:text-slate-700'}`}
          >
            <Calendar size={14} />
            Horario y Descanso
          </button>
        </div>

        {/* Content Container (Scrollable) */}
        <div className="flex-1 overflow-y-auto p-6 min-h-0 custom-scrollbar">
          
          {/* TAB 1: PROFILE */}
          {activeTab === 'profile' && (
            <form onSubmit={handleUpdateProfile} className="space-y-6">
              
              {/* Profile Card Summary */}
              <div className="bg-slate-50 p-4 rounded-2xl border border-slate-100 flex items-center gap-4">
                <div className="w-16 h-16 rounded-full bg-slate-200 border-2 border-white shadow-sm overflow-hidden relative group shrink-0">
                  <img 
                    src={avatar || 'https://i.pravatar.cc/150?img=11'} 
                    alt="Current Avatar" 
                    className="w-full h-full object-cover" 
                  />
                  <div className="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                    <Camera size={18} className="text-white" />
                  </div>
                </div>
                <div>
                  <h4 className="font-bold text-slate-800 text-sm">{currentUser?.name}</h4>
                  <p className="text-xs text-slate-500 font-medium mt-0.5">{currentUser?.email}</p>
                  <span className="inline-block bg-blue-50 text-blue-700 font-extrabold text-[10px] px-2 py-0.5 rounded-md mt-1.5 uppercase">
                    {currentUser?.role === 'admin' ? 'Administrador' : currentUser?.role === 'supervisor' ? 'Supervisor' : 'Colaborador'}
                  </span>
                </div>
              </div>

              {/* Edit Name */}
              <div>
                <label className="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-2">Nombre Completo</label>
                <input 
                  type="text" 
                  required
                  value={name}
                  onChange={e => setName(e.target.value)}
                  className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all font-medium text-slate-800 text-sm"
                  placeholder="Tu nombre completo"
                />
              </div>

              {/* Preset Avatars Selector */}
              <div>
                <label className="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                  <Sparkles size={12} className="text-amber-500" />
                  Selecciona una Imagen de Perfil
                </label>
                <div className="grid grid-cols-4 sm:grid-cols-8 gap-2 bg-slate-50 p-3 rounded-2xl border border-slate-100">
                  {PRESET_AVATARS.map((urlOption, index) => (
                    <button
                      key={index}
                      type="button"
                      onClick={() => setAvatar(urlOption)}
                      className={`w-12 h-12 rounded-full border-2 overflow-hidden transition-all relative ${avatar === urlOption ? 'border-blue-600 scale-105 shadow-sm shadow-blue-500/20' : 'border-transparent hover:border-slate-300'}`}
                    >
                      <img src={urlOption} alt={`Avatar Preset ${index}`} className="w-full h-full object-cover" />
                      {avatar === urlOption && (
                        <div className="absolute inset-0 bg-blue-600/10 flex items-center justify-center">
                          <div className="bg-blue-600 text-white rounded-full p-0.5">
                            <Check size={8} strokeWidth={4} />
                          </div>
                        </div>
                      )}
                    </button>
                  ))}
                </div>
              </div>

              {/* Custom Avatar URL */}
              <div>
                <label className="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-2">O pega una URL de Imagen personalizada</label>
                <input 
                  type="url" 
                  value={avatar}
                  onChange={e => setAvatar(e.target.value)}
                  className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all font-medium text-slate-800 text-xs"
                  placeholder="https://ejemplo.com/mi-foto.jpg"
                />
              </div>

              {/* Form Action */}
              <div className="flex justify-end gap-3 pt-2">
                <button
                  type="submit"
                  disabled={isUpdatingProfile}
                  className="bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs px-5 py-2.5 rounded-xl transition-all shadow-md shadow-blue-500/10 flex items-center gap-1.5 disabled:opacity-75"
                >
                  {isUpdatingProfile ? 'Guardando...' : 'Guardar Cambios'}
                </button>
              </div>

            </form>
          )}

          {/* TAB 2: SECURITY */}
          {activeTab === 'security' && (
            <form onSubmit={handleChangePassword} className="space-y-5">
              
              {passwordError && (
                <div className="bg-rose-50 border border-rose-100 text-rose-600 text-xs font-bold p-3.5 rounded-xl flex items-start gap-2 animate-in fade-in duration-200">
                  <AlertTriangle size={16} className="shrink-0" />
                  <span>{passwordError}</span>
                </div>
              )}

              {passwordSuccess && (
                <div className="bg-emerald-50 border border-emerald-100 text-emerald-600 text-xs font-bold p-3.5 rounded-xl flex items-start gap-2 animate-in fade-in duration-200">
                  <Check size={16} className="shrink-0" />
                  <span>Contraseña actualizada correctamente.</span>
                </div>
              )}

              <div>
                <label className="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-2">Contraseña Actual</label>
                <input 
                  type="password" 
                  required
                  value={currentPassword}
                  onChange={e => setCurrentPassword(e.target.value)}
                  className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all font-medium text-slate-800 text-sm"
                  placeholder="••••••••"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-2">Nueva Contraseña</label>
                <input 
                  type="password" 
                  required
                  value={newPassword}
                  onChange={e => setNewPassword(e.target.value)}
                  className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all font-medium text-slate-800 text-sm"
                  placeholder="Mínimo 6 caracteres"
                />
              </div>

              <div>
                <label className="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-2">Confirmar Nueva Contraseña</label>
                <input 
                  type="password" 
                  required
                  value={newPasswordConfirmation}
                  onChange={e => setNewPasswordConfirmation(e.target.value)}
                  className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all font-medium text-slate-800 text-sm"
                  placeholder="Escribe de nuevo tu nueva contraseña"
                />
              </div>

              <div className="flex justify-end gap-3 pt-2">
                <button
                  type="submit"
                  disabled={isChangingPassword}
                  className="bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs px-5 py-2.5 rounded-xl transition-all shadow-md shadow-blue-500/10 flex items-center gap-1.5 disabled:opacity-75"
                >
                  {isChangingPassword ? 'Cambiando...' : 'Cambiar Contraseña'}
                </button>
              </div>

            </form>
          )}

          {/* TAB 3: HORARIOS & DESCANSO */}
          {activeTab === 'rest_day' && (
            <div className="space-y-6">
              
              {/* Shift info */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="bg-slate-50 p-4 rounded-2xl border border-slate-100 flex items-center gap-3">
                  <div className="p-2.5 bg-blue-50 text-blue-600 rounded-xl shrink-0">
                    <Clock size={18} />
                  </div>
                  <div>
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Horario Laboral</p>
                    <p className="text-sm font-extrabold text-slate-700 mt-0.5">
                      {currentUser?.shiftStart?.slice(0, 5) || '08:00'} a {currentUser?.shiftEnd?.slice(0, 5) || '18:00'}
                    </p>
                  </div>
                </div>

                <div className="bg-slate-50 p-4 rounded-2xl border border-slate-100 flex items-center gap-3">
                  <div className="p-2.5 bg-emerald-50 text-emerald-600 rounded-xl shrink-0">
                    <Calendar size={18} />
                  </div>
                  <div>
                    <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Día de Descanso Oficial</p>
                    <p className="text-sm font-extrabold text-slate-700 mt-0.5">
                      {currentUser?.restDay || 'Domingo'}
                    </p>
                  </div>
                </div>
              </div>

              {/* Request rest day form */}
              <form onSubmit={handleRequestRestDay} className="bg-slate-50/50 p-5 rounded-2xl border border-slate-100 space-y-4">
                <h4 className="font-extrabold text-slate-800 text-sm flex items-center gap-1.5">
                  <Send size={14} className="text-blue-500" />
                  Solicitar Cambio de Día de Descanso
                </h4>

                {restDayError && (
                  <div className="bg-rose-50 border border-rose-100 text-rose-600 text-xs font-bold p-3 rounded-xl flex items-start gap-2">
                    <AlertTriangle size={14} className="shrink-0 mt-0.5" />
                    <span>{restDayError}</span>
                  </div>
                )}

                {restDaySuccess && (
                  <div className="bg-emerald-50 border border-emerald-100 text-emerald-600 text-xs font-bold p-3 rounded-xl flex items-start gap-2">
                    <Check size={14} className="shrink-0 mt-0.5" />
                    <span>Solicitud enviada con éxito. Un administrador la revisará.</span>
                  </div>
                )}

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Día Solicitado</label>
                    <select
                      value={requestedDay}
                      onChange={e => setRequestedDay(e.target.value)}
                      className="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent font-semibold text-slate-700 text-xs"
                    >
                      {['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'].map(day => (
                        <option key={day} value={day}>{day}</option>
                      ))}
                    </select>
                  </div>
                </div>

                <div>
                  <label className="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">Justificación o Razón</label>
                  <textarea
                    required
                    value={justification}
                    onChange={e => setJustification(e.target.value)}
                    rows={3}
                    placeholder="Describe los motivos del cambio..."
                    className="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent font-medium text-slate-700 text-xs resize-none"
                  ></textarea>
                </div>

                <div className="flex justify-end">
                  <button
                    type="submit"
                    disabled={isSubmittingRestDay}
                    className="bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-[10px] px-4 py-2 rounded-xl transition-all shadow-md shadow-blue-500/10 disabled:opacity-75"
                  >
                    {isSubmittingRestDay ? 'Enviando...' : 'Enviar Solicitud'}
                  </button>
                </div>
              </form>

              {/* Solicitudes anteriores */}
              <div>
                <h4 className="font-extrabold text-slate-800 text-xs uppercase tracking-wider mb-3">Historial de Solicitudes</h4>
                {restDayRequests.length === 0 ? (
                  <p className="text-slate-400 text-xs font-semibold text-center py-4 bg-slate-50 rounded-2xl border border-dashed border-slate-200">
                    No has realizado ninguna solicitud de descanso todavía.
                  </p>
                ) : (
                  <div className="border border-slate-100 rounded-2xl overflow-hidden bg-white max-h-48 overflow-y-auto custom-scrollbar">
                    <table className="w-full text-left text-xs border-collapse">
                      <thead>
                        <tr className="bg-slate-50 border-b border-slate-100 font-extrabold text-slate-500 text-[10px]">
                          <th className="p-3">Fecha</th>
                          <th className="p-3">Detalle / Justificación</th>
                          <th className="p-3 text-right">Estatus</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {restDayRequests.map((req) => (
                          <tr key={req.id} className="hover:bg-slate-50/50">
                            <td className="p-3 text-[11px] font-bold text-slate-400 whitespace-nowrap">
                              {new Date(req.created_at).toLocaleDateString('es-MX', { day: 'numeric', month: 'short' })}
                            </td>
                            <td className="p-3 font-medium text-slate-600 min-w-[200px]">
                              {req.justification_text}
                            </td>
                            <td className="p-3 text-right whitespace-nowrap">
                              <span className={`inline-block font-extrabold text-[9px] px-2 py-0.5 rounded-full ${
                                req.status === 'approved' 
                                  ? 'bg-emerald-50 text-emerald-700' 
                                  : req.status === 'rejected' 
                                  ? 'bg-rose-50 text-rose-700' 
                                  : 'bg-amber-50 text-amber-700'
                              }`}>
                                {req.status === 'approved' 
                                  ? 'Aprobado' 
                                  : req.status === 'rejected' 
                                  ? 'Rechazado' 
                                  : 'Pendiente'
                                }
                              </span>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>

            </div>
          )}

        </div>

      </div>
    </div>
  );
};
