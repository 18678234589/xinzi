<?php
/**
 * ETMLL订单同步
 * 从ETMLL财务系统（jujian库 order 表）拉取店铺订单，写入本站 orders 表。
 * 写入规范与 shops/upload.php 手动上传保持一致：
 *   employee_id=0 / order_scope='department' / shop=店铺名 / order_no=订单编号
 *   raw_data 保存 __shop__ / __trade_time__ / __original_price__ / __order_status__ / __etmll_id__
 *   及原始业务字段（商品标题/佣金/合伙人公司等），供订单详情弹窗展示。
 * 增量规则：order_no 已存在于本站（含回收站）的跳过；已同步过的按 etmll_sync_state 水位表跳过。
 * 金额规则：交易关闭按退款单记负数（总额为0时按退款额入账）；部分退款记净额（总额-退款）；其余记订单总额。
 * 退款标记：入账为负数的订单 raw_data 写 __is_refund__=1，供"只看退款"筛选使用；净额为正的部分退款靠"退款金额"字段展示角标。
 */
require_once __DIR__ . '/../config/etmll.php';
require_once __DIR__ . '/ProjectOrderSource.php';

/**
 * 连接ETMLL数据库
 * @param bool $stream true 时使用无缓冲连接（流式读取大结果集，控制内存）
 */
function etmll_connect(bool $stream = false)
{
    static $buffered = null, $unbuffered = null;
    $key = $stream ? 'unbuffered' : 'buffered';
    if (${$key} === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', ETMLL_DB_HOST, ETMLL_DB_PORT, ETMLL_DB_NAME);
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ];
        // 读写超时：SSH隧道中断时快速失败，避免握手中途挂死（mysqlnd 才支持这两个常量）
        if (defined('PDO::MYSQL_ATTR_READ_TIMEOUT')) {
            $opts[PDO::MYSQL_ATTR_READ_TIMEOUT]  = 30;
            $opts[PDO::MYSQL_ATTR_WRITE_TIMEOUT] = 30;
        }
        if ($stream) {
            $opts[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
        }
        ${$key} = new PDO($dsn, ETMLL_DB_USER, ETMLL_DB_PASS, $opts);
    }
    return ${$key};
}

/**
 * ETMLL订单 → 本站店铺流水字段映射
 */
function etmll_map_order(array $o): array
{
    $total     = (float)$o['total_amount'];
    $refund    = (float)$o['refund_amount'];
    $rawStatus = trim((string)$o['raw_status']);
    $isClosed  = mb_strpos($rawStatus, '交易关闭') !== false;

    if ($isClosed) {
        // 关闭订单按退款单展示（负数金额）；源表 total 为 0 时按退款额入账，避免入 0 元被误标异常
        $base = $total > 0 ? $total : $refund;
        $amount = -round($base, 2);
    } elseif ($refund > 0) {
        $amount = round($total - $refund, 2);     // 部分退款记净额
    } else {
        $amount = round($total, 2);
    }

    $payTime = trim((string)($o['order_pay_time'] ?? ''));
    if ($payTime === '') {
        $payTime = trim((string)($o['created_at'] ?? ''));
    }
    $payTime = substr($payTime, 0, 19);           // 去掉毫秒 ".000"
    $orderDate = preg_match('/^\d{4}-\d{2}-\d{2}/', $payTime) ? substr($payTime, 0, 10) : date('Y-m-d');

    $shop = trim((string)$o['shop_name']);
    if ($shop === '') {
        $shop = trim((string)($o['merchant_name'] ?? ''));
    }

    $raw = [
        '__shop__'            => $shop,
        '__order_status__'    => $rawStatus,
        '__trade_time__'      => $payTime,
        '__original_price__'  => $total,          // 售价（与员工订单对账匹配用）
        '__etmll_id__'        => (int)$o['id'],
        '__etmll_synced_at__' => date('Y-m-d H:i:s'),
        '订单编号'   => trim((string)$o['order_no']),
        '店铺'       => $shop,
        '商品标题'   => trim((string)($o['product_title'] ?? '')),
        '订单金额'   => $total,
        '退款金额'   => $refund > 0 ? $refund : '',
        '订单状态'   => $rawStatus,
        '付款时间'   => $payTime,
        '发货时间'   => substr(trim((string)($o['shipping_time'] ?? '')), 0, 19),
        '商户'       => trim((string)($o['merchant_name'] ?? '')),
        '合伙人公司' => trim((string)($o['partner_name'] ?? '')),
        '佣金'       => (float)$o['commission'],
        '代垫金额'   => (float)$o['proxy_amount'],
        '商家订单号' => trim((string)($o['merchant_order_no'] ?? '')),
        '数据来源'   => 'ETMLL自动同步',
    ];

    // 入账为负数 = 退款单，与手动上传的 __is_refund__ 口径一致（供订单页"只看退款"筛选）
    if ($amount < 0) {
        $raw['__is_refund__'] = '1';
    }

    return [
        'order_no'        => trim((string)$o['order_no']),
        'shop'            => $shop,
        'amount'          => $amount,
        'order_date'      => $orderDate,
        'is_abnormal'     => abs($amount) < 0.005 ? 1 : 0,
        'abnormal_reason' => abs($amount) < 0.005 ? '订单金额为0' : '',
        'raw_json'        => json_encode($raw, JSON_UNESCAPED_UNICODE),
    ];
}

function etmll_sync_state_table(PDO $pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `etmll_sync_state` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `etmll_order_id` INT NOT NULL UNIQUE COMMENT 'ETMLL order 表主键',
        `order_no` VARCHAR(64) NOT NULL DEFAULT '',
        `shop` VARCHAR(100) NOT NULL DEFAULT '',
        `order_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `synced_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ETMLL订单同步水位'");
}

/**
 * 执行同步
 * @param bool $dryRun true=只统计不写入
 * @return array inserted/by_shop/new_shops 等统计
 */
function etmll_sync_run(bool $dryRun = false): array
{
    $pdo  = db();
    $epdo = etmll_connect(true);

    etmll_sync_state_table($pdo);

    // 本站已有订单号（含回收站，避免已删除订单被重新同步）
    $have = [];
    foreach ($pdo->query("SELECT `order_no` FROM `orders` WHERE `order_no` <> ''") as $r) {
        $have[trim((string)$r['order_no'])] = 1;
    }

    // 已同步水位
    $done = [];
    foreach ($pdo->query("SELECT `etmll_order_id` FROM `etmll_sync_state`") as $r) {
        $done[(int)$r['etmll_order_id']] = 1;
    }

    // 本站已有店铺
    $shopExists = [];
    foreach ($pdo->query("SELECT `name` FROM `shops`") as $r) {
        $shopExists[$r['name']] = 1;
    }

    // ETMLL 有效订单（status=0；status=2 为ETMLL内已删除）
    $src = $epdo->query("SELECT o.`id`, o.`order_no`, o.`total_amount`, o.`refund_amount`, o.`raw_status`,
                                o.`product_title`, o.`order_pay_time`, o.`shipping_time`, o.`created_at`, o.`shop_name`,
                                o.`merchant_order_no`, o.`commission`, o.`proxy_amount`,
                                m.`name` AS merchant_name, p.`name` AS partner_name
                         FROM `order` o
                         LEFT JOIN `merchant` m ON m.`id` = o.`merchant_id`
                         LEFT JOIN `partner` p ON p.`id` = o.`partner_id`
                         WHERE o.`status` = 0
                         ORDER BY o.`id` ASC");

    $insert = $pdo->prepare("INSERT INTO `orders`
        (employee_id, order_amount, order_date, project, shop, order_no, raw_data, is_abnormal, abnormal_reason, order_scope)
        VALUES (0, ?, ?, '', ?, ?, ?, ?, ?, 'department')");
    $mark    = $pdo->prepare("INSERT INTO `etmll_sync_state` (etmll_order_id, order_no, shop, order_amount) VALUES (?, ?, ?, ?)");
    $addShop = $pdo->prepare("INSERT INTO `shops` (name, sort) VALUES (?, 99)");

    $inserted = 0; $projectFilled = 0; $skippedExisting = 0; $skippedDone = 0; $skippedUnpaid = 0; $skippedNoShop = 0;
    $byShop = []; $newShops = [];

    if (!$dryRun) {
        $pdo->beginTransaction();
    }
    try {
        while ($o = $src->fetch(PDO::FETCH_ASSOC)) {
            $eid = (int)$o['id'];
            if (isset($done[$eid])) {
                $skippedDone++;
                continue;
            }

            $rawStatus = trim((string)$o['raw_status']);
            if (mb_strpos($rawStatus, '等待买家付款') !== false) {
                $skippedUnpaid++;
                continue;
            }

            $m = etmll_map_order($o);
            if ($m['shop'] === '') {
                $skippedNoShop++;
                continue;
            }
            if ($m['order_no'] !== '' && isset($have[$m['order_no']])) {
                $skippedExisting++;
                continue;
            }

            if ($dryRun) {
                $inserted++;
                $byShop[$m['shop']] = ($byShop[$m['shop']] ?? 0) + 1;
                if (!isset($shopExists[$m['shop']])) {
                    $newShops[$m['shop']] = 1;
                }
                continue;
            }

            if (!isset($shopExists[$m['shop']])) {
                $addShop->execute([$m['shop']]);
                $shopExists[$m['shop']] = 1;
                $newShops[$m['shop']] = 1;
            }
            $insert->execute([$m['amount'], $m['order_date'], $m['shop'], $m['order_no'], $m['raw_json'], $m['is_abnormal'], $m['abnormal_reason']]);
            $legacyId = (int)$pdo->lastInsertId();
            $mark->execute([$eid, $m['order_no'], $m['shop'], $m['amount']]);
            // 与手动上传店铺订单一致：已建档的项目订单按订单号自动补空的售价/店铺/交易状态，不改人工填写和已审核数据。
            if ($m['order_no'] !== '' && $m['amount'] > 0) {
                try {
                    if (ps_sync_project_from_shop_order($legacyId, $m['order_no'], $m['shop'], json_decode($m['raw_json'], true), (float)$o['total_amount'])) $projectFilled++;
                } catch (PDOException $projectError) {
                    // 项目结算表未迁移时不影响店铺订单同步。
                }
            }
            $inserted++;
            $byShop[$m['shop']] = ($byShop[$m['shop']] ?? 0) + 1;
        }
        if (!$dryRun) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$dryRun && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    arsort($byShop);
    return [
        'dry_run'          => $dryRun,
        'inserted'         => $inserted,
        'project_filled'   => $projectFilled,
        'skipped_existing' => $skippedExisting,
        'skipped_done'     => $skippedDone,
        'skipped_unpaid'   => $skippedUnpaid,
        'skipped_no_shop'  => $skippedNoShop,
        'by_shop'          => $byShop,
        'new_shops'        => array_keys($newShops),
    ];
}

/**
 * 同步概览：ETMLL/本站订单对照、已同步数量、最近同步时间
 */
function etmll_sync_status(): array
{
    $pdo  = db();
    $epdo = etmll_connect();

    etmll_sync_state_table($pdo);

    $etmllTotal = (int)$epdo->query("SELECT COUNT(*) FROM `order` WHERE `status` = 0")->fetchColumn();

    $etmllByShop = [];
    foreach ($epdo->query("SELECT COALESCE(NULLIF(TRIM(o.`shop_name`), ''), TRIM(m.`name`)) AS shop, COUNT(*) AS c
                           FROM `order` o LEFT JOIN `merchant` m ON m.`id` = o.`merchant_id`
                           WHERE o.`status` = 0 GROUP BY shop ORDER BY c DESC") as $r) {
        if ($r['shop'] !== '') {
            $etmllByShop[$r['shop']] = (int)$r['c'];
        }
    }

    $localByShop = [];
    $localTotal = 0;
    foreach ($pdo->query("SELECT `shop`, COUNT(*) AS c FROM `orders`
                          WHERE `order_scope` = 'department' AND `shop` <> '' AND COALESCE(`is_deleted`, 0) = 0
                          GROUP BY `shop`") as $r) {
        $localByShop[$r['shop']] = (int)$r['c'];
        $localTotal += (int)$r['c'];
    }

    $syncInfo = $pdo->query("SELECT COUNT(*) AS c, MAX(`synced_at`) AS last_at FROM `etmll_sync_state`")->fetch(PDO::FETCH_ASSOC);

    $shops = [];
    foreach ($etmllByShop as $s => $c) {
        $shops[$s] = ['etmll' => $c, 'local' => $localByShop[$s] ?? 0];
    }
    foreach ($localByShop as $s => $c) {
        if (!isset($shops[$s])) {
            $shops[$s] = ['etmll' => 0, 'local' => $c];
        }
    }
    uasort($shops, function ($a, $b) {
        return $b['etmll'] <=> $a['etmll'];
    });

    return [
        'etmll_total'  => $etmllTotal,
        'local_total'  => $localTotal,
        'synced_total' => (int)$syncInfo['c'],
        'last_sync_at' => $syncInfo['last_at'],
        'shops'        => $shops,
    ];
}
