<?php
require_once __DIR__ . '/split_source.php';
require_once __DIR__ . '/../includes/ProjectExpectedSettlement.php';
require_once __DIR__ . '/../includes/ProjectImportClassification.php';
require_once __DIR__ . '/../includes/ProjectIntake.php';
$checks = 0;
function expect_value($label, $actual, $expected) {
    global $checks;
    $checks++;
    if (is_numeric($actual) && is_numeric($expected) ? abs((float)$actual - (float)$expected) > 0.00001 : $actual !== $expected) throw new RuntimeException($label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    echo "PASS $label\n";
}
function test_rule($id, $type, $params, $employee = 84, $extra = []) {
    return $extra + ['id' => $id, 'name' => $type, 'rule_type' => $type, 'scope_business' => '*', 'scope_group' => '*', 'scope_role' => '*', 'employee_id' => $employee, 'metric' => 'profit', 'params' => $params];
}
$ctx = ['forecast' => true, 'snapshots' => [], 'inputs' => [], 'attendance' => [], 'rules' => [test_rule(1, 'base_fee', ['amount'=>800]), test_rule(2, 'attendance_bonus', ['amount'=>200])]];
$m = ps_monthly_results('2026-09', true, $ctx);
$total = ps_expected_rollup(84, [], $m);
expect_value('no orders still receives fixed fee', $total['fixed_fee'], 800);
expect_value('missing attendance forecasts full bonus', $total['attendance'], 200);
expect_value('no orders does not invent commission', $total['commission'], 0);
expect_value('income is fixed plus commission plus attendance', $total['total'], 1000);
$ctx['forecast'] = false;
$actual = ps_expected_rollup(84, [], ps_monthly_results('2026-09', true, $ctx));
expect_value('actual attendance stays unapproved', $actual['attendance'], 0);
$ctx['forecast'] = true;
$ctx['attendance'][84] = ['work'=>240,'absent'=>4];
expect_value('four hours leave expected half attendance', ps_expected_rollup(84, [], ps_monthly_results('2026-09', true, $ctx))['attendance'], 100);
$ctx['inputs'][2][84] = ['value'=>80,'note'=>'approved'];
expect_value('finance attendance approval takes precedence', ps_expected_rollup(84, [], ps_monthly_results('2026-09', true, $ctx))['attendance'], 80);

$rule = ['id'=>68,'rate'=>0.08,'calc_mode'=>'pool','service_fee_rate'=>0.03,'per_order_subsidy'=>0];
$c = ps_calc_person($rule, 6800, 1215, 6800, 1, .03);
expect_value('website source fee three percent', $c['share'], (6800-1215-6800*.03)*.08);
$order = ['id'=>1,'order_no'=>'TEST','project_type'=>'网站模板','order_kind'=>'新订单','receipt_amount'=>0,'refund_amount'=>0,'contract_amount'=>6800];
$person = ['employee_id'=>84,'role_name'=>'客服','group_weight'=>1,'rule'=>$rule,'estimated_calc'=>$c];
$summary = ['direct_cost'=>1215,'pending_cost'=>0,'groups'=>['customer_service'=>['people'=>[$person]]]];
$snaps = ps_expected_snapshot_rows($order, $summary);
expect_value('forecast income uses entered contract when cash unverified', $snaps[0]['income_amount'], 6800);
expect_value('source canonical department profit counts full fee once', $snaps[0]['department_profit'], 5381);
expect_value('canceled unpaid order has no positive commission', ps_expected_snapshot_rows($order, $summary, ['trade_status'=>'交易关闭'])[0]['commission_amount'], 0);
$invalid = $summary; $invalid['groups']['customer_service']['people'][] = $person;
expect_value('duplicate hundred percent shares do not inflate expected income', count(ps_expected_snapshot_rows($order, $invalid)), 0);

$paired = $snaps;
$paired[0]['group_weight'] = .5;
$paired[0]['contribution_profit'] = 5500;
$paired[1] = $paired[0]; $paired[1]['employee_id'] = 85;
$ctx = ['forecast'=>true,'snapshots'=>$paired,'inputs'=>[],'attendance'=>[],'rules'=>[test_rule(3,'dept_share',['base'=>'profit','rate'=>.019,'share'=>1,'deduct_commissions'=>false],16)]];
expect_value('department shared-order profit not doubled or fee halved', ps_expected_rollup(16,$paired,ps_monthly_results('2026-09',true,$ctx))['commission'], round(5381*.019,2));

$tierSnap = $snaps[0];
$tierSnap['project_type']='AI网站定制';$tierSnap['role_name']='前端';$tierSnap['calc_mode']='individual';
$tierSnap['income_amount']=9000;$tierSnap['contribution_profit']=8500;$tierSnap['commission_amount']=1105;$tierSnap['commission_exact']=1105;
$tiers=[['from'=>0,'rate'=>.05,'base'=>2000],['from'=>10000,'rate'=>.07,'base'=>2000],['from'=>15000,'rate'=>.09,'base'=>2500]];
$ctx=['forecast'=>true,'snapshots'=>[$tierSnap],'inputs'=>[],'attendance'=>[],'rules'=>[test_rule(4,'tier_rate',['tiers'=>$tiers],null,['scope_business'=>'AI网站定制','scope_role'=>'前端']),test_rule(5,'base_fee',['amount'=>2300]),test_rule(6,'attendance_bonus',['amount'=>200])]];
$income=ps_expected_rollup(84,[$tierSnap],ps_monthly_results('2026-09',true,$ctx));
expect_value('internal frontend below ten thousand rate is five percent', $income['commission'],425);
$reordered = $ctx; $reordered['rules'] = array_reverse($ctx['rules']);
expect_value('tier fixed fee does not depend on rule order', ps_expected_rollup(84,[$tierSnap],ps_monthly_results('2026-09',true,$reordered))['fixed_fee'], 1800);
expect_value('tier fixed fee excludes separately listed attendance', $income['fixed_fee'],1800);
expect_value('tier gross fixed amount plus attendance is two thousand', $income['fixed_fee']+$income['attendance'],2000);
$rule=['id'=>87,'rate'=>.04,'calc_mode'=>'pool','service_fee_rate'=>.057,'per_order_subsidy'=>2.5,'allow_negative'=>1,'low_profit_threshold'=>5,'low_profit_subsidy'=>1.5];
expect_value('copywriting low profit subsidy',ps_calc_person($rule,10,6,10,1,.057)['subsidy'],1.5);
expect_value('copywriting negative refund commission retained',ps_calc_person($rule,-10,0,0,1,.057)['share'],-.4);
expect_value('classified custom website from product',ps_import_website_business('网站模板','博山定制','软件开发部刘帅',['网站模板','AI网站定制']),'AI网站定制');
expect_value('template product wins over technical name',ps_import_website_business('网站模板','jsp展示中级版','刘帅',['网站模板','AI网站定制']),'网站模板');
expect_value('description custom keyword alone cannot reclassify',ps_import_website_business('网站模板','','定制内容',['网站模板','AI网站定制']),'网站模板');
expect_value('classification cannot broaden allowed businesses',ps_import_website_business('网站模板','博山定制','刘帅',['网站模板']),'网站模板');
expect_value('custom fifteen percent role cannot fall into generic technical service',ps_role_rule_order_kind('小程序开发','technical','定制技术15','技术服务'),'定制');
expect_value('custom customer service role selects custom algorithm',ps_role_rule_order_kind('小程序开发','customer_service','定制客服','新订单'),'定制');
expect_value('explicit renewal role rule is preserved',ps_role_rule_order_kind('小程序开发','technical','定制技术15','续费'),'续费');
$legacyRow = array_fill(0,21,'');
$legacyRow[1]='9.1'; $legacyRow[2]='美呀美'; $legacyRow[3]='buyer'; $legacyRow[4]='5127716413435105438'; $legacyRow[5]='1100';
$legacyRow[9]='博山定制'; $legacyRow[17]='董旭'; $legacyRow[18]='刘帅'; $legacyRow[19]='250';
$legacyMap=ps_import_headerless_map([$legacyRow],['美呀美'],['董旭'=>[['role'=>'customer_service']],'刘帅'=>[['role'=>'technical']]]);
expect_value('headerless website product column is identified', $legacyMap['program_name'], 9);
expect_value('unlabelled renewal price is not guessed as cost', isset($legacyMap['direct_cost']), false);
$roles=ps_import_website_people_roles(['technical'=>[['name'=>'刘帅','role'=>'模板技术']],'customer_service'=>[]], 'AI网站定制');
expect_value('custom internal frontend is not assigned template technical algorithm', $roles['technical'][0]['role'], '前端');
expect_value('import does not overwrite per-row classified business', strpos(split_test_source('project/import.php'), "\$record['project_type'] = \$selectedBusiness;"), false);
$adjustment=['employee_id'=>84,'rule_type'=>'adjustment','rule_name'=>'跨月退款','paid_separately'=>0,'amount'=>-80,'detail'=>''];
expect_value('cross month adjustment reduces expected total and commission',ps_expected_rollup(84,[],[$adjustment])['total'],-80);
echo "All $checks checks passed; no database writes.\n";
