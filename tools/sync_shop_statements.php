<?php
// 把店铺流水（含对公收款）里的付款昵称 / 交易状态 / 店铺补进同订单号的项目订单（只补空字段，已审核 / 已锁定的不动）。
// 用法：php tools/sync_shop_statements.php [项目订单店铺关键字，如 对公] [--apply]     默认只读（事务回滚）：只统计会补多少单
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
chdir($root);
require_once $root . '/includes/ProjectIntake.php';
require_once $root . '/includes/ProjectOrderSource.php';
$apply = in_array('--apply', $argv, true);
$like = '';
foreach (array_slice($argv, 1) as $a) if ($a !== '--apply') $like = $a;
$pdo = db();
$sql = "SELECT id,order_no,shop FROM project_orders WHERE settlement_status NOT IN ('approved','locked') AND order_no<>'' AND order_no NOT LIKE 'WX-%'" . ($like !== '' ? ' AND shop LIKE ' . $pdo->quote('%' . $like . '%') : '') . ' ORDER BY id';
$rows = $pdo->query($sql)->fetchAll();
$synced = 0; $failed = 0; $byShop = [];
foreach ($rows as $r) {
    try {
        $pdo->beginTransaction();
        $ok = ps_sync_existing_shop_order((int)$r['id'], $r['order_no'], (string)$r['shop']);
        if ($apply) $pdo->commit(); else $pdo->rollBack();
        if ($ok) { $synced++; $byShop[$r['shop']] = ($byShop[$r['shop']] ?? 0) + 1; }
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $failed++; fwrite(STDERR, $r['order_no'] . ': ' . $e->getMessage() . "\n"); }
}
echo '检查 ' . count($rows) . ' 张未审核项目订单；' . ($apply ? '已补充 ' : '可补充 ') . $synced . " 张" . ($failed ? "，失败 $failed 张" : '') . "\n";
arsort($byShop);
foreach (array_slice($byShop, 0, 15, true) as $shop => $n) echo "  " . ($shop === '' ? '（店铺为空）' : $shop) . "：$n 张\n";
if (!$apply) echo "（只读预览，加 --apply 才会写入）\n";
