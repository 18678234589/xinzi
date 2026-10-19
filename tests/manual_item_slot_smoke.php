<?php
// 手工项（提成 / 扣项）记入哪一栏：负数的提成项必须减项目分成，应发与实发才同步变化。纯函数，不连库。
require_once __DIR__ . '/../includes/ProjectPayrollExtras.php';
$cases = [
    ['小程序', -1103.92, 'share'], ['留学提成', -50, 'share'], ['资料员提成', -18.28, 'share'], ['游学业务分成', 4959.93, 'share'],
    ['车位费', -100, 'parking'], ['预留金', -200, 'reserve'], ['迟到扣款', -30, 'deduct'], ['个人保险扣除', -471.02, 'deduct'],
    ['补单提成', 2683.33, 'base'], ['补单提成', -100, 'base'], ['全勤奖', 200, 'bonus'], ['其他补助', 100, 'other'],
    ['经理补助', 300, 'mgr'], ['组长补助', 300, 'lead'], ['保险补助', 100, 'ins_sub'], ['小程序设计费', 80, 'other'],
];
foreach ($cases as [$label, $amount, $want]) {
    $got = ps_manual_item_slot($label, $amount);
    if ($got !== $want) { fwrite(STDERR, "未通过：$label $amount 期望 $want 实际 $got\n"); exit(1); }
}
echo count($cases) . " 项通过\n";
