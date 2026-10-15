<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectMonthly.php';
$pdo = db();
$pdo->beginTransaction();
try {
    $q = $pdo->prepare('SELECT id,name FROM employees WHERE id=82 AND name=? FOR UPDATE');
    $q->execute(['李雪']);
    if (!$q->fetch()) throw new RuntimeException('李雪员工身份未匹配，未修改配置');
    $q = $pdo->query("SELECT id FROM project_users WHERE employee_id=82 AND username='lixue' AND is_active=1");
    if (!$q->fetchColumn()) throw new RuntimeException('李雪有效账号未匹配');
    $businesses = array_keys(ps_business_catalog());
    $created = 0;
    foreach ($businesses as $business) {
        $q = $pdo->prepare("SELECT * FROM project_commission_rules WHERE commission_group='technical' AND project_type=? AND role_name='软件测试' AND order_kind='*' AND effective_from='2026-10-01' AND is_extra=0 FOR UPDATE");
        $q->execute([$business]);
        $rule = $q->fetch();
        if ($rule) {
            if (!$rule['is_active'] || $rule['calc_mode'] !== 'individual' || abs((float)$rule['rate'] - 0.05) > 0.000001 || (float)$rule['service_fee_rate'] != 0 || (float)$rule['per_order_subsidy'] != 0 || (float)$rule['min_cost_rate'] != 0 || !$rule['allow_negative']) throw new RuntimeException('已有测试规则与本次要求不一致：' . $business);
        } else {
            $pdo->prepare("INSERT INTO project_commission_rules (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_contract_amount,min_cost_rate,allow_negative,note,effective_from,is_extra) VALUES ('technical',?,'软件测试','*','individual',0.05,0,0,0,0,1,?,'2026-10-01',0)")->execute([$business, '软件测试：（营业额－整单实际成本）×5%；不另扣服务费，不占技术组池权重，须明确指派测试']);
            $created++;
        }
        $q = $pdo->prepare("SELECT role_name FROM project_employee_roles WHERE employee_id=82 AND business_name=? AND commission_group='technical' FOR UPDATE");
        $q->execute([$business]);
        $role = $q->fetchColumn();
        if ($role !== false && $role !== '软件测试') throw new RuntimeException('李雪已有其他技术岗位，不自动覆盖：' . $business);
        $pdo->prepare("INSERT INTO project_employee_roles (employee_id,business_name,commission_group,role_name) VALUES (82,?,'technical','软件测试') ON DUPLICATE KEY UPDATE role_name=VALUES(role_name)")->execute([$business]);
    }
    $messageId = null;
    if (in_array('--notify', $argv, true)) {
        $title = '软件测试角色已开通：2026年10月1日起按利润5%提成';
        $body = "李雪，你的软件测试角色、订单入口和提成核算功能已开通，原有客服角色保留。\n\n自2026年10月1日起，按订单日期生效。财务在订单详情中明确指派你担任“软件测试”后，该测试订单按（营业额－成本）×5%独立计提。营业额按实收减退款，扣整单已审核实际成本；不占技术组池权重，不另扣店铺服务费、不叠加补贴，退款和亏损按同一公式冲减。未指派测试的订单不会自动计提，10月1日前订单不套用新规则。\n\n工作台首页点击“软件测试 · 订单与5%提成”，或打开下方链接，可查看测试订单与提成；“我的分成算法”中也可查看规则。未审核金额为预估，已审核金额以保存的分成快照为准。";
        $pdo->prepare("INSERT INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (82,'announcement',?,?,?,'software-testing-role:82:2026-10-01') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)")->execute([$title, $body, '/project/testing.php?month=2026-10']);
        $messageId = (int)$pdo->lastInsertId();
    }
    $adminId = (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
    ps_audit('employee', 82, 'software_testing_role', ['type'=>'admin','id'=>$adminId,'role'=>'finance','employee_id'=>null], ['effective_from'=>'2026-10-01','rate'=>0.05,'rules_created'=>$created,'notification_id'=>$messageId]);
    $pdo->commit();
    echo json_encode(['employee_id'=>82,'businesses'=>count($businesses),'rules_created'=>$created,'notification_id'=>$messageId], JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
