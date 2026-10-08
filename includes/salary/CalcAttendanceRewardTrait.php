<?php
trait CalcAttendanceRewardTrait
{
    private static function calcAttendanceFull($cfg, $c)
    {
        $fullAmount = (float)($cfg['full_amount'] ?? 200);
        $deductMode = $cfg['deduct_mode'] ?? 'step';      // step(默认阶梯) / none / prorate / fixed
        $workHours  = (float)($cfg['work_hours'] ?? $c['work_hours'] ?? 0); // 当月应出勤总小时数
        $absentHours = (float)($cfg['absent_hours'] ?? $c['absent_hours'] ?? 0); // 当月请假小时数
        $thresholdHours = isset($cfg['absent_threshold_hours']) && $cfg['absent_threshold_hours'] !== ''
            ? (float)$cfg['absent_threshold_hours'] : null; // 超过此值全勤奖归0

        // 无请假 或 扣除模式=none → 全额发放
        if ($absentHours <= 0 || $deductMode === 'none') {
            return [
                'amount' => round($fullAmount, 2),
                'formula' => sprintf('全勤奖 %g（满勤）', $fullAmount),
                'type' => 'attendance_full',
            ];
        }

        // 默认阶梯规则：请假≥8小时全扣，≥4小时扣一半，<4小时全发
        if ($deductMode === 'step') {
            if ($absentHours >= 8) {
                return [
                    'amount' => 0,
                    'formula' => sprintf('全勤奖 0（请假%.1f小时≥8h，全部扣除）', $absentHours),
                    'type' => 'attendance_full',
                ];
            }
            if ($absentHours >= 4) {
                $half = round($fullAmount / 2, 2);
                return [
                    'amount' => $half,
                    'formula' => sprintf('全勤奖 %g/2=%.2f（请假%.1f小时≥4h，扣除一半）', $fullAmount, $half, $absentHours),
                    'type' => 'attendance_full',
                ];
            }
            return [
                'amount' => round($fullAmount, 2),
                'formula' => sprintf('全勤奖 %g（请假%.1f小时<4h，不扣）', $fullAmount, $absentHours),
                'type' => 'attendance_full',
            ];
        }

        // 超过阈值 → 全勤奖归0
        if ($thresholdHours !== null && $absentHours >= $thresholdHours) {
            return [
                'amount' => 0,
                'formula' => sprintf('全勤奖 0（请假%.1f小时≥阈值%.1f小时，归零）', $absentHours, $thresholdHours),
                'type' => 'attendance_full',
            ];
        }

        // 按比例折算
        if ($deductMode === 'prorate') {
            if ($workHours <= 0) $workHours = 176; // 兜底默认22天×8小时
            $ratio = max(0, ($workHours - $absentHours) / $workHours);
            $amt = $fullAmount * $ratio;
            return [
                'amount' => round($amt, 2),
                'formula' => sprintf('全勤奖 %g×(%g-%g)/%g=%.2f', $fullAmount, $workHours, $absentHours, $workHours, $amt),
                'type' => 'attendance_full',
            ];
        }

        // 每小时扣固定金额（fixed）
        if ($deductMode === 'fixed') {
            if ($workHours <= 0) $workHours = 176;
            $perHour = $fullAmount / $workHours;
            $deduct = $absentHours * $perHour;
            $amt = max(0, $fullAmount - $deduct);
            return [
                'amount' => round($amt, 2),
                'formula' => sprintf('全勤奖 %g-%.1f×%.4f=%.2f', $fullAmount, $absentHours, $perHour, $amt),
                'type' => 'attendance_full',
            ];
        }

        // 未知模式 → 全额
        return [
            'amount' => round($fullAmount, 2),
            'formula' => sprintf('全勤奖 %g', $fullAmount),
            'type' => 'attendance_full',
        ];
    }

    // ---- 考勤-日薪制 ----
    private static function calcAttendanceDaily($cfg, $c)
    {
        // context 里没有出勤数据时用订单数估算（或可后续扩展传入实际出勤天数）
        $days = (int)($cfg['work_days'] ?? $c['order_count']);  // 默认用订单数替代
        $dailyRate = (float)($cfg['daily_rate'] ?? 100);
        $amt = $days * $dailyRate;
        return [
            'amount' => round($amt, 2),
            'formula' => sprintf('%d天×%g=%.2f', $days, $dailyRate, $amt),
            'type' => 'attendance_daily',
        ];
    }

    // ---- 考勤-扣款制 ----
    private static function calcAttendanceDeduct($cfg, $c)
    {
        $absentDays = (int)($cfg['absent_days'] ?? 0);  // 缺勤天数（需后续扩展为实际录入）
        $deductPerDay = (float)($cfg['deduct_per_day'] ?? 100);
        $deduct = $absentDays * $deductPerDay;
        // 扣款制：金额为负数
        return [
            'amount' => -round($deduct, 2),  // 注意是负数！
            'formula' => sprintf('-%d天×%g=-%g', $absentDays, $deductPerDay, $deduct),
            'type' => 'attendance_deduct',
        ];
    }

    // ---- 新老客户订单奖励 ----
    private static function calcCustomerReward($cfg, $c, $moduleName = '')
    {
        $newReward = (float)($cfg['new_customer_reward'] ?? 50);
        $oldReward = (float)($cfg['old_customer_reward'] ?? 30);
        
        $employeeId = $c['employee']['id'] ?? 0;
        
        // 记录每个旺旺号的客户类型：true=新客户, false=老客户
        $customerTypes = [];
        
        foreach (($c['orders'] ?? []) as $o) {
            if ($o['employee_id'] != $employeeId) continue;
            
            $wangwang = self::extractWangwang($o);
            if ($wangwang === '') continue;
            
            $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
            $isRefund = isset($rawData['__is_refund__']) && $rawData['__is_refund__'] === '1';
            if ($isRefund) continue;
            
            // 获取备注内容
            $remark = strtolower(trim($o['remark'] ?? ''));
            $rawRemark = '';
            if (is_array($rawData)) {
                foreach ($rawData as $key => $value) {
                    $lowerKey = strtolower(trim($key));
                    if (strpos($lowerKey, '备注') !== false) {
                        $rawRemark = strtolower(trim((string)$value));
                        break;
                    }
                }
            }
            
            // 判断是否新客户：备注包含"新客户"
            $isNewCustomer = strpos($remark, '新客户') !== false || strpos($rawRemark, '新客户') !== false;
            
            // 如果已是新客户，保持不变；否则根据当前订单更新
            if ($isNewCustomer) {
                $customerTypes[$wangwang] = true;
            } elseif (!isset($customerTypes[$wangwang])) {
                // 没有标记新客户，且尚未记录过，则归为老客户
                $customerTypes[$wangwang] = false;
            }
        }
        
        // 统计新客户和老客户数量
        $newCount = 0;
        $oldCount = 0;
        foreach ($customerTypes as $wangwang => $isNew) {
            if ($isNew) {
                $newCount++;
            } else {
                $oldCount++;
            }
        }
        
        $newAmount = $newCount * $newReward;
        $oldAmount = $oldCount * $oldReward;
        $totalAmount = $newAmount + $oldAmount;
        
        $formulaParts = [];
        if ($newCount > 0) {
            $formulaParts[] = sprintf('新客户%d人×¥%.2f=%.2f', $newCount, $newReward, $newAmount);
        }
        if ($oldCount > 0) {
            $formulaParts[] = sprintf('老客户%d人×¥%.2f=%.2f', $oldCount, $oldReward, $oldAmount);
        }
        $formula = implode(' + ', $formulaParts);
        if (count($formulaParts) === 0) {
            $formula = '0.00';
        }
        
        return [
            'amount' => round($totalAmount, 2),
            'formula' => $formula,
            'type' => 'customer_reward',
        ];
    }

}
