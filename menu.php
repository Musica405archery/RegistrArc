<?php
if(!empty($on) AND isset($ret['MODS'])) {
	if (!isset($ret['MODS']['RegistrArc'])) {
        $ret['MODS']['RegistrArc'][] = 'Outils';
    }
	$ret['MODS']['RegistrArc'][] = 'Greffe' .'|'.$CFG->ROOT_DIR.'Modules/Custom/RegistrArc/';
}
?>