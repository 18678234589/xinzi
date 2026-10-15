<?php

            // 月营业额只认已审核订单：还有没审核的，在明细里写清楚（否则营业额按 0 落最低档却看不出原因）
            [$pendingN, $pendingSum] = $forecast ? [0, 0.0] : ps_unreviewed_orders($eid, $month, ps_monthly_scope_businesses($rule));
            $pendingNote = $pendingN > 0 ? sprintf('；注意：本月还有 %d 单（售价合计 ¥%s）没审核，未计入营业额，审核通过后会按新的营业额重新落档', $pendingN, money_plain($pendingSum)) : '';
            [$baseValue, $prorate] = ps_monthly_prorate($calc['base'], $attendance[$eid] ?? null);
            $add($eid, ['name' => $rule['name'] . ' · 底薪'] + $rule, $baseValue, $calc['tier_text'] . '（' . count($orders) . ' 单），该档' . ((float)$calc['tier']['rate'
    ] > 0 ? '底薪' : '保底') . ' ¥' . money_plain($calc['base']) . '；' . $prorate . ($review === null && !empty($p['review_min']) ? '；本月好评率未填写，暂不扣罚'
    : '') . $pendingNote);
            foreach ($calc['items'] as [$label, $amount, $detail]) $add($eid, ['name' => $rule['name'] . ' · ' . $label] + $rule, $amount, $detail);