import { AppError } from './errors.js';
import { normalizeEasyBusyChatIdentity } from './easybusy-api-client.js';
import { loadPlugin } from './plugin-loader.js';

export async function resolveCompany(config) {
  return { id: config.companyId, name: config.companyName };
}

export async function renderChats({ config, company, language, callbacks, deepLink }) {
  const selection = normalizeEasyBusyChatIdentity(deepLink);
  if (selection.chatId && (!selection.accountId || !selection.provider)) {
    throw new AppError('The CRM chat link is incomplete.', { code: 'EASYBUSY_DEEP_LINK_INVALID' });
  }
  await loadPlugin(config.pluginLoaderUrl);
  if (typeof window.renderChatsApp !== 'function') throw new AppError('EasyBusy renderer is unavailable.', { code: 'PLUGIN_RENDER_UNAVAILABLE', retryable: true });
  window.renderChatsApp('chat_container', {
    companyId: company.id,
    accessToken: config.accessToken,
    lang: language,
    callbacks,
    disableBrowserNotifications: true,
    selectedChatId: selection.chatId || undefined,
    selectedAccountId: selection.accountId || undefined,
    selectedProvider: selection.provider || undefined,
  });
}
