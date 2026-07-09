import React, { useState, useEffect, useRef } from 'react';
import { 
  Search, FileText, Briefcase, Repeat, CheckSquare, Settings, 
  Edit, Check, X, ChevronRight, MessageSquare, Upload, 
  GitPullRequest, Eye, BookOpen, AlertCircle, Sparkles, CheckCircle2
} from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';

interface DocIndexItem {
  id: number;
  title: string;
  slug: string;
  icon: string;
  type: string;
}

interface DocIndex {
  puesto?: DocIndexItem[];
  proceso?: DocIndexItem[];
  tarea?: DocIndexItem[];
  nota?: DocIndexItem[];
}

export function OrgVaultManager() {
  const { currentUser } = useAppStore();
  const isAdmin = currentUser?.role === 'admin' || currentUser?.role === 'supervisor';
  
  // State variables
  const [index, setIndex] = useState<DocIndex>({});
  const [searchQuery, setSearchQuery] = useState('');
  const [activeSlug, setActiveSlug] = useState<string>('');
  const [activeDoc, setActiveDoc] = useState<any>(null);
  const [links, setLinks] = useState<any[]>([]);
  const [backlinks, setBacklinks] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const [loadingDoc, setLoadingDoc] = useState(false);
  
  // Suggestion state
  const [isSuggesting, setIsSuggesting] = useState(false);
  const [proposedContent, setProposedContent] = useState('');
  const [suggestionComment, setSuggestionComment] = useState('');
  const [submittingSuggestion, setSubmittingSuggestion] = useState(false);
  
  // Admin tabs & state
  const [adminTab, setAdminTab] = useState<'view' | 'sync' | 'suggestions' | 'edit'>('view');
  const [vaultSettings, setVaultSettings] = useState<any>({ name: '', local_path: '' });
  const [zipFile, setZipFile] = useState<File | null>(null);
  const [syncing, setSyncing] = useState(false);
  
  // Direct edit state
  const [editText, setEditText] = useState('');
  const [editTitle, setEditTitle] = useState('');
  const [editType, setEditType] = useState('nota');
  const [editIcon, setEditIcon] = useState('file-text');
  const [savingEdit, setSavingEdit] = useState(false);

  // Suggestions state
  const [suggestions, setSuggestions] = useState<any[]>([]);
  const [reviewingSuggestion, setReviewingSuggestion] = useState<any>(null);
  const [reviewComment, setReviewComment] = useState('');
  const [processingReview, setProcessingReview] = useState(false);

  // References
  const contentRef = useRef<HTMLDivElement>(null);

  // Fetch index
  const fetchIndex = async (autoLoadFirst = false) => {
    setLoading(true);
    try {
      const res = await axiosInstance.get('/org-vault/index');
      setIndex(res.data.documents || {});
      
      // Auto-load first document if none active
      if (autoLoadFirst && !activeSlug) {
        const categories = ['puesto', 'proceso', 'tarea', 'nota'];
        let firstSlug = '';
        for (const cat of categories) {
          const docs = res.data.documents[cat];
          if (docs && docs.length > 0) {
            firstSlug = docs[0].slug;
            break;
          }
        }
        if (firstSlug) {
          setActiveSlug(firstSlug);
        }
      }
    } catch (err) {
      console.error('Error fetching index:', err);
    } finally {
      setLoading(false);
    }
  };

  // Fetch specific document
  const fetchDocument = async (slug: string) => {
    if (!slug) return;
    setLoadingDoc(true);
    setIsSuggesting(false);
    try {
      const res = await axiosInstance.get(`/org-vault/doc/${slug}`);
      setActiveDoc(res.data.document);
      setLinks(res.data.links || []);
      setBacklinks(res.data.backlinks || []);
      
      // Prep editing values
      setProposedContent(res.data.document.raw_content || '');
      setEditText(res.data.document.raw_content || '');
      setEditTitle(res.data.document.title || '');
      setEditType(res.data.document.type || 'nota');
      setEditIcon(res.data.document.icon || 'file-text');
    } catch (err) {
      console.error('Error fetching document:', err);
    } finally {
      setLoadingDoc(false);
    }
  };

  // Fetch settings & suggestions (Admin only)
  const fetchAdminData = async () => {
    if (!isAdmin) return;
    try {
      const settingsRes = await axiosInstance.get('/org-vault/settings');
      setVaultSettings(settingsRes.data);
      
      const suggestionsRes = await axiosInstance.get('/org-vault/suggestions');
      setSuggestions(suggestionsRes.data || []);
    } catch (err) {
      console.error('Error fetching admin data:', err);
    }
  };

  useEffect(() => {
    fetchIndex(true);
    fetchAdminData();
  }, []);

  useEffect(() => {
    if (activeSlug) {
      fetchDocument(activeSlug);
    }
  }, [activeSlug]);

  // Intercept WikiLink clicks in parsed HTML
  useEffect(() => {
    const handleWikiClick = (e: MouseEvent) => {
      const target = e.target as HTMLElement;
      if (target.classList.contains('wiki-link')) {
        e.preventDefault();
        const slug = target.getAttribute('data-target-slug');
        if (slug) {
          setActiveSlug(slug);
          if (adminTab === 'edit' || adminTab === 'suggestions') {
            setAdminTab('view');
          }
        }
      }
    };

    const contentDiv = contentRef.current;
    if (contentDiv) {
      contentDiv.addEventListener('click', handleWikiClick);
    }
    return () => {
      if (contentDiv) {
        contentDiv.removeEventListener('click', handleWikiClick);
      }
    };
  }, [activeDoc, adminTab]);

  // Submit suggestion
  const handleSubmitSuggestion = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!proposedContent.trim()) return;
    setSubmittingSuggestion(true);
    try {
      await axiosInstance.post('/org-vault/suggest', {
        document_id: activeDoc.id,
        proposed_content: proposedContent,
        comment: suggestionComment
      });
      alert('Sugerencia enviada con éxito. Un administrador la revisará.');
      setIsSuggesting(false);
      setSuggestionComment('');
    } catch (err: any) {
      alert('Error al enviar la sugerencia: ' + (err.response?.data?.message || err.message));
    } finally {
      setSubmittingSuggestion(false);
    }
  };

  // Sync settings
  const handleSaveSettings = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const res = await axiosInstance.post('/org-vault/settings', vaultSettings);
      alert('Configuración guardada correctamente.');
      setVaultSettings(res.data.vault);
    } catch (err: any) {
      alert('Error al guardar: ' + (err.response?.data?.message || err.message));
    }
  };

  // Trigger Local Sync
  const handleLocalSync = async () => {
    setSyncing(true);
    try {
      const res = await axiosInstance.post('/org-vault/sync-local', {
        local_path: vaultSettings.local_path
      });
      alert(`Sincronización exitosa: ${res.data.count} documentos procesados.`);
      fetchIndex();
    } catch (err: any) {
      alert('Error en la sincronización local: ' + (err.response?.data?.message || err.message));
    } finally {
      setSyncing(false);
    }
  };

  // Trigger ZIP Sync
  const handleZipSync = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!zipFile) return;
    setSyncing(true);
    const formData = new FormData();
    formData.append('zip_file', zipFile);
    try {
      const res = await axiosInstance.post('/org-vault/sync-zip', formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
      });
      alert(`Sincronización de ZIP exitosa: ${res.data.count} documentos procesados.`);
      setZipFile(null);
      fetchIndex();
    } catch (err: any) {
      alert('Error al procesar el archivo ZIP: ' + (err.response?.data?.message || err.message));
    } finally {
      setSyncing(false);
    }
  };

  // Direct edit save
  const handleSaveDirectEdit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSavingEdit(true);
    try {
      await axiosInstance.post('/org-vault/edit', {
        id: activeDoc.id,
        title: editTitle,
        raw_content: editText,
        type: editType,
        icon: editIcon
      });
      alert('Documento actualizado correctamente.');
      setAdminTab('view');
      fetchIndex();
      fetchDocument(activeSlug);
    } catch (err: any) {
      alert('Error al guardar edición: ' + (err.response?.data?.message || err.message));
    } finally {
      setSavingEdit(false);
    }
  };

  // Process suggestion (Approve/Reject)
  const handleProcessSuggestion = async (approved: boolean) => {
    if (!reviewingSuggestion) return;
    setProcessingReview(true);
    const url = `/org-vault/suggestions/${reviewingSuggestion.id}/${approved ? 'approve' : 'reject'}`;
    try {
      const res = await axiosInstance.post(url, { review_comment: reviewComment });
      alert(res.data.message);
      setReviewingSuggestion(null);
      setReviewComment('');
      
      // Reload everything
      fetchIndex();
      if (activeSlug) fetchDocument(activeSlug);
      fetchAdminData();
    } catch (err: any) {
      alert('Error al procesar propuesta: ' + (err.response?.data?.message || err.message));
    } finally {
      setProcessingReview(false);
    }
  };

  // Helpers
  const getIcon = (iconName: string) => {
    switch (iconName) {
      case 'briefcase': return <Briefcase size={18} />;
      case 'repeat': return <Repeat size={18} />;
      case 'check-square': return <CheckSquare size={18} />;
      default: return <FileText size={18} />;
    }
  };

  const getCategoryTitle = (cat: string) => {
    switch (cat) {
      case 'puesto': return 'Puestos y Organigrama';
      case 'proceso': return 'Procesos de Operación (SOP)';
      case 'tarea': return 'Listas de Control / Checklists';
      default: return 'Notas Generales';
    }
  };

  // Filter index items by search query
  const filteredIndex = () => {
    const query = searchQuery.toLowerCase();
    const result: DocIndex = {};
    const categories: ('puesto' | 'proceso' | 'tarea' | 'nota')[] = ['puesto', 'proceso', 'tarea', 'nota'];
    
    categories.forEach(cat => {
      const items = index[cat];
      if (items) {
        const filtered = items.filter((item: DocIndexItem) => 
          item.title.toLowerCase().includes(query) || 
          item.type.toLowerCase().includes(query)
        );
        if (filtered.length > 0) {
          result[cat] = filtered;
        }
      }
    });
    return result;
  };

  const activeCategoryTitle = activeDoc ? getCategoryTitle(activeDoc.type) : '';

  return (
    <div className="flex flex-col lg:flex-row h-full gap-6 select-text">
      
      {/* Sidebar - Index list */}
      <div className="w-full lg:w-80 bg-white border border-slate-200 rounded-3xl p-5 shadow-sm flex flex-col shrink-0">
        <div className="flex items-center justify-between mb-4">
          <h3 className="font-black text-slate-800 tracking-tight text-lg">Índice del Baúl</h3>
          {isAdmin && (
            <button 
              onClick={() => {
                setAdminTab(adminTab === 'view' ? 'sync' : 'view');
              }} 
              className={`p-2 rounded-xl border transition-colors ${adminTab === 'sync' ? 'bg-blue-50 border-blue-200 text-blue-600' : 'bg-slate-50 border-slate-200 text-slate-500 hover:bg-slate-100'}`}
              title="Ajustes de Sincronización"
            >
              <Settings size={16} />
            </button>
          )}
        </div>

        {/* Search */}
        <div className="relative mb-5">
          <Search size={16} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
          <input 
            type="text" 
            placeholder="Buscar en el baúl..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full pl-10 pr-4 py-2.5 rounded-2xl border border-slate-200 bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:border-blue-500 focus:bg-white text-sm font-medium transition-all"
          />
        </div>

        {/* List index */}
        <div className="flex-1 overflow-y-auto pr-1 space-y-5 custom-scrollbar max-h-[300px] lg:max-h-none">
          {loading ? (
            <div className="text-center py-8 text-slate-400 text-xs font-semibold animate-pulse">Cargando baúl...</div>
          ) : Object.keys(filteredIndex()).length === 0 ? (
            <div className="text-center py-8 text-slate-400 text-xs font-semibold">No se encontraron documentos.</div>
          ) : (
            Object.entries(filteredIndex()).map(([category, items]) => (
              <div key={category} className="space-y-1.5">
                <h4 className="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2 mb-1.5">
                  {getCategoryTitle(category)}
                </h4>
                {(items as DocIndexItem[]).map((item: DocIndexItem) => {
                  const isActive = activeSlug === item.slug && adminTab === 'view';
                  return (
                    <button
                      key={item.id}
                      onClick={() => {
                        setActiveSlug(item.slug);
                        setAdminTab('view');
                      }}
                      className={`w-full flex items-center gap-3 p-2.5 rounded-xl text-left transition-all ${
                        isActive 
                          ? 'bg-blue-50 text-blue-700 font-bold border-l-4 border-blue-600 shadow-sm' 
                          : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900 border-l-4 border-transparent'
                      }`}
                    >
                      <div className={`p-1.5 rounded-lg ${isActive ? 'bg-white shadow-sm text-blue-600' : 'bg-slate-100 text-slate-500'}`}>
                        {getIcon(item.icon)}
                      </div>
                      <span className="text-xs font-bold truncate flex-1">{item.title}</span>
                      <ChevronRight size={14} className={`opacity-40 transition-transform ${isActive && 'translate-x-0.5'}`} />
                    </button>
                  );
                })}
              </div>
            ))
          )}
        </div>

        {/* Public view share alert */}
        <div className="mt-4 p-4 border border-slate-100 bg-slate-50 rounded-2xl">
          <div className="flex gap-2.5">
            <AlertCircle size={16} className="text-blue-500 shrink-0 mt-0.5" />
            <div className="flex flex-col text-left">
              <span className="text-[11px] font-black text-slate-800">Publicado Online</span>
              <p className="text-[10px] text-slate-500 font-medium leading-relaxed mt-0.5">
                Este baúl está enlazado a una página pública de solo lectura. Cualquiera puede ver la estructura.
              </p>
              <a 
                href={`/organizacion/${currentUser?.tenant?.public_slug || currentUser?.tenant?.subdomain || 'decorarte360'}`}
                target="_blank"
                rel="noreferrer"
                className="text-[10px] text-blue-600 hover:text-blue-700 font-bold mt-1.5 flex items-center gap-1"
              >
                <Eye size={12} /> Ver Web Pública
              </a>
            </div>
          </div>
        </div>
      </div>

      {/* Main pane */}
      <div className="flex-1 bg-white border border-slate-200 rounded-3xl p-6 shadow-sm flex flex-col min-w-0">
        
        {/* Admin Navigation (for managers) */}
        {isAdmin && (
          <div className="flex border-b border-slate-200 pb-3 mb-5 gap-2 overflow-x-auto">
            <button
              onClick={() => setAdminTab('view')}
              className={`px-4 py-2 rounded-xl font-bold text-xs transition-all flex items-center gap-2 ${
                adminTab === 'view' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'
              }`}
            >
              <BookOpen size={14} /> Leer Documento
            </button>
            <button
              onClick={() => setAdminTab('suggestions')}
              className={`px-4 py-2 rounded-xl font-bold text-xs transition-all flex items-center gap-2 relative ${
                adminTab === 'suggestions' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'
              }`}
            >
              <GitPullRequest size={14} /> Propuestas de Cambio
              {suggestions.filter(s => s.status === 'pending').length > 0 && (
                <span className="absolute -top-1.5 -right-1.5 w-5 h-5 bg-rose-500 text-white font-black text-[9px] rounded-full flex items-center justify-center animate-bounce">
                  {suggestions.filter(s => s.status === 'pending').length}
                </span>
              )}
            </button>
            <button
              onClick={() => setAdminTab('edit')}
              className={`px-4 py-2 rounded-xl font-bold text-xs transition-all flex items-center gap-2 ${
                adminTab === 'edit' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'
              }`}
              disabled={!activeDoc}
            >
              <Edit size={14} /> Editar Directamente
            </button>
            <button
              onClick={() => setAdminTab('sync')}
              className={`px-4 py-2 rounded-xl font-bold text-xs transition-all flex items-center gap-2 ${
                adminTab === 'sync' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'
              }`}
            >
              <Upload size={14} /> Sincronización
            </button>
          </div>
        )}

        {/* Tab content */}
        {adminTab === 'view' && (
          <div className="flex-1 flex flex-col lg:flex-row gap-6 min-w-0">
            {/* Document Content */}
            <div className="flex-1 flex flex-col min-w-0">
              {loadingDoc ? (
                <div className="flex-1 flex items-center justify-center py-20 text-slate-400 font-semibold animate-pulse text-sm">
                  Cargando documento...
                </div>
              ) : !activeDoc ? (
                <div className="flex-1 flex flex-col items-center justify-center py-20 text-slate-400 text-sm font-semibold">
                  <BookOpen size={48} className="text-slate-300 mb-4 animate-bounce" />
                  Selecciona un documento en el índice para comenzar la lectura
                </div>
              ) : isSuggesting ? (
                /* SUGGEST CHANGE FORM */
                <form onSubmit={handleSubmitSuggestion} className="flex-1 flex flex-col text-left space-y-4">
                  <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div>
                      <h3 className="text-lg font-black text-slate-900">Sugerir Cambio</h3>
                      <p className="text-xs text-slate-500 font-semibold">Estás sugiriendo mejoras para: <span className="text-blue-600">{activeDoc.title}</span></p>
                    </div>
                    <button 
                      type="button" 
                      onClick={() => setIsSuggesting(false)} 
                      className="p-1.5 rounded-full hover:bg-slate-100 text-slate-500"
                    >
                      <X size={18} />
                    </button>
                  </div>

                  <div className="bg-amber-50 border border-amber-100 rounded-2xl p-4 text-xs font-semibold text-amber-700 flex gap-2.5">
                    <Sparkles size={16} className="shrink-0 mt-0.5" />
                    <span>Redacta los cambios en formato markdown libre. Tu propuesta será validada y aprobada por un administrador antes de publicarse.</span>
                  </div>

                  <div className="flex-1 flex flex-col min-h-[250px]">
                    <label className="text-xs font-black text-slate-500 uppercase tracking-widest mb-1.5">Contenido Propuesto</label>
                    <textarea
                      value={proposedContent}
                      onChange={(e) => setProposedContent(e.target.value)}
                      className="w-full flex-1 p-4 border border-slate-200 rounded-2xl focus:outline-none focus:border-blue-500 font-mono text-xs leading-relaxed"
                      placeholder="Redacta el nuevo contenido..."
                      required
                    />
                  </div>

                  <div className="flex flex-col">
                    <label className="text-xs font-black text-slate-500 uppercase tracking-widest mb-1.5">Comentario / Motivo de la propuesta</label>
                    <input
                      type="text"
                      value={suggestionComment}
                      onChange={(e) => setSuggestionComment(e.target.value)}
                      placeholder="Explica brevemente por qué propones este cambio..."
                      className="w-full px-4 py-2.5 border border-slate-200 rounded-2xl focus:outline-none focus:border-blue-500 text-sm font-medium"
                    />
                  </div>

                  <div className="flex gap-3 justify-end pt-2">
                    <button
                      type="button"
                      onClick={() => setIsSuggesting(false)}
                      className="px-4 py-2.5 rounded-xl font-bold text-xs text-slate-650 bg-slate-100 hover:bg-slate-200 transition-colors"
                    >
                      Cancelar
                    </button>
                    <button
                      type="submit"
                      disabled={submittingSuggestion}
                      className="px-4 py-2.5 rounded-xl font-black text-xs text-white bg-blue-600 hover:bg-blue-700 shadow-md shadow-blue-600/20 transition-all flex items-center gap-1.5"
                    >
                      {submittingSuggestion ? 'Enviando...' : 'Enviar Sugerencia'}
                    </button>
                  </div>
                </form>
              ) : (
                /* STANDARD READ VIEW */
                <div className="flex-1 flex flex-col text-left">
                  {/* Header */}
                  <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4 border-b border-slate-150 pb-4 mb-5">
                    <div className="flex items-center gap-4">
                      <div className="w-14 h-14 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center shadow-inner shrink-0 [&>svg]:w-6 [&>svg]:h-6">
                        {getIcon(activeDoc.icon)}
                      </div>
                      <div className="flex flex-col">
                        <span className="text-[10px] font-black uppercase text-blue-600 tracking-widest leading-none mb-1.5">{activeCategoryTitle}</span>
                        <h1 className="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight leading-tight">{activeDoc.title}</h1>
                      </div>
                    </div>
                    
                    {!isAdmin && (
                      <button
                        onClick={() => setIsSuggesting(true)}
                        className="px-4 py-2.5 rounded-xl font-black text-xs text-blue-700 bg-blue-50 hover:bg-blue-100 transition-all shrink-0 flex items-center justify-center gap-1.5 border border-blue-200/50 self-start"
                      >
                        <MessageSquare size={14} /> Sugerir Mejora
                      </button>
                    )}
                  </div>

                  {/* Rendered HTML Content */}
                  <div 
                    ref={contentRef}
                    className="flex-1 text-slate-800 leading-relaxed pr-1 custom-markdown"
                    dangerouslySetInnerHTML={{ __html: activeDoc.content || '<p class="text-slate-400 italic">Este documento no tiene contenido redactado.</p>' }}
                  />

                  {/* Info alert / Help */}
                  <div className="mt-8 pt-4 border-t border-slate-100 text-[11px] text-slate-400 font-bold flex justify-between items-center">
                    <span>Última sincronización: {new Date(activeDoc.updated_at).toLocaleDateString()}</span>
                    <span>Haga clic en los enlaces azules para navegar instantáneamente</span>
                  </div>
                </div>
              )}
            </div>

            {/* Right Pane (Metadata, links and backlinks) */}
            {activeDoc && !isSuggesting && (
              <div className="w-full lg:w-60 shrink-0 flex flex-col gap-5 text-left border-t lg:border-t-0 lg:border-l border-slate-150 pt-5 lg:pt-0 lg:pl-5">
                {/* Linked Documents (Outgoing) */}
                <div className="space-y-2">
                  <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest block">Enlaces en esta Nota</span>
                  {links.length === 0 ? (
                    <span className="text-xs text-slate-400 font-medium italic block pl-1">No contiene enlaces de salida.</span>
                  ) : (
                    <div className="space-y-1">
                      {links.map(l => (
                        <button
                          key={l.id}
                          onClick={() => setActiveSlug(l.slug)}
                          className="w-full flex items-center gap-2 p-1.5 rounded-lg hover:bg-slate-50 text-left transition-colors"
                        >
                          <div className="text-blue-600 shrink-0">{getIcon(l.icon)}</div>
                          <span className="text-xs font-bold text-blue-600 hover:underline truncate">{l.title}</span>
                        </button>
                      ))}
                    </div>
                  )}
                </div>

                {/* Backlinks (Incoming) */}
                <div className="space-y-2">
                  <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest block">Vínculos de Retroceso</span>
                  {backlinks.length === 0 ? (
                    <span className="text-xs text-slate-400 font-medium italic block pl-1">Ninguna nota enlaza a esta.</span>
                  ) : (
                    <div className="space-y-1">
                      {backlinks.map(l => (
                        <button
                          key={l.id}
                          onClick={() => setActiveSlug(l.slug)}
                          className="w-full flex items-center gap-2 p-1.5 rounded-lg hover:bg-slate-50 text-left transition-colors"
                        >
                          <div className="text-slate-500 shrink-0">{getIcon(l.icon)}</div>
                          <span className="text-xs font-bold text-slate-700 hover:underline truncate">{l.title}</span>
                        </button>
                      ))}
                    </div>
                  )}
                </div>

                {/* Categories description helper */}
                <div className="p-4 bg-slate-50 border border-slate-100 rounded-2xl mt-auto">
                  <span className="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1.5">Guía de Iconos</span>
                  <div className="space-y-1.5">
                    <div className="flex items-center gap-2 text-xs font-bold text-slate-600">
                      <div className="p-1 bg-slate-200/60 rounded text-slate-500"><Briefcase size={12} /></div>
                      Puesto Organizacional
                    </div>
                    <div className="flex items-center gap-2 text-xs font-bold text-slate-600">
                      <div className="p-1 bg-slate-200/60 rounded text-slate-500"><Repeat size={12} /></div>
                      Proceso o Flujo
                    </div>
                    <div className="flex items-center gap-2 text-xs font-bold text-slate-600">
                      <div className="p-1 bg-slate-200/60 rounded text-slate-500"><CheckSquare size={12} /></div>
                      Tarea y Checklist
                    </div>
                    <div className="flex items-center gap-2 text-xs font-bold text-slate-600">
                      <div className="p-1 bg-slate-200/60 rounded text-slate-500"><FileText size={12} /></div>
                      Nota General / Manual
                    </div>
                  </div>
                </div>
              </div>
            )}
          </div>
        )}

        {/* Sync Settings tab */}
        {adminTab === 'sync' && isAdmin && (
          <div className="flex-1 flex flex-col text-left space-y-6 max-w-2xl">
            <div>
              <h3 className="text-lg font-black text-slate-900">Ajustes de Sincronización</h3>
              <p className="text-xs text-slate-500 font-semibold">Configura la lectura automatizada de tus archivos Markdown desde Google Drive u Obsidian.</p>
            </div>

            {/* Local Sync (Google Drive Desktop) */}
            <form onSubmit={handleSaveSettings} className="bg-slate-50 border border-slate-200 rounded-3xl p-6 space-y-4">
              <span className="text-xs font-black text-slate-800 uppercase tracking-wider block border-b border-slate-200 pb-2">Método 1: Carpeta Local Sincronizada</span>
              <p className="text-xs text-slate-500 font-medium leading-relaxed">
                Si el servidor corre de forma local o tiene acceso directo a unidades montadas en disco (ej. Google Drive Desktop), especifica la ruta absoluta de la carpeta de Obsidian.
              </p>
              
              <div className="space-y-3">
                <div>
                  <label className="text-xs font-black text-slate-500 uppercase tracking-widest block mb-1">Nombre del Baúl</label>
                  <input
                    type="text"
                    value={vaultSettings.name || ''}
                    onChange={(e) => setVaultSettings({ ...vaultSettings, name: e.target.value })}
                    className="w-full px-4 py-2.5 border border-slate-200 bg-white rounded-2xl focus:outline-none focus:border-blue-500 text-sm font-medium"
                    placeholder="Ej. Mi Empresa Wiki"
                    required
                  />
                </div>
                <div>
                  <label className="text-xs font-black text-slate-500 uppercase tracking-widest block mb-1">Ruta del Servidor</label>
                  <input
                    type="text"
                    value={vaultSettings.local_path || ''}
                    onChange={(e) => setVaultSettings({ ...vaultSettings, local_path: e.target.value })}
                    className="w-full px-4 py-2.5 border border-slate-200 bg-white rounded-2xl focus:outline-none focus:border-blue-500 text-sm font-medium"
                    placeholder="Ej. C:\Users\Nombre\Google Drive\MiBaulObsidian"
                  />
                </div>
              </div>

              <div className="flex gap-3 justify-end pt-2">
                <button
                  type="submit"
                  className="px-4 py-2 rounded-xl font-bold text-xs bg-slate-200 text-slate-700 hover:bg-slate-300 transition-colors"
                >
                  Guardar Ruta
                </button>
                <button
                  type="button"
                  onClick={handleLocalSync}
                  disabled={syncing || !vaultSettings.local_path}
                  className="px-4 py-2 rounded-xl font-black text-xs bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 shadow-md shadow-blue-600/20 transition-all"
                >
                  {syncing ? 'Sincronizando...' : 'Sincronizar Ahora'}
                </button>
              </div>
            </form>

            {/* ZIP Upload Sync */}
            <form onSubmit={handleZipSync} className="bg-slate-50 border border-slate-200 rounded-3xl p-6 space-y-4">
              <span className="text-xs font-black text-slate-800 uppercase tracking-wider block border-b border-slate-200 pb-2">Método 2: Cargar Archivo ZIP</span>
              <p className="text-xs text-slate-500 font-medium leading-relaxed">
                Exporta tu baúl de Obsidian como un archivo `.zip` en tu computadora local y arrástralo aquí. Es ideal para cuando el servidor corre en la nube.
              </p>

              <div className="border-2 border-dashed border-slate-300 hover:border-slate-400 bg-white rounded-2xl p-6 text-center cursor-pointer transition-colors relative">
                <input
                  type="file"
                  accept=".zip"
                  onChange={(e) => {
                    if (e.target.files && e.target.files.length > 0) {
                      setZipFile(e.target.files[0]);
                    }
                  }}
                  className="absolute inset-0 opacity-0 w-full cursor-pointer"
                />
                <Upload size={32} className="text-slate-400 mx-auto mb-2" />
                {zipFile ? (
                  <span className="text-xs font-bold text-blue-600">{zipFile.name} ({(zipFile.size / 1024 / 1024).toFixed(2)} MB)</span>
                ) : (
                  <div className="flex flex-col">
                    <span className="text-xs font-bold text-slate-600">Arrastra tu archivo .zip aquí o haz clic para explorar</span>
                    <span className="text-[10px] text-slate-400 font-bold mt-1">Límite de tamaño: 20MB</span>
                  </div>
                )}
              </div>

              <div className="flex justify-end pt-2">
                <button
                  type="submit"
                  disabled={syncing || !zipFile}
                  className="px-4 py-2 rounded-xl font-black text-xs bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50 shadow-md shadow-blue-600/20 transition-all"
                >
                  {syncing ? 'Subiendo y Sincronizando...' : 'Subir ZIP'}
                </button>
              </div>
            </form>
          </div>
        )}

        {/* Edit Document Directly Tab */}
        {adminTab === 'edit' && isAdmin && activeDoc && (
          <form onSubmit={handleSaveDirectEdit} className="flex-1 flex flex-col text-left space-y-4">
            <div>
              <h3 className="text-lg font-black text-slate-900">Editar Documento Directamente</h3>
              <p className="text-xs text-slate-500 font-semibold">Modifica el título, tipo o contenido en markdown de la nota. Guardar reconstruirá los enlaces.</p>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label className="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Título del Documento</label>
                <input
                  type="text"
                  value={editTitle}
                  onChange={(e) => setEditTitle(e.target.value)}
                  className="w-full px-4 py-2 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 text-xs font-bold"
                  required
                />
              </div>
              <div>
                <label className="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Tipo de Documento</label>
                <select
                  value={editType}
                  onChange={(e) => setEditType(e.target.value)}
                  className="w-full px-4 py-2 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 text-xs font-bold"
                >
                  <option value="puesto">Puesto Organizacional</option>
                  <option value="proceso">Proceso de Operación (SOP)</option>
                  <option value="tarea">Tarea / Checklist</option>
                  <option value="nota">Nota General / Wiki</option>
                </select>
              </div>
              <div>
                <label className="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Icono (Lucide)</label>
                <select
                  value={editIcon}
                  onChange={(e) => setEditIcon(e.target.value)}
                  className="w-full px-4 py-2 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-550 text-xs font-bold"
                >
                  <option value="briefcase">Maletín (Puesto)</option>
                  <option value="repeat">Engrane de Ciclo (Proceso)</option>
                  <option value="check-square">Casilla de Checklist (Tarea)</option>
                  <option value="file-text">Papel de Texto (Nota General)</option>
                </select>
              </div>
            </div>

            <div className="flex-1 flex flex-col min-h-[300px]">
              <label className="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Contenido Markdown</label>
              <textarea
                value={editText}
                onChange={(e) => setEditText(e.target.value)}
                className="w-full flex-1 p-4 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 font-mono text-xs leading-relaxed"
                required
              />
            </div>

            <div className="flex gap-3 justify-end pt-2">
              <button
                type="button"
                onClick={() => setAdminTab('view')}
                className="px-4 py-2 rounded-xl font-bold text-xs text-slate-650 bg-slate-100 hover:bg-slate-200 transition-colors"
              >
                Cancelar
              </button>
              <button
                type="submit"
                disabled={savingEdit}
                className="px-4 py-2 rounded-xl font-black text-xs text-white bg-blue-600 hover:bg-blue-700 shadow-md shadow-blue-600/20 transition-all"
              >
                {savingEdit ? 'Guardando...' : 'Guardar Cambios'}
              </button>
            </div>
          </form>
        )}

        {/* Change Suggestions list (Admin review) */}
        {adminTab === 'suggestions' && isAdmin && (
          <div className="flex-1 flex flex-col text-left space-y-4">
            <div>
              <h3 className="text-lg font-black text-slate-900">Buzón de Sugerencias de Cambios</h3>
              <p className="text-xs text-slate-500 font-semibold">Revisa, compara y aprueba las propuestas de mejora sugeridas por tus colaboradores.</p>
            </div>

            {reviewingSuggestion ? (
              /* SUGGESTION DETAIL AND DIFF VIEW */
              <div className="flex-1 flex flex-col space-y-4">
                <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                  <div>
                    <h4 className="text-sm font-black text-slate-800">Comparación de Cambios</h4>
                    <p className="text-xs text-slate-500 font-semibold">
                      Sugerido por: <span className="font-bold text-slate-700">{reviewingSuggestion.user?.name || reviewingSuggestion.author_name}</span> para la nota <span className="text-blue-600 font-black">{reviewingSuggestion.document?.title}</span>
                    </p>
                  </div>
                  <button 
                    onClick={() => setReviewingSuggestion(null)}
                    className="p-1.5 rounded-full hover:bg-slate-100 text-slate-500"
                  >
                    <X size={18} />
                  </button>
                </div>

                {reviewingSuggestion.comment && (
                  <div className="bg-slate-50 border border-slate-200 rounded-2xl p-4">
                    <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-1">Comentario del Colaborador</span>
                    <p className="text-xs text-slate-700 font-medium italic">"{reviewingSuggestion.comment}"</p>
                  </div>
                )}

                {/* Diff Side by Side */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 flex-1 min-h-[250px]">
                  <div className="flex flex-col border border-slate-200 rounded-2xl overflow-hidden">
                    <div className="bg-rose-50 border-b border-rose-100 px-4 py-2 text-rose-700 text-xs font-black">Original</div>
                    <textarea 
                      value={reviewingSuggestion.original_content}
                      readOnly
                      className="w-full flex-1 p-3 font-mono text-[11px] leading-relaxed bg-rose-50/10 text-rose-800 focus:outline-none"
                    />
                  </div>
                  <div className="flex flex-col border border-slate-200 rounded-2xl overflow-hidden">
                    <div className="bg-emerald-50 border-b border-emerald-100 px-4 py-2 text-emerald-700 text-xs font-black">Propuesta del Empleado</div>
                    <textarea 
                      value={reviewingSuggestion.proposed_content}
                      readOnly
                      className="w-full flex-1 p-3 font-mono text-[11px] leading-relaxed bg-emerald-50/10 text-emerald-800 focus:outline-none"
                    />
                  </div>
                </div>

                {/* Action Form */}
                <div className="space-y-3 pt-2">
                  <div>
                    <label className="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-1">Nota de Revisión (Comentario para el empleado)</label>
                    <input
                      type="text"
                      value={reviewComment}
                      onChange={(e) => setReviewComment(e.target.value)}
                      placeholder="Ej. Aprobado, cambios integrados. / Rechazado, información desactualizada..."
                      className="w-full px-4 py-2.5 border border-slate-200 rounded-xl focus:outline-none focus:border-blue-500 text-xs font-semibold"
                    />
                  </div>

                  <div className="flex justify-end gap-3">
                    <button
                      onClick={() => handleProcessSuggestion(false)}
                      disabled={processingReview}
                      className="px-4 py-2.5 rounded-xl font-bold text-xs bg-rose-150 text-rose-700 hover:bg-rose-200 transition-colors flex items-center gap-1.5"
                    >
                      <X size={14} /> Rechazar Sugerencia
                    </button>
                    <button
                      onClick={() => handleProcessSuggestion(true)}
                      disabled={processingReview}
                      className="px-4 py-2.5 rounded-xl font-black text-xs bg-emerald-600 text-white hover:bg-emerald-700 shadow-md shadow-emerald-600/20 transition-all flex items-center gap-1.5"
                    >
                      <Check size={14} /> Aprobar e Integrar Cambios
                    </button>
                  </div>
                </div>
              </div>
            ) : suggestions.length === 0 ? (
              <div className="py-12 text-center text-slate-400 text-xs font-semibold">No hay propuestas de cambios en el buzón.</div>
            ) : (
              /* SUGGESTIONS LIST TABLE */
              <div className="overflow-x-auto border border-slate-150 rounded-2xl">
                <table className="w-full text-xs">
                  <thead className="bg-slate-50 text-slate-500 font-black uppercase border-b border-slate-150">
                    <tr>
                      <th className="px-4 py-3 text-left">Documento</th>
                      <th className="px-4 py-3 text-left">Colaborador</th>
                      <th className="px-4 py-3 text-left">Fecha</th>
                      <th className="px-4 py-3 text-left">Estado</th>
                      <th className="px-4 py-3 text-center">Acciones</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-150 font-medium text-slate-700">
                    {suggestions.map((s) => (
                      <tr key={s.id} className="hover:bg-slate-50/50 transition-colors">
                        <td className="px-4 py-3 text-left font-bold text-slate-900">{s.document?.title || 'Eliminado'}</td>
                        <td className="px-4 py-3 text-left">{s.user?.name || s.author_name}</td>
                        <td className="px-4 py-3 text-left">{new Date(s.created_at).toLocaleDateString()}</td>
                        <td className="px-4 py-3 text-left">
                          <span className={`px-2 py-0.5 rounded-full text-[10px] font-black uppercase ${
                            s.status === 'pending' ? 'bg-amber-100 text-amber-800' :
                            s.status === 'approved' ? 'bg-emerald-100 text-emerald-800' :
                            'bg-slate-200 text-slate-700'
                          }`}>
                            {s.status === 'pending' ? 'Pendiente' :
                             s.status === 'approved' ? 'Aprobada' : 'Rechazada'}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-center">
                          {s.status === 'pending' ? (
                            <button
                              onClick={() => setReviewingSuggestion(s)}
                              className="px-3 py-1 bg-slate-900 hover:bg-slate-800 text-white rounded-lg font-black text-[10px]"
                            >
                              Revisar
                            </button>
                          ) : (
                            <span className="text-[10px] text-slate-450 italic">Evaluado</span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}

      </div>
    </div>
  );
}
