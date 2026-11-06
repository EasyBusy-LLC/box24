(function () {
  'use strict';

  const config = window.EasyBusyCrmCardConfig;
  if (!config || !Number.isSafeInteger(Number(config.entityId))) return;

  const labels = config.labels || {};
  const PROVIDER_LABELS = Object.freeze({
    telegrambot: 'Telegram Bot',
    instagram: 'Instagram',
    telegram: 'Telegram',
    facebook: 'Facebook',
    viberbot: 'Viber Bot',
    whatsapp: 'WhatsApp',
    webchat: 'WebChat',
    echatviber: 'eChat Viber',
    whatsapppersonal: 'WhatsApp Personal',
    viberbusiness: 'Viber Business',
    viberpersonal: 'Viber Personal',
  });
  let mounted = false;

  function create(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  }

  function action(name, data) {
    if (!window.BX?.ajax?.runAction) return Promise.reject(new Error('Bitrix AJAX is unavailable.'));
    return window.BX.ajax.runAction(`${config.actionPrefix}${name}`, { data }).then((response) => response?.data || {});
  }

  function chatPayload(item) {
    return {
      easyBusyCompanyId: String(item.easyBusyCompanyId || ''),
      chatId: String(item.chatId || ''),
      accountId: String(item.accountId || ''),
      provider: String(item.provider || ''),
      externalContactId: String(item.externalContactId || ''),
      title: String(item.title || ''),
    };
  }

  function openChat(item) {
    const query = new URLSearchParams();
    const chat = chatPayload(item);
    Object.entries(chat).forEach(([key, value]) => {
      if (value) query.set(key, value);
    });
    const url = `/easybusychats/?${query.toString()}`;
    if (window.BX?.SidePanel?.Instance) window.BX.SidePanel.Instance.open(url, { cacheable: false });
    else window.location.assign(url);
  }

  function conversationTab() {
    const tabs = Object.values(window.BX?.Crm?.EntityDetailManager?.items || {})[0]?.getTabManager?.();
    const tab = tabs?.findItemById('easybusychats');
    return tab && document.getElementById('easybusy-host')?.dataset.iframeUrl ? { tabs, tab } : null;
  }

  // Shows the EasyBusy Chats tab with the create-contact dialog prefilled from this record.
  function openNewChat() {
    const target = conversationTab();
    if (!target || !window.EasyBusyHostBridge?.requestNewChat) return;
    window.EasyBusyHostBridge.requestNewChat();
    target.tabs.selectItem(target.tab);
    // A tab opened before is reloaded so the dialog opens; otherwise it loads when shown.
    const frame = document.getElementById('easybusy-host-frame');
    if (frame) frame.src = frame.src;
  }

  function renderItems(body, items) {
    body.replaceChildren();
    if (!Array.isArray(items) || items.length === 0) {
      body.append(create('div', 'eb-crm-card__empty', labels.empty || 'No linked chats.'));
      return;
    }

    const list = create('div', 'eb-crm-card__list');
    items.forEach((item) => {
      const row = create('div', 'eb-crm-card__row');
      const info = create('div', 'eb-crm-card__info');
      info.append(create('strong', 'eb-crm-card__name', item.title || labels.unknownChat || 'EasyBusy chat'));
      if (item.provider) info.append(create('span', 'eb-crm-card__meta', PROVIDER_LABELS[item.provider] || item.provider));
      if (item.linkBroken) info.append(create('span', 'eb-crm-card__warning', labels.linkBroken || 'This link is broken.'));

      const controls = create('div', 'eb-crm-card__controls');
      if (!item.linkBroken) {
        const open = create('button', 'ui-btn ui-btn-xs ui-btn-primary', labels.open || 'Open chat');
        open.type = 'button';
        open.addEventListener('click', () => openChat(item));
        controls.append(open);
      }

      if (item.canUnlink) {
        const unlink = create('button', 'ui-btn ui-btn-xs ui-btn-light-border', labels.unlink || 'Unlink');
        unlink.type = 'button';
        unlink.addEventListener('click', async () => {
          if (!window.confirm(labels.confirmUnlink || 'Unlink this chat?')) return;
          unlink.disabled = true;
          try {
            const data = { chat: chatPayload(item) };
            if (config.entityType === 'deal') data.dealId = Number(config.entityId);
            await action(config.entityType === 'deal' ? 'unlinkDeal' : 'unlinkContact', data);
            row.remove();
            reloadConversation();
            if (!list.children.length) renderItems(body, []);
          } catch (_) {
            unlink.disabled = false;
            window.BX?.UI?.Notification?.Center?.notify({ content: labels.error || 'Operation failed.' });
          }
        });
        controls.append(unlink);
      }

      row.append(info, controls);
      list.append(row);
    });
    body.append(list);
  }

  let loadSequence = 0;

  async function load(body) {
    // Only the latest request renders, so overlapping refreshes cannot show an older list.
    const sequence = ++loadSequence;
    try {
      const data = config.entityType === 'deal'
        ? await action('getDealChats', { dealId: Number(config.entityId) })
        : await action('getContactChats', { contactId: Number(config.entityId) });
      if (sequence === loadSequence) renderItems(body, data.items || []);
    } catch (_) {
      if (sequence === loadSequence) {
        body.replaceChildren(create('div', 'eb-crm-card__error', labels.error || 'Could not load EasyBusy Chats.'));
      }
    }
  }

  function findMountPoint() {
    return document.querySelector('.crm-entity-card-container')
      || document.querySelector('.crm-entity-card-widget-list')
      || document.querySelector('[data-cid="crm-entity-card"]')
      || document.querySelector('#workarea-content');
  }

  function mount() {
    if (mounted || document.querySelector('[data-easybusy-crm-card]')) return true;
    const target = findMountPoint();
    if (!target) return false;
    mounted = true;

    const card = create('section', 'eb-crm-card');
    card.dataset.easybusyCrmCard = '1';
    const header = create('div', 'eb-crm-card__header');
    header.append(create('span', 'eb-crm-card__title', labels.title || 'EasyBusy Chats'));
    // Without the conversation tab (EasyBusy not configured) there is nowhere to open the dialog.
    if (document.getElementById('easybusy-host')?.dataset.iframeUrl) {
      const newChat = create('button', 'ui-btn ui-btn-xs ui-btn-success', labels.newChat || 'New chat');
      newChat.type = 'button';
      newChat.addEventListener('click', openNewChat);
      header.append(newChat);
    }
    const body = create('div', 'eb-crm-card__body', labels.loading || 'Loading…');
    card.append(header, body);
    target.append(card);
    load(body);
    // Links changed inside the EasyBusy Chats tab (host-bridge.js) would otherwise show only after a page reload.
    window.addEventListener('easybusychats:links-changed', () => load(body));
    return true;
  }

  function reloadConversation() {
    const frame = document.getElementById('easybusy-host-frame');
    if (frame) frame.src = frame.src;
  }

  function openConversation(root) {
    const frame = document.createElement('iframe');
    frame.id = 'easybusy-host-frame';
    frame.className = 'eb-crm-conversation__frame';
    frame.src = root.dataset.iframeUrl;
    frame.title = labels.title || 'EasyBusy Chats';
    frame.allow = 'clipboard-write; microphone';
    root.append(frame);
    window.EasyBusyHostBridge?.init();
  }

  function watchConversationTab() {
    const root = document.getElementById('easybusy-host');
    if (!root?.dataset.iframeUrl) return;
    // The tab stays display:none until selected; loading on first view avoids a token exchange per card.
    const observer = new IntersectionObserver((entries) => {
      if (!entries.some((entry) => entry.isIntersecting)) return;
      observer.disconnect();
      openConversation(root);
    });
    observer.observe(root);
  }

  function start() {
    watchConversationTab();
    if (mount()) return;
    const observer = new MutationObserver(() => {
      if (mount()) observer.disconnect();
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
    window.setTimeout(() => observer.disconnect(), 15000);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
