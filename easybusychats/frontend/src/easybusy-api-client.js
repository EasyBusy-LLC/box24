import { AppError } from './errors.js';

const IDENTIFIER = /^[A-Za-z0-9._:@-]{1,191}$/;
const INVALID_IDENTIFIER = /^(?:undefined|null|false|0)?$/i;
const XHR_META = Symbol('easybusyRequestMeta');

function requiredIdentifier(value, name) {
  const result = String(value || '').trim();
  if (!IDENTIFIER.test(result) || INVALID_IDENTIFIER.test(result)) {
    throw new AppError(`${name} is required.`, { code: 'EASYBUSY_CHAT_IDENTITY_INVALID' });
  }
  return result;
}

function normalizeApiBase(value) {
  const raw = String(value || '').trim().replace(/\/+$/, '');
  let url;
  try {
    url = new URL(raw);
  } catch {
    throw new AppError('EasyBusy API URL is invalid.', { code: 'EASYBUSY_API_CONFIG_INVALID' });
  }
  if (url.protocol !== 'https:' || url.search || url.hash) {
    throw new AppError('EasyBusy API URL is invalid.', { code: 'EASYBUSY_API_CONFIG_INVALID' });
  }
  return url;
}

export function normalizeEasyBusyChatIdentity(raw = {}) {
  return Object.freeze({
    companyId: String(raw.easyBusyCompanyId || raw.companyId || '').trim(),
    provider: String(raw.provider || '').trim().toLowerCase(),
    accountId: String(raw.accountId || raw.easyBusyAccountId || '').trim(),
    chatId: String(raw.chatId || raw.easyBusyChatId || '').trim(),
    externalContactId: String(raw.externalContactId || raw.easyBusyExternalContactId || '').trim(),
  });
}

export function buildEasyBusyProviderUrl(apiBaseUrl, identity) {
  const base = normalizeApiBase(apiBaseUrl);
  const normalized = normalizeEasyBusyChatIdentity(identity);
  const provider = requiredIdentifier(normalized.provider, 'EasyBusy provider');
  const companyId = requiredIdentifier(normalized.companyId, 'EasyBusy company ID');
  const accountId = requiredIdentifier(normalized.accountId, 'EasyBusy account ID');
  return `${base.href.replace(/\/$/, '')}/${encodeURIComponent(provider)}/${encodeURIComponent(companyId)}/account/${encodeURIComponent(accountId)}`;
}

export function buildEasyBusyMessagesUrl(apiBaseUrl, identity, query = {}) {
  const normalized = normalizeEasyBusyChatIdentity(identity);
  const chatId = requiredIdentifier(normalized.chatId, 'EasyBusy chat ID');
  const url = new URL(`${buildEasyBusyProviderUrl(apiBaseUrl, normalized)}/chat/${encodeURIComponent(chatId)}/messages`);
  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, String(value));
  });
  return url.href;
}

function sameIdentifier(left, right) {
  return decodeURIComponent(String(left || '')).toLowerCase() === String(right || '').toLowerCase();
}

export function rewriteLegacyEasyBusyUrl(rawUrl, apiBaseUrl, identity) {
  const normalized = normalizeEasyBusyChatIdentity(identity);
  if (!normalized.companyId || !normalized.provider || !normalized.accountId) {
    return { url: String(rawUrl), changed: false, resource: '' };
  }

  const base = normalizeApiBase(apiBaseUrl);
  let url;
  try {
    url = new URL(String(rawUrl), base.origin);
  } catch {
    return { url: String(rawUrl), changed: false, resource: '' };
  }
  const baseSegments = base.pathname.split('/').filter(Boolean);
  const segments = url.pathname.split('/').filter(Boolean);
  if (url.origin !== base.origin || baseSegments.some((segment, index) => segments[index] !== segment)) {
    return { url: url.href, changed: false, resource: '' };
  }

  const route = segments.slice(baseSegments.length);
  if (!sameIdentifier(route[0], normalized.provider) || !sameIdentifier(route[1], normalized.companyId)) {
    return { url: url.href, changed: false, resource: '' };
  }
  if (route[2] === 'account') {
    return { url: url.href, changed: false, resource: route[4] || '' };
  }

  let resource = '';
  let corrected = null;
  if (['chat', 'contact'].includes(route[2])) {
    resource = route[2];
    if (resource === 'chat' && (!route[3] || INVALID_IDENTIFIER.test(route[3]))) {
      throw new AppError('EasyBusy chat ID is required.', { code: 'EASYBUSY_CHAT_ID_MISSING' });
    }
    corrected = [route[0], route[1], 'account', normalized.accountId, ...route.slice(2)];
  } else if (route[3] === 'messages') {
    resource = 'messages';
    const chatId = route[2];
    if (!chatId || INVALID_IDENTIFIER.test(chatId)) {
      throw new AppError('EasyBusy chat ID is required.', { code: 'EASYBUSY_CHAT_ID_MISSING' });
    }
    if (normalized.chatId && !sameIdentifier(chatId, normalized.chatId)) {
      return { url: url.href, changed: false, resource };
    }
    corrected = [route[0], route[1], 'account', normalized.accountId, 'chat', chatId, ...route.slice(3)];
  }

  if (!corrected) return { url: url.href, changed: false, resource };
  url.pathname = `/${[...baseSegments, ...corrected].map((segment) => encodeURIComponent(decodeURIComponent(segment))).join('/')}`;
  return { url: url.href, changed: true, resource };
}

export function installEasyBusyApiRouteAdapter({ apiBaseUrl, identity, onHttpError } = {}) {
  normalizeApiBase(apiBaseUrl);
  let currentIdentity = normalizeEasyBusyChatIdentity(identity);
  const inFlightFetch = new Map();
  const fetchQueues = new Map();
  const inFlightXhr = new Map();
  const terminalFailures = new Set();
  const originalFetch = globalThis.fetch;
  const Xhr = globalThis.XMLHttpRequest;
  const originalOpen = Xhr?.prototype?.open;
  const originalSend = Xhr?.prototype?.send;
  const originalAbort = Xhr?.prototype?.abort;

  function transform(rawUrl) {
    const meta = rewriteLegacyEasyBusyUrl(rawUrl, apiBaseUrl, currentIdentity);
    meta.failureKey = ['chat', 'messages'].includes(meta.resource)
      ? `${currentIdentity.provider}:${currentIdentity.companyId}:${currentIdentity.accountId}:${currentIdentity.chatId}:${meta.resource}`
      : '';
    return meta;
  }

  function report(status, meta) {
    if (Number(status) === 404 && ['chat', 'messages'].includes(meta?.resource)) {
      if (meta.failureKey) terminalFailures.add(meta.failureKey);
      onHttpError?.({ status: 404, code: 'EASYBUSY_CHAT_NOT_FOUND', resource: meta.resource, chatId: currentIdentity.chatId });
    }
  }

  if (typeof originalFetch === 'function') {
    globalThis.fetch = function easyBusyFetch(input, init = {}) {
      const sourceUrl = typeof input === 'string' || input instanceof URL ? String(input) : input?.url;
      const meta = transform(sourceUrl);
      const method = String(init.method || input?.method || 'GET').toUpperCase();
      const requestInput = input instanceof Request ? new Request(meta.url, input) : meta.url;
      const key = method === 'GET' ? meta.url : '';
      const duplicate = key ? inFlightFetch.get(key) : null;
      if (duplicate) return duplicate.then((response) => response.clone());
      const execute = () => {
        if (method === 'GET' && meta.failureKey && terminalFailures.has(meta.failureKey)) {
          return Promise.resolve(new Response('{"message":"Chat not found"}', {
            status: 404,
            headers: { 'Content-Type': 'application/json' },
          }));
        }
        return originalFetch.call(this, requestInput, init).then((response) => {
          report(response.status, meta);
          return response;
        });
      };
      const previous = meta.failureKey ? (fetchQueues.get(meta.failureKey) || Promise.resolve()) : Promise.resolve();
      const request = previous.catch(() => {}).then(execute);
      if (meta.failureKey) {
        const tail = request.then(() => {}, () => {});
        fetchQueues.set(meta.failureKey, tail);
        tail.finally(() => {
          if (fetchQueues.get(meta.failureKey) === tail) fetchQueues.delete(meta.failureKey);
        });
      }
      if (key) {
        inFlightFetch.set(key, request);
        request.finally(() => inFlightFetch.delete(key));
      }
      return request;
    };
  }

  if (Xhr && originalOpen && originalSend) {
    Xhr.prototype.open = function easyBusyOpen(method, url, ...args) {
      const meta = transform(url);
      this[XHR_META] = { ...meta, method: String(method || 'GET').toUpperCase(), aborted: false };
      return originalOpen.call(this, method, meta.url, ...args);
    };
    Xhr.prototype.send = function easyBusySend(body) {
      const request = this;
      const meta = request[XHR_META] || {};
      const key = meta.method === 'GET' ? (meta.failureKey || meta.url) : '';
      let complete;
      const completion = new Promise((resolve) => { complete = resolve; });
      const execute = () => {
        if (meta.aborted) { complete(); return; }
        if (meta.method === 'GET' && meta.failureKey && terminalFailures.has(meta.failureKey)) {
          queueMicrotask(() => {
            request.dispatchEvent(new ProgressEvent('error'));
            request.dispatchEvent(new ProgressEvent('loadend'));
            complete();
          });
          return;
        }
        request.addEventListener('load', () => report(request.status, meta), { once: true });
        request.addEventListener('loadend', () => {
          complete();
        }, { once: true });
        originalSend.call(request, body);
      };
      if (!key) { execute(); return; }
      const previous = inFlightXhr.get(key) || Promise.resolve();
      const tail = previous.catch(() => {}).then(() => completion);
      inFlightXhr.set(key, tail);
      previous.catch(() => {}).then(execute);
      tail.finally(() => {
        if (inFlightXhr.get(key) === tail) inFlightXhr.delete(key);
      });
    };
    if (originalAbort) {
      Xhr.prototype.abort = function easyBusyAbort() {
        if (this[XHR_META]) this[XHR_META].aborted = true;
        return originalAbort.call(this);
      };
    }
  }

  return {
    setChatIdentity(nextIdentity) {
      currentIdentity = normalizeEasyBusyChatIdentity(nextIdentity);
    },
    getChatIdentity: () => currentIdentity,
    dispose() {
      if (typeof originalFetch === 'function') globalThis.fetch = originalFetch;
      if (Xhr && originalOpen && originalSend) {
        Xhr.prototype.open = originalOpen;
        Xhr.prototype.send = originalSend;
        if (originalAbort) Xhr.prototype.abort = originalAbort;
      }
      inFlightFetch.clear();
      fetchQueues.clear();
      inFlightXhr.clear();
      terminalFailures.clear();
    },
  };
}
