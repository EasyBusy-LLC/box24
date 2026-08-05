import { normalizeChatPayload } from './chat-payload.js';
import { buildCrmActions } from './crm-toolbar.js';
import { toAppError } from './errors.js';

export function createCrmController({ bridge, store, ui, t, companyId }) {
  const phoneSearches = new Map();
  const phoneResults = new Map();
  const recentAutoSearches = new Map();
  const autoSearchDeduplicationMs = 2_000;

  function chatKey(chat) {
    return `${chat?.easyBusyCompanyId || ''}:${chat?.accountId || ''}:${chat?.provider || ''}:${chat?.chatId || ''}`;
  }

  function isCurrentChat(chat) {
    return chatKey(store.getState().chat) === chatKey(chat);
  }

  async function complete(payload, chat) {
    if (typeof payload?.onComplete === 'function') await payload.onComplete(actions(chat));
  }

  async function load(rawPayload) {
    const chat = normalizeChatPayload(rawPayload, companyId);
    if (!chat || !bridge) return { chat, context: null, actions: [] };
    const revision = store.dispatch({ type: 'chat/loading', chat }).revision;
    try {
      const context = await bridge.request('crm:get-context', { chat });
      if (store.getState().revision === revision) store.dispatch({ type: 'chat/loaded', chat, context });
      if (store.getState().revision !== revision) return { chat, context, actions: actions(chat, context) };
      let resolvedContext = context;
      if (shouldAutoSearch(chat, context)) {
        const result = await resolveContactByPhone(chat, { interactive: false, automatic: true });
        if (result?.context) resolvedContext = result.context;
      }
      return { chat, context: resolvedContext, actions: actions(chat, resolvedContext) };
    } catch (error) {
      store.dispatch({ type: 'chat/error', error: toAppError(error) });
      throw error;
    }
  }

  function setContext(chat, context) {
    if (isCurrentChat(chat)) store.dispatch({ type: 'chat/loaded', chat, context });
    if (context?.hasContactMapping) phoneResults.delete(chatKey(chat));
    return context;
  }

  async function mutate(command, payload, chat) {
    store.dispatch({ type: 'chat/mutating' });
    try {
      const result = await bridge.request(command, payload);
      if (result.context) setContext(chat, result.context);
      return result;
    } catch (error) {
      store.dispatch({ type: 'chat/error', error: toAppError(error) });
      throw error;
    }
  }

  async function createContact(chat) {
    const result = await ui.form({
      title: t('createContact'),
      fields: [
        { name: 'name', label: t('name'), value: chat.name || chat.title, required: true },
        { name: 'phone', label: t('phone'), value: chat.phone, type: 'tel', maxLength: 64 },
        { name: 'email', label: t('email'), value: chat.email, type: 'email' },
      ],
      submit: (contact) => mutate('crm:create-contact', { chat, contact }, chat),
    });
    if (!result) return;
    if (result.status === 'duplicate' && result.candidates?.length) {
      const candidate = await ui.picker({
        title: t('linkContact'), initialItems: result.candidates, search: async () => result.candidates,
        label: (item) => item.name, detail: (item) => [...(item.phones || []), ...(item.emails || [])].join(' · '),
      });
      if (candidate) await mutate('crm:link-contact', { chat, contactId: Number(candidate.id) }, chat);
    }
  }

  async function linkContact(chat) {
    const contact = await ui.picker({
      title: t('searchContacts'),
      search: async (query) => (await bridge.request('crm:search-contacts', { query, limit: 20 })).items || [],
      label: (item) => item.name,
      detail: (item) => [...(item.phones || []), ...(item.emails || [])].join(' · '),
    });
    if (!contact) return;
    try {
      await mutate('crm:link-contact', { chat, contactId: Number(contact.id) }, chat);
    } catch (error) {
      if (error?.code !== 'MAPPING_CONFLICT' || !window.confirm(t('confirmUnlinkContact'))) throw error;
      await mutate('crm:link-contact', { chat, contactId: Number(contact.id), replace: true }, chat);
    }
  }

  async function createDeal(chat) {
    await ui.form({
      title: t('createDeal'), fields: [{ name: 'title', label: t('dealTitle'), required: true }],
      submit: ({ title }) => mutate('crm:create-deal', { chat, title }, chat),
    });
  }

  function shouldAutoSearch(chat, context) {
    if (!context?.features?.contacts || context.hasContactMapping || context.contact) return false;
    if (!context.permissions?.contact?.read) return false;
    const key = chatKey(chat);
    const lastSearch = recentAutoSearches.get(key) || 0;
    if (phoneSearches.has(key) || Date.now() - lastSearch < autoSearchDeduplicationMs) return false;
    recentAutoSearches.set(key, Date.now());
    return true;
  }

  function phoneStatusMessage(status, count = 0) {
    if (status === 'linked') return t('phoneContactLinked');
    if (status === 'multiple') return `${t('phoneMultipleFound')} (${count})`;
    if (status === 'not_found') return t('phoneContactNotFound');
    if (status === 'phone_missing') return t('phoneMissing');
    if (status === 'phone_invalid') return t('phoneInvalid');
    return '';
  }

  async function selectPhoneCandidate(chat, candidates) {
    const candidate = await ui.picker({
      title: t('phoneMatches'),
      initialItems: candidates,
      search: async () => candidates,
      label: (item) => item.name,
      detail: (item) => (item.phones || []).join(' · '),
    });
    if (!candidate) return null;
    const result = await bridge.request('crm:select-contact-by-phone', {
      chat,
      contactId: Number(candidate.id),
    });
    if (result.context) setContext(chat, result.context);
    phoneResults.delete(chatKey(chat));
    ui.notify(t('phoneContactLinked'), 'success');
    return result;
  }

  async function resolveContactByPhone(chat, { interactive = false, automatic = false } = {}) {
    const key = chatKey(chat);
    if (phoneSearches.has(key)) return phoneSearches.get(key);

    const request = (async () => {
      try {
        const result = await bridge.request('crm:resolve-contact-by-phone', { chat });
        if (result.context) setContext(chat, result.context);
        if (result.status === 'multiple') {
          const candidates = Array.isArray(result.candidates) ? result.candidates : [];
          phoneResults.set(key, { status: 'multiple', candidates });
          if (interactive) return (await selectPhoneCandidate(chat, candidates)) || result;
        } else if (result.status !== 'not_found' && result.status !== 'phone_missing' && result.status !== 'phone_invalid') {
          phoneResults.delete(key);
        } else {
          phoneResults.set(key, { status: result.status, candidates: [] });
        }

        const message = phoneStatusMessage(result.status, result.candidates?.length || 0);
        if (message && (interactive || automatic)) {
          ui.notify(message, result.status === 'linked' ? 'success' : 'info');
        }
        return result;
      } catch (error) {
        const code = String(error?.code || '');
        const message = code === 'CONTACT_ACCESS_DENIED'
          ? t('phonePermissionDenied')
          : code === 'PHONE_SEARCH_IN_PROGRESS'
            ? t('phoneSearchInProgress')
            : error?.message || t('phoneSearchFailed');
        ui.notify(message, code === 'PHONE_SEARCH_IN_PROGRESS' ? 'info' : 'error');
        return { status: 'error', error };
      } finally {
        phoneSearches.delete(key);
      }
    })();
    phoneSearches.set(key, request);
    return request;
  }

  function actions(chat, providedContext) {
    const context = providedContext || store.getState().context;
    const phoneState = phoneResults.get(chatKey(chat)) || null;
    const wrap = (handler) => async (widgetPayload) => {
      try { return await handler(widgetPayload); }
      catch (error) { ui.notify(error?.message || t('operationFailed')); throw error; }
      finally { await complete(widgetPayload, chat); }
    };
    return buildCrmActions({ context, phoneState, t, handlers: {
      createContact: wrap(() => createContact(chat)),
      linkContact: wrap(() => linkContact(chat)),
      findByPhone: wrap(() => resolveContactByPhone(chat, { interactive: true })),
      choosePhoneMatch: wrap(() => selectPhoneCandidate(chat, phoneState?.candidates || [])),
      viewContact: wrap(() => bridge.request('crm:open-contact', { contactId: Number(context.contact.id) })),
      unlinkContact: wrap(async () => {
        if (!window.confirm(t('confirmUnlinkContact'))) return;
        await mutate('crm:unlink-contact', { chat }, chat);
      }),
      createDeal: wrap(() => createDeal(chat)),
      manageDeals: wrap(() => {
        ui.dealManager({
          context: () => store.getState().context || context,
          search: async (query) => (await bridge.request('crm:search-deals', { query, contactId: Number(context.contact.id), limit: 20 })).items || [],
          openDeal: (deal) => bridge.request('crm:open-deal', { dealId: Number(deal.id) }),
          linkDeal: async (deal) => { await mutate('crm:link-deal', { chat, dealId: Number(deal.id) }, chat); },
          unlinkDeal: async (deal) => { await mutate('crm:unlink-deal', { chat, dealId: Number(deal.id) }, chat); },
        });
      }),
    } });
  }

  return { load, actions };
}
