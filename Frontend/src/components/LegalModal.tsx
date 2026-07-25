import React, { useState } from 'react';
import { ShieldCheck, FileText, X, Lock, CheckCircle2, Building2, UserCheck, AlertTriangle } from 'lucide-react';

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
              <p className="text-xs text-slate-400 font-medium">Marco Legal, SLA B2B y Tratamiento de Datos — Talent360</p>
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
            Aviso de Privacidad Integral
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
            Términos y Condiciones (TOS & SLA)
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
          
          {/* TAB 1: AVISO DE PRIVACIDAD */}
          {activeTab === 'privacy' && (
            <div className="space-y-5">
              <div className="bg-indigo-950/40 border border-indigo-500/30 rounded-2xl p-4 flex items-start gap-3">
                <Lock className="w-5 h-5 text-indigo-400 shrink-0 mt-0.5" />
                <div className="text-slate-200 text-xs">
                  <h4 className="font-extrabold text-white text-sm mb-1">Aviso de Privacidad Integral conforme a la LFPDPPP (México)</h4>
                  <p className="text-slate-300 text-xs leading-relaxed">
                    Talent360 protege la privacidad de los clientes, empresas suscriptoras y colaboradores. Este documento establece los términos de recolección, uso, almacenamiento cifrado y transferencia de datos.
                  </p>
                </div>
              </div>

              <section className="space-y-2">
                <h4 className="text-sm font-black text-white uppercase tracking-wider flex items-center gap-2">
                  <Building2 className="w-4 h-4 text-indigo-400" /> 1. Identidad y Rol del Responsable
                </h4>
                <p>
                  <strong>TALENT360</strong> (en lo sucesivo "LA PLATAFORMA"), accesible desde <code className="bg-slate-800 text-indigo-300 px-1.5 py-0.5 rounded font-mono text-[11px]">https://talent360.app</code>, actúa como:
                </p>
                <ul className="list-disc pl-5 space-y-1 text-slate-300">
                  <li><strong>Responsable</strong> del tratamiento de los datos de contacto, fiscales y facturación de las Empresas Suscriptoras (Clientes B2B).</li>
                  <li><strong>Encargado</strong> del tratamiento de los datos laborales, fotografías de fichaje y pre-nómina ingresados por las Empresas Suscriptoras para sus empleados.</li>
                </ul>
              </section>

              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider">2. Datos Personales Recabados</h4>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <div className="bg-slate-850 p-3.5 rounded-xl border border-slate-800 space-y-1">
                    <h5 className="font-bold text-indigo-300 text-xs">A. Empresa y Administradores</h5>
                    <p className="text-[11px] text-slate-400">Razón social, RFC, domicilio fiscal, nombre de representante legal, correo, teléfono y credenciales encriptadas de pago en Stripe.</p>
                  </div>
                  <div className="bg-slate-850 p-3.5 rounded-xl border border-slate-800 space-y-1">
                    <h5 className="font-bold text-indigo-300 text-xs">B. Datos Laborales de Empleados</h5>
                    <p className="text-[11px] text-slate-400">Nombre completo, CURP, RFC, NSS, puesto, sucursal/tienda, salario base, historial de entradas/salidas y rendimiento en cursos.</p>
                  </div>
                  <div className="bg-slate-850 p-3.5 rounded-xl border border-slate-800 space-y-1 md:col-span-2">
                    <h5 className="font-bold text-amber-300 text-xs">C. Evidencia Fotográfica y Geolocalización (Datos Sensibles)</h5>
                    <p className="text-[11px] text-slate-400">Fotografías tomadas al fichar o durante aperturas de tienda, direcciones IP y coordenadas GPS de sucursal. Se utilizan de forma exclusiva para garantizar la integridad operativa y evitar la suplantación de identidad laboral.</p>
                  </div>
                </div>
              </section>

              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider">3. Finalidades del Tratamiento</h4>
                <ul className="list-disc pl-5 space-y-1 text-slate-300">
                  <li><strong>Primarias:</strong> Operación del reloj checador (Offline-First), cálculo de pre-nómina LFT, control de aperturas de tiendas, gestión de candidatos ATS, impartición de cursos en la Academia y cobro de suscripción con facturación CFDI 4.0.</li>
                  <li><strong>Secundarias:</strong> Notificaciones de mantenimiento, métricas de uptime anonimizadas y mejoras continuas de seguridad.</li>
                </ul>
              </section>

              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider">4. Almacenamiento Seguro y Transferencias</h4>
                <p>
                  Todos los archivos y registros son almacenados con cifrado en reposo y en tránsito dentro de <strong>Google Cloud Platform (GCP)</strong> y procesados mediante pasarelas certificadas PCI-DSS (Stripe). No realizamos venta ni comercialización de información a ningún tercero.
                </p>
              </section>
            </div>
          )}

          {/* TAB 2: TERMINOS Y CONDICIONES (TOS & SLA) */}
          {activeTab === 'terms' && (
            <div className="space-y-5">
              <div className="bg-indigo-950/40 border border-indigo-500/30 rounded-2xl p-4 flex items-start gap-3">
                <FileText className="w-5 h-5 text-indigo-400 shrink-0 mt-0.5" />
                <div className="text-slate-200 text-xs">
                  <h4 className="font-extrabold text-white text-sm mb-1">Términos y Condiciones del Servicio (TOS) & SLA B2B</h4>
                  <p className="text-slate-300 text-xs leading-relaxed">
                    Contrato de suscripción y nivel de servicio para empresas que utilizan la plataforma Talent360 para su gestión de personal y operaciones.
                  </p>
                </div>
              </div>

              <section className="space-y-2">
                <h4 className="text-sm font-black text-white uppercase tracking-wider">1. Licencia de Uso</h4>
                <p>
                  Talent360 concede a la Empresa Suscriptora una licencia revocable, no exclusiva, limitada y no transferible para utilizar la plataforma durante el periodo pagado de su plan (Mensual o Anual).
                </p>
              </section>

              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider">2. Nivel de Servicio (SLA) & Resiliencia Offline</h4>
                <div className="bg-slate-850 p-4 rounded-2xl border border-slate-800 space-y-2">
                  <div className="flex items-center gap-2 text-emerald-400 font-bold text-xs">
                    <CheckCircle2 className="w-4 h-4" /> Disponibilidad Garantizada de Servidores: 99.5% Uptime Mensual
                  </div>
                  <p className="text-slate-400 text-[11px]">
                    El módulo de Reloj Checador PWA cuenta con arquitectura Offline-First. Si la sucursal de la Empresa pierde conexión a Internet, los registros se retienen localmente en el dispositivo de forma encriptada y se sincronizan en cuanto la conexión se restablezca.
                  </p>
                </div>
              </section>

              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider">3. Pagos, Facturación (CFDI 4.0) y Renovaciones</h4>
                <p>
                  Las suscripciones se renuevan automáticamente en la pasarela de pagos. Al ser procesado el cobro exitoso, la plataforma genera de inmediato la factura electrónica CFDI 4.0 correspondiente enviándola al correo registrado.
                </p>
              </section>

              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider">4. Propiedad Intelectual</h4>
                <p>
                  El código fuente, diseño, marcas y algoritmos de Talent360 pertenecen a la plataforma. La Empresa Suscriptora mantiene la titularidad absoluta de sus expedientes, documentos de bóveda y datos laborales.
                </p>
              </section>
            </div>
          )}

          {/* TAB 3: DERECHOS ARCO & BIOMETRICOS */}
          {activeTab === 'arco' && (
            <div className="space-y-5">
              <div className="bg-amber-950/30 border border-amber-500/30 rounded-2xl p-4 flex items-start gap-3">
                <AlertTriangle className="w-5 h-5 text-amber-400 shrink-0 mt-0.5" />
                <div className="text-slate-200 text-xs">
                  <h4 className="font-extrabold text-white text-sm mb-1">Mecanismos para Ejercer Derechos ARCO & Protocolo Biométrico</h4>
                  <p className="text-slate-300 text-xs leading-relaxed">
                    Derechos de Acceso, Rectificación, Cancelación y Oposición para titulares de datos personales registrados en el sistema.
                  </p>
                </div>
              </div>

              <section className="space-y-3">
                <h4 className="text-sm font-black text-white uppercase tracking-wider">1. ¿Cómo solicitar un trámite ARCO?</h4>
                <p>
                  Cualquier colaborador o representante puede solicitar el ejercicio de sus derechos enviando un correo con el asunto <strong>"Solicitud ARCO - Talent360"</strong> a:
                </p>
                <div className="bg-slate-950 p-3 rounded-xl border border-slate-800 text-center font-mono text-indigo-400 font-bold text-sm">
                  privacidad@talent360.app
                </div>
                <p className="text-[11px] text-slate-400">
                  La solicitud debe adjuntar identificación oficial vigente (INE/Pasaporte), nombre de la empresa donde labora y la especificación clara del derecho a ejercer. Tiempo de respuesta legal: <strong>20 días hábiles</strong>.
                </p>
              </section>

              <section className="space-y-2 border-t border-slate-800 pt-4">
                <h4 className="text-sm font-black text-white uppercase tracking-wider">2. Consentimiento de Evidencia Fotográfica en Fichaje</h4>
                <p className="text-slate-300">
                  Al utilizar el PIN de asistencia en el Reloj Checador PWA o en el dialer de apertura, el usuario otorga su consentimiento para la captura de fotografía instantánea como medio de prueba de su jornada laboral.
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
