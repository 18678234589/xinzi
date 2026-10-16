<?php
// 结算单保险扣除（按服务暂停天数）与手工项。只允许本地隔离库。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';
require_once __DIR__ . '/../includes/ProjectPayrollExtras.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; };
ps_payroll_extras_ensure(); // DDL 先于事务
$s = ['personal' => 471.02, 'unit' => 600.0];
$check(ps_insurance_suggest(0, $s)[0] === 471.02 && ps_insurance_suggest(3, $s)[0] === 471.02, '3 天以内按月扣个人 471.02');
$check(ps_insurance_suggest(3.01, $s)[0] === 535.51 && ps_insurance_suggest(6, $s)[0] === 535.51, '超过 3 天扣个人+单位合计的一半');
$check(ps_insurance_suggest(6.01, $s)[0] === 1071.02, '超过 6 天个人+单位全额');
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO employees (name,department,password) VALUES (?,?,?)")->execute(['保险测试', '商标部', 'fixture-only']);
    $emp = (int)$pdo->lastInsertId();
    ps_insurance_settings_save(471.02, 600);
    $pdo->prepare('INSERT INTO attendances (employee_id,year,month,work_hours,absent_hours) VALUES (?,?,?,?,?)')->execute([$emp, 2026, 9, 160, 32]);
    $actor = ['type' => 'admin', 'id' => 1];
    $x = ps_payroll_extras('2026-09')[$emp];
    $check($x['insurance']['amount'] === -535.51 && !$x['insurance']['manual'], '服务暂停 4 天自动扣 535.51');
    ps_payroll_extras_action('save_insurance', '2026-09', $emp, ['amount' => '100'], $actor);
    $x = ps_payroll_extras('2026-09')[$emp];
    $check($x['insurance']['amount'] === -100.0 && $x['insurance']['manual'], '财务手动输入覆盖自动金额');
    ps_payroll_extras_action('save_insurance', '2026-09', $emp, ['amount' => '0'], $actor);
    $check(ps_payroll_extras('2026-09')[$emp]['insurance']['amount'] == 0, '填 0 表示本月不扣');
    ps_payroll_extras_action('save_insurance', '2026-09', $emp, ['amount' => ''], $actor);
    $check(ps_payroll_extras('2026-09')[$emp]['insurance']['amount'] === -535.51, '留空恢复自动');
    ps_payroll_extras_action('add_manual_item', '2026-09', $emp, ['label' => '代理董事长补助', 'amount' => '500', 'note' => '本月'], $actor);
    ps_payroll_extras_action('add_manual_item', '2026-09', $emp, ['label' => '其他扣款', 'amount' => '-20.5'], $actor);
    $x = ps_payroll_extras('2026-09')[$emp];
    $check(count($x['items']) === 2 && $x['items_total'] === 479.5, '手工项正负相加 479.50');
    $check(ps_payroll_extras('2026-10')[$emp]['items_total'] === 0.0, '手工项只属于所在月份');
    foreach ([['label' => '', 'amount' => '5'], ['label' => 'x', 'amount' => '0'], ['label' => 'x', 'amount' => 'abc']] as $bad) {
        $threw = false;
        try { ps_payroll_extras_action('add_manual_item', '2026-09', $emp, $bad, $actor); } catch (RuntimeException $e) { $threw = true; }
        $check($threw, '拒绝无效手工项 ' . json_encode($bad, JSON_UNESCAPED_UNICODE));
    }
    ps_payroll_extras_action('delete_manual_item', '2026-09', $emp, ['item_id' => $x['items'][0]['id']], $actor);
    $check(count(ps_payroll_extras('2026-09')[$emp]['items']) === 1, '删除手工项');
    ps_payroll_extras_freeze('2026-09', [$emp], $actor);
    ps_insurance_settings_save(1, 1);
    $check(ps_payroll_extras('2026-09')[$emp]['insurance']['amount'] === -535.51, '锁月固化后改保险金额不影响本月');
} finally { $pdo->rollBack(); }
echo "OK\n";
