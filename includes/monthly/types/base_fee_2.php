<?php

            // 管理岗等按月固定、不按考勤折算
            [$value, $how] = !empty($p['no_prorate']) ? [round($amount, 2), '每月固定，不按考勤折算'] : ps_monthly_prorate($amount, $attendance[$eid] ?? null);
            $add($eid, $rule, $value, ($override !== null ? '本月金额 ¥' . money_plain($amount) . ($override['note'] !== '' ? '（' . $override['note'] . '）' : '') . '；' : '') . $how . ($override === null && isset($tierBases[$eid]) ? '；' . $tierBases[$eid]['detail'] : ''));