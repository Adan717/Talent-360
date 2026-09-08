import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import PropuestaDeReglamento, { formatearValor, reglasPresentables, type Propuesta } from './PropuestaDeReglamento';

/**
 * El panel de la propuesta del reglamento (Plan A3, 2026-09-07).
 *
 * Lo que se protege: que "cargar" entregue EXACTAMENTE lo que el admin marcó (nada más, nada
 * menos), que lo de confianza baja no venga marcado de fábrica, que las claves que la pantalla no
 * sabe pintar no aparezcan aunque el servidor las mandara, y que las advertencias se vean.
 */
const propuesta: Propuesta = {
  fuente: { nombre: 'reglamento.pdf', caracteres: 12345 },
  propuesta: {
    late_tolerance_minutes: { valor: 15, cita: 'tolerancia de quince minutos', confianza: 'alta' },
    absences_for_suspension: { valor: 4, cita: 'cuatro a suspensión', confianza: 'media' },
    late_action_mode: { valor: 'extend_shift', cita: '', confianza: 'baja' },
    deduct_absence_day: { valor: true, cita: 'sin goce de sueldo', confianza: 'alta' },
    clave_que_la_pantalla_no_conoce: { valor: 1, cita: '', confianza: 'alta' },
  },
  articulos: [{ referencia: 'Artículo 12', resumen: 'Tolerancia de entrada.' }],
  advertencias: ['El reglamento permite 900 minutos de tiempo extra; la LFT (art. 66) tope en 540.'],
  descartadas: ['salario_minimo'],
};

const actuales = { late_tolerance_minutes: 10, absences_for_suspension: 4, late_action_mode: 'deduct', deduct_absence_day: false };

describe('reglasPresentables / formatearValor', () => {
  it('sólo presenta claves conocidas, en el orden de la tabla', () => {
    expect(reglasPresentables(propuesta.propuesta).map(([k]) => k)).toEqual([
      'late_tolerance_minutes', 'absences_for_suspension', 'deduct_absence_day', 'late_action_mode',
    ]);
  });

  it('formatea booleanos y el modo de retardo en español', () => {
    expect(formatearValor('deduct_absence_day', true)).toBe('Sí');
    expect(formatearValor('late_action_mode', 'extend_shift')).toBe('Se repone al final del turno');
    expect(formatearValor('late_tolerance_minutes', 15)).toBe('15');
  });
});

describe('PropuestaDeReglamento', () => {
  it('muestra propuesta frente a lo de hoy, la cita y las advertencias', () => {
    render(<PropuestaDeReglamento propuesta={propuesta} valoresActuales={actuales} onAplicar={vi.fn()} onDescartar={vi.fn()} />);

    expect(screen.getByText(/Propuesta leída de: reglamento.pdf/)).toBeInTheDocument();
    expect(screen.getByText('«tolerancia de quince minutos»')).toBeInTheDocument();
    expect(screen.getByText(/tope en 540/)).toBeInTheDocument();
    expect(screen.queryByText(/clave_que_la_pantalla_no_conoce/)).not.toBeInTheDocument();
  });

  it('carga sólo lo marcado: alta y media vienen marcadas, baja no, y desmarcar excluye', () => {
    const onAplicar = vi.fn();
    render(<PropuestaDeReglamento propuesta={propuesta} valoresActuales={actuales} onAplicar={onAplicar} onDescartar={vi.fn()} />);

    expect(screen.getByLabelText('Cargar Tolerancia de entrada (min)')).toBeChecked();
    expect(screen.getByLabelText('Cargar Faltas para suspensión')).toBeChecked();
    expect(screen.getByLabelText('Cargar Qué pasa con el retardo')).not.toBeChecked(); // confianza baja

    fireEvent.click(screen.getByLabelText('Cargar Faltas para suspensión'));
    fireEvent.click(screen.getByRole('button', { name: /Cargar 2 en el formulario/ }));

    expect(onAplicar).toHaveBeenCalledWith({ late_tolerance_minutes: 15, deduct_absence_day: true });
  });

  it('sin nada marcado no se puede cargar, y descartar avisa', () => {
    const onDescartar = vi.fn();
    render(<PropuestaDeReglamento propuesta={propuesta} valoresActuales={actuales} onAplicar={vi.fn()} onDescartar={onDescartar} />);

    fireEvent.click(screen.getByLabelText('Cargar Tolerancia de entrada (min)'));
    fireEvent.click(screen.getByLabelText('Cargar Faltas para suspensión'));
    fireEvent.click(screen.getByLabelText('Cargar La falta descuenta el día'));
    expect(screen.getByRole('button', { name: /Cargar 0 en el formulario/ })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'Descartar' }));
    expect(onDescartar).toHaveBeenCalled();
  });
});
