(function () {
  'use strict';

  const config = window.EasyBusyCrmCardConfig;
  if (!config || !Number.isSafeInteger(Number(config.entityId))) return;

  const labels = config.labels || {};
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
      const metadata = [item.provider, item.accountId].filter(Boolean).join(' · ');
      if (metadata) info.append(create('span', 'eb-crm-card__meta', metadata));

      const controls = create('div', 'eb-crm-card__controls');
      const open = create('button', 'ui-btn ui-btn-xs ui-btn-primary', labels.open || 'Open chat');
      open.type = 'button';
      open.addEventListener('click', () => openChat(item));
      controls.append(open);

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

  async function load(body) {
    try {
      const data = config.entityType === 'deal'
        ? await action('getDealChats', { dealId: Number(config.entityId) })
        : await action('getContactChats', { contactId: Number(config.entityId) });
      renderItems(body, data.items || []);
    } catch (_) {
      body.replaceChildren(create('div', 'eb-crm-card__error', labels.error || 'Could not load EasyBusy Chats.'));
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
    const header = create('div', 'eb-crm-card__header', labels.title || 'EasyBusy Chats');
    const body = create('div', 'eb-crm-card__body', labels.loading || 'Loading…');
    card.append(header, body);
    target.append(card);
    load(body);
    return true;
  }

  function start() {
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
