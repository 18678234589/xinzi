<?php
// 商标部原表整理与按件计算（纯函数，不连数据库）：php tests/trademark_import_rows_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';

$failures = 0;
function tm_check($label, $actual, $expected)
{
    global $failures;
    if ($actual === $expected) { echo "  [OK] $label\n"; return; }
    $failures++;
    echo "  [FAIL] $label\n    expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n    actual:   " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "=== 客服表头：日期 店铺 旺旺 订单 售价 成本 网报加急 数量 设计 备注 客服 (空) 订单状态 ===\n";
$csHead = ['日期', '店铺', '旺旺', '订单', '售价', '成本', '网报加急', '数量', '设计', '备注', '客服', '', '订单状态'];
$csMap = ps_business_import_map('商标', $csHead, false);
tm_check('表头识别：订单 / 售价 / 成本 / 数量 / 设计 / 客服 / 订单状态', [$csMap['order_no'], $csMap['contract_amount'], $csMap['direct_cost'], $csMap['detail:trademark_count'], $csMap['trademark_extra'], $csMap['customer_service'], $csMap['status']], [3, 4, 5, 7, 8, 10, 12]);

$fix = function ($row) use ($csMap) { return ps_trademark_fix_row($row, $csMap); };
$normal = $fix(['46222', '美呀美', 'hdzwzyhgai', '3313014639152009355', '660', '540', '公司网报', '2', '', '', '于娜', '', '发货']);
tm_check('正常行：网报类型 / 件数不变', [$normal[6], $normal[7]], ['公司网报', '2']);
$shifted = $fix(['美呀美', '46236', '小桥流水农家货', '5127290676845008747', '350', '270', '', '公司网报', '1', '', '于娜', '', '到账']);
tm_check('错位行：日期店铺互换、件数从“设计”列归位', [ps_import_date($shifted[0]), $shifted[1], $shifted[6], $shifted[7]], ['2026-08-02', '美呀美', '公司网报', '1']);
$shipNote = $fix(['46235', '微信', 'Allen', '2608017790928076757566', '550', '450', '8.10发货', '转让', '1', '', '秦婷婷', '', '到账']);
tm_check('网报加急写“8.10发货”：业务取“转让”，件数 1', [$shipNote[6], $shipNote[7]], ['转让', '1']);
$noCount = $fix(['46237', '美呀美', '麦兜响当当6599', '3315399495860010466', '80', '0', '公司网报', '', '', '', '孙荣姿', '', '到账']);
tm_check('未填数量：件数留空', $noCount[7], '');
tm_check('新客户备注保留', $fix(['46067', '美呀美', 'loveeki', '3248211829976518373', '350', '270', '', '公司网报', '1', '新客户', '于娜', '', '未发货'])[9], '新客户');
tm_check('汇总行“合计”跳过', $fix(['', '', '', '合计', '76552', '60682']), null);
tm_check('汇总行“商标客服 底薪 全勤”跳过', $fix(['', '商标客服', '底薪', '全勤', '单量补助3元/单']), null);
tm_check('汇总行“于娜 791.73 200 195”跳过', $fix(['', '于娜', '791.73', '200', '195', '6', '76552']), null);
tm_check('汇总行“合计 9”跳过', $fix(['', '', '', '', '', '', '', '合计', '9']), null);

echo "=== 资料专员表头：时间 店铺 付款账号（旺旺） 订单编号 商标 售价 成本 客服 商标个数 订单状态 ===\n";
$dataHead = ['时间', '店铺', '付款账号（旺旺）', '订单编号', '商标', '售价', '成本', '客服', '商标个数', '订单状态'];
$dataMap = ps_business_import_map('商标', $dataHead, false);
tm_check('表头识别：时间 / 付款账号 / 订单编号 / 商标 / 客服 / 商标个数', [$dataMap['order_date'], $dataMap['payment_nickname'], $dataMap['order_no'], $dataMap['detail:trademark_name'], $dataMap['customer_service'], $dataMap['detail:trademark_count']], [0, 2, 3, 4, 7, 8]);
tm_check('资料专员表没有前端 / 后端人员列（上传人本人自动加入）', [isset($dataMap['frontend']), isset($dataMap['backend'])], [false, false]);
$fixData = function ($row) use ($dataMap) { return ps_trademark_fix_row($row, $dataMap); };
$evening = $fixData(['2026/7/19晚', '美呀美', 'hdzwzyhgai', '3313014639152009355', "家宪 (宋体)\n注册类别： 45类41类", '660', '540', '于娜', '2', '发货']);
tm_check('日期“2026/7/19晚”可识别，商标个数 2', [ps_import_date($evening[0]), $evening[8]], ['2026-07-19', '2']);
tm_check('10 元单未填商标个数', $fixData(['46211', '美呀美', '糨糨糨小机智（微信群聊）', '5122767675307082538', '', '10', '', '孙荣姿', '', '发货'])[8], '');
tm_check('汇总行“695 2.2 1529”跳过', $fixData(['', '', '695', '2.2', '1529', '']), null);
tm_check('汇总行“王庆美 788.27 200 1529”跳过', $fixData(['王庆美', '788.27', '200', '1529', '2517.27', '25.56']), null);
tm_check('汇总行“合计 704 3.2 1548.8”跳过', $fixData(['', '', '', '合计', '704', '3.2', '1548.8']), null);

echo "=== 小额分表：日期 店铺 订单号 旺旺号 售价 返现 业务 客服 ===\n";
$smallMap = ps_business_import_map('商标', ['日期', '店铺', '订单号', '旺旺号', '售价', '返现', '业务', '客服'], false);
tm_check('小额表：售价取“售价”列、没有状态列', [$smallMap['contract_amount'], isset($smallMap['status'])], [4, false]);
tm_check('小额行保留', ps_trademark_fix_row(['46240', '美呀美', '5127179043061020216', 'tb0841625726', '10', '10', '商标注册', '孙荣姿'], $smallMap)[2], '5127179043061020216');

echo "=== 资料 / 提交专员按件计 ===\n";
$calc = ['blocked' => false, 'subsidy' => 2.2, 'note' => '(收入 660.00 − 成本 540.00 − 服务费 6.60) × 0% + 每单补助 2.20'];
tm_check('2 件 = 4.40', ps_trademark_piece_calc($calc, 2.0)['subsidy'], 4.4);
tm_check('1 件 = 2.20', ps_trademark_piece_calc($calc, 1.0)['subsidy'], 2.2);
tm_check('未填个数 = 0', ps_trademark_piece_calc($calc, null)['subsidy'], 0.0);
tm_check('未填个数说明', mb_strpos(ps_trademark_piece_calc($calc, null)['note'], '未填商标个数') !== false, true);
tm_check('无补助的规则不变', ps_trademark_piece_calc(['blocked' => false, 'subsidy' => 0.0, 'note' => 'x'], 3.0)['subsidy'], 0.0);

if ($failures) { fwrite(STDERR, "$failures 项未通过\n"); exit(1); }
echo "\n=== 商标原表整理与按件计算全部通过 ===\n";
