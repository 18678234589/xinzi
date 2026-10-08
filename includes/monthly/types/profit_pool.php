<?php

            // 部门利润池（如微信代写）：池 = (范围内订单毛利合计 − 扣除额) × 比例；
            // 指定人员按固定比例分池，其余范围内人员分“成员比例”，按各自毛利占比。
            $orderProfit = [];
            foreach ($snapshots as $snap) if (ps_monthly_snapshot_matches($rule, $snap)) $orderProfit[(int)$snap['order_id']] = max((float)$snap['contribution_profit'], $orderProfit[(int)$snap['order_id']] ?? -INF);
            $total = array_sum($orderProfit);
            $deduction = (float)($p['deduction'] ?? 0);
            $pool = max($total - $deduction, 0) * (float)($p['rate'] ?? 0);
            $poolText = sprintf('部门毛利 ¥%s − 扣除 ¥%s = ¥%s，× %s%% = 池 ¥%s', money_plain($total), money_plain($deduction), money_plain(max($total - $deduction, 0)), round((float)($p['rate'] ?? 0) * 100, 4), money_plain($pool));
            $fixedIds = [];
            foreach ((array)($p['fixed'] ?? []) as $member) {
                $eid = (int)($member['employee_id'] ?? 0);
                if ($eid <= 0) continue;
                $fixedIds[] = $eid;
                $add($eid, $rule, $pool * (float)$member['share'], $poolText . sprintf('；本人固定 %s%%', round((float)$member['share'] * 100, 4)));
            }
            $membersShare = (float)($p['members_share'] ?? 0);
            foreach ($people as $eid => $m) {
                if (in_array($eid, $fixedIds, true) || $total <= 0) continue;
                $ratio = $m['portion'] / $total;
                $add($eid, $rule, $pool * $membersShare * $ratio, $poolText . sprintf('；成员分配 %s%% × 本人毛利占比 %s%%（¥%s / ¥%s）', round($membersShare * 100, 4), round($ratio * 100, 2), money_plain($m['portion']), money_plain($total)));
            }
            // 利润奖励（微信代写核算标准）：部门总利润超过起点后，每增加一档，部门每人（固定分成人员 + 当月有业绩的成员）奖固定金额，封顶
            $milestone = $p['milestone'] ?? null;
            if ($milestone && (float)($milestone['step'] ?? 0) > 0 && $total > (float)$milestone['from']) {
                $steps = (int)floor(($total - (float)$milestone['from']) / (float)$milestone['step'] + 1e-9);
                $bonus = min($steps * (float)$milestone['amount'], (float)($milestone['cap'] ?? INF));
                if ($bonus > 0) {
                    $recipients = array_unique(array_merge($fixedIds, array_keys(array_filter($people, function ($m) { return $m['portion'] > 0; }))));
                    $bonusRule = ['name' => $rule['name'] . ' · 利润奖励'] + $rule;
                    foreach ($recipients as $eid) $add($eid, $bonusRule, $bonus, sprintf('部门总利润 ¥%s 超过 ¥%s 满 %d 个 ¥%s，每人 %d × ¥%s = ¥%s（封顶 ¥%s）', money_plain($total), money_plain($milestone['from']), $steps, money_plain($milestone['step']), $steps, money_plain($milestone['amount']), money_plain($bonus), money_plain($milestone['cap'] ?? 0)));
                }
            }