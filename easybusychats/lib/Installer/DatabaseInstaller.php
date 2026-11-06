<?php

namespace EasyBusyChats\Installer;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\Connection;
use EasyBusyChats\Model\ChatContactTable;
use EasyBusyChats\Model\ChatDealTable;
use EasyBusyChats\Model\BoxCredentialTable;
use EasyBusyChats\Model\UserTokenTable;

final class DatabaseInstaller
{
    public static function install(): void
    {
        $connection = Application::getConnection();

        self::createTable($connection, ChatContactTable::class);
        self::createTable($connection, ChatDealTable::class);
        self::createTable($connection, UserTokenTable::class);
        self::createTable($connection, BoxCredentialTable::class);
        self::makeLastMessageNullable($connection);
        self::addExternalContactId($connection);
        self::addLinkSource($connection);

        self::createIndex(
            $connection,
            ChatContactTable::getTableName(),
            'ux_eb_chat_contact_key',
            ['SITE_ID', 'EASYBUSY_COMPANY_ID', 'CHAT_ID'],
            true
        );
        self::createIndex(
            $connection,
            ChatContactTable::getTableName(),
            'ix_eb_chat_contact_contact',
            ['BITRIX_CONTACT_ID']
        );
        self::createIndex(
            $connection,
            ChatContactTable::getTableName(),
            'ix_eb_chat_contact_chat',
            ['CHAT_ID']
        );
        self::createIndex(
            $connection,
            ChatDealTable::getTableName(),
            'ux_eb_chat_deal_key',
            ['SITE_ID', 'EASYBUSY_COMPANY_ID', 'CHAT_ID', 'BITRIX_DEAL_ID'],
            true
        );
        self::createIndex(
            $connection,
            ChatDealTable::getTableName(),
            'ix_eb_chat_deal_deal',
            ['BITRIX_DEAL_ID']
        );
        self::createIndex(
            $connection,
            ChatDealTable::getTableName(),
            'ix_eb_chat_deal_chat',
            ['CHAT_ID']
        );
        self::createIndex(
            $connection,
            UserTokenTable::getTableName(),
            'ux_eb_user_token_identity',
            ['SITE_ID', 'BITRIX_USER_ID'],
            true
        );
        self::createIndex(
            $connection,
            UserTokenTable::getTableName(),
            'ix_eb_user_token_user',
            ['BITRIX_USER_ID']
        );
        self::deleteLegacyFiles();
        Option::set('easybusychats', 'database_schema_version', '9');
    }

    public static function uninstall(bool $deleteMappings): void
    {
        self::deleteLegacyFiles();
        $connection = Application::getConnection();
        if ($connection->isTableExists(UserTokenTable::getTableName())) {
            $connection->dropTable(UserTokenTable::getTableName());
        }
        if ($connection->isTableExists(BoxCredentialTable::getTableName())) {
            $connection->dropTable(BoxCredentialTable::getTableName());
        }
        if (!$deleteMappings) {
            return;
        }

        foreach ([ChatDealTable::getTableName(), ChatContactTable::getTableName()] as $tableName) {
            if ($connection->isTableExists($tableName)) {
                $connection->dropTable($tableName);
            }
        }
    }

    // Earlier versions shipped these files where web servers can serve them without authentication.
    private static function deleteLegacyFiles(): void
    {
        $testsDirectory = dirname(__DIR__, 2) . '/tests';
        $paths = [
            Application::getDocumentRoot() . '/local/logs/easybusychats.log',
            $testsDirectory . '/access-policy-smoke.php',
            $testsDirectory . '/phone-e2e-fixture.php',
            $testsDirectory . '/phone-link-integration.php',
            $testsDirectory . '/phone-normalizer.php',
        ];
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($testsDirectory)) {
            @rmdir($testsDirectory);
        }
    }

    private static function createTable(Connection $connection, string $dataManager): void
    {
        $tableName = $dataManager::getTableName();
        if (!$connection->isTableExists($tableName)) {
            $dataManager::getEntity()->createDbTable();
        }
    }

    private static function createIndex(
        Connection $connection,
        string $tableName,
        string $indexName,
        array $columns,
        bool $unique = false
    ): void {
        if ($connection->getIndexName($tableName, $columns, true) !== null) {
            return;
        }

        if ($unique) {
            $connection->createIndex($tableName, $indexName, $columns, null, Connection::INDEX_UNIQUE);
            return;
        }

        $connection->createIndex($tableName, $indexName, $columns);
    }

    private static function makeLastMessageNullable(Connection $connection): void
    {
        if ((int)Option::get('easybusychats', 'database_schema_version', '0') >= 2) {
            return;
        }

        $tableName = ChatContactTable::getTableName();
        if ($connection->getTableField($tableName, 'LAST_MESSAGE_AT') === null) {
            return;
        }
        // Connection::getTableField() does not report nullability, so read it from MySQL.
        if ($connection->getType() === 'mysql') {
            $helper = $connection->getSqlHelper();
            $sqlTableName = $helper->quote($tableName);
            $column = $connection->query(
                'SHOW COLUMNS FROM ' . $sqlTableName . " LIKE 'LAST_MESSAGE_AT'"
            )->fetch();
            if ($column && $column['Null'] === 'NO') {
                $connection->queryExecute(
                    'ALTER TABLE ' . $sqlTableName . ' MODIFY LAST_MESSAGE_AT datetime NULL'
                );
                $connection->clearCaches($tableName);
            }
        }
        Option::set('easybusychats', 'database_schema_version', '2');
    }

    private static function addExternalContactId(Connection $connection): void
    {
        $tableName = ChatContactTable::getTableName();
        if ($connection->getTableField($tableName, 'EXTERNAL_CONTACT_ID') !== null) {
            return;
        }

        $connection->queryExecute(
            'ALTER TABLE ' . $connection->getSqlHelper()->quote($tableName)
            . ' ADD COLUMN EXTERNAL_CONTACT_ID varchar(191)'
        );
        $connection->clearCaches($tableName);
    }

    private static function addLinkSource(Connection $connection): void
    {
        $tableName = ChatContactTable::getTableName();
        $helper = $connection->getSqlHelper();
        if ($connection->getTableField($tableName, 'LINK_SOURCE') === null) {
            $connection->queryExecute(
                'ALTER TABLE ' . $helper->quote($tableName) . ' ADD COLUMN LINK_SOURCE varchar(32)'
            );
            $connection->clearCaches($tableName);
        }

        $connection->queryExecute(
            'UPDATE ' . $helper->quote($tableName)
            . " SET LINK_SOURCE = 'manual' WHERE LINK_SOURCE IS NULL OR LINK_SOURCE = ''"
        );
    }
}
