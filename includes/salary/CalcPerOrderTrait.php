<?php
trait CalcPerOrderTrait
{
    private static function calcPerOrder($cfg, $c, $moduleName = '')
    {
        // 按指定列计数（如"域名"列去重计数）
        $countColumn = isset($cfg['count_column']) && trim($cfg['count_column']) !== '' ? trim($cfg['count_column']) : '';
        if ($countColumn !== '') {
            $distinct = ($cfg['count_distinct'] ?? '是') !== '否';
            $seen = [];       // 按 order_no 去重（避免多模块上传导致重复行）
            $values = [];
            foreach (($c['orders'] ?? []) as $o) {
                $ono = trim($o['order_no'] ?? '');
                if ($ono !== '' && isset($seen[$ono])) continue;
                if ($ono !== '') $seen[$ono] = true;
                $rd = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                if (!is_array($rd)) $rd = [];
                // 排除退款订单（金额<0或标记为退款），退款不计算单量补贴
                $isRefund = isset($rd['__is_refund__']) && $rd['__is_refund__'] === '1';
                if ($isRefund || (float)($o['order_amount'] ?? 0) < 0) continue;
                // 模糊匹配列名：优先精确匹配，找不到则用包含匹配
                $val = '';
                if (isset($rd[$countColumn])) {
                    $val = trim($rd[$countColumn]);
                } else {
                    foreach ($rd as $k => $v) {
                        if (mb_strpos($k, $countColumn) !== false) { $val = trim($v); break; }
                    }
                }
                if ($val !== '') $values[] = $val;
            }
            $isSum = ($cfg['count_distinct'] ?? '') === '求和' || ($cfg['count_mode'] ?? '') === 'sum';
            if ($isSum) {
                $cnt = 0;
                foreach ($values as $v) {
                    if (is_numeric($v)) {
                        $cnt += (float)$v;
                    } elseif (preg_match('/\d+(?:\.\d+)?/', (string)$v, $m)) {
                        $cnt += (float)$m[0];
                    }
                }
            } else {
                $cnt = $distinct ? count(array_unique($values)) : count($values);
            }
            $amt1 = $cnt * (float)($cfg['per_amount'] ?? 50);
            $amt2 = $cnt * (float)($cfg['per_reward'] ?? 0);
            $colLabel = $isSum ? "{$countColumn}求和" : ($distinct ? "{$countColumn}去重" : "{$countColumn}非空");
            return [
                'amount' => round($amt1 + $amt2, 2),
                'formula' => sprintf('%s%g个×¥%g+¥%g=%.2f', $colLabel, $cnt, $cfg['per_amount']??50, $cfg['per_reward']??0, $amt1+$amt2),
                'type' => 'per_order',
            ];
        }

        // 原有逻辑：按 project 名/金额范围/店铺关键字筛选计数
        $minAmount = isset($cfg['min_amount']) && $cfg['min_amount'] !== '' && $cfg['min_amount'] !== null ? (float)$cfg['min_amount'] : null;
        $maxAmount = isset($cfg['max_amount']) && $cfg['max_amount'] !== '' && $cfg['max_amount'] !== null ? (float)$cfg['max_amount'] : null;
        $shopKeyword = isset($cfg['shop_keyword']) && $cfg['shop_keyword'] !== '' && $cfg['shop_keyword'] !== null ? $cfg['shop_keyword'] : null;

        $useFilter = ($minAmount !== null || $maxAmount !== null || $shopKeyword !== null);
        $filterByName = $useFilter ? '' : $moduleName;

        // count_column 为空时，按"付费旺旺"列去重计数
        $cnt = 0;
        $seen = [];
        $getCol = function($rd, $colName) {
            if (isset($rd[$colName])) return trim($rd[$colName]);
            foreach ($rd as $k => $v) {
                if (mb_strpos($k, $colName) !== false) return trim($v);
            }
            return '';
        };
        foreach (($c['orders'] ?? []) as $o) {
            // 模块名过滤
            if ($filterByName !== '' && trim($o['project'] ?? '') !== $filterByName) continue;

            $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
            if (!is_array($rawData)) $rawData = [];

            // 排除退款订单（金额<0或标记为退款），退款不计算单量补贴
            $isRefund = isset($rawData['__is_refund__']) && $rawData['__is_refund__'] === '1';
            if ($isRefund || (float)($o['order_amount'] ?? 0) < 0) continue;

            // 金额范围过滤
            $orderAmt = (float)($o['order_amount'] ?? 0);
            if ($minAmount !== null && $orderAmt < $minAmount) continue;
            if ($maxAmount !== null && $orderAmt > $maxAmount) continue;

            // 店铺关键字过滤
            if ($shopKeyword !== null) {
                $shop = '';
                if (isset($rawData[$shopKeyword])) {
                    $shop = trim($rawData[$shopKeyword]);
                } else {
                    foreach ($rawData as $k => $v) {
                        if (mb_strpos($k, '店铺') !== false || mb_strpos($k, '店名') !== false) {
                            $shop = trim($v);
                            break;
                        }
                    }
                }
                if ($shop === '') continue;
            }

            // 按"旺旺"列去重（兼容"付费旺旺"/"付款旺旺"/"客户旺旺或者微信名称"等不同列名）
            $wangwang = $getCol($rawData, '旺旺');
            if ($wangwang === '') continue; // 旺旺为空不计入
            if (isset($seen[$wangwang])) continue;
            $seen[$wangwang] = true;
            $cnt++;
        }
        $amt1 = $cnt * (float)($cfg['per_amount'] ?? 50);
        $amt2 = $cnt * (float)($cfg['per_reward'] ?? 0);
        return [
            'amount' => round($amt1 + $amt2, 2),
            'formula' => sprintf('%d×%g+%d×%g=%.2f', $cnt, $cfg['per_amount']??50, $cnt, $cfg['per_reward']??0, $amt1+$amt2),
            'type' => 'per_order',
        ];
    }

}
