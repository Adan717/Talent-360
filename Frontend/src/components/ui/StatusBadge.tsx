import { CheckCircle2, AlertTriangle, CircleX, Info, MinusCircle } from 'lucide-react';
import type { ReactNode } from 'react';

export type StatusTone = 'success' | 'warning' | 'danger' | 'error' | 'info' | 'neutral';

const states = {
  success: { icon: CheckCircle2, classes: 'bg-success-bg text-success-text', iconClass: 'text-success-icon' },
  warning: { icon: AlertTriangle, classes: 'bg-warning-bg text-warning-text', iconClass: 'text-warning-icon' },
  danger: { icon: CircleX, classes: 'bg-danger-bg text-danger-text', iconClass: 'text-danger-icon' },
  error: { icon: CircleX, classes: 'bg-error-bg text-error-text', iconClass: 'text-error-icon' },
  info: { icon: Info, classes: 'bg-info-bg text-info-text', iconClass: 'text-info-icon' },
  neutral: { icon: MinusCircle, classes: 'bg-page text-text-2', iconClass: 'text-text-2' },
} as const;

export function StatusBadge({ tone, children, className = '' }: {
  tone: StatusTone;
  children: ReactNode;
  className?: string;
}) {
  const { icon: Icon, classes, iconClass } = states[tone];
  return (
    <span data-status={tone} className={`inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-semibold ${classes} ${className}`}>
      <Icon size={14} className={`shrink-0 ${iconClass}`} aria-hidden="true" />
      <span>{children}</span>
    </span>
  );
}
