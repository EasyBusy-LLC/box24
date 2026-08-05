<?php

namespace EasyBusyChats\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\CryptoField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class UserTokenTable extends DataManager
{
    use DeleteByFilterTrait;

    public static function getTableName(): string
    {
        return 'b_easybusychats_user_token';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new StringField('SITE_ID'))->configureRequired()->configureSize(10),
            (new IntegerField('BITRIX_USER_ID'))->configureRequired(),
            (new CryptoField('TOKEN_ENCRYPTED'))->configureRequired(),
            (new StringField('TOKEN_FINGERPRINT'))->configureRequired()->configureSize(64),
            (new StringField('COMPANY_ID'))->configureRequired()->configureSize(191),
            (new StringField('COMPANY_NAME'))->configureRequired()->configureSize(255),
            (new DatetimeField('VALIDATED_AT'))->configureRequired(),
            (new DatetimeField('CREATED_AT'))->configureRequired(),
            (new DatetimeField('UPDATED_AT'))->configureRequired(),
            (new IntegerField('UPDATED_BY'))->configureRequired(),
        ];
    }
}
