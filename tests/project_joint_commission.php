<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../includes/ProjectIntake.php';
$pdo=db(); $n=0;
function jc_check($actual,$expected,$name) { global $n; $n++; if($actual!==$expected) throw new RuntimeException($name.': '.json_encode($actual,JSON_UNESCAPED_UNICODE)); }
// Connection-local table shadows only. No real account, order or rule is changed.
foreach (['project_commission_rules','project_participants','employees'] as $table) {
    $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl);
    $ddl=preg_replace('/^\s*CONSTRAINT .*FOREIGN KEY.*\n/m','',$ddl);
    $pdo->exec(preg_replace('/,\n\) ENGINE/',"\n) ENGINE",$ddl));
}
$pdo->exec("INSERT INTO project_commission_rules (id,commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_contract_amount,effective_from,is_active) VALUES
 (1,'customer_service','小程序开发','*','*','pool',.05,.03,0,0,'2026-07-01',1),
 (2,'customer_service','小程序开发','*','新订单','pool',.05,.03,20,0,'2026-07-01',1),
 (3,'customer_service','小程序开发','定制客服','定制','pool',.1,.03,10,50,'2026-07-01',1),
 (4,'customer_service','小程序开发','*','定制','pool',.1,.03,10,50,'2026-07-01',1),
 (5,'technical','小程序开发','*','定制','pool',.3,.03,20,50,'2026-07-01',1),
 (6,'technical','小程序开发','定制技术15','定制','pool',.15,.03,20,50,'2026-07-01',1)");
$custom=ps_rule_for('customer_service','小程序开发','2026-09-20','客服','定制');
$template=ps_rule_for('customer_service','小程序开发','2026-09-20','客服','模板');
jc_check((int)$custom['id'],4,'generic customer role must select custom rule');
jc_check((float)$custom['rate'],.1,'custom customer ten percent');
jc_check((float)$custom['per_order_subsidy'],10.0,'custom subsidy');
jc_check((int)$template['id'],2,'template alias selects new-order rule');
jc_check((float)$template['per_order_subsidy'],20.0,'template subsidy');
jc_check((int)ps_rule_for('customer_service','小程序开发','2026-09-20','定制客服','定制')['id'],3,'specific role preserved');
jc_check((float)ps_rule_for('technical','小程序开发','2026-09-20','制作技术','定制')['rate'],.3,'technical not overridden by customer rate');
jc_check((float)ps_rule_for('technical','小程序开发','2026-09-20','定制技术15','定制')['rate'],.15,'technical fifteen-percent override preserved');
foreach ([$custom,$template] as $rule) {
    $single=ps_calc_person($rule,1000,100,1000,1,.03);
    $people=[]; foreach([.5,.5] as $w) $people[]=['rule'=>$rule,'group_weight'=>$w,'calc'=>ps_calc_person($rule,1000,100,1000,$w,.03)];
    $shares=ps_group_share_cents($people); $subsidies=ps_group_subsidy_cents($people);
    jc_check(array_sum($shares), (int)round($single['share']*100),'joint share pool equals single share');
    jc_check(array_sum($subsidies), (int)round($single['subsidy']*100),'joint subsidy only once');
    jc_check($shares[0]+$subsidies[0], $shares[1]+$subsidies[1],'equal joint share including subsidy');
}
$rule=$template; $rule['service_fee_rate']=0; $rule['rate']=.1; $rule['per_order_subsidy']=20;
$people=[];foreach([.5,.5]as$w)$people[]=['rule'=>$rule,'group_weight'=>$w,'calc'=>ps_calc_person($rule,200,0,200,$w,0)];
$a=ps_group_share_cents($people);$b=ps_group_subsidy_cents($people);
jc_check([$a[0]+$b[0],$a[1]+$b[1]],[2000,2000],'forty-yuan pool gives twenty each');
$people[1]['rule']['id']=999;
jc_check(array_sum(ps_group_share_cents($people)),2000,'equivalent role rules share same pool');
jc_check(array_sum(ps_group_subsidy_cents($people)),2000,'equivalent role rules share same subsidy pool');
$rule['per_order_subsidy']=.01;$people=[];
foreach([.333333,.333333,.333334]as$w)$people[]=['rule'=>$rule,'group_weight'=>$w,'calc'=>ps_calc_person($rule,200,0,200,$w,0)];
jc_check(array_sum(ps_group_subsidy_cents($people)),1,'three-person one-cent subsidy conserves cents');
$blocked=ps_calc_person($custom,40,0,40,.5,.03);
jc_check([$blocked['share'],$blocked['subsidy']],[0.0,0.0],'minimum-sale threshold still blocks');
$ind=$custom;$ind['calc_mode']='individual';
$calc=ps_calc_person($ind,1000,100,1000,.5,.03);
jc_check($calc['subsidy'],10.0,'independent-role subsidy unchanged');
$website=$template;$website['project_type']='网站模板';$website['rate']=.08;$website['per_order_subsidy']=0;
jc_check(round(ps_calc_person($website,998,360,998,.5,.03)['share'],2),24.92,'explicit website primary-secondary fee convention preserved');
$pdo->exec("INSERT INTO employees (id,name,password,department) VALUES (990001,'共同客服甲','temporary-test-only','测试'),(990002,'共同客服乙','temporary-test-only','测试')");
ps_intake_participants(990001,['customer_service'=>[['id'=>990001,'role'=>'客服'],['id'=>990002,'role'=>'客服']]]);
$weights=$pdo->query('SELECT group_weight FROM project_participants ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
jc_check(array_map('floatval',$weights),[.5,.5],'online/import shared intake defaults to equal weights');
echo "PASS $n joint-commission assertions; temporary tables only.\n";
