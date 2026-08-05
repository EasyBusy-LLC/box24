<?php

namespace EasyBusyChats\Integration;

use EasyBusyChats\Installer\LeftMenuInstaller;
use EasyBusyChats\Service\AccessPolicyService;

final class UserAccessIntegration
{
    public static function onProlog(): void
    {
        global $USER;

        if (!is_object($USER) || !$USER->IsAuthorized()) {
            return;
        }

        $userId = (int)$USER->GetID();
        LeftMenuInstaller::syncUser($userId, (new AccessPolicyService())->isAllowed($userId));
    }
}
