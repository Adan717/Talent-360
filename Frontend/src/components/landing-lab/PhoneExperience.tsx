import { useState } from 'react';
import { Armchair, Check, Fingerprint, RotateCcw, ShieldCheck, Smartphone } from 'lucide-react';
import { LabClockSimulator } from './LabClockSimulator';

export function PhoneExperience({ onChoosePlan }: { onChoosePlan: () => void }) {
  const [tier, setTier] = useState<'free' | 'pro'>('pro');
  const [resetKey, setResetKey] = useState(0);

  return <section id="lab-reloj" className="lab-section lab-phone-section" aria-labelledby="lab-phone-title"><div className="lab-container lab-phone-layout">
    <div className="lab-phone-copy"><span className="lab-eyebrow">NO SOLO LO VEAS. PRUÉBALO.</span><h2 id="lab-phone-title">Un gran día.<br /><span>Desde la primera entrada.</span></h2><p>El reloj checador de Talent 360, tal como lo vive tu equipo. Toca la pantalla y recorre una jornada de ejemplo.</p>
      <div className="lab-phone-demo-control"><div><strong>Elige cómo explorar la demo</strong><span>Cambia entre la experiencia Básica y Pro.</span></div><div className="lab-phone-controls"><div role="group" aria-label="Versión del reloj de ejemplo"><button type="button" aria-pressed={tier === 'free'} onClick={() => { setTier('free'); setResetKey(key => key + 1); }}>Básica</button><button type="button" aria-pressed={tier === 'pro'} onClick={() => { setTier('pro'); setResetKey(key => key + 1); }}>Pro</button></div><button className="lab-phone-reset" type="button" aria-label="Reiniciar simulador del reloj" title="Reiniciar simulador" onClick={() => setResetKey(key => key + 1)}><RotateCcw size={17} /></button></div></div>
      <div className="lab-phone-benefits"><div><Fingerprint size={21} /><span><strong>Una entrada, todo conectado.</strong><p>Prueba el registro y su validación visual.</p></span></div><div><Armchair size={21} /><span><strong>También hay espacio para una pausa.</strong><p>Explora descansos, comida y salida.</p></span></div><div><Smartphone size={21} /><span><strong>Tu jornada, en el bolsillo.</strong><p>Reloj, tareas y academia en la misma experiencia.</p></span></div></div>
      <p className="lab-phone-safety"><ShieldCheck size={16} /><span>Demo con datos de ejemplo. No solicita cámara ni ubicación y no crea registros reales.</span></p>
    </div>
    <div className="lab-phone-stage">
      <div className="lab-phone-device" role="group" aria-label="Teléfono interactivo: reloj checador"><div className="lab-phone-notch" aria-hidden="true"><i /><span /></div><div className="lab-phone-screen"><LabClockSimulator key={resetKey} tier={tier} setTier={setTier} onActionClick={onChoosePlan} empName="Colaborador de ejemplo" storeName="Empresa de ejemplo" /></div><div className="lab-phone-home" aria-hidden="true" /></div>
      <div className="lab-phone-touch-note"><Check size={13} /> Puedes tocar y explorar esta pantalla.</div>
    </div>
  </div></section>;
}
