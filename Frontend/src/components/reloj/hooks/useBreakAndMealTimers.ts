import { useState, useEffect } from 'react';
import { useAppStore } from '../../../store/useAppStore';

// Extraído de useClockEngine.tsx (refactor Jul 2026): agrupa los horarios de descanso/comida/salida
// y la petición pendiente de descanso. En Sandbox (simulador Matrix) estos valores viven en estado
// local de React espejado a localStorage; fuera de Sandbox, vienen del store global (useAppStore),
// hidratado por fetchState() desde /sync/state. Misma lógica y nombres que antes, solo reubicados.
export function useBreakAndMealTimers() {
  const isSandboxMode = useAppStore(s => s.isSandboxMode);
  const globalBreakStartTimes = useAppStore(s => s.globalBreakStartTimes);
  const globalBreakEndTimes = useAppStore(s => s.globalBreakEndTimes);
  const globalMealStartTimes = useAppStore(s => s.globalMealStartTimes);
  const globalMealEndTimes = useAppStore(s => s.globalMealEndTimes);
  const globalCheckOutTimes = useAppStore(s => s.globalCheckOutTimes);
  const globalPendingBreakRequests = useAppStore(s => s.globalPendingBreakRequests);

  const [localBreakStartTimes, setLocalBreakStartTimes] = useState<Record<number, number>>(() => {
     try {
       const saved = localStorage.getItem('clock_break_start_times');
       return saved ? JSON.parse(saved) : {};
     } catch {
       return {};
     }
  });

  const [localMealStartTimes, setLocalMealStartTimes] = useState<Record<number, number>>(() => {
     try {
       const saved = localStorage.getItem('clock_meal_start_times');
       return saved ? JSON.parse(saved) : {};
     } catch {
       return {};
     }
  });

  const [localMealEndTimes, setLocalMealEndTimes] = useState<Record<number, number>>(() => {
     try {
       const saved = localStorage.getItem('clock_meal_end_times');
       return saved ? JSON.parse(saved) : {};
     } catch {
       return {};
     }
  });

  const [localCheckOutTimes, setLocalCheckOutTimes] = useState<Record<number, number>>(() => {
     try {
       const saved = localStorage.getItem('clock_checkout_times');
       return saved ? JSON.parse(saved) : {};
     } catch {
       return {};
     }
  });

  const [localBreakEndTimes, setLocalBreakEndTimes] = useState<Record<number, number>>(() => {
     try {
       const saved = localStorage.getItem('clock_break_end_times');
       return saved ? JSON.parse(saved) : {};
     } catch {
       return {};
     }
  });

  useEffect(() => {
     localStorage.setItem('clock_break_start_times', JSON.stringify(localBreakStartTimes));
  }, [localBreakStartTimes]);

  useEffect(() => {
     localStorage.setItem('clock_meal_start_times', JSON.stringify(localMealStartTimes));
  }, [localMealStartTimes]);

  useEffect(() => {
     localStorage.setItem('clock_meal_end_times', JSON.stringify(localMealEndTimes));
  }, [localMealEndTimes]);

  useEffect(() => {
     localStorage.setItem('clock_checkout_times', JSON.stringify(localCheckOutTimes));
  }, [localCheckOutTimes]);

  useEffect(() => {
     localStorage.setItem('clock_break_end_times', JSON.stringify(localBreakEndTimes));
  }, [localBreakEndTimes]);

  const [localPendingBreakRequests, setLocalPendingBreakRequests] = useState<Record<number, any>>(() => {
     try {
       const saved = localStorage.getItem('clock_pending_break_requests');
       return saved ? JSON.parse(saved) : {};
     } catch {
       return {};
     }
  });

  useEffect(() => {
     localStorage.setItem('clock_pending_break_requests', JSON.stringify(localPendingBreakRequests));
  }, [localPendingBreakRequests]);

  const pendingBreakRequests = isSandboxMode ? localPendingBreakRequests : (globalPendingBreakRequests || {});

  const setPendingBreakRequests = (updater: any) => {
    if (isSandboxMode) {
      setLocalPendingBreakRequests(prev => typeof updater === 'function' ? updater(prev) : updater);
    } else {
      useAppStore.setState((state: any) => ({
        globalPendingBreakRequests: typeof updater === 'function' ? updater(state.globalPendingBreakRequests || {}) : updater
      }));
    }
  };

  const breakStartTimes = isSandboxMode ? localBreakStartTimes : (globalBreakStartTimes || {});
  const breakEndTimes = isSandboxMode ? localBreakEndTimes : (globalBreakEndTimes || {});
  const mealStartTimes = isSandboxMode ? localMealStartTimes : (globalMealStartTimes || {});
  const mealEndTimes = isSandboxMode ? localMealEndTimes : (globalMealEndTimes || {});
  const checkOutTimes = isSandboxMode ? localCheckOutTimes : (globalCheckOutTimes || {});

  const setBreakStartTimes = (updater: any) => {
    setLocalBreakStartTimes(prev => typeof updater === 'function' ? updater(prev) : updater);
  };
  const setBreakEndTimes = (updater: any) => {
    setLocalBreakEndTimes(prev => typeof updater === 'function' ? updater(prev) : updater);
  };
  const setMealStartTimes = (updater: any) => {
    setLocalMealStartTimes(prev => typeof updater === 'function' ? updater(prev) : updater);
  };
  const setMealEndTimes = (updater: any) => {
    setLocalMealEndTimes(prev => typeof updater === 'function' ? updater(prev) : updater);
  };
  const setCheckOutTimes = (updater: any) => {
    setLocalCheckOutTimes(prev => typeof updater === 'function' ? updater(prev) : updater);
  };

  return {
    pendingBreakRequests, setPendingBreakRequests,
    breakStartTimes, setBreakStartTimes,
    breakEndTimes, setBreakEndTimes,
    mealStartTimes, setMealStartTimes,
    mealEndTimes, setMealEndTimes,
    checkOutTimes, setCheckOutTimes,
  };
}
