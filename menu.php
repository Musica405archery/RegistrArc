<?php
if (subFeatureAcl($acl,AclParticipants,'pTarget') == AclReadWrite) {
      $ret['PART']['RegistrArc'] = 'Raboule la moula!' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/RegistrArc/';
      }
?>