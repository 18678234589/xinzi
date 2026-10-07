<?php
/**
 * ETMLL订单同步
 * 从ETMLL财务系统（jujian库 order 表）拉取店铺订单，写入本站 orders 表。
 * 写入规范与 shops/upload.php 手动上传保持一致：
 *   employee_id=0 / order_scope='department' / shop=店铺名 / order_no=订单编号
 *   raw_data 保存 __shop__ / __trade_time__ / __original_price__ / __order_status__ / __etmll_id__
 *   及原始业务字段（商品标题/佣金/合伙人公司等），供订单详情弹窗展示。
 * 增量规则：按店铺+订单号匹配店铺流水；个人订单不阻止店铺同步。已同步来源仍检查变化。
 * 更新规则：仅刷新 ETMLL 来源流水；手动上传的已有流水只关联与补全项目空字段；回收站不恢复。
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
    $pdo = db();
    etmll_sync_state_table($pdo);
    $epdo = etmll_connect(true);
    $src = $epdo->query("SELECT o.id,o.order_no,o.total_amount,o.refund_amount,o.raw_status,
        o.product_title,o.order_pay_time,o.shipping_time,o.created_at,o.shop_name,
        o.merchant_order_no,o.commission,o.proxy_amount,m.name AS merchant_name,p.name AS partner_name
        FROM `order` o LEFT JOIN merchant m ON m.id=o.merchant_id LEFT JOIN partner p ON p.id=o.partner_id
        WHERE o.status=0 AND COALESCE(o.order_pay_time,o.created_at)>='" . date('Y-m-d', strtotime('-1 year')) . "' ORDER BY o.id ASC");
    try {
        return etmll_sync_orders($pdo, $src, $dryRun);
    } finally {
        $src->closeCursor();
    }
}

/** 来源字段签名不含每次变化的同步时间，也不包含人工附加字段。 */
function etmll_sync_signature($amount, $date, $shop, $orderNo, array $raw): string
{
    $data = [number_format((float)$amount, 2, '.', ''), (string)$date, (string)$shop, (string)$orderNo];
    foreach (['__shop__','__order_status__','__trade_time__','订单编号','店铺','商品标题','订单状态','付款时间','发货时间','商户','合伙人公司','商家订单号','数据来源'] as $key) {
        $data[] = trim((string)($raw[$key] ?? ''));
    }
    foreach (['__original_price__','订单金额','退款金额','佣金','代垫金额'] as $key) {
        $data[] = number_format((float)($raw[$key] ?? 0), 2, '.', '');
    }
    $data[] = (int)($raw['__etmll_id__'] ?? 0);
    $data[] = empty($raw['__is_refund__']) ? 0 : 1;
    return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
}

function etmll_sync_identity($shop, $orderNo): string
{
    return trim((string)$shop) . "\0" . trim((string)$orderNo);
}

/** 分批写关联记录，避免历史手动流水首次关联时逐条往返数据库。 */
function etmll_sync_mark_batch(PDO $pdo, array $rows)
{
    if (!$rows) return;
    $values = [];
    foreach ($rows as $row) foreach ($row as $value) $values[] = $value;
    $q = $pdo->prepare('INSERT INTO etmll_sync_state (etmll_order_id,order_no,shop,order_amount) VALUES '
        . implode(',',array_fill(0,count($rows),'(?,?,?,?)'))
        . ' ON DUPLICATE KEY UPDATE order_no=VALUES(order_no),shop=VALUES(shop),order_amount=VALUES(order_amount),synced_at=CURRENT_TIMESTAMP');
    $q->execute($values);
}

/** 只读取部门店铺流水，个人订单与店铺流水是两种关联记录，不能相互阻止导入。 */
function etmll_sync_inventory(PDO $pdo): array
{
    $byIdentity = []; $bySource = [];
    $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    try {
        $q = $pdo->query("SELECT id,order_no,shop,order_amount,order_date,raw_data,COALESCE(is_deleted,0) AS deleted
            FROM orders WHERE employee_id=0 AND order_scope='department' ORDER BY id ASC");
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $entry = ['id'=>(int)$row['id'], 'deleted'=>(bool)$row['deleted']];
            $key = etmll_sync_identity($row['shop'], $row['order_no']);
            // 同号多行时优先保留仍在使用的记录；全部在回收站时不恢复。
            if (trim((string)$row['order_no']) !== '' && (!isset($byIdentity[$key]) || !$entry['deleted'] || $byIdentity[$key]['deleted'])) $byIdentity[$key] = $entry;
            $raw = json_decode((string)$row['raw_data'], true);
            $sourceId = is_array($raw) ? (int)($raw['__etmll_id__'] ?? 0) : 0;
            if ($sourceId > 0 && (!isset($bySource[$sourceId]) || !$entry['deleted'] || $bySource[$sourceId]['deleted'])) {
                $entry['signature'] = etmll_sync_signature($row['order_amount'], $row['order_date'], $row['shop'], $row['order_no'], $raw);
                $bySource[$sourceId] = $entry;
            }
        }
        $q->closeCursor();
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
    }
    return [$byIdentity, $bySource];
}

/** 流式处理来源；来源标记用于关联与去重，不能用来跳过后续变更。 */
function etmll_sync_orders(PDO $pdo, iterable $sourceRows, bool $dryRun = false): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('同步必须在独立事务中运行');
    $lockName = 'etmll_sync_' . substr(hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
    $locked = false;
    if (!$dryRun) {
        $lock = $pdo->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('已有订单同步正在进行，请稍后刷新查看结果');
        $locked = true;
    }
    try {
        [$byIdentity, $bySource] = etmll_sync_inventory($pdo);
        $done = [];
        foreach ($pdo->query('SELECT etmll_order_id,order_no,shop,order_amount FROM etmll_sync_state') as $row) $done[(int)$row['etmll_order_id']] = $row;
        $shopExists = array_fill_keys($pdo->query('SELECT name FROM shops')->fetchAll(PDO::FETCH_COLUMN), true);
        $projectNos = [];
        try {
            foreach ($pdo->query("SELECT order_no FROM project_orders WHERE settlement_status NOT IN ('approved','locked')") as $row) $projectNos[$row['order_no']] = true;
        } catch (PDOException $ex) {
            if (!in_array($ex->getCode(), ['42S02','42S22'], true)) throw $ex;
        }
        $insert = $pdo->prepare("INSERT INTO orders (employee_id,order_amount,order_date,project,shop,order_no,raw_data,is_abnormal,abnormal_reason,order_scope)
            VALUES (0,?,?,'',?,?,?,?,?,'department')");
        $update = $pdo->prepare('UPDATE orders SET order_amount=?,order_date=?,shop=?,order_no=?,raw_data=?,
            is_abnormal=CASE WHEN abnormal_reason IN (\'\',\'订单金额为0\') THEN ? ELSE is_abnormal END,
            abnormal_reason=CASE WHEN abnormal_reason IN (\'\',\'订单金额为0\') THEN ? ELSE abnormal_reason END WHERE id=? AND COALESCE(is_deleted,0)=0');
        $read = $pdo->prepare('SELECT raw_data FROM orders WHERE id=? AND COALESCE(is_deleted,0)=0 FOR UPDATE');
        $matchNew = $pdo->prepare("SELECT id,COALESCE(is_deleted,0) AS deleted FROM orders WHERE employee_id=0 AND order_scope='department' AND shop=? AND order_no=? ORDER BY COALESCE(is_deleted,0),id DESC LIMIT 1 FOR UPDATE");
        $pendingMarks = [];
        $addShop = $pdo->prepare('INSERT INTO shops (name,sort) VALUES (?,99)');
        $result = ['dry_run'=>$dryRun,'scanned'=>0,'inserted'=>0,'updated'=>0,'linked_existing'=>0,'project_filled'=>0,
            'skipped_existing'=>0,'skipped_done'=>0,'skipped_unpaid'=>0,'skipped_no_shop'=>0,'skipped_deleted'=>0,
            'skipped_missing_local'=>0,'by_shop'=>[],'by_shop_updated'=>[],'by_shop_linked'=>[],'new_shops'=>[]];
        $newShops = [];
        if (!$dryRun) $pdo->beginTransaction();
        try {
            foreach ($sourceRows as $o) {
                $result['scanned']++;
                $eid = (int)$o['id'];
                if (mb_strpos(trim((string)$o['raw_status']), '等待买家付款') !== false) { $result['skipped_unpaid']++; continue; }
                $m = etmll_map_order($o);
                if ($m['shop'] === '') { $result['skipped_no_shop']++; continue; }
                $key = etmll_sync_identity($m['shop'], $m['order_no']);
                $own = isset($bySource[$eid]);
                $existing = $bySource[$eid] ?? ($m['order_no'] !== '' ? ($byIdentity[$key] ?? null) : null);
                if (!$existing && isset($done[$eid])) { $result['skipped_missing_local']++; continue; }
                // 避免读取快照之后，另一个上传刚刚新增同一店铺订单。
                if (!$existing && !$dryRun && $m['order_no'] !== '') {
                    $matchNew->execute([$m['shop'],$m['order_no']]);
                    $existing = $matchNew->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                if ($existing && $existing['deleted']) { $result['skipped_deleted']++; continue; }
                $raw = json_decode($m['raw_json'], true);
                $signature = etmll_sync_signature($m['amount'], $m['order_date'], $m['shop'], $m['order_no'], $raw);
                $isUpdate = $own && $existing['signature'] !== $signature;
                $isInsert = !$existing;
                $isLink = !$isInsert && !isset($done[$eid]);
                $legacyId = $existing ? (int)$existing['id'] : -$eid;
                if ($isInsert && !isset($shopExists[$m['shop']])) {
                    if (!$dryRun) $addShop->execute([$m['shop']]);
                    $shopExists[$m['shop']] = true;
                    $newShops[$m['shop']] = true;
                }
                if ($isInsert) {
                    if (!$dryRun) {
                        $insert->execute([$m['amount'],$m['order_date'],$m['shop'],$m['order_no'],$m['raw_json'],$m['is_abnormal'],$m['abnormal_reason']]);
                        $legacyId = (int)$pdo->lastInsertId();
                    }
                    $result['inserted']++;
                    $result['by_shop'][$m['shop']] = ($result['by_shop'][$m['shop']] ?? 0) + 1;
                } elseif ($isUpdate) {
                    if (!$dryRun) {
                        $read->execute([$legacyId]);
                        $old = $read->fetch(PDO::FETCH_ASSOC);
                        if (!$old) { $result['skipped_deleted']++; continue; }
                        $merged = json_decode((string)$old['raw_data'], true);
                        if (!is_array($merged)) $merged = [];
                        foreach ($raw as $field => $value) $merged[$field] = $value;
                        if (!isset($raw['__is_refund__'])) unset($merged['__is_refund__']);
                        $update->execute([$m['amount'],$m['order_date'],$m['shop'],$m['order_no'],json_encode($merged,JSON_UNESCAPED_UNICODE),$m['is_abnormal'],$m['abnormal_reason'],$legacyId]);
                    }
                    $result['updated']++;
                    $result['by_shop_updated'][$m['shop']] = ($result['by_shop_updated'][$m['shop']] ?? 0) + 1;
                } else {
                    if ($own) $result['skipped_done']++; else $result['skipped_existing']++;
                }
                if ($isLink) {
                    $result['linked_existing']++;
                    $result['by_shop_linked'][$m['shop']] = ($result['by_shop_linked'][$m['shop']] ?? 0) + 1;
                }
                $state = $done[$eid] ?? null;
                if (!$dryRun && ($isInsert || $isUpdate || !$state || $state['order_no'] !== $m['order_no'] || $state['shop'] !== $m['shop'] || abs((float)$state['order_amount']-$m['amount'])>.005)) {
                    $pendingMarks[] = [$eid,$m['order_no'],$m['shop'],$m['amount']];
                    if (count($pendingMarks) >= 500) {
                        etmll_sync_mark_batch($pdo,$pendingMarks);
                        $pendingMarks = [];
                    }
                }
                $done[$eid] = ['order_no'=>$m['order_no'],'shop'=>$m['shop'],'order_amount'=>$m['amount']];
                $entry = ['id'=>$legacyId,'deleted'=>false];
                if ($m['order_no'] !== '') $byIdentity[$key] = $entry;
                if ($isInsert || $own) $bySource[$eid] = $entry + ['signature'=>$signature];
                // 已存在的流水同样尝试补全后来创建的项目订单，保护人工与已审核数据。
                if (!$dryRun && isset($projectNos[$m['order_no']]) && $m['amount'] > 0) {
                    if (ps_sync_project_from_shop_order($legacyId,$m['order_no'],$m['shop'],$raw,(float)$o['total_amount'])) $result['project_filled']++;
                }
            }
            if (!$dryRun) {
                etmll_sync_mark_batch($pdo,$pendingMarks);
                $pdo->commit();
            }
        } catch (Throwable $ex) {
            if (!$dryRun && $pdo->inTransaction()) $pdo->rollBack();
            throw $ex;
        }
        foreach (['by_shop','by_shop_updated','by_shop_linked'] as $field) arsort($result[$field]);
        $result['new_shops'] = array_keys($newShops);
        return $result;
    } finally {
        if ($locked) {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }
}

/**
 * 同步概览：ETMLL/本站订单对照、已同步数量、最近同步时间
 */
function etmll_sync_status(): array
{
    $pdo  = db();
    $epdo = etmll_connect();

    etmll_sync_state_table($pdo);

    $sourceInfo = $epdo->query("SELECT COUNT(*) AS c,MAX(created_at) AS latest_created,MAX(order_pay_time) AS latest_paid FROM `order` WHERE status=0")->fetch(PDO::FETCH_ASSOC);
    $etmllTotal = (int)$sourceInfo['c'];

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
    $lastRun = null;
    try {
        $lastRun = $pdo->query("SELECT MAX(created_at) FROM project_audit_logs WHERE entity_type='etmll_sync' AND action='sync'")->fetchColumn() ?: null;
    } catch (PDOException $ex) {
        if (!in_array($ex->getCode(), ['42S02','42S22'], true)) throw $ex;
    }

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
        'last_run_at'  => $lastRun,
        'latest_source_created' => $sourceInfo['latest_created'],
        'latest_source_paid' => $sourceInfo['latest_paid'],
        'shops'        => $shops,
    ];
}
