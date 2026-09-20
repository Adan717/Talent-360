export type NotificationTone = 'info' | 'success' | 'warning' | 'error';

export type DialogOptions = {
  title?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  tone?: NotificationTone;
  placeholder?: string;
};

export type AppDialogRequest = {
  id: number;
  kind: 'confirm' | 'prompt';
  message: string;
  defaultValue?: string;
  options: DialogOptions;
  resolve: (value: boolean | string | null) => void;
};

export type AppNotification = {
  id: number;
  message: string;
  tone: NotificationTone;
};

let sequence = 0;
let dialogHandler: ((request: AppDialogRequest) => void) | null = null;

const inferTone = (message: string): NotificationTone => {
  if (/error|fall[oó]|no se pudo|inv[aá]lid|rechaz|eliminar|borrar|permanent|cancelar/i.test(message)) return 'error';
  if (/advert|atenci[oó]n|seguro|confirm|pendiente|bloquead/i.test(message)) return 'warning';
  if (/correct|[eé]xito|guardad|cread|actualizad|enviad|completad|aprobad/i.test(message)) return 'success';
  return 'info';
};

export function registerDialogHandler(handler: ((request: AppDialogRequest) => void) | null) {
  dialogHandler = handler;
}

export function notify(message: unknown, tone?: NotificationTone) {
  if (typeof window === 'undefined') return;
  const text = String(message ?? '').trim();
  if (!text) return;
  window.dispatchEvent(new CustomEvent<AppNotification>('talent360:notify', {
    detail: { id: ++sequence, message: text, tone: tone ?? inferTone(text) },
  }));
}

export function confirmAction(message: string, options: DialogOptions = {}): Promise<boolean> {
  return new Promise(resolve => {
    if (!dialogHandler) {
      resolve(false);
      return;
    }
    dialogHandler({
      id: ++sequence,
      kind: 'confirm',
      message,
      options: { tone: inferTone(message), ...options },
      resolve: value => resolve(value === true),
    });
  });
}

export function promptForText(message: string, defaultValue = '', options: DialogOptions = {}): Promise<string | null> {
  return new Promise(resolve => {
    if (!dialogHandler) {
      resolve(null);
      return;
    }
    dialogHandler({
      id: ++sequence,
      kind: 'prompt',
      message,
      defaultValue,
      options: { tone: 'info', ...options },
      resolve: value => resolve(typeof value === 'string' ? value : null),
    });
  });
}
