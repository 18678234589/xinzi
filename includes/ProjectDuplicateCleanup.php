<?php
/**
 * 重复订单叠加清理：没有真实订单号（WX- 内部号）的订单，同业务、同日期、同售价、同付款昵称、同一批参与人，却建了两张（两次上传 / 店铺同步各建一张），
 * 两张都还是草稿、被丢弃的那张没有收款 / 成本 → 留较早的一张，把另一张的订单类型补到保留的订单上（保留的没写类型时），被丢的放进回收站（30 天内可还原）。
 * 已审核的重复只列出来，不动（要财务判断）。
 * 用法：php includes/ProjectDuplicateCleanup.php           # 预览
 *       php includes/ProjectDuplicateCleanup.php --apply   # 执行
 */
require_once __DIR__ . '/ProjectIntake.php';
require_once __DIR__ . '/ProjectOrderDelete.php';

function mdd_groups($since = '2026-09-01')
{
    $rows = db()->prepare("SELECT o.id,o.order_no,o.project_type,o.order_kind,o.order_date,o.contract_amount,o.settlement_status s,o.delivery_status dv,o.created_at,COALESCE(st.payment_nickname,'') nick,
        (SELECT GROUP_CONCAT(CONCAT(p.employee_id,':',p.commission_group) ORDER BY p.employee_id,p.commission_group SEPARATOR ',') FROM project_participants p WHERE p.order_id=o.id) ppl,
        (SELECT COUNT(*) FROM project_cash_movements m WHERE m.order_id=o.id) cashn,
        (SELECT COUNT(*) FROM project_costs c WHERE c.order_id=o.id) costn
        FROM project_orders o LEFT JOIN project_order_sources st ON st.order_id=o.id
        WHERE o.order_no LIKE 'WX-%' AND o.order_date>=? AND o.settlement_status IN ('draft','review','approved') AND o.contract_amount>0 ORDER BY o.id");
    $rows->execute([$since]); $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
    $g = [];
    foreach ($rows as $r) {
        if (trim($r['nick']) === '' || !$r['ppl']) continue;
        $g[$r['project_type'] . '|' . $r['order_date'] . '|' . $r['contract_amount'] . '|' . mb_strtolower(trim($r['nick'])) . '|' . $r['ppl']][] = $r;
    }
    return array_values(array_filter($g, function ($l) { return count($l) > 1; }));
}

/** $minGapMinutes：两张订单创建时间至少相隔这么久才算“重复上传”（同一张表里完全相同的两行是各自独立的订单，创建时间几乎相同，不能合并）。 */
function mdd_run($apply, $since = '2026-09-01', $minGapMinutes = 0)
{
    $actor = ['type' => 'admin', 'id' => (int)db()->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'finance', 'employee_id' => null];
    $done = []; $skipped = [];
    foreach (mdd_groups($since) as $list) {
        $drafts = array_values(array_filter($list, function ($r) { return $r['s'] !== 'approved'; }));
        if (count($drafts) !== count($list)) { $skipped[] = ['reason' => '含已审核订单，需财务判断', 'orders' => array_column($list, 'order_no')]; continue; }
        $keep = $list[0]; $drop = array_slice($list, 1);
        foreach ($drop as $d) {
            if ($minGapMinutes > 0 && abs(strtotime($d['created_at']) - strtotime($keep['created_at'])) < $minGapMinutes * 60) { $skipped[] = ['reason' => '同一次上传里的相同两行，各算一单', 'orders' => [$keep['order_no'], $d['order_no']]]; continue; }
            if ((int)$d['cashn'] > 0 || (int)$d['costn'] > 0) { $skipped[] = ['reason' => '要去掉的那张已有收款 / 成本', 'orders' => [$keep['order_no'], $d['order_no']]]; continue; }
            if ($apply) {
                $pdo = db(); $pdo->beginTransaction();
                try {
                    if (trim((string)$keep['order_kind']) === '' && trim((string)$d['order_kind']) !== '') $pdo->prepare('UPDATE project_orders SET order_kind=?,row_version=row_version+1 WHERE id=?')->execute([$d['order_kind'], (int)$keep['id']]);
                    if ($keep['dv'] !== 'finished' && $d['dv'] === 'finished') $pdo->prepare("UPDATE project_orders SET delivery_status='finished',row_version=row_version+1 WHERE id=?")->execute([(int)$keep['id']]);
                    pod_backup((int)$d['id'], $d['order_no'], $actor);
                    pod_delete_rows((int)$d['id']);
                    ps_audit('order', (int)$keep['id'], 'merge_duplicate', $actor, ['kept' => $keep['order_no'], 'trashed' => $d['order_no'], 'trashed_id' => (int)$d['id'], 'reason' => '同业务 / 日期 / 售价 / 昵称 / 参与人的重复草稿订单']);
                    $pdo->commit();
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            }
            $done[] = ['keep' => $keep['order_no'], 'drop' => $d['order_no'], 'kind' => $keep['order_kind'] ?: $d['order_kind']];
        }
    }
    return [$done, $skipped];
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    [$done, $skipped] = mdd_run(in_array('--apply', $argv, true));
    foreach ($done as $d) echo "合并：保留 {$d['keep']}，" . (in_array('--apply', $argv, true) ? '已把' : '将把') . " {$d['drop']} 放进回收站" . ($d['kind'] ? "（订单类型 {$d['kind']}）" : '') . "\n";
    foreach ($skipped as $s) echo "跳过（{$s['reason']}）：" . implode(' / ', $s['orders']) . "\n";
    if (!in_array('--apply', $argv, true)) echo "\n这是预览，加 --apply 执行。\n";
}
