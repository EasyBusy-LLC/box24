(function () {
  'use strict';

  document.documentElement.dataset.easybusyHostScript = 'loaded';

  const SOURCE_HOST = 'easybusy-bitrix24-host';
  const SOURCE_PLUGIN = 'easybusy-bitrix24-plugin';
  const VERSION = 1;
  const MAX_MESSAGE_AGE = 5 * 60 * 1000;
  // Set by "New chat" in a CRM card; the next iframe bootstrap carries the dialog prefill.
  let newChatRequested = false;
  const OPEN_COMMANDS = new Set(['crm:open-contact', 'crm:open-deal', 'ui:open-full-chat']);
  // Commands that can add or remove chat links; the CRM card refreshes its chat list after them.
  const LINK_COMMANDS = new Set([
    'crm:create-contact', 'crm:link-contact', 'crm:unlink-contact',
    'crm:resolve-contact-by-phone', 'crm:select-contact-by-phone',
    'crm:create-deal', 'crm:link-deal', 'crm:unlink-deal', 'crm:link-new-chat',
  ]);
  const LINKS_CHANGED_EVENT = 'easybusychats:links-changed';
  const ACTIONS = Object.freeze({
    'crm:get-context': 'getChatContext',
    'crm:search-contacts': 'searchContacts',
    'crm:create-contact': 'createContact',
    'crm:link-contact': 'linkContact',
    'crm:unlink-contact': 'unlinkContact',
    'crm:resolve-contact-by-phone': 'resolveContactByPhone',
    'crm:select-contact-by-phone': 'selectContactByPhone',
    'crm:search-deals': 'searchDeals',
    'crm:create-deal': 'createDeal',
    'crm:link-deal': 'linkDeal',
    'crm:unlink-deal': 'unlinkDeal',
    'crm:get-contact-chats': 'getContactChats',
    'crm:get-deal-chats': 'getDealChats',
    'auth:refresh': 'getRuntimeBootstrap',
  });

  function safeObject(value) {
    return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  }

  function positiveId(value) {
    const id = Number(value);
    return Number.isSafeInteger(id) && id > 0 ? id : 0;
  }

  function parseConfiguration(root) {
    try {
      const config = JSON.parse(root.dataset.bootstrap || '{}');
      if (!config.pluginOrigin || !config.hostOrigin || !config.sessionNonce) return null;
      return config;
    } catch (_) {
      return null;
    }
  }

  function normalizeError(error) {
    const errors = Array.isArray(error?.errors) ? error.errors : [];
    const first = errors[0] || error || {};
    return {
      code: String(first.code || first.error || 'HOST_REQUEST_FAILED'),
      message: String(first.message || 'The Bitrix24 operation could not be completed.'),
      retryable: Boolean(first.customData?.retryable || first.retryable),
    };
  }

  function withTimeout(promise, timeout) {
    return new Promise((resolve, reject) => {
      const timer = window.setTimeout(() => reject({ code: 'HOST_TIMEOUT', message: 'Bitrix24 did not respond in time.', retryable: true }), timeout);
      promise.then(
        (value) => { window.clearTimeout(timer); resolve(value); },
        (error) => { window.clearTimeout(timer); reject(error); },
      );
    });
  }

  function init() {
    const root = document.getElementById('easybusy-host');
    if (!root) return;

    root.dataset.bridgeState = 'initializing';

    const config = parseConfiguration(root);
    const status = root.querySelector('[data-easybusy-host-status]');
    if (!config) {
      root.dataset.bridgeState = 'invalid-config';
      if (status) {
        status.textContent = 'EasyBusy Chats host configuration is invalid.';
        status.classList.add('easybusy-chats__status--error');
      }
      return;
    }

    const timeout = Math.max(3000, Math.min(60000, Number(config.requestTimeout) || 15000));
    let runtimeBootstrapPromise = null;

    async function runBitrixAction(action, payload = {}) {
      if (!window.BX?.ajax?.runAction) {
        throw { code: 'BITRIX_AJAX_UNAVAILABLE', message: 'Bitrix24 AJAX is unavailable.', retryable: true };
      }
      const response = await withTimeout(window.BX.ajax.runAction(`${config.actionPrefix}${action}`, {
        data: payload,
      }), timeout);
      return response?.data || {};
    }

    async function newChatPreset(conversation) {
      const source = safeObject(conversation);
      const payload = positiveId(source.dealId) ? { dealId: positiveId(source.dealId) } : { contactId: positiveId(source.contactId) };
      try {
        return { type: 'auto', ...safeObject((await runBitrixAction('getNewChatPreset', payload)).preset) };
      } catch (_) {
        // The dialog still opens, empty, if the record cannot be read.
        return { type: 'auto' };
      }
    }

    const frame = document.getElementById('easybusy-host-frame');
    if (!frame) {
      root.dataset.bridgeState = 'token-setup';
      return;
    }

    const processed = new Map();

    function pruneProcessed() {
      const threshold = Date.now() - MAX_MESSAGE_AGE;
      processed.forEach((timestamp, id) => {
        if (timestamp < threshold) processed.delete(id);
      });
    }

    function send(type, id, payload, error) {
      frame.contentWindow.postMessage({
        source: SOURCE_HOST,
        version: VERSION,
        sessionNonce: config.sessionNonce,
        id,
        type,
        success: !error,
        payload: payload || {},
        error: error || null,
        timestamp: Date.now(),
      }, config.pluginOrigin);
    }

    function openSlider(url) {
      if (!url || !/^\/(crm\/(contact|deal)\/details\/\d+\/|easybusychats\/)/.test(url)) {
        throw { code: 'INVALID_URL', message: 'The requested Bitrix24 URL is invalid.', retryable: false };
      }
      if (!window.BX?.SidePanel?.Instance) {
        window.location.assign(url);
        return;
      }
      window.BX.SidePanel.Instance.open(url, { cacheable: false });
    }

    function showOpenEntity(key, id) {
      if (positiveId(safeObject(config.conversation)[key]) !== id) return false;
      const tabs = Object.values(window.BX?.Crm?.EntityDetailManager?.items || {})[0]?.getTabManager();
      const mainTab = tabs?.findItemById('main');
      if (!mainTab) return false;
      tabs.selectItem(mainTab);
      // The reload closes any dialog left open in the iframe; deferred so the command result is sent first.
      window.setTimeout(() => { frame.src = frame.src; }, 0);
      return true;
    }

    function handleOpen(type, payload) {
      if (type === 'crm:open-contact') {
        const id = positiveId(payload.contactId);
        if (!id) throw { code: 'INVALID_CRM_ID', message: 'Invalid contact ID.', retryable: false };
        if (!showOpenEntity('contactId', id)) openSlider(`/crm/contact/details/${id}/`);
      } else if (type === 'crm:open-deal') {
        const id = positiveId(payload.dealId);
        if (!id) throw { code: 'INVALID_CRM_ID', message: 'Invalid deal ID.', retryable: false };
        if (!showOpenEntity('dealId', id)) openSlider(`/crm/deal/details/${id}/`);
      } else {
        const query = new URLSearchParams();
        const chat = safeObject(payload.chat);
        ['easyBusyCompanyId', 'chatId', 'accountId', 'provider', 'externalContactId'].forEach((key) => {
          const value = String(chat[key] || '').trim();
          if (/^[A-Za-z0-9._:@\-]{1,191}$/.test(value)) query.set(key, value);
        });
        openSlider(`/easybusychats/${query.toString() ? `?${query.toString()}` : ''}`);
      }
      return { opened: true };
    }

    async function runAction(type, payload) {
      if (OPEN_COMMANDS.has(type)) return handleOpen(type, payload);
      if (type === 'crm:link-new-chat') {
        // The record comes from this card, never from the iframe.
        const conversation = safeObject(config.conversation);
        const record = positiveId(conversation.dealId)
          ? { dealId: positiveId(conversation.dealId) }
          : { contactId: positiveId(conversation.contactId) };
        if (!record.dealId && !record.contactId) {
          throw { code: 'UNKNOWN_COMMAND', message: 'Unsupported Bitrix24 command.', retryable: false };
        }
        return runBitrixAction('linkNewChat', { chat: safeObject(payload.chat), replace: payload.replace === true, ...record });
      }
      const action = ACTIONS[type];
      if (!action) throw { code: 'UNKNOWN_COMMAND', message: 'Unsupported Bitrix24 command.', retryable: false };
      return runBitrixAction(action, payload);
    }

    window.addEventListener('message', async (event) => {
      root.dataset.bridgeMessages = String((Number(root.dataset.bridgeMessages) || 0) + 1);
      if (event.source !== frame.contentWindow) {
        root.dataset.bridgeLastReject = 'window';
        return;
      }
      if (event.origin !== config.pluginOrigin) {
        root.dataset.bridgeLastReject = 'origin';
        return;
      }
      const message = safeObject(event.data);
      if (message.source !== SOURCE_PLUGIN || Number(message.version) !== VERSION) {
        root.dataset.bridgeLastReject = 'protocol';
        return;
      }
      if (typeof message.id !== 'string' || !/^[A-Za-z0-9._:-]{8,128}$/.test(message.id)) {
        root.dataset.bridgeLastReject = 'id';
        return;
      }
      if (!Number.isFinite(message.timestamp) || Math.abs(Date.now() - message.timestamp) > MAX_MESSAGE_AGE) {
        root.dataset.bridgeLastReject = 'timestamp';
        return;
      }
      if (message.type !== 'host:ready' && message.sessionNonce !== config.sessionNonce) {
        root.dataset.bridgeLastReject = 'nonce';
        return;
      }
      pruneProcessed();
      if (processed.has(message.id)) return;
      processed.set(message.id, Date.now());
      root.dataset.bridgeLastReject = '';

      if (message.type === 'host:ready') {
        if (status) status.hidden = true;
        try {
          // Shared only while in flight: host:ready repeats during the handshake, and a reloaded iframe needs a new token.
          // The "New chat" prefill joins the same promise, so a retried host:ready still gets it.
          if (!runtimeBootstrapPromise) {
            const withNewChat = newChatRequested;
            newChatRequested = false;
            runtimeBootstrapPromise = Promise.all([
              runBitrixAction('getRuntimeBootstrap'),
              withNewChat ? newChatPreset(config.conversation) : null,
            ]).then(([runtime, newChat]) => (newChat ? { ...runtime, newChat } : runtime)).finally(() => {
              runtimeBootstrapPromise = null;
            });
          }
          const runtime = await runtimeBootstrapPromise;
          root.dataset.bridgeState = 'connected';
          send('host:bootstrap', message.id, { ...config, ...runtime }, null);
        } catch (error) {
          root.dataset.bridgeState = 'runtime-error';
          const normalized = normalizeError(error);
          if (status) {
            status.hidden = false;
            status.textContent = normalized.message;
            status.classList.add('easybusy-chats__status--error');
          }
          send('host:bootstrap', message.id, {}, normalized);
        }
        return;
      }

      try {
        const payload = await runAction(String(message.type || ''), safeObject(message.payload));
        send(`${message.type}:result`, message.id, payload, null);
        if (LINK_COMMANDS.has(message.type)) window.dispatchEvent(new CustomEvent(LINKS_CHANGED_EVENT));
      } catch (error) {
        send(`${message.type}:result`, message.id, {}, normalizeError(error));
      }
    });
    root.dataset.bridgeState = 'listening';
  }

  window.EasyBusyHostBridge = Object.freeze({
    init,
    requestNewChat: () => { newChatRequested = true; },
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
