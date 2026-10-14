<?php
// 修复店铺订单“归属月份记错”：早期上传时，表格“时间”写成 2026081012:21:30 / 20260703093827 / 2026-8-7 10:00 等写法认不出来，
// 这些行被悄悄记到了“上传时选的月份第 1 天”（例如对公收款 8 月的 34 笔、9 月的 21 笔都记到了 7 月）。
// 本工具用原表保存的“时间”列重新识别日期并改正 order_date，只处理“当时没认出时间”的行（raw_data 里没有 __trade_time__）。
// 用法：php tools/repair_shop_order_dates.php [店铺名] [--apply]     默认只读，列出会改动的行数 / 金额按月份的迁移情况
// --apply 会把改动前的 (id, order_date) 写到 storage/private/ 下的备份文件，便于回滚。
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
chdir($root);
require_once $root . '/includes/ProjectIntake.php';
require_once $root . '/includes/ShopOrderDate.php';
$apply = in_array('--apply', $argv, true);
$shopFilter = '';
foreach (array_slice($argv, 1) as $a) if ($a !== '--apply') $shopFilter = $a;
$pdo = db();
$sql = "SELECT id,shop,order_no,order_amount,order_date,raw_data FROM orders WHERE employee_id=0 AND order_scope='department' AND COALESCE(is_deleted,0)=0 AND raw_data LIKE '%\"__shop__\"%'" . ($shopFilter !== '' ? ' AND shop=' . $pdo->quote($shopFilter) : '') . ' ORDER BY id';
$rank = function ($k) {
    if (mb_strpos($k, '付款时间') !== false) return 1;
    if (mb_strpos($k, '交易时间') !== false) return 2;
    if (preg_match('/下单时间|创建时间|拍下时间/u', $k)) return 3;
    return (mb_strpos($k, '时间') !== false && !preg_match('/发货|收货|确认|结束|完成|更新|修改/u', $k)) ? 4 : 0;
};
$move = []; $backup = []; $checked = 0; $noParse = 0; $upd = $pdo->prepare('UPDATE orders SET order_date=?, raw_data=? WHERE id=?');
foreach ($pdo->query($sql) as $row) {
    $checked++;
    $raw = json_decode((string)$row['raw_data'], true);
    if (!is_array($raw) || !empty($raw['__trade_time__'])) continue;
    $best = null; $bestRank = 9;
    foreach ($raw as $k => $v) { if (strpos((string)$k, '__') === 0) continue; $r = $rank((string)$k); if ($r > 0 && $r < $bestRank) { $best = $v; $bestRank = $r; } }
    if ($best === null) continue;
    $p = sod_parse($best);
    if ($p === null) { $noParse++; continue; }
    if ($p['date'] === $row['order_date'] && $p['time'] !== '') { /* 日期本来就对：只补 __trade_time__ */ }
    $key = $row['shop'] . ' ' . substr($row['order_date'], 0, 7) . ' → ' . substr($p['date'], 0, 7);
    if ($p['date'] !== $row['order_date']) { $move[$key]['n'] = ($move[$key]['n'] ?? 0) + 1; $move[$key]['sum'] = ($move[$key]['sum'] ?? 0) + (float)$row['order_amount']; }
    if ($apply) {
        $backup[] = [(int)$row['id'], $row['order_date']];
        $raw['__trade_time__'] = $p['time']; unset($raw['__date_fallback__']);
        $upd->execute([$p['date'], json_encode($raw, JSON_UNESCAPED_UNICODE), (int)$row['id']]);
    }
}
echo "检查 {$checked} 笔店铺流水；时间认不出来的 {$noParse} 笔保持不变\n";
ksort($move);
foreach ($move as $k => $m) echo sprintf("  %s：%d 笔 ¥%s\n", $k, $m['n'], number_format($m['sum'], 2));
if (!$move) echo "  没有需要改月份的订单\n";
if ($apply && $backup) {
    $dir = $root . '/storage/private/repair';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/shop_order_dates_' . date('Ymd_His') . '.json';
    file_put_contents($file, json_encode($backup));
    echo "已更新 " . count($backup) . " 笔（含只补时间的），改动前日期备份：$file\n";
} elseif (!$apply) echo "（只读预览，加 --apply 才会写入）\n";
