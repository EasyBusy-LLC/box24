<?php

if (!check_bitrix_sessid()) {
    return;
}
?>
<form action="<?=$APPLICATION->GetCurPage()?>" method="post">
    <?=bitrix_sessid_post()?>
    <input type="hidden" name="lang" value="<?=LANGUAGE_ID?>">
    <input type="hidden" name="id" value="easybusychats">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">
    <p><?=GetMessage('EASYBUSYCHATS_UNINSTALL_KEEP_DATA')?></p>
    <label>
        <input type="checkbox" name="delete_mappings" value="Y">
        <?=GetMessage('EASYBUSYCHATS_UNINSTALL_DELETE_DATA')?>
    </label>
    <br><br>
    <input type="submit" value="<?=GetMessage('MOD_UNINST_DEL')?>">
</form>
