import React, { useState, useEffect } from 'react';
import { 
  FileText, ShieldCheck, DollarSign, Download, Plus, 
  RefreshCw, Trash2, Send, X, Users, AlertCircle, CheckCircle2, FileCode
} from 'lucide-react';
import axiosInstance from '../lib/axios';

export const SaaSPlatformBilling = () => {
  const [invoices, setInvoices] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const [tenants, setTenants] = useState<any[]>([]);
  const [showManualModal, setShowManualModal] = useState(false);

  // Estados para factura manual
  const [selectedTenantId, setSelectedTenantId] = useState<number | string>('');
  const [amount, setAmount] = useState('');
  const [description, setDescription] = useState('');
  const [submitting, setSubmitting] = useState(false);

  // Estados de alertas
  const [successMsg, setSuccessMsg] = useState('');
  const [errorMsg, setErrorMsg] = useState('');

  useEffect(() => {
    fetchInvoices();
    fetchTenants();
  }, []);

  const fetchInvoices = async () => {
    setLoading(true);
    try {
      const res = await axiosInstance.get('/platform/billing/invoices');
      if (res.data && res.data.success) {
        setInvoices(res.data.data);
      } else if (res.data) {
        setInvoices(res.data);
      }
    } catch (e) {
      console.error("Error al obtener facturas globales del SaaS", e);
    } finally {
      setLoading(false);
    }
  };

  const fetchTenants = async () => {
    try {
      const res = await axiosInstance.get('/platform/tenants');
      setTenants(res.data || []);
    } catch (e) {
      console.error("Error al obtener empresas", e);
    }
  };

  const handleEmitManualInvoice = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedTenantId || !amount || !description) return;

    setSubmitting(true);
    setSuccessMsg('');
    setErrorMsg('');

    try {
      const res = await axiosInstance.post('/platform/billing/invoice/manual', {
        tenant_id: Number(selectedTenantId),
        amount: Number(amount),
        description: description
      });

      if (res.data && res.data.success) {
        setSuccessMsg('Factura manual emitida y timbrada con éxito.');
        setShowManualModal(false);
        setAmount('');
        setDescription('');
        setSelectedTenantId('');
        fetchInvoices(); // Refrescar historial
      }
    } catch (e: any) {
      setErrorMsg(e.response?.data?.message || 'Error al emitir la factura manual');
    } finally {
      setSubmitting(false);
    }
  };

  const handleDeleteInvoice = async (id: string, legalName: string) => {
    if (!window.confirm(`¿Estás seguro de que deseas eliminar o cancelar el registro de factura de "${legalName}"?`)) return;

    try {
      const res = await axiosInstance.delete(`/platform/billing/invoices/${id}`);
      setSuccessMsg(res.data.message || 'Registro de factura eliminado con éxito.');
      fetchInvoices();
    } catch (e: any) {
      setErrorMsg(e.response?.data?.message || 'Error al eliminar el registro de factura.');
    }
  };

  const totalEarnings = invoices.reduce((acc, curr) => curr.status === 'valid' ? acc + curr.total : acc, 0);

  return (
    <div className="flex-1 p-3 sm:p-6 md:p-8 overflow-y-auto custom-scrollbar bg-slate-50 space-y-6">
      {/* Alertas */}
      {successMsg && (
        <div className="p-4 bg-emerald-50 border border-emerald-250 text-emerald-800 rounded-2xl flex items-center gap-3 animate-in slide-in-from-top-4 duration-200">
          <CheckCircle2 size={18} className="shrink-0 text-emerald-600 animate-bounce" />
          <span className="text-xs font-bold">{successMsg}</span>
        </div>
      )}
      {errorMsg && (
        <div className="p-4 bg-rose-50 border border-rose-250 text-rose-800 rounded-2xl flex items-center gap-3 animate-in slide-in-from-top-4 duration-200">
          <AlertCircle size={18} className="shrink-0 text-rose-600 animate-pulse" />
          <span className="text-xs font-bold">{errorMsg}</span>
        </div>
      )}

      {/* Cabecera y botón de acción */}
      <div className="flex items-center justify-between flex-wrap gap-4">
        <div>
          <h2 className="text-xl sm:text-2xl font-black text-slate-800">Centro de Facturación Global</h2>
          <p className="text-xs text-slate-400 font-bold">Control de cobros a empresas y timbrado CFDI de suscripciones SaaS</p>
        </div>
        <div className="flex items-center gap-2 w-full sm:w-auto">
          <button
            onClick={() => setShowManualModal(true)}
            className="w-full sm:w-auto justify-center px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-black shadow-md hover:scale-102 transition-all cursor-pointer border-none flex items-center gap-1.5"
          >
            <Plus size={14} /> Emitir Factura Manual
          </button>
        </div>
      </div>

      {/* Tarjetas de Resumen */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
        <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex items-center gap-4">
          <div className="p-3.5 bg-blue-50 text-blue-600 rounded-2xl border border-blue-100">
            <DollarSign size={24} />
          </div>
          <div>
            <span className="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Ingresos Facturados (Mes)</span>
            <span className="text-2xl font-black text-slate-800 block">
              ${totalEarnings.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </span>
          </div>
        </div>

        <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex items-center gap-4">
          <div className="p-3.5 bg-emerald-50 text-emerald-600 rounded-2xl border border-emerald-100">
            <ShieldCheck size={24} />
          </div>
          <div>
            <span className="text-[10px] font-black text-slate-400 uppercase tracking-wider block">CFDI 4.0 Timbrados</span>
            <span className="text-2xl font-black text-slate-800 block">
              {invoices.filter(i => i.status === 'valid').length} / {invoices.length}
            </span>
          </div>
        </div>

        <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex items-center gap-4">
          <div className="p-3.5 bg-purple-50 text-purple-600 rounded-2xl border border-purple-100">
            <Users size={24} />
          </div>
          <div>
            <span className="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Clientes Activos</span>
            <span className="text-2xl font-black text-slate-800 block">
              {tenants.length} Empresas
            </span>
          </div>
        </div>
      </div>

      {/* Historial de facturas */}
      <div className="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
        <div className="px-6 py-4 border-b border-slate-200 bg-slate-50/30 flex items-center justify-between flex-wrap gap-4">
          <h3 className="text-base font-black text-slate-800">CFDIs de Suscripciones y Ventas</h3>
          <button
            onClick={fetchInvoices}
            disabled={loading}
            className="px-4 py-1.5 bg-white border border-slate-250 hover:bg-slate-50 rounded-xl text-xs font-black text-slate-650 hover:text-slate-800 transition-all flex items-center gap-1.5 cursor-pointer shadow-sm"
          >
            <RefreshCw size={12} className={loading ? 'animate-spin' : ''} />
            Actualizar Historial
          </button>
        </div>

        {loading ? (
          <div className="py-12 flex flex-col items-center justify-center gap-3">
            <RefreshCw size={24} className="text-slate-450 animate-spin" />
            <span className="text-xs font-bold text-slate-500">Consultando API de Facturapi Global...</span>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-left">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200">
                  <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">Folio Fiscal (UUID)</th>
                  <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">Cliente / Empresa</th>
                  <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">RFC</th>
                  <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">Fecha Emisión</th>
                  <th className="py-3 px-6 text-xs font-black text-slate-450 uppercase tracking-wider">Monto Cobrado</th>
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
                      <span className="text-[10px] text-slate-450 font-semibold block">ID: {inv.id}</span>
                    </td>
                    <td className="py-4 px-6">
                      <span className="text-sm font-black text-slate-800">{inv.legal_name}</span>
                      <span className="text-[10px] text-slate-400 font-semibold block">Suscripción SaaS</span>
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
                          Cobrado / Vigente
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
                        <button
                          onClick={() => handleDeleteInvoice(inv.id, inv.legal_name || 'Empresa')}
                          title="Eliminar Registro de Factura"
                          className="p-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-lg transition-all border-none cursor-pointer"
                        >
                          <Trash2 size={13} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
                {invoices.length === 0 && (
                  <tr>
                    <td colSpan={7} className="py-8 text-center text-slate-400 font-semibold text-xs">
                      No se han emitido facturas de suscripciones en este periodo.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* MODAL: FACTURA MANUAL */}
      {showManualModal && (
        <div className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] flex items-center justify-center p-4 animate-in fade-in duration-200">
          <div className="bg-white rounded-3xl max-w-md w-full border border-slate-100 shadow-2xl relative animate-in zoom-in-95 duration-200 flex flex-col overflow-hidden">
            {/* Header del Modal */}
            <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
              <div className="flex items-center gap-2.5">
                <div className="p-2 bg-blue-50 text-blue-600 rounded-xl">
                  <FileText size={18} />
                </div>
                <div>
                  <h3 className="font-black text-slate-800 text-base">Emitir Factura Manual</h3>
                  <p className="text-[11px] text-slate-400 font-medium">Timbrado de cobro especial a cliente</p>
                </div>
              </div>
              <button
                onClick={() => setShowManualModal(false)}
                className="w-8 h-8 flex items-center justify-center bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-800 rounded-full transition-all border-none cursor-pointer"
              >
                <X size={16} />
              </button>
            </div>

            {/* Contenido */}
            <form onSubmit={handleEmitManualInvoice} className="p-6 space-y-4">
              <div className="space-y-1.5">
                <label className="text-xs font-black text-slate-500 uppercase tracking-wider block">Seleccionar Empresa</label>
                <select
                  required
                  value={selectedTenantId}
                  onChange={(e) => setSelectedTenantId(e.target.value)}
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 bg-slate-50/50 text-slate-850 text-sm font-semibold transition-all outline-none"
                >
                  <option value="">-- Elige un cliente --</option>
                  {tenants.map((tenant) => (
                    <option key={tenant.id} value={tenant.id}>
                      {tenant.name} ({tenant.subdomain})
                    </option>
                  ))}
                </select>
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-black text-slate-500 uppercase tracking-wider block">Monto a Cobrar (MXN)</label>
                <input
                  type="number"
                  required
                  min={1}
                  step="0.01"
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                  placeholder="Ej. 1500.00"
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 bg-slate-50/50 text-slate-850 text-sm font-semibold transition-all outline-none"
                />
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-black text-slate-500 uppercase tracking-wider block">Descripción del Cobro</label>
                <input
                  type="text"
                  required
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  placeholder="Ej. Upgrade de almacenamiento - 100 GB"
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 bg-slate-50/50 text-slate-850 text-sm font-semibold transition-all outline-none"
                />
              </div>

              {/* Footer del Modal */}
              <div className="pt-4 border-t border-slate-100 flex items-center justify-end gap-2.5">
                <button
                  type="button"
                  onClick={() => setShowManualModal(false)}
                  className="px-4 py-2 rounded-xl text-slate-500 hover:bg-slate-150 text-xs font-bold transition-all cursor-pointer border-none bg-transparent"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-black shadow-md hover:scale-102 transition-all cursor-pointer border-none flex items-center gap-1.5"
                >
                  {submitting ? <RefreshCw size={14} className="animate-spin" /> : <Send size={14} />}
                  Emitir CFDI SAT
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};
