<?php

namespace EasyBusyChats\Service;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use EasyBusyChats\Exception\IntegrationException;

final class PermissionService
{
    private int $userId;

    public function __construct(int $userId)
    {
        if ($userId <= 0 || !Loader::includeModule('crm')) {
            throw new IntegrationException('CRM is unavailable.', 'CRM_UNAVAILABLE');
        }
        $this->userId = $userId;
    }

    public function getCapabilities(): array
    {
        $permissions = Container::getInstance()->getUserPermissions($this->userId);

        return [
            'contact' => [
                'read' => $permissions->entityType()->canReadItems(\CCrmOwnerType::Contact),
                'add' => $permissions->entityType()->canAddItems(\CCrmOwnerType::Contact),
                'update' => $permissions->entityType()->canUpdateItems(\CCrmOwnerType::Contact),
            ],
            'deal' => [
                'read' => $permissions->entityType()->canReadItems(\CCrmOwnerType::Deal),
                'add' => $permissions->entityType()->canAddItems(\CCrmOwnerType::Deal),
                'update' => $permissions->entityType()->canUpdateItems(\CCrmOwnerType::Deal),
            ],
        ];
    }

    public function assertCanReadContact(int $id): void
    {
        $this->assertItem('CONTACT_ACCESS_DENIED', \CCrmOwnerType::Contact, $id, 'read');
    }

    public function assertCanReadContacts(): void
    {
        $this->assertType('CONTACT_ACCESS_DENIED', \CCrmOwnerType::Contact, 'read');
    }

    public function assertCanAddContact(): void
    {
        $this->assertType('CONTACT_ADD_ACCESS_DENIED', \CCrmOwnerType::Contact, 'add');
    }

    public function assertCanUpdateContact(int $id): void
    {
        $this->assertItem('CONTACT_UPDATE_ACCESS_DENIED', \CCrmOwnerType::Contact, $id, 'update');
    }

    public function assertCanReadDeal(int $id): void
    {
        $this->assertItem('DEAL_ACCESS_DENIED', \CCrmOwnerType::Deal, $id, 'read');
    }

    public function assertCanAddDeal(): void
    {
        $this->assertType('DEAL_ADD_ACCESS_DENIED', \CCrmOwnerType::Deal, 'add');
    }

    public function assertCanUpdateDeal(int $id): void
    {
        $this->assertItem('DEAL_UPDATE_ACCESS_DENIED', \CCrmOwnerType::Deal, $id, 'update');
    }

    private function assertType(string $code, int $typeId, string $operation): void
    {
        $permissions = Container::getInstance()->getUserPermissions($this->userId)->entityType();
        $allowed = $operation === 'add'
            ? $permissions->canAddItems($typeId)
            : $permissions->canReadItems($typeId);
        if (!$allowed) {
            throw new IntegrationException('You do not have permission to perform this CRM operation.', $code);
        }
    }

    private function assertItem(string $code, int $typeId, int $id, string $operation): void
    {
        if ($id <= 0) {
            throw new IntegrationException('Invalid CRM entity ID.', 'INVALID_CRM_ID');
        }

        $permissions = Container::getInstance()->getUserPermissions($this->userId)->item();
        $allowed = $operation === 'update'
            ? $permissions->canUpdate($typeId, $id)
            : $permissions->canRead($typeId, $id);
        if (!$allowed) {
            throw new IntegrationException('You do not have permission to perform this CRM operation.', $code);
        }
    }
}
