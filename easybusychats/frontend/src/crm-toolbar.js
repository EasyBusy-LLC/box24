function icon(path) {
  return `data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${path}</svg>`)}`;
}

const icons = {
  contact: icon('<circle cx="12" cy="7" r="4"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/>'),
  link: icon('<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-2 2"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l2-2"/>'),
  deal: icon('<rect x="2" y="6" width="20" height="14" rx="2"/><path d="M8 6V4h8v2M12 11v4"/>'),
  unlink: icon('<path d="M6 6l12 12M18 6L6 18"/>'),
  phoneSearch: icon('<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.69 2.8a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.33 1.85.56 2.81.69A2 2 0 0 1 22 16.92z"/><circle cx="17" cy="7" r="3"/><path d="m19.2 9.2 2.3 2.3"/>'),
};

function descriptor(id, label, iconValue, callback) {
  return { id, label, description: label, icon: iconValue, callback };
}

export function buildCrmActions({ context, phoneState = null, t, handlers }) {
  if (!context || !context.features?.contacts) return [];
  const contactPermission = context.permissions?.contact || {};
  const dealPermission = context.permissions?.deal || {};
  if (context.hasContactMapping && !context.contact) return [];

  if (!context.contact) {
    const actions = [];
    if (contactPermission.read && phoneState?.status === 'multiple' && phoneState.candidates?.length) {
      actions.push(descriptor(
        'phone_candidates',
        `${t('phoneMatches')} (${phoneState.candidates.length})`,
        icons.phoneSearch,
        handlers.choosePhoneMatch,
      ));
    }
    if (contactPermission.read) actions.push(descriptor('find_by_phone', t('findByPhone'), icons.phoneSearch, handlers.findByPhone));
    if (contactPermission.add) actions.push(descriptor('create_contact', t('createContact'), icons.contact, handlers.createContact));
    if (contactPermission.read) actions.push(descriptor('link_contact', t('linkContact'), icons.link, handlers.linkContact));
    return actions;
  }

  const actions = [descriptor('view_contact', t('viewContact'), icons.contact, handlers.viewContact)];
  if (contactPermission.update) actions.push(descriptor('unlink_contact', t('unlinkContact'), icons.unlink, handlers.unlinkContact));
  if (context.features?.deals && dealPermission.read) actions.push(descriptor('deals', `${t('deals')} (${context.deals?.length || 0})`, icons.deal, handlers.manageDeals));
  if (context.features?.deals && dealPermission.add) actions.push(descriptor('create_deal', t('createDeal'), icons.deal, handlers.createDeal));
  return actions.filter((action) => typeof action.callback === 'function');
}
