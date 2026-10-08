<?php

            $threshold = (float)($p['threshold'] ?? 0);
            $rate = (float)($p['rate'] ?? 0);
            foreach ($people as $eid => $m) {
                if ($m[$metric] <= $threshold) continue;
                $add($eid, $rule, ($m[$metric] - $threshold) * $rate, sprintf('月%s ¥%s − 门槛 ¥%s = ¥%s，× %s%%', $metricLabel, money_plain($m[$metric]), money_plain($threshold), money_plain($m[$metric] - $threshold), round($rate * 100, 4)));
            }