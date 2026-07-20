<?php
/**
 * 历史数据迁移：为已上传订单的 raw_data 补存 __original_price__（售价）
 *
 * 运行方式：php migrate_original_price.php
 * 或浏览器访问：https://your-domain/migrate_original_price.php
 *
 * 逻辑：
 *   - 扫描所有 raw_data 里没有 __original_price__ 的订单
 *   - 如果 raw_data 里有"价格/售价"列 → __original_price__ = 该列值
 *   - 如果只有"订单金额"列（无价格/成本） → __original_price__ = 订单金额值
 *   - 如果有"订单金额"列（且有价格/成本） → __original_price__ = 订单金额（因为金额列本身就是售价）
 *   - 已有 __original_price__ 的跳过
 */

@ini_set('memory_limit', '1024M');
@set_time_limit(0);

require_once __DIR__ . '/config/database.php';

$pdo = db();

// 表头关键词
$priceKeys = ['价格', '售价', 'price', 'Price', 'PRICE'];
$amountKeys = ['订单金额', '金额', 'amount', 'Amount'];
$costKeys = ['成本', '总成本', 'cost', 'Cost'];

function find_col_value($rawMap, $keyList) {
    // 精确匹配
    foreach ($keyList as $k) {
        if (isset($rawMap[$k])) {
            $v = trim((string)$rawMap[$k]);
            if ($v !== '' && is_numeric(preg_replace('/[^\d.\-]/', '', $v))) {
                return (float)preg_replace('/[^\d.\-]/', '', $v);
            }
        }
    }
    // 模糊匹配
    foreach ($rawMap as $key => $val) {
        if (strpos($key, '__') === 0) continue;
        if (!is_string($val)) continue;
        $v = trim($val);
        if ($v === '' || !is_numeric(preg_replace('/[^\d.\-]/', '', $v))) continue;
        foreach ($keyList as $k) {
            if (mb_strpos($key, $k) !== false || stripos($key, $k) !== false) {
                return (float)preg_replace('/[^\d.\-]/', '', $v);
            }
        }
    }
    return null;
}

// 分批处理，避免内存溢出
$batchSize = 500;
$offset = 0;
$totalMigrated = 0;
$totalSkipped = 0;
$totalFailed = 0;
$log = [];

echo "<pre>\n";
echo "=== 历史数据迁移：补存 __original_price__ ===\n\n";

while (true) {
    $stmt = $pdo->prepare(
        "SELECT id, order_amount, raw_data FROM orders
         WHERE COALESCE(is_deleted, 0) = 0
           AND raw_data IS NOT NULL AND raw_data <> ''
         ORDER BY id ASC LIMIT $batchSize OFFSET $offset"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (empty($rows)) break;

    foreach ($rows as $row) {
        $rawMap = json_decode($row['raw_data'], true);
        if (!is_array($rawMap)) {
            $totalFailed++;
            continue;
        }

        // 已有 __original_price__，跳过
        if (isset($rawMap['__original_price__']) && $rawMap['__original_price__'] > 0) {
            $totalSkipped++;
            continue;
        }

        // 尝试从 raw_data 提取售价
        $price = find_col_value($rawMap, $priceKeys);
        $amount = find_col_value($rawMap, $amountKeys);
        $cost = find_col_value($rawMap, $costKeys);

        $originalPrice = 0;

        if ($price !== null && $price > 0) {
            // 有售价列 → 直接用售价
            $originalPrice = $price;
        } elseif ($amount !== null && $amount > 0) {
            // 无售价列但有订单金额列
            if ($cost !== null) {
                // 同时有成本列 → 订单金额 = 售价 - 成本（利润），需还原售价
                // 但无法准确还原（不知道手续费），用 order_amount + cost 近似
                $originalPrice = $amount + $cost;
            } else {
                // 只有订单金额列 → 订单金额就是售价
                $originalPrice = $amount;
            }
        } else {
            // raw_data 里找不到价格信息，用 order_amount 兜底
            $originalPrice = (float)$row['order_amount'];
        }

        if ($originalPrice > 0) {
            $rawMap['__original_price__'] = $originalPrice;
            $newRaw = json_encode($rawMap, JSON_UNESCAPED_UNICODE);

            $upd = $pdo->prepare("UPDATE orders SET raw_data = ? WHERE id = ?");
            $upd->execute([$newRaw, $row['id']]);
            $totalMigrated++;
        } else {
            $totalSkipped++;
        }
    }

    $offset += $batchSize;
    echo "  已处理 $offset 条... (迁移 $totalMigrated, 跳过 $totalSkipped, 失败 $totalFailed)\n";
    flush();
}

echo "\n=== 迁移完成 ===\n";
echo "总迁移：$totalMigrated 条\n";
echo "总跳过（已有或无价格）：$totalSkipped 条\n";
echo "总失败（raw_data解析错误）：$totalFailed 条\n";
echo "</pre>\n";
