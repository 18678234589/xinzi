<?php
// 店铺订单上传：时间写成各种形式的行必须按自己的日期入账，不能悄悄记到“上传时选的月份”；认不出的才用所选月份并提示；同表重复订单号金额不同不覆盖。
// 会写入测试数据，只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/shop_upload_dates_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (($argv[1] ?? '') === 'child') {
    // 子进程：模拟一次上传请求（上传成功会 redirect + exit，所以放在子进程里跑）
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['SCRIPT_NAME'] = '/shops/upload.php'; $_SERVER['REQUEST_URI'] = '/shops/upload.php';
    $_GET = ['shop_id' => (int)$argv[2]];
    $_POST = ['action' => 'upload', 'upload_month' => $argv[3], 'csv_data' => file_get_contents($argv[4])];
    require_once __DIR__ . '/../includes/auth.php';
    $_SESSION['admin_id'] = 1;
    ob_start(); include __DIR__ . '/../shops/upload.php'; ob_end_clean();
    exit;
}
require_once __DIR__ . '/../includes/ProjectIntake.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会写入数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); $tag = bin2hex(random_bytes(3)); $shopName = 'ZZ对公' . $tag;
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$pdo->prepare('INSERT INTO shops (name) VALUES (?)')->execute([$shopName]);
$shopId = (int)$pdo->lastInsertId();
$upload = function ($rows, $month) use ($shopId) {
    $f = tempnam(sys_get_temp_dir(), 'su'); file_put_contents($f, json_encode($rows, JSON_UNESCAPED_UNICODE));
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child ' . $shopId . ' ' . escapeshellarg($month) . ' ' . escapeshellarg($f) . ' 2>&1', $out);
    @unlink($f);
    return implode("\n", $out);
};
$get = function () use ($pdo, $shopName) { $q = $pdo->prepare('SELECT order_no,order_amount,order_date,raw_data FROM orders WHERE shop=? AND COALESCE(is_deleted,0)=0 ORDER BY order_no'); $q->execute([$shopName]); return $q->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC); };
try {
    $head = ['时间', '金额', '付款账户名称', '订单编号', '客服'];
    $rows = [$head,
        ["46237.612962963", '2300', '甲公司', "龙{$tag}0803", '郭'],           // Excel 序列号 → 2026-08-03
        ["2026081012:21:30", '1600', '乙公司', "数{$tag}0810", '郭'],         // 日期时间连写
        ["20260703093827", '1400', '丙公司', "数{$tag}0703", '郭'],            // 14 位紧凑
        ["2026-8-7 10:00", '350', '丁公司', "龙{$tag}0807", '郭'],            // 月日不补零
        ["2026/7/1 934", '240', '戊公司', "龙{$tag}0701", '郭'],              // 时间写成 934
        ["不是日期", '100', '己公司', "龙{$tag}0000", '郭'],                   // 认不出：按所选月份并提示
    ];
    $out = $upload($rows, '2026-07');
    $r = $get();
    $check(count($r) === 6, '6 行全部入账：' . count($r) . ' ' . $out);
    $d = array_map(function ($x) { return $x['order_date']; }, $r);
    $check($d["龙{$tag}0803"] === '2026-08-03', 'Excel 序列号按自己的日期入账（8 月）');
    $check($d["数{$tag}0810"] === '2026-08-10', '“2026081012:21:30” 记到 8 月 10 日，不是所选的 7 月');
    $check($d["数{$tag}0703"] === '2026-07-03', '14 位紧凑时间记到 7 月 3 日');
    $check($d["龙{$tag}0807"] === '2026-08-07', '“2026-8-7 10:00” 记到 8 月 7 日');
    $check($d["龙{$tag}0701"] === '2026-07-01', '“2026/7/1 934” 记到 7 月 1 日');
    $check($d["龙{$tag}0000"] === '2026-07-01' && !empty(json_decode($r["龙{$tag}0000"]['raw_data'], true)['__date_fallback__']), '真的认不出的行才按所选月份记，并打上 __date_fallback__ 标记');
    $sum8 = 0; foreach ($r as $no => $x) if (substr($x['order_date'], 0, 7) === '2026-08') $sum8 += (float)$x['order_amount'];
    $check(abs($sum8 - (2300 + 1600 + 350)) < 0.01, '8 月合计 = 2300+1600+350 = ' . $sum8);

    // 重传同一张表：全部是已有订单，不重复入账、不改月份
    $upload($rows, '2026-07');
    $check(count($get()) === 6, '整张表重传不重复入账');

    // 同表订单号重复、金额不同：保留先出现的，不覆盖
    $dupRows = [$head, ["2026-08-20 10:00", '500', '庚公司', "龙{$tag}0820", '郭'], ["2026-08-20 11:00", '700', '庚公司', "龙{$tag}0820", '郭']];
    $upload($dupRows, '2026-08');
    $r2 = $get();
    $check((float)$r2["龙{$tag}0820"]['order_amount'] === 500.0, '同表同订单号金额不同：保留先出现的 500，不被 700 覆盖');
    echo "全部通过\n";
} finally {
    $pdo->prepare('DELETE FROM orders WHERE shop=?')->execute([$shopName]);
    $pdo->prepare('DELETE FROM shops WHERE id=?')->execute([$shopId]);
}
