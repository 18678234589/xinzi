<?php
/**
 * 历史数据迁移（修正版）：
 * 1. 优先从 raw_data 的"价格/售价"列提取售价
 * 2. 没有"价格"列的才用 order_amount
 * 3. 已有 __original_price__ 但值≠价格列的也修正
 *
 * 访问 migrate_original_price.php?page=1  分页执行
 */
@ini_set('memory_limit', '256M');
@set_time_limit(300);
require_once __DIR__ . '/config/database.php';
$pdo = db();

$page = max(1, (int)($_GET['page'] ?? 1));
$batch = 200;

// 价格列关键词
$priceKeys = ['价格', '售价', 'price', 'Price', 'PRICE'];

function find_price_in_raw($rawMap, $priceKeys) {
    if (!is_array($rawMap)) return null;
    // 精确匹配
    foreach ($priceKeys as $k) {
        if (isset($rawMap[$k])) {
            $v = trim((string)$rawMap[$k]);
            $num = preg_replace('/[^\d.\-]/', '', $v);
            if ($v !== '' && $num !== '' && is_numeric($num) && (float)$num > 0) {
                return (float)$num;
            }
        }
    }
    // 模糊匹配
    foreach ($rawMap as $key => $val) {
        if (strpos($key, '__') === 0) continue;
        if (!is_string($val)) continue;
        $v = trim($val);
        $num = preg_replace('/[^\d.\-]/', '', $v);
        if ($v === '' || $num === '' || !is_numeric($num) || (float)$num <= 0) continue;
        foreach ($priceKeys as $k) {
            if (mb_strpos($key, $k) !== false) return (float)$num;
        }
    }
    return null;
}

echo "<pre>\n=== 修正迁移 __original_price__（第{$page}页）===\n\n";

if ($page === 1) {
    $cnt = $pdo->query(
        "SELECT COUNT(*) FROM orders
         WHERE COALESCE(is_deleted,0)=0 AND raw_data IS NOT NULL AND raw_data <> ''"
    )->fetchColumn();
    $totalPages = ceil($cnt / $batch);
    echo "总订单数：{$cnt}，每页{$batch}条，约{$totalPages}页\n\n";
    flush();
}

$offset = ($page - 1) * $batch;
$stmt = $pdo->prepare(
    "SELECT id, order_amount, raw_data FROM orders
     WHERE COALESCE(is_deleted,0)=0 AND raw_data IS NOT NULL AND raw_data <> ''
     ORDER BY id ASC LIMIT $batch OFFSET $offset"
);
$stmt->execute();
$rows = $stmt->fetchAll();

if (empty($rows)) {
    echo "没有更多数据了。\n</pre>\n";
    exit;
}

$upd = $pdo->prepare("UPDATE orders SET raw_data = ? WHERE id = ?");
$fixed = 0;
$skipped = 0;

$pdo->beginTransaction();
foreach ($rows as $r) {
    $rawMap = json_decode($r['raw_data'], true);
    if (!is_array($rawMap)) { $skipped++; continue; }

    // 从 raw_data 提取"价格/售价"列
    $price = find_price_in_raw($rawMap, $priceKeys);

    // 当前已存的 __original_price__
    $currentOP = isset($rawMap['__original_price__']) ? (float)$rawMap['__original_price__'] : null;

    if ($price !== null && $price > 0) {
        // 有价格列 → 售价 = 价格列值
        if ($currentOP !== $price) {
            $rawMap['__original_price__'] = $price;
            $upd->execute([json_encode($rawMap, JSON_UNESCAPED_UNICODE), $r['id']]);
            $fixed++;
        } else {
            $skipped++;
        }
    } else {
        // 没有价格列 → 用 order_amount 兜底
        if ($currentOP === null) {
            $rawMap['__original_price__'] = (float)$r['order_amount'];
            $upd->execute([json_encode($rawMap, JSON_UNESCAPED_UNICODE), $r['id']]);
            $fixed++;
        } else {
            $skipped++;
        }
    }
}
$pdo->commit();

$processed = count($rows);
echo "本页处理 {$processed} 条，修正 {$fixed} 条，跳过 {$skipped} 条\n";

$hasMore = $processed >= $batch;
if ($hasMore) {
    $next = $page + 1;
    echo "\n<a href='?page=$next'>继续第{$next}页 &raquo;</a>\n";
    echo "<script>setTimeout(function(){location.href='?page=$next';},1500);</script>\n";
    echo "<span class='muted'>1.5秒后自动继续...</span>\n";
} else {
    echo "\n<b>全部完成！</b>\n";
}
echo "</pre>\n";
