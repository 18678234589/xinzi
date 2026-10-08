<?php

            $parts = [];
            $total = 0.0;
            foreach ((array)($p['counters'] ?? []) as $counter) {
                $filterColumn = trim((string)($counter['column'] ?? ''));
                $filterKeywords = array_filter(array_map('trim', explode('+', (string)($counter['keywords'] ?? ''))));
                $filterMatch = ($counter['match'] ?? 'all') === 'any' ? 'any' : 'all';
                $unit = (float)($counter['unit'] ?? 0);
                $dedupByOrderNo = $filterColumn !== '';
                $cnt = 0;
                $seen = [];
                foreach ($orders as $o) {
                    $rd = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                    if (!is_array($rd)) $rd = [];
                    // 排除退款订单
                    if ((isset($rd['__is_refund__']) && $rd['__is_refund__'] === '1') || (float)($o['order_amount'] ?? 0) < 0) continue;
                    if (preg_match('/交易关闭|退款成功|全额退款|退款中|售后中/u', (string)($rd['__order_status__'] ?? ''))) continue;
                    if ($dedupByOrderNo) {
                        // 按 order_no 去重（避免多模块上传导致重复行），先去重后筛关键词（与旧引擎一致）
                        $ono = trim($o['order_no'] ?? '');
                        if ($ono !== '' && isset($seen[$ono])) continue;
                        $val = $getCol($rd, $filterColumn);
                        if ($val === '') continue;
                        if (!empty($filterKeywords)) {
                            // 与旧引擎一致：any=至少命中一个（初值 false），all=全部命中（初值 true）
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
                        if ($ono !== '') $seen[$ono] = true;
                        $cnt++;
                    } else {
                        // 单量补贴：同旺旺同日期只算 1 单；旺旺或日期为空的不计入
                        $wangwang = $getCol($rd, '旺旺');
                        $dateVal = $getCol($rd, '日期');
                        if ($wangwang === '' || $dateVal === '') continue;
                        $key = $wangwang . '|' . $dateVal;
                        if (isset($seen[$key])) continue;
                        $seen[$key] = true;
                        $cnt++;
                    }
                }
                $amount = round($cnt * $unit, 2);
                $total += $amount;
                $label = trim((string)($counter['name'] ?? '')) ?: ($filterColumn !== '' ? $filterColumn : '单量');
                $unitText = rtrim(rtrim(money_plain($unit), '0'), '.');
                if ($filterColumn !== '' && !empty($filterKeywords)) {
                    $kwLabel = ($filterMatch === 'any' ? '含任一“' : '含全部“') . implode('+', $filterKeywords) . '”';
                    $parts[] = sprintf('%s %d 单 × ¥%s = ¥%s（%s列%s）', $label, $cnt, $unitText, money_plain($amount), $filterColumn, $kwLabel);
                } else {
                    $parts[] = sprintf('%s（旺旺+日期去重）%d 单 × ¥%s = ¥%s', $label, $cnt, $unitText, money_plain($amount));
                }
            }
            $add($eid, $rule, $total, sprintf('%s匹配%s：%s', $gateColumn, $employeeName, implode('；', $parts)) . ($allowNoReceipt ? '；无流水单量自动核验，按月只计一次；利润分成另核实收'
    : ''), true);