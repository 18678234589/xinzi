<?php

            $counted = [];
            foreach ($snapshots as $snap) {
                $businesses = ps_monthly_scope_businesses($rule);
                if ($businesses !== null && !in_array(ps_business_normalize($snap['project_type']), $businesses, true)) continue;
                if ((float)$snap['direct_cost'] > 0) $counted[(int)$snap['order_id']] = true;
            }
            $extra = $inputs[(int)$rule['id']][(int)$rule['employee_id']] ?? null;
            $total = count($counted) + ($extra !== null ? (float)$extra['value'] : 0);
            $unit = (float)($p['amount'] ?? 0);
            $add($rule['employee_id'], $rule, $total * $unit, sprintf('系统内 %d 单%s = %s 单 × ¥%s', count($counted), $extra !== null ? ' + 未录入系统 ' . rtrim(rtrim(money_plain($extra['value']), '0'), '.') . ' 单' . ($extra['note'] !== '' ? '（' . $extra['note'] . '）' : '') : '', rtrim(rtrim(money_plain($total), '0'), '.'), money_plain($unit)));