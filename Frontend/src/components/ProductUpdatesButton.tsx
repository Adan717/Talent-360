import React, { useEffect, useMemo, useState } from 'react';
import { Bell, CalendarDays, CheckCheck, ChevronRight, X } from 'lucide-react';
import axiosInstance from '../lib/axios';

export interface ProductUpdate {
  id: string;
  title: string;
  summary: string;
  published_at: string;
  is_active: boolean;
  target_module?: string | null;
}

interface ProductUpdatesButtonProps {
  userId?: number | string;
  onOpenModule?: (moduleId: string) => void;
}

export function ProductUpdatesButton({ userId, onOpenModule }: ProductUpdatesButtonProps) {
  const [updates, setUpdates] = useState<ProductUpdate[]>([]);
  const [isOpen, setIsOpen] = useState(false);
  const storageKey = `talent360_read_product_updates_${userId ?? 'current'}`;
  const [readIds, setReadIds] = useState<string[]>(() => {
    try {
      return JSON.parse(localStorage.getItem(storageKey) || '[]');
    } catch {
      return [];
    }
  });

  useEffect(() => {
    let mounted = true;
    axiosInstance.get('/product-updates')
      .then(({ data }) => {
        if (mounted) setUpdates(Array.isArray(data?.updates) ? data.updates : []);
      })
      .catch(() => {
        if (mounted) setUpdates([]);
      });
    return () => { mounted = false; };
  }, []);

  const unreadCount = useMemo(
    () => updates.filter(update => !readIds.includes(update.id)).length,
    [updates, readIds],
  );

  if (updates.length === 0) return null;

  const openUpdates = () => {
    setIsOpen(true);
    const ids = updates.map(update => update.id);
    setReadIds(ids);
    try {
      localStorage.setItem(storageKey, JSON.stringify(ids));
    } catch {}
  };

  return (
    <>
      <button
        type="button"
        onClick={openUpdates}
        aria-label={unreadCount > 0 ? `${unreadCount} novedades sin leer` : 'Ver novedades'}
        className="relative inline-flex h-10 w-10 items-center justify-center rounded-xl border border-border bg-white text-text-2 transition-colors hover:bg-page hover:text-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
      >
        <Bell size={19} />
        {unreadCount > 0 && (
          <span className="absolute right-1.5 top-1.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-warning-icon" />
        )}
      </button>

      {isOpen && (
        <div className="fixed inset-0 z-[100] flex items-end justify-center bg-slate-950/45 p-0 sm:items-center sm:p-4" role="presentation">
          <button className="absolute inset-0 cursor-default" aria-label="Cerrar novedades" onClick={() => setIsOpen(false)} />
          <section
            role="dialog"
            aria-modal="true"
            aria-labelledby="product-updates-title"
            className="relative z-10 max-h-[88dvh] w-full overflow-hidden rounded-t-3xl border border-border bg-white shadow-xl sm:max-w-xl sm:rounded-3xl"
          >
            <header className="flex items-start justify-between gap-4 border-b border-border px-5 py-4 sm:px-6">
              <div>
                <div className="mb-1 flex items-center gap-2 text-accent">
                  <CheckCheck size={16} />
                  <span className="text-[11px] font-bold uppercase tracking-[0.16em]">Producto</span>
                </div>
                <h2 id="product-updates-title" className="text-xl font-semibold text-text-1">Novedades de Talent 360</h2>
                <p className="mt-1 text-sm text-text-3">Solo aparecen cambios que ya están disponibles.</p>
              </div>
              <button
                type="button"
                onClick={() => setIsOpen(false)}
                className="rounded-xl p-2 text-text-3 hover:bg-page hover:text-text-1"
                aria-label="Cerrar"
              >
                <X size={20} />
              </button>
            </header>

            <div className="max-h-[65dvh] divide-y divide-border overflow-y-auto">
              {updates.map(update => (
                <article key={update.id} className="px-5 py-5 sm:px-6">
                  <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h3 className="font-semibold text-text-1">{update.title}</h3>
                    <span className="inline-flex items-center gap-1.5 text-xs text-text-3">
                      <CalendarDays size={14} />
                      {new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium' }).format(new Date(update.published_at))}
                    </span>
                  </div>
                  <p className="text-sm leading-6 text-text-2">{update.summary}</p>
                  {update.target_module && onOpenModule && (
                    <button
                      type="button"
                      onClick={() => {
                        onOpenModule(update.target_module as string);
                        setIsOpen(false);
                      }}
                      className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-accent hover:text-accent-hover"
                    >
                      Abrir módulo <ChevronRight size={16} />
                    </button>
                  )}
                </article>
              ))}
            </div>
          </section>
        </div>
      )}
    </>
  );
}
