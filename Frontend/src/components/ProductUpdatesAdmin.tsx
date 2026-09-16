import React, { useEffect, useState } from 'react';
import { CalendarDays, Eye, EyeOff, Loader2, Megaphone, Plus, Save, Trash2 } from 'lucide-react';
import axiosInstance from '../lib/axios';
import type { ProductUpdate } from './ProductUpdatesButton';

const MODULE_OPTIONS = [
  ['', 'Sin enlace'],
  ['dashboard', 'Monitor 360'],
  ['rrhh', 'Directorio Digital'],
  ['reloj', 'Reloj Checador'],
  ['operativo', 'Tareas y Rutinas'],
  ['reportes', 'Reportes'],
  ['ats', 'Reclutamiento ATS'],
  ['academia', 'Academia'],
  ['documentos', 'Archivo Digital'],
  ['facturacion', 'Nómina'],
  ['lft', 'Cumplimiento LFT'],
] as const;

const toLocalInput = (value?: string) => {
  const date = value ? new Date(value) : new Date();
  const offset = date.getTimezoneOffset() * 60000;
  return new Date(date.getTime() - offset).toISOString().slice(0, 16);
};

export function ProductUpdatesAdmin() {
  const [updates, setUpdates] = useState<ProductUpdate[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');

  useEffect(() => {
    axiosInstance.get('/platform/product-updates')
      .then(({ data }) => setUpdates(Array.isArray(data?.updates) ? data.updates : []))
      .catch(() => setMessage('No fue posible cargar las novedades.'))
      .finally(() => setLoading(false));
  }, []);

  const addUpdate = () => {
    const id = typeof crypto !== 'undefined' && crypto.randomUUID
      ? crypto.randomUUID()
      : `update-${Date.now()}`;
    setUpdates(current => [{
      id,
      title: '',
      summary: '',
      published_at: new Date().toISOString(),
      is_active: false,
      target_module: null,
    }, ...current]);
  };

  const changeUpdate = (id: string, change: Partial<ProductUpdate>) => {
    setUpdates(current => current.map(update => update.id === id ? { ...update, ...change } : update));
  };

  const saveUpdates = async () => {
    if (updates.some(update => !update.title.trim() || !update.summary.trim())) {
      setMessage('Completa el título y la descripción de cada novedad.');
      return;
    }
    setSaving(true);
    setMessage('');
    try {
      const payload = updates.map(update => ({
        ...update,
        target_module: update.target_module || null,
      }));
      const { data } = await axiosInstance.put('/platform/product-updates', { updates: payload });
      setUpdates(data?.updates || payload);
      setMessage('Cambios guardados. Los avisos activos ya están disponibles para los clientes.');
    } catch (error: any) {
      setMessage(error?.response?.data?.message || 'No fue posible guardar las novedades.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return <div className="flex min-h-64 items-center justify-center text-text-3"><Loader2 className="animate-spin" /></div>;
  }

  return (
    <section className="space-y-5">
      <div className="flex flex-col gap-3 rounded-2xl border border-border bg-white p-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="mb-1 flex items-center gap-2 text-accent">
            <Megaphone size={18} />
            <span className="text-xs font-bold uppercase tracking-wider">Comunicación de producto</span>
          </div>
          <h2 className="text-xl font-semibold text-text-1">Novedades para clientes</h2>
          <p className="mt-1 max-w-2xl text-sm text-text-2">Publica únicamente funciones que ya estén disponibles. Si no hay avisos activos, el acceso no aparece en la aplicación.</p>
        </div>
        <div className="flex gap-2">
          <button type="button" onClick={addUpdate} className="inline-flex items-center gap-2 rounded-xl border border-border bg-white px-4 py-2.5 text-sm font-semibold text-text-2 hover:bg-page">
            <Plus size={17} /> Nueva
          </button>
          <button type="button" onClick={saveUpdates} disabled={saving} className="inline-flex items-center gap-2 rounded-xl bg-accent px-4 py-2.5 text-sm font-semibold text-white hover:bg-accent-hover disabled:opacity-60">
            {saving ? <Loader2 size={17} className="animate-spin" /> : <Save size={17} />} Guardar
          </button>
        </div>
      </div>

      {message && <p role="status" className="rounded-xl border border-border bg-page px-4 py-3 text-sm font-medium text-text-2">{message}</p>}

      {updates.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-border bg-white px-6 py-12 text-center">
          <Megaphone className="mx-auto mb-3 text-slate-400" size={28} />
          <p className="font-semibold text-text-1">No hay novedades publicadas</p>
          <p className="mt-1 text-sm text-text-3">La campana permanecerá oculta para no distraer a los usuarios.</p>
        </div>
      ) : (
        <div className="grid gap-4">
          {updates.map(update => (
            <article key={update.id} className="rounded-2xl border border-border bg-white p-4 sm:p-5">
              <div className="grid gap-4 lg:grid-cols-[1fr_1.5fr_220px]">
                <label className="text-xs font-semibold text-text-2">
                  Título
                  <input value={update.title} onChange={event => changeUpdate(update.id, { title: event.target.value })} maxLength={120} className="mt-1.5 w-full rounded-xl border border-border bg-page px-3 py-2.5 text-sm text-text-1 outline-none focus:border-accent focus:ring-2 focus:ring-focus-ring/20" />
                </label>
                <label className="text-xs font-semibold text-text-2">
                  Descripción
                  <textarea value={update.summary} onChange={event => changeUpdate(update.id, { summary: event.target.value })} maxLength={500} rows={2} className="mt-1.5 w-full resize-none rounded-xl border border-border bg-page px-3 py-2.5 text-sm text-text-1 outline-none focus:border-accent focus:ring-2 focus:ring-focus-ring/20" />
                </label>
                <div className="space-y-3">
                  <label className="block text-xs font-semibold text-text-2">
                    <span className="inline-flex items-center gap-1"><CalendarDays size={13} /> Publicación</span>
                    <input type="datetime-local" value={toLocalInput(update.published_at)} onChange={event => changeUpdate(update.id, { published_at: new Date(event.target.value).toISOString() })} className="mt-1.5 w-full rounded-xl border border-border bg-page px-3 py-2.5 text-sm text-text-1 outline-none focus:border-accent" />
                  </label>
                  <select value={update.target_module || ''} onChange={event => changeUpdate(update.id, { target_module: event.target.value || null })} className="w-full rounded-xl border border-border bg-page px-3 py-2.5 text-sm text-text-1 outline-none focus:border-accent">
                    {MODULE_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                  </select>
                </div>
              </div>
              <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                <button type="button" onClick={() => changeUpdate(update.id, { is_active: !update.is_active })} className={`inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold ${update.is_active ? 'border-success-text/20 bg-success-bg text-success-text' : 'border-border bg-page text-text-3'}`}>
                  {update.is_active ? <Eye size={15} /> : <EyeOff size={15} />}
                  {update.is_active ? 'Visible para clientes' : 'Borrador'}
                </button>
                <button type="button" onClick={() => setUpdates(current => current.filter(item => item.id !== update.id))} className="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold text-danger-text hover:bg-danger-bg">
                  <Trash2 size={15} /> Eliminar
                </button>
              </div>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
