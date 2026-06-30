/**
 * Helper utilities for generating QR codes in local development and production.
 */

export const isLocalhost = (): boolean => {
  const hostname = window.location.hostname;
  return hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1';
};

export const getQrOrigin = (overrideValue?: string): string => {
  if (!isLocalhost()) {
    return window.location.origin;
  }

  const override = overrideValue !== undefined ? overrideValue : (localStorage.getItem('qr_origin_override') || '');
  if (override.trim()) {
    let clean = override.trim();
    // Remove protocol if present to normalize
    clean = clean.replace(/^(https?:\/\/)/, '');
    // Prepend http://
    return `http://${clean}`;
  }

  return window.location.origin;
};
