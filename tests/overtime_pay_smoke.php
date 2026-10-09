<?php
// 超时补贴：考勤“26+2”写法解析、节假日 1.5 倍 / 其他日期 1 倍、规则中心“超时补贴”规则按固定服务费 ÷ 30 计算。
// 纯计算，不读写数据库：php tests/overtime_pay_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectMonthly.php';

$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$parse = function ($cell, $year = 2026, $month = 10) { [$base, $extra] = ot_split_cell($cell); return [trim($base), ot_parse_extra($extra, $year, $month)]; };

try {
    // 1. 节假日表：元旦、除夕、春节初一 / 初二、清明、5.1、端午、中秋、10.1 共 9 天，每年都有
    foreach ([2026, 2027, 2028, 2029, 2030] as $y) {
        $names = array_values(ot_holidays($y));
        $check(count($names) === 9, "$y 年 1.5 倍日期共 9 天：" . implode('、', $names));
    }
    $h26 = ot_holidays(2026);
    $check(isset($h26['2026-01-01'], $h26['2026-05-01'], $h26['2026-10-01']), '元旦 / 5.1 / 10.1 固定日期');
    $check(isset($h26['2026-02-16'], $h26['2026-02-17'], $h26['2026-02-18']), '2026 春节：除夕 2.16、初一 2.17、初二 2.18');
    $check(isset($h26['2026-04-05'], $h26['2026-06-19'], $h26['2026-09-25']), '2026 清明 4.5、端午 6.19、中秋 9.25');
    $check(!isset($h26['2026-10-02']) && !isset($h26['2026-05-02']) && !isset($h26['2026-02-19']), '10.2 / 5.2 / 初三不是 1.5 倍');

    // 2. 考勤单元格写法
    [$base, $ot] = $parse('26+2');
    $check($base === '26' && $ot['normal'] == 2 && $ot['holiday'] == 0, '“26+2”：出勤 26 + 其他日期延时服务 2 天（1 倍）');
    [$base, $ot] = $parse('26');
    $check($base === '26' && $ot['normal'] == 0 && $ot['holiday'] == 0, '“26”：没有延时服务');
    [$base, $ot] = $parse('26+1(10.1)');
    $check($ot['normal'] == 0 && $ot['holiday'] == 1, '“26+1(10.1)”：10.1 当天延时服务 1 天，1.5 倍');
    [$base, $ot] = $parse('26+3(10.1,10.2)');
    $check($ot['holiday'] == 1 && $ot['normal'] == 2, '“26+3(10.1,10.2)”：共 3 天，其中 10.1 一天 1.5 倍，其余 2 天 1 倍');
    [$base, $ot] = $parse('26+(10.1)');
    $check($ot['holiday'] == 1 && $ot['normal'] == 0, '“26+(10.1)”：括号里列了几个日期就是几天');
    [$base, $ot] = $parse('26＋2（10.2）');
    $check($ot['holiday'] == 0 && $ot['normal'] == 2, '全角加号 / 括号；10.2 不是节假日按 1 倍：2 天 × 1');
    [$base, $ot] = $parse('26+1(国庆)');
    $check($ot['holiday'] == 1, '括号里写节日名“国庆”按节假日');
    [$base, $ot] = $parse('24+2(5.1,5.2)', 2026, 5);
    $check($base === '24' && $ot['holiday'] == 1 && $ot['normal'] == 1, '“24+2(5.1,5.2)”：5.1 是 1.5 倍，5.2 是 1 倍');
    [$base, $ot] = $parse('26+1(2.17)', 2026, 2);
    $check($ot['holiday'] == 1, '春节初一 2026-02-17 按节假日');
    [$base, $ot] = $parse('26+1(2.17)', 2027, 2);
    $check($ot['holiday'] == 0 && $ot['normal'] == 1, '2027 年 2.17 不是春节（初一 2.6），按 1 倍');
    [$base, $ot] = $parse('26+2.5');
    $check($ot['normal'] == 2.5, '“26+2.5”：延时服务 2.5 天');
    [$base, $ot] = $parse('26+1(10.1)+1', 2026, 10);
    $check($ot['holiday'] == 1 && $ot['normal'] == 1, '“26+1(10.1)+1”：节假日 1 天 + 其他 1 天');

    // 3. 超时补贴公式：固定服务费 ÷ 30 × 天数 × 倍率
    [$v, $how] = ot_pay(3000, 0, 1);
    $check($v == 150.0, '固定服务费 3000，节假日延时服务 1 天：3000÷30×1×1.5 = 150（' . $v . '）');
    [$v] = ot_pay(3000, 2, 0);
    $check($v == 200.0, '固定服务费 3000，其他日期延时服务 2 天：3000÷30×2×1 = 200');
    [$v] = ot_pay(2300, 1, 1);
    $check($v == round(2300 / 30 * 2.5, 2), '固定服务费 2300，其他 1 天 + 节假日 1 天：2300÷30×(1+1.5) = ' . $v);

    // 4. 规则中心：规则引擎按考勤里的延时服务天数、固定服务费算出超时补贴
    $rule = function ($id, $name, $type, $employeeId, $params) {
        return ['id' => $id, 'name' => $name, 'rule_type' => $type, 'scope_business' => '*', 'scope_group' => '*', 'scope_role' => '*', 'employee_id' => $employeeId, 'metric' => 'profit', 'params' => $params];
    };
    $rules = [
        $rule(1, '超时补贴', 'overtime_pay', null, ['holiday_rate' => 1.5, 'normal_rate' => 1]), // 故意排在固定服务费规则前面：与规则顺序无关
        $rule(2, '甲 固定服务费', 'base_fee', 11, ['amount' => 3000]),
        $rule(3, '乙 固定服务费', 'base_fee', 12, ['amount' => 2400]),
        $rule(4, '丙 固定服务费', 'base_fee', 13, ['amount' => 3000, 'no_prorate' => true]),
    ];
    $attendance = [
        11 => ['work' => 208, 'absent' => 0, 'ot_days' => 2, 'ot_holiday_days' => 0],
        12 => ['work' => 208, 'absent' => 16, 'ot_days' => 1, 'ot_holiday_days' => 1],
        13 => ['work' => 208, 'absent' => 0, 'ot_days' => 0, 'ot_holiday_days' => 1],
        14 => ['work' => 208, 'absent' => 0, 'ot_days' => 1, 'ot_holiday_days' => 0], // 没有固定服务费规则
    ];
    $results = ps_monthly_results('2026-10', true, ['rules' => $rules, 'snapshots' => [], 'inputs' => [], 'attendance' => $attendance]);
    $by = [];
    foreach ($results as $row) $by[$row['employee_id']][$row['rule_type']] = $row;
    $check(abs($by[11]['overtime_pay']['amount'] - 200.0) < 0.005, '甲：固定服务费 3000，其他延时服务 2 天 = 200：' . $by[11]['overtime_pay']['amount'] . ' / ' . $by[11]['overtime_pay']['detail']);
    $check(abs($by[11]['base_fee']['amount'] - 3000.0) < 0.005, '甲：满勤，固定服务费 3000 不受延时服务影响');
    $check(abs($by[12]['overtime_pay']['amount'] - round(2400 / 30 * 2.5, 2)) < 0.005, '乙：固定服务费 2400（用折算前金额，不是请假折算后的），其他 1 天 + 节假日 1 天 = ' . $by[12]['overtime_pay']['amount']);
    $check(abs($by[12]['base_fee']['amount'] - round(2400 - 2400 / 30 * 2, 2)) < 0.005, '乙：请假 2 天，固定服务费照常折算 = ' . $by[12]['base_fee']['amount'] . '，延时服务不冲抵请假');
    $check(abs($by[13]['overtime_pay']['amount'] - 150.0) < 0.005, '丙：管理岗不折算，节假日延时服务 1 天 = 3000÷30×1.5 = 150');
    $check(isset($by[14]['overtime_pay']) && (float)$by[14]['overtime_pay']['amount'] == 0.0 && mb_strpos($by[14]['overtime_pay']['detail'], '没有固定服务费') !== false, '丁：没有固定服务费规则，不凭空发超时补贴，并提示');

    // 5. 本月填写金额（如网站客服每月不同）覆盖默认值时，超时补贴同口径
    $inputs = [3 => [12 => ['value' => '3000', 'note' => '本月补单提成']]];
    $results = ps_monthly_results('2026-10', true, ['rules' => $rules, 'snapshots' => [], 'inputs' => $inputs, 'attendance' => $attendance]);
    $by = [];
    foreach ($results as $row) $by[$row['employee_id']][$row['rule_type']] = $row;
    $check(abs($by[12]['overtime_pay']['amount'] - round(3000 / 30 * 2.5, 2)) < 0.005, '乙：本月金额填 3000 后超时补贴跟着变 = ' . $by[12]['overtime_pay']['amount']);

    // 6. 指定到某人的超时补贴规则，只算这个人
    array_shift($rules); // 去掉全员规则，换成只指定给甲的规则
    $results = ps_monthly_results('2026-10', true, ['rules' => array_merge([$rule(9, '甲 超时补贴', 'overtime_pay', 11, ['holiday_rate' => 2, 'normal_rate' => 1])], $rules), 'snapshots' => [], 'inputs' => [], 'attendance' => $attendance]);
    $otRows = array_values(array_filter($results, function ($r) { return $r['rule_type'] === 'overtime_pay'; }));
    $check(count($otRows) === 1 && $otRows[0]['employee_id'] === 11, '指定给甲的超时补贴规则只给甲算（' . count($otRows) . ' 条）');

    echo "\n=== 超时补贴测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
