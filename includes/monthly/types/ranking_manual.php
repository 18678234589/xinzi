<?php

            // 名次由财务每月填写（如客服绩效考核排名）。
            $awards = array_values(array_map('floatval', $p['awards'] ?? []));
            $positions = [];
            $eligibleIds = array_map('intval', $p['eligible_employee_ids'] ?? []);
            if (!empty($p['unique_positions']) && !empty($p['eligible_department'])) {
                // 按部门动态识别人选，不把未来加入的网站客服排除在政策之外。
                $eligible = db()->prepare('SELECT id FROM employees WHERE department=?');
                $eligible->execute([$p['eligible_department']]);
                $eligibleIds = array_map('intval', $eligible->fetchAll(PDO::FETCH_COLUMN));
            }
            if (!empty($p['unique_positions'])) {
                foreach ($inputs[(int)$rule['id']] ?? [] as $eid => $input) {
                    $pos = (int)$input['value'];
                    if ($pos > 0 && (float)$input['value'] === (float)$pos && isset($people[$eid]) && in_array((int)$eid, $eligibleIds, true)) $positions[$pos][] = (int)$eid;
                }
            }
            foreach ($inputs[(int)$rule['id']] ?? [] as $eid => $input) {
                $position = (int)$input['value'];
                if ($eid === 0 || $position < 1 || !isset($awards[$position - 1])) continue;
                if (!empty($p['unique_positions'])) {
                    if ((float)$input['value'] !== (float)$position || !isset($people[$eid]) || !in_array((int)$eid, $eligibleIds, true)) continue;
                    if (count($positions[$position] ?? []) !== 1) {
                        $add($eid, $rule, 0, '综合考评第 ' . $position . ' 名重复，请财务核对后确认；本项暂不计奖', true);
                        continue;
                    }
                }
                $add($eid, $rule, $awards[$position - 1], '第 ' . $position . ' 名（财务填写）' . ($input['note'] !== '' ? '：' . $input['note'] : ''));
            }
