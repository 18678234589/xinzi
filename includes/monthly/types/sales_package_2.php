<?php

            [$baseValue, $prorate] = ps_monthly_prorate($calc['base'], $attendance[$eid] ?? null);
            $add($eid, ['name' => $rule['name'] . ' · 底薪'] + $rule, $baseValue, $calc['tier_text'] . '（' . count($orders) . ' 单），该档' . ((float)$calc['tier']['rate'
    ] > 0 ? '底薪' : '保底') . ' ¥' . money_plain($calc['base']) . '；' . $prorate . ($review === null && !empty($p['review_min']) ? '；本月好评率未填写，暂不扣罚'
    : ''));
            foreach ($calc['items'] as [$label, $amount, $detail]) $add($eid, ['name' => $rule['name'] . ' · ' . $label] + $rule, $amount, $detail);