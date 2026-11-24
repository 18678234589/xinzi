<?php
// 上月（或更早）没完成、之后才完成 / 发货的订单：自动结算时分成算到“最早还没核算完毕的月份”（完成的月份），不是订单日期的老月份，也不改已核算月份。
// 只允许本地隔离库。
if (PHP_SAPI !== 'cli' || !preg_match('/_(test|ci|sandbox)$/', (string)getenv('DB_NAME')) || getenv('DB_PORT') !== '13399') { fwrite(STDERR, "只能在本地隔离库运行\n"); exit(1); }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectAutoReview.php';
$pdo = db();
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$old = ps_setting_get('refund_settled_through', '');
try {
    ps_setting_set('refund_settled_through', '2026-09', 1);
    $pdo->exec("UPDATE project_payroll_periods SET status='draft' WHERE period IN ('2026-08','2026-09','2026-10','2026-11')");
    $T = '2026-10-20'; // 10 月 20 日：9 月已过截止日（15 日），10 月是当月
    $check(pa_payroll_month_for('2026-08-27', $T) === '2026-10', '8 月订单、核算已到 9 月：现在完成 → 算 2026-10');
    $check(pa_payroll_month_for('2026-09-28', $T) === '2026-10', '9 月订单现在完成 → 算 2026-10');
    $check(pa_payroll_month_for('2026-10-05', $T) === '2026-10', '10 月订单 → 仍是 10 月');
    $check(pa_payroll_month_for('2026-11-03', $T) === '2026-11', '11 月订单 → 仍是 11 月');
    $pdo->exec("UPDATE project_payroll_periods SET status='locked' WHERE period='2026-10'");
    $check(pa_payroll_month_for('2026-08-27', $T) === '2026-11', '10 月已锁定：顺延到 11 月');
    $pdo->exec("UPDATE project_payroll_periods SET status='draft' WHERE period='2026-10'");
    ps_setting_set('refund_settled_through', '', 1);
    $check(pa_payroll_month_for('2026-08-27', $T) === '2026-10', '没设置“已核算至”：10 月 20 日完成的 8 月订单 → 当月 10 月（自动截止）');
    $check(pa_payroll_month_for('2026-10-07', '2026-11-16') === '2026-11', '11 月 16 日才发货的 10 月订单 → 算 11 月（10 月已过 15 日截止）');
    $check(pa_payroll_month_for('2026-10-07', '2026-11-10') === '2026-10', '11 月 10 日发货的 10 月订单 → 仍可补入 10 月（未过截止日）');
    echo "\n=== 完成月份归属测试全部通过 ===\n";
} finally {
    ps_setting_set('refund_settled_through', $old, 1);
}
