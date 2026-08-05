<?php

namespace EasyBusyChats\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class ChatDealTable extends DataManager
{
    use DeleteByFilterTrait;

    public static function getTableName(): string
    {
        return 'b_easybusychats_chat_deal';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
            (new StringField('SITE_ID'))->configureRequired()->configureSize(10),
            (new StringField('EASYBUSY_COMPANY_ID'))->configureRequired()->configureSize(191),
            (new StringField('CHAT_ID'))->configureRequired()->configureSize(191),
            (new IntegerField('BITRIX_DEAL_ID'))->configureRequired(),
            (new IntegerField('CREATED_BY'))->configureRequired(),
            (new DatetimeField('CREATED_AT'))->configureRequired(),
            (new DatetimeField('UPDATED_AT'))->configureRequired(),
        ];
    }
}
