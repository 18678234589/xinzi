<?php

            // 名次由财务每月填写（如客服绩效考核排名）。
            $awards = array_values(array_map('floatval', $p['awards'] ?? []));
            foreach ($inputs[(int)$rule['id']] ?? [] as $eid => $input) {
                $position = (int)$input['value'];
                if ($eid === 0 || $position < 1 || !isset($awards[$position - 1])) continue;
                $add($eid, $rule, $awards[$position - 1], '第 ' . $position . ' 名（财务填写）' . ($input['note'] !== '' ? '：' . $input['note'] : ''));
            }