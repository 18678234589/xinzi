<?php
// 店铺流水订单号带 Excel 文本前缀单引号（'4502…）时必须清洗，否则退款对号查不到。纯函数测试，不连库。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/lib/order_text.php';
foreach (["'4502270052084123915", " ’4502270052084123915 ", "4502270052084123915", "\u{200B}'4502270052084123915"] as $v) {
    if (extract_order_no(['订单编号' => $v]) !== '4502270052084123915') throw new RuntimeException('订单号清洗失败：' . $v);
}
if (extract_order_no(['订单编号' => "'"]) !== '') throw new RuntimeException('只有引号应视为空');
echo "PASS order_no_clean\n";
