// @ts-nocheck
import React from 'react';
import { ClockContext2 } from './store/ClockContext2';
import { useClockEngine } from './reloj2/useClockEngine';
import RelojVisual from './reloj2/RelojVisual';

class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }
  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }
  render() {
    if (this.state.hasError) {
      return (
        <div className="p-8 bg-red-50 text-red-900 h-screen">
          <h1 className="text-2xl font-bold">Error Fatal en React</h1>
          <pre className="mt-4 bg-white p-4 rounded border border-red-200 overflow-auto">
            {this.state.error?.stack || this.state.error?.message || String(this.state.error)}
          </pre>
        </div>
      );
    }
    return this.props.children;
  }
}

export default function RelojChecadorWrapper2() {
  return (
    <ErrorBoundary>
      <RelojChecador2 />
    </ErrorBoundary>
  );
}

function RelojChecador2() {
  const engine = useClockEngine();
  
  if (engine.isGlobalLoading) {
    return (
      <div className="flex flex-col items-center justify-center h-screen bg-slate-100 p-8 text-center animate-pulse">
        <h2 className="text-3xl font-black text-slate-400 mb-4">Cargando Sincronización...</h2>
      </div>
    );
  }

  if (engine.dbEmpty) {
    return (
      <div className="flex flex-col items-center justify-center h-screen bg-slate-100 p-8 text-center">
        <h2 className="text-3xl font-black text-slate-800 mb-4">Base de Datos Vacía</h2>
        <p className="text-slate-600 mb-8 max-w-md">No hay empleados registrados en la base de datos. Ve al Centro de Mando e inyecta los mocks de prueba.</p>
      </div>
    );
  }

  return (
    <ClockContext2.Provider value={engine}>
      <RelojVisual />
    </ClockContext2.Provider>
  );
}
