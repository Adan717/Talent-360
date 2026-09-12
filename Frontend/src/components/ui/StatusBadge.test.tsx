import { render, screen, cleanup } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { StatusBadge, type StatusTone } from './StatusBadge';

afterEach(cleanup);

describe('StatusBadge', () => {
  it.each<StatusTone>(['success', 'warning', 'danger', 'error', 'info', 'neutral'])(
    '%s exposes a text label and an accompanying icon', tone => {
      const { container } = render(<StatusBadge tone={tone}>Estado de prueba</StatusBadge>);
      expect(screen.getByText('Estado de prueba')).toBeVisible();
      const badge = container.querySelector(`[data-status="${tone}"]`);
      expect(badge?.querySelector('svg')).toHaveAttribute('aria-hidden', 'true');
    },
  );
});
