import { useState } from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { ModuleVideoShowcase } from './ModuleVideoShowcase';
import { moduleVideos, type ModuleVideo, type VideoModuleId } from './moduleVideos';

afterEach(cleanup);

function Harness({ videos = moduleVideos }: { videos?: readonly ModuleVideo[] }) {
  const [selected, setSelected] = useState<VideoModuleId>('onboarding');
  return <ModuleVideoShowcase selected={selected} onSelect={setSelected} videos={videos} />;
}

describe('Module video laptop', () => {
  it('keeps all original module content and never loads a player without a video', () => {
    const { container } = render(<Harness />);
    expect(screen.getByRole('heading', { name: 'Módulos en Acción' })).toBeVisible();
    expect(screen.getAllByRole('tab')).toHaveLength(3);
    expect(screen.getByRole('button', { name: /Video de Onboarding y Cuentas próximamente/ })).toBeDisabled();
    expect(container.querySelector('iframe')).toBeNull();
    fireEvent.click(screen.getByRole('tab', { name: /Reloj Checador Biométrico/ }));
    expect(screen.getByRole('tabpanel')).toHaveAttribute('aria-labelledby', 'lab-video-tab-asistencia');
    expect(screen.getByRole('button', { name: /Video de Reloj Checador Biométrico próximamente/ })).toBeDisabled();
  });

  it('loads the configured video only on play and removes playback on a module change', () => {
    const videos = moduleVideos.map(video => ({ ...video, youtubeUrl: 'https://youtu.be/AbCdEfGhI12' }));
    const { container } = render(<Harness videos={videos} />);
    expect(container.querySelector('iframe')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Reproducir video de Onboarding y Cuentas' }));
    expect(screen.getByTitle('Video: Onboarding y Cuentas')).toHaveAttribute('src', 'https://www.youtube-nocookie.com/embed/AbCdEfGhI12?autoplay=1&rel=0&playsinline=1');
    expect(screen.getByTitle('Video: Onboarding y Cuentas')).toHaveAttribute('allowfullscreen');
    fireEvent.click(screen.getByRole('tab', { name: /Portal de Empleos Integrado/ }));
    expect(container.querySelector('iframe')).toBeNull();
    expect(screen.getByRole('button', { name: 'Reproducir video de Portal de Empleos Integrado' })).toBeEnabled();
  });

  it('supports keyboard selection and moves focus to the selected module', () => {
    render(<Harness />);
    fireEvent.keyDown(screen.getByRole('tab', { name: /Onboarding y Cuentas/ }), { key: 'ArrowRight' });
    expect(screen.getByRole('tab', { name: /Reloj Checador Biométrico/ })).toHaveFocus();
    fireEvent.keyDown(screen.getByRole('tab', { name: /Reloj Checador Biométrico/ }), { key: 'End' });
    expect(screen.getByRole('tab', { name: /Portal de Empleos Integrado/ })).toHaveAttribute('aria-selected', 'true');
  });
});
