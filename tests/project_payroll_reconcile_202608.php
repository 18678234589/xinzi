<?php
/**
 * 2026 年 8 月对账：把《订单模板与成本及算法》8 月示例订单（按各人核对表的汇总行）录入系统，
 * 用规则中心的逐单规则 + 月度规则（固定服务费、全勤、奖金、排名、补助、主管提成）计算，
 * 与《2026.8月合作云兼职份收入表》的“应发工资”逐人比对。全部在事务中执行并回滚。
 *
 * 标注“手工”的部分是收入表里有、但示例订单文件中没有明细的业务（标书、续费、督导、代写等），
 * 通过规则中心“其他业务提成（未接入系统）”每月填写。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectPresets.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';

$pdo = db();
// 原系统考勤表通常已存在；空库时先建表（DDL 须在事务外执行）
$pdo->exec("CREATE TABLE IF NOT EXISTS attendances (id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, year SMALLINT NOT NULL, month TINYINT NOT NULL, work_hours DECIMAL(6,1) NOT NULL DEFAULT 0, absent_hours DECIMAL(6,1) NOT NULL DEFAULT 0, remark VARCHAR(500) DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_emp_month (employee_id, year, month)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->beginTransaction();
$month = '2026-08';
try {
    $adminId = (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
    $actor = ['type' => 'admin', 'id' => $adminId, 'employee_id' => null, 'role' => 'finance'];
    $pdo->prepare("DELETE FROM project_payroll_periods WHERE period=?")->execute([$month]);
    $pdo->exec('UPDATE project_commission_rules SET is_active=0');
    $pdo->exec('UPDATE project_monthly_rules SET is_active=0');
    $pdo->prepare('DELETE FROM project_monthly_inputs WHERE payroll_month=?')->execute([$month]);
    $pdo->prepare('DELETE s FROM project_commission_snapshots s WHERE s.payroll_month=?')->execute([$month]);

    $people = [];
    $employee = function ($name, $department) use ($pdo, &$people) {
        if (isset($people[$name])) return $people[$name];
        $q = $pdo->prepare('SELECT id FROM employees WHERE name=? ORDER BY id');
        $q->execute([$name]);
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) > 1) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=? AND department=?'); $q->execute([$name, $department]); $ids = $q->fetchAll(PDO::FETCH_COLUMN) ?: $ids; }
        if (!$ids) { $pdo->prepare("INSERT INTO employees (name,department,password) VALUES (?,?,'')")->execute([$name, $department]); $ids = [(int)$pdo->lastInsertId()]; }
        return $people[$name] = (int)$ids[0];
    };
    foreach (['光君' => '网站技术', '张强' => '网站技术', '孙妍' => '网站技术', '董旭' => '网站客服', '宋倩倩' => '网站客服', '苏婷' => '网站客服', '孙湉湉' => '网站客服', '刘媛媛' => '网站售后部', '吴宁' => '督导', '刘帅' => '定制前端', '于海波' => '定制后端', '崔鑫栋' => '定制后端', '李子晖' => '网站定制', '李仁超' => '定制前端', '孙磊' => '定制前端', '纪鹏程' => '环境配置', '于洋' => '网站售后部', '翟建跃' => '运营经理部', '石凯新' => '标书小程序', '刘丹丹' => '标书小程序', '曹双双' => '标书小程序', '王宁' => '标书小程序', '王亚' => '标书小程序', '朱俊英' => '代写客服', '对账合接客服' => '网站客服'] as $name => $department) $employee($name, $department);
    $E = function ($name) use (&$people) { return $people[$name]; };

    ps_apply_preset_rules($actor, '2026-07-01');
    [, $skipped] = ps_monthly_apply_presets($actor, '2026-07');
    if ($skipped) throw new RuntimeException('月度预置未导入：' . implode('、', $skipped));

    // 考勤（小时）：8 月满勤 26 天；苏婷 23 天、李子晖 25.77 天、吴宁 24.81 天
    $attendance = $pdo->prepare('INSERT INTO attendances (employee_id,year,month,work_hours,absent_hours) VALUES (?,2026,8,208,?) ON DUPLICATE KEY UPDATE work_hours=208,absent_hours=VALUES(absent_hours)');
    foreach ($people as $name => $id) if (!in_array($name, ['朱俊英', '李仁超', '孙磊', '对账合接客服'], true)) $attendance->execute([$id, ['苏婷' => 24, '李子晖' => 1.84, '吴宁' => 9.52][$name] ?? 0]);

    $n = 0;
    $order = function ($type, $kind, $price, array $costs, array $tech, array $cs) use ($pdo, $actor, $month, &$n) {
        $no = 'RECON-2608-' . (++$n);
        $pdo->prepare("INSERT INTO project_orders (order_no,project_type,order_kind,contract_amount,order_date,delivery_status) VALUES (?,?,?,?,'2026-08-15','finished')")->execute([$no, $type, $kind, $price]);
        $id = (int)$pdo->lastInsertId();
        $groups = ['technical' => [], 'customer_service' => []];
        foreach ($tech as [$eid, $role]) $groups['technical'][] = ['id' => $eid, 'role' => $role];
        foreach ($cs as $eid) $groups['customer_service'][] = ['id' => $eid, 'role' => '客服'];
        ps_intake_participants($id, $groups);
        if (!empty(ps_business_catalog()[$type]['resources'])) ps_intake_save_resources($id, 'manual', null, null, null, null, 'none');
        foreach ($costs as $amount) $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,review_status) VALUES (?,'other','8月核对表成本',1,'项',?,?,'approved')")->execute([$id, $amount, $amount]);
        if ($price > 0) {
            $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,review_status,submitted_by_type,submitted_by_id) VALUES (?,'receipt',?,'approved','system',0)")->execute([$id, $price]);
            ps_recalculate_cash($id);
        }
        ps_approve_order($id, $actor, $month);
    };
    $partner = $E('对账合接客服');
    // —— 网站模板技术（光君 / 孙妍 / 张强）：售价、域名空间成本按各人核对表合计
    $order('网站模板', '新订单', 109716, [42440], [[$E('光君'), '模板技术']], []);
    $order('网站模板', '新订单', 72364, [31209.5], [[$E('张强'), '模板技术']], []);
    $order('网站模板', '新订单', 60670, [26075], [[$E('孙妍'), '模板技术']], []);
    // —— 网站客服：博山模板主次 / 博山模板 / 华梦博山定制
    foreach ([['董旭', 1948, 820, 75965, 30285, 43850, 32912.5], ['宋倩倩', 900, 360, 68808, 26829.5, 19050, 12652.5], ['苏婷', 250, 100, 57037, 23035, 34013, 24555.2], ['孙湉湉', 798, 360, 40694, 16955, 28010, 19226.5]] as [$name, $p1, $c1, $p2, $c2, $p3, $c3]) {
        $order('网站模板', '新订单', $p1, [$c1], [], [$E($name), $partner]);
        $order('网站模板', '新订单', $p2, [$c2], [], [$E($name)]);
        $order('AI网站定制', '', $p3, [$c3], [], [$E($name)]);
    }
    $order('网站模板', '新订单', 200, [100], [], [$E('刘媛媛')]);
    // —— 网站定制技术（个人独立计提；域名 / SSL 为本人承担部分）
    $order('AI网站定制', '', 27550, [200, 195], [[$E('刘帅'), '前端（技术）']], []);
    $order('AI网站定制', '', 19950, [160, 180], [[$E('于海波'), '后端']], []);
    $order('AI网站定制', '', 46828, [480, 360], [[$E('崔鑫栋'), '后端']], []);
    $order('AI网站定制', '', 5180, [0, 490], [[$E('李子晖'), '售后']], []);
    $order('AI网站定制', '', 24178, [440, 90], [[$E('李仁超'), '外包前端']], []);
    $order('AI网站定制', '', 15450, [80, 255], [[$E('孙磊'), '外包前端']], []);
    // —— 环境配置：纪鹏程为全部订单客服，技术各自计 15%
    $order('环境配置', '', 2000, [], [[$E('崔鑫栋'), '技术']], [$E('纪鹏程')]);
    $order('环境配置', '', 2500, [], [[$E('孙磊'), '技术']], [$E('纪鹏程')]);
    $order('环境配置', '', 4740, [30], [[$E('纪鹏程'), '技术']], [$E('纪鹏程')]);
    // —— 小程序：石凯新 新订单 3288（15 单补助）+ 续费 1200 + 技术服务 100；刘丹丹 新订单 1350（6 单）
    for ($i = 0; $i < 15; $i++) $order('小程序开发', '新订单', $i < 14 ? 219 : 222, [], [[$E('石凯新'), '制作技术']], []);
    $order('小程序开发', '续费', 1200, [], [[$E('石凯新'), '制作技术']], []);
    $order('小程序开发', '技术服务', 100, [], [[$E('石凯新'), '制作技术']], []);
    for ($i = 0; $i < 6; $i++) $order('小程序开发', '新订单', 225, [], [[$E('刘丹丹'), '制作技术']], []);
    // 客服：曹双双 新订单 3388（13 单）+ 定制 3978 + 技术服务 100；王宁 新订单 1050（7 单）+ 技术服务 450 + 定制 810；朱俊英 新订单 100
    for ($i = 0; $i < 13; $i++) $order('小程序开发', '新订单', $i < 12 ? 260 : 268, [], [], [$E('曹双双')]);
    $order('小程序开发', '定制', 3978, [], [], [$E('曹双双')]);
    $order('小程序开发', '技术服务', 100, [], [], [$E('曹双双')]);
    for ($i = 0; $i < 7; $i++) $order('小程序开发', '新订单', 150, [], [], [$E('王宁')]);
    $order('小程序开发', '技术服务', 450, [], [], [$E('王宁')]);
    $order('小程序开发', '定制', 810, [], [], [$E('王宁')]);
    $order('小程序开发', '新订单', 100, [], [], [$E('朱俊英')]);
    $order('小程序开发', '续费', 1200, [], [], [$E('王亚')]);
    // 小额引流：曹双双 30 单、王宁 38 单，每单 3 元
    for ($i = 0; $i < 30; $i++) $order('小额引流', '', 0, [], [], [$E('曹双双')]);
    for ($i = 0; $i < 38; $i++) $order('小额引流', '', 0, [], [], [$E('王宁')]);

    // —— 本月填写项（规则中心“本月试算”）
    $ruleId = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM project_monthly_rules WHERE name=? AND is_active=1 ORDER BY id DESC LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); };
    $input = $pdo->prepare('INSERT INTO project_monthly_inputs (payroll_month,rule_id,employee_id,value,note) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),note=VALUES(note)');
    $fill = function ($rule, $name, $value, $note = '') use ($input, $month, $ruleId, $E) { $input->execute([$month, $ruleId($rule), $name === null ? 0 : $E($name), $value, $note]); };
    foreach (['董旭' => 333, '宋倩倩' => 441, '苏婷' => 366, '孙湉湉' => 318] as $name => $amount) $fill($name . ' 固定服务费', $name, $amount, '补单提成');
    foreach (['宋倩倩' => 1, '董旭' => 2, '苏婷' => 3] as $name => $pos) $fill('网站客服排名奖', $name, $pos);
    $fill('其他调整', '宋倩倩', 7.28, '上月漏记小程序提成');
    $fill('孙妍 全勤奖', '孙妍', 0, '8 月申请在家上班无全勤奖');
    foreach (['李仁超' => 5, '李子晖' => 13, '崔鑫栋' => 2] as $name => $count) $fill('优站模板奖励', $name, $count);
    $fill('环境配置主管提成', null, 1500, '服务器员工底薪');
    $fill('朱俊英 固定服务费', '朱俊英', 1713.33, '小程序 1380 + 代写（月中调岗）');
    $fill('朱俊英 全勤奖', '朱俊英', 0, '有请假');
    $manual = ['刘媛媛' => [2920.83, '网站续费提成'], '吴宁' => [750, '督导提成'], '曹双双' => [1125.80, '标书提成'], '王宁' => [777.85, '标书提成'], '王亚' => [5903.63, '续费等提成'], '朱俊英' => [214.47, '代写提成']];
    foreach ($manual as $name => [$amount, $note]) $fill('其他业务提成（未接入系统）', $name, $amount, $note);

    // —— 逐人合计 vs 收入表“应发工资”
    $expected = ['光君' => 10127.76, '张强' => 6502.62, '孙妍' => 5502.36, '董旭' => 5484.21, '宋倩倩' => 5055.21, '苏婷' => 4129.26, '孙湉湉' => 3117.91, '刘媛媛' => 3928.35, '吴宁' => 2478.60, '刘帅' => 5815.26, '于海波' => 4341.30, '崔鑫栋' => 7338.83, '李子晖' => 3830.41, '李仁超' => 4804.60, '孙磊' => 3386.75, '纪鹏程' => 3078.45, '于洋' => 6130.74, '翟建跃' => 5900.00, '石凯新' => 3222.52, '刘丹丹' => 2385.48, '曹双双' => 3037.90, '王宁' => 2343.89, '王亚' => 9203.63, '朱俊英' => 1952.65];
    $snapshotSum = $pdo->prepare('SELECT COALESCE(SUM(commission_amount),0) FROM project_commission_snapshots WHERE employee_id=? AND payroll_month=?');
    $results = ps_monthly_results($month);
    $failed = 0;
    printf("%-6s %12s %12s %10s  %s\n", '姓名', '系统应结算', '收入表应发', '差额', '其中手工填写');
    foreach ($expected as $name => $target) {
        $id = $E($name);
        $snapshotSum->execute([$id, $month]);
        $total = (float)$snapshotSum->fetchColumn();
        $separate = 0.0;
        foreach ($results as $row) if ((int)$row['employee_id'] === $id) { if (!empty($row['paid_separately'])) $separate += (float)$row['amount']; else $total += (float)$row['amount']; }
        $diff = round($total - $target, 2);
        if (abs($diff) > 0.001) $failed++;
        printf("%-6s %12s %12s %10s  %s%s\n", $name, number_format($total, 2), number_format($target, 2), $diff == 0 ? '0' : number_format($diff, 2), isset($manual[$name]) ? $manual[$name][1] . ' ' . number_format($manual[$name][0], 2) : '', $separate > 0 ? '；另行支付法人补助 ' . number_format($separate, 2) : '');
    }
    $pdo->rollBack();
    if ($failed) { fwrite(STDERR, "有 {$failed} 人与收入表不一致\n"); exit(1); }
    echo "8 月 24 人应结算金额与收入表应发工资逐人一致；数据已回滚\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
