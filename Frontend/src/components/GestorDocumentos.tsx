import React, { useState, useEffect } from 'react';
import { 
  Folder, FileText, UploadCloud, CheckCircle2, AlertCircle, 
  Eye, Link2, Search, X, Loader2, ArrowRight, FolderOpen, 
  BookOpen, ShieldCheck, ChevronRight, FileCheck, ExternalLink
} from 'lucide-react';
import { useAppStore } from '../store/useAppStore';
import axiosInstance from '../lib/axios';
import { MobileModuleBottomDock } from './common/MobileModuleBottomDock';

interface EmployeeDoc {
  id: string;
  name: string;
  type: string;
  status: 'validado' | 'pendiente' | 'rechazado';
  uploadedAt: string;
  size: string;
  contentUrl?: string;
}

export const GestorDocumentos = () => {
  const { globalUsers, currentTier } = useAppStore();
  const [activeTab, setActiveTab] = useState<'employees' | 'company'>('employees');
  const [searchQuery, setSearchQuery] = useState('');
  
  // Selected employee folder state
  const [selectedEmployeeId, setSelectedEmployeeId] = useState<number | null>(null);
  
  // Modal states
  const [previewDoc, setPreviewDoc] = useState<any | null>(null);
  const [isUploading, setIsUploading] = useState(false);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [isLinkingDoc, setIsLinkingDoc] = useState<any | null>(null);
  
  // Mock courses for linking (normally fetched from LMS)
  const [courses, setCourses] = useState<any[]>([]);
  const [loadingCourses, setLoadingCourses] = useState(false);

  // Initial mock documents for employees
  const [employeeDocs, setEmployeeDocs] = useState<Record<number, EmployeeDoc[]>>({});

  // Mock corporate manuals
  const [corporateDocs, setCorporateDocs] = useState<any[]>([
    {
      id: 'corp1',
      name: 'Manual de Operación de Cajas v2.pdf',
      category: 'Manuales de Operación',
      uploadedAt: '2026-05-10',
      size: '2.4 MB',
      linkedCourse: 'Capacitación Básica de Cajas',
      content: 'Este manual describe el protocolo de cobro, arqueos parciales de efectivo y el uso de terminales bancarias.'
    },
    {
      id: 'corp2',
      name: 'Protocolo de Seguridad y Apertura v1.2.pdf',
      category: 'Seguridad',
      uploadedAt: '2026-06-01',
      size: '1.8 MB',
      linkedCourse: 'Protocolo de Seguridad y Llaves',
      content: 'Este protocolo define las medidas preventivas durante la apertura de la sucursal y la custodia de llaves.'
    },
    {
      id: 'corp3',
      name: 'Manual de Atención al Cliente y Resolución de Conflictos.pdf',
      category: 'Servicio al Cliente',
      uploadedAt: '2026-06-20',
      size: '3.1 MB',
      linkedCourse: null,
      content: 'Lineamientos para brindar una experiencia de compra premium y gestionar quejas o incidencias en el piso de venta.'
    }
  ]);

  // Fetch academy courses to allow linking corporate manuals
  useEffect(() => {
    const fetchCourses = async () => {
      setLoadingCourses(true);
      try {
        const res = await axiosInstance.get('/academy/courses');
        const data = res.data;
        if (data && data.courses) {
          setCourses(data.courses);
        } else if (Array.isArray(data)) {
          setCourses(data);
        }
      } catch (e) {
        console.error("Error fetching courses for linking", e);
        // Fallback mock courses
        setCourses([
          { id: 1, title: 'Inducción de Cajeros' },
          { id: 2, title: 'Protocolo de Seguridad y Llaves' },
          { id: 3, title: 'Servicio al Cliente Premium' }
        ]);
      } finally {
        setLoadingCourses(false);
      }
    };
    fetchCourses();
  }, []);

  // Initialize employee documents dynamically based on globalUsers
  useEffect(() => {
    const initialDocs: Record<number, EmployeeDoc[]> = {};
    globalUsers.forEach(u => {
      // Custom role-based document
      let roleDocName = 'Certificado Médico.pdf';
      if (u.role?.toLowerCase().includes('almacen')) roleDocName = 'Licencia de Conducir C.pdf';
      if (u.role?.toLowerCase().includes('gerente')) roleDocName = 'Contrato de Confidencialidad.pdf';

      initialDocs[u.id] = [
        { id: `${u.id}_solicitud`, name: 'Solicitud de Empleo.pdf', type: 'Solicitud', status: 'validado', uploadedAt: '2026-04-15', size: '1.2 MB' },
        { id: `${u.id}_acta`, name: 'Acta de Nacimiento.pdf', type: 'Identidad', status: 'validado', uploadedAt: '2026-04-15', size: '2.5 MB' },
        { id: `${u.id}_ine`, name: 'Identificación Oficial (INE).pdf', type: 'Identidad', status: 'validado', uploadedAt: '2026-04-16', size: '950 KB' },
        { id: `${u.id}_rfc`, name: 'Cédula de RFC / SAT.pdf', type: 'SAT', status: 'pendiente', uploadedAt: '-', size: '-' },
        { id: `${u.id}_role`, name: roleDocName, type: 'Puesto', status: 'validado', uploadedAt: '2026-04-20', size: '1.5 MB' }
      ];
    });
    setEmployeeDocs(initialDocs);
  }, [globalUsers]);

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file || selectedEmployeeId === null) return;

    setIsUploading(true);
    setUploadProgress(10);
    
    // Simulate upload progress animation
    const interval = setInterval(() => {
      setUploadProgress(prev => {
        if (prev >= 100) {
          clearInterval(interval);
          setTimeout(() => {
            setIsUploading(false);
            setUploadProgress(0);
            
            // Add new document
            const newDoc: EmployeeDoc = {
              id: `doc_${Date.now()}`,
              name: file.name,
              type: 'Otros',
              status: 'validado',
              uploadedAt: new Date().toISOString().slice(0, 10),
              size: `${(file.size / (1024 * 1024)).toFixed(1)} MB`
            };
            
            setEmployeeDocs(prevDocs => ({
              ...prevDocs,
              [selectedEmployeeId]: [
                ...prevDocs[selectedEmployeeId].filter(d => d.name !== file.name),
                newDoc
              ]
            }));
          }, 400);
          return 100;
        }
        return prev + 15;
      });
    }, 150);
  };

  const handleLinkManual = (courseTitle: string) => {
    if (!isLinkingDoc) return;
    
    setCorporateDocs(prev => prev.map(d => 
      d.id === isLinkingDoc.id ? { ...d, linkedCourse: courseTitle } : d
    ));
    
    setIsLinkingDoc(null);
    alert(`Documento vinculado con éxito al curso "${courseTitle}" en la Academia 360.`);
  };

  const selectedEmployee = globalUsers.find(u => u.id === selectedEmployeeId);
  const selectedDocs = selectedEmployeeId !== null ? (employeeDocs[selectedEmployeeId] || []) : [];

  const filteredEmployees = globalUsers.filter(u => 
    u.name.toLowerCase().includes(searchQuery.toLowerCase()) || 
    u.role.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const filteredCorporate = corporateDocs.filter(d => 
    d.name.toLowerCase().includes(searchQuery.toLowerCase()) || 
    d.category.toLowerCase().includes(searchQuery.toLowerCase())
  );

  return (
    <div className="h-full bg-slate-50 flex flex-col font-sans">
      
      {/* Header Sticky (Escritorio) */}
      <header className="sticky -top-8 -mt-8 -mx-8 px-8 pt-6 pb-3 bg-slate-50/90 backdrop-blur-md z-20 transition-all border-b border-slate-200/50 mb-6">
        <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex justify-between items-center gap-4">
          <div>
            <h1 className="text-2xl font-black text-slate-800">Gestor Documental y Expedientes</h1>
            <p className="text-sm text-slate-500">Expedientes de colaboradores y almacén corporativo de manuales oficiales.</p>
          </div>
          <div className="hidden sm:flex gap-2 p-1 bg-slate-100 rounded-xl border border-slate-200">
            <button 
              onClick={() => { setActiveTab('employees'); setSelectedEmployeeId(null); }}
              className={`px-4 py-2 rounded-lg text-xs font-bold transition-all ${activeTab === 'employees' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
            >
              📂 Expedientes Colaboradores
            </button>
            <button 
              onClick={() => { setActiveTab('company'); }}
              className={`px-4 py-2 rounded-lg text-xs font-bold transition-all ${activeTab === 'company' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
            >
              🏢 Documentos Corporativos
            </button>
          </div>
        </div>
      </header>

      {/* DOCK FLOTANTE INFERIOR MÓVIL (Estilo Reloj Checador con muesca SVG y FAB ámbar) */}
      <MobileModuleBottomDock
        colorTheme="amber"
        activeTab={activeTab}
        onSelectTab={(tab) => {
          setActiveTab(tab as any);
          if (tab === 'employees') setSelectedEmployeeId(null);
        }}
        fabIcon={<UploadCloud size={28} className="text-white relative z-10 animate-pulse" />}
        onFabClick={() => setActiveTab('company')}
        fabTitle="Subir Documento / Expediente"
        items={[
          { id: 'employees', label: 'Expedientes', icon: <FileText /> },
          { id: 'company', label: 'Corporativo', icon: <FileCheck /> }
        ]}
      />

      {/* Main Container */}
      <div className="flex-1 overflow-hidden flex p-4 sm:p-8 gap-6 pb-24 sm:pb-8">
        
        {/* TAB 1: EXPEDIENTES DE COLABORADORES */}
        {activeTab === 'employees' && (
          <>
            {/* Left: Folders list */}
            <div className={`bg-white rounded-3xl border border-slate-200 shadow-sm flex flex-col p-6 overflow-hidden ${selectedEmployeeId !== null ? 'w-2/5' : 'w-full'} transition-all duration-300`}>
              <div className="flex items-center justify-between mb-5 shrink-0">
                <h3 className="font-black text-slate-800 text-base">Directorio de Carpetas</h3>
                <div className="relative w-48">
                  <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                  <input 
                    type="text" 
                    placeholder="Buscar..." 
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="w-full pl-8 pr-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
              </div>

              {/* Grid or List of Employee Folders */}
              <div className="flex-1 overflow-y-auto grid grid-cols-1 sm:grid-cols-2 gap-4 pb-4 pr-1 scrollbar-thin">
                {filteredEmployees.map(u => {
                  const isSelected = selectedEmployeeId === u.id;
                  const docsCount = employeeDocs[u.id]?.filter(d => d.status === 'validado').length || 0;
                  const totalDocs = employeeDocs[u.id]?.length || 0;

                  return (
                    <div 
                      key={u.id}
                      onClick={() => setSelectedEmployeeId(u.id)}
                      className={`p-4 rounded-2xl border-2 cursor-pointer transition-all flex items-start gap-3 group relative hover:shadow-md ${
                        isSelected 
                          ? 'border-blue-500 bg-blue-50/20' 
                          : 'border-slate-100 hover:border-slate-200 bg-white'
                      }`}
                    >
                      <div className={`p-3 rounded-xl shrink-0 ${isSelected ? 'bg-blue-100 text-blue-600' : 'bg-amber-50 text-amber-500 group-hover:scale-105 transition-transform'}`}>
                        {isSelected ? <FolderOpen size={24} /> : <Folder size={24} />}
                      </div>
                      <div className="overflow-hidden">
                        <h4 className="font-extrabold text-slate-800 text-xs truncate leading-snug">{u.name}</h4>
                        <p className="text-[10px] text-slate-400 font-semibold truncate mt-0.5">{u.role}</p>
                        <span className="inline-block text-[9px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full mt-2">
                          📂 {docsCount}/{totalDocs} validados
                        </span>
                      </div>
                      <ChevronRight size={14} className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 opacity-0 group-hover:opacity-100 transition-opacity" />
                    </div>
                  );
                })}
              </div>
            </div>

            {/* Right: Selected Folder Details */}
            {selectedEmployeeId !== null && selectedEmployee && (
              <div className="w-3/5 bg-white rounded-3xl border border-slate-200 shadow-sm flex flex-col p-6 overflow-hidden animate-in slide-in-from-right-4 duration-300">
                <div className="flex items-center justify-between border-b border-slate-100 pb-5 shrink-0">
                  <div className="flex items-center gap-3">
                    <img src={selectedEmployee.avatar} alt="Avatar" className="w-10 h-10 rounded-full border border-slate-200 object-cover" />
                    <div>
                      <h3 className="font-black text-slate-800 text-sm leading-tight">{selectedEmployee.name}</h3>
                      <p className="text-[10px] text-slate-505 font-medium">Expediente Personal del Colaborador</p>
                    </div>
                  </div>
                  <button 
                    onClick={() => setSelectedEmployeeId(null)}
                    className="p-1.5 hover:bg-slate-100 text-slate-400 hover:text-slate-600 rounded-lg transition-colors"
                  >
                    <X size={16} />
                  </button>
                </div>

                {/* Documents Table */}
                <div className="flex-1 overflow-y-auto py-4 space-y-3.5 scrollbar-thin">
                  <div className="flex items-center justify-between">
                    <span className="text-[10px] font-black uppercase text-slate-400 tracking-wider">Documentos del Expediente</span>
                    
                    {/* Add Document Input */}
                    <label className={`flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 text-blue-600 hover:bg-blue-100 rounded-lg text-[10px] font-bold cursor-pointer transition-colors border border-blue-100 ${isUploading ? 'opacity-50 cursor-not-allowed' : ''}`}>
                      <UploadCloud size={12} />
                      {isUploading ? 'Cargando...' : 'Subir Documento'}
                      <input 
                        type="file" 
                        className="hidden" 
                        disabled={isUploading}
                        onChange={handleFileUpload}
                      />
                    </label>
                  </div>

                  {/* Upload Progress Bar */}
                  {isUploading && (
                    <div className="p-3 bg-slate-50 border border-slate-200 rounded-xl">
                      <div className="flex justify-between text-[10px] font-bold text-slate-650 mb-1">
                        <span className="flex items-center gap-1"><Loader2 size={10} className="animate-spin text-blue-500" /> Transfiriendo archivo...</span>
                        <span>{uploadProgress}%</span>
                      </div>
                      <div className="w-full bg-slate-200 h-1.5 rounded-full overflow-hidden">
                        <div className="bg-blue-500 h-full transition-all duration-150" style={{ width: `${uploadProgress}%` }}></div>
                      </div>
                    </div>
                  )}

                  <div className="border border-slate-200 rounded-2xl overflow-hidden divide-y divide-slate-200">
                    {selectedDocs.map(doc => (
                      <div key={doc.id} className="p-4 bg-slate-50/50 hover:bg-slate-50 flex items-center justify-between gap-4 transition-colors">
                        <div className="flex items-center gap-3 min-w-0">
                          <div className={`p-2 rounded-xl shrink-0 ${doc.status === 'validado' ? 'bg-emerald-50 text-emerald-500' : 'bg-slate-100 text-slate-400'}`}>
                            <FileText size={18} />
                          </div>
                          <div className="min-w-0">
                            <h5 className="font-bold text-slate-800 text-xs truncate">{doc.name}</h5>
                            <div className="flex items-center gap-2 mt-0.5 text-[9px] font-semibold text-slate-400">
                              <span>Tamaño: {doc.size}</span>
                              <span>•</span>
                              <span>F. Carga: {doc.uploadedAt}</span>
                            </div>
                          </div>
                        </div>
                        
                        <div className="flex items-center gap-3 shrink-0">
                          {doc.status === 'validado' ? (
                            <span className="inline-flex items-center gap-0.5 text-[9px] font-bold text-emerald-600 bg-emerald-50 border border-emerald-100 px-2 py-0.5 rounded-full">
                              <CheckCircle2 size={10}/> Validado
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-0.5 text-[9px] font-bold text-amber-600 bg-amber-50 border border-amber-100 px-2 py-0.5 rounded-full">
                              <AlertCircle size={10}/> Pendiente
                            </span>
                          )}
                          
                          {doc.status === 'validado' && (
                            <button 
                              onClick={() => setPreviewDoc({ ...doc, employeeName: selectedEmployee.name })}
                              className="p-1 hover:bg-slate-200 rounded text-slate-405 hover:text-slate-700 transition-colors"
                              title="Visualizar documento"
                            >
                              <Eye size={14} />
                            </button>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            )}
          </>
        )}

        {/* TAB 2: CORPORATIVOS (MANUALES Y PROTOCOLOS) */}
        {activeTab === 'company' && (
          <div className="w-full bg-white rounded-3xl border border-slate-200 p-6 flex flex-col overflow-hidden shadow-sm">
            <div className="flex items-center justify-between mb-6 shrink-0 border-b border-slate-100 pb-4">
              <div>
                <h3 className="font-black text-slate-800 text-base">Almacén de Protocolos y Manuales</h3>
                <p className="text-xs text-slate-500">Documentos oficiales que definen la operación corporativa de la sucursal.</p>
              </div>
              <div className="relative w-64">
                <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input 
                  type="text" 
                  placeholder="Buscar manuales..." 
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="w-full pl-8 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs outline-none focus:ring-1 focus:ring-blue-500"
                />
              </div>
            </div>

            <div className="flex-1 overflow-y-auto grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 scrollbar-thin">
              {filteredCorporate.map(d => (
                <div key={d.id} className="border border-slate-200 rounded-3xl p-5 hover:border-blue-300 hover:shadow-md transition-all flex flex-col justify-between group">
                  <div>
                    <div className="flex justify-between items-start mb-3">
                      <span className="p-2 bg-indigo-50 text-indigo-600 rounded-xl group-hover:scale-105 transition-transform"><BookOpen size={20} /></span>
                      <span className="text-[9px] font-black uppercase tracking-widest bg-slate-100 text-slate-600 px-2.5 py-1 rounded border border-slate-200">{d.category}</span>
                    </div>
                    <h4 className="font-extrabold text-slate-800 text-sm leading-snug">{d.name}</h4>
                    <p className="text-xs text-slate-500 mt-2 font-medium line-clamp-3">{d.content}</p>
                  </div>
                  
                  <div className="border-t border-slate-100 pt-4 mt-5">
                    {/* Linked Academy status */}
                    <div className="flex justify-between items-center text-xs mb-4">
                      <span className="text-slate-400 font-bold">Vinculado a:</span>
                      {d.linkedCourse ? (
                        <span className="inline-flex items-center gap-1 text-[10px] font-bold text-blue-600 bg-blue-50 px-2.5 py-0.5 rounded-full">
                          <CheckCircle2 size={10} /> {d.linkedCourse}
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 text-[10px] font-bold text-slate-400 bg-slate-105 px-2.5 py-0.5 rounded-full">
                          Sin vincular
                        </span>
                      )}
                    </div>

                    <div className="flex gap-2">
                      <button 
                        onClick={() => setPreviewDoc(d)}
                        className="flex-1 py-2 rounded-xl text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 transition-colors flex items-center justify-center gap-1"
                      >
                        <Eye size={12} /> Ver Manual
                      </button>
                      <button 
                        onClick={() => setIsLinkingDoc(d)}
                        className="flex-1 py-2 rounded-xl text-xs font-black bg-blue-600 text-white hover:bg-blue-700 transition-colors flex items-center justify-center gap-1 shadow-md shadow-blue-600/10"
                      >
                        <Link2 size={12} /> Vincular
                      </button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

      </div>

      {/* MODAL 1: VISUALIZADOR DE DOCUMENTOS (SIMULADOR DE PDF/VISOR) */}
      {previewDoc && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-50 p-4 animate-in fade-in duration-200">
          <div className="bg-slate-900 text-white rounded-3xl max-w-lg w-full shadow-2xl flex flex-col border border-slate-800 animate-in zoom-in-95 duration-150 overflow-hidden">
            <div className="px-6 py-4 bg-slate-800 border-b border-slate-850 flex justify-between items-center shrink-0">
              <div className="flex items-center gap-2">
                <FileCheck size={18} className="text-emerald-400" />
                <span className="font-extrabold text-sm">{previewDoc.name}</span>
              </div>
              <button 
                onClick={() => setPreviewDoc(null)}
                className="p-1 hover:bg-slate-800 rounded text-slate-400 hover:text-white transition-colors"
              >
                <X size={18} />
              </button>
            </div>
            
            {/* Visualizer Body */}
            <div className="p-8 bg-slate-950 flex flex-col items-center justify-center min-h-[320px]">
              {/* Simulated scan image or PDF document */}
              <div className="w-full bg-white text-slate-800 p-8 rounded-2xl shadow-lg border border-slate-200 relative overflow-hidden font-mono text-[10px] space-y-4 max-w-sm">
                <div className="absolute top-0 right-0 w-12 h-12 bg-emerald-500/10 border-l border-b border-emerald-500/20 text-emerald-600 font-sans font-black flex items-center justify-center rotate-45 translate-x-3 -translate-y-3">
                  SAT
                </div>
                
                <div className="text-center border-b border-slate-200 pb-3">
                  <h4 className="font-sans font-black text-slate-900 text-xs tracking-wider uppercase">ESTADOS UNIDOS MEXICANOS</h4>
                  <p className="text-[8px] text-slate-400 mt-1">Registro Digital Seguro y Autenticado</p>
                </div>
                
                <div className="grid grid-cols-2 gap-2 text-[8px]">
                  <div>
                    <span className="block text-slate-400 font-bold uppercase">Titular / Empleado</span>
                    <span className="font-extrabold text-slate-800">{previewDoc.employeeName || 'Talent360 Corporativo'}</span>
                  </div>
                  <div>
                    <span className="block text-slate-400 font-bold uppercase">Clave Única Documento</span>
                    <span className="font-bold text-slate-650">{previewDoc.id}</span>
                  </div>
                </div>

                <div className="bg-slate-50 p-3 rounded-lg border border-slate-100 font-sans text-slate-600 leading-relaxed text-[9px]">
                  {previewDoc.content || 'Este documento cumple con las normativas vigentes del artículo 47 de la Ley Federal del Trabajo. Firma autorizada mediante PIN temporal encriptado.'}
                </div>

                <div className="flex justify-between items-end border-t border-slate-200 pt-3">
                  <div className="text-[7px] text-slate-400">
                    <p>Firma Electrónica: TalentSha256•{previewDoc.id}</p>
                    <p>Fecha de verificación: 2026-06-25</p>
                  </div>
                  <div className="w-10 h-10 bg-slate-900 rounded border border-slate-800 flex items-center justify-center text-[7px] text-white text-center font-sans font-bold leading-none select-none">
                    QR SECURE
                  </div>
                </div>
              </div>
            </div>

            <div className="px-6 py-4 bg-slate-900 border-t border-slate-800 flex gap-2 justify-end shrink-0">
              <button 
                onClick={() => setPreviewDoc(null)}
                className="px-4 py-2 bg-slate-800 hover:bg-slate-750 text-slate-200 rounded-xl text-xs font-bold"
              >
                Cerrar Visualizador
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL 2: VINCULAR MANUAL A CURSO ACADEMIA */}
      {isLinkingDoc && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-50 p-4 animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl max-w-md w-full shadow-2xl p-6 border border-slate-100 animate-in zoom-in-95 duration-150 flex flex-col">
            <div className="flex justify-between items-center border-b border-slate-100 pb-4 mb-4">
              <h4 className="font-black text-slate-800 text-base flex items-center gap-2">
                <Link2 className="text-blue-500" size={18} />
                Vincular a Academia
              </h4>
              <button 
                onClick={() => setIsLinkingDoc(null)}
                className="p-1 hover:bg-slate-50 rounded-lg text-slate-400 hover:text-slate-600 transition-colors"
              >
                <X size={16} />
              </button>
            </div>

            <p className="text-xs text-slate-500 mb-4 leading-normal">
              Selecciona a qué curso de la **Academia 360** deseas asociar el archivo <span className="font-extrabold text-slate-800">"{isLinkingDoc.name}"</span> para que el personal lo tenga como material de estudio obligatorio.
            </p>

            {loadingCourses ? (
              <div className="py-6 text-center text-slate-400 font-medium text-xs flex flex-col items-center justify-center">
                <Loader2 className="animate-spin text-blue-500 mb-2" size={24} />
                Cargando cursos disponibles...
              </div>
            ) : courses.length === 0 ? (
              <div className="py-4 text-center text-slate-400 font-bold text-xs bg-slate-50 rounded-xl border border-dashed border-slate-200">
                No hay cursos creados en la Academia.
              </div>
            ) : (
              <div className="space-y-2 max-h-60 overflow-y-auto pr-1">
                {courses.map(course => (
                  <div 
                    key={course.id}
                    onClick={() => handleLinkManual(course.title)}
                    className="p-3 bg-slate-50 hover:bg-blue-50/40 border border-slate-200 hover:border-blue-200 rounded-xl cursor-pointer flex justify-between items-center transition-all group"
                  >
                    <div>
                      <span className="block font-extrabold text-slate-800 text-xs">{course.title}</span>
                      <span className="text-[9px] text-slate-450 font-medium">{course.course_type === 'induction' ? 'Inducción de Puesto' : 'Capacitación Continua'}</span>
                    </div>
                    <ArrowRight size={14} className="text-slate-300 group-hover:text-blue-500 group-hover:translate-x-1 transition-all" />
                  </div>
                ))}
              </div>
            )}

            <div className="flex gap-2 mt-6">
              <button 
                onClick={() => setIsLinkingDoc(null)}
                className="flex-1 py-3 bg-slate-100 text-slate-700 font-bold hover:bg-slate-200 rounded-xl text-xs transition-colors"
              >
                Cancelar
              </button>
            </div>
          </div>
        </div>
      )}

    </div>
  );
};
