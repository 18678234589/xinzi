<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/ProjectGovernanceRuleAccess.php';
$checks=0;
function gra_check($actual,$expected){global $checks;$checks++;if($actual!==$expected)throw new RuntimeException('Rule access assertion failed');}
$admin=['type'=>'admin','id'=>1]; $user=['type'=>'employee','id'=>5,'employee_id'=>70,'role'=>'technical'];
$committee=['employee_id'=>70,'governance_role'=>'committee']; $chair=['employee_id'=>70,'governance_role'=>'chair'];
$none=pgr_access(null,null,false);gra_check($none['read'],false);gra_check(pgr_rule_visible(['rule_state'=>'confirmed'],$none),false);
$super=pgr_access($admin,null,true);gra_check($super['read'],true);gra_check($super['drafts'],true);gra_check($super['edit'],false);gra_check(pgr_rule_visible(['rule_state'=>'draft'],$super),true);
$finance=pgr_access($admin,null,false);gra_check($finance['read'],true);gra_check($finance['drafts'],false);gra_check($finance['edit'],false);
$public=pgr_access($user,null,false);gra_check($public['read'],true);gra_check($public['drafts'],false);gra_check($public['edit'],false);gra_check(pgr_rule_visible(['rule_state'=>'confirmed'],$public),true);gra_check(pgr_rule_visible(['rule_state'=>'draft'],$public),false);
$member=pgr_access($user,$committee,false);gra_check($member['read'],true);gra_check($member['drafts'],true);gra_check($member['edit'],true);
$chairAccess=pgr_access($user,$chair,false);gra_check($chairAccess['drafts'],true);gra_check($chairAccess['edit'],false);
$fake=pgr_access($user,['employee_id'=>99,'governance_role'=>'committee'],true);gra_check($fake['edit'],false);gra_check($fake['drafts'],false);
gra_check(pgr_access($admin,$committee,true)['edit'],false);
gra_check(pgr_access(['type'=>'admin','id'=>0],null,true)['read'],false);
echo "All $checks governance rule access checks passed; no database writes.\n";
