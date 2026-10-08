<?php

            $override = $inputs[(int)$rule['id']][(int)$rule['employee_id']] ?? null;
            $amount = $override !== null ? (float)$override['value'] : (float)($p['amount'] ?? 0);
            $add($rule['employee_id'], $rule, $amount, ($override !== null ? '本月填写 ¥' . money_plain($amount) . ($override['note'] !== '' ? '（' . $override['note'] . '）' : '') : '每月固定 ¥' . money_plain($amount)) . (!empty($p['separate']) ? '，另行支付，不计入应结算' : ''));