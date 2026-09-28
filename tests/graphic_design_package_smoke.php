<?php
// 平面设计（阎泸琪）营业额阶梯薪酬：纯函数核对，不连数据库。php tests/graphic_design_package_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectMonthly.php';

$failures = 0;
$check = function ($ok, $label) use (&$failures) { echo ($ok ? '  [OK] ' : '  [FAIL] ') . $label . "\n"; if (!$ok) $failures++; };
$params = ['tiers' => [['upto' => 3000, 'base' => 1800, 'rate' => 0, 'big' => 0, 'small' => 0], ['upto' => 6000, 'base' => 2100, 'rate' => 0.05, 'big' => 2, 'small' => 0.5], ['upto' => 9000, 'base' => 2300, 'rate' => 0.08, 'big' => 3, 'small' => 0.5], ['upto' => 12000, 'base' => 3300, 'rate' => 0.1, 'big' => 5, 'small' => 0.5], ['upto' => 15000, 'base' => 4800, 'rate' => 0.1, 'big' => 5, 'small' => 0.5]], 'big_threshold' => 50, 'returning_rate' => 0.1, 'review_min' => 10, 'review_penalty' => 100];
$orders = function (array $amounts, array $returning = []) { return array_map(function ($a, $i) use ($returning) { return ['income' => $a, 'returning' => in_array($i, $returning, true)]; }, $amounts, array_keys($amounts)); };
$total = function ($calc) { return round($calc['base'] + array_sum(array_map(function ($i) { return $i[1]; }, $calc['items'])), 2); };
$item = function ($calc, $name) { foreach ($calc['items'] as $i) if ($i[0] === $name) return round($i[1], 2); return 0.0; };

echo "=== ≤3000 保底 ===\n";
$c = ps_sales_package_calc($params, $orders([2000, 500, 30]));
$check($c['revenue'] == 2530 && $c['base'] == 1800 && !$c['items'], '营业额 2530：保底 1800，无提成、无单量');
$c = ps_sales_package_calc($params, $orders([3000]));
$check($c['base'] == 1800 && !$c['items'], '正好 3000 仍按保底档');
$c = ps_sales_package_calc($params, []);
$check($c['revenue'] == 0 && $c['base'] == 1800, '当月没有订单：照发保底 1800');

echo "=== ≤6000 ===\n";
$c = ps_sales_package_calc($params, $orders([3000, 1500, 450, 40, 10], [2]));
$check($c['base'] == 2100 && $item($c, '营业额提成') == 250.0, '营业额 5000：底薪 2100 + 5000×5% = 250');
$check($item($c, '单量补助') == 7.0, '≥50 元 3 单 × 2 + <50 元 2 单 × 0.5 = 7');
$check($item($c, '老客户找回') == 45.0, '老客户找回订单 450 × 10% = 45');
$check($total($c) == 2402.0, '合计 2402（不含全勤）');

echo "=== ≤9000 / ≤12000 / ≤15000 / 超过 ===\n";
$c = ps_sales_package_calc($params, $orders([4000, 3000, 20]));
$check($c['base'] == 2300 && $item($c, '营业额提成') == 561.6 && $item($c, '单量补助') == 6.5, '营业额 7020：2300 + 8% = 561.6 + 2×3 + 1×0.5 = 6.5');
$c = ps_sales_package_calc($params, $orders([6000, 5000, 60]));
$check($c['base'] == 3300 && $item($c, '营业额提成') == 1106.0 && $item($c, '单量补助') == 15.0, '营业额 11060：3300 + 10% + 3 单 × 5');
$c = ps_sales_package_calc($params, $orders([8000, 7000]));
$check($c['base'] == 4800 && $item($c, '营业额提成') == 1500.0 && $item($c, '单量补助') == 10.0, '营业额 15000：4800 + 10% + 2 单 × 5');
$c = ps_sales_package_calc($params, $orders([9000, 7000]));
$check($c['base'] == 4800 && $item($c, '营业额提成') == 1600.0, '营业额 16000 超过最高档：按 ≤15000 档（4800 + 10%）');

echo "=== 好评率 ===\n";
$c = ps_sales_package_calc($params, $orders([5000]), 8);
$check($item($c, '好评率罚款') == -100.0, '好评率 8% < 10%：扣 100');
$c = ps_sales_package_calc($params, $orders([5000]), 10);
$check($item($c, '好评率罚款') == 0.0, '好评率正好 10%：不扣');
$c = ps_sales_package_calc($params, $orders([5000]), null);
$check($item($c, '好评率罚款') == 0.0, '未填写好评率：不扣');

echo "=== 导入表头（她现在的表）===\n";
$map = ps_business_import_map('平面设计', ['内容', '老客户', '店铺', '付款昵称', '时间', '订单号', '订单金额', ''], false);
$check(($map['detail:design_item'] ?? null) === 0 && ($map['returning_marker'] ?? null) === 1 && ($map['shop'] ?? null) === 2 && ($map['payment_nickname'] ?? null) === 3 && ($map['order_date'] ?? null) === 4 && ($map['order_no'] ?? null) === 5 && ($map['contract_amount'] ?? null) === 6, '内容 / 老客户 / 店铺 / 付款昵称 / 时间 / 订单号 / 订单金额 全部识别');
$check(ps_business_order_kinds('平面设计') === ['新订单', '老客户找回'] && ps_business_service_fee_rate('平面设计') == 0.0, '订单类型：新订单 / 老客户找回；不扣服务费');

if ($failures) { fwrite(STDERR, "$failures 项未通过\n"); exit(1); }
echo "\n=== 平面设计营业额阶梯薪酬全部通过 ===\n";
