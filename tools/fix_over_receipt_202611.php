<?php
/**
 * 历史多算的实收更正：网站类订单（网站模板 / AI网站定制 / 网站定制）实收大于本单售价，多半是店铺实付里含了别的部门 / 订单的钱，
 * 按旧口径整笔记成了本单实收，分成因此多算。把这些订单的实收改成本单售价（ps_set_receipt），已审核订单的原快照保留、差额记为调整。
 * 退款过的订单（refund_amount>0）、实收不超过售价的订单不动。
 * 用法：php tools/fix_over_receipt_202611.php [--since=2026-09-01]                 # 预览（事务里试算后回滚，不写库）
 *       php tools/fix_over_receipt_202611.php --apply [--since=…] [--month=2026-11]  # 执行（调整计入 --month，默认下一个未锁定月份）
 */
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectReceiptEdit.php';

function for_candidates($since)
{
    $q = db()->prepare("SELECT o.id,o.order_no,o.project_type,o.order_date,o.contract_amount,o.receipt_amount,o.settlement_status,
        (SELECT GROUP_CONCAT(e.name SEPARATOR '、') FROM project_participants p JOIN employees e ON e.id=p.employee_id WHERE p.order_id=o.id) ppl
        FROM project_orders o WHERE o.project_type IN ('网站模板','AI网站定制','网站定制') AND o.order_date>=? AND o.settlement_status IN ('draft','review','approved')
        AND o.contract_amount>0 AND o.receipt_amount>o.contract_amount+0.01 AND o.refund_amount=0 ORDER BY o.order_date,o.id");
    $q->execute([$since]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** 执行（或试算）。返回 [订单数, 多出的实收合计, 按人汇总的分成调整]。 */
function for_run($since, $apply, $month = '')
{
    $pdo = db();
    $actor = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'finance', 'employee_id' => null];
    $names = []; foreach ($pdo->query('SELECT id,name FROM employees') as $r) $names[(int)$r['id']] = $r['name'];
    $n = 0; $over = 0.0; $byPerson = []; $failed = [];
    foreach (for_candidates($since) as $o) {
        $pdo->beginTransaction();
        try {
            $r = ps_set_receipt((int)$o['id'], (string)$o['contract_amount'], $actor, '实收不应超过本单售价（实付含别的部门 / 订单的钱）', $month);
            $adj = $pdo->prepare("SELECT employee_id,SUM(amount) amt FROM project_commission_adjustments WHERE order_id=? AND reason LIKE '实收更正%' GROUP BY employee_id"); $adj->execute([(int)$o['id']]);
            foreach ($adj->fetchAll(PDO::FETCH_ASSOC) as $a) $byPerson[$names[(int)$a['employee_id']] ?? $a['employee_id']] = ($byPerson[$names[(int)$a['employee_id']] ?? $a['employee_id']] ?? 0) + (float)$a['amt'];
            $n++; $over += $r['old'] - $r['new'];
            if ($apply) $pdo->commit(); else $pdo->rollBack();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $failed[] = $o['order_no'] . '：' . $e->getMessage(); }
    }
    return [$n, round($over, 2), $byPerson, $failed];
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $since = '2026-09-01'; $month = '';
    foreach ($argv as $a) { if (strpos($a, '--since=') === 0) $since = substr($a, 8); if (strpos($a, '--month=') === 0) $month = substr($a, 8); }
    $apply = in_array('--apply', $argv, true);
    [$n, $over, $by, $failed] = for_run($since, $apply, $month);
    echo ($apply ? '已更正' : '预览（未写库）') . "：$n 张订单，实收共多出 ¥" . number_format($over, 2) . "\n分成调整（负数＝扣回多算的）：\n";
    arsort($by); foreach (array_reverse($by, true) as $name => $amt) echo "  $name " . number_format($amt, 2, '.', '') . "\n";
    foreach ($failed as $f) echo "未处理：$f\n";
}
