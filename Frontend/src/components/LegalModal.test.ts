// @vitest-environment node
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Candado del texto legal (Plan A1, 2026-09-07).
 *
 * El texto vive en `LegalModal.tsx` (copia única que consumen el modal y `/privacidad`). Estas
 * frases prometían cosas que el sistema NO hace (99.5% de disponibilidad, CFDI automático,
 * cancelación desde el panel, exportación a Excel, fotos "encriptadas") o contradecían otra parte
 * del mismo aviso. Si alguna vuelve, esta prueba truena antes de que llegue a producción.
 *
 * Se lee el archivo fuente y no el DOM a propósito: lo que se protege es el TEXTO que firma el
 * cliente, esté donde esté renderizado.
 */
const fuente = readFileSync(resolve(__dirname, 'LegalModal.tsx'), 'utf8');

describe('Texto legal: lo que ya no se promete', () => {
  it.each([
    '99.5%',
    'CFDI',
    'Excel/CSV',
    'con al menos 24 horas de anticipación',
    'desde el panel de administración de SaaS',
    'conforme a la Ley Federal del Trabajo (LFT)',
    'se conservan de forma encriptada',
    'Proveedores Autorizados de Certificación',
  ])('no contiene "%s"', (frase) => {
    expect(fuente).not.toContain(frase);
  });
});

describe('Texto legal: lo que sí es verdad hoy', () => {
  it.each([
    'opera bajo mejores esfuerzos, sin comprometer un porcentaje específico',
    'procurará informar por adelantado, cuando sea posible',
    'escribiendo a <strong>soporte@talent360.com.mx</strong>',
    'una copia de sus datos en formato JSON',
    'La facturación fiscal se gestiona por separado',
    'no calcula ISR ni cuotas del IMSS ni sustituye la nómina formal',
    'el registro de asistencia queda interrumpido hasta regularizar el pago',
    'no cifradas en reposo',
  ])('contiene "%s"', (frase) => {
    expect(fuente).toContain(frase);
  });

  it('la fecha impresa es la de la versión vigente (AvisoDePrivacidad::FECHA_LEGIBLE)', () => {
    // Misma fecha en las dos pestañas (privacidad y términos): una sola versión del texto.
    expect(fuente.split('Última actualización: 7 de Septiembre de 2026')).toHaveLength(3);
    expect(fuente).not.toContain('24 de Julio de 2026');
  });
});
