<?php

            foreach ($inputs[(int)$rule['id']] ?? [] as $eid => $input) {
                if ($eid === 0) continue;
                if ($rule['employee_id'] !== null && (int)$rule['employee_id'] !== $eid) continue;
                $add($eid, $rule, (float)$input['value'], $input['note'] !== '' ? $input['note'] : '财务填写');
            }