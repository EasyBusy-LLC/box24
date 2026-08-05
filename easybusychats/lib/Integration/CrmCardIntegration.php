<?php

namespace EasyBusyChats\Integration;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use EasyBusyChats\Config;
use EasyBusyChats\Exception\IntegrationException;
use EasyBusyChats\Service\PermissionService;
use EasyBusyChats\Service\AccessPolicyService;

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
        $assets->addCss('/local/modules/easybusychats/assets/crm-card.css');
        $assets->addJs('/local/modules/easybusychats/assets/crm-card.js');
        $assets->addString('<script>window.EasyBusyCrmCardConfig=' . $json . ';</script>');
    }

    private static function labels(string $language): array
    {
        $all = [
            'ru-RU' => [
                'title' => 'EasyBusy Chats',
                'loading' => 'Загрузка связанных чатов…',
                'empty' => 'Связанных чатов пока нет.',
                'open' => 'Открыть чат',
                'unlink' => 'Отвязать',
                'confirmUnlink' => 'Отвязать этот чат от CRM?',
                'error' => 'Не удалось загрузить EasyBusy Chats.',
                'unknownChat' => 'Чат EasyBusy',
            ],
            'en-US' => [
                'title' => 'EasyBusy Chats',
                'loading' => 'Loading linked chats…',
                'empty' => 'No linked chats yet.',
                'open' => 'Open chat',
                'unlink' => 'Unlink',
                'confirmUnlink' => 'Unlink this chat from CRM?',
                'error' => 'Could not load EasyBusy Chats.',
                'unknownChat' => 'EasyBusy chat',
            ],
            'uk-UA' => [
                'title' => 'EasyBusy Chats',
                'loading' => 'Завантаження пов’язаних чатів…',
                'empty' => 'Пов’язаних чатів поки немає.',
                'open' => 'Відкрити чат',
                'unlink' => 'Відв’язати',
                'confirmUnlink' => 'Відв’язати цей чат від CRM?',
                'error' => 'Не вдалося завантажити EasyBusy Chats.',
                'unknownChat' => 'Чат EasyBusy',
            ],
        ];

        return $all[$language] ?? $all['ru-RU'];
    }
}
