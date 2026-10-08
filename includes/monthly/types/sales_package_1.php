<?php

            $eid = (int)$rule['employee_id'];
            $orders = [];
            foreach ($snapshots as $snap) {
                if (!ps_monthly_snapshot_matches($rule, $snap) || (int)$snap['employee_id'] !== $eid) continue;
                $orders[(int)$snap['order_id']] = ['income' => (float)$snap['income_amount'], 'returning' => ($snap['order_kind'] ?? '') === '老客户找回'];
            }
            $review = $inputs[(int)$rule['id']][$eid] ?? null;
            $calc = ps_sales_package_calc($p, array_values($orders), $review !== null ? (float)$review['value'] : null);