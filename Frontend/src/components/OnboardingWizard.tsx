import React, { useState, useEffect } from 'react';
import { Sparkles, Building2, Briefcase, UserPlus, Clock, CheckCircle2, ChevronRight, AlertCircle, Loader2, MessageSquare, Send, BookOpen, BarChart3, Users } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';
import { isLocalhost, getQrOrigin } from '../lib/qrHelper';

interface OnboardingWizardProps {
  onComplete: () => void;
}

export function OnboardingWizard({ onComplete }: OnboardingWizardProps) {
  const [step, setStep] = useState<0 | 1 | 2 | 3 | 4 | 5>(0);
  const [showPlanDetails, setShowPlanDetails] = useState(false);
  const [loadDemoRoles, setLoadDemoRoles] = useState(false);
  const [loadDemoEmployees, setLoadDemoEmployees] = useState(false);
  const [loadingDemo, setLoadingDemo] = useState(false);
  const [loading, setLoading] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const { fetchState, updateSetting, currentUser, setCurrentUser, isModuleUnlocked, globalRoles } = useAppStore();

  const activePlanModules = [
    { id: 'rrhh', name: 'Recursos Humanos', icon: Users, tag: 'Gratis', color: 'emerald' },
    { id: 'reloj', name: 'Reloj Checador (PWA)', icon: Clock, tag: 'Gratis', color: 'emerald' },
    { id: 'operativo', name: 'Bolsa de Trabajo', icon: Briefcase, tag: 'Gratis', color: 'emerald' },
    { id: 'ats', name: 'Reclutamiento ATS', icon: UserPlus, tag: 'Prueba', color: 'indigo' },
    { id: 'academia', name: 'Academia LMS', icon: BookOpen, tag: 'Prueba', color: 'indigo' },
    { id: 'reportes', name: 'Reportes & Analítica', icon: BarChart3, tag: 'Prueba', color: 'indigo' }
  ].filter(mod => isModuleUnlocked(mod.id));

  const [isEmailEdited, setIsEmailEdited] = useState(false);
  const [qrIpOverride, setQrIpOverride] = useState(localStorage.getItem('qr_origin_override') || '');

  const handleQrIpChange = (val: string) => {
    setQrIpOverride(val);
    localStorage.setItem('qr_origin_override', val);
  };

  // Step 1: Branch Settings
  // 2026-07-26 (auditoría en vivo): antes esto arrancaba con el literal 'Mi Sucursal Talent360'
  // aunque el usuario ACABA de escribir el nombre real de su empresa dos pantallas antes, en el
  // alta de la Landing Page. El nombre correcto ya viene hidratado en currentUser.tenant.name,
  // así que se usa ese y el genérico queda solo como último recurso.
  const [companyName, setCompanyName] = useState(currentUser?.tenant?.name || 'Mi Sucursal Talent360');
  const [isCompanyNameEdited, setIsCompanyNameEdited] = useState(false);
  const [welcomeMessage, setWelcomeMessage] = useState('¡Hola! Registra tu asistencia diaria aquí.');
  const [adminPhone, setAdminPhone] = useState('');
  const [companyAddress, setCompanyAddress] = useState('');
  const [companyPhone, setCompanyPhone] = useState('');
  const [storeOpenTime, setStoreOpenTime] = useState('08:00');
  const [storeCloseTime, setStoreCloseTime] = useState('18:00');

  // Helpers to format and clean phone numbers (prefixed with Mexican country code 52)
  const formatPhoneVisual = (val: string) => {
    let clean = val.replace(/\D/g, '');
    if (clean.startsWith('52')) {
      clean = clean.slice(2);
    }
    clean = clean.slice(0, 10);
    if (clean.length <= 3) return clean;
    if (clean.length <= 6) return `${clean.slice(0, 3)} ${clean.slice(3)}`;
    return `${clean.slice(0, 3)} ${clean.slice(3, 6)} ${clean.slice(6)}`;
  };

  const getCleanDbPhone = (val: string) => {
    const clean = val.replace(/\D/g, '');
    if (!clean) return '';
    if (clean.length === 10) return `52${clean}`;
    if (clean.startsWith('52') && clean.length > 10) return clean;
    return clean;
  };

  const isAdminPhoneValid = () => {
    const clean = adminPhone.replace(/\D/g, '');
    return clean.length === 12 && clean.startsWith('52');
  };

  const isEmpPhoneValid = () => {
    const clean = empPhone.replace(/\D/g, '');
    return clean.length === 12 && clean.startsWith('52');
  };

  // Step 2: Giro Comercial / Nicho & Puestos
  const [selectedNicho, setSelectedNicho] = useState<'materias_primas' | 'retail' | 'restaurante' | 'oficina' | 'taller' | 'custom'>('materias_primas');
  const [selectedSubNicho, setSelectedSubNicho] = useState<string>('reposteria');
  const [customNichoDesc, setCustomNichoDesc] = useState('');
  const [selectedRoleId, setSelectedRoleId] = useState<number | string>('');
  const [createdRoleId, setCreatedRoleId] = useState<number | null>(null);

  // Mappings y presets interactivos para la precarga del wizard
  const SUB_NICHOS: Record<string, { id: string; label: string; icon: string }[]> = {
    materias_primas: [
      { id: 'reposteria', label: 'Insumos para Repostería & Panadería', icon: '🧁' },
      { id: 'empaques', label: 'Empaques y Materias Primas Mayoreo', icon: '📦' },
      { id: 'chocolateria', label: 'Chocolatería, Confitería y Decoración', icon: '🍫' },
    ],
    retail: [
      { id: 'decoracion', label: 'Decoración, Hogar y Regalos', icon: '🎨' },
      { id: 'boutique', label: 'Boutique / Ropa y Calzado', icon: '👗' },
      { id: 'minimarket', label: 'Minimarket / Abarrotes', icon: '🛒' },
      { id: 'ferreteria', label: 'Ferretería / Herramientas', icon: '🔧' },
      { id: 'farmacia', label: 'Farmacia / Salud', icon: '💊' },
    ],
    restaurante: [
      { id: 'servicio_completo', label: 'Restaurante Servicio Completo', icon: '🍽️' },
      { id: 'cafeteria', label: 'Cafetería / Panadería y Postres', icon: '☕' },
      { id: 'comida_rapida', label: 'Comida Rápida / Taquería', icon: '🌮' },
      { id: 'bar', label: 'Bar / Bar & Grill', icon: '🍹' },
    ],
    oficina: [
      { id: 'despacho', label: 'Despacho Contable / Legal', icon: '⚖️' },
      { id: 'agencia', label: 'Agencia de Marketing / Software', icon: '💻' },
      { id: 'consultoria', label: 'Consultoría Corporativa', icon: '📈' },
      { id: 'inmobiliaria', label: 'Inmobiliaria / Bienes Raíces', icon: '🏠' },
    ],
    taller: [
      { id: 'mecanico', label: 'Taller Mecánico / Automotriz', icon: '🚗' },
      { id: 'tecnico', label: 'Centro Técnico / Electrónica', icon: '🔌' },
      { id: 'manufactura', label: 'Taller de Manufactura / Carpintería', icon: '🔨' },
    ]
  };

  const PRESET_DATA: Record<string, {
    puestos: { name: string; area: string; esAperturador: boolean; jerarquiaLlaves: number }[];
    tareas: { title: string; category: string; priority: string; assistant_type: string; assistant_prompt: string; target_role_name: string }[];
    vacantes: { title: string; salary: string }[];
    cursos: { title: string; type: string }[];
  }> = {
    materias_primas: {
      puestos: [
        { name: 'Administrador Gerente', area: 'Gerencia General', esAperturador: true, jerarquiaLlaves: 1 },
        { name: 'Supervisor de Compras', area: 'Compras & Almacén', esAperturador: false, jerarquiaLlaves: 2 },
        { name: 'Supervisor de Ventas', area: 'Ventas & Mostrador', esAperturador: false, jerarquiaLlaves: 2 },
        { name: 'Supervisor de Producción', area: 'Producción & Envasado', esAperturador: false, jerarquiaLlaves: 2 },
        { name: 'Asesor de Ventas', area: 'Piso de Ventas', esAperturador: false, jerarquiaLlaves: 3 },
        { name: 'Ayudante Integral', area: 'Operaciones', esAperturador: false, jerarquiaLlaves: 3 },
        { name: 'Apoyo Eventual', area: 'Operaciones', esAperturador: false, jerarquiaLlaves: 4 }
      ],
      tareas: [
        // Administrador Gerente (22 Tareas)
        { title: 'Desactivar alarma perimetral y encender switch principal de energía', category: 'seguridad', priority: 'bloqueante', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto de alarma desactivada.', target_role_name: 'Administrador Gerente' },
        { title: 'Verificar funcionamiento de las luces del piso de ventas y clima', category: 'operativo', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Administrador Gerente' },
        { title: 'Realizar conteo del fondo de caja inicial y apertura en punto de venta', category: 'operativo', priority: 'bloqueante', assistant_type: 'captura_numero', assistant_prompt: 'Monto de fondo inicial.', target_role_name: 'Administrador Gerente' },
        { title: 'Inspeccionar estado exterior de la sucursal y tomar foto de la fachada frontal', category: 'seguridad', priority: 'alta', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto de fachada frontal.', target_role_name: 'Administrador Gerente' },
        { title: 'Apertura de caja fuerte/tómbola y asignación de fondos a cajas registradoras', category: 'operativo', priority: 'bloqueante', assistant_type: 'captura_numero', assistant_prompt: 'Monto asignado a cajas.', target_role_name: 'Administrador Gerente' },
        { title: 'Verificar el grupo de trabajo del día y confirmar asistencia del personal (Pase de lista)', category: 'supervision', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Administrador Gerente' },
        { title: 'Recorrer pasillos asegurando que el piso esté libre de cajas u obstáculos', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Administrador Gerente' },
        { title: 'Validar que el personal esté portando el gafete y uniforme limpios', category: 'supervision', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Administrador Gerente' },
        { title: 'Auditoría de puntualidad, retardos y justificantes en el reloj checador', category: 'supervision', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Administrador Gerente' },
        { title: 'Revisión de bitácora de novedades de la jornada anterior', category: 'supervision', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Administrador Gerente' },
        { title: 'Supervisión de clima laboral y atención a incidencias de personal', category: 'supervision', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Administrador Gerente' },
        { title: 'Revisión de flujo de caja diario, retiros parciales y depósitos', category: 'operativo', priority: 'alta', assistant_type: 'captura_numero', assistant_prompt: 'Retiros parciales.', target_role_name: 'Administrador Gerente' },
        { title: 'Ejecutar corte X/Y y validar retiros de efectivo con cajeros (Arqueo gerencial)', category: 'operativo', priority: 'bloqueante', assistant_type: 'captura_numero', assistant_prompt: 'Total arqueado.', target_role_name: 'Administrador Gerente' },
        { title: 'Realizar conciliación bancaria diaria y voucher de terminales', category: 'operativo', priority: 'alta', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto de vouchers.', target_role_name: 'Administrador Gerente' },
        { title: 'Resguardo de efectivo sobrante en la tómbola de seguridad', category: 'seguridad', priority: 'bloqueante', assistant_type: 'captura_numero', assistant_prompt: 'Monto en tómbola.', target_role_name: 'Administrador Gerente' },
        { title: 'Apagar equipos de cómputo, clima y luces de piso de venta', category: 'seguridad', priority: 'bloqueante', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Administrador Gerente' },
        { title: 'Verificación de cierre seguro de puertas de emergencia y accesos', category: 'seguridad', priority: 'bloqueante', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Administrador Gerente' },
        { title: 'Cierre de cortina metálica principal y colocación de candados reforzados', category: 'seguridad', priority: 'bloqueante', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto de candado cerrado.', target_role_name: 'Administrador Gerente' },
        { title: 'Activar alarma perimetral y verificar reporte de armado en sistema', category: 'seguridad', priority: 'bloqueante', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto de alarma armada.', target_role_name: 'Administrador Gerente' },
        { title: 'Envío de reporte diario de ventas y asistencia a dirección general', category: 'supervision', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Administrador Gerente' },
        { title: 'Supervisión de cumplimiento de metas de venta semanales', category: 'supervision', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Administrador Gerente' },
        { title: 'Autorización de compras de insumos operativos extraordinarios', category: 'supervision', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Administrador Gerente' },

        // Supervisor de Compras (12 Tareas)
        { title: 'Auditoría de niveles de inventario crítico en bodega y góndolas', category: 'supervision', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Compras' },
        { title: 'Conteo diario de productos A (alta rotación y mayor valor)', category: 'operativo', priority: 'alta', assistant_type: 'captura_numero', assistant_prompt: 'Piezas contadas.', target_role_name: 'Supervisor de Compras' },
        { title: 'Identificación de faltantes y alertas de stock mínimo', category: 'operativo', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Compras' },
        { title: 'Revisión y recepción de fletes de materias primas (harinas, azúcar, mantecas, chocolates)', category: 'operativo', priority: 'bloqueante', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto del flete.', target_role_name: 'Supervisor de Compras' },
        { title: 'Inspección física de empaques y caducidades en sacos y cajas recibidas', category: 'calidad', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Compras' },
        { title: 'Cotejo de remisiones/facturas físicas contra orden de compra', category: 'operativo', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Compras' },
        { title: 'Ingreso de entradas de mercancía al sistema ERP/Inventarios', category: 'operativo', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Compras' },
        { title: 'Generación de órdenes de compra con proveedores de materias primas', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Compras' },
        { title: 'Seguimiento a entregas pendientes y reclamos por mermas/defectos', category: 'supervision', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Compras' },
        { title: 'Control e inventario de material de empaque (bolsas, cajas, domos)', category: 'operativo', priority: 'normal', assistant_type: 'captura_numero', assistant_prompt: 'Unidades de empaque.', target_role_name: 'Supervisor de Compras' },
        { title: 'Reporte de variación de costos e insumos de temporada', category: 'supervision', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Compras' },
        { title: 'Supervisión de stock de insumos para cajas y mostrador', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Compras' },

        // Supervisor de Ventas (14 Tareas)
        { title: 'Supervisar la atención al cliente en mostrador y agilidad en cajas', category: 'supervision', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Ventas' },
        { title: 'Monitoreo de tiempo de espera en fila de clientes', category: 'supervision', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Ventas' },
        { title: 'Atención y resolución de quejas o devoluciones de clientes', category: 'operativo', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Ventas' },
        { title: 'Verificación de precios visibles y promociones vigentes', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Ventas' },
        { title: 'Revisar pedidos de clientes especiales, escuelas o de mayoreo pendientes de procesar', category: 'operativo', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Ventas' },
        { title: 'Autorización de descuentos especiales o facturación a clientes corporativos', category: 'supervision', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Ventas' },
        { title: 'Realizar arqueos sorpresivos de caja a cajeros y vendedores', category: 'supervision', priority: 'bloqueante', assistant_type: 'captura_numero', assistant_prompt: 'Monto en arqueo.', target_role_name: 'Supervisor de Ventas' },
        { title: 'Validación de cierres parciales de ventas, arqueos y depósitos', category: 'operativo', priority: 'bloqueante', assistant_type: 'captura_numero', assistant_prompt: 'Monto validado.', target_role_name: 'Supervisor de Ventas' },
        { title: 'Realizar inventario rotativo (conteo de productos de alta rotación)', category: 'operativo', priority: 'alta', assistant_type: 'captura_numero', assistant_prompt: 'Piezas contadas.', target_role_name: 'Supervisor de Ventas' },
        { title: 'Realizar ajustes de inventario por mermas o roturas en piso', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Ventas' },
        { title: 'Alinear los precios en las etiquetas de los domos principales', category: 'operativo', priority: 'normal', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto exhibidor.', target_role_name: 'Supervisor de Ventas' },
        { title: 'Coordinación de frenteo y exhibición en cabeceras de pasillo', category: 'operativo', priority: 'normal', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto cabecera.', target_role_name: 'Supervisor de Ventas' },
        { title: 'Supervisión de arqueos de cambio en cajas registradoras', category: 'supervision', priority: 'alta', assistant_type: 'captura_numero', assistant_prompt: 'Cambio en cajas.', target_role_name: 'Supervisor de Ventas' },
        { title: 'Reporte diario de conversión de ventas y tickets promedio', category: 'supervision', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Ventas' },

        // Supervisor de Producción (12 Tareas)
        { title: 'Inspección de lotes de fraccionado, empacado y etiquetado de insumos a granel', category: 'produccion', priority: 'alta', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto fraccionados.', target_role_name: 'Supervisor de Producción' },
        { title: 'Supervisión de básculas de pesado para asegurar gramajes exactos', category: 'calidad', priority: 'bloqueante', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto báscula.', target_role_name: 'Supervisor de Producción' },
        { title: 'Control e impresión de etiquetas con fecha de caducidad y lote', category: 'produccion', priority: 'alta', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto etiqueta.', target_role_name: 'Supervisor de Producción' },
        { title: 'Verificación de la rotación PEPS (Primeras Entradas, Primeras Salidas) en almacén de granel', category: 'calidad', priority: 'bloqueante', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Producción' },
        { title: 'Auditoría de higiene en la zona de envasado y herramientas de trabajo', category: 'seguridad', priority: 'alta', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto área.', target_role_name: 'Supervisor de Producción' },
        { title: 'Control de mermas y pesado de materias primas procesadas en taller de fraccionado', category: 'produccion', priority: 'alta', assistant_type: 'captura_numero', assistant_prompt: 'Kg de merma.', target_role_name: 'Supervisor de Producción' },
        { title: 'Monitoreo de condiciones de temperatura y humedad en almacén de insumos sensibles', category: 'calidad', priority: 'bloqueante', assistant_type: 'captura_numero', assistant_prompt: 'Temperatura °C.', target_role_name: 'Supervisor de Producción' },
        { title: 'Registro de bitácora de limpieza de maquinaria y moldes', category: 'seguridad', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Producción' },
        { title: 'Control de merma por humedad o empaque dañado', category: 'produccion', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Producción' },
        { title: 'Reporte semanal de rendimiento de insumos procesados', category: 'produccion', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Producción' },
        { title: 'Inspección de calidad organoléptica en materia prima recibida', category: 'calidad', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Producción' },
        { title: 'Supervisión del correcto etiquetado de alérgenos en insumos empacados', category: 'calidad', priority: 'bloqueante', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Supervisor de Producción' },

        // Asesor de Ventas (12 Tareas)
        { title: 'Bienvenida y atención personalizada a clientes reposteros y panaderos', category: 'operativo', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Asesor de Ventas' },
        { title: 'Asesoría técnica sobre rendimiento y uso de insumos, esencias y coberturas', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Asesor de Ventas' },
        { title: 'Cobro rápido en caja y manejo de terminales de pago', category: 'operativo', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Asesor de Ventas' },
        { title: 'Verificación de datos fiscales para facturación a clientes', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Asesor de Ventas' },
        { title: 'Limpieza fina de mostrador, vitrinas y desinfección de terminales punto de venta', category: 'operativo', priority: 'normal', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto mostrador.', target_role_name: 'Asesor de Ventas' },
        { title: 'Revisión general de productos exhibidos en góndola para verificar faltantes', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Asesor de Ventas' },
        { title: 'Rellenar góndolas y acomodar mercancía (Frenteo, orden y alineación de precios)', category: 'operativo', priority: 'normal', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto góndola.', target_role_name: 'Asesor de Ventas' },
        { title: 'Limpieza fina de estanterías y productos destacados', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Asesor de Ventas' },
        { title: 'Apoyo en el etiquetado de precios de nueva colección o promociones', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Asesor de Ventas' },
        { title: 'Verificar pedidos especiales del día y validarlos con el sistema', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Asesor de Ventas' },
        { title: 'Acomodo de devoluciones de productos en anaqueles', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Asesor de Ventas' },
        { title: 'Conteo e inventario rápido de cierre en zona de mostrador', category: 'operativo', priority: 'alta', assistant_type: 'captura_numero', assistant_prompt: 'Piezas contadas.', target_role_name: 'Asesor de Ventas' },

        // Ayudante Integral (14 Tareas)
        { title: 'Levantar las cortinas de la entrada y quitar candados', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Ayudante Integral' },
        { title: 'Abrir la puerta principal y colocar la rampa de acceso', category: 'operativo', priority: 'bloqueante', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto rampa.', target_role_name: 'Ayudante Integral' },
        { title: 'Sacar los tapetes de bienvenida y colocarlos en la entrada', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Ayudante Integral' },
        { title: 'Barrer la banqueta exterior y limpiar fachada frontal', category: 'operativo', priority: 'normal', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto banqueta.', target_role_name: 'Ayudante Integral' },
        { title: 'Limpieza de las vitrinas frontales de exhibición principal', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Ayudante Integral' },
        { title: 'Lavar los tapetes de bienvenida de la entrada con hidrolavadora', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Ayudante Integral' },
        { title: 'Trapear los pasillos principales y áreas de exhibición', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Ayudante Integral' },
        { title: 'Lavar y desinfectar el baño de clientes y personal', category: 'operativo', priority: 'bloqueante', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto baños.', target_role_name: 'Ayudante Integral' },
        { title: 'Limpiar las escaleras interiores y sacudir pasamanos', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Ayudante Integral' },
        { title: 'Limpiar el patio de servicio y ordenar contenedores de mermas', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Ayudante Integral' },
        { title: 'Surtido de bodega a exhibidores y traslado de mercancía pesada', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Ayudante Integral' },
        { title: 'Sacudir polvo acumulado en estanterías de bodega trasera', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Ayudante Integral' },
        { title: 'Guardar tapetes de entrada, cerrar rampa y candados al cierre', category: 'seguridad', priority: 'bloqueante', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto candado.', target_role_name: 'Ayudante Integral' },
        { title: 'Recolección y depósito de basura general de la sucursal', category: 'operativo', priority: 'bloqueante', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Ayudante Integral' },

        // Apoyo Eventual (6 Tareas)
        { title: 'Apoyo en la descarga de fletes pesados de sacos de harina, azúcar y mantecas', category: 'operativo', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Apoyo Eventual' },
        { title: 'Apoyo en el traslado de sacos de bodega a zona de fraccionado', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Apoyo Eventual' },
        { title: 'Acomodar y clasificar cajas vacías en el área de reciclaje', category: 'operativo', priority: 'normal', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto reciclaje.', target_role_name: 'Apoyo Eventual' },
        { title: 'Apoyo en el pegado de etiquetas de promociones y reempaquetado especial', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Apoyo Eventual' },
        { title: 'Limpieza y despeje de pasillos auxiliares en temporadas de alta afluencia', category: 'operativo', priority: 'normal', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Apoyo Eventual' },
        { title: 'Apoyo general en montaje de exhibiciones de temporada (Navidad, Rosca, Valentín)', category: 'operativo', priority: 'normal', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto exhibición.', target_role_name: 'Apoyo Eventual' }
      ],
      vacantes: [
        { title: 'Supervisor de Ventas', salary: '$11,000 - $13,500 MXN' },
        { title: 'Supervisor de Compras', salary: '$11,000 - $13,500 MXN' },
        { title: 'Asesor de Ventas', salary: '$8,500 - $9,800 MXN' },
        { title: 'Ayudante Integral', salary: '$8,200 - $9,000 MXN' }
      ],
      cursos: [
        { title: 'Protocolo de Operación Comercial y Calidad Decorarte 360', type: 'Inducción' },
        { title: 'Manejo e Higiene de Materias Primas, Fraccionado y Conservación PEPS', type: 'Capacitación' },
        { title: 'Técnicas de Venta Asistida en Insumos de Repostería y Panadería', type: 'Capacitación' }
      ]
    },
    retail: {
      puestos: [
        { name: 'Gerente de Tienda', area: 'Gerencia', esAperturador: true, jerarquiaLlaves: 1 },
        { name: 'Supervisor de Cajas', area: 'Cajas', esAperturador: true, jerarquiaLlaves: 2 },
        { name: 'Asesor de Ventas y Piso', area: 'Piso de Ventas', esAperturador: false, jerarquiaLlaves: 3 },
        { name: 'Almacenista / Inventarios', area: 'Almacén', esAperturador: false, jerarquiaLlaves: 4 }
      ],
      tareas: [
        { title: 'Conteo y validación de fondo de caja', category: 'operativo', priority: 'alta', assistant_type: 'captura_numero', assistant_prompt: 'Ingrese el monto contado.', target_role_name: 'Supervisor de Cajas' },
        { title: 'Desactivación de alarma y encendido de switch', category: 'seguridad', priority: 'alta', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto de alarma desactivada.', target_role_name: 'Gerente de Tienda' },
        { title: 'Alineación de precios y frenteo de mercancía', category: 'operativo', priority: 'normal', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto de exhibidor ordenado.', target_role_name: 'Asesor de Ventas y Piso' },
        { title: 'Cierre de cortina metálica y candado', category: 'seguridad', priority: 'bloqueante', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto del candado cerrado.', target_role_name: 'Gerente de Tienda' }
      ],
      vacantes: [
        { title: 'Asesor de Ventas y Piso', salary: '$8,000 - $9,500 MXN' },
        { title: 'Cajero(a) de Tienda', salary: '$7,800 - $8,800 MXN' }
      ],
      cursos: [
        { title: 'Protocolo de Apertura y Operación Comercial', type: 'Inducción' },
        { title: 'Excelencia en Servicio al Cliente y Venta Cruzada', type: 'Capacitación' }
      ]
    },
    restaurante: {
      puestos: [
        { name: 'Gerente de Restaurante', area: 'Administración', esAperturador: true, jerarquiaLlaves: 1 },
        { name: 'Chef / Jefe de Cocina', area: 'Cocina', esAperturador: true, jerarquiaLlaves: 2 },
        { name: 'Mesero / Atención al Cliente', area: 'Servicio', esAperturador: false, jerarquiaLlaves: 3 },
        { name: 'Ayudante de Cocina', area: 'Cocina', esAperturador: false, jerarquiaLlaves: 4 }
      ],
      tareas: [
        { title: 'Verificación de temperatura de congeladores', category: 'operativo', priority: 'alta', assistant_type: 'captura_numero', assistant_prompt: 'Ingrese temperatura °C.', target_role_name: 'Chef / Jefe de Cocina' },
        { title: 'Montaje y sanitización de mesas de comedor', category: 'operativo', priority: 'normal', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto de mesas sanitizadas.', target_role_name: 'Mesero / Atención al Cliente' },
        { title: 'Inspección y cierre de llaves de gas principal', category: 'seguridad', priority: 'bloqueante', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto de válvula cerrada.', target_role_name: 'Chef / Jefe de Cocina' }
      ],
      vacantes: [
        { title: 'Mesero(a) con Experiencia', salary: '$7,500 + Propinas' },
        { title: 'Ayudante de Cocina', salary: '$8,000 - $8,800 MXN' }
      ],
      cursos: [
        { title: 'Manejo Higiénico de Alimentos (NOM-251)', type: 'Inducción' },
        { title: 'Seguridad e Inspección de Válvulas de Gas', type: 'Seguridad' }
      ]
    },
    oficina: {
      puestos: [
        { name: 'Director General', area: 'Dirección', esAperturador: true, jerarquiaLlaves: 1 },
        { name: 'Coordinador de Operaciones', area: 'Operaciones', esAperturador: true, jerarquiaLlaves: 2 },
        { name: 'Consultor / Ejecutivo de Cuenta', area: 'Operaciones', esAperturador: false, jerarquiaLlaves: 3 },
        { name: 'Recepcionista / Asistente', area: 'Administración', esAperturador: false, jerarquiaLlaves: 4 }
      ],
      tareas: [
        { title: 'Encendido y verificación de servidores locales', category: 'tecnología', priority: 'alta', assistant_type: 'ninguno', assistant_prompt: '', target_role_name: 'Coordinador de Operaciones' },
        { title: 'Revisión y distribución de correspondencia', category: 'operativo', priority: 'normal', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto de bitácora.', target_role_name: 'Recepcionista / Asistente' }
      ],
      vacantes: [
        { title: 'Ejecutivo de Cuenta / Consultor Junior', salary: '$12,000 MXN' },
        { title: 'Recepcionista y Asistente Administrativo', salary: '$9,000 MXN' }
      ],
      cursos: [
        { title: 'Inducción al Software Corporativo y Gestión del Tiempo', type: 'Inducción' }
      ]
    },
    taller: {
      puestos: [
        { name: 'Jefe de Taller', area: 'Administración', esAperturador: true, jerarquiaLlaves: 1 },
        { name: 'Supervisor de Seguridad', area: 'Calidad', esAperturador: true, jerarquiaLlaves: 2 },
        { name: 'Técnico Operador / Mecánico', area: 'Producción', esAperturador: false, jerarquiaLlaves: 3 },
        { name: 'Ayudante de Almacén', area: 'Logística', esAperturador: false, jerarquiaLlaves: 4 }
      ],
      tareas: [
        { title: 'Inspección de equipo de protección personal (EPP)', category: 'seguridad', priority: 'alta', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto grupal con EPP.', target_role_name: 'Supervisor de Seguridad' },
        { title: 'Apagado de disyuntores de alta tensión y soldadoras', category: 'seguridad', priority: 'bloqueante', assistant_type: 'evidencia_foto', assistant_prompt: 'Foto de disyuntor apagado.', target_role_name: 'Técnico Operador / Mecánico' }
      ],
      vacantes: [
        { title: 'Técnico Mecánico / Operador de Taller', salary: '$11,000 MXN' },
        { title: 'Ayudante General de Almacén', salary: '$8,000 MXN' }
      ],
      cursos: [
        { title: 'Protocolo de Seguridad Industrial y Uso de EPP', type: 'Inducción' }
      ]
    }
  };

  const activePreset = PRESET_DATA[selectedNicho] || PRESET_DATA.retail;
  const [selectedPuestos, setSelectedPuestos] = useState<string[]>(() => activePreset.puestos.map(p => p.name));
  const [selectedTareas, setSelectedTareas] = useState<string[]>(() => activePreset.tareas.map(t => t.title));

  // Al cambiar de giro, resetear selección de puestos y tareas
  const handleSelectNicho = (nichoKey: 'materias_primas' | 'retail' | 'restaurante' | 'oficina' | 'taller' | 'custom') => {
    setSelectedNicho(nichoKey);
    const preset = PRESET_DATA[nichoKey] || PRESET_DATA.retail;
    setSelectedPuestos(preset.puestos.map(p => p.name));
    setSelectedTareas(preset.tareas.map(t => t.title));
    if (SUB_NICHOS[nichoKey]?.length) {
      setSelectedSubNicho(SUB_NICHOS[nichoKey][0].id);
    }
  };

  const togglePuestoSelection = (name: string) => {
    setSelectedPuestos(prev => 
      prev.includes(name) ? prev.filter(p => p !== name) : [...prev, name]
    );
  };

  const toggleTareaSelection = (title: string) => {
    setSelectedTareas(prev => 
      prev.includes(title) ? prev.filter(t => t !== title) : [...prev, title]
    );
  };

  // Step 3: Employee
  const [empName, setEmpName] = useState('Carlos Mendoza');
  const [empEmail, setEmpEmail] = useState('');
  // 2026-07-26 (auditoría en vivo): antes esto venía fijo como 'password123'. Como el correo del
  // colaborador también se autogenera de forma predecible (nombre.apellido@subdominio), cualquiera
  // que supiera que una empresa acaba de darse de alta podía adivinar las credenciales del primer
  // empleado — y la mayoría de los administradores no cambian un valor que ya viene lleno. Ahora
  // se genera una contraseña aleatoria por sesión; el admin la ve en pantalla y puede cambiarla.
  const [empPassword, setEmpPassword] = useState(() => {
    const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const bytes = new Uint32Array(10);
    crypto.getRandomValues(bytes);
    return 'T360-' + Array.from(bytes, (b) => chars[b % chars.length]).join('');
  });
  const [empPhone, setEmpPhone] = useState('');
  const [createdEmpId, setCreatedEmpId] = useState<number | null>(null);
  // employees.id y users.id son DISTINTOS: el primero sirve para /admin/employees/{id}/generate-pin,
  // el segundo es el que espera /clock/punch. Confundirlos ficha a la persona equivocada.
  const [createdEmpUserId, setCreatedEmpUserId] = useState<number | null>(null);
  const [createdEmpPin, setCreatedEmpPin] = useState<string | null>(null);

  // Step 4: Clock-in test
  const [clockInSuccess, setClockInSuccess] = useState(false);

  useEffect(() => {
    if (!isEmailEdited) {
      let subdomain = 'miempresa';
      if (currentUser?.tenant?.subdomain) {
        subdomain = currentUser.tenant.subdomain;
      } else if (currentUser?.email) {
        const emailParts = currentUser.email.split('@');
        if (emailParts.length === 2) {
          const domainParts = emailParts[1].split('.');
          if (domainParts.length >= 2) {
            const domainName = domainParts[0];
            const commonProviders = ['gmail', 'yahoo', 'outlook', 'hotmail', 'live', 'icloud', 'talent360'];
            if (!commonProviders.includes(domainName.toLowerCase())) {
              subdomain = domainName;
            }
          }
        }
      }

      // If still using default/fallback, try to clean the custom company name configured in Step 1
      if ((subdomain === 'miempresa' || subdomain === 'decorarte360') && companyName && companyName !== 'Mi Sucursal Talent360') {
        const cleanCompanyName = companyName
          .toLowerCase()
          .normalize("NFD")
          .replace(/[\u0300-\u036f]/g, "")
          .replace(/[^a-z0-9]/g, '');
        if (cleanCompanyName.length > 0) {
          subdomain = cleanCompanyName;
        }
      }

      const cleanName = empName
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .trim()
        .replace(/\s+/g, '.');
      setEmpEmail(`${cleanName}@${subdomain}.com`);
    }
  }, [empName, currentUser, companyName, isEmailEdited]);

  useEffect(() => {
    if (globalRoles && globalRoles.length > 0 && !selectedRoleId) {
      setSelectedRoleId(globalRoles[0].id);
    }
  }, [globalRoles, selectedRoleId]);

  // 2026-07-26 (auditoría en vivo): `currentUser` llega de forma asíncrona, así que en el primer
  // render puede ser null y el useState de arriba caería en el genérico. Cuando el tenant termina
  // de hidratarse, se rellena el nombre real — salvo que el usuario ya lo haya editado a mano.
  useEffect(() => {
    const tenantName = currentUser?.tenant?.name;
    if (tenantName && !isCompanyNameEdited && companyName === 'Mi Sucursal Talent360') {
      setCompanyName(tenantName);
    }
  }, [currentUser, isCompanyNameEdited, companyName]);

  const [waSentStatus, setWaSentStatus] = useState<{
    sending: boolean;
    sent: boolean;
    error: string | null;
  }>({ sending: false, sent: false, error: null });

  useEffect(() => {
    if (step === 5 && !waSentStatus.sent && !waSentStatus.sending) {
      if (!createdEmpPin) {
        // Bypassed/Skipped employee registration, so no notifications to send
        setWaSentStatus({ sending: false, sent: true, error: null });
        return;
      }
      const sendNotifications = async () => {
        setWaSentStatus(prev => ({ ...prev, sending: true }));
        try {
          const inviteUrl = `${getQrOrigin(qrIpOverride)}/invite?pin=${createdEmpPin}`;
          
          const adminMessage = `¡Hola, ${currentUser?.name || 'Administrador'}! 👋 Gracias por crear tu cuenta de empresa con nosotros en nuestra plataforma.\n\nTu entorno de control ya está listo para operar, te compartimos tu acceso rápido al panel administrativo para gestionar a tus colaboradores en tiempo real:\n\n👤 *Usuario/Email:* ${currentUser?.email || ''}\n🔑 *PIN de prueba del empleado:* ${createdEmpPin} (${empName})\n\n¡Hagamos crecer tu negocio juntos! 🚀\n\n${window.location.origin}`;
          
          const employeeMessage = `*TALENT 360* | ¡Bienvenido al Equipo! 👋\n\nHola, *${empName}*, te damos la más cordial bienvenida a *${companyName}*. 🏢\n\nTu cuenta ha sido registrada con éxito en nuestra plataforma de asistencia y gestión laboral. Para activar tu Reloj Checador móvil (PWA) de forma segura y configurar tu perfil, haz clic en el enlace de invitación:\n\n🔑 *Tu PIN temporal de acceso es:* ${createdEmpPin}\n\n¡Mucho éxito en tu jornada laboral! 🚀\n\n${inviteUrl}`;

          await axiosInstance.post('/admin/onboarding/send-whatsapp', {
            admin_phone: adminPhone,
            admin_message: adminMessage,
            employee_phone: empPhone,
            employee_message: employeeMessage
          });

          setWaSentStatus({ sending: false, sent: true, error: null });
        } catch (e: any) {
          console.error("Error al enviar notificaciones de WhatsApp:", e);
          setWaSentStatus({ 
            sending: false, 
            sent: false, 
            error: e.response?.data?.message || 'Error de conexión al canal de WhatsApp.' 
          });
        }
      };

      sendNotifications();
    }
  }, [step, currentUser, companyName, empName, createdEmpPin, adminPhone, empPhone, qrIpOverride, waSentStatus.sent, waSentStatus.sending]);

  const handleSaveSettings = async () => {
    setLoading(true);
    setErrorMsg(null);
    try {
      // 1. Guardar ajustes de bienvenida en el backend (modelo Company del Tenant)
      await axiosInstance.post('/admin/onboarding/settings', {
        welcomeTitle: `¡Bienvenido a ${companyName}!`,
        welcomeMessage: welcomeMessage,
        welcomeImageUrl: '',
        welcomeVideoUrl: ''
      });
      
      // 2. Guardar en system_settings del Tenant para coherencia
      await Promise.all([
        updateSetting('company_name', companyName),
        updateSetting('welcome_title', `¡Bienvenido a ${companyName}!`),
        updateSetting('welcome_text', welcomeMessage),
        updateSetting('company_address', companyAddress),
        updateSetting('company_phone', companyPhone),
        updateSetting('storeSchedule', { openTime: storeOpenTime, closeTime: storeCloseTime }),
        updateSetting('onboarding_completed', true)
      ]);

      // 3. Guardar el teléfono del administrador en su perfil si fue provisto
      if (adminPhone.trim()) {
        try {
          const profileRes = await axiosInstance.post('/me/update-profile', {
            name: currentUser?.name || 'Administrador',
            phone: adminPhone
          });
          if (profileRes.data && profileRes.data.user) {
            setCurrentUser(profileRes.data.user);
          }
        } catch (profileErr) {
          console.error("Error al guardar el teléfono del administrador:", profileErr);
        }
      }

      setStep(3);
    } catch (e: any) {
      console.error(e);
      setErrorMsg(e.response?.data?.message || 'Error al guardar los ajustes de sucursal.');
    } finally {
      setLoading(false);
    }
  };

  const handleConfigureNicho = async () => {
    setLoading(true);
    setErrorMsg(null);
    try {
      const preset = PRESET_DATA[selectedNicho] || PRESET_DATA.retail;
      const filteredPuestos = selectedNicho === 'custom' 
        ? undefined 
        : preset.puestos.filter(p => selectedPuestos.includes(p.name));
      const filteredTareas = selectedNicho === 'custom' 
        ? undefined 
        : preset.tareas.filter(t => selectedTareas.includes(t.title));

      await axiosInstance.post('/admin/onboarding/configure-nicho', {
        nicho: selectedNicho,
        sub_nicho: selectedSubNicho,
        custom_nicho_description: customNichoDesc,
        selected_puestos: filteredPuestos,
        selected_tareas: filteredTareas
      });
      
      // Persistir la bandera de onboarding completado para que el wizard no se vuelva a mostrar en login
      try {
        await updateSetting('onboarding_completed', true);
      } catch (err) {
        console.error("Error setting onboarding completed in step 1:", err);
      }

      // Refrescar el estado para traer los puestos recién inyectados en Postgres
      await fetchState();
      
      const updatedRoles = useAppStore.getState().globalRoles;
      if (updatedRoles && updatedRoles.length > 0) {
        setSelectedRoleId(updatedRoles[0].id);
      }

      setStep(2);
    } catch (e: any) {
      console.error(e);
      setErrorMsg(e.response?.data?.message || 'Error al configurar el giro de negocio.');
    } finally {
      setLoading(false);
    }
  };
 
  const handleCreateEmployee = async () => {
    if (!selectedRoleId) {
      setErrorMsg("Debes seleccionar un puesto de trabajo para el colaborador.");
      return;
    }
    setLoading(true);
    setErrorMsg(null);
    try {
      const res = await axiosInstance.post('/employees', {
        name: empName,
        email: empEmail,
        password: empPassword,
        role: 'empleado',
        job_role_id: selectedRoleId,
        contract_type: 'Tiempo Completo',
        salary: 8000,
        is_active: true,
        is_active_employee: true,
        shiftStart: storeOpenTime,
        shiftEnd: storeCloseTime,
        phone: empPhone
      });
      if (res.data && res.data.id) {
        const empId = res.data.id;
        setCreatedEmpId(empId);
        // 2026-07-26 (auditoría en vivo, hallazgo grave): `POST /employees` responde
        // `$employee->load('user')`, así que `res.data.id` es el **employees.id**, NO el users.id.
        // Ese mismo valor se estaba mandando después como `user_id` al fichar (handleTestClockIn),
        // y el backend lo interpretaba como users.id — fichando a QUIEN SEA que tuviera ese id,
        // incluso de OTRA empresa. En la prueba real, la empresa nueva terminó registrando una
        // entrada a nombre de un empleado de DecorArte (tenant 1). Aquí se guarda aparte el
        // users.id real para usarlo donde de verdad se pide un usuario. Es la misma familia de
        // bug que §29/§30 (employees.id vs users.id), en un punto que no se había cubierto.
        setCreatedEmpUserId(res.data.user_id ?? res.data.user?.id ?? null);

        // Generar PIN e invitación móvil
        try {
          const pinRes = await axiosInstance.post(`/admin/employees/${empId}/generate-pin`);
          if (pinRes.data && pinRes.data.pin) {
            const pin = pinRes.data.pin;
            setCreatedEmpPin(pin);
          }
        } catch (pinErr) {
          console.error("Error al generar el PIN de activación:", pinErr);
        }
      }
      setStep(4);
    } catch (e: any) {
      console.error(e);
      setErrorMsg(e.response?.data?.message || 'Error al registrar al colaborador.');
    } finally {
      setLoading(false);
    }
  };

  const handleSendEmployeeWhatsApp = async () => {
    let phone = empPhone.trim();
    if (!phone) {
      const userVal = prompt("Ingresa el WhatsApp del colaborador a 10 dígitos (ej. 462 123 4567):", "");
      if (!userVal) return;
      const cleanPhone = getCleanDbPhone(userVal);
      if (cleanPhone.length !== 12 || !cleanPhone.startsWith('52')) {
        alert("Número inválido. Deben ser 10 dígitos.");
        return;
      }
      phone = cleanPhone;
      setEmpPhone(phone);
      try {
        await axiosInstance.put(`/employees/${createdEmpId}`, { phone });
      } catch (e) {
        console.error("Error al actualizar el teléfono en la BD:", e);
      }
    }
    
    const inviteUrl = `${getQrOrigin(qrIpOverride)}/invite?pin=${createdEmpPin}`;
    const message = `*TALENT 360* | ¡Bienvenido al Equipo! 👋\n\nHola, *${empName}*, te damos la más cordial bienvenida a *${companyName}*. 🏢\n\nTu cuenta ha sido registrada con éxito en nuestra plataforma de asistencia y gestión laboral. Para activar tu Reloj Checador móvil (PWA) de forma segura y configurar tu perfil, haz clic en el enlace de invitación:\n\n🔑 *Tu PIN temporal de acceso es:* ${createdEmpPin}\n\n¡Mucho éxito en tu jornada laboral! 🚀\n\n${inviteUrl}`;
    
    const waUrl = `https://wa.me/${phone.replace(/[^0-9]/g, '')}?text=${encodeURIComponent(message)}`;
    window.open(waUrl, '_blank');
  };

  const handleSendAdminWhatsApp = async () => {
    let phone = adminPhone.trim();
    if (!phone) {
      const userVal = prompt("Ingresa tu WhatsApp a 10 dígitos (ej. 462 123 4567):", "");
      if (!userVal) return;
      const cleanPhone = getCleanDbPhone(userVal);
      if (cleanPhone.length !== 12 || !cleanPhone.startsWith('52')) {
        alert("Número inválido. Deben ser 10 dígitos.");
        return;
      }
      phone = cleanPhone;
      setAdminPhone(phone);
      try {
        const profileRes = await axiosInstance.post('/me/update-profile', {
          name: currentUser?.name || 'Administrador',
          phone
        });
        if (profileRes.data && profileRes.data.user) {
          setCurrentUser(profileRes.data.user);
        }
      } catch (e) {
        console.error("Error al actualizar tu teléfono en la BD:", e);
      }
    }
    
    const adminMessage = `¡Hola, *${currentUser?.name || 'Administrador'}*! 👋 Gracias por crear tu cuenta de empresa con nosotros en nuestra plataforma.\n\nTu entorno de control ya está listo para operar, te compartimos tu acceso rápido al panel administrativo para gestionar a tus colaboradores en tiempo real:\n\n👤 *Usuario/Email:* ${currentUser?.email || ''}\n🔑 *PIN de prueba del empleado:* ${createdEmpPin} (${empName})\n\n¡Hagamos crecer tu negocio juntos! 🚀\n\n${window.location.origin}`;
    
    const waUrl = `https://wa.me/${phone.replace(/[^0-9]/g, '')}?text=${encodeURIComponent(adminMessage)}`;
    window.open(waUrl, '_blank');
  };

  const handleTestClockIn = async () => {
    // Se exige el users.id real (no el employees.id): ver comentario en handleCreateEmployee.
    // Si el backend no lo devolvió, se aborta con un mensaje claro en vez de fichar a ciegas
    // con un id que podría pertenecer a otra persona (o a otra empresa).
    if (!createdEmpUserId) {
      setErrorMsg('No se pudo identificar la cuenta del colaborador recién creado. Omite esta prueba y ficha desde el reloj cuando el colaborador active su cuenta.');
      return;
    }
    setLoading(true);
    setErrorMsg(null);
    try {
      // Punch check-in for the created employee
      await axiosInstance.post('/clock/punch', {
        user_id: createdEmpUserId,
        type: 'check_in',
        latitude: 19.4326,
        longitude: -99.1332
      });
      setClockInSuccess(true);
      await fetchState(); // Sync dashboard monitor
      setTimeout(() => {
        setStep(5);
      }, 1500);
    } catch (e: any) {
      console.error(e);
      setErrorMsg(e.response?.data?.message || 'Error al realizar el fichaje de pruebas.');
    } finally {
      setLoading(false);
    }
  };

  const handleFinishOnboarding = async () => {
    try {
      await updateSetting('onboarding_completed', true);
    } catch (e) {
      console.error("Error setting onboarding completed:", e);
    }
    if (loadDemoRoles || loadDemoEmployees) {
      setLoadingDemo(true);
      try {
        await axiosInstance.post('/admin/onboarding/inject-demo', {
          inject_roles: loadDemoRoles,
          inject_employees: loadDemoEmployees
        });
        await fetchState(); // Sincronizar el estado del dashboard con los nuevos datos demo
      } catch (err) {
        console.error("Error al inyectar datos demo:", err);
      } finally {
        setLoadingDemo(false);
      }
    }
    onComplete();
  };

  return (
    <div className="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-900/80 backdrop-blur-md overflow-y-auto">
      <div className="bg-white w-full max-w-2xl rounded-2xl sm:rounded-3xl overflow-hidden shadow-2xl relative border border-slate-100 flex flex-col my-auto max-h-[92vh] sm:max-h-none">
        
        {/* Progress Bar */}
        <div className="h-2 bg-slate-100 w-full flex-shrink-0 flex">
          {[1, 2, 3, 4, 5].map((s) => (
            <div 
              key={s} 
              className={`h-full flex-1 transition-all duration-300 ${s <= step ? 'bg-blue-600' : 'bg-slate-200'}`}
            />
          ))}
        </div>

        {/* Wizard Content */}
        <div className="p-5 sm:p-8 md:p-10 flex-1 flex flex-col overflow-y-auto sm:overflow-y-visible">
          {errorMsg && (
            <div className="mb-6 p-4 bg-rose-50 border border-rose-100 rounded-2xl flex items-start gap-3 text-rose-700 text-sm animate-in fade-in">
              <AlertCircle size={20} className="shrink-0 mt-0.5" />
              <div>
                <span className="font-bold">Hubo un problema: </span>
                {errorMsg}
              </div>
            </div>
          )}

          {step === 0 && (
            <div className="text-center py-4 sm:py-6 animate-in fade-in">
              {/* Contenedor flotante de icono de bienvenida */}
              <div className="relative w-20 h-20 mx-auto mb-6">
                <div className="absolute inset-0 bg-blue-100 rounded-3xl blur-xl opacity-50 animate-pulse"></div>
                <div className="relative w-20 h-20 bg-gradient-to-tr from-blue-600 to-indigo-500 text-white rounded-3xl flex items-center justify-center shadow-lg transform rotate-6 hover:rotate-0 transition-transform duration-300">
                  <Sparkles size={36} className="animate-pulse" />
                </div>
              </div>

              <h2 className="text-2xl sm:text-3xl font-black text-slate-800 mb-2 tracking-tight">¡Bienvenido a Talent 360!</h2>
              <p className="text-slate-600 text-xs sm:text-sm max-w-md mx-auto leading-relaxed mb-6 px-4">
                Configura tu sucursal en 4 sencillos pasos y ponla a funcionar en menos de 2 minutos.
              </p>

              {/* Tarjeta de Notificación del Periodo de Prueba - Rediseñada */}
              <div className="bg-slate-50 border border-slate-200/80 rounded-2xl sm:rounded-3xl p-4 sm:p-5 mb-6 text-left max-w-lg mx-auto shadow-sm relative overflow-hidden transition-all duration-300">
                <div className="absolute top-0 right-0 w-24 h-24 bg-indigo-50 rounded-full blur-2xl -mr-8 -mt-8 opacity-60"></div>
                
                <div className="flex items-start gap-3 relative z-10">
                  <div className="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0 shadow-inner">
                    <Sparkles size={20} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-0.5">
                      <h4 className="font-extrabold text-slate-800 text-sm sm:text-base">Prueba Premium Activada</h4>
                      <span className="bg-indigo-100 text-indigo-700 text-[10px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full shrink-0">
                        30 Días
                      </span>
                    </div>
                    <p className="text-xs text-slate-500 leading-relaxed">
                      Accede a todas las herramientas premium sin costo. Al finalizar el plazo, volverás automáticamente al plan gratuito de por vida sin cargos.
                    </p>
                  </div>
                </div>

                {/* Acordeón de Características */}
                <div className="mt-4 pt-3 border-t border-slate-200/60 relative z-10 flex flex-col gap-3">
                  <button
                    onClick={() => setShowPlanDetails(!showPlanDetails)}
                    className="flex items-center justify-between w-full text-xs font-bold text-indigo-600 hover:text-indigo-700 transition-colors focus:outline-none"
                  >
                    <span>{showPlanDetails ? "Ocultar módulos incluidos" : `Ver módulos incluidos (${activePlanModules.length})`}</span>
                    <span className="text-slate-400 font-normal">
                      {showPlanDetails ? "▲" : "▼"}
                    </span>
                  </button>

                  {showPlanDetails && (
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1 animate-in fade-in slide-in-from-top-1 duration-200">
                      {activePlanModules.map((mod) => {
                        const IconComponent = mod.icon;
                        const colorClass = mod.color === 'emerald' ? 'bg-emerald-50 text-emerald-600' : 'bg-indigo-50 text-indigo-600';
                        return (
                          <div key={mod.id} className="bg-white p-2.5 rounded-xl border border-slate-100 shadow-sm flex items-center gap-2.5 animate-in fade-in duration-300">
                            <div className={`w-8 h-8 rounded-lg ${colorClass} flex items-center justify-center shrink-0`}>
                              <IconComponent size={16} />
                            </div>
                            <div className="min-w-0 flex-1 flex items-center justify-between gap-2">
                              <span className="text-[11px] font-bold text-slate-700 truncate">{mod.name}</span>
                              <span className={`text-[8px] font-extrabold ${colorClass} px-1.5 py-0.5 rounded-md shrink-0`}>{mod.tag}</span>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              </div>

              <div className="pt-2">
                <button 
                  onClick={() => setStep(1)}
                  className="py-3 px-6 sm:py-4 sm:px-8 bg-blue-600 hover:bg-blue-700 text-white rounded-2xl font-black text-sm sm:text-md shadow-lg shadow-blue-500/20 transition-all flex items-center gap-2 mx-auto active:scale-95"
                >
                  Comenzar Configuración <ChevronRight size={18} />
                </button>
              </div>
            </div>
          )}

          {step === 1 && (
            <div className="animate-in fade-in space-y-5">
              <div className="flex items-center gap-3">
                <div className="w-12 h-12 bg-purple-50 text-purple-600 rounded-xl flex items-center justify-center shadow-sm shrink-0">
                  <Sparkles size={24} className="text-purple-600" />
                </div>
                <div>
                  <span className="text-xs font-bold text-purple-600 tracking-wider uppercase">Paso 1 de 4</span>
                  <h3 className="text-xl font-black text-slate-800">Giro Comercial y Estructura</h3>
                </div>
              </div>

              <p className="text-xs text-slate-500 leading-relaxed">
                Selecciona el giro y sub-giro de tu empresa. Te sugeriremos los puestos y tareas operativas iniciales; puedes activar o desactivar con los checkboxes lo que desees incluir.
              </p>

              {/* Botones de Giros Principales */}
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                <button
                  type="button"
                  onClick={() => handleSelectNicho('materias_primas')}
                  className={`p-3 border rounded-2xl flex flex-col items-center gap-1.5 transition-all active:scale-98 ${
                    selectedNicho === 'materias_primas' 
                      ? 'border-purple-600 bg-purple-50/60 shadow-sm ring-1 ring-purple-600/30' 
                      : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50/50'
                  }`}
                >
                  <span className="text-xl">🧁</span>
                  <span className="text-xs font-bold text-slate-700">Materias Primas / Repostería</span>
                </button>

                <button
                  type="button"
                  onClick={() => handleSelectNicho('retail')}
                  className={`p-3 border rounded-2xl flex flex-col items-center gap-1.5 transition-all active:scale-98 ${
                    selectedNicho === 'retail' 
                      ? 'border-purple-600 bg-purple-50/60 shadow-sm ring-1 ring-purple-600/30' 
                      : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50/50'
                  }`}
                >
                  <span className="text-xl">🛍️</span>
                  <span className="text-xs font-bold text-slate-700">Retail / Tienda</span>
                </button>

                <button
                  type="button"
                  onClick={() => handleSelectNicho('restaurante')}
                  className={`p-3 border rounded-2xl flex flex-col items-center gap-1.5 transition-all active:scale-98 ${
                    selectedNicho === 'restaurante' 
                      ? 'border-purple-600 bg-purple-50/60 shadow-sm ring-1 ring-purple-600/30' 
                      : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50/50'
                  }`}
                >
                  <span className="text-xl">🍔</span>
                  <span className="text-xs font-bold text-slate-700">Restaurante</span>
                </button>

                <button
                  type="button"
                  onClick={() => handleSelectNicho('oficina')}
                  className={`p-3 border rounded-2xl flex flex-col items-center gap-1.5 transition-all active:scale-98 ${
                    selectedNicho === 'oficina' 
                      ? 'border-purple-600 bg-purple-50/60 shadow-sm ring-1 ring-purple-600/30' 
                      : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50/50'
                  }`}
                >
                  <span className="text-xl">🏢</span>
                  <span className="text-xs font-bold text-slate-700">Oficina / Servicio</span>
                </button>

                <button
                  type="button"
                  onClick={() => handleSelectNicho('taller')}
                  className={`p-3 border rounded-2xl flex flex-col items-center gap-1.5 transition-all active:scale-98 ${
                    selectedNicho === 'taller' 
                      ? 'border-purple-600 bg-purple-50/60 shadow-sm ring-1 ring-purple-600/30' 
                      : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50/50'
                  }`}
                >
                  <span className="text-xl">⚙️</span>
                  <span className="text-xs font-bold text-slate-700">Taller / Fábrica</span>
                </button>
              </div>

              {/* Opción de Nicho Personalizado por IA */}
              <button
                type="button"
                onClick={() => handleSelectNicho('custom')}
                className={`w-full p-3 border rounded-2xl flex items-center justify-center gap-2.5 transition-all active:scale-98 ${
                  selectedNicho === 'custom'
                    ? 'border-purple-600 bg-gradient-to-r from-purple-500/10 to-indigo-500/10 shadow-sm ring-1 ring-purple-600/30'
                    : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50/50'
                }`}
              >
                <Sparkles size={16} className="text-purple-600 animate-pulse" />
                <span className="text-xs font-bold text-slate-700">Personalizado por IA (Gemini Copilot)</span>
              </button>

              {/* Sub-Giros Específicos */}
              {selectedNicho !== 'custom' && SUB_NICHOS[selectedNicho] && (
                <div className="bg-slate-50 p-3.5 rounded-2xl border border-slate-200/80 animate-in fade-in duration-200">
                  <label className="text-[11px] font-extrabold text-slate-600 uppercase tracking-wider block mb-2">
                    Especialidad / Sub-Giro Recomendado
                  </label>
                  <div className="flex flex-wrap gap-1.5">
                    {SUB_NICHOS[selectedNicho].map((sub) => (
                      <button
                        key={sub.id}
                        type="button"
                        onClick={() => setSelectedSubNicho(sub.id)}
                        className={`px-3 py-1.5 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 ${
                          selectedSubNicho === sub.id
                            ? 'bg-purple-600 text-white shadow-sm scale-102'
                            : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-100'
                        }`}
                      >
                        <span>{sub.icon}</span>
                        <span>{sub.label}</span>
                      </button>
                    ))}
                  </div>
                </div>
              )}

              {/* Textarea para Nicho Personalizado IA */}
              {selectedNicho === 'custom' && (
                <div className="relative animate-in slide-in-from-top-2 duration-200">
                  <textarea
                    id="customNichoDesc"
                    value={customNichoDesc}
                    onChange={(e) => setCustomNichoDesc(e.target.value)}
                    rows={2}
                    className="peer w-full px-4 py-3 border border-slate-200 focus:border-purple-600 focus:ring-1 focus:ring-purple-600 rounded-xl outline-none text-slate-800 text-xs font-medium placeholder-transparent resize-none"
                    placeholder="Describe tu giro de negocio"
                  />
                  <label
                    htmlFor="customNichoDesc"
                    className="absolute left-4 text-[10px] font-bold text-slate-500 transition-all pointer-events-none -top-2 bg-white px-1 peer-placeholder-shown:text-xs peer-placeholder-shown:text-slate-400 peer-placeholder-shown:top-3 peer-focus:-top-2 peer-focus:text-[10px] peer-focus:text-purple-600"
                  >
                    Describe tu giro de negocio (ej: Clínica Vet, Escuela de Idiomas, Gimnasio)
                  </label>
                </div>
              )}

              {/* Previsualización Interactiva de Puestos y Tareas (Checkboxes) */}
              {selectedNicho !== 'custom' && (
                <div className="space-y-3 pt-1">
                  {/* Panel Puestos */}
                  <div className="bg-white p-3.5 rounded-2xl border border-slate-200 shadow-sm space-y-2">
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-extrabold text-slate-800 flex items-center gap-1.5">
                        <span>📋</span> Puestos a Integrar ({selectedPuestos.length}/{activePreset.puestos.length})
                      </span>
                      <span className="text-[10px] text-purple-600 font-bold">Selección editable</span>
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                      {activePreset.puestos.map((puesto) => {
                        const isChecked = selectedPuestos.includes(puesto.name);
                        return (
                          <label
                            key={puesto.name}
                            onClick={() => togglePuestoSelection(puesto.name)}
                            className={`p-2.5 rounded-xl border flex items-center gap-2.5 cursor-pointer select-none transition-all ${
                              isChecked 
                                ? 'border-purple-300 bg-purple-50/40 text-slate-800 font-bold' 
                                : 'border-slate-200 bg-slate-50/50 text-slate-400 opacity-60'
                            }`}
                          >
                            <input
                              type="checkbox"
                              checked={isChecked}
                              onChange={() => {}}
                              className="w-4 h-4 rounded text-purple-600 focus:ring-purple-500 border-slate-300"
                            />
                            <div className="min-w-0 flex-1">
                              <p className="text-xs truncate">{puesto.name}</p>
                              <span className="text-[9px] text-slate-500 font-medium">{puesto.area}</span>
                            </div>
                            {puesto.esAperturador && (
                              <span className="text-[8px] bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded font-extrabold shrink-0">Llaves</span>
                            )}
                          </label>
                        );
                      })}
                    </div>
                  </div>

                  {/* Panel Tareas / Checklists */}
                  <div className="bg-white p-3.5 rounded-2xl border border-slate-200 shadow-sm space-y-2">
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-extrabold text-slate-800 flex items-center gap-1.5">
                        <span>✅</span> Checklists Operativas ({selectedTareas.length}/{activePreset.tareas.length})
                      </span>
                      <span className="text-[10px] text-purple-600 font-bold">Checklists de apertura/cierre</span>
                    </div>
                    <div className="space-y-1.5">
                      {activePreset.tareas.map((tarea) => {
                        const isChecked = selectedTareas.includes(tarea.title);
                        return (
                          <label
                            key={tarea.title}
                            onClick={() => toggleTareaSelection(tarea.title)}
                            className={`p-2 rounded-xl border flex items-center gap-2.5 cursor-pointer select-none transition-all ${
                              isChecked 
                                ? 'border-purple-200 bg-purple-50/30 text-slate-800' 
                                : 'border-slate-100 bg-slate-50/30 text-slate-400 opacity-60'
                            }`}
                          >
                            <input
                              type="checkbox"
                              checked={isChecked}
                              onChange={() => {}}
                              className="w-3.5 h-3.5 rounded text-purple-600 focus:ring-purple-500 border-slate-300"
                            />
                            <span className="text-xs font-medium truncate flex-1">{tarea.title}</span>
                            <span className="text-[9px] bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded font-bold shrink-0">{tarea.target_role_name}</span>
                          </label>
                        );
                      })}
                    </div>
                  </div>

                  {/* Previsualización de Cursos y Vacantes */}
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-[11px]">
                    <div className="bg-indigo-50/50 p-2.5 rounded-xl border border-indigo-100 flex flex-col gap-1">
                      <span className="font-extrabold text-indigo-700 flex items-center gap-1">🎓 Cursos de Inducción</span>
                      <span className="text-slate-600 text-[10px] truncate">{activePreset.cursos.map(c => c.title).join(' • ')}</span>
                    </div>
                    <div className="bg-emerald-50/50 p-2.5 rounded-xl border border-emerald-100 flex flex-col gap-1">
                      <span className="font-extrabold text-emerald-700 flex items-center gap-1">💼 Vacantes en Bolsa ATS</span>
                      <span className="text-slate-600 text-[10px] truncate">{activePreset.vacantes.map(v => v.title).join(' • ')}</span>
                    </div>
                  </div>
                </div>
              )}

              {/* Botones de Acción */}
              <div className="flex flex-col sm:flex-row gap-3 pt-2">
                <button 
                  onClick={handleConfigureNicho}
                  disabled={loading || (selectedNicho === 'custom' && !customNichoDesc.trim()) || (selectedNicho !== 'custom' && selectedPuestos.length === 0)}
                  className="flex-1 py-3.5 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white rounded-xl font-bold transition-all shadow-md flex items-center justify-center gap-2 active:scale-98 text-sm"
                >
                  {loading ? <Loader2 size={18} className="animate-spin" /> : <>Cargar Estructura Seleccionada <ChevronRight size={18} /></>}
                </button>
                <button 
                  onClick={async () => {
                    try { await updateSetting('onboarding_completed', true); } catch (e) {}
                    setStep(2);
                  }}
                  disabled={loading}
                  className="py-3.5 px-5 border border-slate-200 hover:bg-slate-50 text-slate-600 rounded-xl font-bold transition-all text-xs flex items-center justify-center gap-1.5 active:scale-98"
                >
                  Omitir Paso
                </button>
              </div>
            </div>
          )}

          {step === 2 && (
            <div className="animate-in fade-in">
              <div className="flex items-center gap-3 mb-6">
                <div className="w-12 h-12 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shadow-sm">
                  <Building2 size={24} />
                </div>
                <div>
                  <span className="text-xs font-bold text-blue-600 tracking-wider uppercase">Paso 2 de 4</span>
                  <h3 className="text-xl font-black text-slate-800">Ajustes de Sucursal</h3>
                </div>
              </div>
              
              <div className="space-y-4 mb-6">
                <div className="relative">
                  <input 
                    type="text" 
                    id="companyName"
                    value={companyName}
                    onChange={(e) => { setIsCompanyNameEdited(true); setCompanyName(e.target.value); }}
                    className="peer w-full px-4 pt-5 pb-1.5 border border-slate-200 focus:border-blue-600 focus:ring-1 focus:ring-blue-600 rounded-xl outline-none text-slate-800 text-sm font-medium placeholder-transparent"
                    placeholder="Nombre Comercial"
                  />
                  <label 
                    htmlFor="companyName"
                    className="absolute left-4 text-xs font-bold text-slate-500 transition-all pointer-events-none top-1.5 peer-placeholder-shown:text-sm peer-placeholder-shown:text-slate-400 peer-placeholder-shown:top-3.5 peer-focus:top-1.5 peer-focus:text-xs peer-focus:text-blue-600"
                  >
                    Nombre Comercial de la Empresa
                  </label>
                </div>
                <div className="relative">
                  <textarea 
                    id="welcomeMessage"
                    value={welcomeMessage}
                    onChange={(e) => setWelcomeMessage(e.target.value)}
                    className="peer w-full px-4 pt-5 pb-1.5 border border-slate-200 focus:border-blue-600 focus:ring-1 focus:ring-blue-600 rounded-xl outline-none text-slate-800 text-sm font-medium h-20 resize-none placeholder-transparent"
                    placeholder="Mensaje de Bienvenida"
                  />
                  <label 
                    htmlFor="welcomeMessage"
                    className="absolute left-4 text-xs font-bold text-slate-500 transition-all pointer-events-none top-1.5 peer-placeholder-shown:text-sm peer-placeholder-shown:text-slate-400 peer-placeholder-shown:top-3.5 peer-focus:top-1.5 peer-focus:text-xs peer-focus:text-blue-600"
                  >
                    Mensaje de Bienvenida del Reloj Kiosco
                  </label>
                </div>
                <div className="relative">
                  <div className="flex border border-slate-200 focus-within:border-blue-600 focus-within:ring-1 focus-within:ring-blue-600 rounded-xl overflow-hidden bg-white">
                    <div className="bg-slate-50 px-3 py-3 text-xs text-slate-500 font-bold border-r border-slate-200 flex items-center gap-1 select-none">
                      <span>🇲🇽</span>
                      <span>+52</span>
                    </div>
                    <input 
                      type="text" 
                      id="adminPhone"
                      value={formatPhoneVisual(adminPhone)}
                      onChange={(e) => setAdminPhone(getCleanDbPhone(e.target.value))}
                      className="peer w-full px-4 pt-5 pb-1.5 outline-none text-slate-800 text-sm font-medium font-mono placeholder-transparent"
                      placeholder="WhatsApp del Dueño/Administrador"
                    />
                    <label 
                      htmlFor="adminPhone"
                      className="absolute left-16 text-xs font-bold text-slate-500 transition-all pointer-events-none top-1.5 peer-placeholder-shown:text-sm peer-placeholder-shown:text-slate-400 peer-placeholder-shown:top-3.5 peer-focus:top-1.5 peer-focus:text-xs peer-focus:text-blue-600"
                    >
                      WhatsApp del Administrador (opcional)
                    </label>
                  </div>
                  <p className="text-[9px] text-slate-400 mt-1 pl-1">
                    Ingresa los 10 dígitos si deseas recibir alertas y resúmenes de tu sucursal por WhatsApp.
                  </p>
                </div>

                {/* Dirección de la Tienda */}
                <div className="relative">
                  <input 
                    type="text" 
                    id="companyAddress"
                    value={companyAddress}
                    onChange={(e) => setCompanyAddress(e.target.value)}
                    className="peer w-full px-4 pt-5 pb-1.5 border border-slate-200 focus:border-blue-600 focus:ring-1 focus:ring-blue-600 rounded-xl outline-none text-slate-800 text-sm font-medium placeholder-transparent"
                    placeholder="Dirección de la Tienda"
                  />
                  <label 
                    htmlFor="companyAddress"
                    className="absolute left-4 text-xs font-bold text-slate-500 transition-all pointer-events-none top-1.5 peer-placeholder-shown:text-sm peer-placeholder-shown:text-slate-400 peer-placeholder-shown:top-3.5 peer-focus:top-1.5 peer-focus:text-xs peer-focus:text-blue-600"
                  >
                    Dirección de la Tienda / Sucursal
                  </label>
                </div>

                {/* Teléfono de la Tienda */}
                <div className="relative">
                  <div className="flex border border-slate-200 focus-within:border-blue-600 focus-within:ring-1 focus-within:ring-blue-600 rounded-xl overflow-hidden bg-white">
                    <div className="bg-slate-50 px-3 py-3 text-xs text-slate-500 font-bold border-r border-slate-200 flex items-center gap-1 select-none">
                      <span>🇲🇽</span>
                      <span>+52</span>
                    </div>
                    <input 
                      type="text" 
                      id="companyPhone"
                      value={formatPhoneVisual(companyPhone)}
                      onChange={(e) => setCompanyPhone(getCleanDbPhone(e.target.value))}
                      className="peer w-full px-4 pt-5 pb-1.5 outline-none text-slate-800 text-sm font-medium font-mono placeholder-transparent"
                      placeholder="Teléfono de la Tienda"
                    />
                    <label 
                      htmlFor="companyPhone"
                      className="absolute left-16 text-xs font-bold text-slate-500 transition-all pointer-events-none top-1.5 peer-placeholder-shown:text-sm peer-placeholder-shown:text-slate-400 peer-placeholder-shown:top-3.5 peer-focus:top-1.5 peer-focus:text-xs peer-focus:text-blue-600"
                    >
                      Teléfono Fijo o Móvil de la Tienda (10 dígitos)
                    </label>
                  </div>
                </div>

                {/* Horario de la Tienda */}
                <div className="bg-slate-50 p-4 rounded-xl border border-slate-200">
                  <span className="text-xs font-extrabold text-slate-500 uppercase tracking-wider block mb-3">Horario de Operación (Tienda)</span>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="text-[10px] font-bold text-slate-400 uppercase block mb-1">Hora de Apertura</label>
                      <input 
                        type="time" 
                        value={storeOpenTime}
                        onChange={(e) => setStoreOpenTime(e.target.value)}
                        className="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-bold text-slate-700 focus:outline-none focus:border-blue-600"
                      />
                    </div>
                    <div>
                      <label className="text-[10px] font-bold text-slate-400 uppercase block mb-1">Hora de Cierre</label>
                      <input 
                        type="time" 
                        value={storeCloseTime}
                        onChange={(e) => setStoreCloseTime(e.target.value)}
                        className="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-bold text-slate-700 focus:outline-none focus:border-blue-600"
                      />
                    </div>
                  </div>
                </div>
              </div>

              <div className="flex gap-4">
                <button 
                  onClick={handleSaveSettings}
                  disabled={loading || !companyName.trim()}
                  className="flex-1 py-3 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-xl font-bold transition-all shadow-md flex items-center justify-center gap-2 active:scale-98"
                >
                  {loading ? <Loader2 size={18} className="animate-spin" /> : <>Guardar y Continuar <ChevronRight size={18}/></>}
                </button>
              </div>
            </div>
          )}

          {step === 3 && (
            <div className="animate-in fade-in">
              <div className="flex items-center gap-3 mb-6">
                <div className="w-12 h-12 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shadow-sm">
                  <UserPlus size={24} />
                </div>
                <div>
                  <span className="text-xs font-bold text-blue-600 tracking-wider uppercase">Paso 3 de 4</span>
                  <h3 className="text-xl font-black text-slate-800">Contratar Primer Colaborador</h3>
                </div>
              </div>

              <div className="space-y-4 mb-6">
                <div className="relative">
                  <input 
                    type="text" 
                    id="empName"
                    value={empName}
                    onChange={(e) => setEmpName(e.target.value)}
                    className="peer w-full px-4 pt-5 pb-1.5 border border-slate-200 focus:border-blue-600 focus:ring-1 focus:ring-blue-600 rounded-xl outline-none text-slate-800 text-sm font-medium placeholder-transparent"
                    placeholder="Nombre del Colaborador"
                  />
                  <label 
                    htmlFor="empName"
                    className="absolute left-4 text-xs font-bold text-slate-500 transition-all pointer-events-none top-1.5 peer-placeholder-shown:text-sm peer-placeholder-shown:text-slate-400 peer-placeholder-shown:top-3.5 peer-focus:top-1.5 peer-focus:text-xs peer-focus:text-blue-600"
                  >
                    Nombre del Colaborador
                  </label>
                </div>
                
                <div className="relative">
                  <input 
                    type="email" 
                    id="empEmail"
                    value={empEmail}
                    onChange={(e) => {
                      setEmpEmail(e.target.value);
                      setIsEmailEdited(true);
                    }}
                    className="peer w-full px-4 pt-5 pb-1.5 border border-slate-200 focus:border-blue-600 focus:ring-1 focus:ring-blue-600 rounded-xl outline-none text-slate-800 text-sm font-medium placeholder-transparent"
                    placeholder="Correo Electrónico"
                  />
                  <label 
                    htmlFor="empEmail"
                    className="absolute left-4 text-xs font-bold text-slate-500 transition-all pointer-events-none top-1.5 peer-placeholder-shown:text-sm peer-placeholder-shown:text-slate-400 peer-placeholder-shown:top-3.5 peer-focus:top-1.5 peer-focus:text-xs peer-focus:text-blue-600"
                  >
                    Correo Electrónico (Login)
                  </label>
                </div>

                <div className="relative">
                  <input 
                    type="text" 
                    id="empPassword"
                    value={empPassword}
                    onChange={(e) => setEmpPassword(e.target.value)}
                    className="peer w-full px-4 pt-5 pb-1.5 border border-slate-200 focus:border-blue-600 focus:ring-1 focus:ring-blue-600 rounded-xl outline-none text-slate-800 text-sm font-medium placeholder-transparent"
                    placeholder="Contraseña Temporal"
                  />
                  <label 
                    htmlFor="empPassword"
                    className="absolute left-4 text-xs font-bold text-slate-500 transition-all pointer-events-none top-1.5 peer-placeholder-shown:text-sm peer-placeholder-shown:text-slate-400 peer-placeholder-shown:top-3.5 peer-focus:top-1.5 peer-focus:text-xs peer-focus:text-blue-600"
                  >
                    Contraseña Temporal
                  </label>
                </div>

                <div className="relative">
                  <div className="flex border border-slate-200 focus-within:border-blue-600 focus-within:ring-1 focus-within:ring-blue-600 rounded-xl overflow-hidden bg-white">
                    <div className="bg-slate-50 px-3 py-3 text-xs text-slate-500 font-bold border-r border-slate-200 flex items-center gap-1 select-none">
                      <span>🇲🇽</span>
                      <span>+52</span>
                    </div>
                    <input 
                      type="text" 
                      id="empPhone"
                      value={formatPhoneVisual(empPhone)}
                      onChange={(e) => setEmpPhone(getCleanDbPhone(e.target.value))}
                      className="peer w-full px-4 pt-5 pb-1.5 outline-none text-slate-800 text-sm font-medium font-mono placeholder-transparent"
                      placeholder="Número de WhatsApp"
                    />
                    <label 
                      htmlFor="empPhone"
                      className="absolute left-16 text-xs font-bold text-slate-500 transition-all pointer-events-none top-1.5 peer-placeholder-shown:text-sm peer-placeholder-shown:text-slate-400 peer-placeholder-shown:top-3.5 peer-focus:top-1.5 peer-focus:text-xs peer-focus:text-blue-600"
                    >
                      WhatsApp del Colaborador (Obligatorio)
                    </label>
                  </div>
                  <p className="text-[9px] text-slate-400 mt-1 pl-1">
                    Solo ingresa los 10 dígitos. El prefijo de México (+52) se agrega automáticamente.
                  </p>
                </div>

                <div className="relative">
                  <select 
                    id="selectedRoleId"
                    value={selectedRoleId}
                    onChange={(e) => setSelectedRoleId(e.target.value)}
                    className="peer w-full px-4 pt-5 pb-1.5 border border-slate-200 focus:border-blue-600 focus:ring-1 focus:ring-blue-600 rounded-xl outline-none text-slate-800 text-sm font-medium bg-white"
                  >
                    <option value="">Selecciona un puesto de trabajo</option>
                    {globalRoles.map((r: any) => (
                      <option key={r.id} value={r.id}>{r.name} ({r.area})</option>
                    ))}
                  </select>
                  <label 
                    htmlFor="selectedRoleId"
                    className="absolute left-4 text-xs font-bold text-slate-500 top-1.5 pointer-events-none"
                  >
                    Puesto del Colaborador
                  </label>
                </div>
              </div>

              <div className="flex flex-col sm:flex-row gap-3">
                <button 
                  onClick={handleCreateEmployee}
                  disabled={loading || !empName.trim() || !empEmail.trim() || !empPassword.trim() || !isEmpPhoneValid()}
                  className="flex-1 py-3 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-xl font-bold transition-all shadow-md flex items-center justify-center gap-2 active:scale-98"
                >
                  {loading ? <Loader2 size={18} className="animate-spin" /> : <>Registrar Empleado e Ir al Paso 4 <ChevronRight size={18} /></>}
                </button>
                <button 
                  onClick={() => setStep(5)}
                  disabled={loading}
                  className="py-3 px-5 border border-slate-200 hover:bg-slate-50 text-slate-600 rounded-xl font-bold transition-all text-sm flex items-center justify-center gap-1.5 active:scale-98"
                >
                  Omitir (Iniciar sin colaboradores)
                </button>
              </div>
            </div>
          )}

          {step === 4 && (
            <div className="animate-in fade-in animate-duration-300">
              <div className="flex items-center gap-3 mb-6">
                <div className="w-12 h-12 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shadow-sm">
                  <Clock size={24} />
                </div>
                <div>
                  <span className="text-xs font-bold text-blue-600 tracking-wider uppercase">Paso 4 de 4</span>
                  <h3 className="text-xl font-black text-slate-800">Prueba de Fichaje del Reloj</h3>
                </div>
              </div>

              {/* QR Code and invite section */}
              {createdEmpPin && (
                <div className="mb-6 p-5 bg-blue-50/50 border border-blue-100 rounded-2xl flex flex-col gap-4">
                  <div className="flex flex-col sm:flex-row items-center gap-5">
                    <div className="bg-white p-2.5 rounded-xl border border-blue-100 shadow-sm flex-shrink-0">
                      <img 
                        src={`https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(`${getQrOrigin(qrIpOverride)}/invite?pin=${createdEmpPin}`)}`} 
                        alt="Código QR de Invitación" 
                        className="w-24 h-24"
                      />
                    </div>
                    <div className="text-left space-y-1">
                      <span className="text-[10px] font-bold text-blue-600 tracking-wider uppercase">Acceso Móvil Instantáneo (PWA)</span>
                      <h4 className="font-bold text-slate-800 text-sm">Escanea para activar la cuenta de {empName}</h4>
                      <p className="text-[11px] text-slate-500 leading-normal">
                        Apunta la cámara de tu celular aquí para abrir la App del reloj checador en tu móvil, o usa el PIN temporal <strong className="text-blue-600 tracking-widest">{createdEmpPin}</strong>.
                      </p>
                      <div className="pt-1 text-[10px] text-slate-400 break-all select-all font-mono">
                        Enlace: {`${getQrOrigin(qrIpOverride)}/invite?pin=${createdEmpPin}`}
                      </div>
                    </div>
                  </div>

                  {/* Enviar invitaciones por WhatsApp (Colaborador + Administrador) */}
                  <div className="pt-4 border-t border-blue-100/80 flex flex-col gap-3">
                    <div className="text-xs font-bold text-slate-600 flex items-center gap-1.5 justify-center sm:justify-start">
                      <MessageSquare size={14} className="text-emerald-600 animate-pulse" />
                      <span>Enviar invitaciones por WhatsApp:</span>
                    </div>
                    <div className="flex flex-col sm:flex-row gap-2">
                      <button
                        onClick={handleSendEmployeeWhatsApp}
                        className="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-xl text-xs flex items-center justify-center gap-1.5 transition-all shadow-sm hover:shadow active:scale-98"
                      >
                        <MessageSquare size={14} /> Enviar invitación a {empName}
                      </button>
                      <button
                        onClick={handleSendAdminWhatsApp}
                        className="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2.5 px-4 rounded-xl text-xs flex items-center justify-center gap-1.5 transition-all shadow-sm hover:shadow active:scale-98"
                      >
                        <Send size={14} /> Enviar mis accesos (Admin)
                      </button>
                    </div>
                  </div>

                  {isLocalhost() && (
                    <div className="p-3.5 bg-amber-50 border border-amber-200/80 rounded-xl text-left">
                      <div className="flex items-center gap-1.5 text-amber-800 font-bold text-xs mb-1">
                        <AlertCircle size={14} className="text-amber-600" />
                        <span>🔌 Desarrollo Local: Configuración de QR</span>
                      </div>
                      <p className="text-[11px] text-amber-700 leading-relaxed mb-2.5">
                        Al desarrollar localmente, <code className="bg-amber-100/80 px-1 rounded font-mono">localhost</code> no funciona desde el navegador de tu celular. Ingresa la dirección IP local de esta computadora (ej: <code className="bg-amber-100/80 px-1 rounded font-mono">192.168.1.75:5173</code>) para actualizar el código QR y poder escanearlo:
                      </p>
                      <div className="flex gap-2">
                        <input
                          type="text"
                          placeholder="ej: 192.168.1.75:5173"
                          value={qrIpOverride}
                          onChange={(e) => handleQrIpChange(e.target.value)}
                          className="w-full text-xs bg-white border border-amber-300 px-3 py-1.5 rounded-lg text-slate-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 placeholder-slate-400 font-mono shadow-sm transition-all"
                        />
                      </div>
                    </div>
                  )}
                </div>
              )}

              <div className="bg-slate-50 border border-slate-100 rounded-2xl p-6 text-center mb-8 relative overflow-hidden">
                <h4 className="text-sm font-bold text-slate-500 mb-1">KIOSCO KI-RELOJ ACTIVO</h4>
                <p className="text-xs text-slate-400 mb-6">{companyName}</p>

                {clockInSuccess ? (
                  <div className="py-6 animate-in zoom-in-95">
                    <div className="w-16 h-16 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center mx-auto mb-4 border border-emerald-100">
                      <CheckCircle2 size={32} />
                    </div>
                    <h5 className="font-bold text-slate-800">¡Fichaje Registrado!</h5>
                    <p className="text-xs text-slate-500 mt-1">Check-in de entrada exitoso para {empName}</p>
                  </div>
                ) : (
                  <div className="py-4">
                    <p className="text-sm text-slate-600 mb-6">
                      Simula el primer registro de entrada de tu nuevo empleado para constatar cómo la Inteligencia Artificial procesa el fichaje y activa sus tareas diarias.
                    </p>
                    <button 
                      onClick={handleTestClockIn}
                      disabled={loading}
                      className="py-3 px-6 bg-slate-900 hover:bg-slate-800 disabled:opacity-50 text-white rounded-xl text-sm font-bold shadow-md transition-all flex items-center gap-2 mx-auto"
                    >
                      {loading ? <Loader2 size={16} className="animate-spin" /> : <><Clock size={16} /> Fichar Entrada Piloto</>}
                    </button>
                  </div>
                )}
              </div>

              {!clockInSuccess && (
                <div className="flex gap-4">
                  <button 
                    onClick={() => setStep(5)}
                    className="flex-1 py-3 border border-slate-200 hover:bg-slate-50 text-slate-600 rounded-xl font-bold transition-all"
                  >
                    Omitir Prueba
                  </button>
                </div>
              )}
            </div>
          )}

          {step === 5 && (
            <div className="text-center py-6 animate-in fade-in">
              <div className="w-20 h-20 bg-emerald-50 text-emerald-500 rounded-3xl mx-auto flex items-center justify-center mb-6 shadow-sm border border-emerald-100 transform rotate-3">
                <CheckCircle2 size={40} />
              </div>
              <h2 className="text-3xl font-black text-slate-800 mb-2 tracking-tight">¡Configuración Exitosa!</h2>
              <p className="text-slate-600 text-base max-w-md mx-auto leading-relaxed mb-6">
                {createdEmpPin 
                  ? "Has configurado la sucursal, el puesto y registrado al primer empleado. Todo está listo para que el equipo trabaje en piloto automático." 
                  : "Has inicializado tu sucursal con éxito. Ya puedes comenzar a crear puestos y colaboradores en el panel principal o cargar datos demo abajo."}
              </p>

              {/* Estado del envío de notificaciones vía WhatsApp API */}
              {createdEmpPin && (
                <div className="max-w-md mx-auto mb-8">
                  {waSentStatus.sending && (
                    <div className="p-4 bg-slate-50 border border-slate-100 rounded-2xl flex items-center justify-center gap-3 text-slate-600 text-sm shadow-sm animate-pulse">
                      <Loader2 size={18} className="animate-spin text-blue-600 shrink-0" />
                      <span>Enviando accesos y bienvenida vía WhatsApp (Talent 360 API)...</span>
                    </div>
                  )}
                  {waSentStatus.sent && (
                    <div className="p-5 bg-emerald-50/50 border border-emerald-100 rounded-2xl flex flex-col gap-2 text-left shadow-sm animate-in fade-in">
                      <div className="flex items-center gap-2 font-bold text-emerald-800 text-sm">
                        <CheckCircle2 size={18} className="text-emerald-600 shrink-0" />
                        <span>Notificaciones enviadas vía WhatsApp</span>
                      </div>
                      <p className="text-xs text-emerald-700 leading-relaxed pl-6">
                        Se han enviado los accesos móviles y mensajes de bienvenida automáticamente desde la API del canal oficial de <strong>Talent 360</strong>.
                      </p>
                      <div className="pl-6 pt-1 space-y-1 text-[11px] text-slate-500 font-mono">
                        {adminPhone.trim() && <div>• Administrador (+{adminPhone.replace(/\D/g, '')}): Enviado 🟢</div>}
                        {empPhone.trim() && <div>• Colaborador ({empName} - +{empPhone.replace(/\D/g, '')}): Enviado 🟢</div>}
                      </div>
                    </div>
                  )}
                  {waSentStatus.error && (
                    <div className="p-5 bg-rose-50/50 border border-rose-100 rounded-2xl flex flex-col gap-2 text-left shadow-sm animate-in fade-in">
                      <div className="flex items-center gap-2 font-bold text-rose-800 text-sm">
                        <AlertCircle size={18} className="text-rose-600 shrink-0" />
                        <span>Notificación por WhatsApp pendiente</span>
                      </div>
                      <p className="text-xs text-rose-700 leading-relaxed pl-6">
                        {waSentStatus.error}. Puedes realizar el reenvío manual de los accesos utilizando los controles de paso anterior si lo requieres.
                      </p>
                    </div>
                  )}
                </div>
              )}

              {/* Tarjeta de Datos Demo Opcionales */}
              <div className="bg-slate-50 border border-slate-200/80 rounded-2xl p-4 sm:p-5 max-w-md mx-auto mb-6 text-left space-y-3">
                <h4 className="text-xs font-bold text-slate-700 uppercase tracking-wider">🛠️ ¿Deseas cargar datos de demostración?</h4>
                <p className="text-[11px] text-slate-500 leading-normal">
                  Explora la plataforma con puestos y colaboradores ficticios preconfigurados. Podrás eliminarlos en cualquier momento.
                </p>
                <div className="space-y-3 pt-1">
                  <label className="flex items-start gap-2.5 text-xs text-slate-700 font-medium cursor-pointer select-none">
                    <input 
                      type="checkbox" 
                      checked={loadDemoRoles}
                      onChange={(e) => {
                        setLoadDemoRoles(e.target.checked);
                        if (!e.target.checked) setLoadDemoEmployees(false);
                      }}
                      className="rounded border-slate-300 text-blue-600 focus:ring-blue-500 h-4 w-4 mt-0.5"
                    />
                    <div>
                      <span className="font-bold block">Puestos de trabajo de prueba</span>
                      <span className="text-[10px] text-slate-400">Inserta 4 puestos (Gerente, Cajero, etc.) con sus horarios y tareas automáticas.</span>
                    </div>
                  </label>
                  
                  <label className="flex items-start gap-2.5 text-xs text-slate-700 font-medium cursor-pointer select-none">
                    <input 
                      type="checkbox" 
                      checked={loadDemoEmployees}
                      disabled={!loadDemoRoles}
                      onChange={(e) => setLoadDemoEmployees(e.target.checked)}
                      className="disabled:opacity-50 rounded border-slate-300 text-blue-600 focus:ring-blue-500 h-4 w-4 mt-0.5"
                    />
                    <div>
                      <span className={`font-bold block ${!loadDemoRoles ? 'text-slate-400' : ''}`}>Colaboradores adicionales demo</span>
                      <span className="text-[10px] text-slate-400">Agrega 5 empleados ficticios asignados a los puestos demo para ver flujo en tiempo real.</span>
                    </div>
                  </label>
                </div>
              </div>

              <button 
                onClick={handleFinishOnboarding}
                disabled={loadingDemo}
                className="py-4 px-8 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-2xl font-black text-md shadow-lg shadow-blue-500/20 transition-all flex items-center gap-2 mx-auto active:scale-95"
              >
                {loadingDemo ? <Loader2 size={18} className="animate-spin" /> : <>Ingresar al Centro de Mando</>}
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
