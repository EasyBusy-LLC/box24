function stringValue(...values) {
  for (const value of values) {
    const text = String(value || '').trim();
    if (text) return text;
  }
  return '';
}

export function normalizeChatPayload(rawPayload, easyBusyCompanyId) {
  if (!rawPayload || typeof rawPayload !== 'object') return null;
  const raw = rawPayload;
  const chat = raw.chat && typeof raw.chat === 'object' ? raw.chat : {};
  const contact = raw.contact && typeof raw.contact === 'object' ? raw.contact : {};
  const chatId = stringValue(raw.chatId, raw.chat_id, chat.id, chat.chatId);
  if (!chatId) return null;

  return {
    easyBusyCompanyId: stringValue(easyBusyCompanyId, raw.easyBusyCompanyId, raw.companyId),
    chatId,
    accountId: stringValue(raw.accountId, raw.account_id, chat.accountId, chat.account_id),
    provider: stringValue(raw.provider, chat.provider).toLowerCase(),
    externalContactId: stringValue(raw.externalContactId, raw.contact_id, contact.id, chat.contactId),
    title: stringValue(raw.chatTitle, raw.chat_title, chat.title, raw.name, contact.displayName),
    name: stringValue(raw.name, contact.firstName, contact.displayName, contact.userName, chat.title),
    phone: stringValue(raw.phone, raw.phoneNumber, contact.phone, contact.phoneNumber),
    email: stringValue(raw.email, raw.emailAddress, contact.email),
    avatarUrl: stringValue(raw.avatarUrl, raw.avatar, chat.avatarUrl, contact.avatarUrl),
    lastMessageAt: stringValue(raw.lastMessageAt, raw.last_message_at, chat.lastMessageAt, chat.last_message_at) || null,
  };
}

export function normalizeDeepLink(raw, companyId) {
  const input = raw && typeof raw === 'object' ? raw : {};
  return {
    easyBusyCompanyId: stringValue(input.easyBusyCompanyId, companyId),
    chatId: stringValue(input.chatId, input.selectedChatId),
    accountId: stringValue(input.accountId, input.selectedAccountId),
    provider: stringValue(input.provider, input.selectedProvider).toLowerCase(),
    externalContactId: stringValue(input.externalContactId, input.easyBusyExternalContactId),
  };
}
