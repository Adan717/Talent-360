import { useState, useEffect, useRef } from 'react';

export interface VoiceAssistantField {
  id: string;
  label: string;
  type: 'text' | 'select' | 'number';
  value: any;
  setValue: (val: any) => void;
  options?: { value: string | number; label: string }[];
}

export interface UseVoiceFormAssistantProps {
  fields: VoiceAssistantField[];
  onSave: () => void;
  onCancel: () => void;
  isPremium: boolean;
  onUpgradeRequired: () => void;
}

export function useVoiceFormAssistant({
  fields,
  onSave,
  onCancel,
  isPremium,
  onUpgradeRequired,
}: UseVoiceFormAssistantProps) {
  const [isListening, setIsListening] = useState(false);
  const [activeFieldIndex, setActiveFieldIndex] = useState<number>(-1);
  const [transcript, setTranscript] = useState('');
  const [feedback, setFeedback] = useState('');
  const recognitionRef = useRef<any>(null);

  // Helper to read feedback aloud to the user
  const speak = (text: string) => {
    if ('speechSynthesis' in window) {
      window.speechSynthesis.cancel();
      const utterance = new SpeechSynthesisUtterance(text);
      utterance.lang = 'es-MX';
      window.speechSynthesis.speak(utterance);
    }
  };

  const startAssistant = () => {
    if (!isPremium) {
      onUpgradeRequired();
      return;
    }

    const SpeechRecognition =
      (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
    if (!SpeechRecognition) {
      alert('Tu navegador no soporta el reconocimiento de voz. Te recomendamos Google Chrome.');
      return;
    }

    setIsListening(true);
    setActiveFieldIndex(0);
    setTranscript('');
    setFeedback(`Asistente iniciado. Por favor di: ${fields[0].label}`);
    speak(`Asistente de voz iniciado. Por favor, menciona el ${fields[0].label}.`);
  };

  const stopAssistant = () => {
    setIsListening(false);
    setActiveFieldIndex(-1);
    if (recognitionRef.current) {
      try {
        recognitionRef.current.stop();
      } catch (e) {
        console.error(e);
      }
    }
    if ('speechSynthesis' in window) {
      window.speechSynthesis.cancel();
    }
  };

  // Logic to parse numbers from spoken Spanish
  const wordToNumber = (text: string): number => {
    const clean = text.toLowerCase().trim();
    if (clean.includes('mil')) {
      const parts = clean.split('mil');
      const multiplier = 1000;
      const baseWord = parts[0].trim();
      const baseVal = parseSpanishNumberWord(baseWord) || 1;
      let restVal = 0;
      if (parts[1]) {
        restVal = parseSpanishNumberWord(parts[1].trim()) || 0;
      }
      return baseVal * multiplier + restVal;
    }
    return parseFloat(clean.replace(/[^0-9]/g, '')) || 0;
  };

  const parseSpanishNumberWord = (word: string): number => {
    const map: Record<string, number> = {
      'un': 1, 'uno': 1, 'dos': 2, 'tres': 3, 'cuatro': 4, 'cinco': 5, 'seis': 6, 'siete': 7, 'ocho': 8, 'nueve': 9, 'diez': 10,
      'once': 11, 'doce': 12, 'trece': 13, 'catorce': 14, 'quince': 15, 'dieciséis': 16, 'diecisiete': 17, 'dieciocho': 18, 'diecinueve': 19, 'veinte': 20,
      'treinta': 30, 'cuarenta': 40, 'cincuenta': 50, 'sesenta': 60, 'setenta': 70, 'ochenta': 80, 'noventa': 90, 'cien': 100, 'ciento': 100,
      'doscientos': 200, 'trescientos': 300, 'cuatrocientos': 400, 'quinientos': 500, 'seiscientos': 600, 'setecientos': 700, 'ochocientos': 800, 'novecientos': 900
    };
    return map[word] || parseFloat(word) || 0;
  };

  // Capitalize name inputs beautifully
  const capitalizeText = (text: string): string => {
    return text
      .trim()
      .split(/\s+/)
      .map(w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
      .join(' ');
  };

  useEffect(() => {
    if (!isListening || activeFieldIndex < 0) return;

    const SpeechRecognition =
      (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition;
    const rec = new SpeechRecognition();
    recognitionRef.current = rec;

    rec.continuous = false;
    rec.interimResults = false;
    rec.lang = 'es-MX';

    rec.onresult = (event: any) => {
      const resultText = event.results[0][0].transcript;
      setTranscript(resultText);

      // Handle general global voice commands
      const lowerText = resultText.toLowerCase().trim();
      if (lowerText === 'guardar' || lowerText === 'guardar formulario') {
        setFeedback('Guardando formulario...');
        speak('Guardando.');
        setTimeout(() => {
          onSave();
          stopAssistant();
        }, 1000);
        return;
      }
      if (lowerText === 'cancelar' || lowerText === 'cerrar') {
        setFeedback('Cancelando...');
        speak('Cancelado.');
        setTimeout(() => {
          onCancel();
          stopAssistant();
        }, 1000);
        return;
      }

      // Process current field
      const currentField = fields[activeFieldIndex];
      if (currentField.type === 'text') {
        const corrected = capitalizeText(resultText);
        currentField.setValue(corrected);
      } else if (currentField.type === 'number') {
        const val = wordToNumber(resultText);
        currentField.setValue(val);
      } else if (currentField.type === 'select' && currentField.options) {
        // Option selection
        const cleanSpoken = lowerText.replace(/[^a-z0-9áéíóúñ\s]/g, '');
        let matched = false;

        // Try to match index words (primera, segunda, etc.)
        if (cleanSpoken.includes('primero') || cleanSpoken.includes('primera') || cleanSpoken.includes('uno')) {
          currentField.setValue(currentField.options[0]?.value);
          matched = true;
        } else if (cleanSpoken.includes('segundo') || cleanSpoken.includes('segunda') || cleanSpoken.includes('dos')) {
          currentField.setValue(currentField.options[1]?.value);
          matched = true;
        } else if (cleanSpoken.includes('tercero') || cleanSpoken.includes('tercera') || cleanSpoken.includes('tres')) {
          currentField.setValue(currentField.options[2]?.value);
          matched = true;
        }

        // Try matching option text
        if (!matched) {
          const matchedOpt = currentField.options.find(opt =>
            opt.label.toLowerCase().includes(cleanSpoken) ||
            cleanSpoken.includes(opt.label.toLowerCase())
          );
          if (matchedOpt) {
            currentField.setValue(matchedOpt.value);
            matched = true;
          }
        }

        if (!matched) {
          setFeedback(`Opción no reconocida: "${resultText}". Intenta de nuevo.`);
          speak(`No reconocí esa opción. Por favor, repítelo.`);
          return; // Don't advance
        }
      }

      // Advance to next step
      const nextIndex = activeFieldIndex + 1;
      if (nextIndex < fields.length) {
        setActiveFieldIndex(nextIndex);
        setTranscript('');
        const nextField = fields[nextIndex];
        let promptText = `Por favor di: ${nextField.label}.`;
        if (nextField.type === 'select' && nextField.options) {
          promptText += ` Las opciones son: ${nextField.options.map(o => o.label).join(', ')}.`;
        }
        setFeedback(promptText);
        speak(`Entendido. Siguiente campo: ${nextField.label}.`);
      } else {
        setActiveFieldIndex(-1); // Finished filling fields
        setFeedback('Llenado completo. Di: "guardar" para confirmar o "cancelar".');
        speak('Todos los campos completados. Di Guardar para confirmar, o Cancelar.');
      }
    };

    rec.onerror = (event: any) => {
      console.error('Speech recognition error:', event.error);
      if (event.error === 'no-speech') {
        // Silently restart to wait for user speech
        try {
          rec.start();
        } catch (e) {}
      } else {
        setFeedback('Error al escuchar. Por favor, activa tu micrófono.');
      }
    };

    rec.onend = () => {
      // Auto-restart listening if still active and not finished
      if (isListening && activeFieldIndex >= 0) {
        try {
          rec.start();
        } catch (e) {}
      }
    };

    try {
      rec.start();
    } catch (e) {
      console.error(e);
    }

    return () => {
      rec.onresult = null;
      rec.onerror = null;
      rec.onend = null;
      try {
        rec.stop();
      } catch (e) {}
    };
  }, [isListening, activeFieldIndex]);

  return {
    isListening,
    activeFieldIndex,
    activeField: activeFieldIndex >= 0 ? fields[activeFieldIndex] : null,
    transcript,
    feedback,
    startAssistant,
    stopAssistant,
  };
}
