<?php
// 修复商标订单成本：把“没有成本”或“成本卡在待审核（订单直接成本显示 ¥0）”的订单，按 ptc_sync_order_cost 的规则补齐：
//   成本 = max(成本中心标准价（商标个数 × 单价）, Excel 导入过的成本)；待审核的 Excel 官费自动通过；有人工成本的订单不动；已审核 / 锁定订单不动。
// 用法：php tools/repair_trademark_costs.php [起始日期=2026-09-01] [--commit]；不带 --commit 只演练并回滚。写入前备份受影响订单的成本行到 JSON。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';

$commit = in_array('--commit', $argv, true);
$since = '2026-09-01';
foreach ($argv as $i => $arg) if ($i > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) $since = $arg;
$actor = ['type' => 'system', 'id' => 0, 'role' => 'finance', 'employee_id' => null];
$pdo = db();
ptc_templates(); // 先加载模板（避免事务内 DDL）
$orders = $pdo->prepare("SELECT id,order_no,contract_amount FROM project_orders WHERE project_type='商标' AND order_date>=? AND settlement_status NOT IN ('approved','locked') ORDER BY id");
$orders->execute([$since]);
$list = $orders->fetchAll();
$result = []; $backup = []; $before = 0.0; $after = 0.0;
$sum = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM project_costs WHERE order_id=? AND review_status='approved'");
$rowsQ = $pdo->prepare("SELECT * FROM project_costs WHERE order_id=?");
$pdo->beginTransaction();
try {
    foreach ($list as $o) {
        $sum->execute([$o['id']]); $b = (float)$sum->fetchColumn();
        $rowsQ->execute([$o['id']]); $rowsBefore = $rowsQ->fetchAll();
        $r = ptc_sync_order_cost((int)$o['id'], $actor, '成本修复');
        $sum->execute([$o['id']]); $a = (float)$sum->fetchColumn();
        $result[$r] = ($result[$r] ?? 0) + 1; $before += $b; $after += $a;
        if (in_array($r, ['added', 'raised', 'approved'], true)) { $backup[] = ['order_no' => $o['order_no'], 'result' => $r, 'costs_before' => $rowsBefore]; if ($r !== 'ok' && count($backup) <= 12) echo sprintf("  %-28s %-9s 直接成本 %8.2f → %8.2f\n", mb_substr($o['order_no'], 0, 28), $r, $b, $a); }
    }
    echo "检查订单 ", count($list), " 张（自 $since 起，未审核）\n";
    foreach ($result as $k => $n) echo "  ", ['none' => '无需 / 算不出（无件数且 Excel 无成本）', 'added' => '新补成本', 'raised' => '成本不足已补齐', 'approved' => '待审核成本已自动通过', 'ok' => '本来就正常', 'manual' => '有人工成本，未动', 'skip' => '跳过'][$k] ?? $k, "：$n\n";
    printf("订单“直接成本”（已通过成本）合计：%.2f → %.2f\n", $before, $after);
    if ($commit) {
        $dir = __DIR__ . '/../.deploy'; if (!is_dir($dir) || !is_writable($dir)) $dir = sys_get_temp_dir();
        $file = $dir . '/trademark_cost_repair_' . date('Ymd_His') . '.json';
        if (file_put_contents($file, json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) throw new RuntimeException('备份写入失败：' . $file);
        $pdo->commit();
        echo "已提交；修改前的成本行备份：$file\n";
    } else { $pdo->rollBack(); echo "演练完成，已回滚（加 --commit 才会写入）\n"; }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, '失败，已回滚：' . $e->getMessage() . "\n"); exit(1);
}
