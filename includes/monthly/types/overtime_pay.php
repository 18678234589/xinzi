<?php

            // 术语（仅限代码注释，不要出现在任何用户可见的页面、提示、规则说明里）：“延时服务”即通常所说的加班，“超时补贴”即加班费；overtime_* 命名沿用英文原意。
            // 超时补贴：固定服务费 ÷ 30 × 延时服务天数 × 倍率（节假日当天 1.5 倍，其余日期 1 倍）。延时服务天数来自考勤表；employee_id 为空 = 所有有延时服务的合作人员。
            $holidayRate = (float)($p['holiday_rate'] ?? 1.5);
            $normalRate = (float)($p['normal_rate'] ?? 1);
            foreach ($attendance as $otEid => $otAtt) {
                $otEid = (int)$otEid;
                if ($rule['employee_id'] !== null && (int)$rule['employee_id'] !== $otEid) continue;
                $otNormal = (float)($otAtt['ot_days'] ?? 0);
                $otHoliday = (float)($otAtt['ot_holiday_days'] ?? 0);
                if ($otNormal <= 0 && $otHoliday <= 0) continue;
                if (isset($overtimeDone[$otEid])) continue; // 同一人同月只按第一条超时补贴规则计一次
                $overtimeDone[$otEid] = true;
                $otBase = (float)($baseAmounts[$otEid] ?? 0);
                if ($otBase <= 0) {
                    $add($otEid, $rule, 0, '本月有延时服务但没有固定服务费规则（或本月金额为 0），无法计算超时补贴，请先在规则中心补固定服务费', true);
                    continue;
                }
                [$otValue, $otHow] = ot_pay($otBase, $otNormal, $otHoliday, $normalRate, $holidayRate);
                $add($otEid, $rule, $otValue, $otHow);
            }
