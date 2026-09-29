<?php
// 近期真实上传回放发现的问题（2026-09）：无表头表格按内容识别；订单类型列写成付款方式不拦行；连写姓名拆分；
// 重名时取唯一有账号的一位；已审核订单 / 他人订单灰色跳过不算“需处理”。事务内执行，结束回滚。
// php tests/import_replay_fixes_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';

$pdo = db();
$pdo->beginTransaction();
$stored = [];
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$E = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT e.id FROM employees e JOIN project_users u ON u.employee_id=e.id AND u.is_active=1 WHERE e.name=? ORDER BY e.id LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); };
try {
    // 纯函数：重名取唯一有账号者；连写姓名拆分
    $index = ['孙测试' => [['id' => 1, 'has_account' => 0, 'department' => '网站客服', 'businesses' => []], ['id' => 2, 'has_account' => 1, 'department' => '网站客服', 'businesses' => ['网站模板']]], '王宁' => [['id' => 3, 'has_account' => 1, 'businesses' => []]], '朱俊英' => [['id' => 4, 'has_account' => 1, 'businesses' => []]]];
    $check(ps_import_names('孙测试', $index, '网站修改') === [2 => '孙测试'], '重名两位、只有一位开通账号：取有账号的一位');
    $check(ps_import_names('朱俊英王宁', $index, '小程序开发') === [4 => '朱俊英', 3 => '王宁'], '“朱俊英王宁”连写拆成两人');
    $threw = false; try { ps_import_names('朱俊英王', $index, '小程序开发'); } catch (RuntimeException $e) { $threw = true; }
    $check($threw, '拆不完整的连写（朱俊英王）仍提示不在名单');

    // 填写指引与示例模板
    foreach (['日期无法识别' => 'date', '状态“完结”无法识别，请写已完成 / 未完成（或到账、已发货等）' => 'status', '订单号或售价无效' => 'amount', '合作人员“王宁朱”不在人员名单中，请先在“人员与考勤”里添加' => 'person', '缺少“订单编号”或“微信交易流水号”列；请补其中之一' => 'header', '缺少店铺订单号或支付流水号' => 'order_no'] as $msg => $key) {
        $g = ps_import_fix_guide($msg, '小程序开发');
        $check($g && $g['key'] === $key && $g['how'] !== '', '“' . mb_substr($msg, 0, 14) . '…” → 指引：' . ($g['title'] ?? '无'));
    }
    $check(ps_import_fix_guide('原单与上传表的售价不一致，请由财务核对') === null, '需财务核对的问题不当作填写问题');
    $headers = ps_business_import_headers('小程序开发');
    $example = ps_business_import_example_row('小程序开发', $headers);
    $check(count($example) === count($headers) && ps_import_row_is_example($example) && $example[array_search('售价', $headers, true)] === '350', '下载模板附带示例行：' . implode(' | ', array_map(function ($h, $v) { return $h . '=' . $v; }, $headers, $example)));
    $user = $pdo->query("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name='翟建跃' AND u.is_active=1")->fetch();
    $actor = ['type' => 'employee', 'id' => (int)$user['id'], 'employee_id' => (int)$user['employee_id'], 'role' => $user['role']];
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,settlement_status,note) VALUES ('REPLAY-TEST-003','nick_c','小程序开发','续费','美呀美',100,'2026-09-03','finished','approved','测试')")->execute();
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','制作技术',1)")->execute([(int)$pdo->lastInsertId(), $actor['employee_id']]);

    $run = function ($fixture, $name) use ($pdo, $actor, &$stored) {
        $path = __DIR__ . '/fixtures/' . $fixture;
        $stored[] = $storedName = ps_private_store('imports', $path, 'replay_test_' . bin2hex(random_bytes(5)) . '.csv');
        $pdo->prepare("INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('小程序开发',?,?,?,'employee',?,?)")->execute([$name, $storedName, filesize($path), $actor['id'], $actor['employee_id']]);
        $_SESSION = ['project_user_id' => $actor['id'], 'project_csrf' => 'test-csrf'];
        $_SERVER['SCRIPT_NAME'] = '/project/import.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['csrf' => 'test-csrf', 'action' => 'repreview', 'business' => '小程序开发', 'file_id' => (int)$pdo->lastInsertId(), 'all_sheets' => 1];
        $error = ''; ob_start(); include __DIR__ . '/../project/import.php'; $html = ob_get_clean();
        $byNo = []; foreach ($_SESSION['project_import_preview'] ?? [] as $r) $byNo[$r['order_no']] = $r;
        return [$error, $byNo, $html];
    };

    [$error, $byNo, $html] = $run('import_replay_fixes.csv', '回放修复.csv');
    $check($error === '', '预览成功');
    $r1 = $byNo['REPLAY-TEST-001'];
    $check(!empty($r1['base_valid']) && mb_strpos($r1['warning'], '“微信”不是可选类型') !== false && $r1['order_kind'] !== '', '订单类型列写“微信”：不拦整行，提示已忽略并预选类型（' . $r1['order_kind'] . '）');
    $r2 = $byNo['REPLAY-TEST-002'];
    $check(empty($r2['base_valid']) && $r2['status'] === '他人订单' && mb_strpos($r2['error'], '石凯新') !== false, '技术写的是石凯新：标“他人订单”并点名是谁');
    $check($byNo['REPLAY-TEST-003']['status'] === '已导入过', '已审核订单重复上传：标“已导入过”');
    $r4 = $byNo['REPLAY-TEST-004'];
    $check(!empty($r4['base_valid']) && isset($r4['people']['customer_service'][$E('曹双双')], $r4['people']['customer_service'][$E('朱俊英')]), '客服“曹双双朱俊英”连写拆成两位客服');
    $check(mb_strpos($html, '已自动跳过') !== false && mb_strpos($html, '2 行需处理') === false && mb_strpos($html, 'table-secondary') !== false, '跳过行灰色显示、不计入“需处理”');

    $check(!array_filter(array_keys($byNo), function ($no) { return mb_strpos($no, '示例') === 0; }), '模板示例行（订单号以“示例”开头）自动跳过，不会入账');
    $check(empty($byNo['REPLAY-TEST-005']['base_valid']) && mb_strpos($html, 'id="importFixGuide"') !== false && mb_strpos($html, '日期写法无法识别') !== false && mb_strpos($html, '2026-09-01') !== false && mb_strpos($html, '含示例行') !== false, '日期写“昨天”：弹窗说明怎么填、给示例和模板下载链接');
    $check(mb_strpos($html, '怎么改：') !== false, '红色行下直接显示“怎么改”');
    [$error, $byNo, $html] = $run('import_headerless.csv', '光君.csv');
    $check($error === '', '无表头表格预览成功：' . $error);
    $h = $byNo['3316440471002099991'] ?? null;
    $check($h && !empty($h['base_valid']) && $h['order_date'] === '2026-09-23' && $h['contract_amount'] === '540' && mb_strpos($h['shop'], '美呀美') === 0 && $h['payment_nickname'] === 'tb_replay_nick', '第 1 行即订单：日期 / 售价 / 店铺 / 付款昵称按内容识别');
    $check((int)$h['line'] === 1 && isset($byNo['3316440471002099992']) && (int)$byNo['3316440471002099992']['line'] === 2, '行号与 Excel 一致（首行=第 1 行）');
    $check(isset($h['people']['customer_service'][$E('曹双双')]) && isset($h['people']['technical'][$actor['employee_id']]), '客服列 / 技术列按账号角色识别');
    $check(mb_strpos($html, '表格没有表头') !== false, '页面提示“表格没有表头，已按内容识别”');

    $pdo->rollBack();
    echo "\n=== 回放修复全部通过，测试数据已回滚 ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    foreach ($stored as $name) @unlink(ps_private_dir('imports') . '/' . basename($name) . '.php');
}
