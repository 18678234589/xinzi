<?php
// 一次性：于娜 9 月重复录入的一对订单（交易号带“交易号：”前缀与不带前缀被当成两张）。
// 保留信息更完整的 #57300（有付款昵称、参与人齐全），把 #56264 移进回收站（30 天内可还原），其分成快照随之收回。
// 用法：php tools/delete_duplicate_orders_20261019.php [--commit]；不带 --commit 只演练并回滚。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectOrderDelete.php';
require_once __DIR__ . '/../includes/ProjectOrderTrash.php';

$commit = in_array('--commit', $argv, true);
$keepId = 57300; $dupId = 56264;
$actor = ['type' => 'system', 'id' => 0, 'role' => 'finance'];
$pdo = db();
pot_ensure(); // 建表放在事务之前（DDL 会隐式提交）
$pdo->beginTransaction();
try {
    $get = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
    $get->execute([$keepId]); $keep = $get->fetch();
    $get->execute([$dupId]); $dup = $get->fetch();
    if (!$keep || !$dup) throw new RuntimeException('订单不存在（可能已处理）');
    if (ps_order_no_canonical($keep['order_no']) !== ps_order_no_canonical($dup['order_no']) || ps_order_no_canonical($keep['order_no']) === '') throw new RuntimeException('订单号不是同一单');
    if ((float)$keep['contract_amount'] !== (float)$dup['contract_amount'] || $keep['order_date'] !== $dup['order_date']) throw new RuntimeException('售价或日期不一致，不是同一单');
    $people = $pdo->prepare('SELECT employee_id,commission_group FROM project_participants WHERE order_id=?');
    $people->execute([$keepId]);
    $keepPeople = array_map(function ($p) { return $p['employee_id'] . '|' . $p['commission_group']; }, $people->fetchAll());
    $people->execute([$dupId]);
    foreach ($people->fetchAll() as $p) if (!in_array($p['employee_id'] . '|' . $p['commission_group'], $keepPeople, true)) throw new RuntimeException('多余订单的参与人不在保留订单上，不能自动删除');
    if (($why = pod_blocker($dup, $actor)) !== '') throw new RuntimeException($why);
    $snap = $pdo->prepare('SELECT employee_id,commission_amount FROM project_commission_snapshots WHERE order_id=?');
    $snap->execute([$dupId]);
    foreach ($snap->fetchAll() as $s) echo "收回分成：员工#{$s['employee_id']} ¥{$s['commission_amount']}\n";
    $trashNo = pod_backup($dupId, $dup['order_no'], $actor);
    $pdo->prepare('DELETE t FROM project_technical_reconciliations t JOIN project_commission_snapshots s ON s.id=t.snapshot_id WHERE s.order_id=?')->execute([$dupId]);
    foreach (['project_order_items', 'project_commission_snapshots', 'project_commission_adjustments', 'project_cash_movements', 'project_costs', 'project_participants', 'project_order_sources', 'project_order_resources', 'project_order_requests', 'project_order_details'] as $table) {
        $pdo->prepare('DELETE FROM ' . $table . ' WHERE order_id=?')->execute([$dupId]);
    }
    $pdo->prepare('DELETE FROM project_orders WHERE id=?')->execute([$dupId]);
    ps_audit('order', $dupId, 'delete_order', $actor, ['order_no' => $dup['order_no'], 'was_status' => $dup['settlement_status'], 'backup' => $trashNo, 'duplicate_of' => $keepId, 'reason' => '重复录入（交易号前缀写法不同）']);
    echo "已把 #{$dupId}（{$dup['order_no']}）移进回收站（{$trashNo}），保留 #{$keepId}（{$keep['order_no']}）\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
if ($commit) { $pdo->commit(); echo "已保存\n"; } else { $pdo->rollBack(); echo "演练完成，未保存（加 --commit 保存）\n"; }
