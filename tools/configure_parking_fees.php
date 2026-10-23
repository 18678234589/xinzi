<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/ProjectMonthly.php';
require_once dirname(__DIR__) . '/includes/ProjectPayrollExtras.php';
$month = $argv[1] ?? '';
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new RuntimeException('用法：php tools/configure_parking_fees.php YYYY-MM [--apply]');
ps_payroll_extras_ensure();
$pdo = db();
$pdo->beginTransaction();
try {
    $q = $pdo->prepare("SELECT employee_id,SUM(amount) amount FROM project_payroll_manual_items WHERE payroll_month=? AND kind='other' AND amount<0 AND label LIKE '%车位%' GROUP BY employee_id");
    $q->execute([$month]);
    $source = $q->fetchAll();
    $check = $pdo->prepare('SELECT effective_month FROM project_payroll_parking WHERE employee_id=? FOR UPDATE');
    $insert = $pdo->prepare('INSERT INTO project_payroll_parking (employee_id,effective_month,amount,updated_by_admin) VALUES (?,?,100,1)');
    $created = 0;
    foreach ($source as $r) {
        $check->execute([(int)$r['employee_id']]);
        if ($check->fetch()) continue;
        $insert->execute([(int)$r['employee_id'], $month]);
        ps_audit('payroll_parking', (int)$r['employee_id'], 'seed', ['type' => 'admin', 'id' => 1], ['effective_month' => $month, 'amount' => 100, 'source_manual_total' => $r['amount']]);
        $created++;
    }
    $apply = in_array('--apply', $argv, true);
    if ($apply) $pdo->commit(); else $pdo->rollBack();
    echo json_encode(['applied' => $apply, 'source_people' => count($source), 'created' => $created, 'monthly_amount' => 100], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
