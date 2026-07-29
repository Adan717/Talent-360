import React, { useState, useEffect, useRef } from 'react';
import { useAppStore } from '../store/useAppStore';
import { Plus, Edit2, Trash2, Video, FileText, Search, GraduationCap, PlayCircle, Trophy, BookOpen, X, FileQuestion, FileBadge, CheckSquare, Users } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { MobileModuleBottomDock } from './common/MobileModuleBottomDock';

interface QuizQuestion {
  question: string;
  options: string[];
  correctAnswer: number;
}

export interface CertificateTemplate {
  id?: number;
  name: string;
  company_name: string;
  location: string;
  logo_url: string;
  director_name: string;
  director_signature_url: string;
  instructor_name: string;
  instructor_signature_url: string;
  primary_color: string;
  secondary_color: string;
}

interface Course {
  id?: number;
  title: string;
  description: string;
  video_url: string;
  course_type: 'induction' | 'training' | 'promotion';
  quiz_data: QuizQuestion[];
  certificate_template_id?: number | null;
  target_job_role_id?: number | null;
}

export const GestorAcademia = () => {
  const [courses, setCourses] = useState<Course[]>([]);
  const [templates, setTemplates] = useState<CertificateTemplate[]>([]);
  const [jobRoles, setJobRoles] = useState<any[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [editingCourse, setEditingCourse] = useState<Course | null>(null);
  const [editingTemplate, setEditingTemplate] = useState<CertificateTemplate | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isTemplateModalOpen, setIsTemplateModalOpen] = useState(false);
  const [activeTab, setActiveTab] = useState<'all' | 'induction' | 'training' | 'promotion' | 'templates'>('all');
  const [showFabMenu, setShowFabMenu] = useState(false);
  const searchInputRef = useRef<HTMLInputElement>(null);
  const [searchQuery, setSearchQuery] = useState('');

  // Importar desde plantillas
  const [showImportModal, setShowImportModal] = useState(false);
  const [courseTemplates, setCourseTemplates] = useState<any[]>([]);
  const [loadingImportTemplates, setLoadingImportTemplates] = useState(false);
  const [selectedImportTemplate, setSelectedImportTemplate] = useState<any>(null);
  const [importingTemplate, setImportingTemplate] = useState(false);

  const { systemSettings, updateSetting } = useAppStore();

  const fetchCourses = async () => {
    setIsLoading(true);
    try {
      // Cargar plantillas desde configuraciones globales (SaaS)
      if (systemSettings?.certificate_templates) {
        try {
          const parsed = JSON.parse(systemSettings.certificate_templates);
          if (Array.isArray(parsed)) setTemplates(parsed);
        } catch (e) { console.error("Error parsing templates", e); }
      }

      const res = await axiosInstance.get('/academy/courses');
      const data = res.data;
      if (data && data.courses && Array.isArray(data.courses)) {
        setCourses(data.courses);
        setJobRoles(Array.isArray(data.job_roles) ? data.job_roles : []);
      } else if (Array.isArray(data)) {
        setCourses(data); // Legacy fallback
      } else {
        setCourses([]);
      }
    } catch (e) {
      console.error(e);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchCourses();
  }, [systemSettings?.certificate_templates]);

  const fetchImportTemplates = async () => {
    try {
      setLoadingImportTemplates(true);
      const res = await axiosInstance.get('/academy/course-templates');
      setCourseTemplates(res.data || []);
    } catch (e) {
      console.error("Error fetching course templates", e);
    } finally {
      setLoadingImportTemplates(false);
    }
  };

  const handleImportCourseTemplate = async () => {
    if (!selectedImportTemplate) return;
    try {
      setImportingTemplate(true);
      
      const appState = useAppStore.getState();
      if (appState.isSandboxMode) {
        const mockCourse: Course = {
          id: Date.now(),
          title: selectedImportTemplate.title,
          description: selectedImportTemplate.description,
          video_url: selectedImportTemplate.video_url || '',
          course_type: selectedImportTemplate.course_type,
          quiz_data: selectedImportTemplate.quiz_data || [],
          certificate_template_id: null,
          target_job_role_id: null
        };
        setCourses([...courses, mockCourse]);
        alert("Curso importado exitosamente (Simulado en modo Sandbox).");
        setShowImportModal(false);
        setSelectedImportTemplate(null);
        return;
      }

      await axiosInstance.post(`/academy/course-templates/${selectedImportTemplate.id}/import`);
      alert("Curso importado exitosamente.");
      setShowImportModal(false);
      setSelectedImportTemplate(null);
      await fetchCourses();
    } catch (e) {
      console.error("Error importing course template", e);
      alert("Ocurrió un error al importar la plantilla de curso.");
    } finally {
      setImportingTemplate(false);
    }
  };

  useEffect(() => {
    if (showImportModal) {
      fetchImportTemplates();
    }
  }, [showImportModal]);

  const handleSaveTemplate = () => {
    if (!editingTemplate) return;
    
    let newTemplates = [...templates];
    if (editingTemplate.id) {
      newTemplates = newTemplates.map(t => t.id === editingTemplate.id ? editingTemplate : t);
    } else {
      newTemplates.push({ ...editingTemplate, id: Date.now() });
    }
    
    setTemplates(newTemplates);
    updateSetting('certificate_templates', JSON.stringify(newTemplates));
    setIsTemplateModalOpen(false);
  };

  const handleDeleteTemplate = (id: number) => {
    if (!confirm("¿Estás seguro de eliminar esta plantilla?")) return;
    const newTemplates = templates.filter(t => t.id !== id);
    setTemplates(newTemplates);
    updateSetting('certificate_templates', JSON.stringify(newTemplates));
    // Quitar la referencia en los cursos locales (idealmente también hacer PATCH al API)
    const updatedCourses = courses.map(c => c.certificate_template_id === id ? { ...c, certificate_template_id: null } : c);
    setCourses(updatedCourses);
  };

  const filteredCourses = courses.filter(c => {
    const matchesTab = activeTab === 'all' || c.course_type === activeTab;
    const matchesSearch = c.title.toLowerCase().includes(searchQuery.toLowerCase()) || c.description.toLowerCase().includes(searchQuery.toLowerCase());
    return matchesTab && matchesSearch;
  });

  const handleSaveCourse = async () => {
    if (!editingCourse) return;
    const isNew = !editingCourse.id;
    const url = '/academy/courses' + (isNew ? '' : `/${editingCourse.id}`);
    
    try {
      const res = await (isNew ? axiosInstance.post(url, editingCourse) : axiosInstance.put(url, editingCourse));
      setIsModalOpen(false);
      fetchCourses();
    } catch (e) {
      console.error(e);
      alert("Error al guardar el curso");
    }
  };

  const handleDelete = async (id: number) => {
    if(!confirm("¿Estás seguro de eliminar este curso?")) return;
    try {
      await axiosInstance.delete(`/academy/courses/${id}`);
      fetchCourses();
    } catch (e) {
      console.error(e);
    }
  };

  const openNewCourse = () => {
    setEditingCourse({
      title: '',
      description: '',
      video_url: '',
      course_type: 'induction',
      quiz_data: [],
      certificate_template_id: null,
      target_job_role_id: null
    });
    setIsModalOpen(true);
  };

  const openNewTemplate = () => {
    setEditingTemplate({
      name: '',
      company_name: 'Talent360',
      location: 'Irapuato, Gto.',
      logo_url: '',
      director_name: 'Director General',
      director_signature_url: '',
      instructor_name: 'Firma de Instructor',
      instructor_signature_url: '',
      primary_color: '#6b1e2e',
      secondary_color: '#b58c4c'
    });
    setIsTemplateModalOpen(true);
  };

  const addQuestion = () => {
    if(editingCourse) {
      setEditingCourse({
        ...editingCourse,
        quiz_data: [...editingCourse.quiz_data, { question: '', options: ['', '', '', ''], correctAnswer: 0 }]
      });
    }
  };

  return (
    <div className="h-full bg-white rounded-3xl p-8 border border-slate-200 text-slate-800 relative flex flex-col shadow-sm">
      
      <div className="hidden sm:block sticky -top-8 -mt-8 -mx-8 px-8 pt-6 pb-3 bg-slate-50/90 backdrop-blur-md z-20 transition-all border-b border-slate-200/50 mb-6">
        <div className="bg-white rounded-3xl p-3 border border-slate-200 shadow-sm">
          <div className="flex flex-col lg:flex-row justify-between items-center gap-4">
            <div className="flex items-center gap-2 bg-slate-50 p-1.5 rounded-xl overflow-x-auto w-full lg:w-auto scrollbar-none whitespace-nowrap">
              <button 
                onClick={() => setActiveTab('all')} 
                className={`flex-shrink-0 flex items-center justify-center gap-2 text-sm font-bold px-6 py-2 rounded-lg transition-all ${activeTab === 'all' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100/50'}`}
              >
                <BookOpen size={18} className={activeTab === 'all' ? 'text-blue-600' : 'text-slate-400'} />
                <span>Todos</span>
              </button>
              <button 
                onClick={() => setActiveTab('induction')} 
                className={`flex-shrink-0 flex items-center justify-center gap-2 text-sm font-bold px-6 py-2 rounded-lg transition-all ${activeTab === 'induction' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100/50'}`}
              >
                <BookOpen size={18} className={activeTab === 'induction' ? 'text-blue-600' : 'text-slate-400'} />
                <span>Inducción</span>
              </button>
              <button 
                onClick={() => setActiveTab('training')} 
                className={`flex-shrink-0 flex items-center justify-center gap-2 text-sm font-bold px-6 py-2 rounded-lg transition-all ${activeTab === 'training' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100/50'}`}
              >
                <PlayCircle size={18} className={activeTab === 'training' ? 'text-blue-600' : 'text-slate-400'} />
                <span>Entrenamiento</span>
              </button>
              <button 
                onClick={() => setActiveTab('promotion')} 
                className={`flex-shrink-0 flex items-center justify-center gap-2 text-sm font-bold px-6 py-2 rounded-lg transition-all ${activeTab === 'promotion' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100/50'}`}
              >
                <Trophy size={18} className={activeTab === 'promotion' ? 'text-blue-600' : 'text-slate-400'} />
                <span>Promoción</span>
              </button>
              <div className="w-px bg-slate-300 mx-2 self-stretch hidden sm:block"></div>
              <button 
                onClick={() => setActiveTab('templates')} 
                className={`flex-shrink-0 flex items-center justify-center gap-2 text-sm font-bold px-6 py-2 rounded-lg transition-all ${activeTab === 'templates' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100/50'}`}
              >
                <FileBadge size={18} className={activeTab === 'templates' ? 'text-white' : 'text-slate-400'} />
                <span>Diplomas</span>
              </button>
            </div>
          
          <div className="flex flex-col sm:flex-row gap-3 w-full lg:w-auto shrink-0 justify-between sm:justify-end">
            <div className="relative flex-1 sm:w-64">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
              <input 
                ref={searchInputRef}
                type="text" 
                placeholder="Buscar curso..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="w-full bg-white border border-slate-200 rounded-xl pl-10 pr-4 py-2 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none"
              />
            </div>
            
            <div className="hidden sm:flex items-center gap-3">
              {activeTab === 'templates' ? (
                <button 
                  onClick={openNewTemplate}
                  className="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2.5 rounded-xl text-sm font-bold flex items-center gap-2 transition-colors shadow-sm shrink-0"
                >
                  <Plus size={18} />
                  Nueva Plantilla
                </button>
              ) : (
                <>
                  <button 
                    onClick={() => setShowImportModal(true)}
                    className="bg-slate-150 hover:bg-slate-200 border border-slate-200 text-slate-700 px-4 py-2.5 rounded-xl font-bold transition-all shadow-sm flex items-center gap-2 text-sm shrink-0"
                  >
                    <BookOpen size={16} /> Importar
                  </button>
                  <button 
                    onClick={openNewCourse}
                    className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl font-bold transition-all shadow-sm flex items-center gap-2 text-sm shrink-0"
                  >
                    <Plus size={16} /> Crear Curso
                  </button>
                </>
              )}
            </div>
          </div>
        </div>
      </div>
      </div>

      {/* DOCK FLOTANTE INFERIOR MÓVIL (Estilo Reloj Checador con muesca SVG y subacciones) */}
      <MobileModuleBottomDock
        colorTheme="sky"
        activeTab={activeTab}
        onSelectTab={(tab) => setActiveTab(tab as any)}
        fabIcon={<Plus size={30} className="text-white relative z-10 animate-pulse" />}
        fabTitle="Acciones Rápidas Academia"
        subActions={[
          {
            id: 'new_template',
            label: 'Diploma',
            icon: <FileBadge size={18} />,
            onClick: openNewTemplate,
            colorClass: 'bg-indigo-100 text-indigo-600'
          },
          {
            id: 'new_course',
            label: 'Curso',
            icon: <Plus size={18} />,
            onClick: openNewCourse,
            colorClass: 'bg-sky-100 text-sky-600'
          }
        ]}
        items={[
          { id: 'all', label: 'Todos', icon: <BookOpen /> },
          { id: 'induction', label: 'Inducción', icon: <BookOpen /> },
          { id: 'training', label: 'Capacita', icon: <PlayCircle /> },
          { id: 'promotion', label: 'Promoción', icon: <Trophy /> },
          { id: 'templates', label: 'Diplomas', icon: <FileBadge /> }
        ]}
      />

      <div className="flex-1 overflow-y-auto custom-scrollbar pr-2 pb-24 sm:pb-10">
        {isLoading ? (
          <div className="flex justify-center items-center h-64">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
          </div>
        ) : activeTab === 'templates' ? (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {templates.filter(t => t.name.toLowerCase().includes(searchQuery.toLowerCase())).map(template => (
              <div key={template.id} className="bg-white rounded-2xl p-6 border border-slate-200 hover:border-indigo-300 hover:shadow-md transition-all flex flex-col group cursor-pointer relative overflow-hidden">
                <div className="absolute top-0 left-0 w-full h-1" style={{ backgroundColor: template.primary_color }}></div>
                <div className="flex justify-between items-start mb-4 mt-2">
                  <span className="px-3 py-1 text-[10px] uppercase tracking-wider font-black rounded-lg bg-indigo-50 text-indigo-700 border border-indigo-100">
                    Plantilla
                  </span>
                  <div className="flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button onClick={() => { setEditingTemplate(template); setIsTemplateModalOpen(true); }} className="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors"><Edit2 size={14}/></button>
                    <button onClick={() => handleDeleteTemplate(template.id!)} className="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors"><Trash2 size={14}/></button>
                  </div>
                </div>
                
                <h3 className="text-lg font-bold text-slate-900 mb-2 leading-tight">{template.name}</h3>
                <p className="text-slate-500 text-sm mb-1"><span className="font-semibold text-slate-700">Empresa:</span> {template.company_name}</p>
                <p className="text-slate-500 text-sm mb-4"><span className="font-semibold text-slate-700">Ubicación:</span> {template.location}</p>
                
                <div className="flex items-center gap-2 mt-auto">
                   <div className="w-6 h-6 rounded-full border border-slate-300" style={{ backgroundColor: template.primary_color }}></div>
                   <div className="w-6 h-6 rounded-full border border-slate-300" style={{ backgroundColor: template.secondary_color }}></div>
                </div>
              </div>
            ))}
            {templates.length === 0 && (
              <div className="col-span-full flex flex-col items-center justify-center py-20 text-slate-400">
                <FileBadge size={48} className="text-slate-200 mb-4" />
                <p className="text-lg font-medium text-slate-500">No hay plantillas creadas</p>
                <p className="text-sm">Agrega una nueva plantilla para emitir certificados personalizados.</p>
              </div>
            )}
          </div>
        ) : (
          <div className="space-y-8">
            {(() => {
              if (activeTab === 'all') {
                const grouped = (() => {
                  const groups: Record<string, { roleName: string; courses: Course[] }> = {
                    'general': { roleName: 'Cursos Generales / Inducción Común', courses: [] }
                  };
                  jobRoles.forEach(role => {
                    groups[String(role.id)] = { roleName: `Cursos para ${role.name}`, courses: [] };
                  });
                  filteredCourses.forEach(course => {
                    const roleId = course.target_job_role_id;
                    if (roleId && groups[String(roleId)]) {
                      groups[String(roleId)].courses.push(course);
                    } else {
                      groups['general'].courses.push(course);
                    }
                  });
                  return Object.entries(groups).filter(([_, g]) => g.courses.length > 0);
                })();

                if (grouped.length === 0) {
                  return (
                    <div className="flex flex-col items-center justify-center py-20 text-slate-400">
                      <FileBadge size={48} className="text-slate-200 mb-4" />
                      <p className="text-lg font-medium text-slate-500">No hay cursos disponibles</p>
                      <p className="text-sm">Agrega un curso nuevo para empezar.</p>
                    </div>
                  );
                }

                return grouped.map(([roleKey, group]) => (
                  <div key={roleKey} className="space-y-4 animate-in fade-in duration-300">
                    <div className="flex items-center gap-2 border-b border-slate-200/60 pb-2">
                      <div className="p-1.5 rounded-lg bg-blue-50 text-blue-600">
                        <Users size={15} />
                      </div>
                      <h4 className="text-sm font-black text-slate-800 tracking-tight uppercase">
                        {group.roleName}
                      </h4>
                      <span className="bg-slate-100 text-slate-500 border border-slate-200 text-[10px] font-black px-2 py-0.5 rounded-md">
                        {group.courses.length} {group.courses.length === 1 ? 'Curso' : 'Cursos'}
                      </span>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                      {group.courses.map(course => (
                        <div key={course.id} className="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200 hover:border-blue-300 hover:shadow-lg hover:-translate-y-0.5 transition-all duration-300 flex flex-col justify-between group cursor-pointer relative overflow-hidden">
                          <div>
                            <div className={`absolute top-0 left-0 w-full h-1 ${
                                course.course_type === 'induction' ? 'bg-sky-500' :
                                course.course_type === 'training' ? 'bg-emerald-500' :
                                'bg-purple-500'
                              }`}></div>
                            <div className="flex justify-between items-start mb-4 mt-1">
                              <span className={`px-2.5 py-1 text-[9px] uppercase tracking-wider font-black rounded-lg ${
                                course.course_type === 'induction' ? 'bg-sky-50 text-sky-700 border border-sky-100' :
                                course.course_type === 'training' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' :
                                'bg-purple-50 text-purple-700 border border-purple-100'
                              }`}>
                                {course.course_type === 'induction' ? 'Inducción' : course.course_type === 'training' ? 'Entrenamiento' : 'Promoción'}
                              </span>
                              <div className="flex gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button onClick={(e) => { e.stopPropagation(); setEditingCourse(course); setIsModalOpen(true); }} className="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center text-slate-400 hover:text-blue-600 hover:bg-blue-50 transition-all border-none cursor-pointer"><Edit2 size={13}/></button>
                                <button onClick={(e) => { e.stopPropagation(); handleDelete(course.id!); }} className="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center text-slate-400 hover:text-red-600 hover:bg-red-50 transition-all border-none cursor-pointer"><Trash2 size={13}/></button>
                              </div>
                            </div>
                            
                            <h3 className="text-base font-extrabold text-slate-900 mb-2 leading-snug group-hover:text-blue-600 transition-colors">{course.title}</h3>
                            <p className="text-slate-500 text-xs line-clamp-3 mb-6 font-medium leading-relaxed">{course.description}</p>
                          </div>
                          
                          <div className="flex items-center justify-between text-[10px] font-bold text-slate-400 border-t border-slate-100 pt-4 mt-auto">
                            <div className="flex items-center gap-3">
                              <div className="flex items-center gap-1"><Video size={12} className={course.video_url ? 'text-blue-500' : ''}/> {course.video_url ? 'Video' : 'Material'}</div>
                              <div className="flex items-center gap-1"><FileQuestion size={12} className={course.quiz_data?.length ? 'text-orange-500' : ''}/> {course.quiz_data?.length || 0} Preguntas</div>
                            </div>
                            <span className="text-blue-600 font-extrabold group-hover:underline flex items-center gap-0.5">
                              Iniciar <PlayCircle size={12} className="fill-current text-blue-600" />
                            </span>
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                ));
              } else {
                // Categoría específica seleccionada
                if (filteredCourses.length === 0) {
                  return (
                    <div className="flex flex-col items-center justify-center py-20 text-slate-400">
                      <FileBadge size={48} className="text-slate-200 mb-4" />
                      <p className="text-lg font-medium text-slate-500">No hay cursos en esta categoría</p>
                    </div>
                  );
                }

                return (
                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {filteredCourses.map(course => {
                      const role = jobRoles.find(r => r.id === course.target_job_role_id);
                      return (
                        <div key={course.id} className="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200 hover:border-blue-300 hover:shadow-lg hover:-translate-y-0.5 transition-all duration-300 flex flex-col justify-between group cursor-pointer relative overflow-hidden">
                          <div>
                            <div className={`absolute top-0 left-0 w-full h-1 ${
                                course.course_type === 'induction' ? 'bg-sky-500' :
                                course.course_type === 'training' ? 'bg-emerald-500' :
                                'bg-purple-500'
                              }`}></div>
                            <div className="flex justify-between items-start mb-4 mt-1">
                              <span className={`px-2.5 py-1 text-[9px] uppercase tracking-wider font-black rounded-lg ${
                                course.course_type === 'induction' ? 'bg-sky-50 text-sky-700 border border-sky-100' :
                                course.course_type === 'training' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' :
                                'bg-purple-50 text-purple-700 border border-purple-100'
                              }`}>
                                {course.course_type === 'induction' ? 'Inducción' : course.course_type === 'training' ? 'Entrenamiento' : 'Promoción'}
                              </span>
                              <div className="flex gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button onClick={(e) => { e.stopPropagation(); setEditingCourse(course); setIsModalOpen(true); }} className="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center text-slate-400 hover:text-blue-600 hover:bg-blue-50 transition-all border-none cursor-pointer"><Edit2 size={13}/></button>
                                <button onClick={(e) => { e.stopPropagation(); handleDelete(course.id!); }} className="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center text-slate-400 hover:text-red-600 hover:bg-red-50 transition-all border-none cursor-pointer"><Trash2 size={13}/></button>
                              </div>
                            </div>
                            
                            <h3 className="text-base font-extrabold text-slate-900 mb-1 leading-snug group-hover:text-blue-600 transition-colors">{course.title}</h3>
                            <span className="text-[9px] font-black text-slate-400 uppercase tracking-wider mb-2.5 block">
                              📌 {role ? role.name : 'Todos los puestos'}
                            </span>
                            <p className="text-slate-500 text-xs line-clamp-3 mb-6 font-medium leading-relaxed">{course.description}</p>
                          </div>
                          
                          <div className="flex items-center justify-between text-[10px] font-bold text-slate-400 border-t border-slate-100 pt-4 mt-auto">
                            <div className="flex items-center gap-3">
                              <div className="flex items-center gap-1"><Video size={12} className={course.video_url ? 'text-blue-500' : ''}/> {course.video_url ? 'Video' : 'Material'}</div>
                              <div className="flex items-center gap-1"><FileQuestion size={12} className={course.quiz_data?.length ? 'text-orange-500' : ''}/> {course.quiz_data?.length || 0} Preguntas</div>
                            </div>
                            <span className="text-blue-600 font-extrabold group-hover:underline flex items-center gap-0.5">
                              Iniciar <PlayCircle size={12} className="fill-current text-blue-600" />
                            </span>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                );
              }
            })()}
          </div>
        )}
      </div>

      {/* Se eliminó la configuración global de certificados */}

      {/* Editor Modal */}
      {isModalOpen && editingCourse && (
        <div className="fixed inset-0 z-[60] bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4 animate-in fade-in duration-200">
          <div className="bg-white border border-slate-200 rounded-3xl w-full max-w-4xl max-h-[90vh] flex flex-col shadow-2xl">
            <div className="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50 rounded-t-3xl">
              <h2 className="text-xl font-black text-slate-800 flex items-center gap-2"><BookOpen className="text-blue-600" size={20}/> {editingCourse.id ? 'Editar Curso' : 'Nuevo Curso'}</h2>
              <button onClick={() => setIsModalOpen(false)} className="w-8 h-8 flex items-center justify-center rounded-full bg-slate-200 text-slate-500 hover:text-slate-700 transition-colors"><X size={16}/></button>
            </div>
            
            <div className="p-8 overflow-y-auto flex-1 space-y-8 custom-scrollbar">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                  <label className="block text-sm font-bold text-slate-700 mb-2">Título del Curso</label>
                  <input 
                    type="text" 
                    value={editingCourse.title} 
                    onChange={e => setEditingCourse({...editingCourse, title: e.target.value})}
                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all text-sm font-medium"
                    placeholder="Ej. Inducción Talent 360"
                  />
                </div>
                <div>
                  <label className="block text-sm font-bold text-slate-700 mb-2">Categoría</label>
                  <select 
                    value={editingCourse.course_type}
                    onChange={e => setEditingCourse({...editingCourse, course_type: e.target.value as any})}
                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all text-sm font-medium"
                  >
                    <option value="induction">Inducción (Nuevos Ingresos)</option>
                    <option value="training">Entrenamiento (Capacitación Continua)</option>
                    <option value="promotion">Promoción (Plan de Carrera)</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-bold text-slate-700 mb-2">Plantilla de Certificado</label>
                  <select 
                    value={editingCourse.certificate_template_id || ''}
                    onChange={e => setEditingCourse({...editingCourse, certificate_template_id: e.target.value ? Number(e.target.value) : null})}
                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all text-sm font-medium"
                  >
                    <option value="">Sin Certificado</option>
                    {templates.map(t => (
                      <option key={t.id} value={t.id}>{t.name}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-bold text-slate-700 mb-2">Puesto Destino (Opcional)</label>
                  <select 
                    value={editingCourse.target_job_role_id || ''}
                    onChange={e => setEditingCourse({...editingCourse, target_job_role_id: e.target.value ? Number(e.target.value) : null})}
                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all text-sm font-medium"
                  >
                    <option value="">Todos los puestos / General</option>
                    {jobRoles.map(r => (
                      <option key={r.id} value={r.id}>{r.name}</option>
                    ))}
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-sm font-bold text-slate-700 mb-2 flex items-center gap-2"><Video size={16} className="text-slate-400"/> URL del Video (YouTube o MP4)</label>
                <input 
                  type="text" 
                  value={editingCourse.video_url} 
                  onChange={e => setEditingCourse({...editingCourse, video_url: e.target.value})}
                  className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all text-sm font-medium"
                  placeholder="https://www.youtube.com/watch?v=..."
                />
              </div>

              <div>
                <label className="block text-sm font-bold text-slate-700 mb-2 flex items-center gap-2"><FileText size={16} className="text-slate-400"/> Lo que aprenderás (Descripción)</label>
                <textarea 
                  value={editingCourse.description} 
                  onChange={e => setEditingCourse({...editingCourse, description: e.target.value})}
                  className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-slate-800 h-28 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all text-sm font-medium resize-none custom-scrollbar"
                  placeholder="Describe los objetivos de este curso..."
                />
              </div>

              <div className="border-t border-slate-100 pt-8">
                <div className="flex justify-between items-center mb-6">
                  <div>
                     <h3 className="text-lg font-black text-slate-800 flex items-center gap-2"><FileQuestion className="text-orange-500" size={20}/> Examen Final (Quiz)</h3>
                     <p className="text-sm font-medium text-slate-500 mt-1">Evalúa el conocimiento del empleado tras ver el material.</p>
                  </div>
                  <button onClick={addQuestion} className="bg-slate-50 border border-slate-200 hover:bg-slate-100 text-slate-600 px-4 py-2.5 rounded-xl text-sm font-bold transition-colors flex items-center gap-2 shadow-sm">
                    <Plus size={16}/> Añadir Pregunta
                  </button>
                </div>

                <div className="space-y-6">
                  {editingCourse.quiz_data.map((q, i) => (
                    <div key={i} className="bg-slate-50/50 p-6 rounded-2xl border border-slate-200 relative">
                      <button 
                        onClick={() => {
                          const newQuiz = [...editingCourse.quiz_data];
                          newQuiz.splice(i, 1);
                          setEditingCourse({...editingCourse, quiz_data: newQuiz});
                        }}
                        className="absolute top-6 right-6 w-8 h-8 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-400 hover:text-red-500 hover:border-red-200 hover:bg-red-50 transition-colors shadow-sm"
                      >
                        <Trash2 size={14}/>
                      </button>
                      <input 
                        type="text" 
                        value={q.question}
                        onChange={e => {
                          const newQuiz = [...editingCourse.quiz_data];
                          newQuiz[i].question = e.target.value;
                          setEditingCourse({...editingCourse, quiz_data: newQuiz});
                        }}
                        className="w-[90%] bg-transparent border-b-2 border-slate-200 focus:border-blue-500 pb-2 mb-6 text-slate-800 focus:outline-none font-bold text-lg transition-colors"
                        placeholder={`Pregunta ${i + 1}`}
                      />
                      
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {q.options.map((opt, j) => (
                          <div key={j} className={`flex items-center gap-3 p-3 rounded-xl border transition-all ${q.correctAnswer === j ? 'bg-emerald-50 border-emerald-200' : 'bg-white border-slate-200 focus-within:border-blue-300'}`}>
                            <input 
                              type="radio" 
                              name={`correct_${i}`} 
                              checked={q.correctAnswer === j}
                              onChange={() => {
                                const newQuiz = [...editingCourse.quiz_data];
                                newQuiz[i].correctAnswer = j;
                                setEditingCourse({...editingCourse, quiz_data: newQuiz});
                              }}
                              className="w-4 h-4 text-emerald-600 focus:ring-emerald-500 border-slate-300 cursor-pointer"
                            />
                            <input 
                              type="text" 
                              value={opt}
                              onChange={e => {
                                const newQuiz = [...editingCourse.quiz_data];
                                newQuiz[i].options[j] = e.target.value;
                                setEditingCourse({...editingCourse, quiz_data: newQuiz});
                              }}
                              className="w-full bg-transparent text-sm text-slate-700 focus:outline-none font-medium"
                              placeholder={`Opción ${['A','B','C','D'][j]}`}
                            />
                          </div>
                        ))}
                      </div>
                      <p className="text-xs text-slate-400 font-medium mt-4 flex items-center gap-1.5"><Trophy size={12}/> Selecciona el botón circular (radio) de la respuesta que sea correcta.</p>
                    </div>
                  ))}
                  {editingCourse.quiz_data.length === 0 && (
                     <div className="text-center py-8 text-slate-400 text-sm font-medium border-2 border-dashed border-slate-200 rounded-2xl bg-slate-50">
                        No hay preguntas en este examen. Los empleados solo verán el material y marcarán "Completado".
                     </div>
                  )}
                </div>
              </div>
            </div>
            
            <div className="p-6 border-t border-slate-100 flex justify-end gap-4 bg-slate-50 rounded-b-3xl shrink-0">
              <button onClick={() => setIsModalOpen(false)} className="px-6 py-2.5 text-slate-500 hover:text-slate-800 font-bold transition-colors">Cancelar</button>
              <button onClick={handleSaveCourse} className="bg-blue-600 hover:bg-blue-700 px-8 py-2.5 rounded-xl text-white font-bold transition-colors shadow-sm flex items-center gap-2">
                <CheckSquare size={18}/> Guardar Curso
              </button>
            </div>
          </div>
        </div>
      )}
      {/* Template Editor Modal */}
      {isTemplateModalOpen && editingTemplate && (
        <div className="fixed inset-0 z-[60] bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4 animate-in fade-in duration-200">
          <div className="bg-white border border-slate-200 rounded-3xl w-full max-w-4xl max-h-[90vh] flex flex-col shadow-2xl">
            <div className="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/50 rounded-t-3xl">
              <h2 className="text-xl font-black text-slate-800 flex items-center gap-2"><FileBadge className="text-indigo-600" size={20}/> {editingTemplate.id ? 'Editar Plantilla' : 'Nueva Plantilla'}</h2>
              <button onClick={() => setIsTemplateModalOpen(false)} className="w-8 h-8 flex items-center justify-center rounded-full bg-slate-200 text-slate-500 hover:text-slate-700 transition-colors"><X size={16}/></button>
            </div>
            
            <div className="p-8 overflow-y-auto flex-1 space-y-6 custom-scrollbar">
              
              <div>
                <label className="block text-sm font-bold text-slate-700 mb-2">Nombre de la Plantilla</label>
                <input 
                  type="text" 
                  value={editingTemplate.name} 
                  onChange={e => setEditingTemplate({...editingTemplate, name: e.target.value})}
                  className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all text-sm font-medium"
                  placeholder="Ej. Plantilla Corporativa Norte"
                />
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                  <label className="block text-sm font-bold text-slate-700 mb-2">Nombre de la Empresa</label>
                  <input 
                    type="text" 
                    value={editingTemplate.company_name} 
                    onChange={e => setEditingTemplate({...editingTemplate, company_name: e.target.value})}
                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all text-sm font-medium"
                  />
                </div>
                <div>
                  <label className="block text-sm font-bold text-slate-700 mb-2">Ubicación (Sede)</label>
                  <input 
                    type="text" 
                    value={editingTemplate.location} 
                    onChange={e => setEditingTemplate({...editingTemplate, location: e.target.value})}
                    className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all text-sm font-medium"
                  />
                </div>
              </div>

              <div>
                <label className="block text-sm font-bold text-slate-700 mb-2">URL del Logotipo (PNG/JPG)</label>
                <input 
                  type="text" 
                  value={editingTemplate.logo_url} 
                  onChange={e => setEditingTemplate({...editingTemplate, logo_url: e.target.value})}
                  className="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 text-slate-800 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all text-sm font-medium"
                  placeholder="https://ejemplo.com/logo.png"
                />
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="p-4 bg-slate-50 border border-slate-200 rounded-2xl space-y-4">
                  <h3 className="font-bold text-slate-800 flex items-center gap-2"><Users size={16} className="text-slate-500"/> Instructor / Aval</h3>
                  <div>
                    <label className="block text-xs font-bold text-slate-500 uppercase mb-1">Nombre</label>
                    <input type="text" value={editingTemplate.instructor_name} onChange={e => setEditingTemplate({...editingTemplate, instructor_name: e.target.value})} className="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"/>
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-slate-500 uppercase mb-1">Firma (URL)</label>
                    <input type="text" value={editingTemplate.instructor_signature_url} onChange={e => setEditingTemplate({...editingTemplate, instructor_signature_url: e.target.value})} className="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all" placeholder="https://...firma.png"/>
                  </div>
                </div>

                <div className="p-4 bg-slate-50 border border-slate-200 rounded-2xl space-y-4">
                  <h3 className="font-bold text-slate-800 flex items-center gap-2"><CheckSquare size={16} className="text-slate-500"/> Director General</h3>
                  <div>
                    <label className="block text-xs font-bold text-slate-500 uppercase mb-1">Nombre</label>
                    <input type="text" value={editingTemplate.director_name} onChange={e => setEditingTemplate({...editingTemplate, director_name: e.target.value})} className="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"/>
                  </div>
                  <div>
                    <label className="block text-xs font-bold text-slate-500 uppercase mb-1">Firma (URL)</label>
                    <input type="text" value={editingTemplate.director_signature_url} onChange={e => setEditingTemplate({...editingTemplate, director_signature_url: e.target.value})} className="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all" placeholder="https://...firma.png"/>
                  </div>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-6">
                 <div>
                    <label className="block text-sm font-bold text-slate-700 mb-2">Color Primario (Marco Principal)</label>
                    <div className="flex gap-3 items-center">
                      <input type="color" value={editingTemplate.primary_color} onChange={e => setEditingTemplate({...editingTemplate, primary_color: e.target.value})} className="w-12 h-12 rounded-lg cursor-pointer border border-slate-200"/>
                      <span className="text-sm font-medium text-slate-600">{editingTemplate.primary_color}</span>
                    </div>
                 </div>
                 <div>
                    <label className="block text-sm font-bold text-slate-700 mb-2">Color Secundario (Acentos)</label>
                    <div className="flex gap-3 items-center">
                      <input type="color" value={editingTemplate.secondary_color} onChange={e => setEditingTemplate({...editingTemplate, secondary_color: e.target.value})} className="w-12 h-12 rounded-lg cursor-pointer border border-slate-200"/>
                      <span className="text-sm font-medium text-slate-600">{editingTemplate.secondary_color}</span>
                    </div>
                 </div>
              </div>

            </div>
            
            <div className="p-6 border-t border-slate-100 flex justify-end gap-4 bg-slate-50 rounded-b-3xl shrink-0">
              <button onClick={() => setIsTemplateModalOpen(false)} className="px-6 py-2.5 text-slate-500 hover:text-slate-800 font-bold transition-colors">Cancelar</button>
              <button onClick={handleSaveTemplate} className="bg-indigo-600 hover:bg-indigo-700 px-8 py-2.5 rounded-xl text-white font-bold transition-colors shadow-sm flex items-center gap-2">
                <CheckSquare size={18}/> Guardar Plantilla
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL IMPORTAR CURSO DESDE PLANTILLAS */}
      {showImportModal && (
        <div className="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[60] flex items-center justify-center p-4 animate-in fade-in duration-200">
          <div className="bg-white border border-slate-200 rounded-3xl p-8 max-w-2xl w-full shadow-2xl relative max-h-[90vh] flex flex-col animate-in slide-in-from-bottom-4 duration-200">
            <button 
              type="button"
              onClick={() => { setShowImportModal(false); setSelectedImportTemplate(null); }} 
              className="absolute top-6 right-6 text-slate-400 hover:text-slate-600 bg-slate-50 p-2 rounded-full transition-colors"
            >
              <X size={20}/>
            </button>
            
            <h2 className="text-2xl font-black text-slate-900 mb-2 flex items-center gap-2">
              <BookOpen className="text-blue-600" />
              Importar Cursos desde Plantillas
            </h2>
            <p className="text-slate-500 text-sm mb-6">Selecciona uno de los cursos estándar de la industria para añadirlo automáticamente a tu catálogo de la Academia.</p>
            
            {/* LISTA DE PLANTILLAS */}
            <div className="flex-1 overflow-y-auto min-h-[250px] max-h-[400px] border border-slate-200 rounded-2xl p-4 bg-slate-50/50 space-y-2.5 custom-scrollbar mb-6">
              {loadingImportTemplates ? (
                <div className="h-full flex items-center justify-center py-12">
                  <div className="animate-spin text-blue-600 font-bold">Cargando plantillas...</div>
                </div>
              ) : courseTemplates.length === 0 ? (
                <div className="text-center py-12 text-slate-400 font-bold">
                  No se encontraron plantillas de cursos disponibles.
                </div>
              ) : (
                courseTemplates.map((tpl: any) => (
                  <div 
                    key={tpl.id}
                    onClick={() => setSelectedImportTemplate(tpl)}
                    className={`p-4 border rounded-xl cursor-pointer transition-all flex justify-between items-center ${
                      selectedImportTemplate?.id === tpl.id 
                        ? 'border-blue-500 bg-blue-50/50 shadow-sm' 
                        : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-sm'
                    }`}
                  >
                    <div className="flex-1 pr-4">
                      <div className="flex items-center gap-2 mb-1">
                        <h4 className="font-bold text-slate-800 text-base">{tpl.title}</h4>
                        <span className={`px-2 py-0.5 text-[9px] uppercase tracking-wider font-black rounded-md ${
                          tpl.course_type === 'induction' ? 'bg-sky-50 text-sky-700 border border-sky-100' :
                          tpl.course_type === 'training' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' :
                          'bg-purple-50 text-purple-700 border border-purple-100'
                        }`}>
                          {tpl.course_type === 'induction' ? 'Inducción' : tpl.course_type === 'training' ? 'Entrenamiento' : 'Promoción'}
                        </span>
                      </div>
                      <p className="text-slate-500 text-xs font-semibold line-clamp-2">{tpl.description}</p>
                      
                      <div className="flex gap-4 mt-2 text-[11px] text-slate-600 font-semibold bg-slate-100/50 p-2 rounded-lg border border-slate-100 w-fit">
                        {tpl.target_job_role_name && <span>Dirigido a: <strong className="text-slate-700">{tpl.target_job_role_name}</strong></span>}
                        {tpl.quiz_data?.length > 0 && <span>Preguntas: <strong className="text-slate-700">{tpl.quiz_data.length}</strong></span>}
                        {tpl.video_url && <span>Contiene Video</span>}
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>
            
            <div className="flex justify-end gap-3 border-t border-slate-100 pt-6">
              <button 
                type="button"
                onClick={() => { setShowImportModal(false); setSelectedImportTemplate(null); }} 
                className="px-5 py-2.5 border border-slate-200 rounded-xl text-sm font-bold text-slate-500 hover:text-slate-800 hover:bg-slate-50 transition-all"
              >
                Cancelar
              </button>
              <button 
                type="button"
                disabled={!selectedImportTemplate || importingTemplate}
                onClick={handleImportCourseTemplate}
                className="bg-blue-600 hover:bg-blue-700 disabled:bg-blue-300 text-white px-6 py-2.5 rounded-xl text-sm font-bold flex items-center gap-2 transition-all shadow-sm"
              >
                {importingTemplate ? 'Importando...' : 'Importar a mi Academia'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
