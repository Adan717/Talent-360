import React from 'react';
import { 
  ShieldCheck,
  Crown,
  Scale,
  BarChart3,
  Workflow,
  UserCheck,
  ClipboardCheck,
  Clock,
  Building2, 
  Store, 
  ShoppingBag, 
  CreditCard, 
  Package, 
  Truck, 
  UserPlus, 
  Palette, 
  Code, 
  Calculator, 
  Utensils, 
  Wrench, 
  Headset, 
  Shield, 
  GraduationCap, 
  Briefcase,
  Sparkles,
  Award,
  Compass,
  Landmark,
  Eye,
  Coffee,
  DollarSign,
  Factory,
  ShoppingCart,
  TrendingUp,
  Layers,
  Target,
  FileSpreadsheet,
  BadgeCheck,
  Coins
} from 'lucide-react';

export interface JobRoleIconOption {
  key: string;
  label: string;
  category: string;
  industry: string;
}

export const JOB_ROLE_ICON_OPTIONS: JobRoleIconOption[] = [
  { key: 'auto', label: 'Auto (Sugerido por título de puesto)', category: 'General', industry: 'all' },
  
  // Personajes Monitos Alusivos - Giro: Decorarte 360 & Artesanal
  { key: 'monito-gerente', label: '👨‍💼 Monito Ejecutivo con Corbata 👔 (Administrador Gerente)', category: 'Decorarte 360 / Nivel 1', industry: 'decorarte' },
  { key: 'monito-compras', label: '👨‍💼 Monito Comprador con Billetes 💵 (Supervisor de Compras)', category: 'Decorarte 360 / Nivel 2', industry: 'decorarte' },
  { key: 'monito-ventas', label: '👨‍💼 Monito Comercial con Caja Registradora 🧾 (Supervisor de Ventas)', category: 'Decorarte 360 / Nivel 2', industry: 'decorarte' },
  { key: 'monito-produccion', label: '👨‍🔧 Monito Inspector con Tablilla Checklist 📋 (Supervisor de Producción)', category: 'Decorarte 360 / Nivel 2', industry: 'decorarte' },
  { key: 'monito-asesor', label: '👥 Monito Atendiendo a Cliente 🤝 (Asesor de Ventas)', category: 'Decorarte 360 / Nivel 3', industry: 'decorarte' },
  { key: 'monito-ayudante', label: '🛠️ Monito Trabajador en Acción (Ayudante Integral)', category: 'Decorarte 360 / Nivel 3', industry: 'decorarte' },
  { key: 'monito-eventual', label: '⏱️ Monito Auxiliar con Reloj (Apoyo Eventual)', category: 'Decorarte 360 / Nivel 4', industry: 'decorarte' },

  // Personajes Monitos Alusivos - Giro: Retail & Comercio
  { key: 'monito-cajero', label: '💳 Monito Cajero en Módulo de Cobro (Cajero / Cajas)', category: 'Retail / Comercio', industry: 'retail' },
  { key: 'monito-almacenista', label: '📦 Monito Almacenista Cargando Caja (Almacén / Inventario)', category: 'Retail / Comercio', industry: 'retail' },
  { key: 'monito-chofer', label: '🚚 Monito Conductor en Reparto (Chofer / Logística)', category: 'Retail / Comercio', industry: 'retail' },

  // Personajes Monitos Alusivos - Giro: Oficina & Corporativo
  { key: 'monito-rh', label: '👤+ Monito Entrevistador de Talento (Recursos Humanos)', category: 'Oficina / Corporativo', industry: 'oficina' },
  { key: 'monito-contador', label: '🧮 Monito Financiero con Calculadora (Contador / Finanzas)', category: 'Oficina / Corporativo', industry: 'oficina' },
  { key: 'monito-recepcionista', label: '🎧 Monito Recepcionista en Conmutador (Recepción)', category: 'Oficina / Corporativo', industry: 'oficina' },

  // Personajes Monitos Alusivos - Giro: Tecnología & Creativo
  { key: 'monito-programador', label: '💻 Monito Desarrollador con Laptop (Programador / TI)', category: 'Tecnología', industry: 'tecnologia' },
  { key: 'monito-disenador', label: '🎨 Monito Creador con Paleta de Arte (Diseñador / Creativo)', category: 'Tecnología', industry: 'tecnologia' },

  // Personajes Monitos Alusivos - Giro: Restaurantes & Gastronomía
  { key: 'monito-chef', label: '🍳 Monito Chef con Gorro y Sartén (Chef / Cocinero)', category: 'Restaurantes', industry: 'restaurante' },
  { key: 'monito-mesero', label: '🍽️ Monito Mesero con Bandeja de Servicio (Mesero)', category: 'Restaurantes', industry: 'restaurante' },

  // Personajes Monitos Alusivos - Giro: Planta & Manufactura / Servicios
  { key: 'monito-mantenimiento', label: '🔧 Monito Técnico con Herramientas (Mantenimiento)', category: 'Servicios', industry: 'manufactura' },
  { key: 'monito-guardia', label: '🛡️ Monito Vigilante con Escudo (Seguridad)', category: 'Servicios', industry: 'servicios' },
  { key: 'monito-capacitador', label: '🎓 Monito Instructor en Pizarrón (Capacitador / Docente)', category: 'Servicios', industry: 'servicios' },
];

/**
 * Componente Vectorial "Monito Alusivo" con accesorios característicos según el puesto de trabajo.
 */
export const MonitoCharacterBadge: React.FC<{
  type: string;
  size?: number;
  className?: string;
}> = ({ type, size = 24, className = '' }) => {
  switch (type) {
    case 'monito-gerente':
    case 'shield-check':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="12" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M4.5 20.5c0-3.8 3.3-6.5 7.5-6.5s7.5 2.7 7.5 6.5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <path d="M12 14l-1.3 2 1.3 4.5 1.3-4.5L12 14z" fill="currentColor" />
          <path d="M10 14l2 1.5 2-1.5" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" />
        </svg>
      );

    case 'monito-compras':
    case 'scale':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="9" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M3 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <rect x="13.5" y="10" width="8.5" height="5.5" rx="1" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.2" />
          <circle cx="17.7" cy="12.7" r="1.2" fill="currentColor" />
          <path d="M15.5 10v5.5M20 10v5.5" stroke="currentColor" strokeWidth="1" strokeLinecap="round" />
        </svg>
      );

    case 'monito-ventas':
    case 'bar-chart-3':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <rect x="13" y="11" width="9.5" height="7.5" rx="1.5" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.15" />
          <path d="M15.5 8h4.5v3h-4.5V8z" stroke="currentColor" strokeWidth="1.2" fill="none" />
          <path d="M15 15h1.5m2 0h1.5m-3.5 2h3.5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
      );

    case 'monito-produccion':
    case 'workflow':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="7" r="3.2" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M5 6.5c0-2 1.5-3.5 3.5-3.5s3.5 1.5 3.5 3.5H5z" fill="currentColor" fillOpacity="0.3" stroke="currentColor" strokeWidth="1.2" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <rect x="13.5" y="8.5" width="8" height="10" rx="1.2" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.15" />
          <path d="M16 7h3v2h-3V7z" fill="currentColor" />
          <path d="M15.5 12l1 1.2 2.2-2.2M15.5 15.5h4" stroke="currentColor" strokeWidth="1.3" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      );

    case 'monito-asesor':
    case 'user-check':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="7" cy="6" r="3" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2 19.5c0-3 2.2-5.5 5-5.5s5 2.5 5 5.5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <circle cx="17" cy="7.5" r="2.5" stroke="currentColor" strokeWidth="1.8" opacity="0.85" fill="none" />
          <path d="M13 19.5c0-2.3 1.8-4.3 4-4.3s4 2 4 4.3" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" opacity="0.85" />
          <path d="M10.5 8.5c1-1 3-1 4 0" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
      );

    case 'monito-ayudante':
    case 'clipboard-check':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <path d="M14 13.5l4.5 4.5M16.5 12l2.5 2.5M15.5 11l2 2" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <circle cx="19" cy="11" r="1.5" stroke="currentColor" strokeWidth="1.5" fill="currentColor" fillOpacity="0.3" />
        </svg>
      );

    case 'monito-eventual':
    case 'clock':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <circle cx="17.5" cy="14.5" r="4.5" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.2" />
          <path d="M17.5 12v2.5h2" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
      );

    case 'monito-cajero':
    case 'credit-card':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <rect x="13.5" y="10.5" width="8.5" height="6" rx="1" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.2" />
          <path d="M13.5 12.5h8.5M15.5 15h2" stroke="currentColor" strokeWidth="1.2" />
        </svg>
      );

    case 'monito-almacenista':
    case 'package':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <path d="M14 11l4-2 4 2v6l-4 2-4-2v-6z" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.15" />
          <path d="M18 9v8M14 11l4 2 4-2" stroke="currentColor" strokeWidth="1.2" />
        </svg>
      );

    case 'monito-chofer':
    case 'truck':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="7.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M1.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Volante de conducción 🚚 */}
          <circle cx="17.5" cy="14" r="3.5" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.15" />
          <circle cx="17.5" cy="14" r="1" fill="currentColor" />
          <path d="M17.5 10.5v2.5M14 14h2.5M18.5 14H21" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" />
        </svg>
      );

    case 'monito-rh':
    case 'user-plus':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Expediente de candidato / Reclutamiento 👤+ */}
          <circle cx="17.5" cy="11.5" r="2.5" stroke="currentColor" strokeWidth="1.8" />
          <path d="M17.5 9v5M15 11.5h5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
          <path d="M14 19c0-2 1.5-3.5 3.5-3.5s3.5 1.5 3.5 3.5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
      );

    case 'monito-disenador':
    case 'palette':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Paleta de pintura y pincel 🎨 */}
          <circle cx="17.5" cy="14" r="4" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.15" />
          <circle cx="16" cy="13" r="0.8" fill="currentColor" />
          <circle cx="18.5" cy="13" r="0.8" fill="currentColor" />
          <circle cx="17" cy="15.5" r="0.8" fill="currentColor" />
        </svg>
      );

    case 'monito-programador':
    case 'code':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Laptop con código 💻 */}
          <rect x="13.5" y="10" width="8.5" height="5.5" rx="1" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.15" />
          <path d="M12.5 17.5h10.5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
          <path d="M15.5 12l-1 1 1 1M20 12l1 1-1 1" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      );

    case 'monito-contador':
    case 'calculator':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Calculadora financiera 🧮 */}
          <rect x="14" y="9.5" width="7.5" height="10" rx="1.2" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.15" />
          <rect x="15.5" y="11" width="4.5" height="2" rx="0.5" fill="currentColor" />
          <path d="M16 15h1m1.5 0h1m-3.5 2.5h1m1.5 0h1" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" />
        </svg>
      );

    case 'monito-chef':
    case 'utensils':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="7.5" r="3" stroke="currentColor" strokeWidth="2" fill="none" />
          {/* Gorro de Chef 🍳 */}
          <path d="M5.5 7c-1-1.5 0-3.5 1.5-3.5 0.8 0 1.5 0.5 1.8 1.2 0.5-0.8 1.5-1.2 2.2-0.8 0.8 0.4 1.2 1.3 1 2.1H5.5z" fill="currentColor" fillOpacity="0.3" stroke="currentColor" strokeWidth="1.2" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Sartén / Plato de servicio */}
          <circle cx="17.5" cy="14" r="3" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.2" />
          <path d="M20.5 14h2.5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
        </svg>
      );

    case 'monito-mesero':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Bandeja de servicio 🍽️ */}
          <path d="M13.5 14h8.5M14 14c0-2.5 1.8-4 3.75-4S21.5 11.5 21.5 14" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.15" />
          <circle cx="17.7.5" cy="9.5" r="0.8" fill="currentColor" />
        </svg>
      );

    case 'monito-mantenimiento':
    case 'wrench':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Llave de tuercas 🔧 */}
          <path d="M15 12l4 4M18.5 10.5l2 2" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" />
          <circle cx="15.5" cy="11" r="2" stroke="currentColor" strokeWidth="1.8" />
        </svg>
      );

    case 'monito-guardia':
    case 'shield':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="7" r="3.2" stroke="currentColor" strokeWidth="2" fill="none" />
          {/* Gorra de seguridad */}
          <path d="M5 6.5h7c0-1.8-1.5-3-3.5-3S5 4.7 5 6.5z" fill="currentColor" fillOpacity="0.3" stroke="currentColor" strokeWidth="1.2" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Escudo de protección 🛡️ */}
          <path d="M14.5 10h6v3.5c0 3-3 5-3 5s-3-2-3-5V10z" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.2" />
        </svg>
      );

    case 'monito-capacitador':
    case 'graduation-cap':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Pizarrón / Birrete de capacitación 🎓 */}
          <rect x="14" y="9.5" width="8" height="9" rx="1" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.15" />
          <path d="M16 12h4M16 15h2" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" />
        </svg>
      );

    case 'monito-recepcionista':
    case 'headset':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          {/* Audífonos con micrófono 🎧 */}
          <path d="M4.5 6.5c0-2.2 1.8-4 4-4s4 1.8 4 4" stroke="currentColor" strokeWidth="1.5" />
          <rect x="3.5" y="5.5" width="2" height="3" rx="1" fill="currentColor" />
          <rect x="11.5" y="5.5" width="2" height="3" rx="1" fill="currentColor" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Conmutador / Teléfono en escritorio */}
          <rect x="14.5" y="12" width="7" height="5" rx="1" stroke="currentColor" strokeWidth="1.5" fill="currentColor" fillOpacity="0.2" />
        </svg>
      );

    default:
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="12" cy="7" r="4" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M4 21c0-4 3.5-7 8-7s8 3 8 7" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
        </svg>
      );
  }
};

/**
 * Resuelve la clave de icono ejecutiva de Monitos Alusivos adecuada basada en el puesto y jerarquía.
 */
export function resolveJobRoleIconKey(role?: { name?: string; area?: string; icon?: string } | null): string {
  if (!role) return 'briefcase';
  
  if (role.icon && role.icon !== 'auto' && role.icon.trim() !== '') {
    return role.icon.toLowerCase().trim();
  }

  const name = (role.name || '').toLowerCase();
  const area = (role.area || '').toLowerCase();
  const text = `${name} ${area}`;

  // 1. Personajes Monitos Alusivos de Decorarte 360 & Corporativo
  if (text.includes('administrador gerente') || text.includes('gerente general') || text.includes('director general') || text.includes('ceo') || text.includes('presiden')) {
    return 'monito-gerente';
  }
  if (text.includes('supervisor de compras') || text.includes('compras') || text.includes('adquisicion')) {
    return 'monito-compras';
  }
  if (text.includes('supervisor de ventas') || text.includes('jefe de ventas') || text.includes('coordinador de ventas')) {
    return 'monito-ventas';
  }
  if (text.includes('supervisor de producción') || text.includes('supervisor de produccion') || text.includes('producción') || text.includes('produccion') || text.includes('taller') || text.includes('manufactura')) {
    return 'monito-produccion';
  }
  if (text.includes('asesor de ventas') || text.includes('asesor de venta') || text.includes('asesor comercial') || text.includes('atención al cliente') || text.includes('atencion al cliente')) {
    return 'monito-asesor';
  }
  if (text.includes('ayudante integral') || text.includes('ayudante de piso') || text.includes('soporte operativo')) {
    return 'monito-ayudante';
  }
  if (text.includes('apoyo eventual') || text.includes('eventual') || text.includes('auxiliar eventual')) {
    return 'monito-eventual';
  }

  // 2. Personajes Monitos por Giro (Retail, Oficina, Restaurante, Tecnología, Servicios)
  if (text.includes('cajer') || text.includes('caja') || text.includes('tesorer') || text.includes('supervisor de cajas')) {
    return 'monito-cajero';
  }
  if (text.includes('almacen') || text.includes('bodeg') || text.includes('inventari') || text.includes('surtid')) {
    return 'monito-almacenista';
  }
  if (text.includes('chofer') || text.includes('repartid') || text.includes('conduct') || text.includes('transport') || text.includes('envio')) {
    return 'monito-chofer';
  }
  if (text.includes('reclut') || text.includes('recursos humanos') || text.includes('rh') || text.includes('capital humano') || text.includes('talent')) {
    return 'monito-rh';
  }
  if (text.includes('programad') || text.includes('desarrollad') || text.includes('sistemas') || text.includes('ti') || text.includes('software')) {
    return 'monito-programador';
  }
  if (text.includes('diseñ') || text.includes('creativ') || text.includes('marketing') || text.includes('arte')) {
    return 'monito-disenador';
  }
  if (text.includes('contad') || text.includes('finanz') || text.includes('nomin') || text.includes('audit')) {
    return 'monito-contador';
  }
  if (text.includes('chef') || text.includes('cocin')) {
    return 'monito-chef';
  }
  if (text.includes('meser') || text.includes('barista') || text.includes('restauran')) {
    return 'monito-mesero';
  }
  if (text.includes('limpieza') || text.includes('intendenc') || text.includes('mantenimi') || text.includes('tecnic')) {
    return 'monito-mantenimiento';
  }
  if (text.includes('recepcio') || text.includes('conmutad')) {
    return 'monito-recepcionista';
  }
  if (text.includes('guardia') || text.includes('seguridad') || text.includes('vigilant')) {
    return 'monito-guardia';
  }
  if (text.includes('capacit') || text.includes('entrenad') || text.includes('instruct') || text.includes('docent')) {
    return 'monito-capacitador';
  }

  // 3. Fallbacks Generales
  if (text.includes('director') || text.includes('subdirector') || text.includes('gerencia regional')) {
    return 'building-2';
  }
  if (text.includes('gerente de sucursal') || text.includes('gerente de tienda') || text.includes('encargad') || text.includes('sucursal') || text.includes('tienda')) {
    return 'store';
  }
  if (text.includes('vended') || text.includes('venta') || text.includes('comercial') || text.includes('mostrador') || text.includes('promotor')) {
    return 'shopping-bag';
  }

  return 'briefcase';
}

/**
 * Devuelve el elemento de Monito Alusivo u icono Lucide correspondiente.
 */
export function renderJobRoleIcon(
  keyOrRole: string | { name?: string; area?: string; icon?: string } | null,
  size: number = 22,
  className?: string
): React.ReactElement {
  const iconKey = typeof keyOrRole === 'string' ? keyOrRole : resolveJobRoleIconKey(keyOrRole);

  // Si es un personaje Monito Alusivo
  if (iconKey.startsWith('monito-') || iconKey === 'shield-check' || iconKey === 'scale' || iconKey === 'bar-chart-3' || iconKey === 'workflow' || iconKey === 'user-check' || iconKey === 'clipboard-check' || iconKey === 'clock') {
    return <MonitoCharacterBadge type={iconKey} size={size} className={className} />;
  }

  const props = { size, className: className || '' };

  switch (iconKey) {
    case 'crown':
      return <Crown {...props} />;
    case 'shopping-cart':
      return <ShoppingCart {...props} />;
    case 'trending-up':
      return <TrendingUp {...props} />;
    case 'factory':
      return <Factory {...props} />;
    case 'building-2':
      return <Building2 {...props} />;
    case 'store':
      return <Store {...props} />;
    case 'shopping-bag':
      return <ShoppingBag {...props} />;
    case 'credit-card':
      return <CreditCard {...props} />;
    case 'package':
      return <Package {...props} />;
    case 'truck':
      return <Truck {...props} />;
    case 'user-plus':
      return <UserPlus {...props} />;
    case 'palette':
      return <Palette {...props} />;
    case 'code':
      return <Code {...props} />;
    case 'calculator':
      return <Calculator {...props} />;
    case 'utensils':
      return <Utensils {...props} />;
    case 'wrench':
      return <Wrench {...props} />;
    case 'headset':
      return <Headset {...props} />;
    case 'shield':
      return <Shield {...props} />;
    case 'graduation-cap':
      return <GraduationCap {...props} />;
    case 'sparkles':
      return <Sparkles {...props} />;
    case 'award':
      return <Award {...props} />;
    case 'compass':
      return <Compass {...props} />;
    case 'landmark':
      return <Landmark {...props} />;
    case 'eye':
      return <Eye {...props} />;
    case 'coffee':
      return <Coffee {...props} />;
    case 'dollar-sign':
      return <DollarSign {...props} />;
    case 'layers':
      return <Layers {...props} />;
    case 'target':
      return <Target {...props} />;
    case 'file-spreadsheet':
      return <FileSpreadsheet {...props} />;
    case 'badge-check':
      return <BadgeCheck {...props} />;
    case 'coins':
      return <Coins {...props} />;
    case 'briefcase':
    default:
      return <Briefcase {...props} />;
  }
}

interface JobRoleIconProps {
  role?: { name?: string; area?: string; icon?: string; nivel_mando?: number } | null;
  iconKey?: string;
  size?: number;
  className?: string;
  containerClassName?: string;
  isActive?: boolean;
}

/**
 * Componente Ficha Icono de Puesto con personajes Monitos Alusivos y distinciones jerárquicas de mando.
 */
export const JobRoleIconBadge: React.FC<JobRoleIconProps> = ({
  role,
  iconKey,
  size = 24,
  className,
  containerClassName,
  isActive = true
}) => {
  const key = iconKey || resolveJobRoleIconKey(role);
  const nivel = role?.nivel_mando ?? (
    key === 'monito-gerente' || key === 'shield-check' || key === 'crown' ? 1 :
    key === 'monito-compras' || key === 'monito-ventas' || key === 'monito-produccion' || key === 'scale' || key === 'bar-chart-3' || key === 'workflow' ? 2 :
    key === 'monito-asesor' || key === 'monito-ayudante' || key === 'user-check' || key === 'clipboard-check' ? 3 : 4
  );

  // Paleta de colores ejecutivos y jerárquicos
  const hierarchyStyles: Record<number, { bg: string; text: string; ring: string; levelBadge: string }> = {
    1: { 
      bg: 'bg-indigo-950/90 border-indigo-700/80 shadow-md', 
      text: 'text-amber-400', 
      ring: 'ring-2 ring-amber-400/50',
      levelBadge: 'N1 - Dirección General'
    },
    2: { 
      bg: 'bg-slate-900/85 border-slate-700 shadow-sm', 
      text: 'text-indigo-400', 
      ring: 'ring-1 ring-indigo-400/40',
      levelBadge: 'N2 - Supervisión / Jefatura'
    },
    3: { 
      bg: 'bg-slate-100/90 border-slate-300', 
      text: 'text-slate-700', 
      ring: 'border-slate-250',
      levelBadge: 'N3 - Especialista / Piso'
    },
    4: { 
      bg: 'bg-slate-50 border-slate-200', 
      text: 'text-slate-600', 
      ring: 'border-slate-150',
      levelBadge: 'N4 - Auxiliar / Apoyo'
    },
    5: { 
      bg: 'bg-slate-50 border-slate-200', 
      text: 'text-slate-500', 
      ring: 'border-slate-150',
      levelBadge: 'N5 - Apoyo Eventual'
    },
  };

  const levelStyle = hierarchyStyles[nivel] || hierarchyStyles[4];
  const activeBg = isActive ? levelStyle.bg : 'bg-slate-100 border-slate-200 opacity-60';
  const activeText = isActive ? levelStyle.text : 'text-slate-400';

  return (
    <div className="relative group shrink-0">
      <div
        className={`w-12 h-12 rounded-2xl border flex items-center justify-center transition-all duration-200 ${activeBg} ${levelStyle.ring} ${containerClassName || ''}`}
        title={`Puesto: ${role?.name || ''} (${levelStyle.levelBadge})`}
      >
        {renderJobRoleIcon(key, size, `${activeText} ${className || ''}`)}
      </div>
    </div>
  );
};
