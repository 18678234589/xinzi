<?php

            $unit = (float)($p['amount'] ?? 0);
            foreach ($inputs[(int)$rule['id']] ?? [] as $eid => $input) {
                if ($eid === 0 || (float)$input['value'] == 0) continue;
                if ($rule['employee_id'] !== null && (int)$rule['employee_id'] !== $eid) continue;
                $add($eid, $rule, (float)$input['value'] * $unit, sprintf('%s 个 × ¥%s%s', rtrim(rtrim(money_plain($input['value']), '0'), '.'), money_plain($unit), $input['note'
    ] !== '' ? '（' . $input['note'] . '）' : ''));
            }