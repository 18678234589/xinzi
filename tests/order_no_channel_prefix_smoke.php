<?php
// 订单号带渠道词（微信26100188… / 微信：26100188…）与不带渠道词的是同一单：导入时按已有订单对上，不重复建单。写入测试数据，只允许本地隔离库。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "PASS $label\n"; };
$tag = (string)random_int(10000, 99999);
$plain = '2610018804' . $tag . '7274' . '0748110';      // 先导入的是不带渠道词的号
$wx = '微信' . $plain;
$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status) VALUES (?,'cp','商标','普通订单','微信',960,960,'2026-10-02','finished')");
    $ins->execute([$plain]);
    $id = (int)$pdo->lastInsertId();
    $check(ps_order_no_resolve($wx) === $plain, '库里是不带渠道词的号，上传“微信+号”对上已有订单（不重复建单）');
    $check(ps_order_no_resolve('微信 ' . $plain) === $plain && ps_order_no_resolve('微信：' . $plain) === $plain, '“微信 号”“微信：号”同样对上');
    $check(ps_order_no_resolve('支付宝' . $plain) === $plain, '其他渠道词（支付宝）同样对上');
    // 反过来：库里存的是带渠道词的旧写法，上传不带渠道词的号
    $pdo->prepare('UPDATE project_orders SET order_no=? WHERE id=?')->execute([$wx, $id]);
    $check(ps_order_no_resolve($plain) === $wx, '库里是“微信+号”的旧写法，上传不带渠道词的号也对上');
    $check(ps_order_no_resolve('微信：' . $plain) === $wx, '库里是“微信+号”，上传“微信：号”也对上');
    // 没有已有订单时用规范写法（不带渠道词），第二次再来就是同一单
    $new = '2610' . $tag . '99887766';
    $check(ps_order_no_resolve('微信' . $new) === $new, '库里没有时统一用不带渠道词的规范号建单');
    // 不误伤
    $check(ps_order_no_resolve('致2026' . $tag) === '致2026' . $tag, '带汉字前缀的内部号不被改写');
    echo "ALL PASS\n";
} finally {
    $pdo->rollBack();
}
