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
  Coins,
  Stethoscope,
  Gavel,
  Zap,
  Scissors,
  Ruler,
  Car,
  PenTool,
  BookOpen
} from 'lucide-react';

export interface ProfessionMatrixItem {
  key: string;
  profession: string;
  category: string;
  accessory: string;
  industry: string;
  nivel_mando: number;
  keywords: string[];
}

/**
 * MATRIZ MAESTRA UNIVERSAL DE ICONOS POR PROFESIONES, OFICIOS Y JERARQUÍAS
 */
export const JOB_ROLE_PROFESSIONS_MATRIX: ProfessionMatrixItem[] = [
  // --- 🎨 1. GIRO: DECORARTE 360 & ARTESANAL ---
  {
    key: 'monito-gerente',
    profession: 'Administrador Gerente',
    category: 'Decorarte 360',
    accessory: 'Traje y Corbata 👔 + Placa Ejecutiva',
    industry: 'decorarte',
    nivel_mando: 1,
    keywords: ['administrador gerente', 'gerente general', 'director general', 'ceo', 'presidente', 'coordinador general']
  },
  {
    key: 'monito-compras',
    profession: 'Supervisor de Compras',
    category: 'Decorarte 360',
    accessory: 'Fajo de Billetes 💵 + Orden de Compra',
    industry: 'decorarte',
    nivel_mando: 2,
    keywords: ['supervisor de compras', 'compras', 'adquisicion', 'proveedores', 'surtimiento']
  },
  {
    key: 'monito-ventas',
    profession: 'Supervisor de Ventas',
    category: 'Decorarte 360',
    accessory: 'Caja Registradora / Terminal de Cobro 🧾',
    industry: 'decorarte',
    nivel_mando: 2,
    keywords: ['supervisor de ventas', 'jefe de ventas', 'coordinador comercial']
  },
  {
    key: 'monito-produccion',
    profession: 'Supervisor de Producción',
    category: 'Decorarte 360',
    accessory: 'Casco de Protección 👷 + Tablilla Checklist 📋',
    industry: 'decorarte',
    nivel_mando: 2,
    keywords: ['supervisor de producción', 'supervisor de produccion', 'producción', 'produccion', 'taller', 'manufactura', 'inspeccion']
  },
  {
    key: 'monito-asesor',
    profession: 'Asesor de Ventas',
    category: 'Decorarte 360',
    accessory: 'Atención Cara a Cara a Cliente 👥💬',
    industry: 'decorarte',
    nivel_mando: 3,
    keywords: ['asesor de ventas', 'asesor comercial', 'atención al cliente', 'atencion al cliente', 'servicio al cliente']
  },
  {
    key: 'monito-ayudante',
    profession: 'Ayudante Integral',
    category: 'Decorarte 360',
    accessory: 'Herramientas de Trabajo Activo 🛠️',
    industry: 'decorarte',
    nivel_mando: 3,
    keywords: ['ayudante integral', 'ayudante de piso', 'operativo', 'ensamblador']
  },
  {
    key: 'monito-eventual',
    profession: 'Apoyo Eventual',
    category: 'Decorarte 360',
    accessory: 'Reloj de Jornada Temporal ⏱️',
    industry: 'decorarte',
    nivel_mando: 4,
    keywords: ['apoyo eventual', 'eventual', 'cobertura', 'auxiliar temporal']
  },

  // --- 🚗 2. GIRO: AUTOMOTRIZ & TALLERES MECÁNICOS ---
  {
    key: 'monito-gerente-taller',
    profession: 'Gerente de Taller Automotriz',
    category: 'Automotriz',
    accessory: 'Llave Inglesa 🔧 + Maletín Ejecutivo 💼',
    industry: 'automotriz',
    nivel_mando: 1,
    keywords: ['gerente de taller', 'director de taller', 'jefe de taller mecánico']
  },
  {
    key: 'monito-mecanico',
    profession: 'Mecánico Automotriz',
    category: 'Automotriz',
    accessory: 'Llave de Motor & Diagnóstico 🔧🚗',
    industry: 'automotriz',
    nivel_mando: 2,
    keywords: ['mecanico', 'mecanica', 'mecanico automotriz', 'tecnico automotriz', 'mecanico especialista']
  },
  {
    key: 'monito-electricista-auto',
    profession: 'Técnico Autoelectrónico',
    category: 'Automotriz',
    accessory: 'Rayo Eléctrico ⚡ + Diagnóstico',
    industry: 'automotriz',
    nivel_mando: 2,
    keywords: ['electricista automotriz', 'autoelectrico', 'tecnico en escaner', 'diagnostico electronico']
  },
  {
    key: 'monito-ayudante-mecanico',
    profession: 'Ayudante de Mecánico',
    category: 'Automotriz',
    accessory: 'Neumático & Llave de Cruz 🛞🛠️',
    industry: 'automotriz',
    nivel_mando: 3,
    keywords: ['ayudante de mecanico', 'ayudante mecanico', 'auxiliar de taller', 'engrasador']
  },

  // --- ⚡ 3. GIRO: SERVICIOS TÉCNICOS & MANTENIMIENTO ---
  {
    key: 'monito-electricista',
    profession: 'Electricista Residencial e Industrial',
    category: 'Servicios Técnicos',
    accessory: 'Casco Dieléctrico & Rayo Eléctrico ⚡👷',
    industry: 'servicios',
    nivel_mando: 2,
    keywords: ['electricista', 'electrico', 'tecnico electricista', 'instalador electrico']
  },
  {
    key: 'monito-plomero',
    profession: 'Técnico Plomero & Fontanero',
    category: 'Servicios Técnicos',
    accessory: 'Tubería & Llave de Tubo 🚰🔧',
    industry: 'servicios',
    nivel_mando: 2,
    keywords: ['plomero', 'fontanero', 'tecnico plomero', 'instalador de tuberia']
  },
  {
    key: 'monito-climas',
    profession: 'Técnico en Refrigeración & Climas',
    category: 'Servicios Técnicos',
    accessory: 'Aire Acondicionado & Nieve ❄️🔧',
    industry: 'servicios',
    nivel_mando: 2,
    keywords: ['climas', 'refrigeracion', 'aire acondicionado', 'hvac', 'tecnico en climas']
  },

  // --- ⚖️ 4. GIRO: JURÍDICO & SERVICIOS LEGALES ---
  {
    key: 'monito-legal',
    profession: 'Abogado Socio / Director Legal',
    category: 'Servicios Legales',
    accessory: 'Mazo de Justicia ⚖️ + Toga / Traje 👔',
    industry: 'legal',
    nivel_mando: 1,
    keywords: ['abogado socio', 'director legal', 'socio del despacho', 'notario']
  },
  {
    key: 'monito-abogado-senior',
    profession: 'Abogado Litigante Senior',
    category: 'Servicios Legales',
    accessory: 'Expediente Jurídico & Maletín 💼⚖️',
    industry: 'legal',
    nivel_mando: 2,
    keywords: ['abogado', 'abogada', 'litigante', 'asesor juridico', 'consultor legal']
  },
  {
    key: 'monito-asistente-legal',
    profession: 'Asistente Legal / Secretario de Despacho',
    category: 'Servicios Legales',
    accessory: 'Folios Legal & Pluma 📋⚖️',
    industry: 'legal',
    nivel_mando: 3,
    keywords: ['asistente legal', 'secretario legal', 'secretario judicial', 'asistente juridico']
  },
  {
    key: 'monito-pasante-derecho',
    profession: 'Pasante de Derecho',
    category: 'Servicios Legales',
    accessory: 'Libro de Leyes & Notificaciones 📚⏱️',
    industry: 'legal',
    nivel_mando: 4,
    keywords: ['pasante de derecho', 'pasante legal', 'auxiliar juridico', 'notificador']
  },

  // --- 🏥 5. GIRO: SALUD & CLÍNICAS ---
  {
    key: 'monito-director-medico',
    profession: 'Director Médico / Cirujano Jefe',
    category: 'Salud & Medicina',
    accessory: 'Estetoscopio 🩺 + Traje Ejecutivo 👔',
    industry: 'salud',
    nivel_mando: 1,
    keywords: ['director medico', 'director de clinica', 'cirujano jefe', 'jefe de medicina']
  },
  {
    key: 'monito-salud',
    profession: 'Médico Especialista / General',
    category: 'Salud & Medicina',
    accessory: 'Estetoscopio & Bata Médica 🩺',
    industry: 'salud',
    nivel_mando: 2,
    keywords: ['medico', 'médico', 'doctor', 'doctora', 'odontologo', 'dentista', 'pediatra']
  },
  {
    key: 'monito-enfermero',
    profession: 'Enfermero(a) / Urgencias',
    category: 'Salud & Medicina',
    accessory: 'Cruz Médica & Jeringa 💉🏥',
    industry: 'salud',
    nivel_mando: 3,
    keywords: ['enfermero', 'enfermera', 'paramedico', 'tecnico en urgencias', 'auxiliar de enfermeria']
  },
  {
    key: 'monito-asistente-medico',
    profession: 'Asistente Médico / Expedientes',
    category: 'Salud & Medicina',
    accessory: 'Expediente Clínico 📋🩺',
    industry: 'salud',
    nivel_mando: 4,
    keywords: ['asistente medico', 'recepcionista medica', 'auxiliar de clinica']
  },

  // --- 🏗️ 6. GIRO: CONSTRUCCIÓN & OBRA CIVIL ---
  {
    key: 'monito-arquitecto',
    profession: 'Arquitecto / Director de Obra',
    category: 'Construcción',
    accessory: 'Casco Blanco 👷 + Plano Arquitectónico 📐',
    industry: 'construccion',
    nivel_mando: 1,
    keywords: ['arquitecto', 'arquitecta', 'director de obra', 'gerente de proyecto obra']
  },
  {
    key: 'monito-ingeniero-civil',
    profession: 'Ingeniero Civil / Residente',
    category: 'Construcción',
    accessory: 'Casco Amarillo 👷 + Teodolito / Nivel 📏',
    industry: 'construccion',
    nivel_mando: 2,
    keywords: ['ingeniero civil', 'residente de obra', 'supervisor de obra']
  },
  {
    key: 'monito-maestro-obra',
    profession: 'Maestro de Obra',
    category: 'Construcción',
    accessory: 'Cuchara de Albañil & Casco 🛠️👷',
    industry: 'construccion',
    nivel_mando: 3,
    keywords: ['maestro de obra', 'encargado de cuadrilla', 'cabo de obra']
  },
  {
    key: 'monito-peon',
    profession: 'Albañil / Peón de Obra',
    category: 'Construcción',
    accessory: 'Carretilla & Pala 🛒🔨',
    industry: 'construccion',
    nivel_mando: 4,
    keywords: ['albanil', 'albañil', 'peon', 'ayudante de obra', 'fierrero']
  },

  // --- 🎓 7. GIRO: EDUCACIÓN & COLEGIOS ---
  {
    key: 'monito-director-escolar',
    profession: 'Director Escolar / Rector',
    category: 'Educación',
    accessory: 'Birrete 🎓 + Corbata Executive 👔',
    industry: 'educacion',
    nivel_mando: 1,
    keywords: ['director escolar', 'rector', 'directora escolar', 'decano']
  },
  {
    key: 'monito-capacitador',
    profession: 'Profesor / Docente de Asignatura',
    category: 'Educación',
    accessory: 'Birrete & Pizarrón 🎓🏫',
    industry: 'educacion',
    nivel_mando: 2,
    keywords: ['profesor', 'maestro', 'maestra', 'docente', 'capacitador', 'instructor']
  },
  {
    key: 'monito-prefecto',
    profession: 'Prefecto / Coordinador Académico',
    category: 'Educación',
    accessory: 'Silbato & Lista de Asistencia 📣📋',
    industry: 'educacion',
    nivel_mando: 3,
    keywords: ['prefecto', 'prefecta', 'tutor academico', 'coordinador de disciplina']
  },

  // --- 💇 8. GIRO: BELLEZA, ESTÉTICAS & SPA ---
  {
    key: 'monito-estilista',
    profession: 'Estilista / Barbero / Cosmetóloga',
    category: 'Belleza & Spa',
    accessory: 'Tijeras & Peine ✂️💈',
    industry: 'belleza',
    nivel_mando: 2,
    keywords: ['estilista', 'barbero', 'barbera', 'cosmetologa', 'peluquero', 'peinador']
  },

  // --- 🏪 9. GIRO: RETAIL & COMERCIO ---
  {
    key: 'monito-cajero',
    profession: 'Cajero / Supervisor de Cajas',
    category: 'Comercio & Cajas',
    accessory: 'Módulo de Cobro con Tarjeta 💳',
    industry: 'retail',
    nivel_mando: 2,
    keywords: ['cajero', 'cajera', 'cajas', 'tesoreria', 'cobros']
  },
  {
    key: 'monito-almacenista',
    profession: 'Almacenista / Control de Inventario',
    category: 'Logística & Almacén',
    accessory: 'Caja de Paquete / Carga 📦',
    industry: 'retail',
    nivel_mando: 3,
    keywords: ['almacenista', 'almacen', 'bodega', 'inventario', 'surtidor']
  },
  {
    key: 'monito-chofer',
    profession: 'Chofer & Conductor de Reparto',
    category: 'Transporte & Envíos',
    accessory: 'Volante de Conducción / Camión 🚚',
    industry: 'retail',
    nivel_mando: 3,
    keywords: ['chofer', 'repartidor', 'conductor', 'transporte', 'envios']
  },

  // --- 🏢 10. GIRO: CORPORATIVO, OFICINA & TI ---
  {
    key: 'monito-rh',
    profession: 'Especialista en Capital Humano',
    category: 'Recursos Humanos',
    accessory: 'Expediente de Entrevista 👤+',
    industry: 'oficina',
    nivel_mando: 2,
    keywords: ['recursos humanos', 'rh', 'reclutador', 'capital humano', 'talento']
  },
  {
    key: 'monito-disenador',
    profession: 'Diseñador & Creativo Digital',
    category: 'Diseño & Marketing',
    accessory: 'Paleta de Arte & Pincel 🎨',
    industry: 'tecnologia',
    nivel_mando: 2,
    keywords: ['diseñador', 'disenador', 'creativo', 'marketing', 'arte', 'grafico']
  },
  {
    key: 'monito-programador',
    profession: 'Ingeniero en Software & TI',
    category: 'Tecnología & Software',
    accessory: 'Laptop con Código de Programación 💻',
    industry: 'tecnologia',
    nivel_mando: 2,
    keywords: ['programador', 'desarrollador', 'sistemas', 'software', 'ti', 'dev']
  },
  {
    key: 'monito-contador',
    profession: 'Contador Público & Financiero',
    category: 'Finanzas & Contabilidad',
    accessory: 'Calculadora & Reportes 🧮',
    industry: 'oficina',
    nivel_mando: 2,
    keywords: ['contador', 'contabilidad', 'finanzas', 'auditor', 'nominas']
  },
  {
    key: 'monito-recepcionista',
    profession: 'Recepcionista & Conmutador',
    category: 'Recepción & Atención',
    accessory: 'Diadema con Micrófono 🎧',
    industry: 'oficina',
    nivel_mando: 3,
    keywords: ['recepcionista', 'recepcion', 'conmutador', 'atencion telefonica']
  },

  // --- 🍽️ 11. GIRO: RESTAURANTES & GASTRONOMÍA ---
  {
    key: 'monito-chef',
    profession: 'Chef & Especialista Gastronómico',
    category: 'Gastronomía & Cocina',
    accessory: 'Gorro de Chef & Sartén 🍳',
    industry: 'restaurante',
    nivel_mando: 2,
    keywords: ['chef', 'cocinero', 'cocina', 'gastronomia']
  },
  {
    key: 'monito-mesero',
    profession: 'Mesero & Servicio de Salón',
    category: 'Restaurantes & Servicio',
    accessory: 'Bandeja de Servicio 🍽️',
    industry: 'restaurante',
    nivel_mando: 3,
    keywords: ['mesero', 'mesera', 'barista', 'garrotero']
  },

  // --- 🛡️ 12. GIRO: SEGURIDAD & PREVENCIÓN ---
  {
    key: 'monito-guardia',
    profession: 'Oficial de Seguridad & Prevención',
    category: 'Seguridad & Protección',
    accessory: 'Gorra Oficial & Escudo 🛡️',
    industry: 'servicios',
    nivel_mando: 3,
    keywords: ['guardia', 'seguridad', 'vigilante', 'prevencion']
  }
];

export interface JobRoleIconOption {
  key: string;
  label: string;
  category: string;
  industry: string;
}

export const JOB_ROLE_ICON_OPTIONS: JobRoleIconOption[] = [
  { key: 'auto', label: 'Auto (Sugerido automáticamente por Profesión)', category: 'General', industry: 'all' },
  ...JOB_ROLE_PROFESSIONS_MATRIX.map(p => ({
    key: p.key,
    label: `${p.profession} (${p.accessory})`,
    category: p.category,
    industry: p.industry
  }))
];

/**
 * Componente Vectorial de Monitos Profesionales con Accesorios por Profesión u Oficio.
 */
export const MonitoCharacterBadge: React.FC<{
  type: string;
  size?: number;
  className?: string;
}> = ({ type, size = 24, className = '' }) => {
  switch (type) {
    case 'monito-gerente':
    case 'monito-gerente-taller':
    case 'monito-director-medico':
    case 'monito-director-escolar':
    case 'shield-check':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="12" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M4.5 20.5c0-3.8 3.3-6.5 7.5-6.5s7.5 2.7 7.5 6.5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <path d="M12 14l-1.3 2 1.3 4.5 1.3-4.5L12 14z" fill="currentColor" />
          <path d="M10 14l2 1.5 2-1.5" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" />
        </svg>
      );

    case 'monito-mecanico':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Llave de motor / Auto 🔧🚗 */}
          <path d="M15 11l4 4M18.5 9.5l2 2" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <circle cx="15.5" cy="10" r="1.8" stroke="currentColor" strokeWidth="1.5" />
          <rect x="13.5" y="16" width="8" height="4" rx="1" stroke="currentColor" strokeWidth="1.5" fill="currentColor" fillOpacity="0.2" />
        </svg>
      );

    case 'monito-electricista':
    case 'monito-electricista-auto':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="7" r="3.2" stroke="currentColor" strokeWidth="2" fill="none" />
          {/* Casco Dieléctrico */}
          <path d="M5 6.5c0-2 1.5-3.5 3.5-3.5s3.5 1.5 3.5 3.5H5z" fill="currentColor" fillOpacity="0.3" stroke="currentColor" strokeWidth="1.2" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Rayo Eléctrico ⚡ */}
          <path d="M18 9l-3 5.5h3.5L16 20l5.5-6h-3.5L20 9h-2z" fill="currentColor" />
        </svg>
      );

    case 'monito-plomero':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Tubería & Llave de Tubo 🚰🔧 */}
          <path d="M14 10v4h6v-4" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <path d="M17 14v4.5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <circle cx="17" cy="20" r="1" fill="currentColor" />
        </svg>
      );

    case 'monito-climas':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Copo de Nieve / Aire Acondicionado ❄️ */}
          <path d="M18 10v8M14 14h8M15 11l6 6M21 11l-6 6" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
      );

    case 'monito-ayudante-mecanico':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Neumático & Llave de Cruz 🛞 */}
          <circle cx="17.5" cy="14.5" r="4" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.2" />
          <circle cx="17.5" cy="14.5" r="1.5" stroke="currentColor" strokeWidth="1.2" />
        </svg>
      );

    case 'monito-legal':
    case 'monito-abogado-senior':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Mazo de Justicia ⚖️ */}
          <path d="M14 11l4 4M17.5 9.5l2 2" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" />
          <rect x="13.5" y="15" width="8" height="2.5" rx="1" fill="currentColor" />
        </svg>
      );

    case 'monito-asistente-legal':
    case 'monito-pasante-derecho':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Folio de Expediente Legal 📋⚖️ */}
          <rect x="14" y="9" width="7.5" height="10" rx="1" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.15" />
          <path d="M16 12h3.5M16 15h2" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" />
        </svg>
      );

    case 'monito-arquitecto':
    case 'monito-ingeniero-civil':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="7" r="3.2" stroke="currentColor" strokeWidth="2" fill="none" />
          {/* Casco de Obra */}
          <path d="M5 6.5c0-2 1.5-3.5 3.5-3.5s3.5 1.5 3.5 3.5H5z" fill="currentColor" fillOpacity="0.3" stroke="currentColor" strokeWidth="1.2" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Plano Arquitectónico 📐 */}
          <path d="M14 10l7 7M14 17l7-7" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
        </svg>
      );

    case 'monito-maestro-obra':
    case 'monito-peon':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="7" r="3.2" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M5 6.5c0-2 1.5-3.5 3.5-3.5s3.5 1.5 3.5 3.5H5z" fill="currentColor" fillOpacity="0.3" stroke="currentColor" strokeWidth="1.2" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Cuchara de Albañil / Pala 🛠️ */}
          <path d="M15 17l4-5 2.5 2.5-4 5z" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.2" />
        </svg>
      );

    case 'monito-estilista':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Tijeras & Peine ✂️ */}
          <circle cx="16" cy="11" r="1.2" stroke="currentColor" strokeWidth="1.5" />
          <circle cx="19.5" cy="11" r="1.2" stroke="currentColor" strokeWidth="1.5" />
          <path d="M16.8 12l3.5 6.5M18.7 12l-3.5 6.5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
      );

    case 'monito-salud':
    case 'monito-enfermero':
    case 'monito-asistente-medico':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Estetoscopio / Cruz Médica 🩺 */}
          <path d="M15 10v3c0 1.5 1 2.5 2.5 2.5s2.5-1 2.5-2.5v-3" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
          <circle cx="17.5" cy="18" r="1.5" fill="currentColor" />
        </svg>
      );

    case 'monito-prefecto':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          {/* Silbato de Prefectura 📣 */}
          <path d="M14 12h4v3.5h-4z" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.2" />
          <circle cx="18" cy="13.7" r="1" stroke="currentColor" strokeWidth="1" />
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
          <path d="M5.5 7c-1-1.5 0-3.5 1.5-3.5 0.8 0 1.5 0.5 1.8 1.2 0.5-0.8 1.5-1.2 2.2-0.8 0.8 0.4 1.2 1.3 1 2.1H5.5z" fill="currentColor" fillOpacity="0.3" stroke="currentColor" strokeWidth="1.2" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <circle cx="17.5" cy="14" r="3" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.2" />
          <path d="M20.5 14h2.5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
        </svg>
      );

    case 'monito-mesero':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <path d="M13.5 14h8.5M14 14c0-2.5 1.8-4 3.75-4S21.5 11.5 21.5 14" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.15" />
        </svg>
      );

    case 'monito-mantenimiento':
    case 'wrench':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <path d="M15 12l4 4M18.5 10.5l2 2" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" />
          <circle cx="15.5" cy="11" r="2" stroke="currentColor" strokeWidth="1.8" />
        </svg>
      );

    case 'monito-guardia':
    case 'shield':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="7" r="3.2" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M5 6.5h7c0-1.8-1.5-3-3.5-3S5 4.7 5 6.5z" fill="currentColor" fillOpacity="0.3" stroke="currentColor" strokeWidth="1.2" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <path d="M14.5 10h6v3.5c0 3-3 5-3 5s-3-2-3-5V10z" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.2" />
        </svg>
      );

    case 'monito-capacitador':
    case 'monito-director-escolar':
    case 'graduation-cap':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
          <rect x="14" y="9.5" width="8" height="9" rx="1" stroke="currentColor" strokeWidth="1.8" fill="currentColor" fillOpacity="0.15" />
          <path d="M16 12h4M16 15h2" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" />
        </svg>
      );

    case 'monito-recepcionista':
    case 'headset':
      return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" className={className}>
          <circle cx="8.5" cy="6.5" r="3.5" stroke="currentColor" strokeWidth="2" fill="none" />
          <path d="M4.5 6.5c0-2.2 1.8-4 4-4s4 1.8 4 4" stroke="currentColor" strokeWidth="1.5" />
          <rect x="3.5" y="5.5" width="2" height="3" rx="1" fill="currentColor" />
          <rect x="11.5" y="5.5" width="2" height="3" rx="1" fill="currentColor" />
          <path d="M2.5 20.5c0-3.5 2.7-6 6-6s6 2.5 6 6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
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
 * Resuelve la clave de icono ejecutiva de la Matriz de Profesiones adecuada basada en el puesto y jerarquía.
 */
export function resolveJobRoleIconKey(role?: { name?: string; area?: string; icon?: string } | null): string {
  if (!role) return 'briefcase';
  
  if (role.icon && role.icon !== 'auto' && role.icon.trim() !== '') {
    return role.icon.toLowerCase().trim();
  }

  const name = (role.name || '').toLowerCase();
  const area = (role.area || '').toLowerCase();
  const text = `${name} ${area}`;

  // Búsqueda inteligente por palabras clave en la Matriz Maestra de Profesiones
  for (const item of JOB_ROLE_PROFESSIONS_MATRIX) {
    if (item.keywords.some(kw => text.includes(kw))) {
      return item.key;
    }
  }

  // Fallbacks Generales Corporativos
  if (text.includes('director') || text.includes('subdirector') || text.includes('gerencia regional')) {
    return 'building-2';
  }
  if (text.includes('gerente de sucursal') || text.includes('gerente de tienda') || text.includes('encargad') || text.includes('sucursal') || text.includes('tienda')) {
    return 'store';
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

  // Si es un personaje Monito Alusivo de la Matriz de Profesiones
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


/**
 * Componente Ficha Icono de Puesto con personajes Monitos Alusivos y distinciones jerárquicas de mando.
 */
export interface JobRoleIconProps {
  role?: any;
  iconKey?: string;
  size?: number;
  className?: string;
  containerClassName?: string;
  isActive?: boolean;
  standalone?: boolean;
}

/**
 * Componente Icono de Puesto con personajes Monitos Alusivos (Puro icono sin recuadro).
 */
export const JobRoleIconBadge: React.FC<JobRoleIconProps> = ({
  role,
  iconKey,
  size = 34,
  className,
  containerClassName,
  isActive = true
}) => {
  const key = iconKey || resolveJobRoleIconKey(role);
  
  // Buscar nivel de mando en la Matriz Maestra si no viene explícito
  const matchedItem = JOB_ROLE_PROFESSIONS_MATRIX.find(p => p.key === key);
  const nivel = role?.nivel_mando ?? matchedItem?.nivel_mando ?? (
    key === 'monito-gerente' || key === 'monito-gerente-taller' || key === 'monito-director-medico' || key === 'monito-director-escolar' || key === 'monito-legal' || key === 'shield-check' || key === 'crown' ? 1 :
    key === 'monito-compras' || key === 'monito-ventas' || key === 'monito-produccion' || key === 'monito-mecanico' || key === 'monito-abogado-senior' || key === 'monito-salud' || key === 'monito-arquitecto' || key === 'scale' || key === 'bar-chart-3' || key === 'workflow' ? 2 :
    key === 'monito-asesor' || key === 'monito-ayudante' || key === 'monito-enfermero' || key === 'monito-asistente-legal' || key === 'monito-maestro-obra' || key === 'user-check' || key === 'clipboard-check' ? 3 : 4
  );

  // Colores vivos por Jerarquía de Mando
  const hierarchyColors: Record<number, { text: string; levelBadge: string }> = {
    1: { text: 'text-amber-600', levelBadge: 'N1 - Dirección General' },
    2: { text: 'text-indigo-600', levelBadge: 'N2 - Supervisión / Jefatura' },
    3: { text: 'text-sky-600', levelBadge: 'N3 - Especialista / Piso' },
    4: { text: 'text-slate-600', levelBadge: 'N4 - Auxiliar Operativo' },
    5: { text: 'text-slate-500', levelBadge: 'N5 - Apoyo Eventual' },
  };

  const levelStyle = hierarchyColors[nivel] || hierarchyColors[4];
  const activeText = isActive ? levelStyle.text : 'text-slate-400';

  return (
    <div 
      className={`relative shrink-0 flex items-center justify-center p-1 ${containerClassName || ''}`}
      title={`Puesto: ${role?.name || ''} (${levelStyle.levelBadge})`}
    >
      {renderJobRoleIcon(key, size, `${activeText} transition-all duration-300 ${className || ''}`)}
    </div>
  );
};

/**
 * Genera una descripción inteligente y profesional para cualquier puesto cuando no se haya ingresado una personalizada.
 */
export function getRoleSmartDescription(rol?: { name?: string; area?: string; description?: string } | null): string {
  if (!rol) return 'Puesto funcional clave en la organización.';
  
  if (rol.description && rol.description.trim() !== '' && !rol.description.toLowerCase().includes('sin descripción') && !rol.description.toLowerCase().includes('sin descripcion')) {
    return rol.description;
  }

  const name = (rol.name || '').toLowerCase();
  const area = (rol.area || 'la empresa').trim();

  if (name.includes('administrador gerente') || name.includes('gerente general') || name.includes('director general') || name.includes('ceo')) {
    return 'Liderazgo estratégico de la organización, supervisión de metas ejecutivas y dirección de operaciones.';
  }
  if (name.includes('supervisor de compras') || name.includes('compras') || name.includes('adquisicion')) {
    return 'Gestión de abastecimiento, negociación con proveedores clave y optimización de presupuesto.';
  }
  if (name.includes('supervisor de ventas') || name.includes('jefe de ventas') || name.includes('coordinador comercial')) {
    return 'Coordinación del equipo comercial, impulso de metas semanales y estrategia de clientes clave.';
  }
  if (name.includes('supervisor de producción') || name.includes('supervisor de produccion') || name.includes('producción') || name.includes('produccion')) {
    return 'Control de calidad en planta, optimización de tiempos de ensamble e inspección de procesos.';
  }
  if (name.includes('asesor de ventas') || name.includes('atención al cliente') || name.includes('atencion al cliente') || name.includes('asesor comercial')) {
    return 'Atención consultiva personalizada, asesoría en soluciones y fidelización de clientes.';
  }
  if (name.includes('ayudante integral') || name.includes('ayudante de piso') || name.includes('operativo')) {
    return 'Soporte multifuncional en piso de trabajo, surtimiento de insumos y asistencia en operaciones.';
  }
  if (name.includes('apoyo eventual') || name.includes('eventual') || name.includes('auxiliar temporal')) {
    return 'Refuerzo operativo y cobertura auxiliar por jornada durante picos de demanda o proyectos.';
  }
  if (name.includes('cajer') || name.includes('caja')) {
    return 'Manejo de caja registradora, cobro ágil a clientes y arqueo diario de valores.';
  }
  if (name.includes('almacen') || name.includes('bodeg') || name.includes('inventari')) {
    return 'Control de inventarios, recepción de mercancía y gestión de almacenamiento seguro.';
  }
  if (name.includes('chofer') || name.includes('repart') || name.includes('conductor')) {
    return 'Conducción segura de unidades, entrega puntual de envíos y logística de rutas.';
  }
  if (name.includes('mecanico') || name.includes('mecanica') || name.includes('taller')) {
    return 'Diagnóstico técnico automotriz, mantenimiento preventivo y reparación mecánica.';
  }
  if (name.includes('abogado') || name.includes('legal') || name.includes('juridico')) {
    return 'Asesoría jurídica corporativa, revisión de expedientes y representación legal.';
  }
  if (name.includes('medico') || name.includes('doctor') || name.includes('salud')) {
    return 'Atención médica integral, evaluación clínica y seguimiento a la salud de pacientes.';
  }
  if (name.includes('programad') || name.includes('desarrollad') || name.includes('software')) {
    return 'Ingeniería de software, desarrollo de funcionalidades y mantenimiento de plataformas TI.';
  }
  if (name.includes('diseñad') || name.includes('creativ') || name.includes('marketing')) {
    return 'Creación de contenido visual, diseño gráfico publicitario e identidad de marca.';
  }
  if (name.includes('contad') || name.includes('finanz') || name.includes('nomin')) {
    return 'Gestión de contabilidad general, cálculo de nómina y elaboración de estados financieros.';
  }
  if (name.includes('chef') || name.includes('cocin')) {
    return 'Preparación gastronómica especializada, control de insumos en cocina e higiene sanitaria.';
  }
  if (name.includes('meser') || name.includes('barista')) {
    return 'Servicio de salón al cliente, preparación de bebidas y atención en mesa.';
  }
  if (name.includes('mantenimi') || name.includes('intendenc')) {
    return 'Mantenimiento preventivo e instalatorio de infraestructura y servicios generales.';
  }
  if (name.includes('guardia') || name.includes('seguridad')) {
    return 'Monitoreo de accesos, prevención de riesgos y resguardo de las instalaciones.';
  }
  if (name.includes('capacit') || name.includes('docent') || name.includes('instructor')) {
    return 'Formación académica y capacitación continua del personal de la organización.';
  }
  if (name.includes('recepci')) {
    return 'Recepción de visitantes, atención de conmutador telefónico y canalización de folios.';
  }

  return `Puesto clave enfocado en la excelencia operativa y el cumplimiento de metas del área de ${area}.`;
}

