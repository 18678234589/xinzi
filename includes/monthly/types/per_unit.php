<?php

            $unit = (float)($p['amount'] ?? 0);
            if (($p['auto'] ?? '') === 'template_dev') { // 优站 / WP 模板开发补助：件数取自“优站模板开发登记”，订单审核当月自动计入
                require_once __DIR__ . '/../../ProjectTemplateDev.php';
                foreach (ptmd_counts($snapshots) as $eid => $c) {
                    $n = $c[($p['kind'] ?? 'youzhan') === 'wp' ? 'wp' : 'youzhan'];
                    if ($n > 0 && ($rule['employee_id'] === null || (int)$rule['employee_id'] === $eid)) $add($eid, $rule, $n * $unit, sprintf('%d 个 × ¥%s（%s）', $n, money_plain($unit), '订单完成并审核后计入'));
                }
            } else foreach ($inputs[(int)$rule['id']] ?? [] as $eid => $input) {
                if ($eid === 0 || (float)$input['value'] == 0) continue;
                if ($rule['employee_id'] !== null && (int)$rule['employee_id'] !== $eid) continue;
                $add($eid, $rule, (float)$input['value'] * $unit, sprintf('%s 个 × ¥%s%s', rtrim(rtrim(money_plain($input['value']), '0'), '.'), money_plain($unit), $input['note'
    ] !== '' ? '（' . $input['note'] . '）' : ''));
            }