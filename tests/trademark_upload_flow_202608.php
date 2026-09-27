<?php
// 商标部 8 月原表按人上传 → 同单合并 → 按规则核算，与 8 月工资表对账；事务内执行，结束回滚。
// php tests/trademark_upload_flow_202608.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectDepartmentImport.php';

$dir = __DIR__ . '/../订单模板与成本及算法/商标部门/8月商标工资/';
$uploads = [['yuna', '于娜8月.xlsx'], ['sunrongzi', '孙荣姿8月.xlsx'], ['qintingting', '秦婷婷8月.xlsx'], ['wangqingmei', '王庆美8月.xlsx'], ['wanghuizi', '王慧资8月.xlsx']];
$pdo = db();
register_shutdown_function(function () { $o = ob_get_level() ? ob_get_clean() : ""; if ($o !== "") echo "
[exit output] " . mb_substr(strip_tags($o), 0, 400) . "
"; });
$pdo->beginTransaction();
$stored = [];
try {
    $pdo->exec(file_get_contents(__DIR__ . '/../migrations/20260927_trademark_same_customer.sql')); // 同客户规则（未迁移时在事务内补上）
    foreach ($uploads as [$username, $file]) {
        $q = $pdo->prepare('SELECT id,employee_id,role FROM project_users WHERE username=? AND is_active=1');
        $q->execute([$username]);
        $user = $q->fetch();
        if (!$user) throw new RuntimeException("缺少账号 $username");
        $actor = ['type' => 'employee', 'id' => (int)$user['id'], 'employee_id' => (int)$user['employee_id'], 'role' => $user['role']];
        $name = 'trademark_flow_' . bin2hex(random_bytes(5)) . '.xlsx';
        $stored[] = ps_private_store('imports', $dir . $file, $name);
        $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('商标',?,?,?,'employee',?,?)")
            ->execute([$file, end($stored), filesize($dir . $file), $actor['id'], $actor['employee_id']]);
        $fileId = (int)$pdo->lastInsertId();
        $_SESSION = ['project_user_id' => $actor['id']];
        $_SERVER['SCRIPT_NAME'] = '/project/import.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['csrf' => ps_csrf_token(), 'action' => 'repreview', 'business' => '商标', 'file_id' => $fileId, 'all_sheets' => 1];
        $error = ''; $imported = 0; $skipped = 0;
        ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean();
        if ($error !== '') throw new RuntimeException("$file 预览失败：$error");
        $preview = $_SESSION['project_import_preview'] ?? [];
        $bad = array_values(array_filter($preview, function ($r) { return empty($r['base_valid']); }));
        $sheets = implode('、', array_keys(array_filter($_SESSION['project_import_sheets'] ?? [], function ($s) { return !empty($s['used']); })));
        echo "== {$file}（{$sheets}）预览 " . count($preview) . " 单，需处理 " . count($bad) . "\n";
        $ownOrders[$username] = array_column(array_filter($preview, function ($r) { return !empty($r['base_valid']); }), 'order_no');
        foreach (array_slice($bad, 0, 8) as $r) echo "   第 {$r['line']} 行 {$r['order_no']}：{$r['error']}\n";
        $_POST = ['csrf' => ps_csrf_token(), 'action' => 'commit', 'business' => '商标'];
        $error = ''; $imported = 0; $skipped = 0;
        ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean();
        if ($error !== '') throw new RuntimeException("$file 提交失败：$error");
        echo "   导入 $imported 单，跳过 $skipped\n";
    }

    $totals = [];
    $usernames = ['于娜' => 'yuna', '孙荣姿' => 'sunrongzi', '秦婷婷' => 'qintingting'];
    $orders = $pdo->query("SELECT * FROM project_orders WHERE project_type='商标'")->fetchAll();
    $kinds = [];
    foreach ($orders as $order) {
        // 按售价试算（导入不产生实收）：与工资表“售价”口径一致
        $order['receipt_amount'] = $order['contract_amount'];
        $kinds[$order['order_kind']] = ($kinds[$order['order_kind']] ?? 0) + 1;
        $sum = ps_summary($order, ps_costs((int)$order['id']), ps_participants((int)$order['id']));
        foreach ($sum['groups'] as $group) foreach ($group['people'] as $p) {
            if (empty($p['estimated_calc'])) continue;
            // 客服只对账本人上传表里的订单（资料 / 提交专员表里还有别月、别人的单）
            if ($p['commission_group'] === 'customer_service' && !in_array($order['order_no'], $ownOrders[$usernames[$p['name']] ?? ''] ?? [], true)) continue;
            $t = &$totals[$p['name'] ?? $p['employee_id']];
            $t['share'] = ($t['share'] ?? 0) + $p['estimated_calc']['share'];
            $t['subsidy'] = ($t['subsidy'] ?? 0) + $p['estimated_calc']['subsidy'];
            $t['orders'] = ($t['orders'] ?? 0) + 1;
            unset($t);
        }
    }
    echo "\n订单 " . count($orders) . " 张，类型：" . json_encode($kinds, JSON_UNESCAPED_UNICODE) . "\n";
    $expected = ['于娜' => '提成 1812.54 + 单量 195 + 新客 6（66 位客户，新客户原表未标注）', '孙荣姿' => '提成 1644.41 + 单量 135 + 新客 24 + 小额 3（49 位客户，新客户原表未标注）', '王庆美' => '资料 1529（695 件）', '王慧资' => '提交 1548.80（704 件）', '秦婷婷' => '见个人表'];
    foreach ($totals as $who => $t) printf("  %s：提成 %.2f，补助 %.2f（%d 单）；8 月表：%s\n", $who, $t['share'], $t['subsidy'], $t['orders'], $expected[$who] ?? '-');
    $pdo->rollBack();
    echo "\n测试数据已回滚\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    foreach ($stored as $s) @unlink(ps_private_dir('imports') . '/' . basename($s) . '.php');
}
