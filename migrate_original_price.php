<?php
/**
 * 历史数据迁移：为已上传订单的 raw_data 补存 __original_price__（售价）
 *
 * 运行方式：浏览器访问 migrate_original_price.php
 * 支持分页：migrate_original_price.php?page=2
 *
 * 优化：只查需要迁移的行（NOT LIKE '%__original_price__%'），事务批量更新，
 *       每批100条，每页处理10批后输出进度并停止，需手动翻页继续。
 */

@ini_set('memory_limit', '256M');
@set_time_limit(300); // 5分钟超时，避免长时间占用连接

require_once __DIR__ . '/config/database.php';
$pdo = db();

// 表头关键词
$priceKeys  = ['价格', '售价', 'price', 'Price', 'PRICE'];
$amountKeys = ['订单金额', '金额', 'amount', 'Amount'];
$costKeys   = ['成本', '总成本', 'cost', 'Cost'];

function find_col_value($rawMap, $keyList) {
    foreach ($keyList as $k) {
        if (isset($rawMap[$k])) {
            $v = trim((string)$rawMap[$k]);
            $num = preg_replace('/[^\d.\-]/', '', $v);
            if ($v !== '' && $num !== '' && is_numeric($num)) return (float)$num;
        }
    }
    foreach ($rawMap as $key => $val) {
        if (strpos($key, '__') === 0) continue;
        if (!is_string($val)) continue;
        $v = trim($val);
        $num = preg_replace('/[^\d.\-]/', '', $v);
        if ($v === '' || $num === '' || !is_numeric($num)) continue;
        foreach ($keyList as $k) {
            if (mb_strpos($key, $k) !== false || stripos($key, $k) !== false) return (float)$num;
        }
    }
    return null;
}

$page     = max(1, (int)($_GET['page'] ?? 1));
$batchSize = 100;   // 每批查询+更新的行数
$batchesPerPage = 10; // 每页处理10批 = 1000条

// 首页先统计总数
$totalToMigrate = 0;
$totalPages = 0;
if ($page === 1) {
    $cntStmt = $pdo->query(
        "SELECT COUNT(*) FROM orders
         WHERE COALESCE(is_deleted, 0) = 0
           AND raw_data IS NOT NULL AND raw_data <> ''
           AND raw_data NOT LIKE '%__original_price__%'"
    );
    $totalToMigrate = (int)$cntStmt->fetchColumn();
    $totalPages = $totalToMigrate > 0 ? (int)ceil($totalToMigrate / ($batchSize * $batchesPerPage)) : 0;
    echo "需迁移总数：{$totalToMigrate} 条，共 {$totalPages} 页（每页1000条）\n\n";
    flush();
}

$totalMigrated = 0;
$totalSkipped  = 0;
$totalFailed   = 0;
$processed     = 0;

echo "<pre>\n";
if ($page > 1) echo "=== 历史数据迁移：补存 __original_price__ (第 {$page} 页) ===\n\n";
flush();

for ($b = 0; $b < $batchesPerPage; $b++) {
    // 只查 raw_data 里还没有 __original_price__ 的订单
    $offset = ($page - 1) * $batchesPerPage * $batchSize + $b * $batchSize;
    $stmt = $pdo->prepare(
        "SELECT id, order_amount, raw_data FROM orders
         WHERE COALESCE(is_deleted, 0) = 0
           AND raw_data IS NOT NULL AND raw_data <> ''
           AND raw_data NOT LIKE '%__original_price__%'
         ORDER BY id ASC LIMIT $batchSize OFFSET $offset"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        echo "\n没有更多需要迁移的数据了。\n";
        break;
    }

    // 事务批量更新
    $pdo->beginTransaction();
    $upd = $pdo->prepare("UPDATE orders SET raw_data = ? WHERE id = ?");
    foreach ($rows as $row) {
        $rawMap = json_decode($row['raw_data'], true);
        if (!is_array($rawMap)) { $totalFailed++; continue; }

        $price  = find_col_value($rawMap, $priceKeys);
        $amount = find_col_value($rawMap, $amountKeys);
        $cost   = find_col_value($rawMap, $costKeys);

        $originalPrice = 0;
        if ($price !== null && $price > 0) {
            $originalPrice = $price;
        } elseif ($amount !== null && $amount > 0) {
            $originalPrice = ($cost !== null) ? $amount + $cost : $amount;
        } else {
            $originalPrice = (float)$row['order_amount'];
        }

        if ($originalPrice > 0) {
            $rawMap['__original_price__'] = $originalPrice;
            $upd->execute([json_encode($rawMap, JSON_UNESCAPED_UNICODE), $row['id']]);
            $totalMigrated++;
        } else {
            $totalSkipped++;
        }
        $processed++;
    }
    $pdo->commit();

    echo "  批 " . ($b + 1) . ": 处理 " . count($rows) . " 条 (累计迁移 $totalMigrated, 跳过 $totalSkipped, 失败 $totalFailed)\n";
    flush();
}

$hasMore = ($processed >= $batchesPerPage * $batchSize) && $totalMigrated > 0;

echo "\n=== 第 {$page} 页完成 ===\n";
echo "本页迁移：$totalMigrated 条\n";
echo "本页跳过：$totalSkipped 条\n";
echo "本页失败：$totalFailed 条\n";

if ($hasMore) {
    $nextPage = $page + 1;
    echo "\n<a href='?page=$nextPage'>点击继续处理第 $nextPage 页 &raquo;</a>\n";
    echo "<script>setTimeout(function(){window.location.href='?page=$nextPage';},2000);</script>\n";
    echo "<span class='text-muted'>2秒后自动继续... (第 {$page}/{$totalPages} 页)</span>\n";
} else {
    echo "\n<b>全部迁移完成！</b>\n";
}
echo "</pre>\n";
