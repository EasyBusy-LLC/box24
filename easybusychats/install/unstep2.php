<?php

if (!check_bitrix_sessid()) {
    return;
}
?>
<?=CAdminMessage::ShowNote(GetMessage('EASYBUSYCHATS_UNINSTALL_COMPLETE'))?>
