<?php
if (subFeatureAcl($acl,AclParticipants,'pTarget') == AclReadWrite) {
      $ret['PART']['RegistrArc'] = 'Greffe' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/RegistrArc/';
      }
?>