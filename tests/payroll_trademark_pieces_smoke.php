<?php
// 报酬页“本期订单与登记金额”显示商标总件数（已核算 / 待核算）和资料 / 提交专员的按件预计报酬。只允许本地隔离库。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectPayrollOrderSummary.php';
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; };
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO employees (name,department,password) VALUES (?,?,?)")->execute(['件数测试', '商标部', 'fixture-only']);
    $emp = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_employee_roles (employee_id,business_name,commission_group,role_name) VALUES (?,'商标','technical','提交专员')")->execute([$emp]);
    $make = function ($count, $status) use ($pdo, $emp) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status) VALUES (?,'件数客户','商标','普通订单','测试',500,500,'2026-09-10','finished',?)")->execute(['PCS-' . bin2hex(random_bytes(5)), $status]);
        $id = (int)$pdo->lastInsertId();
        ps_save_business_details($id, '商标', ['service_type' => '注册', 'trademark_name' => '测试', 'trademark_count' => $count]);
        $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','提交专员',0.5)")->execute([$id, $emp]);
    };
    $make('2', 'approved'); $make('1', 'approved'); $make('26', 'draft'); $make('', 'draft'); $make('3', 'draft');
    $s = ps_payroll_order_summary($emp, '2026-09');
    $b = $s['businesses']['商标'];
    $check($b['count'] === 5 && $b['pieces'] === 32.0 && $b['pieces_settled'] === 3.0, '商标总件数 32 = 已核算 3 + 待核算 29（件数留空的不计）');
    $p = $s['piece'];
    $check($p && $p['unit'] === 2.2 && $p['estimate'] === 70.4 && $p['settled_amount'] === 6.6 && $p['role'] === '提交专员', '每件 2.2：预计 ¥70.40，其中已核算 ¥6.60');
    ob_start(); ps_payroll_order_summary_render($s); $html = ob_get_clean();
    $check(strpos($html, '商标件数') !== false && strpos($html, '32 件') !== false && strpos($html, '已核算 3') !== false && strpos($html, '待核算 29') !== false && strpos($html, '70.40') !== false, '页面显示商标总件数、已核算 / 待核算和预计报酬');
    $other = ps_payroll_order_summary(0, '2026-09');
    $check(array_key_exists('piece', $other) && $other['piece'] === null, '财务看全部人时不显示个人按件预计');
    echo "ALL PASS\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
