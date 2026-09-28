<?php
// 微信代写：账号可上传 / 录入、编辑订单补助 9 月起 3 元、部门利润池 13% 与利润奖励（6 万以上每 1 万每人 100，封顶 1300）。
// 事务内执行，结束回滚。php tests/wechat_writing_smoke.php（上传预览需 订单模板与成本及算法/微信代写部门/8月微信代写编辑订单.xlsx）
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';

$pdo = db();
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
    $check((float)ps_rule_for('technical', '软文代写', '2026-08-20', '', '')['per_order_subsidy'] === 2.5, '8 月订单：2.5 元/单（与 8 月汇总表一致）');
    $check((float)ps_rule_for('technical', '软文代写', '2026-09-05', '', '')['per_order_subsidy'] === 3.0, '9 月起订单：3 元/单（核算标准）');
    $check((float)ps_rule_for('technical', '软文代写', '2026-09-05', '', '合并单')['per_order_subsidy'] === 0.0, '合并单仍不计补助');

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
    $pool = (75000 - 6000) * 0.10;
    $check(abs(($r['微信代写部门利润池'][$E('姚鹏')] ?? 0) - $pool * 0.13) < 0.01 && abs(($r['微信代写部门利润池'][$E('李雪')] ?? 0) - $pool * 0.13) < 0.01, '部门利润 75000：姚鹏、李雪各拿总提成 ' . $pool . ' × 13% = ' . round($pool * 0.13, 2));
    $check(abs(($r['微信代写部门利润池'][$E('韩菲菲')] ?? 0) - $pool * 0.68 / 3) < 0.01, '编辑按毛利占比分 68%（各 1/3）');
    $bonus = $r['微信代写部门利润池 · 利润奖励'] ?? [];
    $check(count($bonus) === 5 && !array_filter($bonus, function ($v) { return $v !== 100.0; }), '利润 75000（超 6 万满 1 个 1 万）：5 人每人 +100');
    $make('2027-01', 86000);
    $bonus = $lines('2027-01')['微信代写部门利润池 · 利润奖励'] ?? [];
    $check(count($bonus) === 5 && !array_filter($bonus, function ($v) { return $v !== 1300.0; }), '利润 258000：每人封顶 1300');

    echo "=== 上传编辑订单表（预览）===\n";
    $file = __DIR__ . '/../订单模板与成本及算法/微信代写部门/8月微信代写编辑订单.xlsx';
    if (is_file($file)) {
        $u = $users['韩菲菲'];
        $stored = ps_private_store('imports', $file, 'wx_test_' . bin2hex(random_bytes(5)) . '.xlsx');
        $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('微信代写','8月微信代写编辑订单.xlsx',?,?,'employee',?,?)")->execute([$stored, filesize($file), $u['id'], $u['employee_id']]);
        $fileId = (int)$pdo->lastInsertId();
        $_SESSION = ['project_user_id' => (int)$u['id'], 'project_csrf' => 'test-csrf'];
        $_SERVER['SCRIPT_NAME'] = '/project/import.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['csrf' => 'test-csrf', 'action' => 'repreview', 'business' => '微信代写', 'file_id' => $fileId, 'all_sheets' => 1];
        $error = ''; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean();
        $preview = $_SESSION['project_import_preview'] ?? [];
        $valid = count(array_filter($preview, function ($x) { return !empty($x['base_valid']); }));
        $sheets = implode('、', array_keys(array_filter($_SESSION['project_import_sheets'] ?? [], function ($s) { return !empty($s['used']); })));
        $check($error === '' && $valid > 0, '韩菲菲上传 8 月编辑订单表：读取分表 ' . $sheets . '，' . count($preview) . ' 单中 ' . $valid . ' 单可导入');
    } else echo "  （缺少样表，跳过上传预览）\n";

    $pdo->rollBack();
    echo "\n=== 微信代写全部通过，测试数据已回滚 ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    if ($stored !== null) @unlink(ps_private_dir('imports') . '/' . basename($stored) . '.php');
}
