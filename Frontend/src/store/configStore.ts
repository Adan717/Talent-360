import { useState, useEffect } from 'react';

// Valores por defecto
export const DEFAULT_CONFIGS = {
  timeBankConfigs: {
    mealMinutes: 60,
    mealMinMandatory: 20,
    shortBreakMinutes: 5,
    maxShortBreaks: 2
  },
  mealSettings: {
    maxChairs: 3,
    startHour: 13,
    endHour: 16,
    stepMins: 15,
    hideFullSlots: true
  },
  leySillaConfig: {
    enabled: true,
    roles: ['Cajeros'],
    maxStanding: 120,
    restDuration: 15,
    buddySystem: true
  },
  featureFlags: {
    modoNativo: false,
    paseLista: true,
    delegacionLlaves: true,
    amnistia: true,
    perimetro: true,
    comidas: true,
    evaluacion360: true,
    reportes: true,
    historial: true,
    sonidos: true,
    animacionesUI: true,
    modoOscuro: true
  },
  adminConfigs: {
    allowAmnesty: true,
    showHistory: true,
    allowThemes: true
  }
};

class ConfigStore extends EventTarget {
  private configs: typeof DEFAULT_CONFIGS;

  constructor() {
    super();
    const stored = localStorage.getItem('talent360_configs');
    if (stored) {
      try {
        const parsed = JSON.parse(stored);
        this.configs = {
           timeBankConfigs: { ...DEFAULT_CONFIGS.timeBankConfigs, ...parsed.timeBankConfigs },
           mealSettings: { ...DEFAULT_CONFIGS.mealSettings, ...parsed.mealSettings },
           leySillaConfig: { ...DEFAULT_CONFIGS.leySillaConfig, ...parsed.leySillaConfig },
           featureFlags: { ...DEFAULT_CONFIGS.featureFlags, ...parsed.featureFlags },
           adminConfigs: { ...DEFAULT_CONFIGS.adminConfigs, ...parsed.adminConfigs },
        };
      } catch (e) {
        this.configs = DEFAULT_CONFIGS;
      }
    } else {
      this.configs = DEFAULT_CONFIGS;
    }
  }

  get() {
    return this.configs;
  }

  update(newConfigs: Partial<typeof DEFAULT_CONFIGS>) {
    this.configs = { ...this.configs, ...newConfigs };
    localStorage.setItem('talent360_configs', JSON.stringify(this.configs));
    this.dispatchEvent(new Event('config_updated'));
  }
  
  // Private helper that doesn't stringify to prevent loops on storage event
  updateFromStorage(rawConfigs: any) {
    this.configs = { ...this.configs, ...rawConfigs };
    this.dispatchEvent(new Event('config_updated'));
  }
}

export const globalConfigStore = new ConfigStore();

export function useConfigStore() {
  const [state, setState] = useState(globalConfigStore.get());

  useEffect(() => {
    const handleUpdate = () => setState(globalConfigStore.get());
    globalConfigStore.addEventListener('config_updated', handleUpdate);
    
    // Escuchar cambios de otras pestañas
    const handleStorage = (e: StorageEvent) => {
       if (e.key === 'talent360_configs') {
          globalConfigStore.updateFromStorage(e.newValue ? JSON.parse(e.newValue) : DEFAULT_CONFIGS);
       }
    };
    window.addEventListener('storage', handleStorage);
    
    return () => {
       globalConfigStore.removeEventListener('config_updated', handleUpdate);
       window.removeEventListener('storage', handleStorage);
    };
  }, []);

  return [state, (c: Partial<typeof DEFAULT_CONFIGS>) => globalConfigStore.update(c)] as const;
}
