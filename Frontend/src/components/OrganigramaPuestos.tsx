import React, { useMemo, useState, useCallback, useEffect } from 'react';
import {
  ReactFlow,
  ReactFlowProvider,
  Controls,
  Background,
  Panel,
  Handle,
  Position,
  MarkerType,
  useNodesState,
  useEdgesState,
  type Node,
  type Edge,
  type Connection,
  type NodeProps,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import { renderJobRoleIcon } from '../lib/jobRoleIcons';

// Organigrama interactivo de puestos (Directorio > Puestos). Reemplaza el árbol CSS estático que
// vivía inline en RecursosHumanos.tsx por un lienzo real donde se puede dibujar la conexión entre
// dos puestos directamente arrastrando de uno a otro — ver docs/BACKEND_INTERFACES.md y la
// conversación del 2026-07-21 sobre por qué se decidió así.
//
// Dos tipos de relación se dibujan como capas separadas (misma idea que ya usaba el árbol viejo,
// solo que ahora es interactivo en vez de solo checkboxes):
//   - "jerarquia" (org_parent_role_id): línea SÓLIDA, un solo padre por puesto — es el esqueleto
//     visual del árbol.
//   - "reporta_a" (reports_to_role_ids): línea PUNTEADA, puede haber varias por puesto — es la
//     jerarquía operativa real (un puesto puede reportar a más de un superior).
//
// NOTA sobre layout: no hay ninguna librería de auto-layout de grafos instalada (dagre no está en
// package.json ni se pudo instalar — este sandbox no tiene acceso al registro de npm). Se
// implementó un layout de árbol simple (tipo "tidy tree") a mano más abajo — suficiente para un
// organigrama jerárquico normal, sin dependencias nuevas. Las posiciones se recalculan solas según
// org_parent_role_id; el usuario NO arrastra las tarjetas (nodesDraggable=false), solo dibuja o
// borra conexiones — así no hace falta guardar coordenadas x/y en la base de datos.

const NODE_WIDTH = 260;
// NODE_HEIGHT es solo la altura usada para el CÁLCULO del layout (separación entre filas del
// árbol). Ahora que cada tarjeta puede mostrar varias fichas de colaboradores, la altura visual
// real puede variar; para no tener que recalcular el árbol dinámicamente, la lista de
// colaboradores dentro de la tarjeta tiene scroll interno con una altura máxima (ver
// COLLAB_LIST_MAX_H más abajo) para que la tarjeta nunca crezca más allá de lo que este valor
// contempla y así no se encime con la fila de abajo.
const NODE_HEIGHT = 230;
const COLLAB_LIST_MAX_H = 150;
const H_GAP = 36;
const V_GAP = 120;

const LEVEL_BADGES: Record<number, { text: string; bg: string; border: string }> = {
  1: { text: '👑 Dirección', bg: 'bg-amber-100 text-amber-800 border-amber-200', border: 'border-amber-400' },
  2: { text: '⭐ Jefatura', bg: 'bg-blue-100 text-blue-800 border-blue-200', border: 'border-blue-400' },
  3: { text: '📈 Supervisión', bg: 'bg-emerald-100 text-emerald-800 border-emerald-200', border: 'border-emerald-400' },
  4: { text: '👤 Operativo', bg: 'bg-indigo-100 text-indigo-800 border-indigo-200', border: 'border-indigo-400' },
  5: { text: '🔧 Auxiliar', bg: 'bg-slate-100 text-slate-800 border-slate-200', border: 'border-slate-300' },
  6: { text: '🚫 Inactivo/Apoyo', bg: 'bg-rose-100 text-rose-800 border-rose-200', border: 'border-dashed border-slate-300' },
};

interface PuestoTreeNode {
  role: any;
  children: PuestoTreeNode[];
}

function getParentId(r: any): number | null {
  if (r.org_parent_role_id !== undefined && r.org_parent_role_id !== null) return Number(r.org_parent_role_id);
  if (r.reports_to_role_id !== undefined && r.reports_to_role_id !== null) return Number(r.reports_to_role_id);
  return null;
}

function buildForest(roles: any[]): PuestoTreeNode[] {
  const active = roles.filter((r) => r.is_active !== false);
  const activeIds = new Set(active.map((r) => r.id));

  const childrenOf = (id: number) =>
    active
      .filter((r) => getParentId(r) === id)
      .sort((a, b) => (a.nivel_mando ?? 4) - (b.nivel_mando ?? 4));

  const build = (r: any, path: Set<number>): PuestoTreeNode | null => {
    // Corta cualquier ciclo accidental al momento de renderizar (defensivo — la validación en
    // handleConnect/handleEdgeClick ya debería impedir crear uno, pero si llegara a existir uno
    // heredado de datos viejos, esto evita un loop infinito al construir el árbol).
    if (path.has(r.id)) return null;
    const nextPath = new Set(path).add(r.id);
    const children = childrenOf(r.id)
      .map((c) => build(c, nextPath))
      .filter((n): n is PuestoTreeNode => n !== null);
    return { role: r, children };
  };

  const isRoot = (r: any) => {
    const pid = getParentId(r);
    return pid === null || !activeIds.has(pid);
  };

  return active
    .filter(isRoot)
    .map((r) => build(r, new Set<number>()))
    .filter((n): n is PuestoTreeNode => n !== null);
}

function layoutForest(forest: PuestoTreeNode[]): { nodes: Node[]; edges: Edge[] } {
  const nodes: Node[] = [];
  let edges: Edge[] = [];

  const subtreeWidth = (n: PuestoTreeNode): number =>
    n.children.length === 0 ? 1 : n.children.reduce((sum, c) => sum + subtreeWidth(c), 0);

  const place = (n: PuestoTreeNode, depth: number, leftUnits: number) => {
    const width = subtreeWidth(n);
    const centerUnits = leftUnits + width / 2;
    const x = centerUnits * (NODE_WIDTH + H_GAP) - NODE_WIDTH / 2;
    const y = depth * (NODE_HEIGHT + V_GAP);

    nodes.push({
      id: String(n.role.id),
      type: 'puesto',
      position: { x, y },
      data: { role: n.role },
      draggable: false,
    });

    if (n.role.org_parent_role_id) {
      edges.push({
        id: `jerarquia-${n.role.id}-${n.role.org_parent_role_id}`,
        source: String(n.role.id),
        target: String(n.role.org_parent_role_id),
        sourceHandle: 'src-top',
        targetHandle: 'tgt-bottom',
        type: 'straight',
        style: { stroke: '#6366f1', strokeWidth: 2 },
        markerEnd: { type: MarkerType.ArrowClosed, color: '#6366f1', width: 16, height: 16 },
        data: { kind: 'jerarquia' },
      });
    }

    ((n.role.reports_to_role_ids || []) as number[]).forEach((targetId) => {
      if (!targetId || Number(targetId) === n.role.org_parent_role_id) return;
      edges.push({
        id: `reporta-${n.role.id}-${targetId}`,
        source: String(n.role.id),
        target: String(targetId),
        sourceHandle: 'src-top',
        targetHandle: 'tgt-bottom',
        type: 'straight',
        style: { stroke: '#f59e0b', strokeWidth: 2, strokeDasharray: '6 4' },
        markerEnd: { type: MarkerType.ArrowClosed, color: '#f59e0b', width: 14, height: 14 },
        data: { kind: 'reporta_a' },
      });
    });

    let childLeft = leftUnits;
    n.children.forEach((c) => {
      place(c, depth + 1, childLeft);
      childLeft += subtreeWidth(c);
    });
  };

  let cursorX = 0;
  forest.forEach((root) => {
    const width = subtreeWidth(root);
    place(root, 0, cursorX);
    cursorX += width;
  });

  const nodeIds = new Set(nodes.map((n) => n.id));
  edges = edges.filter((e) => nodeIds.has(e.source) && nodeIds.has(e.target));

  return { nodes, edges };
}

function PuestoNode({ data }: NodeProps) {
  const role = (data as any).role;
  const collaborators: any[] = (data as any).collaborators || [];
  const readOnly: boolean = !!(data as any).readOnly;
  const onCollaboratorDrop: ((employeeId: number, targetRoleId: number) => void | Promise<void>) | undefined =
    (data as any).onCollaboratorDrop;
  const level = role.nivel_mando ?? 4;
  const levelInfo = LEVEL_BADGES[level] || LEVEL_BADGES[4];
  const isActive = role.is_active !== false;
  const [isDropTarget, setIsDropTarget] = useState(false);

  return (
    <div
      onDragOver={(e) => {
        if (readOnly || !onCollaboratorDrop) return;
        // Solo reaccionamos si lo que se arrastra es una ficha de colaborador (marcada más abajo
        // con type='collaborator'); así no interferimos con el propio sistema de arrastre de
        // conexiones de React Flow, que no usa el HTML5 Drag and Drop nativo.
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (!isDropTarget) setIsDropTarget(true);
      }}
      onDragLeave={() => {
        if (isDropTarget) setIsDropTarget(false);
      }}
      onDrop={(e) => {
        setIsDropTarget(false);
        if (readOnly || !onCollaboratorDrop) return;
        const draggedType = e.dataTransfer.getData('type');
        if (draggedType !== 'collaborator') return;
        e.preventDefault();
        const employeeId = Number(e.dataTransfer.getData('text/plain'));
        if (!employeeId) return;
        onCollaboratorDrop(employeeId, role.id);
      }}
      className={`bg-white border-2 rounded-2xl px-4 py-3 text-center shadow-sm transition-all ${levelInfo.border} ${
        !isActive ? 'opacity-60' : ''
      } ${isDropTarget ? 'ring-4 ring-indigo-400/40 border-dashed border-indigo-500 bg-indigo-50/50 scale-[1.03]' : ''}`}
      style={{ width: NODE_WIDTH }}
    >
      {/* Handle superior: se usa para ARRASTRAR desde este puesto hacia su superior (el puesto
          queda "abajo" en el árbol, así que el gesto natural es jalar desde arriba hacia el jefe). */}
      <Handle type="source" position={Position.Top} id="src-top" style={{ background: '#94a3b8', width: 10, height: 10 }} />
      {/* Handle inferior: se usa para RECIBIR una conexión de un subordinado que arrastra desde su
          propio handle superior hasta aquí. */}
      <Handle type="target" position={Position.Bottom} id="tgt-bottom" style={{ background: '#94a3b8', width: 10, height: 10 }} />

      <span className={`text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full border ${levelInfo.bg}`}>
        {levelInfo.text}
      </span>
      <div className="font-black text-xs text-slate-800 uppercase tracking-widest mt-2 mb-1 truncate flex items-center justify-center gap-1.5" title={role.name}>
        <span className="shrink-0 text-indigo-600">{renderJobRoleIcon(role, 14)}</span>
        <span className="truncate">{role.name}</span>
      </div>
      <div className="text-[9px] font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-md inline-block mb-2">
        {role.area || 'General'}
      </div>

      {/* nopan es la clase clave aquí: sin ella, el gesto de "mousedown + arrastrar" sobre una ficha
          de colaborador lo capta el pan-on-drag del LIENZO de React Flow (que sigue activo aunque
          nodesDraggable={false} — eso solo desactiva mover el NODO, no el paneo del fondo) y termina
          arrastrando todo el organigrama en vez de iniciar el drag-and-drop nativo HTML5 de la ficha. */}
      <div className="space-y-1.5 overflow-y-auto pr-0.5 nodrag nopan nowheel" style={{ maxHeight: COLLAB_LIST_MAX_H }}>
        {collaborators.length > 0 ? (
          collaborators.map((c: any) => (
            <div
              key={c.id}
              draggable={!readOnly}
              onDragStart={(e) => {
                if (readOnly) return;
                e.stopPropagation();
                e.dataTransfer.setData('type', 'collaborator');
                e.dataTransfer.setData('text/plain', String(c.id));
              }}
              className={`nodrag nopan flex items-center gap-2 bg-slate-50 border border-slate-100 p-1.5 rounded-xl text-left select-none ${
                readOnly ? '' : 'cursor-grab active:cursor-grabbing hover:bg-indigo-50/50 hover:border-indigo-100'
              }`}
              title={readOnly ? c.name : `Arrastra a otro puesto para reasignar a ${c.name}`}
            >
              <img
                src={c.avatar || `https://api.dicebear.com/7.x/avataaars/svg?seed=${c.name}`}
                alt={c.name}
                className="w-6 h-6 rounded-full border-2 border-white shadow-sm flex-shrink-0"
                draggable={false}
              />
              <div className="overflow-hidden">
                <div className="text-[10px] font-black text-slate-800 truncate leading-tight">{c.name}</div>
                {c.email && <div className="text-[8px] font-medium text-slate-400 truncate">{c.email}</div>}
              </div>
            </div>
          ))
        ) : (
          <div className="text-[10px] font-bold italic text-slate-400 bg-slate-50 border border-dashed border-slate-200 py-2 rounded-xl">
            🕳️ Vacante / Sin asignar
          </div>
        )}
      </div>
      {!isActive && <div className="text-[9px] font-bold text-rose-500 mt-1.5">Inactivo</div>}
    </div>
  );
}

const nodeTypes = { puesto: PuestoNode };

interface OrganigramaPuestosProps {
  jobRoles: any[];
  employees: any[];
  readOnly?: boolean;
  onUpdateRole: (roleId: number, patch: Record<string, any>) => void | Promise<void>;
  onNodeClick?: (role: any) => void;
  /** Reasigna a un colaborador a otro puesto — se dispara al soltar su ficha sobre otra tarjeta. */
  onCollaboratorDrop?: (employeeId: number, targetRoleId: number) => void | Promise<void>;
}

function OrganigramaPuestosInner({
  jobRoles,
  employees,
  readOnly,
  onUpdateRole,
  onNodeClick,
  onCollaboratorDrop,
}: OrganigramaPuestosProps) {
  const [connectMode, setConnectMode] = useState<'jerarquia' | 'reporta_a'>('jerarquia');

  const employeesByRole = useMemo(() => {
    const map = new Map<number, any[]>();
    employees
      .filter((e: any) => e.is_active_employee !== false && e.job_role_id)
      .forEach((e: any) => {
        const list = map.get(e.job_role_id) || [];
        list.push(e);
        map.set(e.job_role_id, list);
      });
    return map;
  }, [employees]);

  const { nodes: layoutNodes, edges: layoutEdges } = useMemo(() => {
    const forest = buildForest(jobRoles);
    const { nodes, edges } = layoutForest(forest);
    nodes.forEach((n) => {
      (n.data as any).collaborators = employeesByRole.get(Number(n.id)) || [];
      (n.data as any).readOnly = !!readOnly;
      (n.data as any).onCollaboratorDrop = onCollaboratorDrop;
    });
    return { nodes, edges };
  }, [jobRoles, employeesByRole, readOnly, onCollaboratorDrop]);

  const [nodes, setNodes, onNodesChange] = useNodesState(layoutNodes);
  const [edges, setEdges, onEdgesChange] = useEdgesState(layoutEdges);

  useEffect(() => {
    setNodes(layoutNodes);
    setEdges(layoutEdges);
  }, [layoutNodes, layoutEdges, setNodes, setEdges]);

  const wouldCreateJerarquiaCycle = useCallback(
    (sourceId: number, targetId: number): boolean => {
      if (sourceId === targetId) return true;
      let current: number | null = targetId;
      const visited = new Set<number>();
      while (current !== null && !visited.has(current)) {
        visited.add(current);
        if (current === sourceId) return true;
        const role = jobRoles.find((r: any) => r.id === current);
        current = role?.org_parent_role_id ?? null;
      }
      return false;
    },
    [jobRoles]
  );

  const wouldCreateReportaCycle = useCallback(
    (sourceId: number, targetId: number): boolean => {
      if (sourceId === targetId) return true;
      // BFS desde targetId siguiendo reports_to_role_ids: si llegamos a sourceId, la conexión
      // source -> target cerraría un ciclo (target ya reporta, directa o indirectamente, a source).
      const queue: number[] = [targetId];
      const visited = new Set<number>();
      while (queue.length > 0) {
        const current = queue.shift() as number;
        if (visited.has(current)) continue;
        visited.add(current);
        if (current === sourceId) return true;
        const role = jobRoles.find((r: any) => r.id === current);
        ((role?.reports_to_role_ids || []) as number[]).forEach((id) => queue.push(Number(id)));
      }
      return false;
    },
    [jobRoles]
  );

  const handleConnect = useCallback(
    (connection: Connection) => {
      if (readOnly) return;
      if (!connection.source || !connection.target) return;
      const sourceId = Number(connection.source);
      const targetId = Number(connection.target);
      if (sourceId === targetId) {
        alert('Un puesto no puede reportarse a sí mismo.');
        return;
      }

      if (connectMode === 'jerarquia') {
        if (wouldCreateJerarquiaCycle(sourceId, targetId)) {
          alert('Esa conexión crearía un ciclo en la jerarquía visual (un puesto terminaría siendo superior de sí mismo).');
          return;
        }
        onUpdateRole(sourceId, { org_parent_role_id: targetId });
      } else {
        if (wouldCreateReportaCycle(sourceId, targetId)) {
          alert('Esa conexión crearía un ciclo en "Reporta A" (el puesto destino ya reporta, directa o indirectamente, a este puesto).');
          return;
        }
        const role = jobRoles.find((r: any) => r.id === sourceId);
        const existingIds: number[] = (role?.reports_to_role_ids || []).map(Number);
        if (existingIds.includes(targetId)) return;
        const updatedIds = [...existingIds, targetId];
        onUpdateRole(sourceId, { reports_to_role_ids: updatedIds, reports_to_role_id: updatedIds[0] });
      }
    },
    [connectMode, jobRoles, onUpdateRole, readOnly, wouldCreateJerarquiaCycle, wouldCreateReportaCycle]
  );

  const handleEdgeClick = useCallback(
    (event: React.MouseEvent, edge: Edge) => {
      if (readOnly) return;
      event.stopPropagation();
      const kind = (edge.data as any)?.kind;
      const sourceRole = jobRoles.find((r: any) => r.id === Number(edge.source));
      const targetRole = jobRoles.find((r: any) => r.id === Number(edge.target));
      const label = kind === 'jerarquia' ? 'la jerarquía visual' : '"Reporta A"';
      if (!window.confirm(`¿Quitar la conexión de ${label}?\n\n"${sourceRole?.name || '?'}" → "${targetRole?.name || '?'}"`)) {
        return;
      }

      if (kind === 'jerarquia') {
        onUpdateRole(Number(edge.source), { org_parent_role_id: null });
      } else {
        const updatedIds = ((sourceRole?.reports_to_role_ids || []) as number[])
          .map(Number)
          .filter((id) => id !== Number(edge.target));
        onUpdateRole(Number(edge.source), { reports_to_role_ids: updatedIds, reports_to_role_id: updatedIds[0] ?? null });
      }
    },
    [jobRoles, onUpdateRole, readOnly]
  );

  return (
    <div className="w-full h-[600px] bg-slate-50 border border-slate-200 rounded-3xl overflow-hidden shadow-inner relative">
      <ReactFlow
        nodes={nodes}
        edges={edges}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        onConnect={handleConnect}
        onEdgeClick={handleEdgeClick}
        onNodeClick={(_, node) => onNodeClick?.((node.data as any).role)}
        nodeTypes={nodeTypes}
        nodesDraggable={false}
        nodesConnectable={!readOnly}
        elementsSelectable={!readOnly}
        fitView
        proOptions={{ hideAttribution: true }}
      >
        <Background gap={24} color="#e2e8f0" />
        <Controls showInteractive={false} />
        {!readOnly && (
          <Panel
            position="top-left"
            className="bg-white/95 backdrop-blur-sm border border-slate-200/80 rounded-2xl p-1.5 shadow-lg flex gap-1"
          >
            <button
              type="button"
              onClick={() => setConnectMode('jerarquia')}
              className={`px-3 py-2 rounded-xl text-[11px] font-black uppercase tracking-wider transition-all ${
                connectMode === 'jerarquia' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800'
              }`}
            >
              🌳 Jerarquía visual
            </button>
            <button
              type="button"
              onClick={() => setConnectMode('reporta_a')}
              className={`px-3 py-2 rounded-xl text-[11px] font-black uppercase tracking-wider transition-all ${
                connectMode === 'reporta_a' ? 'bg-amber-500 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800'
              }`}
            >
              🔗 Reporta A
            </button>
          </Panel>
        )}
        <Panel
          position="bottom-left"
          className="bg-white/90 backdrop-blur-sm border border-slate-200 rounded-xl px-3 py-2 text-[10px] text-slate-500 font-semibold max-w-[280px]"
        >
          {connectMode === 'jerarquia'
            ? 'Arrastra desde el punto superior de un puesto hasta el punto inferior de su jefe visual (línea sólida). Clic en una línea para quitarla.'
            : 'Arrastra desde el punto superior de un puesto hasta el punto inferior de cada supervisor operativo (línea punteada, puede haber varias). Clic en una línea para quitarla.'}
          {' '}Arrastra la ficha de un colaborador y suéltala sobre otra tarjeta para cambiarlo de puesto.
        </Panel>
      </ReactFlow>
    </div>
  );
}

export default function OrganigramaPuestos(props: OrganigramaPuestosProps) {
  return (
    <ReactFlowProvider>
      <OrganigramaPuestosInner {...props} />
    </ReactFlowProvider>
  );
}
