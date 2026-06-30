import React, { useState, useEffect } from 'react';
import { Plus, Edit2, Briefcase, X, QrCode, Image, ListPlus, Trash2, HelpCircle } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { isLocalhost, getQrOrigin } from '../lib/qrHelper';

export default function GestorVacantes() {
  const [vacancies, setVacancies] = useState<any[]>([]);
  const [qrIpOverride, setQrIpOverride] = useState(localStorage.getItem('qr_origin_override') || '');
  const handleQrIpChange = (val: string) => {
    setQrIpOverride(val);
    localStorage.setItem('qr_origin_override', val);
  };
  const [jobRoles, setJobRoles] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  // Modal states
  const [showModal, setShowModal] = useState(false);
  const [showQRModal, setShowQRModal] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [formData, setFormData] = useState({
    title: '',
    job_role_id: '',
    work_type: 'Presencial',
    schedule: 'Lunes a Sábado 9:00 AM a 7:00 PM',
    salary_range: '',
    description: '',
    image_url: 'https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&w=600&q=80',
    requirements: [] as string[],
    is_active: 1
  });

  const [newRequirement, setNewRequirement] = useState('');

  const handleAddRequirement = () => {
    if (newRequirement.trim()) {
      setFormData(prev => ({
        ...prev,
        requirements: [...prev.requirements, newRequirement.trim()]
      }));
      setNewRequirement('');
    }
  };

  const handleRemoveRequirement = (index: number) => {
    setFormData(prev => ({
      ...prev,
      requirements: prev.requirements.filter((_, i) => i !== index)
    }));
  };

  const PRESET_IMAGES = [
    { name: 'Administrativa / Oficina', url: 'https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&w=600&q=80' },
    { name: 'Tienda / Retail', url: 'https://images.unsplash.com/photo-1441986300917-64674bd600d8?auto=format&fit=crop&w=600&q=80' },
    { name: 'Almacén / Logística', url: 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?auto=format&fit=crop&w=600&q=80' },
    { name: 'Atención al Cliente', url: 'https://images.unsplash.com/photo-1521791136364-7286475269a9?auto=format&fit=crop&w=600&q=80' },
    { name: 'Cocina / Restaurante', url: 'https://images.unsplash.com/photo-1556910103-1c02745aae4d?auto=format&fit=crop&w=600&q=80' },
    { name: 'Operativo / Taller', url: 'https://images.unsplash.com/photo-1504307651254-35680f356dfd?auto=format&fit=crop&w=600&q=80' }
  ];

  useEffect(() => {
    fetchInitialData();
  }, []);

  useEffect(() => {
    const handleNewVacancyEvent = () => {
      openNewModal();
    };
    window.addEventListener('trigger-new-vacancy', handleNewVacancyEvent);
    return () => {
      window.removeEventListener('trigger-new-vacancy', handleNewVacancyEvent);
    };
  }, [jobRoles]);

  const fetchInitialData = async () => {
    try {
      setLoading(true);
      const [vacRes, stateRes] = await Promise.all([
        axiosInstance.get('/admin/vacancies'),
        axiosInstance.get('/sync/state')
      ]);
      const vacData = vacRes.data;
      const stateData = stateRes.data;
      
      setVacancies(vacData);
      setJobRoles(stateData.job_roles || []);
    } catch (error) {
      console.error("Error fetching data", error);
    } finally {
      setLoading(false);
    }
  };

  const toggleStatus = async (id: number, currentStatus: any) => {
    try {
      const newStatus = (currentStatus === 1 || currentStatus === true) ? 0 : 1;
      await axiosInstance.put(`/admin/vacancies/${id}`, { is_active: newStatus });
      fetchInitialData();
    } catch (error) {
      console.error("Error toggling status", error);
    }
  };

  const openNewModal = () => {
    setEditingId(null);
    setFormData({
      title: '',
      job_role_id: jobRoles[0]?.id || '',
      work_type: 'Presencial',
      schedule: 'Lunes a Sábado 9:00 AM a 7:00 PM',
      salary_range: '',
      description: '',
      image_url: 'https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&w=600&q=80',
      requirements: [],
      is_active: 1
    });
    setShowModal(true);
  };

  const openEditModal = (v: any) => {
    setEditingId(v.id);
    setFormData({
      title: v.title || '',
      job_role_id: v.job_role_id || '',
      work_type: v.work_type || 'Presencial',
      schedule: v.schedule || 'Lunes a Sábado 9:00 AM a 7:00 PM',
      salary_range: v.salary_range || '',
      description: v.description || '',
      image_url: v.image_url || 'https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&w=600&q=80',
      requirements: Array.isArray(v.requirements) ? v.requirements : [],
      is_active: v.is_active ? 1 : 0
    });
    setShowModal(true);
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const url = editingId ? `/admin/vacancies/${editingId}` : `/admin/vacancies`;
        
      if (editingId) {
        await axiosInstance.put(url, formData);
      } else {
        await axiosInstance.post(url, formData);
      }
      
      setShowModal(false);
      fetchInitialData();
    } catch (error) {
      console.error("Error saving vacancy", error);
      alert("Hubo un error al guardar la vacante.");
    }
  };

  if (loading) return <div className="p-8 text-center text-slate-500">Cargando bolsa de trabajo...</div>;

  return (
    <div className="bg-white rounded-3xl p-4 sm:p-6 border border-slate-200 relative">
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
        <div>
          <h2 className="text-xl font-bold text-slate-900 flex items-center gap-2">
            <Briefcase size={20} className="text-blue-600" /> Gestor de Vacantes (ATS)
          </h2>
          <p className="text-sm text-slate-500 mt-1">Controla las vacantes visibles en la Web Pública de Empleos.</p>
        </div>
        <div className="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
          <button 
            onClick={() => setShowQRModal(true)}
            className="w-full sm:w-auto justify-center bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-4 py-2.5 rounded-lg font-medium shadow-sm transition-all text-sm flex items-center gap-2"
          >
            <QrCode size={16} /> Ver QR Público
          </button>
          <button 
            onClick={openNewModal}
            className="w-full sm:w-auto justify-center bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg font-medium shadow-sm transition-all text-sm flex items-center gap-2"
          >
            <Plus size={16} /> Nueva Vacante
          </button>
        </div>
      </div>

      {/* VISTA ESCRITORIO (TABLA) */}
      <div className="hidden md:block overflow-x-auto">
        <table className="w-full text-left border-collapse">
          <thead>
            <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 text-xs uppercase tracking-wider">
              <th className="p-4 font-semibold rounded-tl-xl">Puesto Oficial</th>
              <th className="p-4 font-semibold">Título Vacante</th>
              <th className="p-4 font-semibold">Tipo</th>
              <th className="p-4 font-semibold">Salario</th>
              <th className="p-4 font-semibold text-center">Web Pública</th>
              <th className="p-4 font-semibold rounded-tr-xl">Acciones</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {vacancies.map(v => (
              <tr key={v.id} className="hover:bg-slate-50 transition-colors">
                <td className="p-4">
                  <span className="inline-block bg-slate-100 text-slate-700 px-2.5 py-1 rounded-md text-xs font-bold border border-slate-200">
                    {v.role_name}
                  </span>
                </td>
                <td className="p-4">
                  <p className="font-bold text-slate-800 text-sm">{v.title}</p>
                </td>
                <td className="p-4 text-sm text-slate-600">{v.work_type}</td>
                <td className="p-4 text-sm text-emerald-600 font-semibold">{v.salary_range}</td>
                <td className="p-4 text-center">
                  <button 
                    onClick={() => toggleStatus(v.id, v.is_active)}
                    className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none ${v.is_active ? 'bg-blue-600' : 'bg-slate-300'}`}
                  >
                    <span className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform shadow-sm ${v.is_active ? 'translate-x-6' : 'translate-x-1'}`} />
                  </button>
                </td>
                <td className="p-4 text-sm">
                  <button 
                    onClick={() => openEditModal(v)}
                    className="text-slate-400 hover:text-blue-600 font-medium p-2 hover:bg-blue-50 rounded-lg transition-colors"
                  >
                    <Edit2 size={16} />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* VISTA MÓVIL (TARJETAS) */}
      <div className="block md:hidden space-y-4">
        {vacancies.map(v => (
          <div key={v.id} className="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm relative">
            <div className="flex justify-between items-start gap-4 mb-3">
              <div>
                <span className="inline-block bg-slate-100 text-slate-700 px-2 py-0.5 rounded text-[10px] font-bold border border-slate-200">
                  {v.role_name}
                </span>
                <h4 className="font-bold text-slate-800 text-sm mt-1">{v.title}</h4>
              </div>
              <button 
                onClick={() => openEditModal(v)}
                className="text-slate-400 hover:text-blue-600 p-1.5 hover:bg-blue-50 rounded-lg transition-colors shrink-0"
              >
                <Edit2 size={16} />
              </button>
            </div>
            <div className="grid grid-cols-2 gap-2 text-xs border-t border-slate-100 pt-3">
              <div>
                <p className="text-slate-400">Modalidad</p>
                <p className="font-semibold text-slate-700">{v.work_type}</p>
              </div>
              <div>
                <p className="text-slate-400">Salario</p>
                <p className="font-semibold text-emerald-600">{v.salary_range}</p>
              </div>
              <div className="col-span-2 flex items-center justify-between mt-1 bg-slate-50 p-2 rounded-xl">
                <span className="text-xs text-slate-500 font-medium">Publicada en Web Pública</span>
                <button 
                  onClick={() => toggleStatus(v.id, v.is_active)}
                  className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none shrink-0 ${v.is_active ? 'bg-blue-600' : 'bg-slate-300'}`}
                >
                  <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform shadow-sm ${v.is_active ? 'translate-x-4' : 'translate-x-0.5'}`} />
                </button>
              </div>
            </div>
          </div>
        ))}
      </div>

      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4 overflow-y-auto">
          <div className="bg-white rounded-3xl max-w-4xl w-full p-4 sm:p-8 shadow-2xl border border-slate-100 relative my-8 animate-slide-up max-h-[90vh] overflow-y-auto">
             <button onClick={() => setShowModal(false)} className="absolute top-6 right-6 text-slate-400 hover:text-slate-600 bg-slate-50 p-2 rounded-full transition-all"><X size={20}/></button>
            <h3 className="text-2xl font-black text-slate-900 mb-6 flex items-center gap-2">
              <Briefcase className="text-blue-600" size={24} />
              {editingId ? 'Editar Vacante' : 'Crear Nueva Vacante'}
            </h3>
            
            <form onSubmit={handleSave} className="space-y-6">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                
                {/* COLUMNA IZQUIERDA: Información Básica */}
                <div className="space-y-4">
                  <div className="bg-slate-50 p-5 rounded-2xl border border-slate-100 space-y-4">
                    <h4 className="font-bold text-xs uppercase tracking-wider text-slate-400 mb-2">Información de la Vacante</h4>
                    
                    <div>
                      <label className="block text-xs font-bold text-slate-600 uppercase mb-1.5 ml-1">Título Público de la Vacante</label>
                      <input 
                        type="text" 
                        required
                        className="w-full bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all font-semibold"
                        value={formData.title}
                        onChange={e => setFormData({...formData, title: e.target.value})}
                        placeholder="Ej. Gerente de Sucursal"
                      />
                    </div>

                    <div>
                      <label className="block text-xs font-bold text-slate-600 uppercase mb-1.5 ml-1">Puesto Oficial (Backend)</label>
                      <select 
                        className="w-full bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all font-semibold"
                        value={formData.job_role_id}
                        onChange={e => setFormData({...formData, job_role_id: e.target.value})}
                        required
                      >
                        <option value="">Selecciona un puesto oficial...</option>
                        {jobRoles.filter((r: any) => r.is_active !== false || String(r.id) === String(formData.job_role_id)).map(r => (
                          <option key={r.id} value={r.id}>{r.name}</option>
                        ))}
                      </select>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                      <div>
                        <label className="block text-xs font-bold text-slate-600 uppercase mb-1.5 ml-1">Modalidad</label>
                        <select 
                          className="w-full bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all font-semibold"
                          value={formData.work_type}
                          onChange={e => setFormData({...formData, work_type: e.target.value})}
                        >
                          <option>Presencial</option>
                          <option>Híbrido</option>
                          <option>Remoto</option>
                        </select>
                      </div>
                      <div>
                        <label className="block text-xs font-bold text-slate-600 uppercase mb-1.5 ml-1">Rango Salarial</label>
                        <input 
                          type="text" 
                          className="w-full bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all font-semibold"
                          value={formData.salary_range}
                          onChange={e => setFormData({...formData, salary_range: e.target.value})}
                          placeholder="Ej. $12,000 - $15,000 Mensuales"
                          required
                        />
                      </div>
                    </div>

                    <div>
                      <label className="block text-xs font-bold text-slate-600 uppercase mb-1.5 ml-1">Horario y Jornada</label>
                      <input 
                        type="text" 
                        className="w-full bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all font-semibold"
                        value={formData.schedule}
                        onChange={e => setFormData({...formData, schedule: e.target.value})}
                        placeholder="Ej. Lunes a Sábado 9:00 AM a 7:00 PM"
                        required
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-xs font-bold text-slate-600 uppercase mb-1.5 ml-1">Descripción de la vacante</label>
                    <textarea 
                      className="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm h-32 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all font-semibold leading-relaxed"
                      value={formData.description}
                      onChange={e => setFormData({...formData, description: e.target.value})}
                      placeholder="Redacta los detalles, funciones principales y oferta de valor de la posición..."
                      required
                    />
                  </div>
                </div>

                {/* COLUMNA DERECHA: Imagen y Requisitos */}
                <div className="space-y-4">
                  {/* Gestor Dinámico de Requisitos */}
                  <div className="bg-slate-50 p-5 rounded-2xl border border-slate-100 space-y-4 flex flex-col h-[280px]">
                    <h4 className="font-bold text-xs uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
                      <ListPlus size={14} className="text-blue-500" />
                      Requisitos de la Vacante
                    </h4>
                    
                    <div className="flex gap-2">
                      <input 
                        type="text"
                        className="flex-grow bg-white border border-slate-200 rounded-xl px-4 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 font-semibold"
                        placeholder="Ej. Experiencia mínima de 2 años en gerencia"
                        value={newRequirement}
                        onChange={e => setNewRequirement(e.target.value)}
                        onKeyDown={e => {
                          if (e.key === 'Enter') {
                            e.preventDefault();
                            handleAddRequirement();
                          }
                        }}
                      />
                      <button 
                        type="button"
                        onClick={handleAddRequirement}
                        className="bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs px-4 py-2 rounded-xl transition-all shadow-sm shrink-0"
                      >
                        Agregar
                      </button>
                    </div>

                    <div className="flex-grow overflow-y-auto bg-white border border-slate-200 rounded-xl p-3 space-y-2 custom-scrollbar">
                      {formData.requirements.length === 0 ? (
                        <div className="h-full flex items-center justify-center text-slate-400 text-xs italic">
                          Sin requisitos agregados todavía.
                        </div>
                      ) : (
                        formData.requirements.map((req, idx) => (
                          <div key={idx} className="flex justify-between items-center gap-2 bg-slate-50 border border-slate-100 p-2 rounded-lg text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors">
                            <span className="truncate">{req}</span>
                            <button 
                              type="button"
                              onClick={() => handleRemoveRequirement(idx)}
                              className="text-slate-400 hover:text-red-500 p-1 rounded transition-colors"
                              title="Eliminar requisito"
                            >
                              <Trash2 size={13} />
                            </button>
                          </div>
                        ))
                      )}
                    </div>
                  </div>

                  {/* Imagen de la Vacante */}
                  <div className="bg-slate-50 p-5 rounded-2xl border border-slate-100 space-y-4">
                    <h4 className="font-bold text-xs uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
                      <Image size={14} className="text-blue-500" />
                      Diseño / Imagen de Portada
                    </h4>

                    {/* Previsualización rápida */}
                    <div className="h-24 w-full bg-slate-200 rounded-xl overflow-hidden relative border border-slate-200">
                      {formData.image_url ? (
                        <img src={formData.image_url} alt="Vista previa de vacante" className="w-full h-full object-cover" />
                      ) : (
                        <div className="h-full w-full flex items-center justify-center text-slate-400 text-xs italic">Sin imagen</div>
                      )}
                      <div className="absolute inset-0 bg-black/40 flex items-end p-2.5">
                        <span className="text-[10px] font-bold text-white uppercase bg-blue-600/80 backdrop-blur-sm px-2 py-0.5 rounded">Vista Previa</span>
                      </div>
                    </div>

                    <div>
                      <label className="block text-[10px] font-extrabold text-slate-500 uppercase mb-1 ml-1">URL de la Imagen (Opcional)</label>
                      <input 
                        type="url"
                        className="w-full bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all font-medium"
                        value={formData.image_url}
                        onChange={e => setFormData({...formData, image_url: e.target.value})}
                        placeholder="Pega cualquier enlace de imagen..."
                      />
                    </div>

                    {/* Galería de presets prediseñados */}
                    <div>
                      <label className="block text-[10px] font-extrabold text-slate-500 uppercase mb-2 ml-1">Galería Prediseñada (Selección Rápida)</label>
                      <div className="grid grid-cols-3 gap-2">
                        {PRESET_IMAGES.map((img, idx) => (
                          <button
                            key={idx}
                            type="button"
                            onClick={() => setFormData({...formData, image_url: img.url})}
                            className={`group relative h-12 rounded-lg overflow-hidden border transition-all ${formData.image_url === img.url ? 'ring-2 ring-blue-500 border-blue-500' : 'border-slate-200 hover:border-blue-400'}`}
                            title={img.name}
                          >
                            <img src={img.url} alt={img.name} className="w-full h-full object-cover transition-transform group-hover:scale-105" />
                            <div className="absolute inset-0 bg-black/50 hover:bg-black/30 transition-all flex items-center justify-center p-1">
                              <span className="text-[8px] font-extrabold text-white text-center leading-tight uppercase truncate">{img.name.split(' ')[0]}</span>
                            </div>
                          </button>
                        ))}
                      </div>
                    </div>
                  </div>
                </div>

              </div>

              {/* Botonera de Envío */}
              <div className="flex gap-4 pt-4 mt-6 border-t border-slate-100">
                <button 
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="flex-1 bg-slate-100 text-slate-700 hover:bg-slate-200 px-4 py-3.5 rounded-xl font-bold transition-all text-sm"
                >
                  Cancelar
                </button>
                <button 
                  type="submit"
                  className="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-3.5 rounded-xl font-bold shadow-md transition-all text-sm shadow-blue-500/10"
                >
                  Guardar Vacante
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal del Código QR */}
      {showQRModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4 animate-fade-in">
          <div className="bg-white rounded-3xl max-w-sm w-full p-8 shadow-xl border border-slate-100 relative overflow-hidden flex flex-col items-center text-center">
             <button onClick={() => setShowQRModal(false)} className="absolute top-6 right-6 text-slate-400 hover:text-slate-600 bg-slate-50 p-2 rounded-full"><X size={20}/></button>
             <QrCode size={48} className="text-blue-600 mb-4" />
             <h3 className="text-2xl font-bold text-slate-900 mb-2">Portal Público</h3>
             <p className="text-sm text-slate-500 mb-4">Imprime este código QR y colócalo en la entrada de la sucursal o compártelo en redes sociales.</p>
             
             <div className="bg-slate-50 p-4 rounded-2xl border border-slate-200 w-full flex justify-center mb-4">
                {/* Generador de QR dinámico usando la API gratuita de qrserver */}
                <img src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(getQrOrigin(qrIpOverride) + '?module=web')}`} alt="QR Portal Vacantes" className="w-48 h-48 rounded-xl shadow-sm mix-blend-multiply" />
             </div>

             {isLocalhost() && (
                <div className="p-3 bg-amber-50 border border-amber-200/80 rounded-xl text-left w-full mb-4">
                  <span className="text-[10px] font-bold text-amber-800 uppercase block mb-1">🔌 Desarrollo Local: Configuración de QR</span>
                  <p className="text-[9px] text-amber-700 leading-relaxed mb-2">
                    Ingresa la IP local de tu PC (ej: <code className="bg-amber-100 px-1 rounded font-mono">192.168.1.75:5173</code>) para que tu celular pueda acceder:
                  </p>
                  <input
                    type="text"
                    placeholder="ej: 192.168.1.75:5173"
                    value={qrIpOverride}
                    onChange={(e) => handleQrIpChange(e.target.value)}
                    className="w-full text-[11px] bg-white border border-amber-300 px-2 py-1 rounded-md text-slate-700 focus:outline-none focus:ring-1 focus:ring-amber-500 placeholder-slate-400 font-mono"
                  />
                </div>
             )}

             <p className="text-xs font-bold text-blue-600 bg-blue-50 px-3 py-1.5 rounded-lg border border-blue-100">¡Escanea para probar!</p>
          </div>
        </div>
      )}
    </div>
  );
}
