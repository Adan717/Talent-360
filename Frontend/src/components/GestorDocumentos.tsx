import React, { useState, useEffect, useCallback, useRef } from 'react';
import {
  Folder, FileText, UploadCloud, CheckCircle2, AlertCircle,
  Eye, Link2, Search, X, Loader2, ArrowRight, FolderOpen,
  BookOpen, ChevronRight, FileCheck, Download, Trash2, XCircle
} from 'lucide-react';
import axiosInstance from '../lib/axios';
import { MobileModuleBottomDock } from './common/MobileModuleBottomDock';

// ============================================================================
// Archivo Digital (ronda 2026-08). Esta pantalla era un mockup 100% frontend:
// expedientes fabricados, barra de upload con setInterval que jamás enviaba el
// archivo (pérdida de datos) y un visor con sello "SAT" inventado. Se conservó
// la carrocería visual y se cambió el motor completo por el API real
// (/admin/documentos/*). La checklist de 6 muestra FALTANTES honestos.
// ============================================================================

interface EmployeeDoc {
  id: number;
  doc_type: string;
  original_name: string;
  mime: string;
  size_bytes: number;
  status: 'pendiente' | 'validado' | 'rechazado';
  rejection_reason: string | null;
  created_at: string;
}

interface ChecklistItem {
  doc_type: string;
  label: string;
  doc: EmployeeDoc | null;
}

interface EmployeeSummary {
  employee_id: number;
  name: string;
  role: string;
  subidos: number;
  validados: number;
  faltantes: number;
}

interface CompanyDoc {
  id: number;
  name: string;
  category: string;
  original_name: string;
  mime: string;
  size_bytes: number;
  linked_course_id: number | null;
  course: { id: number; title: string } | null;
  created_at: string;
}

const formatBytes = (bytes: number) => {
  if (!bytes) return '-';
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

const formatDate = (iso: string | null | undefined) => (iso ? iso.slice(0, 10) : '-');

const apiError = (e: any, fallback: string) =>
  e?.response?.data?.message || Object.values(e?.response?.data?.errors || {}).flat()[0] || fallback;

export const GestorDocumentos = () => {
  const [activeTab, setActiveTab] = useState<'employees' | 'company'>('employees');
  const [searchQuery, setSearchQuery] = useState('');

  // Grid de carpetas (resumen real del backend)
  const [summary, setSummary] = useState<EmployeeSummary[]>([]);
  const [loadingSummary, setLoadingSummary] = useState(true);
  const [summaryError, setSummaryError] = useState(false);
  const [corporateError, setCorporateError] = useState(false);

  // Expediente abierto
  const [selectedEmployeeId, setSelectedEmployeeId] = useState<number | null>(null);
  const [expediente, setExpediente] = useState<{
    employee: { id: number; name: string; role: string };
    checklist: ChecklistItem[];
    extras: EmployeeDoc[];
  } | null>(null);
  const [loadingExpediente, setLoadingExpediente] = useState(false);

  // Upload real (progreso de axios, no un timer)
  const [uploadingType, setUploadingType] = useState<string | null>(null);
  const [uploadProgress, setUploadProgress] = useState(0);

  // Corporativos
  const [corporateDocs, setCorporateDocs] = useState<CompanyDoc[]>([]);
  const [loadingCorporate, setLoadingCorporate] = useState(true);
  const [showCorpUpload, setShowCorpUpload] = useState(false);
  const [corpCategory, setCorpCategory] = useState('');
  const [corpFile, setCorpFile] = useState<File | null>(null);
  const [corpUploading, setCorpUploading] = useState(false);

  // Modales
  const [previewDoc, setPreviewDoc] = useState<{ name: string; mime: string; url: string } | null>(null);
  const [loadingPreviewId, setLoadingPreviewId] = useState<number | null>(null);
  const [isLinkingDoc, setIsLinkingDoc] = useState<CompanyDoc | null>(null);
  const [rejectingDoc, setRejectingDoc] = useState<EmployeeDoc | null>(null);
  const [rejectReason, setRejectReason] = useState('');

  // Cursos de Academia (para vincular manuales)
  const [courses, setCourses] = useState<any[]>([]);
  const [loadingCourses, setLoadingCourses] = useState(false);

  const fetchSummary = useCallback(async () => {
    try {
      const res = await axiosInstance.get('/admin/documentos/expedientes');
      setSummary(res.data.employees || []);
      setSummaryError(false);
    } catch (e) {
      console.error('Error cargando expedientes', e);
      // Sin este flag, un 500/timeout se pintaría como "No hay colaboradores" — mentira.
      setSummaryError(true);
    } finally {
      setLoadingSummary(false);
    }
  }, []);

  // Guarda contra respuestas rancias: si el admin cambió de empleado mientras un GET
  // (o el refetch post-upload) estaba en vuelo, la respuesta vieja se descarta — sin
  // esto el panel puede mostrar el expediente de A con el id de B seleccionado, y
  // validar/borrar sobre el documento equivocado.
  const selectedIdRef = useRef<number | null>(null);

  const fetchExpediente = useCallback(async (employeeId: number) => {
    setLoadingExpediente(true);
    try {
      const res = await axiosInstance.get(`/admin/documentos/expedientes/${employeeId}`);
      if (selectedIdRef.current !== employeeId) return;
      setExpediente(res.data);
    } catch (e) {
      console.error('Error cargando expediente', e);
      if (selectedIdRef.current === employeeId) setExpediente(null);
    } finally {
      if (selectedIdRef.current === employeeId) setLoadingExpediente(false);
    }
  }, []);

  const fetchCorporate = useCallback(async () => {
    try {
      const res = await axiosInstance.get('/admin/documentos/corporativos');
      setCorporateDocs(res.data.docs || []);
      setCorporateError(false);
    } catch (e) {
      console.error('Error cargando corporativos', e);
      setCorporateError(true);
    } finally {
      setLoadingCorporate(false);
    }
  }, []);

  useEffect(() => {
    fetchSummary();
    fetchCorporate();
  }, [fetchSummary, fetchCorporate]);

  useEffect(() => {
    selectedIdRef.current = selectedEmployeeId;
    if (selectedEmployeeId !== null) fetchExpediente(selectedEmployeeId);
    else setExpediente(null);
  }, [selectedEmployeeId, fetchExpediente]);

  useEffect(() => {
    const fetchCourses = async () => {
      setLoadingCourses(true);
      try {
        const res = await axiosInstance.get('/academy/courses');
        const data = res.data;
        setCourses(data?.courses || (Array.isArray(data) ? data : []));
      } catch (e) {
        console.error('Error fetching courses for linking', e);
        setCourses([]);
      } finally {
        setLoadingCourses(false);
      }
    };
    fetchCourses();
  }, []);

  // ---- Acciones sobre el expediente -----------------------------------------

  const handleFileUpload = async (file: File, docType: string) => {
    if (selectedEmployeeId === null) return;
    const employeeId = selectedEmployeeId;
    setUploadingType(docType);
    setUploadProgress(0);
    try {
      const form = new FormData();
      form.append('file', file);
      form.append('doc_type', docType);
      await axiosInstance.post(`/admin/documentos/expedientes/${employeeId}`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress: (e) => setUploadProgress(e.total ? Math.round((e.loaded * 100) / e.total) : 0),
      });
      // Refrescar el expediente solo si el admin sigue viendo al mismo empleado.
      const refetches = [fetchSummary()];
      if (selectedIdRef.current === employeeId) refetches.push(fetchExpediente(employeeId));
      await Promise.all(refetches);
    } catch (e: any) {
      alert(apiError(e, 'No se pudo subir el documento. Solo PDF/JPG/PNG de hasta 10 MB.'));
    } finally {
      setUploadingType(null);
      setUploadProgress(0);
    }
  };

  const handleValidate = async (doc: EmployeeDoc, accion: 'validar' | 'rechazar', motivo?: string): Promise<boolean> => {
    try {
      await axiosInstance.post(`/admin/documentos/${doc.id}/validar`, { accion, motivo });
      if (selectedEmployeeId !== null) await Promise.all([fetchExpediente(selectedEmployeeId), fetchSummary()]);
      return true;
    } catch (e: any) {
      alert(apiError(e, 'No se pudo actualizar el documento.'));
      return false;
    }
  };

  const handleDelete = async (doc: EmployeeDoc) => {
    if (!window.confirm(`¿Quitar "${doc.original_name}" del expediente?`)) return;
    try {
      await axiosInstance.delete(`/admin/documentos/${doc.id}`);
      if (selectedEmployeeId !== null) await Promise.all([fetchExpediente(selectedEmployeeId), fetchSummary()]);
    } catch (e: any) {
      alert(apiError(e, 'No se pudo eliminar el documento.'));
    }
  };

  // Descarga autenticada por blob — el archivo vive en storage privado y solo
  // sale por este endpoint; no existe URL pública que adivinar.
  const fetchBlobUrl = async (docId: number, scope: 'empleado' | 'corporativo') => {
    const res = await axiosInstance.get(`/admin/documentos/descargar/${docId}`, {
      params: { scope },
      responseType: 'blob',
    });
    return window.URL.createObjectURL(res.data as Blob);
  };

  const handlePreview = async (docId: number, name: string, mime: string, scope: 'empleado' | 'corporativo') => {
    if (loadingPreviewId !== null) return; // ya hay un blob en vuelo — evita pisar el modal
    setLoadingPreviewId(docId);
    try {
      const url = await fetchBlobUrl(docId, scope);
      if (mime === 'application/pdf' || mime.startsWith('image/')) {
        setPreviewDoc((prev) => {
          if (prev) window.URL.revokeObjectURL(prev.url);
          return { name, mime, url };
        });
      } else {
        triggerDownload(url, name);
      }
    } catch (e: any) {
      alert(apiError(e, 'No se pudo descargar el documento.'));
    } finally {
      setLoadingPreviewId(null);
    }
  };

  // revoke=false cuando el URL sigue montado en el visor (el modal lo revoca al cerrar).
  const triggerDownload = (url: string, name: string, revoke = true) => {
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', name);
    document.body.appendChild(link);
    link.click();
    link.remove();
    if (revoke) window.URL.revokeObjectURL(url);
  };

  const handleDownload = async (docId: number, name: string, scope: 'empleado' | 'corporativo') => {
    try {
      triggerDownload(await fetchBlobUrl(docId, scope), name);
    } catch (e: any) {
      alert(apiError(e, 'No se pudo descargar el documento.'));
    }
  };

  const closePreview = () => {
    if (previewDoc) window.URL.revokeObjectURL(previewDoc.url);
    setPreviewDoc(null);
  };

  // Si el admin navega a otro módulo con el visor abierto, revocar el blob al desmontar.
  const previewUrlRef = useRef<string | null>(null);
  previewUrlRef.current = previewDoc?.url ?? null;
  useEffect(() => () => {
    if (previewUrlRef.current) window.URL.revokeObjectURL(previewUrlRef.current);
  }, []);

  // ---- Corporativos ---------------------------------------------------------

  const handleCorpUpload = async () => {
    if (!corpFile || !corpCategory.trim()) {
      alert('Elige un archivo y escribe la categoría.');
      return;
    }
    setCorpUploading(true);
    try {
      const form = new FormData();
      form.append('file', corpFile);
      form.append('category', corpCategory.trim());
      await axiosInstance.post('/admin/documentos/corporativos', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setShowCorpUpload(false);
      setCorpFile(null);
      setCorpCategory('');
      await fetchCorporate();
    } catch (e: any) {
      alert(apiError(e, 'No se pudo subir el manual. Solo PDF/JPG/PNG de hasta 10 MB.'));
    } finally {
      setCorpUploading(false);
    }
  };

  const handleLinkManual = async (courseId: number | null) => {
    if (!isLinkingDoc) return;
    try {
      await axiosInstance.post(`/admin/documentos/corporativos/${isLinkingDoc.id}/vincular`, {
        course_id: courseId,
      });
      setIsLinkingDoc(null);
      await fetchCorporate();
    } catch (e: any) {
      alert(apiError(e, 'No se pudo vincular el manual.'));
    }
  };

  // ---- Derivados ------------------------------------------------------------

  const filteredEmployees = summary.filter(
    (u) =>
      u.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      u.role.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const filteredCorporate = corporateDocs.filter(
    (d) =>
      d.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      d.category.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const statusBadge = (doc: EmployeeDoc) => {
    if (doc.status === 'validado')
      return (
        <span className="inline-flex items-center gap-0.5 text-[9px] font-bold text-emerald-600 bg-emerald-50 border border-emerald-100 px-2 py-0.5 rounded-full">
          <CheckCircle2 size={10} /> Validado
        </span>
      );
    if (doc.status === 'rechazado')
      return (
        <span
          className="inline-flex items-center gap-0.5 text-[9px] font-bold text-rose-600 bg-rose-50 border border-rose-100 px-2 py-0.5 rounded-full"
          title={doc.rejection_reason || undefined}
        >
          <XCircle size={10} /> Rechazado
        </span>
      );
    return (
      <span className="inline-flex items-center gap-0.5 text-[9px] font-bold text-amber-600 bg-amber-50 border border-amber-100 px-2 py-0.5 rounded-full">
        <AlertCircle size={10} /> Pendiente
      </span>
    );
  };

  const docRow = (doc: EmployeeDoc, label?: string) => (
    <div key={doc.id} className="p-4 bg-slate-50/50 hover:bg-slate-50 flex items-center justify-between gap-4 transition-colors">
      <div className="flex items-center gap-3 min-w-0">
        <div className={`p-2 rounded-xl shrink-0 ${doc.status === 'validado' ? 'bg-emerald-50 text-emerald-500' : doc.status === 'rechazado' ? 'bg-rose-50 text-rose-500' : 'bg-slate-100 text-slate-400'}`}>
          <FileText size={18} />
        </div>
        <div className="min-w-0">
          {label && <span className="block text-[8px] font-black uppercase tracking-wider text-slate-400">{label}</span>}
          <h5 className="font-bold text-slate-800 text-xs truncate">{doc.original_name}</h5>
          <div className="flex items-center gap-2 mt-0.5 text-[9px] font-semibold text-slate-400">
            <span>Tamaño: {formatBytes(doc.size_bytes)}</span>
            <span>•</span>
            <span>F. Carga: {formatDate(doc.created_at)}</span>
          </div>
          {doc.status === 'rechazado' && doc.rejection_reason && (
            <p className="text-[9px] font-semibold text-rose-500 mt-0.5 truncate" title={doc.rejection_reason}>
              Motivo: {doc.rejection_reason}
            </p>
          )}
        </div>
      </div>

      <div className="flex items-center gap-2 shrink-0">
        {statusBadge(doc)}

        {doc.status === 'pendiente' && (
          <>
            <button
              onClick={() => handleValidate(doc, 'validar')}
              className="px-2 py-1 rounded-lg text-[9px] font-black bg-emerald-600 text-white hover:bg-emerald-700 transition-colors"
              title="Validar documento"
            >
              Validar
            </button>
            <button
              onClick={() => { setRejectingDoc(doc); setRejectReason(''); }}
              className="px-2 py-1 rounded-lg text-[9px] font-black bg-rose-50 text-rose-600 border border-rose-100 hover:bg-rose-100 transition-colors"
              title="Rechazar documento"
            >
              Rechazar
            </button>
          </>
        )}

        <button
          onClick={() => handlePreview(doc.id, doc.original_name, doc.mime, 'empleado')}
          className="p-1 hover:bg-slate-200 rounded text-slate-400 hover:text-slate-700 transition-colors"
          title="Ver documento"
          disabled={loadingPreviewId === doc.id}
        >
          {loadingPreviewId === doc.id ? <Loader2 size={14} className="animate-spin" /> : <Eye size={14} />}
        </button>
        <button
          onClick={() => handleDownload(doc.id, doc.original_name, 'empleado')}
          className="p-1 hover:bg-slate-200 rounded text-slate-400 hover:text-slate-700 transition-colors"
          title="Descargar"
        >
          <Download size={14} />
        </button>
        <button
          onClick={() => handleDelete(doc)}
          className="p-1 hover:bg-rose-100 rounded text-slate-400 hover:text-rose-600 transition-colors"
          title="Quitar del expediente"
        >
          <Trash2 size={14} />
        </button>
      </div>
    </div>
  );

  const uploadButton = (docType: string, compact = false) => (
    <label
      className={`inline-flex items-center gap-1.5 ${compact ? 'px-2 py-1' : 'px-3 py-1.5'} bg-blue-50 text-blue-600 hover:bg-blue-100 rounded-lg text-[10px] font-bold cursor-pointer transition-colors border border-blue-100 ${uploadingType ? 'opacity-50 cursor-not-allowed' : ''}`}
    >
      <UploadCloud size={12} />
      {uploadingType === docType ? `${uploadProgress}%` : compact ? 'Subir' : 'Subir Documento'}
      <input
        type="file"
        accept=".pdf,.jpg,.jpeg,.png"
        className="hidden"
        disabled={uploadingType !== null}
        onChange={(e) => {
          const f = e.target.files?.[0];
          if (f) handleFileUpload(f, docType);
          e.target.value = '';
        }}
      />
    </label>
  );

  const selectedSummary = summary.find((s) => s.employee_id === selectedEmployeeId);

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

      {/* DOCK FLOTANTE INFERIOR MÓVIL */}
      <MobileModuleBottomDock
        colorTheme="amber"
        activeTab={activeTab}
        onSelectTab={(tab) => {
          setActiveTab(tab as any);
          if (tab === 'employees') setSelectedEmployeeId(null);
        }}
        fabIcon={<UploadCloud size={28} className="text-white relative z-10 animate-pulse" />}
        onFabClick={() => { setActiveTab('company'); setShowCorpUpload(true); }}
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
            <div className={`bg-white rounded-3xl border border-slate-200 shadow-sm flex flex-col p-6 overflow-hidden ${selectedEmployeeId !== null ? 'hidden sm:flex sm:w-2/5' : 'w-full'} transition-all duration-300`}>
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

              {loadingSummary ? (
                <div className="flex-1 flex flex-col items-center justify-center text-slate-400 text-xs font-medium">
                  <Loader2 className="animate-spin text-blue-500 mb-2" size={24} />
                  Cargando expedientes...
                </div>
              ) : summaryError ? (
                <div className="flex-1 flex flex-col items-center justify-center gap-3 text-xs">
                  <span className="text-rose-500 font-bold flex items-center gap-1"><AlertCircle size={14} /> No se pudieron cargar los expedientes.</span>
                  <button
                    onClick={() => { setLoadingSummary(true); fetchSummary(); }}
                    className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold transition-colors"
                  >
                    Reintentar
                  </button>
                </div>
              ) : filteredEmployees.length === 0 ? (
                <div className="flex-1 flex items-center justify-center text-slate-400 font-bold text-xs">
                  No hay colaboradores activos. Da de alta a tu equipo en Recursos Humanos.
                </div>
              ) : (
                <div className="flex-1 overflow-y-auto grid grid-cols-1 sm:grid-cols-2 gap-4 pb-4 pr-1 scrollbar-thin content-start">
                  {filteredEmployees.map((u) => {
                    const isSelected = selectedEmployeeId === u.employee_id;
                    return (
                      <div
                        key={u.employee_id}
                        onClick={() => setSelectedEmployeeId(u.employee_id)}
                        className={`p-4 rounded-2xl border-2 cursor-pointer transition-all flex items-start gap-3 group relative hover:shadow-md self-start ${
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
                          <div className="flex flex-wrap gap-1 mt-2">
                            <span className="inline-block text-[9px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full">
                              📂 {u.validados}/{u.subidos} validados
                            </span>
                            {u.faltantes > 0 ? (
                              <span className="inline-block text-[9px] font-bold text-rose-600 bg-rose-50 border border-rose-100 px-2 py-0.5 rounded-full">
                                {u.faltantes} faltante{u.faltantes === 1 ? '' : 's'}
                              </span>
                            ) : (
                              <span className="inline-block text-[9px] font-bold text-emerald-600 bg-emerald-50 border border-emerald-100 px-2 py-0.5 rounded-full">
                                Completo
                              </span>
                            )}
                          </div>
                        </div>
                        <ChevronRight size={14} className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 opacity-0 group-hover:opacity-100 transition-opacity" />
                      </div>
                    );
                  })}
                </div>
              )}
            </div>

            {/* Right: Expediente del colaborador */}
            {selectedEmployeeId !== null && (
              <div className="w-full sm:w-3/5 bg-white rounded-3xl border border-slate-200 shadow-sm flex flex-col p-6 overflow-hidden animate-in slide-in-from-right-4 duration-300">
                <div className="flex items-center justify-between border-b border-slate-100 pb-5 shrink-0">
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center font-black text-sm border border-amber-200">
                      {(expediente?.employee.name || selectedSummary?.name || '?').charAt(0)}
                    </div>
                    <div>
                      <h3 className="font-black text-slate-800 text-sm leading-tight">{expediente?.employee.name || selectedSummary?.name}</h3>
                      <p className="text-[10px] text-slate-500 font-medium">Expediente Personal del Colaborador</p>
                    </div>
                  </div>
                  <button
                    onClick={() => setSelectedEmployeeId(null)}
                    className="p-1.5 hover:bg-slate-100 text-slate-400 hover:text-slate-600 rounded-lg transition-colors"
                  >
                    <X size={16} />
                  </button>
                </div>

                {loadingExpediente ? (
                  <div className="flex-1 flex flex-col items-center justify-center text-slate-400 text-xs font-medium">
                    <Loader2 className="animate-spin text-blue-500 mb-2" size={24} />
                    Cargando expediente...
                  </div>
                ) : expediente ? (
                  <div className="flex-1 overflow-y-auto py-4 space-y-3.5 scrollbar-thin">

                    {/* Barra de progreso REAL del upload en curso */}
                    {uploadingType && (
                      <div className="p-3 bg-slate-50 border border-slate-200 rounded-xl">
                        <div className="flex justify-between text-[10px] font-bold text-slate-600 mb-1">
                          <span className="flex items-center gap-1"><Loader2 size={10} className="animate-spin text-blue-500" /> Transfiriendo archivo...</span>
                          <span>{uploadProgress}%</span>
                        </div>
                        <div className="w-full bg-slate-200 h-1.5 rounded-full overflow-hidden">
                          <div className="bg-blue-500 h-full transition-all duration-150" style={{ width: `${uploadProgress}%` }}></div>
                        </div>
                      </div>
                    )}

                    {/* Checklist de 6 requeridos — faltantes honestos */}
                    <span className="text-[10px] font-black uppercase text-slate-400 tracking-wider">Documentos Requeridos</span>
                    <div className="border border-slate-200 rounded-2xl overflow-hidden divide-y divide-slate-200">
                      {expediente.checklist.map((item) =>
                        item.doc ? (
                          docRow(item.doc, item.label)
                        ) : (
                          <div key={item.doc_type} className="p-4 bg-white flex items-center justify-between gap-4">
                            <div className="flex items-center gap-3 min-w-0">
                              <div className="p-2 rounded-xl shrink-0 bg-rose-50 text-rose-400 border border-dashed border-rose-200">
                                <FileText size={18} />
                              </div>
                              <div className="min-w-0">
                                <h5 className="font-bold text-slate-800 text-xs truncate">{item.label}</h5>
                                <span className="inline-flex items-center gap-0.5 text-[9px] font-bold text-rose-600 mt-0.5">
                                  <AlertCircle size={10} /> FALTANTE
                                </span>
                              </div>
                            </div>
                            <div className="shrink-0">{uploadButton(item.doc_type, true)}</div>
                          </div>
                        )
                      )}
                    </div>

                    {/* Otros documentos */}
                    <div className="flex items-center justify-between pt-2">
                      <span className="text-[10px] font-black uppercase text-slate-400 tracking-wider">Otros Documentos</span>
                      {uploadButton('otro')}
                    </div>
                    {expediente.extras.length === 0 ? (
                      <div className="py-4 text-center text-slate-400 font-bold text-xs bg-slate-50 rounded-xl border border-dashed border-slate-200">
                        Sin documentos adicionales.
                      </div>
                    ) : (
                      <div className="border border-slate-200 rounded-2xl overflow-hidden divide-y divide-slate-200">
                        {expediente.extras.map((doc) => docRow(doc))}
                      </div>
                    )}
                  </div>
                ) : (
                  <div className="flex-1 flex items-center justify-center text-slate-400 font-bold text-xs">
                    No se pudo cargar el expediente.
                  </div>
                )}
              </div>
            )}
          </>
        )}

        {/* TAB 2: CORPORATIVOS (MANUALES Y PROTOCOLOS) */}
        {activeTab === 'company' && (
          <div className="w-full bg-white rounded-3xl border border-slate-200 p-6 flex flex-col overflow-hidden shadow-sm">
            <div className="flex items-center justify-between mb-6 shrink-0 border-b border-slate-100 pb-4 gap-3 flex-wrap">
              <div>
                <h3 className="font-black text-slate-800 text-base">Almacén de Protocolos y Manuales</h3>
                <p className="text-xs text-slate-500">Documentos oficiales que definen la operación corporativa.</p>
              </div>
              <div className="flex items-center gap-2">
                <div className="relative w-48 sm:w-64">
                  <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                  <input
                    type="text"
                    placeholder="Buscar manuales..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="w-full pl-8 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
                <button
                  onClick={() => setShowCorpUpload(true)}
                  className="flex items-center gap-1.5 px-3 py-2 bg-blue-600 text-white hover:bg-blue-700 rounded-lg text-xs font-black transition-colors shadow-md shadow-blue-600/10"
                >
                  <UploadCloud size={14} /> Subir Manual
                </button>
              </div>
            </div>

            {loadingCorporate ? (
              <div className="flex-1 flex flex-col items-center justify-center text-slate-400 text-xs font-medium">
                <Loader2 className="animate-spin text-blue-500 mb-2" size={24} />
                Cargando manuales...
              </div>
            ) : corporateError ? (
              <div className="flex-1 flex flex-col items-center justify-center gap-3 text-xs">
                <span className="text-rose-500 font-bold flex items-center gap-1"><AlertCircle size={14} /> No se pudieron cargar los manuales.</span>
                <button
                  onClick={() => { setLoadingCorporate(true); fetchCorporate(); }}
                  className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-bold transition-colors"
                >
                  Reintentar
                </button>
              </div>
            ) : filteredCorporate.length === 0 ? (
              <div className="flex-1 flex items-center justify-center text-slate-400 font-bold text-xs">
                Aún no hay manuales corporativos. Sube el primero con "Subir Manual".
              </div>
            ) : (
              <div className="flex-1 overflow-y-auto grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 scrollbar-thin content-start">
                {filteredCorporate.map((d) => (
                  <div key={d.id} className="border border-slate-200 rounded-3xl p-5 hover:border-blue-300 hover:shadow-md transition-all flex flex-col justify-between group self-start">
                    <div>
                      <div className="flex justify-between items-start mb-3">
                        <span className="p-2 bg-indigo-50 text-indigo-600 rounded-xl group-hover:scale-105 transition-transform"><BookOpen size={20} /></span>
                        <span className="text-[9px] font-black uppercase tracking-widest bg-slate-100 text-slate-600 px-2.5 py-1 rounded border border-slate-200">{d.category}</span>
                      </div>
                      <h4 className="font-extrabold text-slate-800 text-sm leading-snug">{d.name}</h4>
                      <p className="text-[10px] text-slate-400 font-semibold mt-2">
                        {formatBytes(d.size_bytes)} • Subido el {formatDate(d.created_at)}
                      </p>
                    </div>

                    <div className="border-t border-slate-100 pt-4 mt-5">
                      <div className="flex justify-between items-center text-xs mb-4">
                        <span className="text-slate-400 font-bold">Vinculado a:</span>
                        {d.course ? (
                          <span className="inline-flex items-center gap-1 text-[10px] font-bold text-blue-600 bg-blue-50 px-2.5 py-0.5 rounded-full">
                            <CheckCircle2 size={10} /> {d.course.title}
                          </span>
                        ) : d.linked_course_id !== null ? (
                          // El curso vinculado fue borrado en Academia (soft-delete: el FK no
                          // dispara). Decirlo — y dejar el botón de quitar el vínculo activo.
                          <span className="inline-flex items-center gap-1 text-[10px] font-bold text-amber-600 bg-amber-50 border border-amber-100 px-2.5 py-0.5 rounded-full">
                            <AlertCircle size={10} /> Curso eliminado
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 text-[10px] font-bold text-slate-400 bg-slate-100 px-2.5 py-0.5 rounded-full">
                            Sin vincular
                          </span>
                        )}
                      </div>

                      <div className="flex gap-2">
                        <button
                          onClick={() => handlePreview(d.id, d.original_name, d.mime, 'corporativo')}
                          className="flex-1 py-2 rounded-xl text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 transition-colors flex items-center justify-center gap-1"
                          disabled={loadingPreviewId === d.id}
                        >
                          {loadingPreviewId === d.id ? <Loader2 size={12} className="animate-spin" /> : <Eye size={12} />} Ver
                        </button>
                        <button
                          onClick={() => handleDownload(d.id, d.original_name, 'corporativo')}
                          className="py-2 px-3 rounded-xl text-xs font-bold bg-slate-100 text-slate-700 hover:bg-slate-200 transition-colors flex items-center justify-center"
                          title="Descargar"
                        >
                          <Download size={12} />
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
            )}
          </div>
        )}

      </div>

      {/* MODAL 1: VISOR REAL (blob autenticado: PDF en iframe, imagen directa) */}
      {previewDoc && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-50 p-4 animate-in fade-in duration-200">
          <div className="bg-slate-900 text-white rounded-3xl max-w-3xl w-full shadow-2xl flex flex-col border border-slate-800 animate-in zoom-in-95 duration-150 overflow-hidden max-h-[90vh]">
            <div className="px-6 py-4 bg-slate-800 border-b border-slate-700 flex justify-between items-center shrink-0">
              <div className="flex items-center gap-2 min-w-0">
                <FileCheck size={18} className="text-emerald-400 shrink-0" />
                <span className="font-extrabold text-sm truncate">{previewDoc.name}</span>
              </div>
              <button
                onClick={closePreview}
                className="p-1 hover:bg-slate-700 rounded text-slate-400 hover:text-white transition-colors shrink-0"
              >
                <X size={18} />
              </button>
            </div>

            <div className="flex-1 bg-slate-950 min-h-[420px] flex items-center justify-center overflow-hidden">
              {previewDoc.mime === 'application/pdf' ? (
                <iframe src={previewDoc.url} title={previewDoc.name} className="w-full h-[70vh] border-0 bg-white" />
              ) : (
                <img src={previewDoc.url} alt={previewDoc.name} className="max-w-full max-h-[70vh] object-contain" />
              )}
            </div>

            <div className="px-6 py-4 bg-slate-900 border-t border-slate-800 flex gap-2 justify-end shrink-0">
              <button
                onClick={() => triggerDownload(previewDoc.url, previewDoc.name, false)}
                className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold flex items-center gap-1"
              >
                <Download size={12} /> Descargar
              </button>
              <button
                onClick={closePreview}
                className="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-bold"
              >
                Cerrar
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL 2: VINCULAR MANUAL A CURSO ACADEMIA (persiste en el backend) */}
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
              Selecciona a qué curso de la <b>Academia 360</b> deseas asociar el archivo{' '}
              <span className="font-extrabold text-slate-800">"{isLinkingDoc.name}"</span>.
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
                {courses.map((course) => (
                  <div
                    key={course.id}
                    onClick={() => handleLinkManual(course.id)}
                    className="p-3 bg-slate-50 hover:bg-blue-50/40 border border-slate-200 hover:border-blue-200 rounded-xl cursor-pointer flex justify-between items-center transition-all group"
                  >
                    <div>
                      <span className="block font-extrabold text-slate-800 text-xs">{course.title}</span>
                      <span className="text-[9px] text-slate-400 font-medium">{course.course_type === 'induction' ? 'Inducción de Puesto' : 'Capacitación Continua'}</span>
                    </div>
                    <ArrowRight size={14} className="text-slate-300 group-hover:text-blue-500 group-hover:translate-x-1 transition-all" />
                  </div>
                ))}
              </div>
            )}

            <div className="flex gap-2 mt-6">
              {isLinkingDoc.linked_course_id !== null && (
                <button
                  onClick={() => handleLinkManual(null)}
                  className="flex-1 py-3 bg-rose-50 text-rose-600 font-bold hover:bg-rose-100 border border-rose-100 rounded-xl text-xs transition-colors"
                >
                  Quitar vínculo
                </button>
              )}
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

      {/* MODAL 3: SUBIR MANUAL CORPORATIVO */}
      {showCorpUpload && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-50 p-4 animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl max-w-md w-full shadow-2xl p-6 border border-slate-100 animate-in zoom-in-95 duration-150 flex flex-col">
            <div className="flex justify-between items-center border-b border-slate-100 pb-4 mb-4">
              <h4 className="font-black text-slate-800 text-base flex items-center gap-2">
                <UploadCloud className="text-blue-500" size={18} />
                Subir Manual Corporativo
              </h4>
              <button
                onClick={() => { setShowCorpUpload(false); setCorpFile(null); setCorpCategory(''); }}
                className="p-1 hover:bg-slate-50 rounded-lg text-slate-400 hover:text-slate-600 transition-colors"
              >
                <X size={16} />
              </button>
            </div>

            <label className="text-[10px] font-black uppercase text-slate-400 tracking-wider mb-1">Categoría</label>
            <input
              type="text"
              list="corp-categorias"
              value={corpCategory}
              onChange={(e) => setCorpCategory(e.target.value)}
              placeholder="Ej. Manuales de Operación"
              className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs outline-none focus:ring-1 focus:ring-blue-500 mb-4"
            />
            <datalist id="corp-categorias">
              <option value="Manuales de Operación" />
              <option value="Seguridad" />
              <option value="Servicio al Cliente" />
              <option value="Reglamento Interno" />
            </datalist>

            <label className="flex flex-col items-center justify-center gap-2 p-6 bg-slate-50 border-2 border-dashed border-slate-200 hover:border-blue-300 rounded-2xl cursor-pointer transition-colors text-center">
              <UploadCloud size={24} className="text-blue-500" />
              <span className="text-xs font-bold text-slate-700">
                {corpFile ? corpFile.name : 'Elegir archivo (PDF/JPG/PNG, máx. 10 MB)'}
              </span>
              <input
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                className="hidden"
                onChange={(e) => {
                  setCorpFile(e.target.files?.[0] || null);
                  // Sin esto, re-elegir un archivo del mismo nombre no dispara onChange
                  // y el reintento sube el snapshot rancio (ERR_UPLOAD_FILE_CHANGED).
                  e.target.value = '';
                }}
              />
            </label>

            <div className="flex gap-2 mt-6">
              <button
                onClick={() => { setShowCorpUpload(false); setCorpFile(null); setCorpCategory(''); }}
                className="flex-1 py-3 bg-slate-100 text-slate-700 font-bold hover:bg-slate-200 rounded-xl text-xs transition-colors"
              >
                Cancelar
              </button>
              <button
                onClick={handleCorpUpload}
                disabled={corpUploading}
                className="flex-1 py-3 bg-blue-600 text-white font-black hover:bg-blue-700 rounded-xl text-xs transition-colors disabled:opacity-50 flex items-center justify-center gap-1"
              >
                {corpUploading ? <Loader2 size={14} className="animate-spin" /> : <UploadCloud size={14} />} Subir
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL 4: MOTIVO DE RECHAZO (obligatorio — también lo exige el servidor) */}
      {rejectingDoc && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-50 p-4 animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl max-w-md w-full shadow-2xl p-6 border border-slate-100 animate-in zoom-in-95 duration-150 flex flex-col">
            <div className="flex justify-between items-center border-b border-slate-100 pb-4 mb-4">
              <h4 className="font-black text-slate-800 text-base flex items-center gap-2">
                <XCircle className="text-rose-500" size={18} />
                Rechazar Documento
              </h4>
              <button
                onClick={() => setRejectingDoc(null)}
                className="p-1 hover:bg-slate-50 rounded-lg text-slate-400 hover:text-slate-600 transition-colors"
              >
                <X size={16} />
              </button>
            </div>

            <p className="text-xs text-slate-500 mb-3 leading-normal">
              Explica por qué se rechaza <span className="font-extrabold text-slate-800">"{rejectingDoc.original_name}"</span>.
              El colaborador (o quien lo suba de nuevo) verá este motivo.
            </p>

            <textarea
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              placeholder="Ej. El documento está ilegible, re-escanear por favor."
              rows={3}
              className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs outline-none focus:ring-1 focus:ring-rose-400 resize-none"
            />

            <div className="flex gap-2 mt-6">
              <button
                onClick={() => setRejectingDoc(null)}
                className="flex-1 py-3 bg-slate-100 text-slate-700 font-bold hover:bg-slate-200 rounded-xl text-xs transition-colors"
              >
                Cancelar
              </button>
              <button
                onClick={async () => {
                  if (!rejectReason.trim()) {
                    alert('El motivo es obligatorio para rechazar.');
                    return;
                  }
                  // Cerrar solo si el servidor aceptó — si falla, el motivo escrito no se pierde.
                  if (await handleValidate(rejectingDoc, 'rechazar', rejectReason.trim())) {
                    setRejectingDoc(null);
                  }
                }}
                className="flex-1 py-3 bg-rose-600 text-white font-black hover:bg-rose-700 rounded-xl text-xs transition-colors"
              >
                Rechazar
              </button>
            </div>
          </div>
        </div>
      )}

    </div>
  );
};
