import { normalizeChatPayload, normalizeDeepLink } from './chat-payload.js';
import { getConfig, normalizeLanguage, withRuntimeConfig } from './config.js';
import { createCrmController } from './crm-controller.js';
import { createCrmUi } from './crm-ui.js';
import { installEasyBusyApiRouteAdapter } from './easybusy-api-client.js';
import { renderChats, resolveCompany } from './easybusy-client.js';
import { createHostBridge } from './host-bridge.js';
import { createI18n } from './i18n.js';
import { createStore } from './state.js';
import { installCryptoRandomUUIDFallback } from './request-id.js';
import { createWidgetStateController } from './widget-state.js';

const stateNode = document.getElementById('widget-state');
const stateText = document.getElementById('widget-state-text');
const retryButton = document.getElementById('widget-retry');
const chatContainer = document.getElementById('chat_container');
const widgetState = createWidgetStateController({ stateNode, stateText, retryButton, chatContainer });
const params = new URLSearchParams(window.location.search);
let startPromise = null;
let activeBridge = null;
let apiRouteAdapter = null;
let terminalApiError = null;
let accessTokenRefreshTimer = null;

installCryptoRandomUUIDFallback();

async function start() {
  if (startPromise) return startPromise;
  startPromise = (async () => {
    const staticConfig = getConfig();
    const requestedLanguage = normalizeLanguage(params.get('lang') || staticConfig.defaultLang);
    let language = requestedLanguage;
    let t = createI18n(language);
    widgetState.setState(t('loadingCompany'));

    const hostOrigin = params.get('hostOrigin') || '';
    activeBridge = createHostBridge({ hostOrigin, timeout: staticConfig.requestTimeout });
    if (!activeBridge) throw new Error('EasyBusy Chats must be opened from Bitrix24.');
    const bootstrap = await activeBridge.connect();
    const config = withRuntimeConfig(staticConfig, bootstrap);
    accessTokenRefreshTimer = window.setTimeout(
      () => window.location.reload(),
      Math.max(30_000, (config.expiresIn - 60) * 1000),
    );
    language = normalizeLanguage(bootstrap.language || requestedLanguage);
    t = createI18n(language);

    const company = await resolveCompany(config);
    const store = createStore();
    const ui = createCrmUi(t);
    const crm = createCrmController({ bridge: activeBridge, store, ui, t, companyId: company.id });
    const deepLink = normalizeDeepLink(bootstrap?.initialChat, company.id);
    apiRouteAdapter = installEasyBusyApiRouteAdapter({
      apiBaseUrl: config.apiBaseUrl,
      identity: deepLink,
      onHttpError: (error) => {
        if (error?.code !== 'EASYBUSY_CHAT_NOT_FOUND') return;
        terminalApiError = error;
        widgetState.setState(t('chatNotFound'), 'error');
      },
    });

    widgetState.setState(t('loadingChats'));
    await renderChats({
      config,
      company,
      language,
      deepLink,
      callbacks: {
        onOpenChat: async (payload = {}) => {
          if (!activeBridge) return [];
          try {
            const chat = normalizeChatPayload(payload, company.id);
            if (!chat?.chatId) {
              widgetState.setState(t('chatIdentityMissing'), 'error');
              return [];
            }
            terminalApiError = null;
            apiRouteAdapter?.setChatIdentity(chat);
            const result = await crm.load(chat);
            return result.actions || [];
          } catch (error) {
            ui.notify(error?.message || t('operationFailed'));
            return [];
          }
        },
      },
    });
    if (!terminalApiError) widgetState.showChats();
  })().catch((error) => {
    widgetState.setState(error?.message || 'Failed to load EasyBusy Chats.', 'error');
    throw error;
  });
  return startPromise;
}

retryButton.addEventListener('click', () => window.location.reload());
window.addEventListener('pagehide', () => {
  if (accessTokenRefreshTimer) window.clearTimeout(accessTokenRefreshTimer);
  apiRouteAdapter?.dispose();
  activeBridge?.dispose();
}, { once: true });
start().catch(() => {});
