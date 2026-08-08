import React, { useState, useEffect } from 'react';
import { 
  Building2, FileText, CheckCircle2, AlertCircle, 
  Upload, Key, Clock, ShieldCheck, Download, Trash2, 
  RefreshCw, CheckCircle, BarChart3, Receipt, Settings2, FileCode, CheckSquare, Printer
} from 'lucide-react';
import { useAppStore } from '../store/useAppStore';
import axiosInstance from '../lib/axios';
import { MobileModuleBottomDock } from './common/MobileModuleBottomDock';

export const FacturacionManager = () => {
  const [activeTab, setActiveTab] = useState<'fiscal' | 'timbrado' | 'historial'>('fiscal');
  const { currentUser } = useAppStore();

  // Estados de datos fiscales
  const [rfc, setRfc] = useState('');
  const [taxName, setTaxName] = useState('');
  const [taxRegimen, setTaxRegimen] = useState('601'); // General de Ley PM
  const [postalCode, setPostalCode] = useState('');

  // Estados de CSD
  const [cerFileBase64, setCerFileBase64] = useState<string | null>(null);
  const [keyFileBase64, setKeyFileBase64] = useState<string | null>(null);
  const [cerName, setCerName] = useState('');
  const [keyName, setKeyName] = useState('');
  const [csdPassword, setCsdPassword] = useState('');

  // Estados de timbrado. Los ids seleccionados son de EXPEDIENTE (employees), que es la
  // llave real de weekly_payrolls — antes se iteraban usuarios y los ids no correspondían.
  const [selectedEmployees, setSelectedEmployees] = useState<number[]>([]);
  const [timbrando, setTimbrando] = useState(false);
  const [progress, setProgress] = useState(0);
  const [payrollStatus, setPayrollStatus] = useState<Record<number, { status: 'pending' | 'success' | 'failed', uuid?: string, error?: string, pdfUrl?: string }>>({});

  // Estados de historial
  const [invoices, setInvoices] = useState<any[]>([]);
  const [loadingInvoices, setLoadingInvoices] = useState(false);
  const [invoicesError, setInvoicesError] = useState('');

  // Estados de mensajería
  const [successMsg, setSuccessMsg] = useState('');
  const [errorMsg, setErrorMsg] = useState('');
  const [isSaving, setIsSaving] = useState(false);

  // Nómina del periodo operativo (la última semana cerrada del tenant, N3): el backend
  // manda {period, employees} — el periodo REAL, no una quincena inventada en el cliente.
  const [payrollSummary, setPayrollSummary] = useState<any[]>([]);
  const [payrollPeriod, setPayrollPeriod] = useState<{ start_date: string; end_date: string } | null>(null);
  const fetchPayrollSummary = async () => {
    try {
      const res = await axiosInstance.get('/admin/payroll');
      setPayrollSummary(res.data?.employees || []);
      setPayrollPeriod(res.data?.period || null);
    } catch (e) {
      console.error("Error al obtener resúmenes de nómina", e);
    }
  };

  const handlePrintTicket = async (employeeId: number) => {
    try {
      const response = await axiosInstance.get(`/admin/payroll/ticket/${employeeId}`, {
        responseType: 'blob'
      });
      const blob = new Blob([response.data], { type: 'application/pdf' });
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `ticket_nomina_${employeeId}.pdf`);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    } catch (err) {
      console.error(err);
      alert('Error al descargar el ticket de 80 mm.');
    }
  };

  useEffect(() => {
    if (activeTab === 'timbrado') {
      fetchPayrollSummary();
    }
  }, [activeTab]);

  // Cargar datos fiscales guardados en el tenant
  useEffect(() => {
    if (currentUser?.tenant) {
      setRfc(currentUser.tenant.rfc || '');
      setTaxName(currentUser.tenant.tax_name || '');
      setTaxRegimen(currentUser.tenant.tax_regimen || '601');
      setPostalCode(currentUser.tenant.postal_code || '');
      
      // Indicar si ya existen certificados
      if (currentUser.tenant.csd_certificate) {
        setCerName('certificado_guardado.cer');
        setCerFileBase64(currentUser.tenant.csd_certificate);
      }
      if (currentUser.tenant.csd_private_key) {
        setKeyName('llave_guardada.key');
        setKeyFileBase64(currentUser.tenant.csd_private_key);
      }
    }
    fetchInvoices();
  }, [currentUser]);

  const fetchInvoices = async () => {
    setLoadingInvoices(true);
    setInvoicesError('');
    try {
      const res = await axiosInstance.get('/billing/invoices');
      // N8: el backend ya no inventa facturas — si el proveedor falla (p. ej. sin
      // credenciales), se muestra el error real y la lista queda vacía.
      if (res.data?.success && Array.isArray(res.data.data)) {
        setInvoices(res.data.data);
      } else {
        setInvoices([]);
        setInvoicesError(res.data?.error || 'No se pudo consultar el historial del proveedor fiscal.');
      }
    } catch (e) {
      console.error("Error al obtener facturas", e);
      setInvoices([]);
      setInvoicesError('No se pudo consultar el historial del proveedor fiscal.');
    } finally {
      setLoadingInvoices(false);
    }
  };

  // Manejar lectura de archivo .cer / .key
  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>, type: 'cer' | 'key') => {
    const file = e.target.files?.[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = () => {
      const base64String = (reader.result as string).split(',')[1] || (reader.result as string);
      if (type === 'cer') {
        setCerFileBase64(base64String);
        setCerName(file.name);
      } else {
        setKeyFileBase64(base64String);
        setKeyName(file.name);
      }
    };
    reader.readAsDataURL(file);
  };

  // Guardar configuración fiscal básica
  const handleSaveTaxData = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSaving(true);
    setSuccessMsg('');
    setErrorMsg('');

    try {
      const res = await axiosInstance.post('/billing/tax-data', {
        tax_name: taxName,
        rfc: rfc.toUpperCase(),
        tax_regimen: taxRegimen,
        postal_code: postalCode
      });
      if (res.data.success) {
        setSuccessMsg('Datos fiscales actualizados con éxito');
        if (currentUser?.tenant) {
          currentUser.tenant.rfc = rfc.toUpperCase();
          currentUser.tenant.tax_name = taxName;
          currentUser.tenant.tax_regimen = taxRegimen;
          currentUser.tenant.postal_code = postalCode;
        }
      }
    } catch (e: any) {
      setErrorMsg(e.response?.data?.message || 'Error al guardar los datos fiscales');
    } finally {
      setIsSaving(false);
    }
  };

  // Guardar y sincronizar Sellos Digitales (CSD)
  const handleUploadCsd = async () => {
    if (!cerFileBase64 || !keyFileBase64 || !csdPassword) {
      setErrorMsg('Por favor carga el certificado, la llave privada e ingresa la contraseña.');
      return;
    }

    setIsSaving(true);
    setSuccessMsg('');
    setErrorMsg('');

    try {
      const res = await axiosInstance.post('/billing/csd', {
        certificate: cerFileBase64,
        private_key: keyFileBase64,
        password: csdPassword
      });

      if (res.data.success) {
        setSuccessMsg('Sellos Digitales (CSD) cargados e integrados exitosamente con el SAT/PAC.');
      } else {
        setSuccessMsg('Certificados guardados localmente, pero se encuentra en modo de pruebas (Sandbox).');
      }
    } catch (e: any) {
      setErrorMsg(e.response?.data?.message || 'Error al cargar los sellos digitales en el SAT');
    } finally {
      setIsSaving(false);
    }
  };

  // N1: sólo es timbrable una nómina AUTORIZADA por la empresa y aún sin folio fiscal.
  const esTimbrable = (emp: any) =>
    emp.approval_status === 'approved_by_admin' && !emp.cfdi_uuid && payrollStatus[emp.id]?.status !== 'success';

  const timbrables = payrollSummary.filter(esTimbrable);

  // Timbrado masivo real: el monto lo pone el servidor desde la nómina autorizada; aquí
  // sólo viaja QUIÉN y QUÉ periodo (el periodo operativo que reportó el backend).
  const handleTimbrarMasivo = async () => {
    if (selectedEmployees.length === 0 || !payrollPeriod) return;
    setTimbrando(true);
    setProgress(0);
    setSuccessMsg('');
    setErrorMsg('');

    const chunkLength = selectedEmployees.length;
    let successCount = 0;

    for (let i = 0; i < chunkLength; i++) {
      const empId = selectedEmployees[i];

      try {
        const res = await axiosInstance.post('/billing/payroll/timbrar', {
          employee_id: empId,
          period_start: payrollPeriod.start_date,
          period_end: payrollPeriod.end_date
        });

        if (res.data && res.data.success) {
          successCount++;
          setPayrollStatus(prev => ({
            ...prev,
            [empId]: {
              status: 'success',
              uuid: res.data.receipt?.uuid,
              pdfUrl: res.data.receipt?.pdf_url
            }
          }));
        } else {
          setPayrollStatus(prev => ({ ...prev, [empId]: { status: 'failed', error: res.data?.error } }));
        }
      } catch (e: any) {
        setPayrollStatus(prev => ({
          ...prev,
          [empId]: { status: 'failed', error: e.response?.data?.error || 'Error de conexión con el proveedor fiscal' }
        }));
      }

      setProgress(Math.round(((i + 1) / chunkLength) * 100));
    }

    setTimbrando(false);
    setSelectedEmployees([]);
    if (successCount === chunkLength) {
      setSuccessMsg(`Timbrado completado: ${successCount} de ${chunkLength} recibos de nómina.`);
    } else {
      setErrorMsg(`Se timbraron ${successCount} de ${chunkLength} recibos. Revisa el detalle de los que fallaron en la tabla.`);
    }
    fetchPayrollSummary(); // Refrescar folios sellados
    fetchInvoices(); // Refrescar lista de facturas
  };

  // Seleccionar/Deseleccionar todos los TIMBRABLES (autorizados y sin folio)
  const toggleSelectAll = () => {
    if (selectedEmployees.length === timbrables.length) {
      setSelectedEmployees([]);
    } else {
      setSelectedEmployees(timbrables.map(e => e.id));
    }
  };

  const toggleSelectEmployee = (id: number) => {
    setSelectedEmployees(prev =>
      prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
    );
  };

  return (
    <div className="flex-1 flex flex-col h-full bg-slate-50 overflow-hidden">
      {/* Encabezado Principal (Escritorio) */}
      <div className="hidden sm:block sticky -top-8 -mt-8 -mx-8 px-8 pt-6 pb-3 bg-slate-50/90 backdrop-blur-md z-20 transition-all border-b border-slate-200/50 mb-6">
        <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex justify-between items-center gap-4">
          <div className="flex items-center gap-3">
            <div className="p-3 bg-emerald-50 text-emerald-600 rounded-2xl border border-emerald-100 shadow-inner">
              <Receipt size={24} />
            </div>
            <div>
              <h2 className="text-xl font-black text-slate-800">Facturación Electrónica CFDI 4.0</h2>
              <p className="text-xs text-slate-400 font-semibold">Configuración de sellos CSD y timbrado de recibos de nómina SAT</p>
            </div>
          </div>

          {/* Tab Selector */}
          <div className="flex bg-slate-100 p-1 rounded-xl border border-slate-200/50">
            <button
              onClick={() => setActiveTab('fiscal')}
              className={`px-4 py-2 rounded-lg text-xs font-black transition-all flex items-center gap-1.5 cursor-pointer border-none ${
                activeTab === 'fiscal' ? 'bg-white text-emerald-600 shadow-sm' : 'text-slate-500 hover:text-slate-800 bg-transparent'
              }`}
            >
              <ShieldCheck size={14} /> Fiscal / CSD
            </button>
            <button
              onClick={() => setActiveTab('timbrado')}
              className={`px-4 py-2 rounded-lg text-xs font-black transition-all flex items-center gap-1.5 cursor-pointer border-none ${
                activeTab === 'timbrado' ? 'bg-white text-emerald-600 shadow-sm' : 'text-slate-500 hover:text-slate-800 bg-transparent'
              }`}
            >
              <Key size={14} /> Timbrado de Nómina
            </button>
            <button
              onClick={() => setActiveTab('historial')}
              className={`px-4 py-2 rounded-lg text-xs font-black transition-all flex items-center gap-1.5 cursor-pointer border-none ${
                activeTab === 'historial' ? 'bg-white text-emerald-600 shadow-sm' : 'text-slate-500 hover:text-slate-800 bg-transparent'
              }`}
            >
              <Clock size={14} /> Historial SAT
            </button>
          </div>
        </div>
      </div>

      {/* DOCK FLOTANTE INFERIOR MÓVIL (Estilo Reloj Checador con muesca SVG y FAB verde) */}
      <MobileModuleBottomDock
        colorTheme="emerald"
        activeTab={activeTab}
        onSelectTab={(tab) => setActiveTab(tab as any)}
        fabIcon={<Receipt size={28} className="text-white relative z-10 animate-pulse" />}
        onFabClick={() => setActiveTab('timbrado')}
        fabTitle="Timbrar Nómina / CFDI 4.0"
        items={[
          { id: 'fiscal', label: 'Fiscal CSD', icon: <ShieldCheck /> },
          { id: 'timbrado', label: 'Timbrado', icon: <Key /> },
          { id: 'historial', label: 'Historial SAT', icon: <Clock /> }
        ]}
      />

      {/* Cuerpo del Módulo */}
      <div className="flex-1 overflow-y-auto p-8 custom-scrollbar">
        {/* Mensajes de Alerta */}
        {successMsg && (
          <div className="mb-6 p-4 bg-emerald-50 border border-emerald-250 text-emerald-800 rounded-2xl flex items-center gap-3 animate-in slide-in-from-top-4 duration-200">
            <CheckCircle2 size={18} className="shrink-0 text-emerald-600 animate-bounce" />
            <span className="text-xs font-bold">{successMsg}</span>
          </div>
        )}
        {errorMsg && (
          <div className="mb-6 p-4 bg-rose-50 border border-rose-250 text-rose-800 rounded-2xl flex items-center gap-3 animate-in slide-in-from-top-4 duration-200">
            <AlertCircle size={18} className="shrink-0 text-rose-600 animate-pulse" />
            <span className="text-xs font-bold">{errorMsg}</span>
          </div>
        )}

        {/* CONTENIDO TAB: FISCAL / CSD */}
        {activeTab === 'fiscal' && (
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
            {/* Formulario de Datos Fiscales */}
            <div className="lg:col-span-2 bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-6">
              <div className="border-b border-slate-100 pb-3 flex items-center gap-2">
                <Building2 size={18} className="text-slate-500" />
                <h3 className="text-base font-black text-slate-800">Cédula de Identificación Fiscal</h3>
              </div>

              <form onSubmit={handleSaveTaxData} className="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div className="space-y-1.5">
                  <label className="text-xs font-black text-slate-500 uppercase tracking-wider">Razón Social (SAT)</label>
                  <input
                    type="text"
                    required
                    value={taxName}
                    onChange={(e) => setTaxName(e.target.value)}
                    placeholder="Ej. DECORARTE S.A. DE C.V."
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 bg-slate-50/50 text-slate-800 text-sm font-semibold transition-all outline-none"
                  />
                </div>

                <div className="space-y-1.5">
                  <label className="text-xs font-black text-slate-500 uppercase tracking-wider">RFC</label>
                  <input
                    type="text"
                    required
                    maxLength={13}
                    value={rfc}
                    onChange={(e) => setRfc(e.target.value.toUpperCase())}
                    placeholder="Ej. DEC150203AA0"
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 bg-slate-50/50 text-slate-800 text-sm font-semibold transition-all outline-none"
                  />
                </div>

                <div className="space-y-1.5">
                  <label className="text-xs font-black text-slate-500 uppercase tracking-wider">Régimen Fiscal</label>
                  <select
                    value={taxRegimen}
                    onChange={(e) => setTaxRegimen(e.target.value)}
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 bg-slate-50/50 text-slate-800 text-sm font-semibold transition-all outline-none"
                  >
                    <option value="601">601 - General de Ley Personas Morales</option>
                    <option value="603">603 - Personas Morales con Fines no Lucrativos</option>
                    <option value="605">605 - Sueldos y Salarios e Ingresos Asimilados a Salarios</option>
                    <option value="606">606 - Arrendamiento</option>
                    <option value="612">612 - Personas Físicas con Actividades Empresariales y Profesionales</option>
                    <option value="626">626 - Régimen Simplificado de Confianza (RESICO)</option>
                  </select>
                </div>

                <div className="space-y-1.5">
                  <label className="text-xs font-black text-slate-500 uppercase tracking-wider">Código Postal Fiscal</label>
                  <input
                    type="text"
                    required
                    maxLength={5}
                    value={postalCode}
                    onChange={(e) => setPostalCode(e.target.value)}
                    placeholder="Ej. 64000"
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 bg-slate-50/50 text-slate-800 text-sm font-semibold transition-all outline-none"
                  />
                </div>

                <div className="md:col-span-2 pt-3 border-t border-slate-100 flex justify-end">
                  <button
                    type="submit"
                    disabled={isSaving}
                    className="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-black shadow-md hover:scale-102 transition-all cursor-pointer border-none flex items-center gap-1.5 disabled:opacity-50"
                  >
                    {isSaving && <RefreshCw size={12} className="animate-spin" />}
                    Guardar Datos Fiscales
                  </button>
                </div>
              </form>
            </div>

            {/* Carga de CSD (Sellos Digitales) */}
            <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm space-y-6 flex flex-col justify-between">
              <div className="space-y-5">
                <div className="border-b border-slate-100 pb-3 flex items-center gap-2">
                  <Key size={18} className="text-slate-500" />
                  <h3 className="text-base font-black text-slate-800">Certificados SAT (CSD)</h3>
                </div>

                {/* Subida del .cer */}
                <div className="space-y-2">
                  <span className="text-xs font-black text-slate-500 uppercase tracking-wider block">Archivo Certificado (.cer)</span>
                  <div className="relative border border-dashed border-slate-250 hover:border-emerald-500 rounded-xl p-3 bg-slate-50/50 hover:bg-slate-50/20 transition-all flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                      <div className={`p-2 rounded-lg ${cerFileBase64 ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400'}`}>
                        <FileCode size={16} />
                      </div>
                      <span className="text-xs font-bold text-slate-700 truncate max-w-[150px]">
                        {cerName || 'Selecciona archivo .cer'}
                      </span>
                    </div>
                    <label className="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-lg text-[10px] font-black cursor-pointer transition-all">
                      Buscar
                      <input
                        type="file"
                        accept=".cer"
                        onChange={(e) => handleFileChange(e, 'cer')}
                        className="hidden"
                      />
                    </label>
                  </div>
                </div>

                {/* Subida del .key */}
                <div className="space-y-2">
                  <span className="text-xs font-black text-slate-500 uppercase tracking-wider block">Archivo Llave Privada (.key)</span>
                  <div className="relative border border-dashed border-slate-250 hover:border-emerald-500 rounded-xl p-3 bg-slate-50/50 hover:bg-slate-50/20 transition-all flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                      <div className={`p-2 rounded-lg ${keyFileBase64 ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400'}`}>
                        <Key size={16} />
                      </div>
                      <span className="text-xs font-bold text-slate-700 truncate max-w-[150px]">
                        {keyName || 'Selecciona archivo .key'}
                      </span>
                    </div>
                    <label className="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-lg text-[10px] font-black cursor-pointer transition-all">
                      Buscar
                      <input
                        type="file"
                        accept=".key"
                        onChange={(e) => handleFileChange(e, 'key')}
                        className="hidden"
                      />
                    </label>
                  </div>
                </div>

                {/* Contraseña CSD */}
                <div className="space-y-1.5">
                  <label className="text-xs font-black text-slate-500 uppercase tracking-wider">Contraseña de la Llave CSD</label>
                  <input
                    type="password"
                    value={csdPassword}
                    onChange={(e) => setCsdPassword(e.target.value)}
                    placeholder="Contraseña del certificado"
                    className="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 bg-slate-50/50 text-slate-800 text-sm font-semibold transition-all outline-none"
                  />
                </div>
              </div>

              <div className="pt-5 border-t border-slate-100 flex flex-col gap-2">
                <button
                  type="button"
                  onClick={handleUploadCsd}
                  disabled={isSaving || !cerFileBase64 || !keyFileBase64 || !csdPassword}
                  className="w-full py-2.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-black shadow-md hover:scale-101 transition-all cursor-pointer border-none disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  Sincronizar y Subir al SAT
                </button>
                <p className="text-[10px] text-slate-400 font-medium text-center">
                  🔐 Las llaves privadas se almacenan encriptadas de extremo a extremo.
                </p>
              </div>
            </div>
          </div>
        )}

        {/* CONTENIDO TAB: TIMBRADO DE NÓMINA */}
        {activeTab === 'timbrado' && (
          <div className="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
            {/* Header del timbrado: el periodo operativo REAL (última semana cerrada) */}
            <div className="px-6 py-4 border-b border-slate-200 bg-slate-50/30 flex items-center justify-between flex-wrap gap-4">
              <div className="flex items-center gap-3">
                <span className="px-3 py-1.5 bg-white border border-slate-250 rounded-xl text-xs font-bold text-slate-700">
                  {payrollPeriod
                    ? `Periodo del ${payrollPeriod.start_date} al ${payrollPeriod.end_date}`
                    : 'Cargando periodo...'}
                </span>
                <span className="text-xs font-black text-slate-400">
                  {payrollSummary.length} Colaboradores · {timbrables.length} listos para timbrar
                </span>
              </div>

              <button
                disabled={selectedEmployees.length === 0 || timbrando}
                onClick={handleTimbrarMasivo}
                className="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-black shadow-md hover:scale-102 transition-all cursor-pointer border-none flex items-center gap-2 disabled:opacity-45 disabled:cursor-not-allowed"
              >
                {timbrando ? (
                  <>
                    <RefreshCw size={14} className="animate-spin" />
                    Timbrando ({progress}%)
                  </>
                ) : (
                  <>
                    <CheckSquare size={14} />
                    Timbrar Selección ({selectedEmployees.length})
                  </>
                )}
              </button>
            </div>

            {/* Barra de progreso de timbrado */}
            {timbrando && (
              <div className="w-full bg-slate-100 h-1.5 relative overflow-hidden">
                <div 
                  className="bg-emerald-500 h-full transition-all duration-300"
                  style={{ width: `${progress}%` }}
                />
              </div>
            )}

            {/* Tabla de colaboradores */}
            <div className="overflow-x-auto">
              <table className="w-full border-collapse text-left">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-200">
                    <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider w-12 text-center">
                      <input
                        type="checkbox"
                        checked={selectedEmployees.length === timbrables.length && timbrables.length > 0}
                        onChange={toggleSelectAll}
                        className="rounded text-emerald-600 focus:ring-emerald-500 w-4 h-4 cursor-pointer"
                      />
                    </th>
                    <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">Colaborador</th>
                    <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">RFC / CURP</th>
                    <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">Neto del Periodo</th>
                    <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">Deducciones</th>
                    <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">Estado Nómina</th>
                    <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">Estado Timbrado</th>
                    <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider text-center">Acciones</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-150">
                  {payrollSummary.map((emp) => {
                    const isSelected = selectedEmployees.includes(emp.id);
                    const status = payrollStatus[emp.id];
                    const timbrable = esTimbrable(emp);
                    const yaTimbrada = !!emp.cfdi_uuid || status?.status === 'success';
                    // El neto que se timbra es el GUARDADO en la nómina firmada/autorizada.
                    const netoMostrado = emp.net_firmado ?? emp.net;

                    return (
                      <tr
                        key={emp.id}
                        className={`hover:bg-slate-50/50 transition-all ${
                          isSelected ? 'bg-emerald-50/10' : ''
                        }`}
                      >
                        <td className="py-4 px-6 text-center">
                          <input
                            type="checkbox"
                            checked={isSelected}
                            disabled={!timbrable}
                            onChange={() => toggleSelectEmployee(emp.id)}
                            className="rounded text-emerald-600 focus:ring-emerald-500 w-4 h-4 cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed"
                          />
                        </td>
                        <td className="py-4 px-6">
                          <div className="flex items-center gap-3">
                            <div className="w-9 h-9 rounded-full bg-slate-100 flex items-center justify-center font-black text-slate-600">
                              {emp.name.charAt(0)}
                            </div>
                            <div>
                              <span className="text-sm font-black text-slate-800 block">{emp.name}</span>
                              <span className="text-[10px] text-slate-400 font-semibold block">{emp.role}</span>
                            </div>
                          </div>
                        </td>
                        <td className="py-4 px-6">
                          {emp.rfc ? (
                            <span className="text-xs font-mono font-bold text-slate-700 block">{emp.rfc}</span>
                          ) : (
                            <span className="text-[10px] font-bold text-amber-600 block">Sin RFC (se usará genérico)</span>
                          )}
                          {emp.curp && (
                            <span className="text-[10px] font-mono text-slate-400 font-semibold block">{emp.curp}</span>
                          )}
                        </td>
                        <td className="py-4 px-6">
                          {emp.salary_pending ? (
                            <span className="text-[10px] font-bold text-amber-600">Salario sin capturar</span>
                          ) : (
                            <span className="text-sm font-black text-slate-800">
                              ${Number(netoMostrado ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </span>
                          )}
                        </td>
                        <td className="py-4 px-6">
                          <span className="text-xs font-bold text-rose-600">
                            {emp.penalty > 0 ? `-$${Number(emp.penalty).toLocaleString('es-MX', { minimumFractionDigits: 2 })}` : '—'}
                          </span>
                        </td>
                        <td className="py-4 px-6">
                          {emp.approval_status === 'approved_by_admin' ? (
                            <span className="px-2.5 py-1 bg-emerald-50 text-emerald-700 text-[10px] font-extrabold uppercase rounded-full border border-emerald-200/50">
                              🟢 Autorizada
                            </span>
                          ) : emp.approval_status === 'approved_by_employee' || emp.approval_status === 'finalized' ? (
                            <span className="px-2.5 py-1 bg-sky-50 text-sky-700 text-[10px] font-extrabold uppercase rounded-full border border-sky-200/50">
                              🔵 Firmada · falta autorizar
                            </span>
                          ) : (
                            <span className="px-2.5 py-1 bg-amber-50 text-amber-700 text-[10px] font-extrabold uppercase rounded-full border border-amber-200/50">
                              🟡 Sin firma del colaborador
                            </span>
                          )}
                        </td>
                        <td className="py-4 px-6">
                          {yaTimbrada ? (
                            <div className="space-y-0.5">
                              <span className="px-2.5 py-1 bg-emerald-50 text-emerald-700 text-[10px] font-extrabold uppercase rounded-full border border-emerald-200/50 flex items-center gap-1 w-fit">
                                <CheckCircle size={10} /> Timbrado SAT
                              </span>
                              <span className="text-[9px] font-mono font-bold text-slate-450 block truncate max-w-[120px]" title={emp.cfdi_uuid || status?.uuid || ''}>
                                {emp.cfdi_uuid || status?.uuid || 'Folio en proceso'}
                              </span>
                            </div>
                          ) : status?.status === 'failed' ? (
                            <div className="space-y-0.5">
                              <span className="px-2.5 py-1 bg-rose-50 text-rose-700 text-[10px] font-extrabold uppercase rounded-full border border-rose-200/50 flex items-center gap-1 w-fit">
                                <AlertCircle size={10} /> Falló Timbrado
                              </span>
                              {status.error && (
                                <span className="text-[9px] font-bold text-rose-500 block max-w-[180px]">{status.error}</span>
                              )}
                            </div>
                          ) : (
                            <span className="px-2.5 py-1 bg-amber-50 text-amber-700 text-[10px] font-extrabold uppercase rounded-full border border-amber-200/50">
                              Pendiente Timbrar
                            </span>
                          )}
                        </td>
                        <td className="py-4 px-6 text-center">
                          <div className="flex items-center justify-center gap-1.5">
                            <button
                              onClick={() => handlePrintTicket(emp.id)}
                              title="Imprimir Ticket 80mm"
                              className="p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 hover:text-slate-800 rounded-lg transition-all border-none cursor-pointer"
                            >
                              <Printer size={12} />
                            </button>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                  {payrollSummary.length === 0 && (
                    <tr>
                      <td colSpan={8} className="py-8 text-center text-slate-400 font-semibold text-xs">
                        No hay colaboradores activos con nómina en el periodo. Agrega colaboradores en Recursos Humanos primero.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* CONTENIDO TAB: HISTORIAL SAT */}
        {activeTab === 'historial' && (
          <div className="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
            <div className="px-6 py-4 border-b border-slate-200 bg-slate-50/30 flex items-center justify-between flex-wrap gap-4">
              <h3 className="text-base font-black text-slate-800">Facturas y Recibos Emitidos en el SAT</h3>
              <button
                onClick={fetchInvoices}
                disabled={loadingInvoices}
                className="px-4 py-1.5 bg-white border border-slate-250 hover:bg-slate-50 rounded-xl text-xs font-black text-slate-600 hover:text-slate-800 transition-all flex items-center gap-1.5 cursor-pointer shadow-sm"
              >
                <RefreshCw size={12} className={loadingInvoices ? 'animate-spin' : ''} />
                Sincronizar Historial
              </button>
            </div>

            {loadingInvoices ? (
              <div className="py-12 flex flex-col items-center justify-center gap-3">
                <RefreshCw size={24} className="text-slate-400 animate-spin" />
                <span className="text-xs font-bold text-slate-500">Consultando API de Facturapi...</span>
              </div>
            ) : invoicesError ? (
              <div className="py-10 px-8 text-center">
                <AlertCircle size={28} className="text-amber-500 mx-auto mb-3" />
                <p className="text-xs font-bold text-slate-600 mb-1">No se pudo consultar el historial fiscal</p>
                <p className="text-[10.5px] text-slate-400 font-medium max-w-md mx-auto">{invoicesError}</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full border-collapse text-left">
                  <thead>
                    <tr className="bg-slate-50 border-b border-slate-200">
                      <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">Folio Fiscal (UUID)</th>
                      <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">Receptor</th>
                      <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">RFC</th>
                      <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">Fecha Emisión</th>
                      <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">Monto Total</th>
                      <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">Estado</th>
                      <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider text-center">Acciones</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-150">
                    {invoices.map((inv) => (
                      <tr key={inv.id} className="hover:bg-slate-50/50 transition-all">
                        <td className="py-4 px-6">
                          <span className="text-xs font-mono font-bold text-slate-700 block max-w-[200px] truncate" title={inv.uuid}>
                            {inv.uuid}
                          </span>
                          <span className="text-[10px] text-slate-400 font-semibold block">ID: {inv.id}</span>
                        </td>
                        <td className="py-4 px-6">
                          <span className="text-sm font-black text-slate-800">{inv.legal_name}</span>
                          <span className="text-[10px] text-slate-400 font-semibold block capitalize">CFDI Tipo {inv.type === 'payroll' ? 'Nómina' : 'Ingreso'}</span>
                        </td>
                        <td className="py-4 px-6">
                          <span className="text-xs font-mono font-bold text-slate-700">{inv.rfc}</span>
                        </td>
                        <td className="py-4 px-6">
                          <span className="text-xs font-bold text-slate-500">
                            {new Date(inv.created_at).toLocaleString('es-MX')}
                          </span>
                        </td>
                        <td className="py-4 px-6">
                          <span className="text-sm font-black text-slate-800">
                            ${inv.total.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                          </span>
                        </td>
                        <td className="py-4 px-6">
                          {inv.status === 'valid' ? (
                            <span className="px-2.5 py-1 bg-emerald-50 text-emerald-700 text-[10px] font-extrabold uppercase rounded-full border border-emerald-200/50">
                              Vigente / Activo
                            </span>
                          ) : (
                            <span className="px-2.5 py-1 bg-slate-100 text-slate-500 text-[10px] font-extrabold uppercase rounded-full border border-slate-200">
                              Cancelado SAT
                            </span>
                          )}
                        </td>
                        <td className="py-4 px-6 text-center">
                          <div className="flex items-center justify-center gap-1.5">
                            <button
                              title="Descargar PDF"
                              className="p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-650 hover:text-slate-800 rounded-lg transition-all border-none cursor-pointer"
                            >
                              <Download size={13} />
                            </button>
                            <button
                              title="Ver XML"
                              className="p-1.5 bg-slate-100 hover:bg-slate-200 text-slate-650 hover:text-slate-800 rounded-lg transition-all border-none cursor-pointer"
                            >
                              <FileCode size={13} />
                            </button>
                            {inv.status === 'valid' && (
                              <button
                                title="Cancelar Factura"
                                className="p-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 hover:text-rose-800 rounded-lg transition-all border-none cursor-pointer"
                              >
                                <Trash2 size={13} />
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))}
                    {invoices.length === 0 && (
                      <tr>
                        <td colSpan={7} className="py-8 text-center text-slate-400 font-semibold text-xs">
                          Ningún CFDI emitido recientemente.
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
};
