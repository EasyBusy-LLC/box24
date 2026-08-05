import { AppError } from './errors.js';

function required(value, name) {
  const result = String(value || '').trim();
  if (!result) throw new AppError(`${name} is not configured.`, { code: 'CONFIG_MISSING' });
  return result;
}

function httpsUrl(value, name) {
  const raw = required(value, name);
  let url;
  try {
    url = new URL(raw);
  } catch {
    throw new AppError(`${name} is invalid.`, { code: 'CONFIG_INVALID' });
  }
  if (url.protocol !== 'https:') throw new AppError(`${name} must use HTTPS.`, { code: 'CONFIG_INVALID' });
  return raw.replace(/\/+$/, '');
}

export function normalizeLanguage(value) {
  const language = String(value || '').trim();
  return ['ru-RU', 'en-US', 'uk-UA'].includes(language) ? language : 'ru-RU';
}

export function getConfig() {
  const raw = window.EASYBUSY_BITRIX24_CONFIG || {};
  return Object.freeze({
    environment: String(raw.environment || 'prod'),
    pluginLoaderUrl: httpsUrl(raw.pluginLoaderUrl, 'EasyBusy plugin loader URL'),
    defaultLang: normalizeLanguage(raw.defaultLang),
    requestTimeout: Math.max(3000, Math.min(60000, Number(raw.requestTimeout) || 15000)),
  });
}

export function withRuntimeConfig(config, bootstrap) {
  const easyBusy = bootstrap?.easyBusy && typeof bootstrap.easyBusy === 'object' ? bootstrap.easyBusy : {};
  return Object.freeze({
    ...config,
    companyName: required(easyBusy.companyName, 'EasyBusy company name'),
    companyId: required(easyBusy.companyId, 'EasyBusy company ID'),
    apiBaseUrl: httpsUrl(easyBusy.apiBaseUrl, 'EasyBusy API URL'),
    accessToken: required(easyBusy.accessToken, 'EasyBusy access token'),
    expiresIn: Math.max(60, Math.min(86400, Number(easyBusy.expiresIn) || 3600)),
    authMode: String(easyBusy.authMode || ''),
  });
}
