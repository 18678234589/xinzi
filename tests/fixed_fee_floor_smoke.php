<?php
$root=getenv('HEZUOSHANG_APP') ?: dirname(__DIR__);
require_once $root.'/includes/ProjectExpectedSettlement.php';
function fixed_fee_rule($id,$type,$params,$employee=84,$extra=[]) {
    return $extra+['id'=>$id,'name'=>$type,'rule_type'=>$type,'scope_business'=>'*','scope_group'=>'*','scope_role'=>'*','employee_id'=>$employee,'metric'=>'profit','params'=>$params];
}
function fixed_fee_check($name,$ok){if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
$snap=['employee_id'=>84,'order_id'=>0,'project_type'=>'AI网站定制','role_name'=>'前端','commission_group'=>'technical','calc_mode'=>'individual','income_amount'=>9000,'contribution_profit'=>8500,'department_profit'=>8500,'commission_amount'=>1105,'commission_exact'=>1105,'group_weight'=>1,'direct_cost'=>500,'service_fee'=>0];
$tiers=[['from'=>0,'rate'=>.05,'base'=>2000],['from'=>15000,'rate'=>.09,'base'=>4000]];
$ctx=['forecast'=>true,'snapshots'=>[$snap],'inputs'=>[],'attendance'=>[],'rules'=>[fixed_fee_rule(4,'tier_rate',['tiers'=>$tiers],null,['scope_business'=>'AI网站定制','scope_role'=>'前端']),fixed_fee_rule(5,'base_fee',['amount'=>2300]),fixed_fee_rule(6,'attendance_bonus',['amount'=>200])]];
$income=function($context)use($snap){return ps_expected_rollup(84,$context['snapshots'],ps_monthly_results('2026-09',true,$context));};
$a=$income($ctx);fixed_fee_check('2300 employee fee is not reduced and attendance is separate',$a['fixed_fee']==2300 && $a['attendance']==200 && $a['commission']==425);
$higher=$ctx;$higher['rules'][1]['params']['amount']=2800;fixed_fee_check('2800 employee fee is not reduced',$income($higher)['fixed_fee']==2800);
$lower=$ctx;$lower['rules'][1]['params']['amount']=800;fixed_fee_check('tier minimum still protects lower fee',$income($lower)['fixed_fee']==1800);
$highTier=$ctx;$highTier['snapshots'][0]['income_amount']=18000;$highTier['snapshots'][0]['contribution_profit']=17000;$highTier['snapshots'][0]['department_profit']=17000;fixed_fee_check('higher achieved tier still raises fixed fee',$income($highTier)['fixed_fee']==3800);
$reverse=$ctx;$reverse['rules']=array_reverse($reverse['rules']);fixed_fee_check('display order does not change fixed fee',$income($reverse)['fixed_fee']==2300);
$manual=$ctx;$manual['inputs'][5][84]=['value'=>2100,'note'=>'explicit finance override'];fixed_fee_check('explicit finance override remains authoritative',$income($manual)['fixed_fee']==2100);
