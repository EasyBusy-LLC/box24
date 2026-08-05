<?php

namespace EasyBusyChats\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\CryptoField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class BoxCredentialTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_easybusychats_box_credential';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary(),
            (new StringField('CLIENT_ID'))->configureRequired()->configureSize(191),
            (new CryptoField('CLIENT_SECRET_ENCRYPTED'))->configureRequired(),
            (new DatetimeField('CREATED_AT'))->configureRequired(),
            (new DatetimeField('UPDATED_AT'))->configureRequired(),
            (new IntegerField('UPDATED_BY'))->configureRequired(),
        ];
    }
}
