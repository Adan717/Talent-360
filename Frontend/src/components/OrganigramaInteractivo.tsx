// @ts-nocheck
import React, { useState, useEffect, useCallback } from 'react';
import {
  DndContext,
  DragOverlay,
  closestCenter,
  PointerSensor,
  useSensor,
  useSensors,
  DragStartEvent,
  DragEndEvent,
} from '@dnd-kit/core';
import { useDraggable, useDroppable } from '@dnd-kit/core';
import {
  ChevronDown, ChevronRight, Users, UserPlus, Settings2,
  GripVertical, Crown, Shield, User, Briefcase, Building2,
  RefreshCw, Save, AlertCircle, CheckCircle, Loader2, X,
  Network, Edit3, Plus
} from 'lucide-react';
import axiosInstance from '../lib/axios';

// ============================================================
// TYPES
// ============================================================
interface OrgEmployee {
  id: number;
  name: string;
  job_role?: { name: string; color?: string };
  role?: string;
  avatar?: string;
  report_to?: number | null;
  children?: OrgEmployee[];
  is_active?: boolean;
}

// ============================================================
// EMPLOYEE CARD (Draggable)
// ============================================================
function OrgCard({ emp, depth = 0, isDragging = false }: { emp: OrgEmployee; depth?: number; isDragging?: boolean }) {
  const { attributes, listeners, setNodeRef, transform } = useDraggable({ id: emp.id });

  const initials = emp.name?.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase() || '??';
  const roleColor = emp.job_role?.color || '#6366f1';

  const style = transform ? {
    transform: `translate3d(${transform.x}px, ${transform.y}px, 0)`,
    zIndex: 999,
  } : undefined;

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={`relative group select-none ${isDragging ? 'opacity-30' : ''}`}
    >
      <div className={`
        flex items-center gap-3 p-3 rounded-xl border transition-all duration-200
        ${isDragging
          ? 'border-violet-500 bg-violet-500/20 shadow-xl shadow-violet-500/20'
          : 'border-white/10 bg-white/5 hover:bg-white/10 hover:border-white/20'
        }
      `}>
        {/* Grip handle */}
        <div
          {...listeners}
          {...attributes}
          className="cursor-grab active:cursor-grabbing p-1 text-white/30 hover:text-white/60 transition-colors flex-shrink-0"
        >
          <GripVertical size={14} />
        </div>

        {/* Avatar */}
        <div
          className="w-9 h-9 rounded-xl flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
          style={{ backgroundColor: roleColor + '33', border: `2px solid ${roleColor}66` }}
        >
          {initials}
        </div>

        {/* Info */}
        <div className="flex-1 min-w-0">
          <p className="text-sm font-semibold text-white truncate leading-tight">{emp.name}</p>
          <p className="text-xs truncate" style={{ color: roleColor }}>{emp.job_role?.name || emp.role || 'Sin puesto'}</p>
        </div>

        {/* Badge role */}
        {(emp.role === 'admin' || emp.role === 'encargado') && (
          <Crown size={12} style={{ color: roleColor }} className="flex-shrink-0" />
        )}
      </div>
    </div>
  );
}

// ============================================================
// DROP ZONE (for each employee node)
// ============================================================
function DropZone({
  emp, depth, children, isOver, expandedIds, onToggle, onDrop
}: any) {
  const { setNodeRef, isOver: dndIsOver } = useDroppable({ id: `drop-${emp.id}` });
  const hasChildren = emp.children && emp.children.length > 0;
  const isExpanded = expandedIds.has(emp.id);

  return (
    <div className="flex flex-col" style={{ marginLeft: depth > 0 ? 32 : 0 }}>
      {/* Connector line */}
      {depth > 0 && (
        <div className="flex items-start">
          <div className="w-6 border-l-2 border-b-2 border-white/10 rounded-bl-lg h-6 mt-0 flex-shrink-0" />
        </div>
      )}

      <div
        ref={setNodeRef}
        className={`rounded-2xl transition-all duration-150 mb-2 ${dndIsOver ? 'ring-2 ring-violet-500 bg-violet-500/5' : ''}`}
      >
        <div className="flex items-center gap-1">
          {/* Expand toggle */}
          <button
            onClick={() => onToggle(emp.id)}
            className={`w-5 h-5 rounded flex items-center justify-center flex-shrink-0 transition-colors ${
              hasChildren ? 'text-white/40 hover:text-white/70' : 'invisible'
            }`}
          >
            {isExpanded ? <ChevronDown size={12} /> : <ChevronRight size={12} />}
          </button>

          {/* Card */}
          <div className="flex-1">
            {children}
          </div>
        </div>

        {/* Drop indicator */}
        {dndIsOver && (
          <div className="mx-3 mt-1 mb-2 h-1 rounded-full bg-violet-500/60 animate-pulse" />
        )}
      </div>

      {/* Children */}
      {hasChildren && isExpanded && (
        <div className="ml-6 border-l-2 border-white/5 pl-2">
          {emp.children.map((child: OrgEmployee) => (
            <OrgNodeTree
              key={child.id}
              emp={child}
              depth={depth + 1}
              expandedIds={expandedIds}
              onToggle={onToggle}
              onDrop={onDrop}
              activeId={null}
            />
          ))}
        </div>
      )}
    </div>
  );
}

// ============================================================
// RECURSIVE NODE TREE
// ============================================================
function OrgNodeTree({ emp, depth, expandedIds, onToggle, onDrop, activeId }: any) {
  return (
    <DropZone
      emp={emp}
      depth={depth}
      expandedIds={expandedIds}
      onToggle={onToggle}
      onDrop={onDrop}
    >
      <OrgCard emp={emp} depth={depth} isDragging={activeId === emp.id} />
    </DropZone>
  );
}

// ============================================================
// MAIN COMPONENT: OrganigramaInteractivo
// ============================================================
export default function OrganigramaInteractivo() {
  const [employees, setEmployees] = useState<OrgEmployee[]>([]);
  const [tree, setTree] = useState<OrgEmployee[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [activeId, setActiveId] = useState<number | null>(null);
  const [activeEmp, setActiveEmp] = useState<OrgEmployee | null>(null);
  const [expandedIds, setExpandedIds] = useState<Set<number>>(new Set());
  const [pendingChanges, setPendingChanges] = useState<{ userId: number; reportTo: number | null }[]>([]);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } })
  );

  // ── Build Tree from flat list ──────────────────────────────
  const buildTree = useCallback((flatList: OrgEmployee[]): OrgEmployee[] => {
    const map: Record<number, OrgEmployee> = {};
    flatList.forEach(e => { map[e.id] = { ...e, children: [] }; });

    const roots: OrgEmployee[] = [];
    flatList.forEach(e => {
      if (e.report_to && map[e.report_to]) {
        map[e.report_to].children!.push(map[e.id]);
      } else {
        roots.push(map[e.id]);
      }
    });

    return roots;
  }, []);

  // ── Fetch employees ────────────────────────────────────────
  const fetchEmployees = useCallback(async () => {
    setIsLoading(true);
    setError('');
    try {
      const res = await axiosInstance.get('/employees?include=jobRole&per_page=200');
      const list: OrgEmployee[] = (res.data?.data || res.data || []).map((e: any) => ({
        id: e.user_id ?? e.id,
        name: e.name ?? e.user?.name ?? 'Sin nombre',
        job_role: e.job_role ?? e.jobRole,
        role: e.role ?? e.user?.role,
        report_to: e.report_to ?? null,
        is_active: e.is_active_employee ?? true,
      }));

      setEmployees(list);
      const treeData = buildTree(list);
      setTree(treeData);

      // Auto-expand first 2 levels
      const toExpand = new Set<number>();
      treeData.forEach(root => {
        toExpand.add(root.id);
        root.children?.forEach(child => toExpand.add(child.id));
      });
      setExpandedIds(toExpand);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Error al cargar el organigrama.');
    } finally {
      setIsLoading(false);
    }
  }, [buildTree]);

  useEffect(() => { fetchEmployees(); }, [fetchEmployees]);

  // ── Drag handlers ──────────────────────────────────────────
  const handleDragStart = (e: DragStartEvent) => {
    const emp = employees.find(x => x.id === e.active.id);
    setActiveId(e.active.id as number);
    setActiveEmp(emp || null);
  };

  const handleDragEnd = (e: DragEndEvent) => {
    const { active, over } = e;
    setActiveId(null);
    setActiveEmp(null);

    if (!over || active.id === over.id) return;

    // over.id format: "drop-{empId}"
    const targetId = typeof over.id === 'string'
      ? parseInt(over.id.replace('drop-', ''), 10)
      : Number(over.id);

    if (isNaN(targetId) || targetId === active.id) return;

    // Update flat list
    const updated = employees.map(emp => {
      if (emp.id === active.id) {
        return { ...emp, report_to: targetId };
      }
      return emp;
    });

    setEmployees(updated);
    setTree(buildTree(updated));

    // Queue pending change
    setPendingChanges(prev => {
      const filtered = prev.filter(c => c.userId !== active.id);
      return [...filtered, { userId: active.id as number, reportTo: targetId }];
    });

    setSuccess('');
  };

  // ── Toggle expand ──────────────────────────────────────────
  const handleToggle = (id: number) => {
    setExpandedIds(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  // ── Save changes ───────────────────────────────────────────
  const handleSave = async () => {
    if (pendingChanges.length === 0) return;
    setIsSaving(true);
    setError('');
    setSuccess('');

    try {
      // Send each change to backend
      await Promise.all(
        pendingChanges.map(change =>
          axiosInstance.patch(`/employees/${change.userId}/report-to`, {
            report_to: change.reportTo,
          })
        )
      );

      setPendingChanges([]);
      setSuccess(`${pendingChanges.length} cambio(s) guardados en el organigrama.`);
    } catch (err: any) {
      setError(err?.response?.data?.message || 'Error al guardar. Intenta de nuevo.');
    } finally {
      setIsSaving(false);
    }
  };

  // ── Discard changes ────────────────────────────────────────
  const handleDiscard = () => {
    setPendingChanges([]);
    fetchEmployees();
    setSuccess('');
    setError('');
  };

  // ── Stats ──────────────────────────────────────────────────
  const rootCount    = tree.length;
  const activeCount  = employees.filter(e => e.is_active).length;
  const rolesCount   = new Set(employees.map(e => e.job_role?.name).filter(Boolean)).size;

  return (
    <div className="flex flex-col h-full bg-[#0A0A0F] text-white">
      {/* ── HEADER ── */}
      <div className="flex items-center justify-between px-6 py-4 border-b border-white/10 flex-shrink-0">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-violet-500/20 flex items-center justify-center">
            <Network size={20} className="text-violet-400" />
          </div>
          <div>
            <h1 className="font-bold text-xl leading-tight">Organigrama</h1>
            <p className="text-xs text-white/40">Arrastra para reorganizar la estructura</p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={fetchEmployees}
            disabled={isLoading}
            className="p-2 rounded-lg bg-white/5 hover:bg-white/10 text-white/50 hover:text-white transition-all"
            title="Recargar"
          >
            <RefreshCw size={16} className={isLoading ? 'animate-spin' : ''} />
          </button>

          {pendingChanges.length > 0 && (
            <>
              <button
                onClick={handleDiscard}
                className="px-3 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 text-white/50 text-sm transition-all flex items-center gap-1.5"
              >
                <X size={14} /> Descartar
              </button>
              <button
                onClick={handleSave}
                disabled={isSaving}
                className="px-4 py-1.5 rounded-lg bg-violet-600 hover:bg-violet-500 text-white text-sm font-semibold transition-all flex items-center gap-1.5 disabled:opacity-50"
              >
                {isSaving ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}
                Guardar ({pendingChanges.length})
              </button>
            </>
          )}
        </div>
      </div>

      {/* ── STATS ── */}
      <div className="flex gap-4 px-6 py-3 border-b border-white/5 flex-shrink-0">
        {[
          { label: 'Colaboradores', value: activeCount, icon: Users, color: 'text-violet-400' },
          { label: 'Líderes raíz', value: rootCount, icon: Crown, color: 'text-amber-400' },
          { label: 'Puestos únicos', value: rolesCount, icon: Briefcase, color: 'text-emerald-400' },
        ].map(stat => (
          <div key={stat.label} className="flex items-center gap-2 bg-white/5 rounded-lg px-3 py-1.5">
            <stat.icon size={14} className={stat.color} />
            <span className="text-xs text-white/50">{stat.label}:</span>
            <span className="text-sm font-bold">{stat.value}</span>
          </div>
        ))}
      </div>

      {/* ── MESSAGES ── */}
      {error && (
        <div className="mx-6 mt-3 p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-sm text-red-400 flex items-center gap-2 flex-shrink-0">
          <AlertCircle size={15} className="flex-shrink-0" /> {error}
        </div>
      )}
      {success && (
        <div className="mx-6 mt-3 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-sm text-emerald-400 flex items-center gap-2 flex-shrink-0">
          <CheckCircle size={15} className="flex-shrink-0" /> {success}
        </div>
      )}

      {/* ── TREE ── */}
      <div className="flex-1 overflow-y-auto px-6 py-4">
        {isLoading ? (
          <div className="flex flex-col items-center justify-center h-64 gap-4">
            <div className="relative">
              <div className="w-16 h-16 rounded-2xl bg-violet-500/10 flex items-center justify-center">
                <Network size={28} className="text-violet-400" />
              </div>
              <div className="absolute inset-0 rounded-2xl border-2 border-violet-500/30 animate-ping" />
            </div>
            <p className="text-white/40 text-sm animate-pulse">Cargando organigrama...</p>
          </div>
        ) : employees.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-64 gap-3 text-white/30">
            <Users size={48} className="opacity-20" />
            <p className="text-base">No hay colaboradores registrados.</p>
            <p className="text-xs">Agrega empleados primero desde Recursos Humanos.</p>
          </div>
        ) : (
          <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragStart={handleDragStart}
            onDragEnd={handleDragEnd}
          >
            {/* Unassigned employees (no report_to) shown as roots */}
            <div className="space-y-1">
              {tree.map(root => (
                <OrgNodeTree
                  key={root.id}
                  emp={root}
                  depth={0}
                  expandedIds={expandedIds}
                  onToggle={handleToggle}
                  onDrop={() => {}}
                  activeId={activeId}
                />
              ))}
            </div>

            {/* Drag Overlay (what you see while dragging) */}
            <DragOverlay>
              {activeEmp && (
                <div className="rotate-2 scale-105">
                  <OrgCard emp={activeEmp} isDragging={false} />
                </div>
              )}
            </DragOverlay>
          </DndContext>
        )}
      </div>

      {/* ── PENDING BANNER ── */}
      {pendingChanges.length > 0 && !isSaving && (
        <div className="px-6 py-3 border-t border-amber-500/20 bg-amber-500/5 flex items-center justify-between flex-shrink-0">
          <div className="flex items-center gap-2 text-amber-400 text-sm">
            <AlertCircle size={15} />
            <span>{pendingChanges.length} cambio(s) sin guardar en la estructura</span>
          </div>
          <button onClick={handleSave} className="text-xs px-3 py-1.5 rounded-lg bg-amber-500/20 text-amber-300 hover:bg-amber-500/30 transition-colors">
            Guardar ahora
          </button>
        </div>
      )}
    </div>
  );
}
