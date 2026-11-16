<?php

            $eid = (int)$rule['employee_id'];
            $orders = [];
            foreach ($snapshots as $snap) {
                if (!ps_monthly_snapshot_matches($rule, $snap) || (int)$snap['employee_id'] !== $eid) continue;
                $orders[(int)$snap['order_id']] = ['income' => (float)$snap['income_amount'], 'returning' => ($snap['order_kind'] ?? '') === '老客户找回'];
            }
            // 老客户找回 +10% 只算“详情 / 首页”类设计：设计内容里没有这两个词的老客户单不加。
            $returningIds = array_keys(array_filter($orders, function ($o) { return $o['returning']; }));
            if ($returningIds) {
                $dq = db()->query('SELECT order_id,details_json FROM project_order_details WHERE order_id IN (' . implode(',', array_map('intval', $returningIds)) . ')');
                $items = [];
                foreach ($dq->fetchAll() as $d) $items[(int)$d['order_id']] = (string)((json_decode((string)$d['details_json'], true) ?: [])['design_item'] ?? '');
                foreach ($returningIds as $rid) if (!preg_match('/详情|首页/u', $items[$rid] ?? '')) $orders[$rid]['returning'] = false;
            }
            $review = $inputs[(int)$rule['id']][$eid] ?? null;
            $calc = ps_sales_package_calc($p, array_values($orders), $review !== null ? (float)$review['value'] : null);