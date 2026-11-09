<?php

namespace EasyBusyChats\Integration;

use Bitrix\Main\Application;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\UI\Extension;
use EasyBusyChats\Config;
use EasyBusyChats\Exception\IntegrationException;
use EasyBusyChats\Service\PermissionService;
use EasyBusyChats\Service\AccessPolicyService;
use EasyBusyChats\Service\BoxAuthService;
use EasyBusyChats\Service\MappingService;

final class CrmCardIntegration
{
    public static function onProlog(): void
    {
        global $USER;

        if (!is_object($USER) || !$USER->IsAuthorized() || !Loader::includeModule('crm')) {
            return;
        }

        if (!(new AccessPolicyService())->isAllowed((int)$USER->GetID())) {
            return;
        }

        $path = (string)Application::getInstance()->getContext()->getRequest()->getRequestedPage();
        if (!preg_match('~^/crm/(contact|deal)/details/(\d+)/~', $path, $matches)) {
            return;
        }

        $type = $matches[1];
        $entityId = (int)$matches[2];
        if ($entityId <= 0 || ($type === 'contact' && !Config::isContactIntegrationEnabled())
            || ($type === 'deal' && !Config::isDealIntegrationEnabled())) {
            return;
        }

        try {
            $permissions = new PermissionService((int)$USER->GetID());
            if ($type === 'contact') {
                $permissions->assertCanReadContact($entityId);
            } else {
                $permissions->assertCanReadDeal($entityId);
            }
        } catch (IntegrationException) {
            return;
        }

        $language = Config::getCurrentLanguage();
        $labels = self::labels($language);
        $config = [
            'entityType' => $type,
            'entityId' => $entityId,
            'actionPrefix' => 'easybusychats.Api.',
            'language' => $language,
            'labels' => $labels,
        ];
        $json = json_encode(
            $config,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        $assets = Asset::getInstance();
        // Toasts and confirmations in the card use the Bitrix UI dialogs.
        Extension::load(['ui.notification', 'ui.dialogs.messagebox']);
        $assets->addCss('/local/modules/easybusychats/assets/crm-card.css');
        $assets->addJs('/local/modules/easybusychats/assets/host-bridge.js');
        $assets->addJs('/local/modules/easybusychats/assets/crm-card.js');
        $assets->addString('<script>window.EasyBusyCrmCardConfig=' . $json . ';</script>');
    }

    public static function onEntityDetailsTabsInitialized(Event $event): ?EventResult
    {
        global $USER;

        $entityId = (int)$event->getParameter('entityID');
        $tabs = $event->getParameter('tabs');
        $type = match ((int)$event->getParameter('entityTypeID')) {
            \CCrmOwnerType::Contact => Config::isContactIntegrationEnabled() ? 'contact' : null,
            \CCrmOwnerType::Deal => Config::isDealIntegrationEnabled() ? 'deal' : null,
            default => null,
        };
        if ($type === null || $entityId <= 0 || !is_array($tabs) || !is_object($USER) || !$USER->IsAuthorized()) {
            return null;
        }

        $html = self::conversationTabHtml($type, $entityId, (int)$USER->GetID());
        if ($html === '') {
            return null;
        }

        $mainTabIndex = array_search('main', array_column($tabs, 'id'), true);
        array_splice($tabs, $mainTabIndex === false ? 0 : $mainTabIndex + 1, 0, [[
            'id' => 'easybusychats',
            'name' => 'EasyBusy Chats',
            'html' => $html,
        ]]);

        return new EventResult(EventResult::SUCCESS, ['tabs' => $tabs]);
    }

    private static function conversationTabHtml(string $type, int $entityId, int $userId): string
    {
        $iframeUrl = Config::getIframeUrl();
        $pluginOrigin = Config::getPluginOrigin();
        $hostOrigin = Config::getCurrentOrigin();
        if ($iframeUrl === '' || $pluginOrigin === '' || $hostOrigin === '') {
            return '';
        }

        try {
            $authStatus = (new BoxAuthService())->getStatus();
            if (!(new AccessPolicyService())->isAllowed($userId) || empty($authStatus['configured'])) {
                return '';
            }
            $permissions = new PermissionService($userId);
            if ($type === 'contact') {
                $permissions->assertCanReadContact($entityId);
            } else {
                $permissions->assertCanReadDeal($entityId);
            }
            $initialChat = self::latestLinkedChat($type, $entityId, (string)$authStatus['clientId']);
        } catch (\Throwable) {
            return '';
        }

        $language = Config::getCurrentLanguage();
        $bootstrap = [
            'protocolVersion' => 1,
            'sessionNonce' => bin2hex(random_bytes(24)),
            'pluginOrigin' => $pluginOrigin,
            'hostOrigin' => $hostOrigin,
            'language' => $language,
            'actionPrefix' => 'easybusychats.Api.',
            'requestTimeout' => Config::getRequestTimeout(),
            'conversation' => [$type . 'Id' => $entityId],
            'initialChat' => $initialChat,
        ];
        $separator = str_contains($iframeUrl, '?') ? '&' : '?';
        $iframeUrl .= $separator . http_build_query([
            'lang' => $language,
            'hostOrigin' => $hostOrigin,
        ], '', '&', PHP_QUERY_RFC3986);
        $json = json_encode(
            $bootstrap,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return '<div id="easybusy-host" class="eb-crm-conversation"'
            . ' data-bootstrap="' . htmlspecialcharsbx($json) . '"'
            . ' data-iframe-url="' . htmlspecialcharsbx($iframeUrl) . '">'
            . '<div class="eb-crm-conversation__status" data-easybusy-host-status hidden></div>'
            . '</div>';
    }

    private static function latestLinkedChat(string $type, int $entityId, string $companyId): array
    {
        $siteId = defined('SITE_ID') && SITE_ID !== ''
            ? SITE_ID
            : (string)(Application::getInstance()->getContext()->getSite() ?: 's1');
        $mappings = new MappingService();
        $chats = $type === 'contact'
            ? $mappings->getContactChats($siteId, $entityId)
            : $mappings->getDealChats($siteId, $entityId);
        foreach ($chats as $chat) {
            if ((string)$chat['EASYBUSY_COMPANY_ID'] === $companyId
                && (string)($chat['ACCOUNT_ID'] ?? '') !== ''
                && (string)($chat['PROVIDER'] ?? '') !== ''
            ) {
                return [
                    'easyBusyCompanyId' => (string)$chat['EASYBUSY_COMPANY_ID'],
                    'chatId' => (string)$chat['CHAT_ID'],
                    'accountId' => (string)$chat['ACCOUNT_ID'],
                    'provider' => (string)$chat['PROVIDER'],
                    'externalContactId' => (string)($chat['EXTERNAL_CONTACT_ID'] ?? ''),
                ];
            }
        }

        return [];
    }

    private static function labels(string $language): array
    {
        $all = [
            'ru-RU' => [
                'title' => 'EasyBusy Chats',
                'loading' => 'Загрузка связанных чатов…',
                'empty' => 'Связанных чатов пока нет.',
                'open' => 'Открыть чат',
                'newChat' => 'Новый чат',
                'unlink' => 'Отвязать',
                'confirmUnlink' => 'Отвязать этот чат от CRM?',
                'error' => 'Не удалось загрузить EasyBusy Chats.',
                'unknownChat' => 'Чат EasyBusy',
                'linkBroken' => 'Связь не работает: чат относится к другой компании EasyBusy, потому что изменились настройки авторизации. Отвяжите чат и свяжите его заново в разделе «Все чаты».',
            ],
            'en-US' => [
                'title' => 'EasyBusy Chats',
                'loading' => 'Loading linked chats…',
                'empty' => 'No linked chats yet.',
                'open' => 'Open chat',
                'newChat' => 'New chat',
                'unlink' => 'Unlink',
                'confirmUnlink' => 'Unlink this chat from CRM?',
                'error' => 'Could not load EasyBusy Chats.',
                'unknownChat' => 'EasyBusy chat',
                'linkBroken' => 'This link is broken: the chat belongs to another EasyBusy company because the authorization settings changed. Unlink it and link the chat again from All chats.',
            ],
            'uk-UA' => [
                'title' => 'EasyBusy Chats',
                'loading' => 'Завантаження пов’язаних чатів…',
                'empty' => 'Пов’язаних чатів поки немає.',
                'open' => 'Відкрити чат',
                'newChat' => 'Новий чат',
                'unlink' => 'Відв’язати',
                'confirmUnlink' => 'Відв’язати цей чат від CRM?',
                'error' => 'Не вдалося завантажити EasyBusy Chats.',
                'unknownChat' => 'Чат EasyBusy',
                'linkBroken' => 'Зв’язок не працює: чат належить іншій компанії EasyBusy, бо змінилися налаштування авторизації. Відв’яжіть чат і пов’яжіть його знову в розділі «Усі чати».',
            ],
        ];

        return $all[$language] ?? $all['ru-RU'];
    }
}
