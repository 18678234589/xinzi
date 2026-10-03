<?php
require_once __DIR__.'/ProjectRenewals.php';
$renewalActor=ps_actor();
if ($renewalActor && pr_ready() && pr_scope($renewalActor)!=='none') {
    echo $nav('/project/renewals.php','fa-calendar-check','续费工作台',$_rel==='project/renewals.php'||$_rel==='project/renewal_sms.php');
}
