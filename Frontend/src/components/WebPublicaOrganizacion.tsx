import React, { useState, useEffect, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { 
  Search, FileText, Briefcase, Repeat, CheckSquare, 
  ChevronRight, Eye, BookOpen, AlertCircle, Sparkles, Building2,
  ArrowLeft, Globe, Share2, Copy, Check
} from 'lucide-react';
import axiosInstance from '../lib/axios';

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

export function WebPublicaOrganizacion() {
  const { tenantSlug, docSlug } = useParams();
  const navigate = useNavigate();
  
  const [index, setIndex] = useState<DocIndex>({});
  const [searchQuery, setSearchQuery] = useState('');
  const [activeDoc, setActiveDoc] = useState<any>(null);
  const [links, setLinks] = useState<any[]>([]);
  const [backlinks, setBacklinks] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [copiedLink, setCopiedLink] = useState(false);
  
  const [tenant, setTenant] = useState<any>({
    name: 'Talent360',
    logo_url: '',
    brand_color: '#3b82f6'
  });
  const [vaultName, setVaultName] = useState('Estructura Organizacional');

  const contentRef = useRef<HTMLDivElement>(null);

  const fetchPublicVault = async (slugTarget = docSlug) => {
    setLoading(true);
    const url = slugTarget 
      ? `/public/org-vault/${tenantSlug}/${slugTarget}`
      : `/public/org-vault/${tenantSlug}`;
    try {
      const res = await axiosInstance.get(url);
      setTenant(res.data.tenant);
      setVaultName(res.data.vault_name);
      setIndex(res.data.index || {});
      setActiveDoc(res.data.document);
      setLinks(res.data.links || []);
      setBacklinks(res.data.backlinks || []);
    } catch (err) {
      console.error('Error fetching public vault:', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (tenantSlug) {
      fetchPublicVault(docSlug);
    }
  }, [tenantSlug, docSlug]);

  // Intercept WikiLink clicks in parsed HTML
  useEffect(() => {
    const handleWikiClick = (e: MouseEvent) => {
      const target = e.target as HTMLElement;
      if (target.classList.contains('wiki-link')) {
        e.preventDefault();
        const slug = target.getAttribute('data-target-slug');
        if (slug) {
          navigate(`/organizacion/${tenantSlug}/${slug}`);
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
  }, [activeDoc, tenantSlug]);

  const handleShare = () => {
    navigator.clipboard.writeText(window.location.href);
    setCopiedLink(true);
    setTimeout(() => setCopiedLink(false), 2000);
  };

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
          item.title.toLowerCase().includes(query)
        );
        if (filtered.length > 0) {
          result[cat] = filtered;
        }
      }
    });
    return result;
  };

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col font-sans select-text text-left">
      
      {/* Premium Header */}
      <header className="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-sm">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
          <div className="flex items-center gap-3.5 min-w-0">
            {tenant.logo_url ? (
              <div className="w-10 h-10 rounded-xl bg-white border border-slate-200 p-0.5 flex items-center justify-center shrink-0 shadow-sm">
                <img src={tenant.logo_url} alt="Logo" className="w-full h-full object-contain" />
              </div>
            ) : (
              <div className="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 shadow-sm text-white font-black text-xl" style={{ backgroundColor: tenant.brand_color }}>
                {tenant.name.charAt(0)}
              </div>
            )}
            <div className="flex flex-col text-left">
              <h1 className="text-base sm:text-lg font-black text-slate-900 tracking-tight leading-none mb-1">{tenant.name}</h1>
              <div className="flex items-center gap-1.5 text-slate-500">
                <Globe size={12} className="shrink-0" />
                <span className="text-[10px] font-bold uppercase tracking-wider">{vaultName}</span>
              </div>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <button
              onClick={handleShare}
              className="px-3.5 py-2 rounded-xl text-slate-650 bg-slate-100 hover:bg-slate-200 transition-colors flex items-center gap-2 text-xs font-bold shrink-0 shadow-sm border border-slate-200/50"
            >
              {copiedLink ? <Check size={14} className="text-emerald-600" /> : <Share2 size={14} />}
              {copiedLink ? 'Enlace Copiado' : 'Compartir'}
            </button>
            <a
              href="/"
              className="px-3.5 py-2 rounded-xl text-white bg-slate-900 hover:bg-slate-800 transition-colors flex items-center gap-2 text-xs font-bold shrink-0 shadow-sm"
            >
              <ArrowLeft size={14} /> Volver a Talent360
            </a>
          </div>
        </div>
      </header>

      {/* Main Body */}
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-1 flex flex-col lg:flex-row gap-6 min-w-0">
        
        {/* Sidebar Index */}
        <div className="w-full lg:w-80 bg-white border border-slate-200 rounded-3xl p-5 shadow-sm flex flex-col shrink-0">
          <div className="flex items-center justify-between mb-4">
            <h3 className="font-black text-slate-800 tracking-tight text-base">Índice del Portal</h3>
            <span className="text-[9px] font-black tracking-widest text-slate-400 bg-slate-100 border border-slate-200 px-1.5 py-0.5 rounded uppercase">Público</span>
          </div>

          {/* Search */}
          <div className="relative mb-5">
            <Search size={16} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
            <input 
              type="text" 
              placeholder="Buscar puesto o proceso..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-10 pr-4 py-2.5 rounded-2xl border border-slate-200 bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:border-blue-500 focus:bg-white text-xs font-semibold transition-all"
            />
          </div>

          {/* List index */}
          <div className="flex-1 overflow-y-auto pr-1 space-y-5 custom-scrollbar max-h-[300px] lg:max-h-none">
            {loading ? (
              <div className="text-center py-8 text-slate-400 text-xs font-semibold animate-pulse">Cargando índices...</div>
            ) : Object.keys(filteredIndex()).length === 0 ? (
              <div className="text-center py-8 text-slate-400 text-xs font-semibold">No hay coincidencia de búsqueda.</div>
            ) : (
              Object.entries(filteredIndex()).map(([category, items]) => (
                <div key={category} className="space-y-1.5">
                  <h4 className="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2 mb-1.5">
                    {getCategoryTitle(category)}
                  </h4>
                  {(items as DocIndexItem[]).map((item: DocIndexItem) => {
                    const isActive = activeDoc?.slug === item.slug;
                    return (
                      <button
                        key={item.id}
                        onClick={() => navigate(`/organizacion/${tenantSlug}/${item.slug}`)}
                        className={`w-full flex items-center gap-3 p-2.5 rounded-xl text-left transition-all ${
                          isActive 
                            ? 'bg-blue-50 text-blue-700 font-bold border-l-4 border-blue-600 shadow-sm' 
                            : 'text-slate-650 hover:bg-slate-50 hover:text-slate-900 border-l-4 border-transparent'
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
        </div>

        {/* Reading Pane */}
        <div className="flex-1 bg-white border border-slate-200 rounded-3xl p-6 sm:p-8 shadow-sm flex flex-col min-w-0">
          {loading ? (
            <div className="flex-1 flex items-center justify-center py-20 text-slate-400 font-semibold animate-pulse text-sm">
              Cargando documento...
            </div>
          ) : !activeDoc ? (
            <div className="flex-1 flex flex-col items-center justify-center py-20 text-slate-400 text-sm font-semibold text-center">
              <AlertCircle size={48} className="text-slate-300 mb-4" />
              <h3 className="text-lg font-black text-slate-700 mb-1">Documento no encontrado</h3>
              <p className="text-slate-500 max-w-sm text-xs leading-relaxed">
                El documento que buscas no existe o no se ha sincronizado correctamente.
              </p>
            </div>
          ) : (
            <div className="flex-1 flex flex-col lg:flex-row gap-6 min-w-0">
              {/* Central Text */}
              <div className="flex-1 min-w-0 flex flex-col">
                <div className="flex items-center gap-4 border-b border-slate-150 pb-4 mb-5">
                  <div className="w-14 h-14 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center shadow-inner shrink-0 [&>svg]:w-6 [&>svg]:h-6">
                    {getIcon(activeDoc.icon)}
                  </div>
                  <div className="flex flex-col">
                    <span className="text-[10px] font-black uppercase text-blue-600 tracking-widest leading-none mb-1.5">
                      {getCategoryTitle(activeDoc.type)}
                    </span>
                    <h1 className="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight leading-tight">{activeDoc.title}</h1>
                  </div>
                </div>

                {/* Rendered HTML */}
                <div 
                  ref={contentRef}
                  className="flex-1 text-slate-850 leading-relaxed pr-1 custom-markdown"
                  dangerouslySetInnerHTML={{ __html: activeDoc.content || '<p class="text-slate-400 italic">Esta sección está vacía.</p>' }}
                />

                <div className="mt-8 pt-4 border-t border-slate-100 text-[10px] text-slate-450 font-bold flex flex-col sm:flex-row justify-between items-center gap-2">
                  <span>© {tenant.name} - Estructura Organizativa Oficial</span>
                  <span>Última actualización: {new Date(activeDoc.updated_at).toLocaleDateString()}</span>
                </div>
              </div>

              {/* Side relations */}
              <div className="w-full lg:w-56 shrink-0 flex flex-col gap-5 text-left border-t lg:border-t-0 lg:border-l border-slate-150 pt-5 lg:pt-0 lg:pl-5">
                {/* Related Documents */}
                <div className="space-y-2">
                  <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest block">Temas Relacionados</span>
                  {links.length === 0 ? (
                    <span className="text-xs text-slate-400 font-medium italic block pl-1">Sin enlaces relacionados.</span>
                  ) : (
                    <div className="space-y-1">
                      {links.map(l => (
                        <button
                          key={l.id}
                          onClick={() => navigate(`/organizacion/${tenantSlug}/${l.slug}`)}
                          className="w-full flex items-center gap-2 p-1.5 rounded-lg hover:bg-slate-50 text-left transition-colors"
                        >
                          <div className="text-blue-600 shrink-0">{getIcon(l.icon)}</div>
                          <span className="text-xs font-bold text-blue-650 hover:underline truncate">{l.title}</span>
                        </button>
                      ))}
                    </div>
                  )}
                </div>

                {/* Backlinks */}
                <div className="space-y-2">
                  <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest block">Mencionado en</span>
                  {backlinks.length === 0 ? (
                    <span className="text-xs text-slate-400 font-medium italic block pl-1">Sin menciones.</span>
                  ) : (
                    <div className="space-y-1">
                      {backlinks.map(l => (
                        <button
                          key={l.id}
                          onClick={() => navigate(`/organizacion/${tenantSlug}/${l.slug}`)}
                          className="w-full flex items-center gap-2 p-1.5 rounded-lg hover:bg-slate-50 text-left transition-colors"
                        >
                          <div className="text-slate-500 shrink-0">{getIcon(l.icon)}</div>
                          <span className="text-xs font-bold text-slate-700 hover:underline truncate">{l.title}</span>
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>

      </div>

      {/* Public Footer */}
      <footer className="bg-white border-t border-slate-200 py-6 mt-auto">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-slate-450 text-xs font-bold">
          Estructura de Procesos y Puestos provista y auditada por <span className="text-blue-600">Talent 360</span>. Todos los derechos reservados.
        </div>
      </footer>

    </div>
  );
}
