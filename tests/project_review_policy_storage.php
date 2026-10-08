<?php
if (PHP_SAPI!=='cli') exit;
require_once __DIR__.'/../includes/ProjectMonthly.php';
require_once __DIR__.'/../includes/ProjectReviewPolicy.php';
$pdo=db();$n=0;
function rps_check($ok,$text){global $n;$n++;if(!$ok)throw new RuntimeException($text);}
// Connection-local shadows: no real order, rule or reward is created.
foreach(['orders','employees','project_monthly_rules','project_orders','project_participants','project_order_sources'] as $table){
    $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl);
    $ddl=preg_replace('/^\s*CONSTRAINT .*FOREIGN KEY.*\n/m','',$ddl);
    $ddl=preg_replace('/,\n\) ENGINE/',"\n) ENGINE",$ddl);$pdo->exec($ddl);
}
$pdo->exec('CREATE TEMPORARY TABLE project_review_rule_policies (rule_scope VARCHAR(16),rule_id INT,allow_no_receipt INT,calculation_period VARCHAR(16))');
$pdo->exec("INSERT INTO project_review_rule_policies VALUES ('monthly',901,1,'monthly'),('monthly',902,0,'monthly'),('order',903,0,'order')");
$pdo->exec("INSERT INTO employees (id,name,password,department) VALUES (990001,'无流水测试人员','temporary-test-only','核验测试')");
$params=['dept'=>'','gate_column'=>'接单客服','counters'=>[['name'=>'单量','unit'=>1,'column'=>''],['name'=>'拍链接','unit'=>0.5,'column'=>'拍建站','keywords'=>'网站链接','match'=>'any']]];
$rule=['id'=>901,'name'=>'无流水测试补助','rule_type'=>'legacy_sheet','scope_business'=>'网站续费','scope_group'=>'*','scope_role'=>'*','employee_id'=>990001,'metric'=>'profit','params'=>$params,'effective_from'=>'2026-09','effective_to'=>null,'is_active'=>1];
$pdo->prepare('INSERT INTO project_monthly_rules (id,name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,1)')
    ->execute([901,$rule['name'],'legacy_sheet','网站续费','*','*',990001,'profit',json_encode($params,JSON_UNESCAPED_UNICODE),'2026-09']);
$raw=['接单客服'=>'无流水测试人员','旺旺'=>'买家A','日期'=>'2026-09-01','拍建站'=>'网站链接','__order_status__'=>'未核验'];
$ins=$pdo->prepare('INSERT INTO orders (employee_id,order_scope,order_no,shop,order_date,order_amount,raw_data,is_abnormal,is_deleted) VALUES (990001,\'personal\',?,\'S\',\'2026-09-01\',0,?,0,0)');
$ins->execute(['A',json_encode($raw,JSON_UNESCAPED_UNICODE)]);$ins->execute(['A',json_encode($raw,JSON_UNESCAPED_UNICODE)]);
$x=$raw;$x['__verified_month__']='2026-08';$x['旺旺']='其他月份';$ins->execute(['OTHER-MONTH',json_encode($x,JSON_UNESCAPED_UNICODE)]);
$x=$raw;$x['__is_refund__']='1';$x['旺旺']='已退款';$ins->execute(['REFUNDED',json_encode($x,JSON_UNESCAPED_UNICODE)]);
$x=$raw;$x['__order_status__']='退款中';$x['旺旺']='待退款';$ins->execute(['REFUND-PENDING',json_encode($x,JSON_UNESCAPED_UNICODE)]);
rps_check(prp_allow_no_receipt('monthly',$rule),'explicit monthly exemption must load');
$disabled=$rule;$disabled['id']=902;rps_check(!prp_allow_no_receipt('monthly',$disabled),'disabled policy must not waive receipt');
rps_check(!prp_allow_no_receipt('order',['id'=>903,'rate'=>0,'per_order_subsidy'=>1]),'order policy must independently enforce receipt');
$ctx=['rules'=>[$rule],'snapshots'=>[],'inputs'=>[],'attendance'=>[]];
$result=ps_monthly_results('2026-09',true,$ctx);
rps_check(count($result)===1 && (float)$result[0]['amount']===1.5,'missing receipt fixed counters pass once; duplicates/refunds/other month excluded');
rps_check(strpos($result[0]['detail'],'无流水单量自动核验')!==false,'calculation provenance must explain no receipt allowance');
$repeat=ps_monthly_results('2026-09',true,$ctx);rps_check($repeat===$result,'repeat evaluation must not double count');
$ctx['rules']=[$disabled];$held=ps_monthly_results('2026-09',true,$ctx);rps_check((float)$held[0]['amount']===0.0,'disabling exemption keeps unverified rows out');
$context=prp_order_context(['order_no'=>'A','order_date'=>'2026-09-01','project_type'=>'网站续费','shop'=>'S']);
rps_check(count($context['monthly_allowance_rules'])===1 && $context['monthly_allowance_rules'][0]['rule_id']===901,'order review must link actual monthly allowance eligibility');
rps_check(!$context['monthly_income_required'],'pure count rule does not invent financial dependency');
$pdo->exec("INSERT INTO project_orders (id,order_no,project_type,order_kind,shop,order_date,contract_amount) VALUES (990011,'PROJECT-ONLY','网站续费','拍链接','S','2026-09-03',100)");
$pdo->exec("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (990011,990001,'customer_service','客服',1)");
$pdo->exec("INSERT INTO project_order_sources (order_id,payment_nickname,trade_status) VALUES (990011,'真实客户B','买家已付款,等待卖家发货')");
$linked=prp_project_quantity_rows('2026-09',990001,[]);
rps_check(count($linked)===1 && $linked[0]['id']===-990011,'project-only participant must contribute quantity source without a legacy row');
$rawLink=json_decode($linked[0]['raw_data'],true);rps_check($rawLink['旺旺']==='真实客户B' && $rawLink['拍建站']==='网站链接','only actual nickname and explicit link category used');
rps_check(prp_project_quantity_rows('2026-09',990001,$linked)===[],'project quantity source deduplicates original sources');
rps_check(prp_project_quantity_rows('2026-09',990002,[])===[],'unrelated person cannot receive project quantity');
$pdo->exec("UPDATE project_order_sources SET trade_status='退款中' WHERE order_id=990011");
rps_check(prp_project_quantity_rows('2026-09',990001,[])===[],'pending refund excludes new project quantity');
$pdo->exec("INSERT INTO project_orders (id,order_no,project_type,order_kind,shop,order_date,contract_amount) VALUES (990012,'PROJECT-MONTH','网站续费','拍链接','S','2026-10-03',100)");
$pdo->exec("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (990012,990001,'customer_service','客服',1)");
$pdo->exec("INSERT INTO project_order_sources (order_id,payment_nickname,trade_status) VALUES (990012,'真实客户C','买家已付款,等待卖家发货')");
$bridgeRule=$rule;$bridgeRule['params']['dept']='网站售后部';
$bridge=ps_monthly_results('2026-10',true,['rules'=>[$bridgeRule],'snapshots'=>[],'inputs'=>[],'attendance'=>[]]);
rps_check(count($bridge)===1&&(float)$bridge[0]['amount']===1.5,'actual monthly engine must consume project-only quantity source without creating receipt or duplicate bonus');
echo "PASS $n rule storage assertions; temporary tables only\n";
