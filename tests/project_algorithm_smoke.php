<?php
// 《程序表记录》《网站核算》《小程序部门核算标准》口径回归：导入预置 → 逐单分成与 8 月核对表一致。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectPresets.php';

function check_algorithm($actual, $expected, $label)
{
    if (abs((float)$actual - (float)$expected) > 0.001) throw new RuntimeException($label . ': expected ' . $expected . ', got ' . $actual);
}

function algorithm_person($summary, $group, $index = 0)
{
    $person = $summary['groups'][$group]['people'][$index] ?? null;
    if (!$person || !$person['calc']) throw new RuntimeException($group . ' #' . $index . ' 未匹配到规则');
    return round($person['calc']['share'] + $person['calc']['subsidy'], 2);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $adminId = (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
    $employeeIds = array_map('intval', $pdo->query('SELECT id FROM employees ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN));
    if (!$adminId || count($employeeIds) < 3) throw new RuntimeException('测试需要一名管理员及三名合作人员');
    $actor = ['type' => 'admin', 'id' => $adminId, 'employee_id' => null, 'role' => 'finance'];

    // 1. 预置导入幂等
    $pdo->exec("UPDATE project_cost_templates SET is_active=0");
    $pdo->exec("UPDATE project_commission_rules SET is_active=0");
    $added = ps_apply_preset_templates($actor);
    if ($added !== count(ps_preset_cost_templates())) throw new RuntimeException('程序表模板未全部导入：' . $added);
    if (ps_apply_preset_templates($actor) !== 0) throw new RuntimeException('程序表模板重复导入');
    $rulesAdded = ps_apply_preset_rules($actor, '2026-09-01');
    if ($rulesAdded !== count(ps_preset_rules())) throw new RuntimeException('核算规则未全部导入：' . $rulesAdded);
    if (ps_apply_preset_rules($actor, '2026-09-01') !== 0) throw new RuntimeException('核算规则重复导入');

    // 2. 程序名称匹配套餐（部门表“程序名称”列）
    $programs = ps_intake_templates('program', '网站模板');
    $match = function ($text) use ($programs) { $t = ps_intake_program_suggestion($text, $programs); return $t ? (float)$t['price'] : null; };
    check_algorithm($match('5年JSP展示中级版'), 950, '5年JSP展示中级版成本');
    check_algorithm($match('4年JSP展示中级版'), 860, '4年JSP展示中级版成本');
    check_algorithm($match('JSP展示高级版'), 530, 'JSP展示高级版默认 1 年空间+域名');
    check_algorithm($match('PHP'), 170, 'PHP 空间+域名（空间 90 + 域名首年 80）');
    check_algorithm($match('优站'), 230, '优站 1 年空间+域名');
    if ($match('不存在的程序') !== null) throw new RuntimeException('未知程序不应自动匹配');

    $date = '2026-09-15';
    $order = function ($type, $contract, $receipt, $kind = '') use ($date) { return ['project_type' => $type, 'order_kind' => $kind, 'contract_amount' => $contract, 'receipt_amount' => $receipt, 'refund_amount' => 0, 'order_date' => $date]; };
    $approved = function ($amount) { return ['amount' => $amount, 'review_status' => 'approved']; };

    // 3. 网站模板：售价 998，JSP展示中级版 1 年空间+域名 360，服务费 3%
    $template = ps_summary($order('网站模板', 998, 998), [$approved(360)], [
        ['employee_id' => 1, 'commission_group' => 'technical', 'group_weight' => 1, 'role_name' => '模板技术'],
        ['employee_id' => 2, 'commission_group' => 'customer_service', 'group_weight' => 0.5, 'role_name' => '客服'],
        ['employee_id' => 3, 'commission_group' => 'customer_service', 'group_weight' => 0.5, 'role_name' => '客服'],
    ]);
    check_algorithm($template['service_fee'], 29.94, '模板服务费 3%');
    check_algorithm(algorithm_person($template, 'technical'), 79.05, '模板技术 13%');
    check_algorithm(algorithm_person($template, 'customer_service', 0), 24.92, '两名模板客服（主次）：(998−360−1.5%)×4%，与核算表“博山模板主次 0.04”一致');
    // 8 月光君汇总：售价 109716、成本 42440 → (109716−42440−3%)×13% = 8317.99（另加 809.77 月度奖金在原月度结算）
    $monthly = ps_summary($order('网站模板', 109716, 109716), [$approved(42440)], [['employee_id' => 1, 'commission_group' => 'technical', 'group_weight' => 1, 'role_name' => '模板技术']]);
    check_algorithm(algorithm_person($monthly, 'technical'), 8317.99, '光君 8 月模板提成');

    // 4. AI 网站定制：主单 1000 + SSL 追加 200（SSL 30），使用域名 80；前后端各担 50% 成本
    $custom = ps_summary($order('AI网站定制', 1200, 1200), [$approved(80), $approved(30)], [
        ['employee_id' => 1, 'commission_group' => 'technical', 'group_weight' => 0.5, 'role_name' => '前端（技术）'],
        ['employee_id' => 2, 'commission_group' => 'technical', 'group_weight' => 0.5, 'role_name' => '后端'],
        ['employee_id' => 3, 'commission_group' => 'customer_service', 'group_weight' => 1, 'role_name' => '客服'],
    ]);
    check_algorithm(algorithm_person($custom, 'technical', 0), 139.49, '定制前端 (1200−6%−55)×13%');
    check_algorithm(algorithm_person($custom, 'technical', 1), 107.30, '定制后端 (1200−6%−55)×10%');
    check_algorithm(algorithm_person($custom, 'customer_service'), 38.40, '定制客服：博山定制成本按售价 65%，(1200−780−3%)×10%');
    $outsourced = ps_summary($order('AI网站定制', 1200, 1200), [$approved(80)], [['employee_id' => 1, 'commission_group' => 'technical', 'group_weight' => 1, 'role_name' => '外包前端']]);
    check_algorithm(algorithm_person($outsourced, 'technical'), 224.00, '外包前端不扣服务费 20%');

    // 5. 环境配置 15%，服务费 3%
    $env = ps_summary($order('环境配置', 500, 500), [], [['employee_id' => 1, 'commission_group' => 'technical', 'group_weight' => 1, 'role_name' => '技术']]);
    check_algorithm(algorithm_person($env, 'technical'), 72.75, '环境配置 (500−15)×15%');

    // 6. 小程序：新订单 5% + 20；续费客服不计；定制技术 30% + 20，定制客服 10% + 10 且低于 50 元不计
    $mpPeople = [['employee_id' => 1, 'commission_group' => 'technical', 'group_weight' => 1, 'role_name' => '制作技术'], ['employee_id' => 2, 'commission_group' => 'customer_service', 'group_weight' => 1, 'role_name' => '客服']];
    $mpNew = ps_summary($order('小程序开发', 350, 350, '新订单'), [], $mpPeople);
    check_algorithm(algorithm_person($mpNew, 'technical'), 36.98, '小程序技术新订单');
    check_algorithm(algorithm_person($mpNew, 'customer_service'), 36.98, '小程序客服新订单');
    $mpRenew = ps_summary($order('小程序开发', 100, 100, '续费'), [], $mpPeople);
    check_algorithm(algorithm_person($mpRenew, 'customer_service'), 0, '续费客服暂不核算');
    check_algorithm(algorithm_person($mpRenew, 'technical'), 4.85, '续费技术 5%');
    $mpCustom = ps_summary($order('小程序开发', 2100, 2100, '定制'), [], [['employee_id' => 1, 'commission_group' => 'technical', 'group_weight' => 1, 'role_name' => '定制技术'], ['employee_id' => 2, 'commission_group' => 'customer_service', 'group_weight' => 1, 'role_name' => '定制客服']]);
    check_algorithm(algorithm_person($mpCustom, 'technical'), 631.10, '小程序定制技术 30%+20');
    check_algorithm(algorithm_person($mpCustom, 'customer_service'), 213.70, '小程序定制客服 10%+10');
    $mpSmall = ps_summary($order('小程序开发', 40, 40, '定制'), [], [['employee_id' => 2, 'commission_group' => 'customer_service', 'group_weight' => 1, 'role_name' => '定制客服']]);
    check_algorithm(algorithm_person($mpSmall, 'customer_service'), 0, '售价低于 50 元不计');
    // 8 月石凯新：收入 4588 → (4588−3%)×5% = 222.52，与核对表一致
    $mpMonth = ps_summary($order('小程序开发', 4588, 4588), [], [['employee_id' => 1, 'commission_group' => 'technical', 'group_weight' => 1, 'role_name' => '制作技术']]);
    check_algorithm(algorithm_person($mpMonth, 'technical'), 222.52, '石凯新 8 月基础提成');

    // 7. 默认岗位：外包前端自动套用 20% 规则
    $pdo->prepare("INSERT INTO project_employee_roles (employee_id,business_name,commission_group,role_name) VALUES (?,'AI网站定制','technical','外包前端')")->execute([$employeeIds[0]]);
    if (ps_employee_default_role($employeeIds[0], 'AI网站定制', 'technical') !== '外包前端') throw new RuntimeException('默认岗位未生效');

    // 8. 端到端：网站模板订单确认程序套餐 → 审核生成快照（含服务费与计算说明）
    $no = 'ALGO-' . bin2hex(random_bytes(5));
    $pdo->prepare("INSERT INTO project_orders (order_no,project_type,contract_amount,order_date,delivery_status) VALUES (?,'网站模板',998,?, 'finished')")->execute([$no, $date]);
    $orderId = (int)$pdo->lastInsertId();
    ps_intake_participants($orderId, ['technical' => [['id' => $employeeIds[1], 'role' => '模板技术']], 'customer_service' => [['id' => $employeeIds[2], 'role' => '客服']]], '网站模板');
    ps_intake_save_resources($orderId, 'manual', null, null, null, null, 'pending');
    $jsp = null;
    foreach ($programs as $programTemplate) if ($programTemplate['name'] === 'JSP展示中级版' && $programTemplate['specification'] === '1年 空间+域名') $jsp = $programTemplate;
    ps_intake_confirm_resources($orderId, '', 0, 0, $actor, $jsp['id']);
    $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,review_status,submitted_by_type,submitted_by_id) VALUES (?,'receipt',998,'approved','system',0)")->execute([$orderId]);
    ps_recalculate_cash($orderId);
    ps_approve_order($orderId, $actor, '2099-11');
    $snap = $pdo->prepare('SELECT commission_group,commission_amount,service_fee,calc_mode,calc_note FROM project_commission_snapshots WHERE order_id=? ORDER BY commission_group');
    $snap->execute([$orderId]);
    $rows = $snap->fetchAll(PDO::FETCH_UNIQUE);
    check_algorithm($rows['technical']['commission_amount'], 79.05, '快照：模板技术');
    check_algorithm($rows['customer_service']['commission_amount'], 48.64, '快照：模板客服');
    check_algorithm($rows['technical']['service_fee'], 29.94, '快照：服务费');
    $supplier = $pdo->prepare("SELECT supplier_amount,amount FROM project_costs WHERE order_id=? AND category='program'");
    $supplier->execute([$orderId]);
    $programCost = $supplier->fetch();
    check_algorithm($programCost['amount'], 360, '程序套餐核算成本');
    check_algorithm($programCost['supplier_amount'], 260, '程序套餐采购价');

    // 程序套餐为成本中心固定价：超过 ¥500 也自动通过，不必逐单审核。
    $bigNo = 'ALGO-BIG-' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO project_orders (order_no,project_type,contract_amount,order_date) VALUES (?,'网站模板',1500,?)")->execute([$bigNo, $date]);
    $bigId = (int)$pdo->lastInsertId();
    $fiveYear = ps_intake_program_suggestion('5年JSP展示中级版', $programs);
    ps_intake_confirm_resources($bigId, '', 0, 0, $actor, $fiveYear['id']);
    $bigCost = $pdo->prepare('SELECT amount,review_status FROM project_costs WHERE order_id=?');
    $bigCost->execute([$bigId]);
    $bigRow = $bigCost->fetch();
    if ((float)$bigRow['amount'] !== 950.0 || $bigRow['review_status'] !== 'approved') throw new RuntimeException('950 元程序套餐应按成本中心价自动通过');

    // 9. 小额引流：售价 10 全额退差价，实收为 0，仍按每单 3 元补助结算
    $small = 'DRAIN-' . bin2hex(random_bytes(5));
    $pdo->prepare("INSERT INTO project_orders (order_no,project_type,contract_amount,order_date,delivery_status) VALUES (?,'小额引流',10,?,'finished')")->execute([$small, $date]);
    $smallId = (int)$pdo->lastInsertId();
    ps_intake_participants($smallId, ['technical' => [], 'customer_service' => [['id' => $employeeIds[2], 'role' => '客服']]], '小额引流');
    ps_approve_order($smallId, $actor, '2099-11');
    $smallSnap = $pdo->prepare('SELECT commission_amount,subsidy_amount FROM project_commission_snapshots WHERE order_id=?');
    $smallSnap->execute([$smallId]);
    $smallRow = $smallSnap->fetch();
    check_algorithm($smallRow['commission_amount'], 3, '小额引流补助');
    check_algorithm($smallRow['subsidy_amount'], 3, '小额引流补助明细');

    // 客户联系方式：本单参与人看完整、不在本单的人打码；管理员可设为某角色始终打码；财务始终完整
    $staffActor = ['role' => 'technical'];
    $contact = '客户联系方式：15559193252，jack@yingshangmodular.com';
    if (ps_contact_for($staffActor, $contact, false, true) !== $contact) throw new RuntimeException('本单参与人应看到完整联系方式');
    if (ps_contact_for($staffActor, $contact, false, false) !== '客户联系方式：155***3252，ja***@yingshangmodular.com') throw new RuntimeException('非本单人员手机号/邮箱未打码');
    if (ps_contact_for($staffActor, 'lucky_wx88', true, false) !== 'lu***88' || ps_contact_for($staffActor, '微信：abc_def123', false, false) !== '微信：ab***23') throw new RuntimeException('微信号未打码');
    ps_setting_set('contact_visibility', ['technical' => 'masked', 'customer_service' => 'participant'], $adminId);
    if (ps_contact_for($staffActor, '15559193252', false, true) !== '155***3252') throw new RuntimeException('设为始终打码后参与人仍看到完整号码');
    if (ps_contact_for(['role' => 'customer_service'], '15559193252', false, true) !== '15559193252') throw new RuntimeException('客服按角色设置应看到完整号码');
    if (ps_contact_for($actor, '15559193252', false, false) !== '15559193252') throw new RuntimeException('财务应看到完整联系方式');
    if (ps_contact_for($staffActor, '订单 3315896257497005056', false, false) !== '订单 3315896257497005056') throw new RuntimeException('订单号不应被打码');
    // AI 返回解析：兼容代码块与前后说明
    $parsed = ps_ai_json("好的：
```json
{\"order_no\": \"5127194379715039218\", \"contract_amount\": \"998\"}
```");
    if (($parsed['order_no'] ?? '') !== '5127194379715039218') throw new RuntimeException('AI JSON 解析失败');
    if (ps_ai_endpoint('https://token.example.com', '/chat/completions') !== 'https://token.example.com/v1/chat/completions' || ps_ai_endpoint('https://x.com/v1/', '/models') !== 'https://x.com/v1/models') throw new RuntimeException('AI 接口地址拼接错误');

    // 11. 售后退款调整：模板单 998 已审核（技术 79.05 / 客服 48.64）后全额退款 → 各自扣回，原快照不变
    $pdo->prepare("UPDATE project_payroll_periods SET status='locked' WHERE period='2099-11'")->execute();
    if (ps_next_open_month('2099-11') !== '2099-12') throw new RuntimeException('下一个未锁定月份计算错误');
    $created = ps_post_adjustment($orderId, $actor, 998, -360, '客户全额退款，套餐成本退回', '2099-12');
    $adj = $pdo->prepare('SELECT commission_group,amount FROM project_commission_adjustments WHERE order_id=? ORDER BY commission_group');
    $adj->execute([$orderId]);
    $adjRows = $adj->fetchAll(PDO::FETCH_KEY_PAIR);
    check_algorithm($adjRows['technical'] ?? 0, -79.05, '全额退款扣回技术分成');
    check_algorithm($adjRows['customer_service'] ?? 0, -48.64, '全额退款扣回客服分成');
    $snap->execute([$orderId]);
    $afterSnap = $snap->fetchAll(PDO::FETCH_UNIQUE);
    check_algorithm($afterSnap['technical']['commission_amount'], 79.05, '原快照保持不变');
    try { ps_post_adjustment($orderId, $actor, 1, 0, '再退', '2099-11'); throw new RuntimeException('已锁定月份不应接受调整'); } catch (RuntimeException $expected) { if (strpos($expected->getMessage(), '已锁定') === false) throw $expected; }
    // 部分退款：小程序新订单 350（5% + 补助 20）退 100 → 只扣差额，补助保留
    $mpNo = 'ALGO-MP-' . bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO project_orders (order_no,project_type,order_kind,contract_amount,order_date,delivery_status) VALUES (?,'小程序开发','新订单',350,?,'finished')")->execute([$mpNo, $date]);
    $mpId = (int)$pdo->lastInsertId();
    ps_intake_participants($mpId, ['technical' => [], 'customer_service' => [['id' => $employeeIds[2], 'role' => '客服']]], '小程序开发');
    ps_intake_save_resources($mpId, 'manual', null, null, null, null, 'none');
    $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,review_status,submitted_by_type,submitted_by_id) VALUES (?,'receipt',350,'approved','system',0)")->execute([$mpId]);
    ps_recalculate_cash($mpId);
    ps_approve_order($mpId, $actor, '2099-12');
    ps_post_adjustment($mpId, $actor, 100, 0, '部分退款', '2099-12');
    $adj->execute([$mpId]);
    $mpAdj = $adj->fetchAll(PDO::FETCH_KEY_PAIR);
    check_algorithm($mpAdj['customer_service'] ?? 0, -5.00, '部分退款只扣 100×5% 差额，补助保留');

    // 12. 华梦外包：成本 = 售价 × 80%，自动通过
    $hm = null;
    foreach (ps_intake_templates('outsourcing', 'AI网站定制') as $t) if ($t['name'] === '华梦定制外包') $hm = $t;
    if (!$hm) throw new RuntimeException('未导入华梦外包模板');
    [$hmUnit, $hmAmount] = ps_template_cost_amount($hm, 3800);
    check_algorithm($hmAmount, 3040, '华梦外包 3800 × 80%');
    if (ps_template_cost_status($hm, $hmAmount) !== 'approved') throw new RuntimeException('外包比例成本应按成本中心价自动通过');
    $hmOrder = ps_summary($order('AI网站定制', 3800, 3800), [$approved(3040)], [['employee_id' => 1, 'commission_group' => 'customer_service', 'group_weight' => 1, 'role_name' => '客服']]);
    check_algorithm(algorithm_person($hmOrder, 'customer_service'), 64.60, '华梦单客服 (3800−3040−3%)×10%');

    // 13. 重名：两位“孙湉湉”，按已开通账号且能做当前业务的那位唯一确定
    $index = ['孙湉湉' => [['id' => 101, 'name' => '孙湉湉', 'department' => '客服', 'has_account' => 0, 'businesses' => []], ['id' => 102, 'name' => '孙湉湉', 'department' => '网站客服', 'has_account' => 1, 'businesses' => ['网站模板', 'AI网站定制']]]];
    if (array_keys(ps_import_names('孙湉湉', $index, '网站模板')) !== [102]) throw new RuntimeException('重名未按已开通账号确定');
    $index['孙湉湉'][1]['has_account'] = 0;
    if (array_keys(ps_import_names('孙湉湉', $index, '网站模板')) !== [102]) throw new RuntimeException('重名未按部门对应业务确定');
    try { ps_import_names('孙湉湉', $index, '设计'); throw new RuntimeException('无法区分的重名应报错'); } catch (RuntimeException $expected) { if (strpos($expected->getMessage(), '重名') === false) throw $expected; }
    if (array_keys(ps_import_names('软件开发部李仁超', ['李仁超' => [['id' => 7, 'name' => '李仁超']]])) !== [7]) throw new RuntimeException('“软件开发部”前缀未去除');

    // 10. 标签与待办
    if (ps_label('category', 'program') !== '程序套餐' || ps_label('settlement', 'approved') !== '已审核') throw new RuntimeException('中文标签错误');
    $todos = ps_order_todos(['project_type' => '网站模板', 'settlement_status' => 'draft', 'price_source' => 'missing', 'domain_mode' => 'pending', 'tech_count' => 0, 'pending_costs' => 1, 'pending_cash' => 0, 'receipt_amount' => 0, 'delivery_status' => 'unfinished']);
    if (count($todos) !== 6) throw new RuntimeException('待办识别数量错误：' . count($todos));

    $pdo->rollBack();
    echo "程序表成本、网站模板/定制/环境配置/小程序分成口径与 8 月核对表一致；数据已回滚\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
