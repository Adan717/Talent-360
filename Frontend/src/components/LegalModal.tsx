import React, { useState } from 'react';
import { ShieldCheck, FileText, X, Lock, CheckCircle2, Building2, UserCheck, AlertTriangle, KeyRound, CreditCard, Cpu, Database, Scale, Globe, RefreshCcw } from 'lucide-react';

export type LegalDocType = 'privacy' | 'terms' | 'arco';

interface LegalModalProps {
  isOpen: boolean;
  onClose: () => void;
  defaultTab?: LegalDocType;
}

export const LegalModal: React.FC<LegalModalProps> = ({ isOpen, onClose, defaultTab = 'privacy' }) => {
  const [activeTab, setActiveTab] = useState<LegalDocType>(defaultTab);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-4 overflow-y-auto animate-in fade-in duration-200">
      <div className="bg-slate-900 border border-slate-700/80 w-full max-w-4xl rounded-3xl shadow-2xl flex flex-col max-h-[90vh] text-slate-100 overflow-hidden">
        
        {/* Modal Header */}
        <div className="px-6 py-4 bg-slate-950 border-b border-slate-800 flex items-center justify-between shrink-0">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-2xl bg-indigo-500/10 border border-indigo-500/30 flex items-center justify-center text-indigo-400">
              <ShieldCheck className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-black text-white flex items-center gap-2">
                Centro de Protección Legal & Privacidad <span className="text-[10px] bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 px-2 py-0.5 rounded-full font-bold uppercase tracking-wider">LFPDPPP & TOS</span>
              </h2>
              <p className="text-xs text-slate-400 font-medium">Marco Legal Completo, SLA B2B y Tratamiento de Datos — Talent360</p>
            </div>
          </div>
          <button 
            onClick={onClose}
            className="p-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-xl transition cursor-pointer"
            title="Cerrar ventana"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Tab Selector */}
        <div className="px-6 py-2.5 bg-slate-900/90 border-b border-slate-800 flex gap-2 overflow-x-auto shrink-0">
          <button
            type="button"
            onClick={() => setActiveTab('privacy')}
            className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer ${
              activeTab === 'privacy' 
                ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/20' 
                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60'
            }`}
          >
            <ShieldCheck className="w-4 h-4" />
            Aviso de Privacidad (8 Puntos)
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('terms')}
            className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer ${
              activeTab === 'terms' 
                ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/20' 
                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60'
            }`}
          >
            <FileText className="w-4 h-4" />
            Términos del Servicio (7 Puntos TOS & SLA)
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('arco')}
            className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all cursor-pointer ${
              activeTab === 'arco' 
                ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/20' 
                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60'
            }`}
          >
            <UserCheck className="w-4 h-4" />
            Derechos ARCO & Biométricos
          </button>
        </div>

        {/* Scrollable Content */}
        <div className="p-6 overflow-y-auto space-y-6 text-slate-300 text-xs leading-relaxed font-sans scrollbar-thin scrollbar-thumb-slate-700">
          
          {/* TAB 1: AVISO DE PRIVACIDAD INTEGRAL COMPLETO (8 PUNTOS) */}
          {activeTab === 'privacy' && (
            <div className="space-y-6">
              <div className="bg-indigo-950/40 border border-indigo-500/30 rounded-2xl p-4 flex items-start gap-3">
                <Lock className="w-5 h-5 text-indigo-400 shrink-0 mt-0.5" />
                <div className="text-slate-200 text-xs">
                  <h4 className="font-extrabold text-white text-sm mb-1">Aviso de Privacidad Integral Completo conforme a la LFPDPPP (México)</h4>
                  <p className="text-slate-300 text-xs leading-relaxed">
                    Última actualización: 24 de Julio de 2026. TALENT360 cumple cabalmente con la Ley Federal de Protección de Datos Personales en Posesión de los Particulares.
                  </p>
                </div>
              </div>

              {/* Punto 1 */}
              <section className="space-y-2">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <Building2 className="w-4 h-4 text-indigo-400" /> 1. Identidad y Rol del Responsable
                </h4>
                <p>
                  <strong>TALENT360</strong> (en lo sucesivo "LA PLATAFORMA"), accesible desde el portal web <code className="bg-slate-800 text-indigo-300 px-1.5 py-0.5 rounded font-mono text-[11px]">https://talent360.com.mx</code>, opera bajo un modelo de Software como Servicio (SaaS) B2B:
                </p>
                <ul className="list-disc pl-5 space-y-1.5 text-slate-300">
                  <li><strong>Respecto a los datos de la Empresa Suscriptora (Cliente):</strong> Talent360 actúa como <strong>RESPONSABLE</strong> del tratamiento de los datos de contacto, fiscales y de facturación del representante legal y administradores.</li>
                  <li><strong>Respecto a los datos de los Empleados/Colaboradores:</strong> La Empresa Suscriptora actúa como <strong>RESPONSABLE</strong> y Talent360 actúa como <strong>ENCARGADO</strong> del tratamiento, procesando la información exclusivamente para la prestación del servicio SaaS bajo las instrucciones del Cliente.</li>
                </ul>
              </section>

              {/* Punto 2 */}
              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <Database className="w-4 h-4 text-indigo-400" /> 2. Datos Personales Recabados
                </h4>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <div className="bg-slate-850 p-3.5 rounded-xl border border-slate-800 space-y-1">
                    <h5 className="font-bold text-indigo-300 text-xs">A. Datos de la Empresa y Administradores</h5>
                    <p className="text-[11px] text-slate-400">Razón Social, RFC, Domicilio Fiscal, Nombre del Representante Legal, Correo Electrónico, Teléfono y Datos Financieros de Tarjeta (procesados de manera encriptada por Stripe).</p>
                  </div>
                  <div className="bg-slate-850 p-3.5 rounded-xl border border-slate-800 space-y-1">
                    <h5 className="font-bold text-indigo-300 text-xs">B. Datos Identificativos y Laborales de Colaboradores</h5>
                    <p className="text-[11px] text-slate-400">Nombre completo, CURP, RFC, Número de Seguro Social (NSS), Puesto, Sucursal/Tienda, Horario Laboral, Salario Base, Fecha de Ingreso y Estructura Jerárquica.</p>
                  </div>
                  <div className="bg-slate-850 p-3.5 rounded-xl border border-slate-800 space-y-1">
                    <h5 className="font-bold text-amber-300 text-xs">C. Evidencia Fotográfica y Geolocalización (Datos Sensibles)</h5>
                    <p className="text-[11px] text-slate-400">Fotografías tomadas desde la cámara web/dispositivo al fichar entrada/salida o registrar comidas, IP y coordenadas GPS. Utilizadas únicamente como evidencia fotográfica de presencia e integridad operativa.</p>
                  </div>
                  <div className="bg-slate-850 p-3.5 rounded-xl border border-slate-800 space-y-1">
                    <h5 className="font-bold text-indigo-300 text-xs">D. Datos de Capacitación y Evaluación</h5>
                    <p className="text-[11px] text-slate-400">Progreso de lectura, resultados de exámenes, intentos registrados y certificaciones emitidas dentro de la Academia Talent360.</p>
                  </div>
                </div>
              </section>

              {/* Punto 3 */}
              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <Cpu className="w-4 h-4 text-indigo-400" /> 3. Finalidades del Tratamiento de los Datos
                </h4>
                <div className="space-y-2">
                  <h5 className="font-bold text-white text-xs">Finalidades Primarias (Necesarias para la prestación del servicio):</h5>
                  <ul className="list-disc pl-5 space-y-1 text-slate-300">
                    <li>Crear y administrar la cuenta SaaS multi-inquilino de la Empresa Suscriptora.</li>
                    <li>Registrar y verificar la asistencia, puntualidad y aperturas de sucursales en tiempo real y en modo Offline-First.</li>
                    <li>Calcular pre-nóminas, incidencias, deducciones y horas extra conforme a la Ley Federal del Trabajo (LFT).</li>
                    <li>Gestionar procesos de reclutamiento (ATS), recepción de postulaciones y seguimiento de vacantes.</li>
                    <li>Impartir cursos de capacitación y evaluar el desempeño operativo del personal.</li>
                    <li>Procesar el cobro periódico de la suscripción y emitir las facturas electrónicas (CFDI 4.0) correspondientes.</li>
                  </ul>
                  <h5 className="font-bold text-white text-xs pt-1">Finalidades Secundarias:</h5>
                  <ul className="list-disc pl-5 space-y-1 text-slate-300">
                    <li>Generar estadísticas consolidadas e integradas de rendimiento operacional (datos 100% anonimizados).</li>
                    <li>Enviar notificaciones del sistema, avisos de mantenimiento y actualizaciones de la plataforma.</li>
                  </ul>
                </div>
              </section>

              {/* Punto 4 */}
              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <Lock className="w-4 h-4 text-indigo-400" /> 4. Tratamiento de Evidencia Fotográfica y Datos Biométricos
                </h4>
                <p>
                  En caso de que el fichaje requiera fotografía desde la PWA del reloj checador o el dialer de apertura:
                </p>
                <ul className="list-disc pl-5 space-y-1 text-slate-300">
                  <li>La fotografía se captura con el único propósito de validar la identidad física del colaborador en el instante del fichaje.</li>
                  <li>Las imágenes son almacenadas de forma cifrada en la nube (<strong>Google Cloud Storage</strong>) bajo protocolos estrictos de aislamiento por inquilino (<code className="bg-slate-800 text-indigo-300 px-1 py-0.5 rounded font-mono text-[10px]">TenantScope</code>).</li>
                  <li>No se venderán, comercializarán ni compartirán estas imágenes con ningún tercero bajo ninguna circunstancia.</li>
                </ul>
              </section>

              {/* Punto 5 */}
              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <Globe className="w-4 h-4 text-indigo-400" /> 5. Transferencia de Datos Personales
                </h4>
                <p>
                  Talent360 no transfiere datos personales a terceros sin su consentimiento, salvo las excepciones previstas en el Artículo 37 de la LFPDPPP, limitándose estrictamente a los siguientes proveedores de infraestructura (Encargados de infraestructura):
                </p>
                <ul className="list-disc pl-5 space-y-1 text-slate-300">
                  <li><strong>Google Cloud Platform (GCP):</strong> Servicio de hospedaje, base de datos PostgreSQL encriptada y almacenamiento de archivos.</li>
                  <li><strong>Stripe / Mercado Pago:</strong> Procesamiento de pagos con tarjeta bajo cumplimiento estándar PCI-DSS.</li>
                  <li><strong>Proveedores Autorizados de Certificación (PAC):</strong> Emisión automatizada de facturas electrónicas CFDI ante el SAT.</li>
                </ul>
              </section>

              {/* Punto 6 */}
              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <UserCheck className="w-4 h-4 text-indigo-400" /> 6. Mecanismo para Ejercer los Derechos ARCO y Revocación del Consentimiento
                </h4>
                <p>
                  Usted o sus colaboradores tienen derecho a conocer qué datos personales tenemos, para qué los utilizamos y las condiciones del uso que les damos (<strong>Acceso</strong>); solicitar la corrección de su información (<strong>Rectificación</strong>); que la eliminemos de nuestras bases de datos (<strong>Cancelación</strong>); u oponerse al uso de sus datos para fines específicos (<strong>Oposición</strong>).
                </p>
                <p>
                  Para ejercer sus <strong>Derechos ARCO</strong>, deberá enviar una solicitud al correo electrónico oficial:
                </p>
                <div className="bg-slate-950 p-2.5 rounded-xl border border-slate-800 text-center font-mono text-indigo-400 font-bold text-xs">
                  📧 privacidad@talent360.com.mx
                </div>
                <p className="text-[11px] text-slate-400">
                  Requisitos: Adjuntar identificación oficial vigente (INE/Pasaporte), nombre de la empresa suscriptora y detalle preciso del derecho a ejercer. Plazo de respuesta legal: <strong>20 (veinte) días hábiles</strong>.
                </p>
              </section>

              {/* Punto 7 */}
              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <RefreshCcw className="w-4 h-4 text-indigo-400" /> 7. Uso de Cookies y Almacenamiento Local (LocalStorage)
                </h4>
                <p>
                  Talent360 utiliza cookies HttpOnly de sesión y tecnología de almacenamiento local en el navegador (<code className="bg-slate-800 text-indigo-300 px-1 py-0.5 rounded font-mono text-[10px]">LocalStorage / IndexedDB</code>) para:
                </p>
                <ul className="list-disc pl-5 space-y-1 text-slate-300">
                  <li>Mantener la sesión autenticada de manera segura (Laravel Sanctum).</li>
                  <li>Permitir que el Reloj Checador siga funcionando en sucursales <strong>sin conexión a Internet (Offline-First)</strong> y sincronice automáticamente los datos al restablecerse la red.</li>
                </ul>
              </section>

              {/* Punto 8 */}
              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <Scale className="w-4 h-4 text-indigo-400" /> 8. Cambios al Aviso de Privacidad
                </h4>
                <p>
                  Talent360 se reserva el derecho de efectuar en cualquier momento modificaciones o actualizaciones al presente Aviso de Privacidad. Dichas modificaciones estarán disponibles en el portal web <code className="bg-slate-800 text-indigo-300 px-1.5 py-0.5 rounded font-mono text-[11px]">https://talent360.com.mx/privacidad</code> y/o mediante notificación dentro del panel de administración del sistema.
                </p>
              </section>
            </div>
          )}

          {/* TAB 2: TÉRMINOS Y CONDICIONES (7 PUNTOS TOS & SLA B2B COMPLETO) */}
          {activeTab === 'terms' && (
            <div className="space-y-6">
              <div className="bg-indigo-950/40 border border-indigo-500/30 rounded-2xl p-4 flex items-start gap-3">
                <FileText className="w-5 h-5 text-indigo-400 shrink-0 mt-0.5" />
                <div className="text-slate-200 text-xs">
                  <h4 className="font-extrabold text-white text-sm mb-1">Términos y Condiciones del Servicio (TOS) & SLA B2B (7 Puntos Completos)</h4>
                  <p className="text-slate-300 text-xs leading-relaxed">
                    Última actualización: 24 de Julio de 2026. Al registrar una empresa o utilizar Talent360, el Cliente acepta estos 7 puntos de vinculación contractual.
                  </p>
                </div>
              </div>

              {/* Punto 1 */}
              <section className="space-y-2">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <KeyRound className="w-4 h-4 text-indigo-400" /> 1. Objeto de la Licencia
                </h4>
                <p>
                  Talent360 otorga al Cliente una licencia de uso <strong>no exclusiva, revocable, limitada, no transferible y de suscripción periódica</strong> para acceder y utilizar la plataforma SaaS de administración de recursos humanos, control de asistencia, nómina LFT, reclutamiento y operaciones corporativas durante el periodo contratado.
                </p>
              </section>

              {/* Punto 2 */}
              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <UserCheck className="w-4 h-4 text-indigo-400" /> 2. Relación y Roles de Protección de Datos (Encargado vs Responsable)
                </h4>
                <ul className="list-disc pl-5 space-y-1.5 text-slate-300">
                  <li>El Cliente reconoce que es el único <strong>Responsable</strong> respecto al tratamiento de los datos personales y evidencias fotográficas de sus empleados ingresados a la plataforma.</li>
                  <li>El Cliente garantiza que cuenta con las autorizaciones laborales, contratos de trabajo y avisos de privacidad correspondientes de su personal para el uso del reloj checador.</li>
                  <li>Talent360 actuará como <strong>Encargado</strong>, limitándose a procesar los datos de conformidad con las instrucciones del Cliente y las medidas de seguridad del servicio.</li>
                </ul>
              </section>

              {/* Punto 3 */}
              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <CreditCard className="w-4 h-4 text-indigo-400" /> 3. Planes, Pagos y Facturación (CFDI 4.0)
                </h4>
                <div className="space-y-2">
                  <ul className="list-disc pl-5 space-y-1 text-slate-300">
                    <li><strong>Suscripción Recurrente:</strong> El acceso a Talent360 se factura por adelantado de manera mensual o anual según el plan seleccionado.</li>
                    <li><strong>Cobro Automatizado:</strong> Los pagos se procesan a través de pasarelas de pago autorizadas (Stripe / Mercado Pago). El Cliente autoriza el cobro automático a su tarjeta.</li>
                    <li><strong>Facturación Fiscal (CFDI 4.0):</strong> Al realizarse el cobro, el sistema emitirá de forma automática la factura fiscal digital en formato XML y PDF con los datos fiscales proporcionados por el Cliente.</li>
                    <li><strong>Falta de Pago y Suspensión:</strong> En caso de no poder procesar el cobro en la fecha de renovación, Talent360 otorgará un periodo de gracia de 5 días naturales. Transcurrido dicho plazo, el acceso al inquilino podrá ser suspendido temporalmente.</li>
                  </ul>
                </div>
              </section>

              {/* Punto 4 */}
              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <CheckCircle2 className="w-4 h-4 text-indigo-400" /> 4. Acuerdo de Nivel de Servicio (SLA) y Disponibilidad
                </h4>
                <div className="bg-slate-850 p-4 rounded-2xl border border-slate-800 space-y-2">
                  <div className="flex items-center gap-2 text-emerald-400 font-bold text-xs">
                    <CheckCircle2 className="w-4 h-4" /> Disponibilidad Mensual Garantizada: 99.5% Uptime Servidores
                  </div>
                  <p className="text-slate-400 text-[11px] leading-relaxed">
                    <strong>Resiliencia Offline-First:</strong> El módulo de Reloj Checador PWA está diseñado para continuar operando en las sucursales de la Empresa aun cuando la sucursal pierda conexión a Internet. Los fichajes se almacenan localmente y se sincronizan al restablecerse la red.
                  </p>
                  <p className="text-slate-400 text-[11px]">
                    <strong>Mantenimiento Programado:</strong> Talent360 notificará con al menos 24 horas de anticipación cualquier ventana de mantenimiento mayor que pueda interrumpir temporalmente el acceso a la plataforma web.
                  </p>
                </div>
              </section>

              {/* Punto 5 */}
              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <ShieldCheck className="w-4 h-4 text-indigo-400" /> 5. Propiedad Intelectual
                </h4>
                <ul className="list-disc pl-5 space-y-1.5 text-slate-300">
                  <li><strong>Propiedad de la Plataforma:</strong> Talent360, su código fuente (Laravel/React), arquitectura, bases de datos, marcas, logotipos, interfaces, algoritmos y diseño son propiedad exclusiva de Talent360.</li>
                  <li><strong>Propiedad de los Datos del Cliente:</strong> El Cliente mantendrá en todo momento la titularidad exclusiva sobre la información, expedientes de empleados, reportes de asistencia y documentos cargados en su bóveda privada (<code className="bg-slate-800 text-indigo-300 px-1 py-0.5 rounded font-mono text-[10px]">Vault</code>).</li>
                </ul>
              </section>

              {/* Punto 6 */}
              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <RefreshCcw className="w-4 h-4 text-indigo-400" /> 6. Cancelación y Terminación de la Cuenta
                </h4>
                <ul className="list-disc pl-5 space-y-1 text-slate-300">
                  <li>El Cliente podrá cancelar su suscripción en cualquier momento desde el panel de administración de SaaS (<code className="bg-slate-800 text-indigo-300 px-1 py-0.5 rounded font-mono text-[10px]">Configuración de Cuenta</code>).</li>
                  <li>Al cancelar, el Cliente mantendrá el acceso completo hasta el final del periodo mensual/anual ya pagado.</li>
                  <li>El Cliente podrá solicitar la exportación masiva de sus datos de nómina y empleados en formato Excel/CSV antes del cierre definitivo de la cuenta.</li>
                </ul>
              </section>

              {/* Punto 7 */}
              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <Scale className="w-4 h-4 text-indigo-400" /> 7. Limitación de Responsabilidad y Ley Aplicable
                </h4>
                <ul className="list-disc pl-5 space-y-1.5 text-slate-300">
                  <li>Talent360 no será responsable de multas, sanciones laborales o discrepancias legales derivadas del mal uso que el Cliente dé a las herramientas de pre-nómina, las cuales constituyen un asistente operativo y no sustituyen el asesoramiento laboral formal.</li>
                  <li>La responsabilidad máxima total de Talent360 ante el Cliente por cualquier reclamación no excederá la suma equivalente al monto pagado por el Cliente en los últimos 12 meses de servicio.</li>
                  <li>Estos Términos se rigen e interpretan de conformidad con las leyes vigentes y tribunales competentes de los <strong>Estados Unidos Mexicanos</strong>.</li>
                </ul>
              </section>
            </div>
          )}

          {/* TAB 3: DERECHOS ARCO & PROTOCOLO BIOMÉTRICO */}
          {activeTab === 'arco' && (
            <div className="space-y-6">
              <div className="bg-amber-950/30 border border-amber-500/30 rounded-2xl p-4 flex items-start gap-3">
                <AlertTriangle className="w-5 h-5 text-amber-400 shrink-0 mt-0.5" />
                <div className="text-slate-200 text-xs">
                  <h4 className="font-extrabold text-white text-sm mb-1">Mecanismos para Ejercer Derechos ARCO & Protocolo Biométrico</h4>
                  <p className="text-slate-300 text-xs leading-relaxed">
                    Instrucciones detalladas de ejercicio de Derechos de Acceso, Rectificación, Cancelación y Oposición para trabajadores y clientes conforme al INAI/LFPDPPP.
                  </p>
                </div>
              </div>

              <section className="space-y-3">
                <h4 className="text-sm font-black text-white uppercase tracking-wider">1. ¿Cómo solicitar un trámite ARCO?</h4>
                <p>
                  Cualquier colaborador o representante puede solicitar el ejercicio de sus derechos enviando un correo electrónico con el asunto <strong>"Solicitud ARCO - Talent360"</strong> a:
                </p>
                <div className="bg-slate-950 p-3 rounded-xl border border-slate-800 text-center font-mono text-indigo-400 font-bold text-sm">
                  📧 privacidad@talent360.com.mx
                </div>
                <div className="space-y-1 text-slate-300">
                  <p className="font-bold text-white text-xs">Documentación requerida en el correo:</p>
                  <ul className="list-disc pl-5 space-y-1 text-[11px] text-slate-300">
                    <li>Identificación oficial vigente con fotografía (INE o Pasaporte escaneado).</li>
                    <li>Nombre de la Empresa donde labora dentro de la plataforma Talent360.</li>
                    <li>Descripción clara y precisa de los datos personales respecto de los cuales desea ejercer el derecho (Acceso, Rectificación, Cancelación u Oposición).</li>
                    <li>Plazo de respuesta legal garantizado: <strong>20 (veinte) días hábiles</strong>.</li>
                  </ul>
                </div>
              </section>

              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider">2. Consentimiento de Evidencia Fotográfica en Fichaje</h4>
                <p className="text-slate-300">
                  Al utilizar el PIN de asistencia en el Reloj Checador PWA o en el dialer de apertura de tiendas, el usuario otorga su consentimiento expreso para la captura de fotografía instantánea como medio de prueba física de su jornada laboral, evitando suplantaciones de identidad.
                </p>
              </section>

              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider">3. Protocolo de Retención y Depuración Segura</h4>
                <p className="text-slate-300">
                  Las fotografías de fichaje e incidencias se conservan de forma encriptada durante el tiempo necesario para soportar la revisión de la pre-nómina y auditorías internas. Al ser dado de baja un empleado en el sistema, la empresa responsable puede solicitar la depuración definitiva de los archivos fotográficos asociados.
                </p>
              </section>
            </div>
          )}

        </div>

        {/* Modal Footer */}
        <div className="px-6 py-4 bg-slate-950 border-t border-slate-800 flex justify-between items-center shrink-0">
          <p className="text-[10px] text-slate-500 font-medium">Talent360 © 2026 — Plataforma Cumplimiento LFPDPPP & LFT</p>
          <button
            type="button"
            onClick={onClose}
            className="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs rounded-xl transition shadow-lg shadow-indigo-600/20 cursor-pointer"
          >
            Aceptar y Cerrar
          </button>
        </div>

      </div>
    </div>
  );
};
