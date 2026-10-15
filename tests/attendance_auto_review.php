<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectMonthly.php';

function attendance_check($ok, $label) {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}
function attendance_result($attendance, $override = null, $forecast = false, $full = 200) {
    $rule = ['id'=>17, 'name'=>'全勤奖', 'rule_type'=>'attendance_bonus', 'employee_id'=>12,
        'scope_business'=>'*', 'scope_group'=>'*', 'scope_role'=>'*', 'metric'=>'profit', 'params'=>['amount'=>$full]];
    $context = ['rules'=>[$rule], 'snapshots'=>[], 'attendance'=>$attendance === null ? [] : [12=>$attendance],
        'inputs'=>$override === null ? [] : [17=>[12=>['value'=>$override, 'note'=>'财务核定']]], 'forecast'=>$forecast];
    return ps_monthly_results('2026-09', true, $context)[0];
}

attendance_check(ps_attendance_bonus_amount('网站客服') === 100.0 && ps_attendance_bonus_amount(' 网站客服部 ') === 100.0 && ps_attendance_bonus_amount('网站售后部') === 200.0, '部门标准为网站客服100、其余200');
$full = ['work'=>208, 'absent'=>0];
$row = attendance_result($full);
attendance_check((float)$row['amount'] === 200.0 && strpos($row['detail'], '考勤自动审核') === 0 && (float)attendance_result($full, null, false, 100)['amount'] === 100.0, '有效考勤自动计入，无须逐人批准');
$leave = [];
foreach ([0,3.5,4,7.5,8] as $hours) $leave[] = ps_attendance_suggestion(200, ['work'=>208, 'absent'=>$hours])[0];
attendance_check($leave === [200.0,200.0,100.0,100.0,0.0], '沿用现有请假阶梯，边界金额正确');
attendance_check((float)attendance_result(null)['amount'] === 0.0 && strpos(attendance_result(null)['detail'], '待核对考勤') === 0 && (float)attendance_result(['work'=>0,'absent'=>0])['amount'] === 0.0, '缺失或无效考勤不自动发放');
$home = $full + ['work_from_home'=>true];
attendance_check((float)attendance_result($home)['amount'] === 0.0 && (float)attendance_result($home, 200)['amount'] === 0.0, '居家办公不发，人工正数覆盖也不能绕过');
attendance_check(ps_attendance_work_from_home('9月3日申请在家上班') && ps_attendance_work_from_home('本月居家办公2天') && !ps_attendance_work_from_home('本月无居家办公') && !ps_attendance_work_from_home('居家办公0天')
    && ps_attendance_row_work_from_home(['姓名','居家办公天数'], ['验收',2], 0)
    && ps_attendance_row_work_from_home(['姓名','居家办公天数'], ['验收','2天'], 0)
    && ps_attendance_row_work_from_home(['姓名','是否居家办公'], ['验收','√'], 0)
    && !ps_attendance_row_work_from_home(['姓名','居家办公天数'], ['验收',0], 0)
    && ps_attendance_row_work_from_home(['姓名','9月3日'], ['验收','居家'], 0), '识别备注、居家天数列及每日标记，排除否定及零天记录');
attendance_check((float)attendance_result($full, 0)['amount'] === 0.0 && (float)attendance_result($full, 80)['amount'] === 80.0, '保留财务已有的零元排除和核定金额');
attendance_check((float)attendance_result(null, null, true)['amount'] === 200.0 && (float)attendance_result($home, null, true)['amount'] === 0.0, '未来预期与实际核算分开，居家预期仍为零');
