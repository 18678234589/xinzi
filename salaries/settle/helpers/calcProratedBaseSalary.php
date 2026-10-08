<?php
function calcProratedBaseSalary($empId, $month, $baseSalary) {
    $attInfo = null;
    $mp = explode('-', (string)$month);
    if (count($mp) === 2) {
        $attInfo = get_attendance((int)$empId, (int)$mp[0], (int)$mp[1]);
    }
    if (!$attInfo) {
        return ['original' => $baseSalary, 'actual_days' => 0, 'leave_days' => 0,
                'prorated' => $baseSalary, 'status' => '未录入考勤，固定服务费按满勤发放', 'has_att' => false];
    }
    $workH   = (float)$attInfo['work_hours'];
    $absentH = (float)$attInfo['absent_hours'];
    // 以考勤表显示值为准：满勤天数、实际出勤天数各自保留2位小数后，
    // 请假天数 = 满勤天数 − 实际出勤天数（用显示值相减，与考勤表完全一致）
    $fullDays   = round($workH / 8, 2);                          // 满勤天数（考勤表显示值）
    $actualDays = round(max(0, ($workH - $absentH) / 8), 2);     // 实际出勤天数（考勤表显示值）
    $leaveDays  = round($fullDays - $actualDays, 2);             // 请假天数 = 满勤 − 实际出勤
    if ($leaveDays <= 0) {
        $prorated = $baseSalary;                   // 满勤不折
        $status   = '满勤，固定服务费全额发放';
    } elseif ($leaveDays <= 4) {
        // 请假≤4天：固定服务费 − 固定服务费/30 × 请假天数（基数用30，与满勤天数无关）
        $prorated = round($baseSalary - $baseSalary / 30 * $leaveDays, 2);
        $actualDays = 30 - $leaveDays;             // 显示用：30−请假天数
        $status = sprintf('请假%.2f天(≤4天)，固定服务费−固定服务费/30×请假天数', $leaveDays);
    } else {
        // 请假>4天：固定服务费/30 × 实际出勤天数（实际出勤=满勤天数−请假天数）
        $prorated = round($baseSalary / 30 * $actualDays, 2);
        $status = sprintf('请假%.2f天(>4天)，固定服务费/30×实际出勤%.2f天', $leaveDays, $actualDays);
    }
    return ['original' => $baseSalary, 'actual_days' => $actualDays, 'leave_days' => $leaveDays,
            'prorated' => $prorated, 'status' => $status, 'has_att' => true];
}
