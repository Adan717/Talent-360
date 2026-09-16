import React, { useCallback, useEffect, useState } from 'react';
import { CheckCircle2, Plus, RefreshCw, Truck, X } from 'lucide-react';
import axiosInstance from '../../lib/axios';

interface VendorVisit {
  id: string;
  vendor_name: string;
  driver_name: string;
  order_ref: string;
  arrival_time: string;
  status: 'in_premises' | 'completed';
  received_by: string;
}

export function VendorVisitsPanel({ isDark = false }: { isDark?: boolean }) {
  const [visits, setVisits] = useState<VendorVisit[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [showForm, setShowForm] = useState(false);
  const [error, setError] = useState('');
  const [vendorName, setVendorName] = useState('');
  const [driverName, setDriverName] = useState('');
  const [orderRef, setOrderRef] = useState('');

  const loadVisits = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const response = await axiosInstance.get('/admin/dashboard/monitor');
      setVisits(response.data?.data?.vendors || []);
    } catch (requestError: any) {
      setError(requestError?.response?.data?.message || 'No se pudieron cargar las visitas.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadVisits();
  }, [loadVisits]);

  const registerVisit = async () => {
    if (!vendorName.trim() || saving) return;
    setSaving(true);
    setError('');
    try {
      await axiosInstance.post('/admin/dashboard/vendors', {
        vendor_name: vendorName.trim(),
        driver_name: driverName.trim() || 'Repartidor',
        order_ref: orderRef.trim() || 'S/N',
      });
      setVendorName('');
      setDriverName('');
      setOrderRef('');
      setShowForm(false);
      await loadVisits();
    } catch (requestError: any) {
      setError(requestError?.response?.data?.message || 'No se pudo registrar la visita.');
    } finally {
      setSaving(false);
    }
  };

  const completeVisit = async (id: string) => {
    setError('');
    try {
      await axiosInstance.post(`/admin/dashboard/vendors/${id}/complete`);
      await loadVisits();
    } catch (requestError: any) {
      setError(requestError?.response?.data?.message || 'No se pudo registrar la salida.');
    }
  };

  const activeVisits = visits.filter((visit) => visit.status === 'in_premises');

  return (
    <section className="space-y-4" aria-labelledby="vendor-visits-title">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div>
            <h4 id="vendor-visits-title" className="text-base font-bold text-text-1 dark:text-slate-100">Visitas y proveedores</h4>
            <p className="text-xs text-text-3">Control de entradas y salidas de personas externas.</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={loadVisits}
            disabled={loading}
            className="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-border bg-white text-text-2 hover:bg-page disabled:opacity-50"
            aria-label="Actualizar visitas"
          >
            <RefreshCw size={16} className={loading ? 'animate-spin' : ''} />
          </button>
          <button
            type="button"
            onClick={() => setShowForm((current) => !current)}
            className="inline-flex items-center gap-2 rounded-xl bg-accent px-3.5 py-2 text-xs font-bold text-white hover:bg-accent-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
          >
            {showForm ? <X size={16} /> : <Plus size={16} />}
            {showForm ? 'Cancelar' : 'Registrar visita'}
          </button>
        </div>
      </div>

      {showForm && (
        <div className={`grid gap-3 rounded-2xl border p-4 sm:grid-cols-3 ${isDark ? 'border-slate-800 bg-slate-950/50' : 'border-border bg-page'}`}>
          <label className="text-xs font-semibold text-text-2">
            Empresa o proveedor
            <input value={vendorName} onChange={(event) => setVendorName(event.target.value)} className="mt-1.5 w-full rounded-xl border border-border bg-white px-3 py-2.5 text-sm text-text-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" placeholder="Nombre" />
          </label>
          <label className="text-xs font-semibold text-text-2">
            Persona que ingresa
            <input value={driverName} onChange={(event) => setDriverName(event.target.value)} className="mt-1.5 w-full rounded-xl border border-border bg-white px-3 py-2.5 text-sm text-text-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" placeholder="Nombre" />
          </label>
          <label className="text-xs font-semibold text-text-2">
            Referencia
            <input value={orderRef} onChange={(event) => setOrderRef(event.target.value)} className="mt-1.5 w-full rounded-xl border border-border bg-white px-3 py-2.5 text-sm text-text-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" placeholder="Orden o motivo" />
          </label>
          <button type="button" onClick={registerVisit} disabled={!vendorName.trim() || saving} className="sm:col-start-3 rounded-xl bg-accent px-4 py-2.5 text-xs font-bold text-white hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50">
            {saving ? 'Registrando…' : 'Confirmar entrada'}
          </button>
        </div>
      )}

      {error && <p role="alert" className="rounded-xl border border-danger-text/20 bg-danger-bg px-3 py-2 text-xs font-semibold text-danger-text">{error}</p>}

      <div className="rounded-2xl border border-border bg-white">
        <div className="flex items-center justify-between border-b border-border px-4 py-3">
          <span className="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-text-1"><Truck size={16} className="text-accent" /> En las instalaciones</span>
          <span className="rounded-full bg-navy-50 px-2.5 py-1 text-xs font-bold text-navy-800">{activeVisits.length}</span>
        </div>
        <div className="divide-y divide-border">
          {loading ? (
            <p className="px-4 py-8 text-center text-xs text-text-3">Cargando visitas…</p>
          ) : activeVisits.length === 0 ? (
            <div className="px-4 py-8 text-center">
              <CheckCircle2 size={26} className="mx-auto mb-2 text-success-text" />
              <p className="text-sm font-semibold text-text-1">No hay visitas activas</p>
              <p className="mt-1 text-xs text-text-3">Las nuevas entradas aparecerán aquí.</p>
            </div>
          ) : activeVisits.map((visit) => (
            <div key={visit.id} className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0">
                <p className="truncate text-sm font-bold text-text-1">{visit.vendor_name}</p>
                <p className="mt-0.5 text-xs text-text-3">{visit.driver_name} · {visit.order_ref}</p>
                <p className="mt-1 text-[11px] font-semibold text-text-2">Entrada {visit.arrival_time} · recibió {visit.received_by}</p>
              </div>
              <button type="button" onClick={() => completeVisit(visit.id)} className="shrink-0 rounded-xl border border-border bg-white px-3 py-2 text-xs font-bold text-text-2 hover:bg-page focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring">
                Registrar salida
              </button>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
