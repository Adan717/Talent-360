import React, { useState, useEffect, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { 
  Search, FileText, Briefcase, Repeat, CheckSquare, 
  ChevronRight, Eye, EyeOff, BookOpen, AlertCircle, Globe, 
  Share2, Check, ArrowLeft, BookOpen as BookIcon, Menu, Building2,
  Volume2, VolumeX, Mic, Sparkles, X, Lock, Unlock, Key, MessageSquare,
  Clock, Trophy, ClipboardList, Settings, Paperclip
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
  [category: string]: DocIndexItem[] | undefined;
}

export function WebPublicaOrganizacion() {
  const { tenantSlug, docSlug } = useParams();
  const navigate = useNavigate();
  
  // User login & registration states
  const [loginEmail, setLoginEmail] = useState('');
  const [loginPassword, setLoginPassword] = useState('');
  const [registerName, setRegisterName] = useState('');
  const [registerEmail, setRegisterEmail] = useState('');
  const [registerPassword, setRegisterPassword] = useState('');
  const [registerJobRoleId, setRegisterJobRoleId] = useState('');
  const [isRegisterMode, setIsRegisterMode] = useState(false);
  const [availableJobRoles, setAvailableJobRoles] = useState<any[]>([]);
  const [index, setIndex] = useState<DocIndex>({});
  const [currentUser, setCurrentUser] = useState<any>(null);
  const [vaultToken, setVaultToken] = useState('');
  const [showPasscodeModal, setShowPasscodeModal] = useState(false);
  const [passcodeError, setPasscodeError] = useState('');
  const [verifyingPasscode, setVerifyingPasscode] = useState(false);
  const [hideOracleButton, setHideOracleButton] = useState(false);
  const [showMilestone50, setShowMilestone50] = useState(false);
  const [showMilestone95, setShowMilestone95] = useState(false);
  const [showPasswordText, setShowPasswordText] = useState(false);

  const passcode = vaultToken;
  const passcodeRole = currentUser?.role === 'admin' ? 'auditor' : (currentUser ? 'colaborador' : null) as 'auditor' | 'colaborador' | null;

  // Book & Search states
  const [isOpen, setIsOpen] = useState(false);
  const [mobileView, setMobileView] = useState<'index' | 'content'>('index');
  const [searchQuery, setSearchQuery] = useState('');
  const [activeDoc, setActiveDoc] = useState<any>(null);
  const [links, setLinks] = useState<any[]>([]);
  const [backlinks, setBacklinks] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [copiedLink, setCopiedLink] = useState(false);
  const [readDocSlugs, setReadDocSlugs] = useState<string[]>([]);
  
  // Suggestion states (Colaborador)
  const [isSuggesting, setIsSuggesting] = useState(false);
  const [proposedContent, setProposedContent] = useState('');
  const [suggestionComment, setSuggestionComment] = useState('');
  const [suggestionName, setSuggestionName] = useState('');
  const [submittingSuggestion, setSubmittingSuggestion] = useState(false);

  // Audit states (Auditor)
  const [activeBookTab, setActiveBookTab] = useState<'read' | 'audit'>('read');
  const [suggestionsList, setSuggestionsList] = useState<any[]>([]);
  const [loadingSuggestions, setLoadingSuggestions] = useState(false);
  const [activeSuggestion, setActiveSuggestion] = useState<any>(null);
  const [reviewComment, setReviewComment] = useState('');
  const [processingSuggestion, setProcessingSuggestion] = useState(false);

  // Narrator state
  const [isSpeaking, setIsSpeaking] = useState(false);

  // AI Voice Assistant states
  const [showAiAssistant, setShowAiAssistant] = useState(false);
  const [isListening, setIsListening] = useState(false);
  const [assistantStatus, setAssistantStatus] = useState('Listo');
  const [questionText, setQuestionText] = useState('');
  const [aiAnswer, setAiAnswer] = useState('');
  const [queryingAi, setQueryingAi] = useState(false);

  // Scribe (AI Contractor Document Scribe) states
  const [scribeActiveTab, setScribeActiveTab] = useState<'chat' | 'scribe'>('chat');
  const [candidateName, setCandidateName] = useState('');
  const [jobRoleSlug, setJobRoleSlug] = useState('');
  const [requestedDocs, setRequestedDocs] = useState<string[]>(['contract', 'tasks', 'rules', 'responsive']);
  const [scribeResultHtml, setScribeResultHtml] = useState('');
  const [generatingScribe, setGeneratingScribe] = useState(false);

  const [showWelcomeModal, setShowWelcomeModal] = useState(false);

  const [tenant, setTenant] = useState<any>({
    name: 'DecorArte 360',
    logo_url: '',
    brand_color: '#8b102e'
  });
  const [vaultName, setVaultName] = useState('La Receta Secreta');

  const [expandedCategories, setExpandedCategories] = useState<Record<string, boolean>>({});

  const toggleCategory = (category: string) => {
    setExpandedCategories(prev => ({
      ...prev,
      [category]: !prev[category]
    }));
  };

  // Auto-expansion disabled to keep all categories collapsed initially

  // Load read progress from localStorage
  useEffect(() => {
    if (!tenantSlug) return;
    try {
      const saved = localStorage.getItem(`vault_read_docs_${tenantSlug}`);
      if (saved) {
        setReadDocSlugs(JSON.parse(saved));
      }
    } catch (e) {
      console.error(e);
    }
  }, [tenantSlug]);

  // Track and save read progress when document is opened
  useEffect(() => {
    if (activeDoc?.id && tenantSlug) {
      let nextSlugs: string[] = [];
      setReadDocSlugs(prev => {
        if (prev.includes(activeDoc.slug)) {
          nextSlugs = prev;
          return prev;
        }
        nextSlugs = [...prev, activeDoc.slug];
        localStorage.setItem(`vault_read_docs_${tenantSlug}`, JSON.stringify(nextSlugs));
        return nextSlugs;
      });

      // Post progress to backend if manual user is logged in
      const token = sessionStorage.getItem(`vault_token_${tenantSlug}`);
      if (token) {
        axiosInstance.post(`/public/org-vault/${tenantSlug}/progress`, 
          { document_id: activeDoc.id },
          { headers: { Authorization: `Bearer ${token}` } }
        ).catch(err => console.error('Error recording read progress:', err));
      }

      // Check milestones
      setTimeout(() => {
        const totalDocsCount = Object.values(index).flat().length;
        if (totalDocsCount > 0 && nextSlugs.length > 0) {
          const pct = Math.round((nextSlugs.length / totalDocsCount) * 100);
          const shown50 = sessionStorage.getItem(`milestone_50_${tenantSlug}`);
          const shown95 = sessionStorage.getItem(`milestone_95_${tenantSlug}`);
          
          if (pct >= 50 && pct < 95 && !shown50) {
            setShowMilestone50(true);
            sessionStorage.setItem(`milestone_50_${tenantSlug}`, 'true');
          } else if (pct >= 95 && !shown95) {
            setShowMilestone95(true);
            sessionStorage.setItem(`milestone_95_${tenantSlug}`, 'true');
          }
        }
      }, 500);
    }
  }, [activeDoc, tenantSlug, index]);

  const contentRef = useRef<HTMLDivElement>(null);
  const recognitionRef = useRef<any>(null);

  // Auto-open book if user is saved in sessionStorage
  useEffect(() => {
    const savedUser = sessionStorage.getItem(`vault_user_${tenantSlug}`);
    const savedToken = sessionStorage.getItem(`vault_token_${tenantSlug}`);
    if (savedUser && savedToken) {
      try {
        setCurrentUser(JSON.parse(savedUser));
        setVaultToken(savedToken);
        setIsOpen(true);
        if (docSlug) {
          setMobileView('content');
        }
      } catch (e) {
        console.error('Error parsing saved vault user:', e);
      }
    }
  }, [docSlug, tenantSlug]);

  const fetchPublicVault = async (slugTarget = docSlug) => {
    setLoading(true);
    const token = sessionStorage.getItem(`vault_token_${tenantSlug}`) || '';
    const url = slugTarget 
      ? `/public/org-vault/${tenantSlug}/${slugTarget}`
      : `/public/org-vault/${tenantSlug}`;
    try {
      const res = await axiosInstance.get(url, {
        headers: token ? { Authorization: `Bearer ${token}` } : {}
      });
      setTenant(res.data.tenant);
      setVaultName(res.data.vault_name || 'La Receta Secreta');
      setHideOracleButton(res.data.hide_oracle_button || false);
      setIndex(res.data.index || {});
      setActiveDoc(res.data.document);
      setLinks(res.data.links || []);
      setBacklinks(res.data.backlinks || []);
      setAvailableJobRoles(res.data.job_roles || []);
      if (res.data.read_doc_slugs && res.data.read_doc_slugs.length > 0) {
        setReadDocSlugs(res.data.read_doc_slugs);
      }
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
          setMobileView('content');
          setIsSuggesting(false);
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
  }, [navigate, tenantSlug]);

  // Fetch pending suggestions for auditor
  const fetchSuggestions = async () => {
    setLoadingSuggestions(true);
    try {
      const res = await axiosInstance.post(`/public/org-vault/${tenantSlug}/suggestions`, {
        passcode: passcode
      });
      setSuggestionsList(res.data || []);
    } catch (err) {
      console.error('Error fetching suggestions:', err);
    } finally {
      setLoadingSuggestions(false);
    }
  };

  useEffect(() => {
    if (activeBookTab === 'audit' && passcodeRole === 'auditor') {
      fetchSuggestions();
    }
  }, [activeBookTab]);

  // Speech synthesis stop on unmount
  useEffect(() => {
    return () => {
      if ('speechSynthesis' in window) {
        window.speechSynthesis.cancel();
      }
    };
  }, []);

  const handleShare = () => {
    navigator.clipboard.writeText(window.location.href);
    setCopiedLink(true);
    setTimeout(() => setCopiedLink(false), 2000);
  };

  const getIcon = (iconName: string) => {
    switch (iconName) {
      case 'briefcase': return <Briefcase size={16} />;
      case 'repeat': return <Repeat size={16} />;
      case 'check-square': return <CheckSquare size={16} />;
      case 'clock': return <Clock size={16} />;
      case 'trophy': return <Trophy size={16} />;
      case 'clipboard-list': return <ClipboardList size={16} />;
      case 'settings': return <Settings size={16} />;
      case 'book-open': return <BookOpen size={16} />;
      case 'building-2': return <Building2 size={16} />;
      case 'paperclip': return <Paperclip size={16} />;
      default: return <FileText size={16} />;
    }
  };

  const getCategoryTitle = (cat: string) => {
    switch (cat) {
      case 'organizacion': return 'Organización y Empresa';
      case 'puesto': return 'Puestos y Jerarquías';
      case 'proceso': return 'Procesos y Procedimientos';
      case 'tarea': return 'Tareas y Checklists';
      case 'rutina': return 'Rutinas y Operaciones';
      case 'indicador': return 'Indicadores de Desempeño (KPIs)';
      case 'reglas': return 'Reglas y Sanciones';
      case 'formatos': return 'Formatos y Documentos';
      case 'anexo': return 'Anexos';
      case 'glosario': return 'Glosario de Términos';
      case 'nota': return 'Notas y Bitácoras';
      default: return cat.charAt(0).toUpperCase() + cat.slice(1);
    }
  };

  const categoryOrder = [
    'organizacion',
    'puesto',
    'proceso',
    'tarea',
    'rutina',
    'indicador',
    'reglas',
    'formatos',
    'anexo',
    'glosario',
    'nota'
  ];

  interface Subgroup {
    title: string;
    items: DocIndexItem[];
  }

  const getCategorySubgroups = (category: string, items: DocIndexItem[]): Subgroup[] => {
    if (category === 'tarea') {
      const checklists = items.filter(item => 
        item.title.toLowerCase().includes('checklist') || 
        item.title.toLowerCase().includes('lista') ||
        item.slug.toLowerCase().includes('checklist')
      );
      const tareas = items.filter(item => !checklists.includes(item));
      
      const groups: Subgroup[] = [];
      if (checklists.length > 0) groups.push({ title: 'Checklists de Control', items: checklists });
      if (tareas.length > 0) groups.push({ title: 'Tareas Operativas', items: tareas });
      return groups;
    }

    if (category === 'puesto') {
      const directivos = items.filter(item => 
        item.title.toLowerCase().includes('administrador') || 
        item.title.toLowerCase().includes('gerente') ||
        item.title.toLowerCase().includes('supervisor') ||
        item.title.toLowerCase().includes('coordinador')
      );
      const operativos = items.filter(item => !directivos.includes(item));

      const groups: Subgroup[] = [];
      if (directivos.length > 0) groups.push({ title: 'Directivos y Gerencia', items: directivos });
      if (operativos.length > 0) groups.push({ title: 'Puestos Operativos', items: operativos });
      return groups;
    }

    if (category === 'anexo') {
      const puesto = items.filter(item => item.title.toLowerCase().includes('puesto'));
      const otros = items.filter(item => !puesto.includes(item));

      const groups: Subgroup[] = [];
      if (puesto.length > 0) groups.push({ title: 'Anexos de Puestos', items: puesto });
      if (otros.length > 0) groups.push({ title: 'Anexos Complementarios', items: otros });
      return groups;
    }

    if (category === 'organizacion') {
      const filosofia = items.filter(item => 
        item.title.toLowerCase().includes('historia') || 
        item.title.toLowerCase().includes('filosofia') || 
        item.title.toLowerCase().includes('carta') || 
        item.title.toLowerCase().includes('mision') || 
        item.title.toLowerCase().includes('vision') || 
        item.title.toLowerCase().includes('valores') || 
        item.title.toLowerCase().includes('principios')
      );
      const sistemas = items.filter(item => !filosofia.includes(item));

      const groups: Subgroup[] = [];
      if (filosofia.length > 0) groups.push({ title: 'Filosofía e Historia', items: filosofia });
      if (sistemas.length > 0) groups.push({ title: 'Sistemas e Inducción', items: sistemas });
      return groups;
    }

    if (category === 'formatos') {
      const contratos = items.filter(item => 
        item.title.toLowerCase().includes('contrato') || 
        item.title.toLowerCase().includes('convenio') || 
        item.title.toLowerCase().includes('acuerdo')
      );
      const cartas = items.filter(item => 
        item.title.toLowerCase().includes('carta') || 
        item.title.toLowerCase().includes('acta') ||
        item.title.toLowerCase().includes('responsiva')
      );
      const checklistDoc = items.filter(item => 
        item.title.toLowerCase().includes('checklist') ||
        item.title.toLowerCase().includes('expediente')
      );
      const otros = items.filter(item => !contratos.includes(item) && !cartas.includes(item) && !checklistDoc.includes(item));

      const groups: Subgroup[] = [];
      if (contratos.length > 0) groups.push({ title: 'Contratos y Convenios', items: contratos });
      if (cartas.length > 0) groups.push({ title: 'Cartas y Actas Responsivas', items: cartas });
      if (checklistDoc.length > 0) groups.push({ title: 'Checklists de Ingreso', items: checklistDoc });
      if (otros.length > 0) groups.push({ title: 'Formatos Operativos', items: otros });
      return groups;
    }

    return [{ title: getCategoryTitle(category), items }];
  };

  const filteredIndex = () => {
    const query = searchQuery.toLowerCase();
    const result: DocIndex = {};
    const allCategories = Object.keys(index);
    
    allCategories.forEach(cat => {
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

  const sortedFilteredIndexEntries = () => {
    const fIndex = filteredIndex();
    return Object.entries(fIndex).sort(([catA], [catB]) => {
      let idxA = categoryOrder.indexOf(catA);
      let idxB = categoryOrder.indexOf(catB);
      if (idxA === -1) idxA = 999;
      if (idxB === -1) idxB = 999;
      return idxA - idxB;
    });
  };

  // User verification
  const handleVerifyPasscode = async (e: React.FormEvent) => {
    e.preventDefault();
    setVerifyingPasscode(true);
    setPasscodeError('');
    try {
      const res = await axiosInstance.post(`/public/org-vault/${tenantSlug}/login`, {
        email: loginEmail,
        password: loginPassword
      });
      if (res.data.valid) {
        setCurrentUser(res.data.user);
        setVaultToken(res.data.token);
        sessionStorage.setItem(`vault_user_${tenantSlug}`, JSON.stringify(res.data.user));
        sessionStorage.setItem(`vault_token_${tenantSlug}`, res.data.token);
        setShowPasscodeModal(false);
        setIsOpen(true);
        setShowWelcomeModal(true);
        // Refresh vault info with token now active
        setTimeout(() => fetchPublicVault(docSlug), 100);
      }
    } catch (err: any) {
      setPasscodeError(err.response?.data?.error || 'Usuario o contraseña incorrectos.');
    } finally {
      setVerifyingPasscode(false);
    }
  };

  const handleRegisterUser = async (e: React.FormEvent) => {
    e.preventDefault();
    setVerifyingPasscode(true);
    setPasscodeError('');
    try {
      const res = await axiosInstance.post(`/public/org-vault/${tenantSlug}/register`, {
        name: registerName,
        email: registerEmail,
        password: registerPassword,
        job_role_id: registerJobRoleId ? parseInt(registerJobRoleId) : null
      });
      if (res.data.valid) {
        setCurrentUser(res.data.user);
        setVaultToken(res.data.token);
        sessionStorage.setItem(`vault_user_${tenantSlug}`, JSON.stringify(res.data.user));
        sessionStorage.setItem(`vault_token_${tenantSlug}`, res.data.token);
        setShowPasscodeModal(false);
        setIsOpen(true);
        setShowWelcomeModal(true);
        // Refresh vault info with token now active
        setTimeout(() => fetchPublicVault(docSlug), 100);
      }
    } catch (err: any) {
      setPasscodeError(err.response?.data?.error || 'Error al registrarse. Por favor verifica tus datos.');
    } finally {
      setVerifyingPasscode(false);
    }
  };

  const handleLogout = () => {
    sessionStorage.removeItem(`vault_user_${tenantSlug}`);
    sessionStorage.removeItem(`vault_token_${tenantSlug}`);
    setCurrentUser(null);
    setVaultToken('');
    setIsOpen(false);
    setActiveBookTab('read');
    if ('speechSynthesis' in window) {
      window.speechSynthesis.cancel();
    }
  };

  const renderCornerOrnament = (rotateClass: string) => (
    <svg className={`absolute w-10 h-10 text-[#d4af37]/65 pointer-events-none z-20 ${rotateClass}`} viewBox="0 0 100 100" fill="none" stroke="currentColor" strokeWidth="3">
      <path d="M 12 12 L 65 12" strokeLinecap="round" />
      <path d="M 12 12 L 12 65" strokeLinecap="round" />
      <path d="M 22 22 C 22 35, 35 22, 35 35" strokeLinecap="round" />
      <path d="M 12 28 C 20 28, 20 20, 28 20" strokeLinecap="round" />
      <path d="M 28 12 C 28 20, 20 20, 20 28" strokeLinecap="round" />
      <rect x="10" y="10" width="4" height="4" fill="currentColor" />
    </svg>
  );

  // Submit suggestion
  const handleSubmitSuggestion = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!suggestionName.trim() || !proposedContent.trim() || !suggestionComment.trim()) {
      alert('Por favor rellena todos los campos.');
      return;
    }
    setSubmittingSuggestion(true);
    try {
      await axiosInstance.post(`/public/org-vault/${tenantSlug}/suggestions/create`, {
        passcode: passcode,
        document_id: activeDoc.id,
        proposed_content: proposedContent,
        comment: suggestionComment,
        user_name: suggestionName
      });
      alert('Propuesta de mejora enviada con éxito.');
      setIsSuggesting(false);
      setProposedContent('');
      setSuggestionComment('');
    } catch (err) {
      console.error(err);
      alert('Error al enviar la sugerencia.');
    } finally {
      setSubmittingSuggestion(false);
    }
  };

  // Approve suggestion
  const handleApproveSuggestion = async (id: number) => {
    if (!window.confirm('¿Seguro que deseas APROBAR e incorporar este cambio al manual de inmediato?')) return;
    setProcessingSuggestion(true);
    try {
      const res = await axiosInstance.post(`/public/org-vault/${tenantSlug}/suggestions/${id}/approve`, {
        passcode: passcode,
        review_comment: reviewComment
      });
      alert(res.data.message || 'Propuesta aprobada.');
      setReviewComment('');
      setActiveSuggestion(null);
      fetchSuggestions();
      // Re-fetch active document
      if (activeDoc) {
        fetchPublicVault(activeDoc.slug);
      }
    } catch (err) {
      console.error(err);
      alert('Error al aprobar.');
    } finally {
      setProcessingSuggestion(false);
    }
  };

  // Reject suggestion
  const handleRejectSuggestion = async (id: number) => {
    if (!window.confirm('¿Seguro que deseas RECHAZAR esta sugerencia?')) return;
    setProcessingSuggestion(true);
    try {
      const res = await axiosInstance.post(`/public/org-vault/${tenantSlug}/suggestions/${id}/reject`, {
        passcode: passcode,
        review_comment: reviewComment
      });
      alert(res.data.message || 'Propuesta rechazada.');
      setReviewComment('');
      setActiveSuggestion(null);
      fetchSuggestions();
    } catch (err) {
      console.error(err);
      alert('Error al rechazar.');
    } finally {
      setProcessingSuggestion(false);
    }
  };

  // Text to Speech Narrator
  const speakText = (htmlContent: string) => {
    if ('speechSynthesis' in window) {
      if (isSpeaking) {
        window.speechSynthesis.cancel();
        setIsSpeaking(false);
        return;
      }

      const tempDiv = document.createElement("div");
      tempDiv.innerHTML = htmlContent;
      const plainText = tempDiv.textContent || tempDiv.innerText || "";

      const utterance = new SpeechSynthesisUtterance(plainText);
      utterance.lang = 'es-MX'; // Mexican Spanish
      utterance.rate = 1.0;
      
      utterance.onend = () => {
        setIsSpeaking(false);
      };
      utterance.onerror = () => {
        setIsSpeaking(false);
      };

      setIsSpeaking(true);
      window.speechSynthesis.speak(utterance);
    } else {
      alert("La narración de voz no es soportada en este navegador.");
    }
  };

  // Voice Recognition AI Assistant
  const startListening = () => {
    const SpeechRecognition = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
    if (!SpeechRecognition) {
      alert("El reconocimiento de voz (Speech Recognition) no está soportado en este navegador. Te recomendamos usar Google Chrome.");
      return;
    }

    if ('speechSynthesis' in window) {
      window.speechSynthesis.cancel();
    }

    const rec = new SpeechRecognition();
    recognitionRef.current = rec;
    rec.lang = 'es-MX';
    rec.interimResults = false;
    rec.maxAlternatives = 1;

    rec.onstart = () => {
      setIsListening(true);
      setAssistantStatus('Escuchando tu pregunta...');
      setQuestionText('');
      setAiAnswer('');
    };

    rec.onspeechend = () => {
      rec.stop();
    };

    rec.onresult = async (event: any) => {
      const speechToText = event.results[0][0].transcript;
      setQuestionText(speechToText);
      setIsListening(false);
      setAssistantStatus('Pensando...');
      await askCopilot(speechToText);
    };

    rec.onerror = (event: any) => {
      console.error('Speech recognition error:', event.error);
      setIsListening(false);
      setAssistantStatus('Error al escuchar. Haz clic para reintentar.');
    };

    rec.start();
  };

  const stopListening = () => {
    if (recognitionRef.current) {
      recognitionRef.current.stop();
      setIsListening(false);
      setAssistantStatus('Listo');
    }
  };

  const askCopilot = async (question: string) => {
    setQueryingAi(true);
    try {
      const res = await axiosInstance.post(`/public/org-vault/${tenantSlug}/copilot`, {
        question: question
      });
      const answer = res.data.answer || 'No obtuve respuesta de la IA.';
      setAiAnswer(answer);
      setAssistantStatus('Hablando...');
      speakResponse(answer);
    } catch (err: any) {
      console.error('Error asking copilot:', err);
      const errMsg = 'Error al consultar el Oráculo. Intenta de nuevo.';
      setAiAnswer(errMsg);
      setAssistantStatus('Error');
      speakResponse(errMsg);
    } finally {
      setQueryingAi(false);
    }
  };

  const speakResponse = (text: string) => {
    if ('speechSynthesis' in window) {
      window.speechSynthesis.cancel();
      const utterance = new SpeechSynthesisUtterance(text);
      utterance.lang = 'es-MX';
      utterance.onend = () => {
        setAssistantStatus('Listo');
      };
      utterance.onerror = () => {
        setAssistantStatus('Listo');
      };
      window.speechSynthesis.speak(utterance);
    }
  };

  const handleGenerateOnboardingDocs = async () => {
    setGeneratingScribe(true);
    setScribeResultHtml('');
    try {
      const res = await axiosInstance.post(`/public/org-vault/${tenantSlug}/scribe`, {
        passcode: passcode,
        candidate_name: candidateName,
        job_role_slug: jobRoleSlug,
        documents: requestedDocs
      });
      setScribeResultHtml(res.data.html || '<p>Error al generar documentos.</p>');
    } catch (err: any) {
      console.error(err);
      alert(err.response?.data?.error || 'Error al generar la documentación.');
    } finally {
      setGeneratingScribe(false);
    }
  };

  const handlePrintScribeDocs = () => {
    const printWindow = window.open('', '_blank');
    if (printWindow) {
      printWindow.document.write(`
        <html>
          <head>
            <title>Kit de Contratación - ${candidateName}</title>
            <style>
              body {
                font-family: Georgia, serif;
                padding: 40px;
                color: #1a1a1a;
                line-height: 1.6;
              }
              h1, h2, h3 {
                color: #4a0717;
                font-family: 'Playfair Display', serif;
              }
              hr {
                page-break-after: always;
                border: none;
                margin: 40px 0;
              }
              p, li {
                font-size: 14px;
                text-align: justify;
              }
            </style>
          </head>
          <body>
            ${scribeResultHtml}
          </body>
        </html>
      `);
      printWindow.document.close();
      printWindow.print();
    }
  };

  // Golden Corners SVG Component
  const GoldenCorners = () => (
    <div className="absolute inset-0 pointer-events-none p-3.5">
      {/* Top Left */}
      <svg className="absolute top-2 left-2 w-8 h-8 text-[#d4af37] opacity-80" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
        <path d="M2 10V2h8M4 6V4h2" strokeLinecap="round" strokeLinejoin="round"/>
      </svg>
      {/* Top Right */}
      <svg className="absolute top-2 right-2 w-8 h-8 text-[#d4af37] opacity-80" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
        <path d="M22 10V2h-8M20 6V4h-2" strokeLinecap="round" strokeLinejoin="round"/>
      </svg>
      {/* Bottom Left */}
      <svg className="absolute bottom-2 left-2 w-8 h-8 text-[#d4af37] opacity-80" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
        <path d="M2 14v8h8M4 18v2h2" strokeLinecap="round" strokeLinejoin="round"/>
      </svg>
      {/* Bottom Right */}
      <svg className="absolute bottom-2 right-2 w-8 h-8 text-[#d4af37] opacity-80" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
        <path d="M22 14v8h-8M20 18v2" strokeLinecap="round"/>
      </svg>
    </div>
  );

  return (
    <div className={`min-h-screen w-full flex flex-col justify-center items-center ${isOpen ? 'p-0 sm:p-6 md:p-8' : 'p-3 sm:p-6 md:p-8'} select-text font-serif relative overflow-hidden`} 
      style={{
        background: 'radial-gradient(circle, #22080f 0%, #0d0104 100%)', // Deep dark burgundy ambient gradient
      }}
    >
      {/* Custom Styles Injection */}
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Playfair+Display:ital,wght@0,700;1,700&family=Cinzel:wght@700&family=MedievalSharp&family=Pinyon+Script&display=swap');

        @keyframes fadeIn {
          from { opacity: 0; transform: translateY(-4px); }
          to { opacity: 1; transform: translateY(0); }
        }

        /* Parchment vintage look matching the beige-cream logo background */
        .parchment {
          background-color: #f6ecda;
          background-image: radial-gradient(circle, rgba(255,255,255,0.4) 0%, rgba(0,0,0,0.03) 100%);
          box-shadow: inset 0 0 30px rgba(80,50,30,0.08);
          border: 1px solid #e2d3bb;
        }
        
        .gold-metal-text {
          background: linear-gradient(to right, #bf953f 0%, #fcf6ba 25%, #b38728 50%, #fbf5b7 75%, #aa771c 100%);
          -webkit-background-clip: text;
          -webkit-text-fill-color: transparent;
          filter: drop-shadow(0 2px 4px rgba(0,0,0,0.5));
        }

        .gold-border {
          border-color: #cda83d;
        }

        /* Customize markdown styling to fit parchment book style */
        .custom-markdown p {
          font-family: Georgia, serif;
          font-size: 14px;
          line-height: 1.7;
          color: #2b251f;
          margin-bottom: 12px;
          text-align: justify;
        }

        .custom-markdown h1,
        .custom-markdown h2,
        .custom-markdown h3 {
          font-family: 'Playfair Display', Georgia, serif;
          color: #4a0717; /* Wine red heading matching logo circle */
          font-weight: 900;
          margin-top: 20px;
          margin-bottom: 8px;
          letter-spacing: -0.01em;
        }

        .custom-markdown h1 { font-size: 22px; border-bottom: 1px solid #d2c7ac; padding-bottom: 4px; }
        .custom-markdown h2 { font-size: 18px; border-bottom: 1px dashed #d2c7ac; padding-bottom: 4px; }
        .custom-markdown h3 { font-size: 15px; }

        .custom-markdown li {
          font-family: Georgia, serif;
          font-size: 13.5px;
          color: #2b251f;
          margin-left: 16px;
          margin-bottom: 4px;
        }

        .custom-markdown blockquote {
          border-left: 3px solid #b38728;
          padding-left: 12px;
          margin: 16px 0;
          color: #4a3e35;
          font-style: italic;
          background-color: rgba(179,135,40,0.04);
        }

        .custom-markdown code {
          font-family: monospace;
          background-color: rgba(0,0,0,0.05);
          color: #922b21;
          padding: 1px 4px;
          border-radius: 4px;
          font-size: 11px;
        }

        /* Center fold line effect */
        .book-spine-line::after {
          content: '';
          position: absolute;
          top: 0;
          bottom: 0;
          left: 0;
          width: 25px;
          background: linear-gradient(to right, rgba(0,0,0,0.18) 0%, rgba(0,0,0,0.06) 30%, rgba(0,0,0,0) 100%);
          pointer-events: none;
        }

        .book-spine-line-right::before {
          content: '';
          position: absolute;
          top: 0;
          bottom: 0;
          right: 0;
          width: 25px;
          background: linear-gradient(to left, rgba(0,0,0,0.18) 0%, rgba(0,0,0,0.06) 30%, rgba(0,0,0,0) 100%);
          pointer-events: none;
        }

        /* 3D cover tilt effect */
        .book-cover-3d {
          transform: perspective(1000px) rotateY(-8deg) rotateX(2deg);
          box-shadow: 15px 15px 35px rgba(0,0,0,0.75), -2px 0 5px rgba(255,255,255,0.05);
          transition: transform 0.4s cubic-bezier(0.165, 0.84, 0.44, 1), box-shadow 0.4s ease;
        }
        
        .book-cover-3d:hover {
          transform: perspective(1000px) rotateY(-1deg) rotateX(0deg) scale(1.025);
          box-shadow: 25px 25px 45px rgba(0,0,0,0.85);
        }
      `}</style>

      {/* Background ambient particles/decorations */}
      <div className="absolute top-10 left-10 w-96 h-96 bg-[#8b102e]/5 rounded-full blur-[100px] pointer-events-none"></div>
      <div className="absolute bottom-10 right-10 w-96 h-96 bg-[#bf953f]/5 rounded-full blur-[100px] pointer-events-none"></div>

      {/* 🛡️ USER LOGIN / REGISTRATION DIALOG MODAL */}
      {showPasscodeModal && (
        <div className="fixed inset-0 bg-black/80 backdrop-blur-md z-50 flex items-center justify-center p-4">
          <form 
            onSubmit={isRegisterMode ? handleRegisterUser : handleVerifyPasscode}
            className="bg-gradient-to-br from-[#f6ecda] via-[#faf6eb] to-[#eedfb7] w-full max-w-sm rounded-3xl p-6 relative border-4 border-[#b38728] shadow-[0_15px_45px_rgba(0,0,0,0.85)] flex flex-col text-left space-y-4 max-h-[90vh] overflow-y-auto"
          >
            {/* Double thin border inside the form */}
            <div className="absolute inset-1.5 border border-dashed border-[#b38728]/35 rounded-[22px] pointer-events-none"></div>
            
            <GoldenCorners />
            <button 
              type="button"
              onClick={() => setShowPasscodeModal(false)}
              className="absolute top-4 right-4 p-1.5 rounded-full hover:bg-[#3d1b13]/10 text-[#3d1b13] z-20 cursor-pointer"
            >
              <X size={16} />
            </button>

            <div className="flex flex-col items-center text-center mt-3 relative z-10">
              <div className="w-12 h-12 rounded-full bg-gradient-to-br from-[#bf953f] via-[#fcf6ba] to-[#aa771c] border-2 border-[#8b102e]/30 flex items-center justify-center text-[#4a0717] mb-2 shadow-md drop-shadow-[0_2px_4px_rgba(0,0,0,0.2)] animate-bounce-subtle">
                <Lock size={20} className="text-[#4a0717]" />
              </div>
              <h3 className="font-serif text-lg font-black text-[#4a0717] tracking-wide">
                {isRegisterMode ? 'Registrar Lector' : 'Acceso Resguardado'}
              </h3>
              <p className="text-[9px] font-sans text-slate-500 font-bold uppercase tracking-[0.2em] mt-0.5">
                {isRegisterMode ? 'Crea tu cuenta de capacitación' : 'Ingresa tus credenciales de manual'}
              </p>
            </div>

            <div className="space-y-3 relative z-10">
              {isRegisterMode ? (
                <>
                  <div>
                    <label className="text-[9.5px] font-black text-[#8b102e] uppercase tracking-widest block mb-1 font-sans">Nombre Completo</label>
                    <input 
                      type="text"
                      required
                      placeholder="Ej. Juan Pérez"
                      value={registerName}
                      onChange={(e) => setRegisterName(e.target.value)}
                      className="w-full px-3 py-2.5 rounded-xl border border-[#d2c7ac] bg-[#faf6eb] text-[#2b251f] placeholder-slate-450 focus:outline-none focus:border-[#8b102e] font-serif text-xs shadow-inner"
                    />
                  </div>

                  <div>
                    <label className="text-[9.5px] font-black text-[#8b102e] uppercase tracking-widest block mb-1 font-sans">Correo o Usuario</label>
                    <input 
                      type="text"
                      required
                      placeholder="juan.perez o juan@empresa.com"
                      value={registerEmail}
                      onChange={(e) => setRegisterEmail(e.target.value)}
                      className="w-full px-3 py-2.5 rounded-xl border border-[#d2c7ac] bg-[#faf6eb] text-[#2b251f] placeholder-slate-455 focus:outline-none focus:border-[#8b102e] font-serif text-xs shadow-inner"
                    />
                  </div>

                  <div>
                    <label className="text-[9.5px] font-black text-[#8b102e] uppercase tracking-widest block mb-1 font-sans">Contraseña</label>
                    <div className="relative">
                      <input 
                        type={showPasswordText ? 'text' : 'password'}
                        required
                        placeholder="Mínimo 4 caracteres"
                        value={registerPassword}
                        onChange={(e) => setRegisterPassword(e.target.value)}
                        className="w-full pl-3 pr-10 py-2.5 rounded-xl border border-[#d2c7ac] bg-[#faf6eb] text-[#2b251f] placeholder-slate-455 focus:outline-none focus:border-[#8b102e] font-serif text-xs shadow-inner"
                      />
                      <button
                        type="button"
                        onClick={() => setShowPasswordText(!showPasswordText)}
                        className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-[#8b102e]"
                      >
                        {showPasswordText ? <EyeOff size={15} /> : <Eye size={15} />}
                      </button>
                    </div>
                  </div>

                  <div>
                    <label className="text-[9.5px] font-black text-[#8b102e] uppercase tracking-widest block mb-1 font-sans">Puesto en la Empresa</label>
                    <select
                      required
                      value={registerJobRoleId}
                      onChange={(e) => setRegisterJobRoleId(e.target.value)}
                      className="w-full px-3 py-2.5 rounded-xl border border-[#d2c7ac] bg-[#faf6eb] text-[#2b251f] focus:outline-none focus:border-[#8b102e] font-serif text-xs shadow-inner"
                    >
                      <option value="">-- Selecciona tu Puesto --</option>
                      {availableJobRoles.map((role) => (
                        <option key={role.id} value={role.id}>{role.name}</option>
                      ))}
                    </select>
                  </div>
                </>
              ) : (
                <>
                  <div>
                    <label className="text-[9.5px] font-black text-[#8b102e] uppercase tracking-widest block mb-1 font-sans">Usuario / Correo</label>
                    <input 
                      type="text"
                      required
                      placeholder="ejemplo@decorarte.com"
                      value={loginEmail}
                      onChange={(e) => setLoginEmail(e.target.value)}
                      className="w-full px-3.5 py-2.5 rounded-xl border border-[#d2c7ac] bg-[#faf6eb] text-[#2b251f] placeholder-slate-450 focus:outline-none focus:border-[#8b102e] font-serif text-sm shadow-inner"
                    />
                  </div>

                  <div>
                    <label className="text-[9.5px] font-black text-[#8b102e] uppercase tracking-widest block mb-1 font-sans">Contraseña</label>
                    <div className="relative">
                      <input 
                        type={showPasswordText ? 'text' : 'password'}
                        required
                        placeholder="••••••••"
                        value={loginPassword}
                        onChange={(e) => setLoginPassword(e.target.value)}
                        className="w-full pl-3.5 pr-10 py-2.5 rounded-xl border border-[#d2c7ac] bg-[#faf6eb] text-[#2b251f] placeholder-slate-450 focus:outline-none focus:border-[#8b102e] font-serif text-sm shadow-inner"
                      />
                      <button
                        type="button"
                        onClick={() => setShowPasswordText(!showPasswordText)}
                        className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-[#8b102e]"
                      >
                        {showPasswordText ? <EyeOff size={15} /> : <Eye size={15} />}
                      </button>
                    </div>
                  </div>
                </>
              )}

              {passcodeError && (
                <div className="p-2.5 bg-rose-50 border border-rose-150 text-rose-700 text-[10px] font-bold rounded-lg flex items-center gap-1.5 font-sans">
                  <AlertCircle size={12} />
                  <span>{passcodeError}</span>
                </div>
              )}

              <button
                type="submit"
                disabled={verifyingPasscode}
                className="w-full py-2.5 rounded-xl bg-gradient-to-b from-[#8b102e] to-[#60051a] hover:from-[#9c1437] hover:to-[#70071f] text-[#faf6eb] font-black font-sans text-xs tracking-wider uppercase shadow-md hover:shadow-lg transition-all disabled:opacity-50 border border-[#d4af37]/35 cursor-pointer transform active:scale-[0.98]"
              >
                {verifyingPasscode ? 'Procesando...' : (isRegisterMode ? 'Crear mi Cuenta' : 'Abrir Recetario')}
              </button>

              <div className="text-center pt-2">
                <button
                  type="button"
                  onClick={() => {
                    setIsRegisterMode(!isRegisterMode);
                    setPasscodeError('');
                  }}
                  className="text-[10px] text-[#8b102e] hover:underline font-bold font-sans cursor-pointer"
                >
                  {isRegisterMode ? '¿Ya tienes una cuenta? Inicia Sesión' : '¿No tienes cuenta? Regístrate aquí'}
                </button>
              </div>
            </div>
          </form>
        </div>
      )}

      {/* 🔮 PASSCODE SUCCESS WELCOME MODAL */}
      {showWelcomeModal && (
        <div className="fixed inset-0 bg-black/85 backdrop-blur-md z-50 flex items-center justify-center p-4">
          <div className="bg-[#f6ecda] w-full max-w-md rounded-3xl p-6 relative border-4 border-[#b38728] shadow-2xl flex flex-col text-center items-center justify-center">
            <GoldenCorners />
            {currentUser?.email === 'marisoldecorarte@gmail.com' && (currentUser?.role === 'admin' || currentUser?.role === 'supervisor') ? (
              <div className="space-y-4 w-full">
                <Sparkles size={36} className="text-[#b38728] mb-1 animate-bounce mx-auto" />
                <h3 className="font-serif text-lg font-black text-[#4a0717]">¡Bienvenida, Diseñadora de Sueños!</h3>
                
                {/* Dedicatoria Parchment scroll */}
                <div 
                  className="my-3 relative z-10 rotate-[-1.5deg] hover:rotate-0 transition-transform duration-300 p-4 sm:p-5 rounded-l-[22px_8px] rounded-r-[8px_22px] border-y-2 border-[#5c3e21]/45 border-x-4 border-double border-[#5c3e21]/70 bg-gradient-to-r from-[#d3c09b] via-[#faf0db] to-[#d3c09b] shadow-[0_10px_20px_rgba(0,0,0,0.45),_inset_0_0_25px_rgba(92,62,33,0.18)] max-w-sm mx-auto"
                >
                  <div className="absolute -top-3 -right-2 bg-[#8b102e] text-[#faf6eb] text-[8px] font-sans font-black px-1.5 py-0.5 rounded shadow rotate-[12deg] border border-[#d4af37]/40 uppercase tracking-widest">
                    Sello Gurú
                  </div>
                  <p 
                    className="text-xs sm:text-[13px] text-[#4a0717] leading-relaxed text-center" 
                    style={{ 
                      fontFamily: "'Dancing Script', cursive",
                      fontWeight: 700
                    }}
                  >
                    "Con noble afecto y alta estima para MRV, de vuestro leal servidor El Gran Gurú. Es y siempre será un supremo honor crear a vuestro lado. Prometida fue esta obra y hoy os es entregada; un pergamino de tantos que mis manos han trazado, con el anhelo de que no sea el último. Continuaremos escribiendo juntos los anales de nuestra existence."
                  </p>
                </div>

                <button
                  type="button"
                  onClick={() => setShowWelcomeModal(false)}
                  className="w-full py-2.5 rounded-xl bg-gradient-to-r from-[#bf953f] to-[#aa771c] hover:from-[#aa771c] hover:to-[#8c6739] text-[#3d1b13] font-black font-sans text-xs tracking-wider uppercase shadow-md transition-colors"
                >
                  Entrar al Manual
                </button>
              </div>
            ) : (
              <>
                <Sparkles size={36} className="text-[#b38728] mb-3 animate-bounce" />
                <h3 className="font-serif text-lg font-black text-[#4a0717] mb-2">¡Validación Exitosa!</h3>
                <p className="text-xs text-[#2b251f] font-serif font-bold italic mb-5 leading-relaxed">
                  "Esta receta dejó de ser secreta."
                </p>
                <button
                  type="button"
                  onClick={() => setShowWelcomeModal(false)}
                  className="w-full py-2.5 rounded-xl bg-gradient-to-r from-[#bf953f] to-[#aa771c] hover:from-[#aa771c] hover:to-[#8c6739] text-[#3d1b13] font-black font-sans text-xs tracking-wider uppercase shadow-md transition-colors"
                >
                  Comenzar a Leer
                </button>
              </>
            )}
          </div>
        </div>
      )}

      {loading && (
        <div className="text-amber-100/80 font-bold tracking-widest text-sm animate-pulse font-sans">
          Abriendo "La Receta Secreta" de DecorArte...
        </div>
      )}

      {!loading && (
        <div className="w-full max-w-6xl flex flex-col items-center relative z-10">
          
          {/* Header Controls (Only when book is closed) */}
          {!isOpen && (
            <div className="w-full flex justify-between items-center mb-5 px-2 text-amber-100/70 text-xs font-bold font-sans">
              <div className="flex items-center gap-4">
                <span className="flex items-center gap-1.5 text-amber-100/50">
                  <Lock size={14} /> Libro Cerrado
                </span>
              </div>
              <div className="flex items-center gap-4">
                <button 
                  onClick={handleShare}
                  className="flex items-center gap-1.5 hover:text-white transition-colors"
                >
                  {copiedLink ? <Check size={14} className="text-emerald-500" /> : <Share2 size={14} />}
                  {copiedLink ? 'Enlace Copiado' : 'Compartir Receta'}
                </button>
              </div>
            </div>
          )}

          {/* MAIN SKEUOMORPHIC BOOK CONTAINER */}
          {!isOpen ? (
            <div 
              onClick={() => setShowPasscodeModal(true)}
              className="w-full max-w-[340px] sm:max-w-[430px] rounded-r-2xl rounded-l-md book-cover-3d cursor-pointer relative overflow-hidden select-none border-2 border-r-4 border-slate-950 min-h-[580px] sm:min-h-[660px] bg-cover bg-center transition-all duration-700 hover:scale-[1.02] shadow-[12px_22px_60px_rgba(0,0,0,0.95)] group"
              style={{
                backgroundImage: 'url("/book_cover.jpg")',
                borderColor: '#110103',
              }}
            >
              {/* Subtle hover overlay to make the book shine on hover */}
              <div className="absolute inset-0 bg-gradient-to-tr from-white/0 via-white/5 to-white/0 opacity-0 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"></div>
              {/* Skeuomorphic book spine simulated shadow on the left edge */}
              <div className="absolute left-0 top-0 bottom-0 w-6 bg-gradient-to-r from-black/45 to-transparent pointer-events-none"></div>
            </div>
          ) : (
            
            /* =========================================================================
               2. OPENED BOOK DOUBLE PAGE VIEW (Hojas de Pergamino)
               ========================================================================= */
            <div className="w-full min-h-screen sm:min-h-0 bg-[#240207] p-0 sm:p-4 rounded-none sm:rounded-3xl shadow-2xl relative border-0 sm:border-4 border-[#120003]"
              style={{
                boxShadow: '0 25px 50px rgba(0,0,0,0.85)',
              }}
            >
              {/* Outer leather cover overlap representation */}
              <div className="absolute inset-0 bg-[#4a0717] rounded-none sm:rounded-3xl -z-10 transform scale-[1.006] border-0 sm:border border-[#d4af37]/25 shadow-2xl"></div>

              {/* Open Book Pages Wrapper */}
              <div className="flex flex-col lg:flex-row rounded-none sm:rounded-2xl overflow-hidden relative min-h-screen sm:min-h-[620px]">
                
                {/* Ribbon bookmark hanging down the center */}
                <div className="hidden lg:block absolute left-[50%] -translate-x-1/2 top-0 h-44 w-3.5 bg-[#8b102e] border-x border-[#500618] rounded-b-md shadow-lg z-20 pointer-events-none after:content-[''] after:absolute after:bottom-0 after:left-0 after:right-0 after:h-2 after:bg-black/20"></div>
                {showAiAssistant && (
                  <div className="fixed inset-0 bg-black/75 backdrop-blur-sm z-50 flex items-center justify-center p-3">
                    <div className={`bg-[#f6ecda] w-full ${scribeActiveTab === 'scribe' && scribeResultHtml ? 'max-w-3xl h-[92%]' : 'max-w-md'} rounded-3xl p-6 relative border-4 border-[#b38728] shadow-2xl flex flex-col text-left transition-all duration-300`}>
                      <GoldenCorners />
                      
                      {/* Close button */}
                      <button 
                        onClick={() => {
                          setShowAiAssistant(false);
                          if ('speechSynthesis' in window) window.speechSynthesis.cancel();
                          stopListening();
                        }}
                        className="absolute top-4 right-4 p-1.5 rounded-full hover:bg-[#3d1b13]/5 text-[#3d1b13] z-10"
                      >
                        <X size={18} />
                      </button>
                      
                      {/* Title */}
                      <h3 className="font-serif text-base sm:text-lg font-black text-[#4a0717] border-b border-[#d2c7ac]/40 pb-2 mb-3 flex items-center gap-2">
                        <Sparkles size={18} className="text-[#b38728]" />
                        Oráculo & Escribano DecorArte 360
                      </h3>

                      {/* Mode Toggle Tabs */}
                      <div className="flex gap-2 mb-4 relative z-10 font-sans">
                        <button
                          type="button"
                          onClick={() => {
                            setScribeActiveTab('chat');
                            if ('speechSynthesis' in window) window.speechSynthesis.cancel();
                            stopListening();
                          }}
                          className={`flex-1 py-1 rounded-lg text-[9px] font-black tracking-wider uppercase border text-center transition-all ${
                            scribeActiveTab === 'chat' 
                              ? 'bg-[#8b102e] border-[#8b102e] text-white shadow-sm' 
                              : 'bg-[#faf6eb] border-[#d2c7ac] text-[#4a0717] hover:bg-white'
                          }`}
                        >
                          Preguntas al Oráculo
                        </button>
                        <button
                          type="button"
                          onClick={() => {
                            setScribeActiveTab('scribe');
                            if ('speechSynthesis' in window) window.speechSynthesis.cancel();
                            stopListening();
                          }}
                          className={`flex-1 py-1 rounded-lg text-[9px] font-black tracking-wider uppercase border text-center transition-all ${
                            scribeActiveTab === 'scribe' 
                              ? 'bg-[#8b102e] border-[#8b102e] text-white shadow-sm' 
                              : 'bg-[#faf6eb] border-[#d2c7ac] text-[#4a0717] hover:bg-white'
                          }`}
                        >
                          Escribano de Contratación
                        </button>
                      </div>

                      {scribeActiveTab === 'chat' ? (
                        /* ========================================================
                           TAB 1: ORACLE VOICE CHAT
                           ======================================================== */
                        <div className="flex-1 flex flex-col justify-between min-h-0">
                          {/* Pulsing Talisman Mic */}
                          <div className="flex flex-col items-center my-4">
                            <button
                              type="button"
                              onClick={isListening ? stopListening : startListening}
                              className={`w-20 h-20 rounded-full flex flex-col items-center justify-center shadow-xl border-4 transition-all ${
                                isListening 
                                  ? 'bg-rose-50 border-rose-400 text-rose-600 animate-pulse'
                                  : 'bg-[#faf6eb] border-[#d2c7ac] text-[#3d1b13] hover:scale-105 hover:border-[#b38728]'
                              }`}
                            >
                              <Mic size={28} className={isListening ? 'animate-bounce' : ''} />
                              <span className="text-[7px] font-black uppercase mt-1 tracking-widest font-sans">
                                {isListening ? 'Escuchando' : 'Hablar'}
                              </span>
                            </button>
                            <span className="text-[9px] font-black text-[#8c6739] uppercase tracking-widest mt-2 animate-pulse font-sans">
                              {assistantStatus}
                            </span>
                          </div>

                          {/* Manual Text Input Fallback */}
                          <div className="flex gap-2 mb-3 px-1 relative z-10">
                            <input 
                              type="text"
                              placeholder="Escribe tu duda aquí..."
                              value={questionText}
                              onChange={(e) => setQuestionText(e.target.value)}
                              onKeyDown={(e) => {
                                if (e.key === 'Enter' && questionText.trim()) {
                                  askCopilot(questionText);
                                }
                              }}
                              className="flex-1 px-3 py-1.5 rounded-xl border border-[#d2c7ac] bg-[#faf6eb] text-xs focus:outline-none focus:border-[#8b102e] font-sans"
                            />
                            <button
                              type="button"
                              onClick={() => {
                                if (questionText.trim()) askCopilot(questionText);
                              }}
                              disabled={queryingAi || !questionText.trim()}
                              className="px-3.5 py-1.5 rounded-xl bg-[#8b102e] hover:bg-[#4a0717] text-white font-bold text-xs transition-colors disabled:opacity-50 font-sans"
                            >
                              Preguntar
                            </button>
                          </div>

                          {/* Transcription / Question */}
                          {questionText && (
                            <div className="mb-3 bg-[#faf6eb] border border-[#d2c7ac] rounded-2xl p-2.5">
                              <span className="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-0.5 font-sans">Pregunta</span>
                              <p className="text-xs text-[#2b251f] font-serif font-bold italic">"{questionText}"</p>
                            </div>
                          )}

                          {/* AI Answer */}
                          {(queryingAi || aiAnswer) && (
                            <div className="bg-[#faf6eb] border border-[#d2c7ac] rounded-2xl p-3.5 flex-1 overflow-y-auto max-h-[160px] scrollbar-none relative mb-2">
                              <span className="text-[8px] font-black text-[#8c6739] uppercase tracking-widest block mb-1 font-sans">Respuesta del Oráculo</span>
                              {queryingAi ? (
                                <div className="text-xs text-[#3d1b13]/55 italic animate-pulse font-serif">Consultando pergaminos...</div>
                              ) : (
                                <p className="text-xs text-[#2b251f] font-serif leading-relaxed italic">{aiAnswer}</p>
                              )}
                            </div>
                          )}

                          {/* Tip / Footer */}
                          <div className="text-[8px] text-[#3d1b13]/40 font-sans font-bold border-t border-[#d2c7ac]/45 pt-1.5 mt-2 text-center">
                            PREGUNTA DE VIVA VOZ SOBRE NÓMINAS, PUESTOS Y SANCIONES.
                          </div>
                        </div>
                      ) : (
                        /* ========================================================
                           TAB 2: AI CONTRACT SCRIBE
                           ======================================================== */
                        <div className="flex-grow flex flex-col min-h-0 text-left">
                          {scribeResultHtml ? (
                            /* Scribe Document Result & Printer Workspace */
                            <div className="flex-1 flex flex-col justify-between min-h-0">
                              <div className="flex-1 overflow-y-auto border border-[#d2c7ac] bg-[#faf6eb] p-4 rounded-2xl custom-markdown shadow-inner scrollbar-none">
                                <div dangerouslySetInnerHTML={{ __html: scribeResultHtml }} />
                              </div>

                              <div className="flex gap-2.5 mt-4">
                                <button
                                  type="button"
                                  onClick={() => setScribeResultHtml('')}
                                  className="flex-1 py-2 rounded-xl border border-[#d2c7ac] text-[#4a0717] bg-[#faf6eb] hover:bg-white font-sans font-bold text-xs tracking-wider uppercase"
                                >
                                  Redactar Otro
                                </button>
                                <button
                                  type="button"
                                  onClick={handlePrintScribeDocs}
                                  className="flex-grow py-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 text-white font-sans font-black text-xs tracking-wider uppercase shadow-md flex items-center justify-center gap-1.5"
                                >
                                  <FileText size={14} /> Imprimir / PDF
                                </button>
                              </div>
                            </div>
                          ) : (
                            /* Scribe Setup Form */
                            <div className="space-y-3.5 relative z-10 flex-1 flex flex-col justify-between min-h-0">
                              <div className="space-y-3">
                                <div>
                                  <label className="text-[9px] font-black text-[#8b102e] uppercase tracking-widest block mb-1 font-sans">Nombre del Nuevo Colaborador</label>
                                  <input 
                                    type="text"
                                    required
                                    placeholder="Ej: Laura González"
                                    value={candidateName}
                                    onChange={(e) => setCandidateName(e.target.value)}
                                    className="w-full px-3 py-2 rounded-xl border border-[#d2c7ac] bg-[#faf6eb] text-xs focus:outline-none focus:border-[#8b102e] font-sans"
                                  />
                                </div>

                                <div>
                                  <label className="text-[9px] font-black text-[#8b102e] uppercase tracking-widest block mb-1 font-sans">Puesto (Basado en el Manual)</label>
                                  <select
                                    value={jobRoleSlug}
                                    onChange={(e) => setJobRoleSlug(e.target.value)}
                                    className="w-full px-3 py-2 rounded-xl border border-[#d2c7ac] bg-[#faf6eb] text-xs focus:outline-none focus:border-[#8b102e] font-serif"
                                  >
                                    <option value="">-- Selecciona un puesto del baúl --</option>
                                    {(index.puesto || []).map((p: any) => (
                                      <option key={p.id} value={p.slug}>{p.title}</option>
                                    ))}
                                  </select>
                                </div>

                                <div>
                                  <label className="text-[9px] font-black text-[#8b102e] uppercase tracking-widest block mb-1.5 font-sans">Pergaminos a Redactar</label>
                                  <div className="grid grid-cols-2 gap-2 text-[10px] font-sans text-slate-700">
                                    <label className="flex items-center gap-1.5 cursor-pointer">
                                      <input 
                                        type="checkbox" 
                                        checked={requestedDocs.includes('contract')} 
                                        onChange={(e) => {
                                          if (e.target.checked) setRequestedDocs([...requestedDocs, 'contract']);
                                          else setRequestedDocs(requestedDocs.filter(d => d !== 'contract'));
                                        }}
                                      />
                                      Contrato Laboral
                                    </label>
                                    <label className="flex items-center gap-1.5 cursor-pointer">
                                      <input 
                                        type="checkbox" 
                                        checked={requestedDocs.includes('tasks')} 
                                        onChange={(e) => {
                                          if (e.target.checked) setRequestedDocs([...requestedDocs, 'tasks']);
                                          else setRequestedDocs(requestedDocs.filter(d => d !== 'tasks'));
                                        }}
                                      />
                                      Obligaciones y Tareas
                                    </label>
                                    <label className="flex items-center gap-1.5 cursor-pointer">
                                      <input 
                                        type="checkbox" 
                                        checked={requestedDocs.includes('rules')} 
                                        onChange={(e) => {
                                          if (e.target.checked) setRequestedDocs([...requestedDocs, 'rules']);
                                          else setRequestedDocs(requestedDocs.filter(d => d !== 'rules'));
                                        }}
                                      />
                                      Reglamento Interno
                                    </label>
                                    <label className="flex items-center gap-1.5 cursor-pointer">
                                      <input 
                                        type="checkbox" 
                                        checked={requestedDocs.includes('responsive')} 
                                        onChange={(e) => {
                                          if (e.target.checked) setRequestedDocs([...requestedDocs, 'responsive']);
                                          else setRequestedDocs(requestedDocs.filter(d => d !== 'responsive'));
                                        }}
                                      />
                                      Carta Responsiva
                                    </label>
                                  </div>
                                </div>
                              </div>

                              <button
                                type="button"
                                onClick={handleGenerateOnboardingDocs}
                                disabled={generatingScribe || !candidateName || !jobRoleSlug || requestedDocs.length === 0}
                                className="w-full py-2.5 rounded-xl bg-gradient-to-r from-[#bf953f] to-[#aa771c] hover:from-[#aa771c] hover:to-[#8c6739] text-[#3d1b13] font-black font-sans text-xs tracking-wider uppercase shadow-md transition-colors disabled:opacity-50 mt-4 flex items-center justify-center gap-1.5"
                              >
                                {generatingScribe ? 'Redactando Pergaminos AI...' : 'Generar Kit de Contratación'}
                              </button>
                            </div>
                          )}
                        </div>
                      )}
                    </div>
                  </div>
                )}

                {/* -----------------------------------------------------------
                    PAGE A: INDEX / TABLE OF CONTENTS (Left Page)
                    ----------------------------------------------------------- */}
                <div className={`w-full lg:w-1/2 p-4 sm:p-7 flex flex-col justify-between relative book-spine-line-right border-b lg:border-b-0 lg:border-r border-[#d8ccb6] ${
                  mobileView === 'index' ? 'flex' : 'hidden lg:flex'
                }`}
                  style={{
                    backgroundColor: '#f6ecda',
                    boxShadow: 'inset -20px 0 30px rgba(0,0,0,0.03), inset 10px 0 20px rgba(255,255,255,0.4)',
                  }}
                >
                  <div className="flex-1 flex flex-col">
                    {/* Header */}
                    <div className="border-b border-[#4a0717]/20 pb-3 mb-4 flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <img src="/decorarte_logo.png" alt="Mini logo" className="w-7 h-7 object-contain" />
                        <div>
                          <span className="text-[8px] font-black text-[#8b102e] uppercase tracking-widest leading-none block mb-0.5 font-sans">DecorArte</span>
                          <h3 className="text-sm sm:text-base font-bold font-serif text-[#4a0717] tracking-wide leading-none">La Receta Secreta</h3>
                        </div>
                      </div>
                      <BookIcon size={18} className="text-[#4a0717]/40" />
                    </div>

                    {/* Search */}
                    <div className="relative mb-4">
                      <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-[#4a0717]/50" />
                      <input 
                        type="text" 
                        placeholder="Buscar en el manual..."
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        className="w-full pl-8 pr-4 py-2 rounded-xl border border-[#d2c7ac] bg-[#faf6eb] text-[#2b251f] placeholder-[#4a0717]/30 focus:outline-none focus:border-[#8b102e] text-xs font-semibold font-sans transition-all shadow-sm"
                      />
                    </div>

                    {/* Navigation tabs for Auditors */}
                    {passcodeRole === 'auditor' && (
                      <div className="flex gap-2 mb-4 relative z-10 font-sans">
                        <button
                          onClick={() => {
                            setActiveBookTab('read');
                            setIsSuggesting(false);
                          }}
                          className={`flex-1 py-1.5 rounded-lg text-[10px] font-bold tracking-wider uppercase border text-center transition-all ${
                            activeBookTab === 'read' 
                              ? 'bg-[#8b102e] border-[#8b102e] text-white shadow-sm' 
                              : 'bg-[#faf6eb] border-[#d2c7ac] text-[#4a0717] hover:bg-white'
                          }`}
                        >
                          Manual
                        </button>
                        <button
                          onClick={() => {
                            setActiveBookTab('audit');
                            setActiveSuggestion(null);
                            setIsSuggesting(false);
                          }}
                          className={`flex-grow py-1.5 rounded-lg text-[10px] font-bold tracking-wider uppercase border text-center transition-all flex items-center justify-center gap-1 ${
                            activeBookTab === 'audit' 
                              ? 'bg-[#b38728] border-[#b38728] text-[#3d1b13] shadow-sm' 
                              : 'bg-[#faf6eb] border-[#d2c7ac] text-[#4a0717] hover:bg-white'
                          }`}
                        >
                          <Key size={10} /> Auditoría
                        </button>
                      </div>
                    )}

                    {/* Index List (Shows manual files OR suggestions inbox) */}
                    <div className="flex-1 overflow-y-auto pr-1 space-y-4 scrollbar-none min-h-0">
                      {activeBookTab === 'audit' ? (
                        /* ==========================================
                           AUDITOR INBOX LIST
                           ========================================== */
                        <div className="space-y-2">
                          <h4 className="text-[9px] font-black text-[#8b102e] uppercase tracking-widest px-1 border-b border-[#4a0717]/10 pb-0.5">
                            Propuestas de Colaboradores
                          </h4>
                          {loadingSuggestions ? (
                            <div className="text-center py-8 text-[#4a0717]/40 text-xs italic font-semibold animate-pulse">Cargando propuestas...</div>
                          ) : suggestionsList.length === 0 ? (
                            <div className="text-center py-8 text-[#4a0717]/40 text-xs italic font-semibold">No hay propuestas pendientes de revisión.</div>
                          ) : (
                            suggestionsList.map((s) => (
                              <button
                                key={s.id}
                                onClick={() => {
                                  setActiveSuggestion(s);
                                  setMobileView('content');
                                }}
                                className={`w-full p-2.5 rounded-lg border text-left transition-all flex flex-col gap-1 ${
                                  activeSuggestion?.id === s.id
                                    ? 'bg-[#b38728]/10 border-[#b38728] text-[#4a0717]'
                                    : 'bg-[#faf6eb] border-[#d2c7ac] text-[#2b251f] hover:bg-white'
                                }`}
                              >
                                <div className="flex justify-between items-center">
                                  <span className="text-[9px] font-black uppercase text-[#8b102e] tracking-wider truncate max-w-[130px]">
                                    {s.document?.title || 'Doc. Eliminado'}
                                  </span>
                                  <span className={`text-[8px] font-bold px-1.5 py-0.5 rounded leading-none ${
                                    s.status === 'pending' ? 'bg-amber-100 text-amber-800' :
                                    s.status === 'approved' ? 'bg-emerald-100 text-emerald-800' :
                                    'bg-rose-100 text-rose-800'
                                  }`}>
                                    {s.status === 'pending' ? 'Pendiente' : s.status === 'approved' ? 'Aprobado' : 'Rechazado'}
                                  </span>
                                </div>
                                <p className="text-xs font-serif italic truncate">"{s.comment}"</p>
                                <span className="text-[8px] text-slate-500 tracking-wider text-right block mt-0.5">Por: {s.user_name}</span>
                              </button>
                            ))
                          )}
                        </div>
                      ) : (
                        /* ==========================================
                           STANDARD DOCUMENT INDEX
                           ========================================== */
                        sortedFilteredIndexEntries().length === 0 ? (
                          <div className="text-center py-8 text-[#4a0717]/40 text-xs italic font-semibold">No se encontraron capítulos.</div>
                        ) : (
                          sortedFilteredIndexEntries().map(([category, items]) => {
                            const isExpanded = !!expandedCategories[category];
                            const totalCount = items?.length || 0;
                            const readCount = items?.filter(item => readDocSlugs.includes(item.slug)).length || 0;
                            const percent = totalCount > 0 ? Math.round((readCount / totalCount) * 100) : 0;

                            return (
                               <div key={category} className="space-y-1">
                                 <button
                                   type="button"
                                   onClick={() => toggleCategory(category)}
                                   className="w-full flex items-center justify-between text-left px-1 mb-1.5 border-b border-[#4a0717]/15 pb-1 select-none hover:opacity-80 group transition-all"
                                 >
                                   <div className="flex items-center gap-1.5">
                                     <div className={`p-0.5 rounded text-[#8b102e] transition-transform duration-200 ${isExpanded ? 'rotate-90' : ''}`}>
                                       <ChevronRight size={12} />
                                     </div>
                                     <h4 className="text-[11.5px] font-black text-[#8b102e] uppercase tracking-widest font-sans">
                                       {getCategoryTitle(category)}
                                     </h4>
                                   </div>
                                   <div className="flex items-center gap-1.5">
                                     {percent > 0 && (
                                       <span className="text-[9px] font-sans font-black text-emerald-600 bg-emerald-50 px-1 py-0.5 rounded border border-emerald-150 leading-none">
                                         {percent}%
                                       </span>
                                     )}
                                     <span className="text-[9px] font-sans font-black text-[#8b102e]/60 px-1.5 py-0.5 bg-[#8b102e]/5 rounded-md leading-none">
                                       {items?.length || 0}
                                     </span>
                                   </div>
                                 </button>

                                 {isExpanded && (
                                   <div className="space-y-3 pl-2 transition-all duration-300 animate-[fadeIn_0.2s_ease-out]">
                                     {getCategorySubgroups(category, items as DocIndexItem[]).map((subgroup, subIdx) => (
                                       <div key={subIdx} className="space-y-1">
                                         {/* Subgroup title */}
                                         {getCategorySubgroups(category, items as DocIndexItem[]).length > 1 && (
                                           <div className="text-[9px] font-sans font-black text-[#8b102e]/60 uppercase tracking-widest pl-1.5 border-l border-[#8b102e]/20 mt-1 mb-0.5 select-none">
                                             {subgroup.title}
                                           </div>
                                         )}
                                         <div className="space-y-1">
                                           {subgroup.items.map((item: DocIndexItem) => {
                                             const isActive = activeDoc?.slug === item.slug && activeBookTab === 'read';
                                             const isRead = readDocSlugs.includes(item.slug);
                                             return (
                                               <button
                                                 key={item.id}
                                                 onClick={() => {
                                                   navigate(`/organizacion/${tenantSlug}/${item.slug}`);
                                                   setMobileView('content');
                                                   setActiveBookTab('read');
                                                   setIsSuggesting(false);
                                                 }}
                                                 className={`w-full flex items-center gap-2.5 p-2 rounded-lg text-left transition-all ${
                                                   isActive 
                                                     ? 'bg-[#8b102e]/5 text-[#8b102e] font-bold border-l-2 border-[#b38728] shadow-sm' 
                                                     : 'text-[#2b251f]/85 hover:bg-[#8b102e]/5 hover:text-[#8b102e] border-l-2 border-transparent'
                                                 }`}
                                               >
                                                 <div className={`p-1 rounded-md ${isActive ? 'bg-[#8b102e]/10 text-[#8b102e]' : 'bg-[#faf6eb] text-[#2b251f]/60 border border-[#d2c7ac]'}`}>
                                                   {getIcon(item.icon)}
                                                 </div>
                                                 <span className="text-[13.5px] font-serif truncate flex-1 leading-none">{item.title}</span>
                                                 {isRead && (
                                                   <div className="w-3.5 h-3.5 rounded-full bg-emerald-50 border border-emerald-250 flex items-center justify-center text-emerald-600 shrink-0">
                                                     <Check size={8} strokeWidth={3.5} />
                                                   </div>
                                                 )}
                                                 <ChevronRight size={12} className={`opacity-40 transition-transform ${isActive && 'translate-x-0.5'}`} />
                                               </button>
                                             );
                                           })}
                                         </div>
                                       </div>
                                     ))}
                                   </div>
                                 )}
                               </div>
                             );
                          })
                        )
                      )}
                    </div>
                  </div>

                  {/* Bottom Controls inside Index Page */}
                  <div className="border-t border-[#d8ccb6] pt-3 mt-4 flex justify-between items-center gap-3 relative z-10">
                    <button 
                      onClick={handleLogout}
                      className="flex-1 py-2 px-3 bg-[#8b102e]/10 hover:bg-[#8b102e]/20 text-[#8b102e] font-sans font-black text-[10px] uppercase tracking-wider rounded-xl transition-colors flex items-center justify-center gap-1.5"
                    >
                      <Unlock size={12} className="text-rose-700" />
                      Cerrar Libro
                    </button>
                    <button 
                      onClick={handleShare}
                      className="flex-1 py-2 px-3 bg-[#b38728]/10 hover:bg-[#b38728]/20 text-[#b38728] font-sans font-black text-[10px] uppercase tracking-wider rounded-xl transition-colors flex items-center justify-center gap-1.5"
                    >
                      {copiedLink ? <Check size={12} className="text-emerald-600" /> : <Share2 size={12} />}
                      {copiedLink ? 'Copiado' : 'Compartir'}
                    </button>
                  </div>
                </div>

                {/* -----------------------------------------------------------
                    PAGE B: CONTENT RENDERER / SUGESTIONES (Right Page)
                    ----------------------------------------------------------- */}
                <div className={`w-full lg:w-1/2 p-4 sm:p-7 flex flex-col justify-between relative book-spine-line ${
                  mobileView === 'content' ? 'flex' : 'hidden lg:flex'
                }`}
                  style={{
                    backgroundColor: '#f6ecda',
                    boxShadow: 'inset 20px 0 30px rgba(0,0,0,0.03), inset -10px 0 20px rgba(255,255,255,0.4)',
                  }}
                >
                  <div className="flex-1 flex flex-col min-w-0">
                    
                    {activeBookTab === 'audit' ? (
                      /* ========================================================
                         AUDITOR REVIEW WORKSPACE (RIGHT PAGE)
                         ======================================================== */
                      <div className="flex-1 flex flex-col min-w-0">
                        <div className="border-b border-[#4a0717]/20 pb-3 mb-4 flex items-center justify-between">
                          <span className="text-xs font-bold text-[#4a0717] font-sans">Panel de Evaluación de Cambios</span>
                          <button 
                            onClick={() => setMobileView('index')} 
                            className="lg:hidden p-1.5 rounded-lg border border-[#d2c7ac] text-[#4a0717] bg-[#faf6eb] hover:bg-white flex items-center gap-1 text-[10px] font-sans font-bold shadow-sm animate-pulse"
                          >
                            <Menu size={14} /> Solicitudes
                          </button>
                        </div>

                        {!activeSuggestion ? (
                          <div className="flex-1 flex flex-col items-center justify-center py-16 text-[#4a0717]/40 text-center">
                            <Key size={36} className="text-[#4a0717]/20 mb-3 animate-bounce" />
                            <h4 className="text-sm font-bold font-serif mb-1">Buzón de Auditoría Abierto</h4>
                            <p className="text-[10px] max-w-[220px] leading-relaxed">
                              Selecciona una propuesta del listado izquierdo para evaluarla, ver los cambios propuestos y actualizar el manual.
                            </p>
                          </div>
                        ) : (
                          <div className="flex-1 flex flex-col min-w-0 justify-between">
                            <div className="space-y-4">
                              <div className="bg-[#faf6eb] border border-[#d2c7ac] rounded-xl p-3">
                                <span className="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-0.5 font-sans">Origen del Cambio</span>
                                <div className="text-xs font-serif font-bold text-[#4a0717]">
                                  Propuesto por: {activeSuggestion.user_name} • Documento: {activeSuggestion.document?.title || 'Desconocido'}
                                </div>
                                <p className="text-[11px] text-slate-600 mt-1 italic">"{activeSuggestion.comment}"</p>
                              </div>

                              <div className="flex flex-col text-left">
                                <span className="text-[8px] font-black text-slate-400 uppercase tracking-widest block mb-1 font-sans">Texto Propuesto</span>
                                <textarea
                                  readOnly
                                  value={activeSuggestion.proposed_content}
                                  className="w-full h-32 px-3 py-2 rounded-xl border border-[#d2c7ac] bg-[#faf6eb] text-slate-700 font-mono text-[10px] focus:outline-none scrollbar-none shadow-inner"
                                />
                              </div>

                              {activeSuggestion.status === 'pending' && (
                                <div className="space-y-2">
                                  <label className="text-[8px] font-black text-[#8b102e] uppercase tracking-widest block font-sans">Nota de Revisión (Opcional)</label>
                                  <input
                                    type="text"
                                    placeholder="Ej: Aprobado tras revisión de recetas"
                                    value={reviewComment}
                                    onChange={(e) => setReviewComment(e.target.value)}
                                    className="w-full px-3 py-1.5 rounded-xl border border-[#d2c7ac] bg-[#faf6eb] text-xs focus:outline-none"
                                  />
                                </div>
                              )}
                            </div>

                            {activeSuggestion.status === 'pending' && (
                              <div className="flex gap-3 mt-4">
                                <button
                                  onClick={() => handleRejectSuggestion(activeSuggestion.id)}
                                  disabled={processingSuggestion}
                                  className="flex-1 py-2 rounded-xl border border-rose-300 text-rose-700 bg-rose-50 hover:bg-rose-100 font-sans font-bold text-xs tracking-wider uppercase disabled:opacity-50"
                                >
                                  Rechazar
                                </button>
                                <button
                                  onClick={() => handleApproveSuggestion(activeSuggestion.id)}
                                  disabled={processingSuggestion}
                                  className="flex-grow py-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-sans font-black text-xs tracking-wider uppercase shadow-md disabled:opacity-50"
                                >
                                  Aprobar e Incorporar
                                </button>
                              </div>
                            )}
                          </div>
                        )}
                      </div>
                    ) : isSuggesting ? (
                      /* ========================================================
                         EMPLOYEE SUGGESTION SUBMISSION FORM (RIGHT PAGE)
                         ======================================================== */
                      <form onSubmit={handleSubmitSuggestion} className="flex-1 flex flex-col min-w-0 justify-between text-left">
                        <div className="border-b border-[#4a0717]/20 pb-3 mb-4 flex items-center justify-between">
                          <span className="text-xs font-bold text-[#4a0717] font-sans">Proponer Mejora al Manual</span>
                          <button 
                            type="button"
                            onClick={() => setIsSuggesting(false)}
                            className="p-1 rounded-full hover:bg-slate-100 text-[#4a0717]"
                          >
                            <X size={16} />
                          </button>
                        </div>

                        <div className="flex-grow space-y-3 pr-1 overflow-y-auto scrollbar-none max-h-[350px]">
                          <div>
                            <label className="text-[8px] font-black text-[#8b102e] uppercase tracking-widest block mb-1 font-sans">Tu Nombre</label>
                            <input 
                              type="text"
                              required
                              placeholder="Ej: Chef Juan Pérez"
                              value={suggestionName}
                              onChange={(e) => setSuggestionName(e.target.value)}
                              className="w-full px-3 py-1.5 rounded-lg border border-[#d2c7ac] bg-[#faf6eb] text-xs focus:outline-none"
                            />
                          </div>

                          <div>
                            <label className="text-[8px] font-black text-[#8b102e] uppercase tracking-widest block mb-1 font-sans">¿Por qué sugieres esta mejora?</label>
                            <input 
                              type="text"
                              required
                              placeholder="Ej: Corregir medidas de harina o agregar checklist"
                              value={suggestionComment}
                              onChange={(e) => setSuggestionComment(e.target.value)}
                              className="w-full px-3 py-1.5 rounded-lg border border-[#d2c7ac] bg-[#faf6eb] text-xs focus:outline-none"
                            />
                          </div>

                          <div>
                            <label className="text-[8px] font-black text-[#8b102e] uppercase tracking-widest block mb-1 font-sans">Texto Corregido (Markdown)</label>
                            <textarea
                              required
                              rows={5}
                              value={proposedContent}
                              onChange={(e) => setProposedContent(e.target.value)}
                              className="w-full px-3 py-2 rounded-xl border border-[#d2c7ac] bg-[#faf6eb] text-slate-700 font-mono text-[10px] focus:outline-none shadow-inner resize-none"
                            />
                          </div>
                        </div>

                        <div className="flex gap-2.5 mt-4">
                          <button
                            type="button"
                            onClick={() => setIsSuggesting(false)}
                            className="flex-1 py-2 rounded-xl border border-[#d2c7ac] text-[#4a0717] bg-[#faf6eb] hover:bg-white font-sans font-bold text-[10px] tracking-wider uppercase"
                          >
                            Cancelar
                          </button>
                          <button
                            type="submit"
                            disabled={submittingSuggestion}
                            className="flex-grow py-2 rounded-xl bg-gradient-to-r from-[#8b102e] to-[#4a0717] hover:from-[#4a0717] hover:to-black text-white font-sans font-black text-[10px] tracking-wider uppercase shadow-md disabled:opacity-50"
                          >
                            {submittingSuggestion ? 'Enviando...' : 'Enviar al Oráculo'}
                          </button>
                        </div>
                      </form>
                    ) : (
                      /* ========================================================
                         STANDARD DOCUMENT VIEWER (RIGHT PAGE)
                         ======================================================== */
                      <div className="flex-1 flex flex-col min-w-0">
                        {/* Header */}
                        <div className="border-b border-[#4a0717]/20 pb-3 mb-4 flex items-center justify-between">
                          {activeDoc ? (
                            <div className="flex items-center gap-3">
                              <div className="p-1.5 rounded-lg bg-[#8b102e]/5 text-[#8b102e] shrink-0 border border-[#d2c7ac]">
                                {getIcon(activeDoc.icon)}
                              </div>
                              <div className="flex flex-col text-left">
                                <span className="text-[9px] font-black uppercase text-[#8b102e] tracking-widest leading-none mb-1 font-sans">
                                  {getCategoryTitle(activeDoc.type)}
                                </span>
                                <span className="text-xs font-bold text-[#1e3b8b] font-sans truncate max-w-[100px] sm:max-w-[200px]">{activeDoc.title}</span>
                              </div>
                            </div>
                          ) : (
                            <span className="text-xs text-[#4a0717]/40 font-bold">LECTURA MANUAL</span>
                          )}
                          
                          <div className="flex items-center gap-1.5">
                            {/* Narrator Button */}
                            {activeDoc && (
                              <button
                                onClick={() => speakText(activeDoc.content)}
                                className={`p-1.5 rounded-lg border transition-all flex items-center gap-1 text-[10px] font-sans font-bold shadow-sm ${
                                  isSpeaking 
                                    ? 'bg-rose-100 border-rose-300 text-[#8b102e] animate-pulse' 
                                    : 'bg-[#faf6eb] border-[#d2c7ac] text-[#4a0717] hover:bg-white'
                                }`}
                                title="Narrar contenido"
                              >
                                {isSpeaking ? <VolumeX size={14} /> : <Volume2 size={14} />}
                                <span className="hidden sm:inline">{isSpeaking ? 'Detener' : 'Escuchar'}</span>
                              </button>
                            )}

                            {/* Suggest improvement button */}
                            {activeDoc && (
                              <button
                                onClick={() => {
                                  setProposedContent(activeDoc.raw_content);
                                  setIsSuggesting(true);
                                }}
                                className="p-1.5 rounded-lg border border-[#d2c7ac] text-[#4a0717] bg-[#faf6eb] hover:bg-white flex items-center gap-1 text-[10px] font-sans font-bold shadow-sm"
                                title="Sugerir una corrección o adición"
                              >
                                <MessageSquare size={14} />
                                <span className="hidden sm:inline">Sugerir</span>
                              </button>
                            )}
                            
                            <button 
                              onClick={() => setMobileView('index')} 
                              className="lg:hidden p-1.5 rounded-lg border border-[#d2c7ac] text-[#4a0717] bg-[#faf6eb] hover:bg-white flex items-center gap-1 text-[10px] font-sans font-bold shadow-sm"
                            >
                              <Menu size={14} /> Índice
                            </button>
                          </div>
                        </div>

                        {/* Document Content */}
                        <div className="flex-1 flex flex-col min-w-0">
                          {!activeDoc ? (
                            <div className="flex-1 flex flex-col items-center justify-center py-16 text-[#4a0717]/40 text-center">
                              <BookIcon size={36} className="text-[#4a0717]/20 mb-3 animate-bounce" />
                              <h4 className="text-sm font-bold font-serif mb-1">El Libro está Abierto</h4>
                              <p className="text-[10px] max-w-[220px] leading-relaxed">
                                Selecciona cualquier capítulo del índice a la izquierda para comenzar a leer la receta secreta.
                              </p>
                            </div>
                          ) : (
                            <div className="flex-1 flex flex-col min-w-0">
                              {/* Rich Text area */}
                              <div 
                                ref={contentRef}
                                className="flex-1 text-[#2b251f] pr-1 custom-markdown overflow-y-auto scrollbar-none min-h-0"
                                dangerouslySetInnerHTML={{ __html: activeDoc.content || '<p class="text-slate-400 italic">Esta sección está vacía.</p>' }}
                              />

                              {/* Wiki connections inside page footnotes */}
                              {(links.length > 0 || backlinks.length > 0) && (
                                <div className="mt-4 pt-3 border-t border-dashed border-[#d2c7ac] text-left space-y-1.5">
                                  <span className="text-[9px] font-black text-[#8b102e] uppercase tracking-widest block font-sans">Ramificaciones de este Tema</span>
                                  <div className="flex flex-wrap gap-1.5">
                                    {links.map(l => (
                                      <button
                                        key={l.id}
                                        onClick={() => {
                                          navigate(`/organizacion/${tenantSlug}/${l.slug}`);
                                          setMobileView('content');
                                        }}
                                        className="px-2.5 py-1 bg-[#faf6eb] border border-[#d2c7ac] hover:bg-white hover:border-[#8b102e] text-[#1e3b8b] font-serif rounded-lg text-[10px] font-bold flex items-center gap-1 shadow-sm transition-colors"
                                      >
                                        {getIcon(l.icon)}
                                        <span>{l.title}</span>
                                      </button>
                                    ))}
                                    {backlinks.map(l => (
                                      <button
                                        key={l.id}
                                        onClick={() => {
                                          navigate(`/organizacion/${tenantSlug}/${l.slug}`);
                                          setMobileView('content');
                                        }}
                                        className="px-2.5 py-1 bg-[#faf6eb] border border-[#d2c7ac] hover:bg-white hover:border-[#8b102e] text-slate-700 font-serif rounded-lg text-[10px] font-bold flex items-center gap-1 shadow-sm transition-colors"
                                      >
                                        <Globe size={10} />
                                        <span>{l.title}</span>
                                      </button>
                                    ))}
                                  </div>
                                </div>
                              )}
                            </div>
                          )}
                        </div>
                      </div>
                    )}

                  </div>

                  {/* Ribbon bookmark / page footer */}
                  <div className="border-t border-[#d8ccb6] pt-3 mt-4 text-[9px] text-[#4a0717]/40 font-sans font-bold flex justify-between">
                    <span>Todo para la repostería</span>
                    <span>PÁG. R</span>
                  </div>
                </div>

              </div>

            </div>
          )}

          {/* Public Footer */}
          <div className="mt-6 text-amber-100/40 text-[9px] font-black uppercase tracking-[0.2em] font-sans">
            La Receta Secreta • Desarrollado por Talent 360
          </div>
        </div>
      )}

      {/* FLOATING GOLD TALISMAN FOR AI ASSISTANT */}
      {isOpen && !loading && (
        currentUser?.role === 'admin' || 
        currentUser?.role === 'supervisor' || 
        (currentUser?.job_role_name && (
          currentUser.job_role_name.toLowerCase().includes('administrador') || 
          currentUser.job_role_name.toLowerCase().includes('gerente')
        ))
      ) && (
        <button 
          onClick={() => setShowAiAssistant(true)}
          className="fixed bottom-6 right-6 w-16 h-16 rounded-full bg-gradient-to-br from-[#bf953f] via-[#fcf6ba] to-[#aa771c] text-[#3d1b13] flex flex-col items-center justify-center shadow-2xl border-2 border-[#1c0808] hover:scale-105 transition-transform z-40 cursor-pointer animate-bounce-subtle"
          title="Preguntar a la IA por Voz"
        >
          <Mic size={22} className="text-[#3d1b13]" />
          <span className="text-[8px] font-black tracking-wider uppercase font-sans mt-0.5 leading-none">Oráculo</span>
        </button>
      )}

      {/* 🌟 MILESTONE 50% MOTIVATIONAL MODAL */}
      {showMilestone50 && (
        <div className="fixed inset-0 bg-black/85 backdrop-blur-md z-50 flex items-center justify-center p-4">
          <div className="bg-[#f6ecda] w-full max-w-sm rounded-3xl p-6 relative border-4 border-[#b38728] shadow-2xl flex flex-col text-center items-center justify-center">
            <GoldenCorners />
            <Sparkles size={36} className="text-[#b38728] mb-3 animate-bounce" />
            <h3 className="font-serif text-lg font-black text-[#4a0717]">¡Excelente Progreso!</h3>
            <p className="text-xs text-slate-600 font-bold font-sans leading-relaxed mt-2">
              Llevas el <strong>50%</strong> del manual leído. Te motivamos a seguir leyendo y apoyarnos para completar todo lo que es este contenido.
            </p>
            <button
              type="button"
              onClick={() => setShowMilestone50(false)}
              className="mt-5 px-6 py-2 rounded-xl bg-gradient-to-r from-[#bf953f] to-[#aa771c] text-[#3d1b13] font-black font-sans text-xs tracking-wider uppercase shadow-md hover:scale-105 transition-transform cursor-pointer"
            >
              ¡Entendido, continuaré!
            </button>
          </div>
        </div>
      )}

      {/* 🎉 MILESTONE 95% GRATITUDE MODAL */}
      {showMilestone95 && (
        <div className="fixed inset-0 bg-black/85 backdrop-blur-md z-50 flex items-center justify-center p-4">
          <div className="bg-[#f6ecda] w-full max-w-sm rounded-3xl p-6 relative border-4 border-[#b38728] shadow-2xl flex flex-col text-center items-center justify-center">
            <GoldenCorners />
            <Sparkles size={36} className="text-emerald-700 mb-3 animate-bounce" />
            <h3 className="font-serif text-lg font-black text-emerald-800">¡Gran Logro!</h3>
            <p className="text-xs text-slate-600 font-bold font-sans leading-relaxed mt-2">
              Llevas el <strong>95%</strong> de la lectura del manual. Agradecemos mucho tu tiempo para poder visualizar el contenido y capacitarte en nuestros procesos.
            </p>
            <button
              type="button"
              onClick={() => setShowMilestone95(false)}
              className="mt-5 px-6 py-2 rounded-xl bg-gradient-to-r from-[#bf953f] to-[#aa771c] text-[#3d1b13] font-black font-sans text-xs tracking-wider uppercase shadow-md hover:scale-105 transition-transform cursor-pointer"
            >
              Cerrar y Finalizar
            </button>
          </div>
        </div>
      )}

    </div>
  );
}
