<?php
trait CalcReferralTrait
{
    private static function calcReferralOrder($cfg, $c, $moduleName = '')
    {
        // count_mode: 'keyword'(默认，按列+关键词计数) / 'staff_match'(接单客服匹配合作人员姓名+旺旺日期去重)
        $countMode = $cfg['count_mode'] ?? 'keyword';

        if ($countMode === 'staff_match') {
            // 接单客服出现合作人员姓名 → 该表订单归属此合作人员 → 计算单量（旺旺+日期去重）
            // 可选：配置 count_column + count_keyword 时，先按该列关键词筛选，再去重计数
            $employeeName = trim($c['employee']['name'] ?? '');
            $subsidy = (float)($cfg['subsidy'] ?? 0);

            // 拍建站列关键词筛选（可选）
            $filterColumn = isset($cfg['count_column']) && trim($cfg['count_column']) !== '' ? trim($cfg['count_column']) : '';
            $filterKeywords = $filterColumn !== '' ? array_filter(array_map('trim', explode('+', $cfg['count_keyword'] ?? ''))) : [];
            $filterMatch = $cfg['count_keyword_match'] ?? 'all'; // all=AND / any=OR

            // 辅助：模糊匹配列名取值
            $getCol = function($rd, $colName) {
                if (isset($rd[$colName])) return trim($rd[$colName]);
                foreach ($rd as $k => $v) {
                    if (mb_strpos($k, $colName) !== false) return trim($v);
                }
                return '';
            };

            // 第一步：扫描所有订单，判断接单客服列是否出现过合作人员姓名
            $ownsTable = false;
            foreach (($c['orders'] ?? []) as $o) {
                $rd = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                if (!is_array($rd)) $rd = [];
                $kefu = $getCol($rd, '接单客服');
                if ($kefu === '') continue;
                $names = array_map('trim', explode(',', $kefu));
                if (in_array($employeeName, $names, true)) { $ownsTable = true; break; }
            }

            if (!$ownsTable) {
                return [
                    'amount' => 0,
                    'formula' => sprintf('0.00（接单客服无匹配%s的订单）', $employeeName),
                    'type' => 'referral_order',
                ];
            }

            // 第二步：接单客服匹配到合作人员姓名，该表订单归属此合作人员，计算单量
            // - 配置了 count_column（如"拍建站链接"按单补助）：按 order_no 去重，每条匹配订单算1单
            //   （不要求付费旺旺/日期非空，这类数量表常不填旺旺日期，否则会少算）
            // - 未配置 count_column（如"单量补贴"，统计独立客户单量）：按 付费旺旺+日期 去重
            $cnt = 0;
            $seen = []; // key 视去重方式而定
            $dedupByOrderNo = ($filterColumn !== '');
            foreach (($c['orders'] ?? []) as $o) {
                $rd = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                if (!is_array($rd)) $rd = [];
                // 排除退款订单
                $isRefund = isset($rd['__is_refund__']) && $rd['__is_refund__'] === '1';
                if ($isRefund || (float)($o['order_amount'] ?? 0) < 0) continue;
                // 按 order_no 去重（避免多模块上传导致重复行）
                if ($dedupByOrderNo) {
                    $ono = trim($o['order_no'] ?? '');
                    if ($ono !== '' && isset($seen[$ono])) continue;
                }
                // 可选：按拍建站列关键词筛选
                if ($filterColumn !== '') {
                    $val = $getCol($rd, $filterColumn);
                    if ($val === '') continue;
                    if (!empty($filterKeywords)) {
                        if ($filterMatch === 'any') {
                            $match = false;
                            foreach ($filterKeywords as $kw) {
                                if (mb_strpos($val, $kw) !== false) { $match = true; break; }
                            }
                        } else {
                            $match = true;
                            foreach ($filterKeywords as $kw) {
                                if (mb_strpos($val, $kw) === false) { $match = false; break; }
                            }
                        }
                        if (!$match) continue;
                    }
                }
                if ($dedupByOrderNo) {
                    // 按单补助：每条匹配订单算1单，按 order_no 去重
                    $ono = trim($o['order_no'] ?? '');
                    if ($ono !== '') $seen[$ono] = true;
                    $cnt++;
                } else {
                    // 单量补贴：读取"旺旺"和"日期"列，同旺旺同日期只算1单
                    $wangwang = $getCol($rd, '旺旺');
                    $dateVal  = $getCol($rd, '日期');
                    // 旺旺或日期为空的不计入单量
                    if ($wangwang === '' || $dateVal === '') continue;
                    $key = $wangwang . '|' . $dateVal;
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $cnt++;
                }
            }
            $subsidyAmt = $cnt * $subsidy;
            // 公式描述
            if ($filterColumn !== '' && !empty($filterKeywords)) {
                $kwLabel = ($filterMatch === 'any' ? "含任一'" : "含全部'") . implode('+', $filterKeywords) . "'";
                $formula = sprintf('接单客服匹配%s %s%s %d单×¥%g(每单补助)=%.2f', $employeeName, $filterColumn, $kwLabel, $cnt, $subsidy, $subsidyAmt);
            } else {
                $formula = sprintf('接单客服匹配%s %d单×¥%g(每单补助)=%.2f', $employeeName, $cnt, $subsidy, $subsidyAmt);
            }
            if ($cnt === 0) {
                $formula = $filterColumn !== ''
                    ? sprintf('0.00（接单客服匹配%s但%s列无命中订单）', $employeeName, $filterColumn)
                    : sprintf('0.00（接单客服匹配%s但无有效订单）', $employeeName);
            }
            return [
                'amount' => round($subsidyAmt, 2),
                'formula' => $formula,
                'type' => 'referral_order',
            ];
        }

        // 按指定列+关键词计数（如"建站订单"列值同时包含"拍"+"链接"）
        $countColumn = isset($cfg['count_column']) && trim($cfg['count_column']) !== '' ? trim($cfg['count_column']) : '';
        if ($countColumn !== '') {
            $keywords = array_filter(array_map('trim', explode('+', $cfg['count_keyword'] ?? '')));
            $kwMatch  = $cfg['count_keyword_match'] ?? 'all'; // all=同时包含(AND) / any=任一包含(OR)
            $seen = [];   // 按 order_no 去重
            $cnt = 0;
            foreach (($c['orders'] ?? []) as $o) {
                $ono = trim($o['order_no'] ?? '');
                if ($ono !== '' && isset($seen[$ono])) continue;
                if ($ono !== '') $seen[$ono] = true;
                $rd = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                if (!is_array($rd)) $rd = [];
                // 排除退款订单（金额<0或标记为退款），退款不计算拍链接补贴
                // 注意：纯数量表金额=0是正常的，不应排除
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
                if ($val === '') continue;
                // 关键词匹配：all=同时包含所有，any=包含任一即可
                if (empty($keywords)) {
                    $match = true; // 无关键词则只需列值非空
                } elseif ($kwMatch === 'any') {
                    $match = false;
                    foreach ($keywords as $kw) {
                        if (mb_strpos($val, $kw) !== false) { $match = true; break; }
                    }
                } else {
                    $match = true;
                    foreach ($keywords as $kw) {
                        if (mb_strpos($val, $kw) === false) { $match = false; break; }
                    }
                }
                if ($match) $cnt++;
            }
            $subsidy = (float)($cfg['subsidy'] ?? 0);
            $subsidyAmt = $cnt * $subsidy;
            if (!empty($keywords)) {
                $kwLabel = ($kwMatch === 'any' ? "含任一'" : "含全部'") . implode('+', $keywords) . "'";
            } else {
                $kwLabel = '非空';
            }
            $formula = sprintf('%s%s%d单×¥%g(每单补助)=%.2f', $countColumn, $kwLabel, $cnt, $subsidy, $subsidyAmt);
            if ($cnt === 0) {
                $formula = sprintf('0.00（%s列无匹配%s的订单）', $countColumn, $kwLabel);
            }
            return [
                'amount' => round($subsidyAmt, 2),
                'formula' => $formula,
                'type' => 'referral_order',
            ];
        }

        // 原有逻辑：按 project 名筛选订单（不再支持金额范围/店铺关键字过滤）
        $total = self::filterOrderTotal($c, $moduleName);
        $count = self::filterOrderCount($c, $moduleName);

        $subsidy = (float)($cfg['subsidy'] ?? 0);
        // 引流订单项目报酬 = 每单补助金额 × 订单数量（订单金额仅用于筛选/展示）
        $subsidyAmt = $count * $subsidy;
        $amt = $subsidyAmt;

        $formula = sprintf('%d单×¥%g(每单补助)=%.2f', $count, $subsidy, $subsidyAmt);
        if ($count === 0) {
            $formula = '0.00（无匹配订单）';
        }
        if ($formula === '') {
            $formula = '0.00';
        }
        return [
            'amount' => round($amt, 2),
            'formula' => $formula,
            'type' => 'referral_order',
        ];
    }

}
