<?php
// 往月整月实结算：财务没核对过的月份（无核对记录）本人不能申请，核对后（红 / 黄 / 绿）才可；财务代申请不受限。
// 会写入测试数据，只允许本地隔离库：DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php tests/payroll_month_checked_smoke.php
if (PHP_SAPI !== 'cli' || !preg_match('/_(test|ci|sandbox)$/', (string)getenv('DB_NAME')) || getenv('DB_PORT') !== '13399') { fwrite(STDERR, "只能在本地隔离库运行\n"); exit(1); }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlementRequest.php';
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$emp = 0;
try {
    ppf_ensure();
    $pdo->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute(['pmc_' . $tag, '网站售后部', 'x']); $emp = (int)$pdo->lastInsertId();
    $month = '2026-08';
    $check(pspr_month_checked($emp, $month) === false, '没有核对记录的往月：本人不能申请');
    $pdo->prepare("INSERT INTO project_payroll_flags (employee_id,payroll_month,color,reason) VALUES (?,?,'yellow','核对中')")->execute([$emp, $month]);
    $check(pspr_month_checked($emp, $month) === true, '财务有核对记录（黄）后：可以申请');
    $pdo->prepare('DELETE FROM project_payroll_flags WHERE employee_id=?')->execute([$emp]);
    $check(pspr_month_checked($emp, $month) === false, '记录删除后又不可申请');
} finally {
    if ($emp) { $pdo->exec("DELETE FROM project_payroll_flags WHERE employee_id=$emp"); $pdo->exec("DELETE FROM employees WHERE id=$emp"); }
}
echo "全部通过，测试数据已清理\n";
