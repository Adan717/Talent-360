import React from 'react';
import { CLOCK_FEATURE_TAGS_MATRIX } from '../logic/clockFeatureTags';
import type { ClockFeatureTag } from '../logic/clockFeatureTags';
import { useAppStore } from '../../../store/useAppStore';

export const ClockControlCenterPanel: React.FC = () => {
  const { allowedFeatures, isFeatureUnlocked } = useAppStore();

  const handleToggleTag = (key: string, isMandatory?: boolean) => {
    if (isMandatory) return; // Las funciones obligatorias de ley/seguridad no se desactivan

    const currentlyActive = isFeatureUnlocked(key);
    let updatedFeatures: string[];

    if (currentlyActive) {
      updatedFeatures = allowedFeatures.filter((f) => f !== key);
    } else {
      updatedFeatures = [...allowedFeatures, key];
    }

    useAppStore.setState({ allowedFeatures: updatedFeatures });
  };

  const getTierBadge = (tier: ClockFeatureTag['defaultTier']) => {
    switch (tier) {
      case 'free':
        return <span className="px-2 py-0.5 text-xs font-semibold rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">🆓 Gratuito</span>;
      case 'pro':
        return <span className="px-2 py-0.5 text-xs font-semibold rounded bg-violet-500/10 text-violet-400 border border-violet-500/20">💎 Plan Pro</span>;
      case 'enterprise':
        return <span className="px-2 py-0.5 text-xs font-semibold rounded bg-amber-500/10 text-amber-400 border border-amber-500/20">🏢 Enterprise</span>;
    }
  };

  return (
    <div className="bg-slate-900 border border-slate-800 rounded-xl p-6 text-slate-100 shadow-xl max-w-4xl mx-auto">
      <div className="flex items-center justify-between pb-4 border-b border-slate-800 mb-6">
        <div>
          <h2 className="text-xl font-extrabold flex items-center gap-2 text-white">
            🎛️ Centro de Control General — Módulos del Reloj Checador
          </h2>
          <p className="text-xs text-slate-400 mt-1">
            Habilite o deshabilite las características del Dialer por plan. Las funciones desactivadas realizan degradación suave (fallback) sin interrumpir el fichaje básico.
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {CLOCK_FEATURE_TAGS_MATRIX.map((tag) => {
          const isChecked = isFeatureUnlocked(tag.key);
          return (
            <div
              key={tag.key}
              onClick={() => handleToggleTag(tag.key, tag.isMandatory)}
              className={`p-4 rounded-lg border transition-all duration-200 cursor-pointer flex flex-col justify-between ${
                isChecked
                  ? 'bg-slate-800/80 border-slate-700 hover:border-slate-600'
                  : 'bg-slate-950/40 border-slate-900 opacity-60 hover:opacity-80'
              }`}
            >
              <div>
                <div className="flex items-center justify-between mb-2">
                  <div className="flex items-center gap-3">
                    <input
                      type="checkbox"
                      checked={isChecked}
                      disabled={tag.isMandatory}
                      onChange={() => {}}
                      className="w-4 h-4 rounded text-violet-600 focus:ring-violet-500 bg-slate-950 border-slate-700 cursor-pointer"
                    />
                    <span className="font-bold text-sm text-slate-200">{tag.name}</span>
                  </div>
                  {getTierBadge(tag.defaultTier)}
                </div>
                <p className="text-xs text-slate-400 pl-7">{tag.description}</p>
              </div>

              <div className="mt-3 pl-7 flex items-center gap-2 text-[10px] font-mono text-slate-500">
                <span>Flag:</span>
                <code className="bg-slate-950 px-1.5 py-0.5 rounded border border-slate-800 text-slate-400">{tag.key}</code>
                {tag.isMandatory && <span className="text-emerald-400 ml-auto font-sans font-medium">Core Intocable</span>}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
};
