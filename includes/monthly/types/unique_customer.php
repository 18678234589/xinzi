<?php

            // 本月范围内本人订单按客户旺旺去重：同一旺旺下两单、三单都只算 1 单；已按逐单规则发过每单补助的旺旺不重复发；全额退款不计；
            // 部分退款 / 旺旺与另一个很像的，等财务在“单量补助待核对”里标记“可以结算”后才计入（puc_analyze）。
            $uc = puc_analyze($rule, $snapshots);
            $ucDecided = puc_decisions($rule['id'], $month);
            $paidAlready = 0; $payable = 0; $held = 0;
            foreach ($uc['customers'] as $ucKey => $ucCustomer) {
                if ($ucCustomer['paid']) $paidAlready++;
                elseif ($ucCustomer['flag'] === '' || ($ucDecided[$ucKey] ?? '') === 'pay') $payable++;
                elseif (($ucDecided[$ucKey] ?? 'pending') === 'pending') $held++;
            }
            $unit = (float)($p['amount'] ?? 0);
            $add($rule['employee_id'], $rule, $payable * $unit, sprintf('%d 个不同客户旺旺（同一旺旺多单只算 1 单），其中 %d 个已按逐单规则发过每单补助，本项 %d 单 × ¥%s%s%s', count($uc['customers']), $paidAlready, $payable, money_plain($unit), $held ? '；另有 ' . $held . ' 个旺旺待财务核对，核对为“可以结算”后计入' : '', $uc['noNick'] ? '；' . $uc['noNick'] . ' 单没有旺旺未计' : ''));
