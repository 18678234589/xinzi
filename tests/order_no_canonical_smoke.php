<?php
// 只读：订单号规范化与同号识别（用线上真实写法验证，不写库）。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';

$cases = [
    '订单编号：科中2026091901' => '科中2026091901',
    '订单编号：科中2026091901科恒中信' => '科中2026091901',
    '科中2026092201' => '科中2026092201',
    '26092114046501428406247580 和范蕾蕾答辩1200 一起' => '26092114046501428406247580',
    '26092114046501428406247580' => '26092114046501428406247580',
    '致2026081303' => '致2026081303',
    ' 5127402818420056504 ' => '5127402818420056504',
    'WX-ABCDEF0123456789ABCDEF01' => 'WX-ABCDEF0123456789ABCDEF01',
];
foreach ($cases as $raw => $want) {
    $got = ps_order_no_canonical($raw);
    if ($got !== $want) throw new RuntimeException("规范化错误：“{$raw}” => “{$got}”，期望“{$want}”");
}
// 库里已有“订单编号：科中2026091901”时，不带前缀或带尾部备注的写法都应落到同一张订单号。
foreach (['科中2026091901', '订单编号：科中2026091901科恒中信', '订单编号：科中2026091901'] as $raw) {
    $resolved = ps_order_no_resolve($raw);
    if (ps_order_no_canonical($resolved) !== '科中2026091901') throw new RuntimeException("同号识别失败：{$raw} => {$resolved}");
}
echo "订单号规范化与同号识别通过\n";
