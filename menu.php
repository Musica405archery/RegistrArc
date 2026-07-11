<?php
if(!empty($on) AND isset($ret['MODS'])) {
	if (!isset($ret['MODS']['Tools'])) {
        $ret['MODS']['Tools'][] = 'Outils';
    }
	$ret['MODS']['Tools'][] = 'Greffe v2' .'|'.$CFG->ROOT_DIR.'Modules/Custom/Greffe2/';
}
?>