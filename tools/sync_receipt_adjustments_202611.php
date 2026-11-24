<?php
/**
 * 已审核订单的实收被手动改过、但分成快照还是旧实收算的：按快照口径重算差额，记为调整（计入下一个未锁定月份，或 --month=）。
 * 判断：快照里的收入 ≠ 订单当前净实收（实收 − 退款）。已按售后调整流程处理过的订单，重算差额为 0，不会重复记。
 * 用法：php tools/sync_receipt_adjustments_202611.php [--since=2026-09-01]                 # 预览（事务里试算后回滚）
 *       php tools/sync_receipt_adjustments_202611.php --apply [--since=…] [--month=2026-11]
 */
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';

function sra_candidates($since)
{
    $q = db()->prepare("SELECT o.id,o.order_no,o.project_type,o.receipt_amount,o.refund_amount,
        (SELECT MAX(s.income_amount) FROM project_commission_snapshots s WHERE s.order_id=o.id) snap_income
        FROM project_orders o WHERE o.settlement_status IN ('approved','locked') AND o.order_date>=?
        AND EXISTS (SELECT 1 FROM project_commission_snapshots s WHERE s.order_id=o.id)
        HAVING ABS((o.receipt_amount-o.refund_amount)-snap_income)>0.01 ORDER BY o.id");
    $q->execute([$since]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function sra_run($since, $apply, $month = '')
{
    $pdo = db(); $month = $month !== '' ? $month : ps_next_open_month();
    $names = []; foreach ($pdo->query('SELECT id,name FROM employees') as $r) $names[(int)$r['id']] = $r['name'];
    $n = 0; $changed = 0; $by = []; $failed = []; $rows = [];
    foreach (sra_candidates($since) as $o) {
        $n++;
        $pdo->beginTransaction();
        try {
            $created = ps_post_adjustment((int)$o['id'], ['type' => 'system', 'id' => 0, 'role' => 'finance'], 0, 0, '实收变更补记：快照按 ¥' . number_format((float)$o['snap_income'], 2, '.', '') . ' 算，当前净实收 ¥' . number_format((float)$o['receipt_amount'] - (float)$o['refund_amount'], 2, '.', ''), $month, true);
            if ($created) { $changed++; $rows[] = $o['order_no']; foreach ($created as $c) { $k = $names[$c['employee_id']] ?? $c['employee_id']; $by[$k] = ($by[$k] ?? 0) + $c['amount']; } }
            if ($apply) $pdo->commit(); else $pdo->rollBack();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $failed[] = $o['order_no'] . '：' . $e->getMessage(); }
    }
    return [$n, $changed, $by, $failed, $rows];
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $since = '2026-09-01'; $month = '';
    foreach ($argv as $a) { if (strpos($a, '--since=') === 0) $since = substr($a, 8); if (strpos($a, '--month=') === 0) $month = substr($a, 8); }
    $apply = in_array('--apply', $argv, true);
    [$n, $changed, $by, $failed, $rows] = sra_run($since, $apply, $month);
    echo ($apply ? '已补记' : '预览（未写库）') . "：实收与快照不一致的订单 $n 张，其中 $changed 张需要补调整\n";
    foreach ($rows as $r) echo "  $r\n";
    echo "分成调整合计：\n"; foreach ($by as $name => $amt) echo '  ' . $name . ' ' . number_format($amt, 2, '.', '') . "\n";
    foreach ($failed as $f) echo "未处理：$f\n";
}
