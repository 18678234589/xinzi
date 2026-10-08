<?php

            $awards = array_values(array_filter(array_map('floatval', $p['awards'] ?? []), function ($v) { return $v > 0; }));
            $ranked = array_filter($people, function ($m) use ($metric) { return $m[$metric] > 0; });
            uasort($ranked, function ($a, $b) use ($metric) { return $b[$metric] <=> $a[$metric]; });
            $position = 0;
            foreach ($ranked as $eid => $m) {
                if (!isset($awards[$position])) break;
                $add($eid, $rule, $awards[$position], sprintf('第 %d 名（月%s ¥%s，共 %d 人参与排名）', $position + 1, $metricLabel, money_plain($m[$metric]), count($ranked)));
                $position++;
            }