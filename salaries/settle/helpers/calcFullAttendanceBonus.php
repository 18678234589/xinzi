<?php
function calcFullAttendanceBonus($empId, $month, $bonusBase) {
    $attInfo = null;
    $mp = explode('-', (string)$month);
    if (count($mp) === 2) {
        $attInfo = get_attendance((int)$empId, (int)$mp[0], (int)$mp[1]);
    }
    if (!$attInfo || $bonusBase <= 0) {
        return ['base' => 0, 'deduct' => 0, 'net' => 0,
                'status' => $attInfo ? '未启用全勤奖' : '未录入考勤，不发全勤奖',
                'has_att' => (bool)$attInfo];
    }
    $absent = (float)$attInfo['absent_hours'];
    if ($absent >= 8) {
        $deduct = $bonusBase;            // 全扣
    } elseif ($absent >= 4) {
        $deduct = $bonusBase / 2;         // 扣一半
    } else {
        $deduct = 0;                      // 不扣
    }
    if ($absent >= 8) {
        $status = '请假≥8h，全勤奖全部扣除';
    } elseif ($absent >= 4) {
        $status = '请假≥4h，扣除全勤奖一半';
    } elseif ($absent > 0) {
        $status = '请假<4h，全勤奖不扣';
    } else {
        $status = '满勤，全勤奖全额发放';
    }
    return ['base' => $bonusBase, 'deduct' => $deduct,
            'net' => $bonusBase - $deduct, 'status' => $status, 'has_att' => true];
}
