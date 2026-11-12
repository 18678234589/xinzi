<?php
// 一次性：2026-11-03 商标上传（订单号写成“微信+号码”）造成的 21 张重复订单。保留原订单（参与人齐全），多余的那张进回收站（30 天内可还原），重复的分成随之收回。
// 判定：订单号以“微信”开头，去掉后与保留订单同号；售价相同；多余订单的参与人都在保留订单上；所在月份未核算 / 未锁定。
// 用法：php tools/delete_duplicate_orders_20261112.php [--commit]；不带 --commit 只演练并回滚。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectDuplicateOrders.php';

$commit = in_array('--commit', $argv, true);
$actor = ['type' => 'system', 'id' => 0, 'role' => 'finance'];
$pdo = db();
pot_ensure(); // 建表放在事务之前（DDL 会隐式提交）
$pdo->beginTransaction();
$done = 0; $money = 0.0; $skipped = [];
try {
    foreach (pdo_duplicate_groups() as $g) {
        $keep = $g['keep'];
        foreach ($g['dups'] as $d) {
            if (!preg_match('/^微信\d/u', $d['order_no'])) continue;
            if (ps_order_no_canonical($d['order_no']) !== ps_order_no_canonical($keep['order_no'])) { $skipped[] = $d['order_no'] . '：订单号对不上'; continue; }
            if ((float)$d['contract_amount'] !== (float)$keep['contract_amount']) { $skipped[] = $d['order_no'] . '：售价不同'; continue; }
            if (array_diff($d['people'], $keep['people'])) { $skipped[] = $d['order_no'] . '：有保留订单没有的参与人'; continue; }
            $row = $pdo->query('SELECT id,order_no,project_type,order_date,settlement_status FROM project_orders WHERE id=' . (int)$d['id'] . ' FOR UPDATE')->fetch();
            if (!$row || ($why = pod_blocker($row, $actor)) !== '') { $skipped[] = $d['order_no'] . '：' . ($row ? $why : '订单不存在'); continue; }
            $trash = pod_backup((int)$d['id'], $row['order_no'], $actor);
            pod_delete_rows((int)$d['id']);
            ps_audit('order', (int)$d['id'], 'delete_order', $actor, ['order_no' => $row['order_no'], 'was_status' => $row['settlement_status'], 'backup' => $trash, 'duplicate_of' => (int)$keep['id'], 'reason' => '订单号带“微信”前缀导致重复建单（2026-11-03 上传）']);
            $done++; $money += (float)$d['snap_total'];
            echo "删除 #{$d['id']} [{$d['order_no']}] → 保留 #{$keep['id']} [{$keep['order_no']}]  收回分成 ¥" . number_format((float)$d['snap_total'], 2) . "（{$trash}）\n";
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
foreach ($skipped as $s) echo "跳过 $s\n";
echo "共处理 {$done} 张，收回重复分成 ¥" . number_format($money, 2) . "\n";
if ($commit) { $pdo->commit(); echo "已保存\n"; } else { $pdo->rollBack(); echo "演练完成，未保存（加 --commit 保存）\n"; }
