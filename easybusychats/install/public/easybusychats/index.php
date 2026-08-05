<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use EasyBusyChats\Config;
use EasyBusyChats\Service\BoxAuthService;
use EasyBusyChats\Service\AccessPolicyService;

global $APPLICATION, $USER;

if (!$USER->IsAuthorized()) {
    LocalRedirect('/?login=yes&backurl=' . urlencode('/easybusychats/'));
}

$moduleLoaded = Loader::includeModule('easybusychats');
$language = $moduleLoaded ? Config::getCurrentLanguage() : 'ru-RU';
$accessGranted = $moduleLoaded && (new AccessPolicyService())->isAllowed((int)$USER->GetID());
if ($moduleLoaded) {
    $APPLICATION->SetTitle('EasyBusy Chats');
    Asset::getInstance()->addCss('/local/modules/easybusychats/assets/app.css');
    if ($accessGranted) {
        Asset::getInstance()->addJs('/local/modules/easybusychats/assets/host-bridge.js');
    }
}
if ($moduleLoaded && !$accessGranted) {
    \CHTTP::SetStatus('403 Forbidden');
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';

if (!$moduleLoaded) {
    ShowError('Модуль EasyBusyChats не установлен.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    return;
}

if (!$accessGranted) {
    $accessDeniedMessages = [
        'ru-RU' => 'Администратор Bitrix24 не предоставил вам доступ к EasyBusy Chats.',
        'en-US' => 'The Bitrix24 administrator has not granted you access to EasyBusy Chats.',
        'uk-UA' => 'Адміністратор Bitrix24 не надав вам доступ до EasyBusy Chats.',
    ];
    ShowError($accessDeniedMessages[$language] ?? $accessDeniedMessages['ru-RU']);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
    return;
}

$iframeUrl = Config::getIframeUrl();
$pluginOrigin = Config::getPluginOrigin();
$hostOrigin = Config::getCurrentOrigin();
$siteId = defined('SITE_ID') && SITE_ID !== '' ? SITE_ID : 's1';
$authStatus = ['configured' => false];
$authStatusError = '';
try {
    $authStatus = (new BoxAuthService())->getStatus();
} catch (\Throwable) {
    $authStatusError = 'EasyBusy Box authentication storage is unavailable.';
}
$translations = [
    'ru-RU' => [
        'notConfigured' => 'Авторизация EasyBusy не настроена администратором.',
        'openSettings' => 'Открыть настройки модуля',
    ],
    'en-US' => [
        'notConfigured' => 'EasyBusy authentication has not been configured by an administrator.',
        'openSettings' => 'Open module settings',
    ],
    'uk-UA' => [
        'notConfigured' => 'Авторизацію EasyBusy не налаштовано адміністратором.',
        'openSettings' => 'Відкрити налаштування модуля',
    ],
];
$messages = $translations[$language] ?? $translations['ru-RU'];
$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
$identifier = static function (mixed $value, int $limit = 191): string {
    $value = trim((string)$value);
    if ($value === '' || mb_strlen($value) > $limit || !preg_match('/^[A-Za-z0-9._:@\-]+$/', $value)) {
        return '';
    }

    return $value;
};
$initialChat = array_filter([
    'easyBusyCompanyId' => $identifier($request->getQuery('easyBusyCompanyId')),
    'chatId' => $identifier($request->getQuery('chatId')),
    'accountId' => $identifier($request->getQuery('accountId')),
    'provider' => $identifier($request->getQuery('provider'), 64),
    'externalContactId' => $identifier($request->getQuery('externalContactId')),
], static fn(string $value): bool => $value !== '');

$bootstrap = [
    'protocolVersion' => 1,
    'sessionNonce' => bin2hex(random_bytes(24)),
    'pluginOrigin' => $pluginOrigin,
    'hostOrigin' => $hostOrigin,
    'language' => $language,
    'siteId' => $siteId,
    'user' => [
        'id' => (int)$USER->GetID(),
        'name' => trim((string)$USER->GetFullName()),
    ],
    'actionPrefix' => 'easybusychats.Api.',
    'requestTimeout' => Config::getRequestTimeout(),
    'features' => [
        'contacts' => Config::isContactIntegrationEnabled(),
        'deals' => Config::isDealIntegrationEnabled(),
    ],
    'initialChat' => $initialChat,
    'authStatus' => $authStatus,
];

if ($iframeUrl !== '' && $pluginOrigin !== '' && $hostOrigin !== '') {
    $separator = str_contains($iframeUrl, '?') ? '&' : '?';
    $iframeUrl .= $separator . http_build_query([
        'lang' => $language,
        'hostOrigin' => $hostOrigin,
    ], '', '&', PHP_QUERY_RFC3986);
}
ob_start();
?>
<div
    id="easybusy-host"
    class="easybusy-chats easybusy-chats--iframe"
    data-bootstrap="<?=htmlspecialcharsbx(json_encode($bootstrap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT))?>"
>
    <?php if ($authStatusError !== ''): ?>
        <div class="easybusy-chats__status easybusy-chats__status--error">
            <?=htmlspecialcharsbx($authStatusError)?>
        </div>
    <?php elseif ($iframeUrl === '' || $pluginOrigin === '' || $hostOrigin === ''): ?>
        <div class="easybusy-chats__status easybusy-chats__status--error">
            EasyBusy Chats configuration is invalid. Check the module settings.
        </div>
    <?php elseif (empty($authStatus['configured'])): ?>
        <section class="easybusy-token-card">
            <h2><?=htmlspecialcharsbx($messages['notConfigured'])?></h2>
            <?php if ($USER->IsAdmin()): ?>
                <a class="ui-btn ui-btn-primary" href="/bitrix/admin/settings.php?lang=<?=urlencode((string)LANGUAGE_ID)?>&amp;mid=easybusychats">
                    <?=htmlspecialcharsbx($messages['openSettings'])?>
                </a>
            <?php endif; ?>
        </section>
    <?php else: ?>
    <div class="easybusy-chats__status" data-easybusy-host-status hidden></div>
    <iframe
        id="easybusy-host-frame"
        class="easybusy-chats__frame"
        src="<?=htmlspecialcharsbx($iframeUrl)?>"
        title="EasyBusy Chats"
        allow="clipboard-write; microphone"
        loading="eager"
    ></iframe>
    <?php endif; ?>
</div>
<?php
$pageContent = (string)ob_get_clean();
$isSidePanel = ($_GET['IFRAME'] ?? '') === 'Y'
    && ($_GET['IFRAME_TYPE'] ?? '') === 'SIDE_SLIDER';

if ($isSidePanel) {
    $APPLICATION->IncludeComponent('bitrix:ui.sidepanel.wrapper', '', [
        '~CONTENT' => $pageContent,
        'PLAIN_VIEW' => true,
        'USE_PADDING' => false,
        'HIDE_TOOLBAR' => true,
        'PREVENT_LOADING_WITHOUT_IFRAME' => false,
        'USE_FAST_WAY_CLOSE_LOADER' => true,
    ]);
}

echo $pageContent;
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
