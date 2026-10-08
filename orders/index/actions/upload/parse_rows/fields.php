<?php

                        $rowIdx = 0;
                        foreach ($dataRows as $row) {
                            $rowIdx++;
                            if (count(array_filter($row, fn($v) => trim($v) !== '')) === 0) continue;

                            // 计算订单金额
                            $feeRate = 0;       // 手续费率
                            $feeAmount = 0;     // 手续费金额
                            $originalPrice = 0;  // 原始售价（扣手续费前）
                            if ($idxAmount !== null) {
                                // 直接使用订单金额列（美工部等），售价即为订单金额
                                $amount = extract_amount($row[$idxAmount] ?? '');
                                $originalPrice = $amount;
                            } elseif ($idxPrice !== null && $idxCost !== null) {
                                // 金额 = 售价 - 成本
                                $price  = extract_amount($row[$idxPrice] ?? '');
                                $cost   = extract_amount($row[$idxCost] ?? '');
                                $originalPrice = $price;
                                $amount = $price - $cost;

                                // ====== 手续费扣除 ======
                                // 优先级：算法配置中选中模块的 service_fee_rate > dept_fee.php 部门费率（仅部门订单）
                                $feeRate = 0;
                                $modMatched = false;

                                // 1. 查合作人员算法配置，按选中的模块名匹配（已预加载到 $modCfg）
                                if (!empty($projectArr) && $employee_id > 0 && $modCfg && !empty($modCfg['modules'])) {
                                    foreach ($modCfg['modules'] as $m) {
                                        if (($m['enabled'] ?? true) && in_array($m['name'] ?? '', $projectArr)) {
                                            $feeRate = (float)$m['config']['service_fee_rate'] ?? 0;
                                            $modMatched = true;
                                            break;
                                        }
                                    }
                                }

                                // 2. 部门订单且模块未匹配到时，回退到 dept_config.php / dept_fee.php
                                if (!$modMatched && $order_scope === 'department' && $dept_name !== '') {
                                    // 优先查 dept_config.php（网站售后部独立配置）
                                    static $deptConfigMap = null;
                                    if ($deptConfigMap === null) {
                                        $deptConfigFile = (dirname((dirname((dirname((dirname(((dirname(__DIR__, 1)) . '/part_2/parts'), 1)), 1)), 1)), 2)) . '/../config/dept_config.php';
                                        if (file_exists($deptConfigFile)) {
                                            $dc = include $deptConfigFile;
                                            if (is_array($dc) && isset($dc['dept_name'], $dc['service_fee_rate'])) {
                                                $deptConfigMap = [$dc['dept_name'] => (float)$dc['service_fee_rate']];
                                            } else {
                                                $deptConfigMap = [];
                                            }
                                        } else {
                                            $deptConfigMap = [];
                                        }
                                    }
                                    $feeRate = isset($deptConfigMap[$dept_name]) ? $deptConfigMap[$dept_name] : 0.0;

                                    // 再回退到 dept_fee.php（通用部门费率配置）
                                    if ($feeRate === 0) {
                                        static $deptFeeMap = null;
                                        if ($deptFeeMap === null) {
                                            $deptFeeFile = (dirname((dirname((dirname((dirname(((dirname(__DIR__, 1)) . '/part_2/parts'), 1)), 1)), 1)), 2)) . '/../config/dept_fee.php';
                                            $deptFeeMap = file_exists($deptFeeFile) ? (include $deptFeeFile) : [];
                                            if (!is_array($deptFeeMap)) $deptFeeMap = [];
                                        }
                                        $feeRate = isset($deptFeeMap[$dept_name]) ? (float)$deptFeeMap[$dept_name] : 0.0;
                                    }
                                }

                                // 3. 扣除手续费
                                if ($feeRate > 0) {
                                    $feeAmount = round($price * $feeRate, 2);
                                    $amount = round($price - $feeAmount - $cost, 2);
                                }
                            } else {
                                // 纯数量表（无金额列），订单金额存0，按数量计算的模块只数笔数
                                $amount = 0;
                            }

                            // 日期：优先使用上传时选择的归属月份，忽略Excel中的日期列
                            // 使用归属月份的第一天作为订单日期
                            $parsedDate = $upload_month . '-01';
                            $dateErr = '';

                            // 异常标记
                            $isAbn = 0; $abnReason = '';
                            // 退款订单标记：金额为负数，或金额为0且非纯数量表（纯数量表金额0是正常的）
                            $isRefund = ($amount < 0) || ($amount == 0 && !$countOnlyModules);

                            // 原样存储每列；部门订单额外存入部门名
                            $rawMap = [];
                            foreach ($normalizedHeaders as $ci => $hdr) {
                                $rawMap[$hdr] = $row[$ci] ?? '';
                            }
                            if ($order_scope === 'department') {
                                $rawMap['__dept__'] = $dept_name;
                                // 存储合作人员-模块映射，结算时虚拟生成拆分行（不再物理插入N条拆分记录）
                                if (!empty($deptEmpModules)) {
                                    $rawMap['__dept_modules__'] = $deptEmpModules;
                                }
                            }
                            // 存储手续费拆分信息，供前端展示（个人订单 + 部门订单）
                            if ($feeRate > 0) {
                                $rawMap['__fee_rate__'] = $feeRate;
                                $rawMap['__fee_amount__'] = $feeAmount;
                            }
                            // 始终存储原始售价，供异常订单对比使用（售价匹配，非利润匹配）
                            if ($originalPrice > 0) {
                                $rawMap['__original_price__'] = $originalPrice;
                            }
                            if ($isRefund) {
                                $rawMap['__is_refund__'] = '1'; // 标记为退款订单
                            }
                            // 订单状态统一设置为"未核验"
                            $rawMap['__order_status__'] = '未核验';
                            // 提取订单号（用于后续店铺/合作人员订单对比）
                            $orderNo = extract_order_no($rawMap);

                            // 没有订单编号的订单跳过并记录行号
                            if ($orderNo === '') {
                                $noOrderNoRows[] = $rowIdx;
                                continue;
                            }

                            // 部门订单只插一条汇总记录（employee_id=0），结算时按 __dept_modules__ 虚拟拆分
                            if ($order_scope === 'department' && !empty($deptEmpModules)) {
                                $stmt->execute([0, $amount, $parsedDate, $deptProjStr, $orderNo, json_encode($rawMap, JSON_UNESCAPED_UNICODE), $isAbn, $abnReason, $order_scope]);
                                $isAbn ? $skipped++ : $inserted++;

                                // 归属字段匹配：只在指定的列中查找合作人员姓名
                                $matchedEmps = [];
                                // 合作人员姓名→模块配置映射已在外层预构建到 $empNameMap
                                // 只在指定的归属字段列中匹配合作人员姓名
                                $scanKeys = !empty($ownershipFields) ? $ownershipFields : array_keys($rawMap);
                                foreach ($scanKeys as $field) {
                                    if (!isset($rawMap[$field])) continue;
                                    $v = trim((string)$rawMap[$field]);
                                    if ($v === '') continue;
                                    if (isset($empNameMap[$v])) {
                                        $dem = $empNameMap[$v];
                                        // 去重：同一合作人员不重复添加
                                        $alreadyMatched = false;
                                        foreach ($matchedEmps as $m) {
                                            if ((int)$m['employee_id'] === (int)$dem['employee_id']) {
                                                $alreadyMatched = true;
                                                break;
                                            }
                                        }
                                        if (!$alreadyMatched) {
                                            $matchedEmps[] = $dem;
                                        }
                                    }
                                }

                                // 有匹配的合作人员：只为匹配到的合作人员创建拆分行
                                // 无匹配：跳过，不分配给任何人
                                $splits = $matchedEmps;
                                $isUnmatched = empty($matchedEmps);
                                if ($isUnmatched) $unmatched++;
                                foreach ($splits as $dem) {
                                    $demRawMap = $rawMap;
                                    $demRawMap['__from_dept__'] = $dept_name;
                                    if ($isUnmatched) {
                                        $demRawMap['__unmatched__'] = '1'; // 标记为未归属
                                    }
                                    $stmt->execute([$dem['employee_id'], $amount, $parsedDate, $dem['module'], $orderNo, json_encode($demRawMap, JSON_UNESCAPED_UNICODE), $isAbn, $abnReason, 'personal']);
                                }
                            } else {
                                $bindEmpId = $order_scope === 'department' ? 0 : $employee_id;
                                if (empty($projectArr)) {
                                    // 不指定模块，project 存空
                                    $stmt->execute([$bindEmpId, $amount, $parsedDate, '', $orderNo, json_encode($rawMap, JSON_UNESCAPED_UNICODE), $isAbn, $abnReason, $order_scope]);
                                    $isAbn ? $skipped++ : $inserted++;
                                } else {
                                    // 多模块：每个选中的模块插一条副本，project 各不相同
                                    foreach ($projectArr as $proj) {
                                        $stmt->execute([$bindEmpId, $amount, $parsedDate, $proj, $orderNo, json_encode($rawMap, JSON_UNESCAPED_UNICODE), $isAbn, $abnReason, $order_scope]);
                                        $isAbn ? $skipped++ : $inserted++;
                                    }
                                }
                            }
                        }
                        db()->commit();
                        