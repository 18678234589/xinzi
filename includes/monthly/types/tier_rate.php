<?php

            foreach ($people as $eid => $m) {
                $tier = ps_monthly_pick_tier($p['tiers'] ?? [], $m[$metric]);
                if (!$tier) continue;
                $target = $m['portion'] * (float)$tier['rate'];
                $add($eid, $rule, $target - $m['share'], sprintf('月%s ¥%s 落在 ≥¥%s 档 %s%%：毛利 ¥%s × %s%% = ¥%s，逐单已计 ¥%s', $metricLabel, money_plain($m
    [$metric]), money_plain($tier['from']), round((float)$tier['rate'] * 100, 2), money_plain($m['portion']), round((float)$tier['rate'] * 100, 2), money_plain($target), money_plain
    ($m['share'])) . (isset($tierBases[$eid]) ? '；' . $tierBases[$eid]['detail'] : ''));
            }