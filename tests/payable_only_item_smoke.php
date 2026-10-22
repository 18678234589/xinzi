<?php
// 手工项“只调整应结算”：应结算按金额变化，实结算保持不变；普通手工项两者一起变。
// 会写入测试数据，只允许本地隔离库：DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php tests/payable_only_item_smoke.php
if (PHP_SAPI !== 'cli' || !preg_match('/_(test|ci|sandbox)$/', (string)getenv('DB_NAME')) || getenv('DB_PORT') !== '13399') { fwrite(STDERR, "只能在本地隔离库运行\n"); exit(1); }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';
require_once __DIR__ . '/../includes/ProjectPayrollSheet.php';
$pdo = db(); $tag = bin2hex(random_bytes(3)); $month = '2026-09';
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$emp = 0; $rule = 0;
try {
    ps_payroll_extras_ensure();
    $pdo->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute(['po_' . $tag, '网站技术', 'x']); $emp = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_monthly_rules (name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from,is_active) VALUES (?,'base_fee','*','*','*',?,'profit','{\"amount\": 2000}','2026-06',1)")->execute(['po_base_' . $tag, $emp]); $rule = (int)$pdo->lastInsertId();
    $row = function () use ($emp, $month) { foreach (pps_sheet($month, $emp)['rows'] as $r) if ((int)$r['id'] === $emp) return $r; return null; };
    $r0 = $row(); $check($r0 && abs($r0['base'] - 2000) < 0.01, '基础：固定服务费 2000：' . json_encode($r0 ? [$r0['payable'], $r0['net']] : null));
    $add = function ($label, $amount, $note) use ($pdo, $emp, $month) { $pdo->prepare("INSERT INTO project_payroll_manual_items (employee_id,payroll_month,kind,label,amount,note) VALUES (?,?,'other',?,?,?)")->execute([$emp, $month, $label, $amount, $note]); return (int)$pdo->lastInsertId(); };
    $i1 = $add('提成', -300, '');
    $r1 = $row();
    $check(abs($r1['payable'] - ($r0['payable'] - 300)) < 0.01 && abs($r1['net'] - ($r0['net'] - 300)) < 0.01, '普通负数提成：应结算、实结算同时减 300');
    $i2 = $add('提成', -465.02, PPE_PAYABLE_ONLY);
    $r2 = $row();
    $check(abs($r2['payable'] - ($r1['payable'] - 465.02)) < 0.01 && abs($r2['net'] - $r1['net']) < 0.001, '“只调应结算” −465.02：应结算减 465.02，实结算不变');
    $x = ps_payroll_extras($month)[$emp];
    $check(abs($x['items_total'] - (-300)) < 0.001, '详情页 / 申请用的手工项合计不含“只调应结算”的项');
    $pdo->prepare('DELETE FROM project_payroll_manual_items WHERE id=?')->execute([$i2]);
    $check(abs($row()['payable'] - $r1['payable']) < 0.001, '删除该项后应结算恢复');
} finally {
    if ($emp) { $pdo->exec("DELETE FROM project_payroll_manual_items WHERE employee_id=$emp"); }
    if ($rule) $pdo->exec("DELETE FROM project_monthly_rules WHERE id=$rule");
    if ($emp) $pdo->exec("DELETE FROM employees WHERE id=$emp");
}
echo "全部通过，测试数据已清理\n";
