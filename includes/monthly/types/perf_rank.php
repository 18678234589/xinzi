<?php

            // 设计客服：原系统“客服绩效”按多店绩效平均分排名，前三名 850 / 800 / 750（财务照常上传绩效数据）；
            // 结果按考勤折算（同固定服务费）；本月可填写金额覆盖（如绩效数据未上传）。
            if (!function_exists('cs_perf_rank_result')) require_once (dirname(__DIR__, 2)) . '/functions.php';
            [$year, $mon] = array_map('intval', explode('-', $month));
            $candidates = [];
            if (function_exists('cs_perf_rank_list')) foreach (cs_perf_rank_list($year, $mon) as $item) $candidates[(int)$item['id']] = true;
            foreach ($inputs[(int)$rule['id']] ?? [] as $eid => $input) if ($eid > 0) $candidates[$eid] = true;
            foreach (array_keys($candidates) as $eid) {
                if ($rule['employee_id'] !== null && (int)$rule['employee_id'] !== $eid) continue;
                $override = $inputs[(int)$rule['id']][$eid] ?? null;
                if ($override !== null) { $amount = (float)$override['value']; $how = '本月填写 ¥' . money_plain($amount) . ($override['note'] !== '' ? '（' . $override['note'
    ] . '）' : ''); }
                else { $result = cs_perf_rank_result($eid, $year, $mon); $amount = (float)$result['amount']; $how = $result['formula']; }
                if ($amount <= 0) continue;
                [$value, $prorate] = ps_monthly_prorate($amount, $attendance[$eid] ?? null);
                $add($eid, $rule, $value, $how . '；' . $prorate);
            }