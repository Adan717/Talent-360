// Firma criptográfica offline (docs/BACKEND_INTERFACES.md §2).
// Obtiene y cachea EN MEMORIA (no localStorage, para reducir superficie de robo) el secreto HMAC
// vigente del tenant, y calcula el offline_stamp que exige /clock/punch-batch para cada fichaje
// guardado sin conexión.
//
// IMPORTANTE — debe coincidir EXACTO con Backend/app/Services/OfflineSignatureService.php:
//   PHP:  hash_hmac('sha256', $message, $plain)
//   donde $plain es el secreto tal como lo devuelve GET /clock/offline-secret: un STRING base64
//   (ej. "a3F9c2Xy..."), NO decodificado de vuelta a los 32 bytes crudos. hash_hmac usa los bytes
//   UTF-8/ASCII de ese string como clave. Si aquí decodificáramos el base64 antes de firmar,
//   la firma nunca coincidiría con la que el backend recalcula para verificar.
//
// $message = "{$user_id}|{$type}|{$time}|{$client_timestamp}" (interpolación de string simple).
// hash_hmac() con el 4to argumento en false (default) devuelve hex minúsculas de 64 caracteres.

import axiosInstance from './axios';

interface CachedSecret {
  secret: string;
  cachedAt: number;
}

let cachedSecret: CachedSecret | null = null;
let inFlightFetch: Promise<string> | null = null;

// El backend rota el secreto cada 24h (ver getOrCreateCurrentSecret). Refrescamos un poco antes
// para minimizar la ventana en la que firmamos con un secreto a punto de expirar — igual el backend
// acepta el secreto anterior hasta 1h después de vencido, así que hay margen de sobra.
const MAX_CACHE_AGE_MS = 23 * 60 * 60 * 1000;

async function fetchSecretFromServer(): Promise<string> {
  const res = await axiosInstance.get('/clock/offline-secret');
  const secret = res.data?.secret;
  if (!secret || typeof secret !== 'string') {
    throw new Error('El servidor no devolvió un secreto de firma offline válido.');
  }
  cachedSecret = { secret, cachedAt: Date.now() };
  return secret;
}

/**
 * Devuelve el secreto vigente, usando la copia en memoria si todavía es fresca.
 * Si dos llamadas concurrentes piden el secreto sin caché, comparten la misma petición en vuelo
 * en vez de disparar dos GET simultáneos.
 */
export async function getOfflineSecret(): Promise<string> {
  if (cachedSecret && Date.now() - cachedSecret.cachedAt < MAX_CACHE_AGE_MS) {
    return cachedSecret.secret;
  }
  if (inFlightFetch) return inFlightFetch;

  inFlightFetch = fetchSecretFromServer().finally(() => {
    inFlightFetch = null;
  });
  return inFlightFetch;
}

/**
 * Precarga el secreto en memoria mientras hay conexión (llamar al montar la app / iniciar sesión),
 * para que ya esté disponible si el dispositivo pierde conexión más tarde y necesita firmar un
 * fichaje offline sin poder alcanzar el servidor en ese momento.
 */
export async function warmOfflineSecret(): Promise<void> {
  try {
    await getOfflineSecret();
  } catch (e) {
    console.error('No se pudo precargar el secreto de firma offline:', e);
  }
}

async function hmacSha256Hex(secret: string, message: string): Promise<string> {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw',
    enc.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  const signatureBuffer = await crypto.subtle.sign('HMAC', key, enc.encode(message));
  return Array.from(new Uint8Array(signatureBuffer))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}

/**
 * Calcula el offline_stamp para un fichaje. Si no hay secreto en caché Y el dispositivo está
 * offline en este momento, esto fallará (no hay forma de firmarlo sin el secreto) — por eso
 * warmOfflineSecret() debe llamarse proactivamente mientras hay conexión.
 */
export async function computeOfflineStamp(
  userId: number,
  type: string,
  time: string,
  clientTimestamp: string
): Promise<string> {
  const secret = await getOfflineSecret();
  const message = `${userId}|${type}|${time}|${clientTimestamp}`;
  return hmacSha256Hex(secret, message);
}
