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
}

export const JOB_ROLE_ICON_OPTIONS: JobRoleIconOption[] = [
  { key: 'auto', label: 'Auto (Sugerido por nombre)', category: 'General' },
  
  // Jerarquía Corporativa - Decorarte 360 (Seriedad & Nivel Ejecutivo)
  { key: 'shield-check', label: 'Nivel 1 - Administrador Gerente (Alta Dirección)', category: 'Decorarte 360 / Nivel 1' },
  { key: 'scale', label: 'Nivel 2 - Supervisor de Compras (Gestión y Adquisiciones)', category: 'Decorarte 360 / Nivel 2' },
  { key: 'bar-chart-3', label: 'Nivel 2 - Supervisor de Ventas (Inteligencia Comercial)', category: 'Decorarte 360 / Nivel 2' },
  { key: 'workflow', label: 'Nivel 2 - Supervisor de Producción (Ingeniería y Planta)', category: 'Decorarte 360 / Nivel 2' },
  { key: 'user-check', label: 'Nivel 3 - Asesor de Ventas (Atención Consultiva)', category: 'Decorarte 360 / Nivel 3' },
  { key: 'clipboard-check', label: 'Nivel 3 - Ayudante Integral (Soporte Operativo)', category: 'Decorarte 360 / Nivel 3' },
  { key: 'clock', label: 'Nivel 4 - Apoyo Eventual (Personal de Refuerzo)', category: 'Decorarte 360 / Nivel 4' },

  // Otros Roles Corporativos e Industriales Generales
  { key: 'crown', label: 'Presidente / Fundador / Consejo', category: 'Dirección' },
  { key: 'building-2', label: 'Director Operativo / Gerencia Regional', category: 'Gerencia' },
  { key: 'store', label: 'Gerente de Sucursal / Supervisor de Tienda', category: 'Operaciones' },
  { key: 'shopping-bag', label: 'Ejecutivo de Ventas / Mostrador', category: 'Ventas' },
  { key: 'credit-card', label: 'Cajero(a) / Supervisor de Cajas / Tesorería', category: 'Finanzas' },
  { key: 'package', label: 'Almacenista / Control de Inventario', category: 'Logística' },
  { key: 'truck', label: 'Chofer / Envíos y Logística', category: 'Logística' },
  { key: 'user-plus', label: 'Recursos Humanos / Gestión de Talento', category: 'Capital Humano' },
  { key: 'palette', label: 'Diseñador / Creativo / Marketing', category: 'Diseño' },
  { key: 'code', label: 'Programador / Sistemas / TI / Software', category: 'Tecnología' },
  { key: 'calculator', label: 'Contador / Finanzas / Auditoría', category: 'Finanzas' },
  { key: 'utensils', label: 'Chef / Cocinero / Servicio Gastronómico', category: 'Servicios' },
  { key: 'wrench', label: 'Mantenimiento Técnico / Servicios Generales', category: 'Mantenimiento' },
  { key: 'shield', label: 'Guardia / Prevención y Seguridad', category: 'Seguridad' },
  { key: 'graduation-cap', label: 'Capacitador / Instructor Corporativo', category: 'Capacitación' },
  { key: 'briefcase', label: 'Puesto General / Administrativo', category: 'General' },
];

/**
 * Resuelve la clave de icono ejecutiva y seria adecuada basada en el puesto y jerarquía.
 */
export function resolveJobRoleIconKey(role?: { name?: string; area?: string; icon?: string } | null): string {
  if (!role) return 'briefcase';
  
  if (role.icon && role.icon !== 'auto' && role.icon.trim() !== '') {
    return role.icon.toLowerCase().trim();
  }

  const name = (role.name || '').toLowerCase();
  const area = (role.area || '').toLowerCase();
  const text = `${name} ${area}`;

  // 1. Jerarquía Ejecutiva Seria para Decorarte 360 & Corporativo
  if (text.includes('administrador gerente') || text.includes('gerente general') || text.includes('director general') || text.includes('ceo') || text.includes('presiden')) {
    return 'shield-check';
  }
  if (text.includes('supervisor de compras') || text.includes('compras') || text.includes('adquisicion')) {
    return 'scale';
  }
  if (text.includes('supervisor de ventas') || text.includes('jefe de ventas') || text.includes('coordinador de ventas')) {
    return 'bar-chart-3';
  }
  if (text.includes('supervisor de producción') || text.includes('supervisor de produccion') || text.includes('producción') || text.includes('produccion') || text.includes('taller') || text.includes('manufactura')) {
    return 'workflow';
  }
  if (text.includes('asesor de ventas') || text.includes('asesor de venta') || text.includes('asesor comercial') || text.includes('atención al cliente') || text.includes('atencion al cliente')) {
    return 'user-check';
  }
  if (text.includes('ayudante integral') || text.includes('ayudante de piso') || text.includes('soporte operativo')) {
    return 'clipboard-check';
  }
  if (text.includes('apoyo eventual') || text.includes('eventual') || text.includes('auxiliar eventual')) {
    return 'clock';
  }

  // 2. Mapeos Generales Corporativos
  if (text.includes('director') || text.includes('subdirector') || text.includes('gerencia regional')) {
    return 'building-2';
  }
  if (text.includes('gerente de sucursal') || text.includes('gerente de tienda') || text.includes('encargad') || text.includes('sucursal') || text.includes('tienda')) {
    return 'store';
  }
  if (text.includes('cajer') || text.includes('caja') || text.includes('tesorer') || text.includes('supervisor de cajas')) {
    return 'credit-card';
  }
  if (text.includes('vended') || text.includes('venta') || text.includes('comercial') || text.includes('mostrador') || text.includes('promotor')) {
    return 'shopping-bag';
  }
  if (text.includes('almacen') || text.includes('bodeg') || text.includes('inventari') || text.includes('logist') || text.includes('surtid')) {
    return 'package';
  }
  if (text.includes('chofer') || text.includes('repartid') || text.includes('transport') || text.includes('envio')) {
    return 'truck';
  }
  if (text.includes('reclut') || text.includes('recursos humanos') || text.includes('rh') || text.includes('capital humano') || text.includes('talent')) {
    return 'user-plus';
  }
  if (text.includes('diseñ') || text.includes('creativ') || text.includes('marketing') || text.includes('arte')) {
    return 'palette';
  }
  if (text.includes('programad') || text.includes('desarrollad') || text.includes('sistemas') || text.includes('ti') || text.includes('software')) {
    return 'code';
  }
  if (text.includes('contad') || text.includes('finanz') || text.includes('nomin') || text.includes('audit')) {
    return 'calculator';
  }
  if (text.includes('chef') || text.includes('cocin') || text.includes('meser') || text.includes('barista') || text.includes('gastronom')) {
    return 'utensils';
  }
  if (text.includes('limpieza') || text.includes('intendenc') || text.includes('mantenimi') || text.includes('tecnic')) {
    return 'wrench';
  }
  if (text.includes('recepcio') || text.includes('call center') || text.includes('soporte') || text.includes('telefon')) {
    return 'headset';
  }
  if (text.includes('guardia') || text.includes('seguridad') || text.includes('vigilant')) {
    return 'shield';
  }
  if (text.includes('capacit') || text.includes('entrenad') || text.includes('instruct') || text.includes('docent')) {
    return 'graduation-cap';
  }

  return 'briefcase';
}

/**
 * Devuelve el elemento Lucide corporativo correspondiente a la clave de icono especificada o inferida.
 */
export function renderJobRoleIcon(
  keyOrRole: string | { name?: string; area?: string; icon?: string } | null,
  size: number = 20,
  className?: string
): React.ReactElement {
  const iconKey = typeof keyOrRole === 'string' ? keyOrRole : resolveJobRoleIconKey(keyOrRole);
  const props = { size, className: className || '' };

  switch (iconKey) {
    case 'shield-check':
      return <ShieldCheck {...props} />;
    case 'scale':
      return <Scale {...props} />;
    case 'bar-chart-3':
      return <BarChart3 {...props} />;
    case 'workflow':
      return <Workflow {...props} />;
    case 'user-check':
      return <UserCheck {...props} />;
    case 'clipboard-check':
      return <ClipboardCheck {...props} />;
    case 'clock':
      return <Clock {...props} />;
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
 * Componente Ficha Icono de Puesto con estilos corporativos serios que distinguen la jerarquía de mando.
 */
export const JobRoleIconBadge: React.FC<JobRoleIconProps> = ({
  role,
  iconKey,
  size = 22,
  className,
  containerClassName,
  isActive = true
}) => {
  const key = iconKey || resolveJobRoleIconKey(role);
  const nivel = role?.nivel_mando ?? (
    key === 'shield-check' || key === 'crown' ? 1 :
    key === 'scale' || key === 'bar-chart-3' || key === 'workflow' ? 2 :
    key === 'user-check' || key === 'clipboard-check' ? 3 : 4
  );

  // Paleta de colores ejecutivos por Nivel de Mando / Jerarquía
  const hierarchyStyles: Record<number, { bg: string; text: string; ring: string; levelBadge: string }> = {
    1: { 
      bg: 'bg-indigo-950/80 border-indigo-700/80 shadow-md', 
      text: 'text-amber-400', 
      ring: 'ring-2 ring-amber-400/40',
      levelBadge: 'N1 - Dirección'
    },
    2: { 
      bg: 'bg-slate-900/80 border-slate-700 shadow-sm', 
      text: 'text-indigo-400', 
      ring: 'ring-1 ring-indigo-400/30',
      levelBadge: 'N2 - Supervisión'
    },
    3: { 
      bg: 'bg-slate-100 border-slate-250', 
      text: 'text-slate-700', 
      ring: 'border-slate-200',
      levelBadge: 'N3 - Especialista'
    },
    4: { 
      bg: 'bg-slate-50 border-slate-200', 
      text: 'text-slate-600', 
      ring: 'border-slate-150',
      levelBadge: 'N4 - Auxiliar'
    },
    5: { 
      bg: 'bg-slate-50 border-slate-200', 
      text: 'text-slate-500', 
      ring: 'border-slate-150',
      levelBadge: 'N5 - Apoyo'
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
