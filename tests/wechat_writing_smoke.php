<?php
// 微信代写：账号可上传 / 录入、编辑订单补助 9 月起 3 元、部门利润池 13% 与利润奖励（6 万以上每 1 万每人 100，封顶 1300）。
// 事务内执行，结束回滚。php tests/wechat_writing_smoke.php（上传预览需 订单模板与成本及算法/微信代写部门/8月微信代写编辑订单.xlsx）
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';

$pdo = db();
require_once __DIR__ . '/require_isolated_database.php';
require_isolated_test_database($pdo);
require_once __DIR__ . '/clean_leftovers.php'; test_clean_leftovers($pdo, ['orders' => ['WXTEST-%', 'SRC%']]); // 上次失败遗留的数据（含 wechat_writing_sources 的 2026-12 订单）
$pdo->beginTransaction();
$stored = null;
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$E = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=?'); $q->execute([$name]); return (int)$q->fetchColumn(); };
try {
    echo "=== 账号 ===\n";
    $users = [];
    foreach (['韩菲菲', '孙梦琦', '张钰琪', '姚鹏', '李雪'] as $name) {
        $q = $pdo->prepare('SELECT id,employee_id,role FROM project_users WHERE employee_id=? AND is_active=1');
        $q->execute([$E($name)]);
        $u = $q->fetch();
        $check($u && in_array('微信代写', ps_actor_businesses(['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role']]), true), $name . '：账号启用，可做“微信代写”');
        $users[$name] = $u;
    }

    echo "=== 编辑订单（对接编辑）补助 ===\n";
    $check((float)ps_rule_for('technical', '软文代写', '2026-08-20', '对接编辑', '')['per_order_subsidy'] === 2.5, '8 月对接编辑：2.5 元/单');
    $check((float)ps_rule_for('technical', '软文代写', '2026-09-05', '对接编辑', '')['per_order_subsidy'] === 2.5, '对接编辑按最新明确口径：2.5 元/单');
    $check((float)ps_rule_for('technical', '软文代写', '2026-09-05', '对接编辑', '合并单')['per_order_subsidy'] === 0.0, '合并单仍不计补助');

    echo "=== 部门利润池与利润奖励 ===\n";
    $finance = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'employee_id' => null, 'role' => 'finance'];
    $make = function ($month, $profitEach) use ($pdo, $E, $finance) {
        foreach (['韩菲菲', '孙梦琦', '张钰琪'] as $i => $name) {
            $no = 'WXTEST-' . $month . '-' . $i;
            $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,note) VALUES (?,'测试','微信代写','店铺订单','微信订单',?,?,?,'finished','测试')")->execute([$no, $profitEach + 1000, $profitEach + 1000, $month . '-05']);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status) VALUES (?,'outsourcing','写手稿费',1,'项',1000,1000,'one_time',1,'测试','approved')")->execute([$id]);
            $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$id, $E($name)]);
            ps_approve_order($id, $finance, $month);
        }
    };
    $lines = function ($month) { $out = []; foreach (ps_monthly_results($month, true) as $r) if (strpos($r['rule_name'], '微信代写部门利润池') === 0) $out[$r['rule_name']][(int)$r['employee_id']] = (float)$r['amount']; return $out; };
    $make('2026-12', 25000);
    $r = $lines('2026-12');
    $pool = (75000 - 5000) * 0.10;
    $check(abs(($r['微信代写部门利润池'][$E('姚鹏')] ?? 0) - $pool * 0.13) < 0.01 && abs(($r['微信代写部门利润池'][$E('李雪')] ?? 0) - $pool * 0.13) < 0.01, '部门利润 75000：姚鹏、李雪各拿总提成 ' . $pool . ' × 13% = ' . round($pool * 0.13, 2));
    $check(abs(($r['微信代写部门利润池'][$E('韩菲菲')] ?? 0) - $pool * 0.68 / 3) < 0.01, '编辑按毛利占比分 68%（各 1/3）');
    $bonus = $r['微信代写部门利润池 · 利润奖励'] ?? [];
    $check(count($bonus) === 5 && !array_filter($bonus, function ($v) { return $v !== 100.0; }), '利润 75000（超 6 万满 1 个 1 万）：5 人每人 +100');
    $make('2027-01', 86000);
    $bonus = $lines('2027-01')['微信代写部门利润池 · 利润奖励'] ?? [];
    $check(count($bonus) === 5 && !array_filter($bonus, function ($v) { return $v !== 1300.0; }), '利润 258000：每人封顶 1300');

    // 已删除“上传 8 月旧编辑订单表预览”：新规则要求自接订单明确淘宝 / 微信、编辑订单标注“编辑对接”，旧表没有这些列，预览必然全是“需处理”；
    // 新口径的上传识别由 tests/wechat_writing_sources.php 覆盖。

    $pdo->rollBack();
    echo "\n=== 微信代写全部通过，测试数据已回滚 ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    if ($stored !== null) @unlink(ps_private_dir('imports') . '/' . basename($stored) . '.php');
}
