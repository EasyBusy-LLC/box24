function createFallbackRequestId() {
  if (globalThis.crypto?.getRandomValues) {
    const bytes = new Uint8Array(16);
    globalThis.crypto.getRandomValues(bytes);

    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    return [...bytes]
      .map((value, index) => {
        const hex = value.toString(16).padStart(2, '0');
        return [4, 6, 8, 10].includes(index) ? `-${hex}` : hex;
      })
      .join('');
  }

  return `req-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function fallbackRandomUUID() {
  return createFallbackRequestId();
}

export function createRequestId() {
  if (globalThis.crypto?.randomUUID && globalThis.crypto.randomUUID !== fallbackRandomUUID) {
    return globalThis.crypto.randomUUID();
  }
  return createFallbackRequestId();
}

export function installCryptoRandomUUIDFallback() {
  let cryptoObject = globalThis.crypto;
  if (!cryptoObject) {
    try {
      Object.defineProperty(globalThis, 'crypto', { configurable: true, value: {} });
      cryptoObject = globalThis.crypto;
    } catch {
      return false;
    }
  }
  if (typeof cryptoObject.randomUUID === 'function') return false;
  try {
    Object.defineProperty(cryptoObject, 'randomUUID', {
      configurable: true,
      value: fallbackRandomUUID,
    });
    return typeof cryptoObject.randomUUID === 'function';
  } catch {
    return false;
  }
}
