import React, { useEffect, useRef, useState } from 'react';
import { Camera, X, RefreshCw, Check } from 'lucide-react';

// §23 (estados #17 y #18b de docs/Logica Dial.md): evidencia fotográfica del comedor al INICIAR y al
// TERMINAR la comida. Modal autocontenido de captura por cámara: abre getUserMedia, deja tomar la foto,
// la comprime a un JPEG (~lado máx 900px, calidad 0.7) como data URL y la entrega por onCapture. El
// backend (POST /clock/meal-photo, §23) acepta exactamente ese data URI base64 y corta a 2MB; la
// compresión de aquí lo deja muy por debajo (~150-250KB).

interface MealPhotoCaptureProps {
  type: 'meal_start' | 'meal_end';
  onCapture: (dataUrl: string) => void | Promise<void>;
  onCancel: () => void;
  submitting?: boolean;
}

const MAX_SIDE = 900;
const JPEG_QUALITY = 0.7;

export default function MealPhotoCapture({ type, onCapture, onCancel, submitting = false }: MealPhotoCaptureProps) {
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [starting, setStarting] = useState(true);

  const title = type === 'meal_start' ? 'Foto del comedor LIMPIO (antes de comer)' : 'Foto del comedor LIMPIO (al terminar)';

  const stopStream = () => {
    if (streamRef.current) {
      streamRef.current.getTracks().forEach((t) => t.stop());
      streamRef.current = null;
    }
  };

  const startCamera = async () => {
    setError(null);
    setStarting(true);
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment' },
        audio: false,
      });
      streamRef.current = stream;
      if (videoRef.current) {
        videoRef.current.srcObject = stream;
        await videoRef.current.play().catch(() => {});
      }
    } catch (e: any) {
      setError('No se pudo acceder a la cámara. Revisa los permisos del navegador.');
    } finally {
      setStarting(false);
    }
  };

  useEffect(() => {
    startCamera();
    return stopStream;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const takePhoto = () => {
    const video = videoRef.current;
    if (!video || !video.videoWidth) return;

    const scale = Math.min(1, MAX_SIDE / Math.max(video.videoWidth, video.videoHeight));
    const w = Math.round(video.videoWidth * scale);
    const h = Math.round(video.videoHeight * scale);

    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    ctx.drawImage(video, 0, 0, w, h);
    const dataUrl = canvas.toDataURL('image/jpeg', JPEG_QUALITY);
    setPreview(dataUrl);
    stopStream();
  };

  const retake = () => {
    setPreview(null);
    startCamera();
  };

  const confirm = async () => {
    if (!preview) return;
    await onCapture(preview);
  };

  return (
    <div className="absolute inset-0 bg-slate-900/90 backdrop-blur-sm z-[60] flex flex-col p-4 animate-fade-in-up">
      <div className="bg-white rounded-3xl p-4 w-full flex-grow flex flex-col shadow-2xl relative overflow-hidden text-slate-800">
        <div className="flex justify-between items-center mb-3">
          <h3 className="font-extrabold text-sm text-slate-800 flex items-center gap-2">
            <Camera size={16} className="text-emerald-600" /> Evidencia de Comedor
          </h3>
          <button onClick={onCancel} className="text-slate-400 hover:text-slate-700" aria-label="Cancelar">
            <X size={20} />
          </button>
        </div>

        <p className="text-[11px] text-slate-500 mb-3 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 font-semibold">
          {title}
        </p>

        <div className="flex-grow relative bg-slate-900 rounded-2xl overflow-hidden flex items-center justify-center">
          {error ? (
            <div className="text-center p-6">
              <p className="text-rose-300 text-xs font-bold mb-3">{error}</p>
              <button onClick={startCamera} className="bg-white/10 text-white text-xs font-bold px-4 py-2 rounded-full border border-white/20 hover:bg-white/20 flex items-center gap-1.5 mx-auto">
                <RefreshCw size={12} /> Reintentar
              </button>
            </div>
          ) : preview ? (
            <img src={preview} alt="Evidencia capturada" className="w-full h-full object-cover" />
          ) : (
            <>
              <video ref={videoRef} playsInline muted className="w-full h-full object-cover" />
              {starting && <span className="absolute text-white/70 text-xs font-semibold">Iniciando cámara…</span>}
            </>
          )}
        </div>

        <div className="mt-4">
          {preview ? (
            <div className="flex gap-2">
              <button
                onClick={retake}
                disabled={submitting}
                className="flex-1 py-3 bg-slate-100 text-slate-700 font-bold rounded-2xl hover:bg-slate-200 active:scale-95 transition-all flex items-center justify-center gap-1.5 disabled:opacity-50"
              >
                <RefreshCw size={16} /> Repetir
              </button>
              <button
                onClick={confirm}
                disabled={submitting}
                className="flex-1 py-3 bg-emerald-600 text-white font-extrabold rounded-2xl shadow-lg hover:bg-emerald-700 active:scale-95 transition-all flex items-center justify-center gap-1.5 disabled:opacity-50"
              >
                <Check size={16} /> {submitting ? 'Enviando…' : 'Usar foto'}
              </button>
            </div>
          ) : (
            <button
              onClick={takePhoto}
              disabled={!!error || starting}
              className="w-full py-4 bg-emerald-600 text-white font-extrabold rounded-2xl shadow-lg hover:bg-emerald-700 active:scale-95 transition-all flex items-center justify-center gap-2 disabled:opacity-50"
            >
              <Camera size={18} /> Tomar Foto
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
