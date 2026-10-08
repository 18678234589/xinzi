<?php

            $orders = [];
            $commissions = 0.0;
            $businesses = ps_monthly_scope_businesses($rule);
            foreach ($snapshots as $snap) {
                if ($businesses !== null && !in_array(ps_business_normalize($snap['project_type']), $businesses, true)) continue;
                $oid = (int)$snap['order_id'];
                $orders[$oid] = $orders[$oid] ?? ['pool' => null, 'max' => null, 'revenue' => 0.0];
                if (isset($snap['department_profit'], $snap['department_revenue'])) {
                    // 部门毛利按整单成本与完整服务费计一次，不能取某个人分摊后的基数。
                    $orders[$oid]['pool'] = (float)$snap['department_profit'];
                    $orders[$oid]['revenue'] = (float)$snap['department_revenue'];
                    $commissions += (float)$snap['commission_amount'];
                    continue;
                }
                // 收入口径：售价收入 − 服务费（每单计一次）
                $orders[$oid]['revenue'] = max($orders[$oid]['revenue'], (float)$snap['income_amount'] - (float)$snap['service_fee']);
                if ($snap['calc_mode'] === 'pool' && (float)$snap['group_weight'] >= 1) $orders[$oid]['pool'] = (float)$snap['contribution_profit'];
                else $orders[$oid]['max'] = max((float)$snap['contribution_profit'], $orders[$oid]['max'] ?? -INF);
                $commissions += (float)$snap['commission_amount'];
            }
            $byRevenue = ($p['base'] ?? 'profit') === 'revenue';
            // 毛利口径每单只计一次：组池快照的计提基数即整单毛利；否则取其中最大者。
            $total = $byRevenue ? array_sum(array_column($orders, 'revenue')) : array_sum(array_map(function ($o) { return $o['pool'] ?? $o['max'] ?? 0; }, $orders));
            $deduct = !empty($p['deduct_commissions']) ? $commissions : 0.0;
            $extra = (float)($inputs[(int)$rule['id']][0]['value'] ?? 0);
            $rate = (float)($p['rate'] ?? 0);
            $share = (float)($p['share'] ?? 1);
            $base = max($total - $deduct - $extra, 0);
            $add($rule['employee_id'], $rule, $base * $rate * $share, sprintf('部门 %d 单%s ¥%s%s − 其他费用 ¥%s = ¥%s，× %s%% × 分配 %s%%', count($orders), $byRevenue ? '收入（扣服务费）' : '毛利', money_plain($total), $deduct > 0 ? ' − 部门提成 ¥' . money_plain($deduct) : '', money_plain($extra), money_plain($base), round($rate * 100, 4), round($share * 100, 2)));