<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use EasyBusyChats\Config;
use EasyBusyChats\Exception\IntegrationException;
use EasyBusyChats\Service\BoxCredentialService;
use EasyBusyChats\Service\AccessPolicyService;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

global $APPLICATION, $USER;

if (!$USER->IsAdmin() || !Loader::includeModule('easybusychats')) {
    return;
}

Loc::loadMessages(__FILE__);
$request = Context::getCurrent()->getRequest();
$errors = [];
$saved = false;
$credentialService = new BoxCredentialService();
$accessPolicy = new AccessPolicyService();
$credentialStatus = $credentialService->getStatus();
// Fields set by environment variables are shown read-only and keep their stored values on save.
$environmentOverrides = Config::getEnvironmentOverrides();
$isFromEnvironment = static fn(string $option): bool => isset($environmentOverrides[$option]);

if ($request->isPost() && check_bitrix_sessid()) {
    $pluginUrl = trim((string)$request->getPost(Config::OPTION_PLUGIN_URL));
    $pluginOrigin = rtrim(trim((string)$request->getPost(Config::OPTION_PLUGIN_ORIGIN)), '/');
    $language = trim((string)$request->getPost(Config::OPTION_DEFAULT_LANGUAGE));
    $timeout = (int)$request->getPost(Config::OPTION_REQUEST_TIMEOUT);
    $apiBaseUrl = rtrim(trim((string)$request->getPost(Config::OPTION_API_BASE_URL)), '/');
    $clientId = trim((string)$request->getPost('box_client_id'));
    $clientSecret = trim((string)$request->getPost('box_client_secret'));

    if ($isFromEnvironment(Config::OPTION_PLUGIN_URL)) {
        $pluginUrl = Option::get(Config::MODULE_ID, Config::OPTION_PLUGIN_URL, Config::getDefaults()[Config::OPTION_PLUGIN_URL]);
    }
    if ($isFromEnvironment(Config::OPTION_PLUGIN_ORIGIN)) {
        $pluginOrigin = Option::get(Config::MODULE_ID, Config::OPTION_PLUGIN_ORIGIN, '');
    }
    if ($isFromEnvironment(Config::OPTION_API_BASE_URL)) {
        $apiBaseUrl = Option::get(Config::MODULE_ID, Config::OPTION_API_BASE_URL, Config::getDefaults()[Config::OPTION_API_BASE_URL]);
    }

    if (!Config::isValidPluginUrl($pluginUrl)) {
        $errors[] = Loc::getMessage('EASYBUSYCHATS_OPTIONS_INVALID_PLUGIN_URL');
    }
    if ($pluginOrigin !== '' && !Config::isValidOrigin($pluginOrigin)) {
        $errors[] = Loc::getMessage('EASYBUSYCHATS_OPTIONS_INVALID_PLUGIN_ORIGIN');
    }
    if (!in_array($language, ['ru-RU', 'en-US', 'uk-UA'], true)) {
        $errors[] = Loc::getMessage('EASYBUSYCHATS_OPTIONS_INVALID_LANGUAGE');
    }
    if ($timeout < 3000 || $timeout > 60000) {
        $errors[] = Loc::getMessage('EASYBUSYCHATS_OPTIONS_INVALID_TIMEOUT');
    }
    if (!Config::isValidEasyBusyApiBaseUrl($apiBaseUrl)) {
        $errors[] = Loc::getMessage('EASYBUSYCHATS_OPTIONS_INVALID_API_URL');
    }

    if ($errors === []) {
        try {
            $credentialStatus = $credentialService->configure($clientId, $clientSecret, (int)$USER->GetID());
        } catch (IntegrationException $error) {
            $errors[] = $error->getMessage();
        }
    }

    if ($errors === []) {
        try {
            $selectedAccessUsers = $request->getPost('easybusy_access_users');
            $accessPolicy->replaceAllowedUsers(is_array($selectedAccessUsers) ? $selectedAccessUsers : []);
        } catch (IntegrationException $error) {
            $errors[] = $error->getMessage();
        }
    }

    if ($errors === []) {
        Option::set(Config::MODULE_ID, Config::OPTION_PLUGIN_URL, $pluginUrl);
        Option::set(Config::MODULE_ID, Config::OPTION_PLUGIN_ORIGIN, $pluginOrigin);
        Option::set(Config::MODULE_ID, Config::OPTION_DEFAULT_LANGUAGE, $language);
        Option::set(
            Config::MODULE_ID,
            Config::OPTION_CONTACT_ENABLED,
            $request->getPost(Config::OPTION_CONTACT_ENABLED) === 'Y' ? 'Y' : 'N'
        );
        Option::set(
            Config::MODULE_ID,
            Config::OPTION_DEAL_ENABLED,
            $request->getPost(Config::OPTION_DEAL_ENABLED) === 'Y' ? 'Y' : 'N'
        );
        Option::set(
            Config::MODULE_ID,
            Config::OPTION_DEBUG_ENABLED,
            $request->getPost(Config::OPTION_DEBUG_ENABLED) === 'Y' ? 'Y' : 'N'
        );
        Option::set(Config::MODULE_ID, Config::OPTION_REQUEST_TIMEOUT, (string)$timeout);
        Option::set(Config::MODULE_ID, Config::OPTION_API_BASE_URL, $apiBaseUrl);
        $saved = true;
    }
}

$accessUsers = $accessPolicy->getUsersForManagement();

foreach ($errors as $error) {
    CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => $error]);
}
if ($saved) {
    CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => Loc::getMessage('EASYBUSYCHATS_OPTIONS_SAVED')]);
}

$tabs = [
    [
        'DIV' => 'easybusychats_settings',
        'TAB' => Loc::getMessage('EASYBUSYCHATS_OPTIONS_TAB'),
        'TITLE' => Loc::getMessage('EASYBUSYCHATS_OPTIONS_TITLE'),
    ],
    [
        'DIV' => 'easybusychats_access',
        'TAB' => Loc::getMessage('EASYBUSYCHATS_OPTIONS_ACCESS_TAB'),
        'TITLE' => Loc::getMessage('EASYBUSYCHATS_OPTIONS_ACCESS_TITLE'),
    ],
];
$tabControl = new CAdminTabControl('easybusychatsOptions', $tabs);
$tabControl->Begin();
?>
<?php
$renderUrlInput = static function (string $option, string $storedValue, string $effectiveValue) use ($environmentOverrides): string {
    if (!isset($environmentOverrides[$option])) {
        return '<input type="text" size="70" name="' . $option . '" value="' . htmlspecialcharsbx($storedValue) . '">';
    }

    return '<input type="text" size="70" value="' . htmlspecialcharsbx($effectiveValue) . '" readonly disabled>'
        . '<div class="adm-info-message-wrap"><small>'
        . htmlspecialcharsbx(Loc::getMessage('EASYBUSYCHATS_OPTIONS_FROM_ENVIRONMENT', ['#NAME#' => $environmentOverrides[$option]]))
        . '</small></div>';
};
?>
<form method="post" action="<?=htmlspecialcharsbx($APPLICATION->GetCurPage())?>?mid=easybusychats&amp;lang=<?=LANGUAGE_ID?>">
    <?=bitrix_sessid_post()?>
    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td width="40%"><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_PLUGIN_URL')?>:</td>
        <td width="60%"><?=$renderUrlInput(
            Config::OPTION_PLUGIN_URL,
            Option::get(Config::MODULE_ID, Config::OPTION_PLUGIN_URL, Config::getDefaults()[Config::OPTION_PLUGIN_URL]),
            Config::getIframeUrl()
        )?></td>
    </tr>
    <tr>
        <td><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_PLUGIN_ORIGIN')?>:</td>
        <td><?=$renderUrlInput(
            Config::OPTION_PLUGIN_ORIGIN,
            Option::get(Config::MODULE_ID, Config::OPTION_PLUGIN_ORIGIN, ''),
            Config::getPluginOrigin()
        )?></td>
    </tr>
    <tr>
        <td><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_DEFAULT_LANGUAGE')?>:</td>
        <td>
            <select name="<?=Config::OPTION_DEFAULT_LANGUAGE?>">
                <?php foreach (['ru-RU' => 'Русский', 'en-US' => 'English', 'uk-UA' => 'Українська'] as $value => $label): ?>
                    <option value="<?=$value?>" <?=$value === Config::getDefaultLanguage() ? 'selected' : ''?>><?=htmlspecialcharsbx($label)?></option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <tr>
        <td><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_API_BASE_URL')?>:</td>
        <td><?=$renderUrlInput(
            Config::OPTION_API_BASE_URL,
            Option::get(Config::MODULE_ID, Config::OPTION_API_BASE_URL, Config::getDefaults()[Config::OPTION_API_BASE_URL]),
            Config::getEasyBusyApiBaseUrl()
        )?></td>
    </tr>
    <tr class="heading">
        <td colspan="2"><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_BOX_AUTH_TITLE')?></td>
    </tr>
    <tr>
        <td><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_BOX_CLIENT_ID')?>:</td>
        <td><input type="text" size="50" maxlength="191" name="box_client_id" value="<?=htmlspecialcharsbx((string)($credentialStatus['clientId'] ?? ''))?>" autocomplete="off"></td>
    </tr>
    <tr>
        <td><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_BOX_CLIENT_SECRET')?>:</td>
        <td>
            <input type="password" size="50" maxlength="4096" name="box_client_secret" value="" autocomplete="new-password">
            <?php if (!empty($credentialStatus['configured'])): ?>
                <div class="adm-info-message-wrap"><small><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_BOX_SECRET_SAVED')?></small></div>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_CONTACT_ENABLED')?>:</td>
        <td><input type="checkbox" name="<?=Config::OPTION_CONTACT_ENABLED?>" value="Y" <?=Config::isContactIntegrationEnabled() ? 'checked' : ''?>></td>
    </tr>
    <tr>
        <td><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_DEAL_ENABLED')?>:</td>
        <td><input type="checkbox" name="<?=Config::OPTION_DEAL_ENABLED?>" value="Y" <?=Config::isDealIntegrationEnabled() ? 'checked' : ''?>></td>
    </tr>
    <tr>
        <td><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_DEBUG_ENABLED')?>:</td>
        <td><input type="checkbox" name="<?=Config::OPTION_DEBUG_ENABLED?>" value="Y" <?=Config::isDebugEnabled() ? 'checked' : ''?>></td>
    </tr>
    <tr>
        <td><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_REQUEST_TIMEOUT')?>:</td>
        <td><input type="number" min="3000" max="60000" step="1000" name="<?=Config::OPTION_REQUEST_TIMEOUT?>" value="<?=Config::getRequestTimeout()?>"></td>
    </tr>
    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td colspan="2">
            <div class="adm-info-message-wrap">
                <div class="adm-info-message"><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_ACCESS_DESCRIPTION')?></div>
            </div>
            <table class="adm-list-table" style="width: 100%;">
                <thead>
                    <tr class="adm-list-table-header">
                        <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner"><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_ACCESS_ALLOWED')?></div></td>
                        <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner"><?=Loc::getMessage('EASYBUSYCHATS_OPTIONS_ACCESS_USER')?></div></td>
                        <td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Email</div></td>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accessUsers as $accessUser): ?>
                        <tr class="adm-list-table-row">
                            <td class="adm-list-table-cell" style="text-align:center; width:100px;">
                                <?php if ($accessUser['isAdmin']): ?>
                                    <input type="checkbox" checked disabled title="<?=htmlspecialcharsbx(Loc::getMessage('EASYBUSYCHATS_OPTIONS_ACCESS_ADMIN_ALWAYS'))?>">
                                <?php else: ?>
                                    <input type="checkbox" name="easybusy_access_users[]" value="<?=(int)$accessUser['id']?>" <?=$accessUser['allowed'] ? 'checked' : ''?>>
                                <?php endif; ?>
                            </td>
                            <td class="adm-list-table-cell">
                                <?=htmlspecialcharsbx((string)$accessUser['name'])?>
                                <small>(<?=htmlspecialcharsbx((string)$accessUser['login'])?>, ID <?=(int)$accessUser['id']?>)</small>
                                <?php if ($accessUser['isAdmin']): ?>
                                    <strong> — <?=htmlspecialcharsbx(Loc::getMessage('EASYBUSYCHATS_OPTIONS_ACCESS_ADMIN'))?></strong>
                                <?php endif; ?>
                            </td>
                            <td class="adm-list-table-cell"><?=htmlspecialcharsbx((string)$accessUser['email'])?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </td>
    </tr>
    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="save" value="<?=Loc::getMessage('MAIN_SAVE')?>" class="adm-btn-save">
    <?php $tabControl->End(); ?>
</form>
