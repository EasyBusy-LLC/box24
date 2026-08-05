<?php

namespace EasyBusyChats\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class ChatContactTable extends DataManager
{
    use DeleteByFilterTrait;

    public static function getTableName(): string
    {
        return 'b_easybusychats_chat_contact';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new StringField('SITE_ID'))->configureRequired()->configureSize(10),
            (new StringField('EASYBUSY_COMPANY_ID'))->configureRequired()->configureSize(191),
            (new StringField('CHAT_ID'))->configureRequired()->configureSize(191),
            (new StringField('ACCOUNT_ID'))->configureSize(191),
            (new StringField('PROVIDER'))->configureSize(64),
            (new StringField('EXTERNAL_CONTACT_ID'))->configureSize(191),
            (new IntegerField('BITRIX_CONTACT_ID'))->configureRequired(),
            (new StringField('LINK_SOURCE'))->configureRequired()->configureSize(32),
            (new StringField('CHAT_TITLE'))->configureSize(255),
            (new StringField('AVATAR_URL'))->configureSize(500),
            (new DatetimeField('LAST_MESSAGE_AT'))->configureNullable(),
            (new IntegerField('CREATED_BY'))->configureRequired(),
            (new DatetimeField('CREATED_AT'))->configureRequired(),
            (new DatetimeField('UPDATED_AT'))->configureRequired(),
        ];
    }
}
