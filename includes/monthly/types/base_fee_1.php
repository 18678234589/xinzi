<?php

            $eid = (int)$rule['employee_id'];
            $override = $inputs[(int)$rule['id']][$eid] ?? null;
            $amount = $override !== null ? (float)$override['value'] : (float)($tierBases[$eid]['amount'] ?? $p['amount'] ?? 0);