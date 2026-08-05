import { AppError } from './errors.js';

let loaderPromise = null;

export function loadPlugin(url, timeout = 30000) {
  if (typeof window.renderChatsApp === 'function') return Promise.resolve();
  if (loaderPromise) return loaderPromise;
  loaderPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    let settled = false;
    const started = Date.now();
    const poll = window.setInterval(() => {
      if (typeof window.renderChatsApp === 'function') finish();
      else if (Date.now() - started >= timeout) finish(new AppError('EasyBusy plugin loading timed out.', { code: 'PLUGIN_TIMEOUT', retryable: true }));
    }, 100);
    function finish(error) {
      if (settled) return;
      settled = true;
      window.clearInterval(poll);
      if (error) reject(error); else resolve();
    }
    script.src = url;
    script.async = true;
    script.dataset.easybusyLoader = '1';
    script.onerror = () => finish(new AppError('Failed to load EasyBusy plugin.', { code: 'PLUGIN_LOAD_FAILED', retryable: true }));
    document.head.append(script);
  }).catch((error) => {
    loaderPromise = null;
    throw error;
  });
  return loaderPromise;
}
