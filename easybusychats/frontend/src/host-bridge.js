import { AppError } from './errors.js';
import { createRequestId } from './request-id.js';

export const SOURCE_HOST = 'easybusy-bitrix24-host';
export const SOURCE_PLUGIN = 'easybusy-bitrix24-plugin';
export const PROTOCOL_VERSION = 1;
const MAX_MESSAGE_AGE = 5 * 60 * 1000;

export function normalizeOrigin(value) {
  const raw = String(value || '').trim().replace(/\/$/, '');
  if (!raw) return '';
  try {
    const url = new URL(raw);
    if (!['http:', 'https:'].includes(url.protocol) || url.origin !== raw) return '';
    return raw;
  } catch {
    return '';
  }
}

export function isValidHostEnvelope(event, expectedOrigin, expectedSource) {
  const message = event?.data;
  return event?.source === expectedSource
    && event?.origin === expectedOrigin
    && message && typeof message === 'object'
    && message.source === SOURCE_HOST
    && Number(message.version) === PROTOCOL_VERSION
    && typeof message.id === 'string'
    && Number.isFinite(message.timestamp)
    && Math.abs(Date.now() - message.timestamp) <= MAX_MESSAGE_AGE;
}

export function createHostBridge({ hostOrigin, timeout = 15000 } = {}) {
  const origin = normalizeOrigin(hostOrigin);
  if (!origin || window.parent === window) return null;
  const parent = window.parent;
  const pending = new Map();
  let sessionNonce = '';
  let bootstrap = null;
  let disposed = false;
  let connectPromise = null;

  function post(type, payload = {}, requestId = createRequestId(), includeNonce = true) {
    parent.postMessage({
      source: SOURCE_PLUGIN,
      version: PROTOCOL_VERSION,
      sessionNonce: includeNonce ? sessionNonce : '',
      id: requestId,
      type,
      payload,
      timestamp: Date.now(),
    }, origin);
    return requestId;
  }

  function onMessage(event) {
    if (!isValidHostEnvelope(event, origin, parent)) return;
    const message = event.data;
    if (sessionNonce && message.sessionNonce !== sessionNonce) return;
    const entry = pending.get(message.id);
    if (!entry) return;
    pending.delete(message.id);
    window.clearTimeout(entry.timer);
    if (message.success === false || message.error) {
      entry.reject(new AppError(message.error?.message || 'Bitrix24 operation failed.', {
        code: message.error?.code || 'HOST_REQUEST_FAILED',
        retryable: Boolean(message.error?.retryable),
      }));
      return;
    }
    entry.resolve(message.payload || {});
  }

  window.addEventListener('message', onMessage);

  function wait(requestId, waitTimeout = timeout) {
    return new Promise((resolve, reject) => {
      const timer = window.setTimeout(() => {
        pending.delete(requestId);
        reject(new AppError('Bitrix24 did not respond in time.', { code: 'HOST_TIMEOUT', retryable: true }));
      }, Math.max(250, Math.min(60000, Number(waitTimeout) || 15000)));
      pending.set(requestId, { resolve, reject, timer });
    });
  }

  async function connect() {
    if (bootstrap && sessionNonce) return bootstrap;
    if (connectPromise) return connectPromise;
    connectPromise = (async () => {
      const totalTimeout = Math.max(3000, Math.min(60000, Number(timeout) || 15000));
      const deadline = Date.now() + totalTimeout;
      let lastError = null;
      while (!disposed && Date.now() < deadline) {
        const requestId = createRequestId();
        const remaining = deadline - Date.now();
        const response = wait(requestId, Math.min(1250, remaining));
        post('host:ready', {}, requestId, false);
        try {
          bootstrap = await response;
          sessionNonce = String(bootstrap.sessionNonce || '');
          if (!sessionNonce || Number(bootstrap.protocolVersion) !== PROTOCOL_VERSION || normalizeOrigin(bootstrap.hostOrigin) !== origin) {
            throw new AppError('Bitrix24 bridge handshake failed.', { code: 'HOST_HANDSHAKE_FAILED' });
          }
          return bootstrap;
        } catch (error) {
          lastError = error;
          if (error?.code !== 'HOST_TIMEOUT') throw error;
        }
      }
      throw lastError || new AppError('Bitrix24 did not respond in time.', { code: 'HOST_TIMEOUT', retryable: true });
    })().finally(() => {
      connectPromise = null;
    });
    return connectPromise;
  }

  async function request(type, payload = {}) {
    if (disposed) throw new AppError('Bitrix24 bridge is closed.', { code: 'HOST_BRIDGE_CLOSED' });
    if (!sessionNonce) await connect();
    const requestId = createRequestId();
    const response = wait(requestId);
    post(type, payload, requestId, true);
    return response;
  }

  return {
    connect,
    request,
    getBootstrap: () => bootstrap,
    dispose() {
      disposed = true;
      window.removeEventListener('message', onMessage);
      pending.forEach((entry) => {
        window.clearTimeout(entry.timer);
        entry.reject(new AppError('Bitrix24 bridge was closed.', { code: 'HOST_BRIDGE_CLOSED' }));
      });
      pending.clear();
    },
  };
}
