<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectMonthly.php';
require_once __DIR__ . '/../includes/ProjectRuleAlgo.php';
require_once __DIR__ . '/../includes/ProjectIntake.php';
$pdo = db();
$pdo->beginTransaction();
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; };
try {
    $people = [];
    foreach (['tester', 'technical'] as $label) {
        $pdo->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute(['software_test_' . bin2hex(random_bytes(6)), '软件测试QA', bin2hex(random_bytes(16))]);
        $people[] = (int)$pdo->lastInsertId();
    }
    [$tester, $tech] = $people;
    foreach (['网站模板', '环境配置', '商标'] as $business) {
        $pdo->prepare("INSERT INTO project_employee_roles (employee_id,business_name,commission_group,role_name) VALUES (?,?,'technical','软件测试')")->execute([$tester,$business]);
        $pdo->prepare("INSERT INTO project_commission_rules (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_cost_rate,allow_negative,effective_from,is_extra) VALUES ('technical',?,'软件测试','*','individual',0.05,0,0,0,1,'2026-10-01',0)")->execute([$business]);
    }
    $check(ps_rule_for('technical','网站模板','2026-09-30','软件测试') === null && (float)ps_rule_for('technical','网站模板','2026-10-01','软件测试')['rate'] === 0.05, '生效日期边界，不回落到普通技术规则');
    $rule = ps_rule_for('technical','网站模板','2026-10-01','软件测试');
    $a = ps_calc_person($rule,1000,200,1000,0,0.03);
    $b = ps_calc_person($rule,100,200,1000,0,0.03);
    $check($a['share'] === 40.0 && $a['cost_basis'] === 200.0 && $a['fee'] === 0.0 && $b['share'] === -5.0, '整单营业额扣整单成本乘5%，不扣服务费，亏损同公式冲减');
    $order = ['id'=>0,'project_type'=>'网站模板','order_kind'=>'新订单','order_date'=>'2026-10-02','contract_amount'=>1000,'receipt_amount'=>1000,'refund_amount'=>0];
    $costs = [['review_status'=>'approved','amount'=>200,'category'=>'outsourcing','item_name'=>'外包']];
    $normal = ['employee_id'=>$tech,'commission_group'=>'technical','role_name'=>'技术','group_weight'=>1];
    $testing = ['employee_id'=>$tester,'commission_group'=>'technical','role_name'=>'软件测试','group_weight'=>0];
    $before = ps_summary($order,$costs,[$normal]);
    $after = ps_summary($order,$costs,[$normal,$testing]);
    $check($before['groups']['technical']['people'][0]['calc']['share'] === $after['groups']['technical']['people'][0]['calc']['share'] && (float)$after['groups']['technical']['people'][1]['calc']['share'] === 40.0 && ps_group_weight_valid($after['groups']['technical']), '增加测试不稀释技术提成或组池权重');
    $pdo->prepare("INSERT INTO project_commission_rules (commission_group,project_type,role_name,order_kind,calc_mode,rate,per_order_subsidy,effective_from,is_extra) VALUES ('technical','网站模板','*','*','individual',0,20,'2026-10-01',1)")->execute();
    $only = ps_summary($order,$costs,[$testing]);
    $check((float)$only['groups']['technical']['people'][0]['calc']['subsidy'] === 0.0 && ps_group_weight_valid($only['groups']['technical']) && !ps_group_weight_valid(['people'=>[$normal],'weight'=>0.5]), '测试不叠加通用补贴，仅测试订单可审，普通组权重仍校验');
    $unauthorized = $testing; $unauthorized['employee_id'] = $tech;
    $denied = ps_summary($order,$costs,[$unauthorized]);
    $check($denied['groups']['technical']['missing_rule'] && ps_rules_for_person('technical','网站模板','2026-10-02','软件测试/前端') === [], '未开通角色和混合测试岗位不能核算');
    $formula = pra_formula($rule,$tester,0.03);
    $check(strpos($formula['headline'],'5%') !== false && strpos($formula['headline'],'营业额') !== false && ps_employee_default_role($tester,'网站模板','technical') === null, '个人算法UI显示5%，普通导入不自动变成测试');
    $tag = 'SOFTTEST-' . bin2hex(random_bytes(6));
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status) VALUES (?,'测试','环境配置','测试',1000,1000,'2026-10-02','finished','draft')")->execute([$tag]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','none')")->execute([$id]);
    $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status) VALUES (?,'outsourcing','外包',1,'项',200,200,'one_time',1,'测试','approved')")->execute([$id]);
    $rejected = false;
    try { ps_intake_participants($id,['technical'=>[['id'=>$tech,'role'=>'软件测试']]],'环境配置'); } catch (RuntimeException $e) { $rejected = true; }
    ps_intake_participants($id,['technical'=>[['id'=>$tester,'role'=>'软件测试']]],'环境配置');
    $p = ps_participants($id);
    $check($rejected && count($p) === 1 && (float)$p[0]['group_weight'] === 0.0, '导入拒绝未授权测试人员，明确测试岗位不占组池权重');
    $finance = ['type'=>'admin','id'=>(int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(),'employee_id'=>null,'role'=>'finance'];
    ps_approve_order($id,$finance,'2026-10');
    $q = $pdo->prepare('SELECT commission_amount,direct_cost,service_fee FROM project_commission_snapshots WHERE order_id=?');$q->execute([$id]);$snap=$q->fetch();
    $check((float)$snap['commission_amount'] === 40.0 && (float)$snap['direct_cost'] === 200.0 && (float)$snap['service_fee'] === 0.0, '实际审核生成40元快照，已审核流程保留');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo "测试数据全部回滚\n";
