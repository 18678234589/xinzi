<?php
// 拼多多官方导出订单（csv，UTF-8 或 GBK）上传到店铺：订单号取“订单号”，金额取“商家实收金额(元)”（不是“多多支付立减金额”），日期取“订单成交时间”，
// 状态取订单状态（售后状态写退款成功的按退款记负数），商品标题别名；重传不重复。
// 会写入测试数据，只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/shop_upload_pdd_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (($argv[1] ?? '') === 'child') {
    // 子进程：模拟一次文件上传请求（成功后会 redirect + exit）
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['SCRIPT_NAME'] = '/shops/upload.php'; $_SERVER['REQUEST_URI'] = '/shops/upload.php';
    $_GET = ['shop_id' => (int)$argv[2]];
    $_POST = ['action' => 'upload', 'upload_month' => ''];
    $_FILES = ['excel_file' => ['name' => 'pdd.csv', 'tmp_name' => $argv[3], 'error' => UPLOAD_ERR_OK, 'size' => filesize($argv[3])]];
    require_once __DIR__ . '/../includes/auth.php';
    $_SESSION['admin_id'] = 1;
    register_shutdown_function(function () use ($argv) { if (!empty($argv[4]) && isset($GLOBALS['rq']['msg'])) file_put_contents($argv[4], urldecode((string)$GLOBALS['rq']['msg'])); });
    ob_start(); include __DIR__ . '/../shops/upload.php'; ob_end_clean();
    exit;
}
require_once __DIR__ . '/../includes/ProjectIntake.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会写入数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$head = ['商品', '订单号', '订单状态', '商品总价(元)', '邮费(元)', '店铺优惠折扣(元)', '平台优惠折扣(元)', '多多支付立减金额(元)', '用户实付金额(元)', '商家实收金额(元)', '商品数量(件)', '发货时间', '确认收货时间', '商品id', '商品规格', '样式ID', '商家编码-规格维度', '商家编码-商品维度', '商家备注', '售后状态', '快递单号', '快递公司', '订单成交时间', '是否分期'];
$mkRow = function ($title, $no, $status, $total, $discount, $paid, $recv, $time, $after = '无售后或售后取消') {
    return [$title, $no, $status, $total . "\t", '0.0' . "\t", $discount . "\t", '0.0' . "\t", '5.0' . "\t", $paid . "\t", $recv . "\t", '1', '', '', '1021989277485', '定制用户下单', '1994140696348', '', '', '张欣+纪鹏程+h5', $after, '', '', $time, '否'];
};
$rows = [$head,
    $mkRow('微信小程序开发定制APP软件', "261112-{$tag}0001", '已发货，待收货', '510.0', '10.0', '500.0', '505.0', '2026-11-10 12:39:23'),
    $mkRow('家政小程序制作', "261112-{$tag}0002", '待发货', '800.0', '8.0', '792.0', '792.0', '2026-11-09 09:09:18'),
    $mkRow('网站开发', "261112-{$tag}0003", '已收货', '300.0', '0.0', '300.0', '300.0', '2026-11-08 20:00:00'),
    $mkRow('退款的单', "261112-{$tag}0004", '已收货', '100.0', '0.0', '100.0', '100.0', '2026-11-07 10:00:00', '退款成功'),
];
$writeCsv = function (array $rows, $enc) {
    $f = tempnam(sys_get_temp_dir(), 'pdd'); $fp = fopen($f, 'w');
    foreach ($rows as $r) fputcsv($fp, $r);
    fclose($fp);
    $c = file_get_contents($f);
    file_put_contents($f, $enc === 'gbk' ? mb_convert_encoding($c, 'GB18030', 'UTF-8') : "\xEF\xBB\xBF" . $c);
    return $f;
};
$upload = function ($shopId, $file) {
    $msgFile = tempnam(sys_get_temp_dir(), 'sm');
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child ' . $shopId . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($msgFile) . ' 2>&1', $out);
    $msg = (string)@file_get_contents($msgFile); @unlink($msgFile);
    return implode("\n", $out) . $msg;
};
$shops = [];
try {
    foreach (['utf8' => 'ZZ拼多多U' . $tag, 'gbk' => 'ZZ拼多多G' . $tag] as $enc => $shopName) {
        $pdo->prepare('INSERT INTO shops (name) VALUES (?)')->execute([$shopName]); $shopId = (int)$pdo->lastInsertId(); $shops[] = $shopName;
        $f = $writeCsv($rows, $enc === 'gbk' ? 'gbk' : 'utf8');
        $out = $upload($shopId, $f);
        $q = $pdo->prepare('SELECT order_no,order_amount,order_date,raw_data FROM orders WHERE shop=? AND COALESCE(is_deleted,0)=0 ORDER BY order_no'); $q->execute([$shopName]);
        $got = []; foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $got[$r['order_no']] = $r;
        $check(count($got) === 4, "[$enc] 4 行都入账：" . count($got) . ' ' . substr($out, 0, 160));
        $a = $got["261112-{$tag}0001"];
        $check(abs((float)$a['order_amount'] - 505.0) < 0.01, "[$enc] 金额取“商家实收金额”505（不是 5 的“多多支付立减金额”，也不是实付 500）：" . $a['order_amount']);
        $check($a['order_date'] === '2026-11-10', "[$enc] 日期取“订单成交时间”：" . $a['order_date']);
        $raw = json_decode($a['raw_data'], true);
        $check(($raw['__order_status__'] ?? '') === '已发货，待收货' && ($raw['商品标题'] ?? '') === '微信小程序开发定制APP软件', "[$enc] 状态和商品标题：" . json_encode([$raw['__order_status__'] ?? '', $raw['商品标题'] ?? ''], JSON_UNESCAPED_UNICODE));
        $check(abs((float)$got["261112-{$tag}0002"]['order_amount'] - 792.0) < 0.01 && abs((float)$got["261112-{$tag}0003"]['order_amount'] - 300.0) < 0.01, "[$enc] 待发货 792、已收货 300 照常入账");
        $rf = $got["261112-{$tag}0004"];
        $check((float)$rf['order_amount'] < 0 && (json_decode($rf['raw_data'], true)['__is_refund__'] ?? '') === '1', "[$enc] 售后状态“退款成功”的订单按退款记负数：" . $rf['order_amount']);
        $upload($shopId, $f);
        $q->execute([$shopName]);
        $check(count($q->fetchAll()) === 4, "[$enc] 整张表重传不重复入账");
        @unlink($f);
    }
    echo "\n=== 拼多多订单上传测试全部通过 ===\n";
} finally {
    foreach ($shops as $n) { $pdo->prepare('DELETE FROM orders WHERE shop=?')->execute([$n]); $pdo->prepare('DELETE FROM shops WHERE name=?')->execute([$n]); }
}
