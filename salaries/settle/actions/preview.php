<?php

        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $month       = $_POST['month'] ?? '';

        if ($employee_id <= 0 || $month === '') {
            $error = '请选择合作人员和月份';
        } else {
            $emp = get_employee($employee_id);
            if (!$emp) {
                $error = '合作人员不存在';
            } else {
                // 加载当月订单（个人订单 + 部门订单虚拟拆分）
                $deptShare = true;
                $cfgData = SalaryCalculator::readModulesConfig($employee_id);
                if (($cfgData['dept_share'] ?? 1) == 0) $deptShare = false;

                // 网站售后部，优先使用 dept_config.php 独立配置，隔离其他分支的修改
                if ($deptConfigName !== '' && ($emp['department'] ?? '') === $deptConfigName) {
                    $deptShare = $deptConfigShare;
                }

                $loaded = loadEmployeeOrdersWithDept($employee_id, $month, $emp['department'] ?? '', $deptShare, $emp['name'] ?? '');
                $orderList = $loaded['orders'];
                $order_total = $loaded['order_total'];

                if (count($orderList) === 0) {
                    // 文员等无订单合作人员：不报错，继续计算（固定服务费+考勤+保险等不依赖订单）
                    $orderList = [];
                    $order_total = 0;
                }

                {
                    // 自定义额外金额（多项，每项含金额+备注）
                    $extraItems = [];  // [{amount, remark}, ...]
                    $extraAmount = 0;
                    $amounts = $_POST['extra_amounts'] ?? [];
                    $remarks = $_POST['extra_remarks'] ?? [];
                    foreach ($amounts as $i => $amt) {
                        $a = round((float)$amt, 2);
                        $r = trim($remarks[$i] ?? '');
                        if ($a != 0 || $r !== '') {
                            $extraItems[] = ['amount' => $a, 'remark' => $r];
                            $extraAmount += $a;
                        }
                    }
                    $extraAmount = round($extraAmount, 2);

                    // 全勤奖（先加后扣：自动抓取考勤，按请假小时数扣除）
                    $bonusBase = (float)($_POST['full_attendance_bonus'] ?? 200);
                    $bonus = calcFullAttendanceBonus($emp['id'], $month, $bonusBase);

                    // 调用项目报酬算法（自动选择合作人员专属算法或默认算法）
                    $result = SalaryCalculator::calculate($emp, $orderList, $order_total, $month);

                    // 固定服务费按出勤天数折算（分母固定30；处理 base_salary 字段与阶梯/客服绩效固定服务费等固定服务费类模块）
                    $baseInfo = applyProratedBaseSalary($result, $emp['id'], $month);

                    // 将自定义金额叠加到最终结果
                    $result['net_pay']    = round($result['net_pay'] + $extraAmount, 2);
                    $result['module_total'] = round(($result['module_total'] ?? 0) + $extraAmount, 2);
                    // 在模块列表末尾逐项追加，便于明细展示
                    foreach ($extraItems as $ei) {
                        if ($ei['amount'] != 0) {
                            $result['modules'][] = [
                                'name'   => $ei['remark'] !== '' ? $ei['remark'] : '自定义额外金额',
                                'amount' => round($ei['amount'], 2),
                                'formula'=> $ei['remark'] !== '' ? sprintf('手动调整 %+.2f（%s）', $ei['amount'], $ei['remark']) : sprintf('手动调整 %+.2f', $ei['amount']
    ),
                                'type'   => 'extra_amount',
                            ];
                        }
                    }

                    // 全勤奖叠加（先加后扣模式：净额累加到应结算金额）
                    if ($bonus['net'] != 0) {
                        $result['net_pay'] = round($result['net_pay'] + $bonus['net'], 2);
                        $result['module_total'] = round(($result['module_total'] ?? 0) + $bonus['net'], 2);
                        $result['modules'][] = [
                            'name'   => '全勤奖',
                            'amount' => round($bonus['base'], 2),
                            'formula'=> $bonus['status'],
                            'type'   => 'attendance_full',
                        ];
                        if ($bonus['deduct'] > 0) {
                            $result['modules'][] = [
                                'name'   => '全勤扣除',
                                'amount' => -round($bonus['deduct'], 2),
                                'formula'=> sprintf('请假扣减 -%.2f', $bonus['deduct']),
                                'type'   => 'attendance_deduct',
                            ];
                        }
                    }

                    // 保险扣除（勾选时扣除，默认勾选）
                    $deductInsurance = isset($_POST['deduct_insurance']);
                    if ($deductInsurance && $insuranceAmount > 0) {
                        $result['net_pay']      = round($result['net_pay'] - $insuranceAmount, 2);
                        $result['module_total'] = round(($result['module_total'] ?? 0) - $insuranceAmount, 2);
                        $result['modules'][] = [
                            'name'   => '保险扣除',
                            'amount' => -round($insuranceAmount, 2),
                            'formula'=> sprintf('保险扣除 -%.2f', $insuranceAmount),
                            'type'   => 'insurance',
                        ];
                    }

                    // DEBUG: 分析订单金额分布
                    $debug_info = "调试信息：\n";
                    $debug_info .= "订单总数: " . count($orderList) . " 笔，总金额: ¥{$order_total}\n\n";

                    // 统计金额分布
                    $over50 = [];
                    $under50 = [];
                    $oldCustomer = []; // 老客户订单
                    foreach ($orderList as $o) {
                        $amt = (float)($o['order_amount'] ?? 0);

                        // 排除退款订单
                        $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                        $isRefund = isset($rawData['__is_refund__']) && $rawData['__is_refund__'] === '1';

                        if (!$isRefund) {
                            if ($amt >= 50) {
                                $over50[] = $amt;
                            } elseif ($amt > 0) { // 只统计正数金额
                                $under50[] = $amt;
                            }
                        }

                        // 检查是否老客户订单
                        $isOldCustomer = false;
                        $oldCustomerColumn = '';
                        $oldCustomerValue = '';

                        // 优先查找"老客户"列
                        if (isset($rawData['老客户']) && trim($rawData['老客户']) !== '') {
                            $isOldCustomer = true;
                            $oldCustomerColumn = '老客户';
                            $oldCustomerValue = trim($rawData['老客户']);
                        } else {
                            // 否则查找"店铺"列中包含"老客户"的
                            foreach ($rawData as $k => $v) {
                                if (mb_strpos($k, '店铺') !== false || mb_strpos($k, '店名') !== false) {
                                    if (mb_strpos(trim($v), '老客户') !== false) {
                                        $isOldCustomer = true;
                                        $oldCustomerColumn = $k;
                                        $oldCustomerValue = trim($v);
                                    }
                                    break;
                                }
                            }
                        }

                        if ($isOldCustomer) {
                            $oldCustomer[] = ['id' => $o['id'], 'amt' => $amt, 'shop' => $oldCustomerValue, 'column' => $oldCustomerColumn];
                        }
                    }
                    $debug_info .= "≥50元订单: " . count($over50) . " 笔，金额: ¥" . array_sum($over50) . "\n";
                    $debug_info .= "  前5笔: " . json_encode(array_slice($over50, 0, 5)) . "\n\n";
                    $debug_info .= "<50元订单: " . count($under50) . " 笔，金额: ¥" . array_sum($under50) . "\n";
                    $debug_info .= "  前5笔: " . json_encode(array_slice($under50, 0, 5)) . "\n\n";

                    $debug_info .= "老客户订单: " . count($oldCustomer) . " 笔，金额: ¥" . array_sum(array_column($oldCustomer, 'amt')) . "\n";
                    if (count($oldCustomer) > 0) {
                        $debug_info .= "  明细:\n";
                        foreach (array_slice($oldCustomer, 0, 5) as $oc) {
                            $debug_info .= "    订单{$oc['id']}: ¥{$oc['amt']}, 列名:{$oc['column']}, 店铺值:{$oc['shop']}\n";
                        }
                    } else {
                        $debug_info .= "  未找到包含'老客户'的订单（检查'店铺'列的值）\n";
                    }
                    $debug_info .= "\n";

                    // 按模块名统计订单匹配情况（帮助排查"某模块¥0"问题）
                    $projectStats = [];
                    foreach ($orderList as $o) {
                        $proj = trim($o['project'] ?? '');
                        if ($proj === '') $proj = '(空)';
                        if (!isset($projectStats[$proj])) $projectStats[$proj] = ['cnt' => 0, 'total' => 0];
                        $projectStats[$proj]['cnt']++;
                        $projectStats[$proj]['total'] += (float)($o['order_amount'] ?? 0);
                    }
                    $debug_info .= "订单按project分布（用于模块匹配）:\n";
                    foreach ($projectStats as $proj => $st) {
                        $debug_info .= "  [{$proj}] => {$st['cnt']}笔, ¥" . number_format($st['total'], 2) . "\n";
                    }
                    $debug_info .= "\n";

                    $debug_info .= "计算结果：\n";
                    foreach (($result['modules'] ?? []) as $mod) {
                        $debug_info .= "  {$mod['name']}: ¥{$mod['amount']} (公式: {$mod['formula']})\n";
                    }

                    $preview = [
                        'employee'       => $emp,
                        'month'          => $month,
                        'order_count'    => count($orderList),
                        'order_total'    => $order_total,
                        'commission'     => $result['module_total'] ?? $result['commission'],
                        'net_pay'        => $result['net_pay'],
                        'extra_amount'   => $extraAmount,
                        'extra_items'    => $extraItems,
                        'full_attendance_bonus' => $bonusBase,
                        'bonus_info'     => $bonus,
                        'base_info'      => $baseInfo,
                        'modules'        => $result['modules'] ?? [],
                        'module_total'   => $result['module_total'] ?? $result['commission'],
                        'base_salary'    => $result['base_salary'] ?? (float)$emp['base_salary'],
                        'formula_text'   => $result['formula_text'],
                        'algorithm_name' => $result['algorithm_name'],
                        'is_custom'      => $result['is_custom'],
                        'insurance_amount' => ($deductInsurance && $insuranceAmount > 0) ? $insuranceAmount : 0,
                    ];

                    // 查询异常订单按模块分组统计
                    $abnormalStmt = db()->prepare(
                        "SELECT project, COUNT(*) AS cnt, COALESCE(SUM(order_amount), 0) AS total
                         FROM orders
                         WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ?
                         AND COALESCE(is_abnormal, 0) = 1 AND COALESCE(is_deleted, 0) = 0
                         GROUP BY project ORDER BY total DESC"
                    );
                    $abnormalStmt->execute([$employee_id, $month]);
                    $abnormalRows = $abnormalStmt->fetchAll();
                    $abnormalStmt->closeCursor();
                    $preview['abnormal_modules'] = $abnormalRows;

                    // 查询未核验订单按模块分组统计
                    $unverifiedStmt = db()->prepare(
                        "SELECT project, COUNT(*) AS cnt, COALESCE(SUM(order_amount), 0) AS total
                         FROM orders
                         WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ?
                         AND COALESCE(is_deleted, 0) = 0
                         AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__order_status__')) = '未核验'
                         GROUP BY project ORDER BY total DESC"
                    );
                    $unverifiedStmt->execute([$employee_id, $month]);
                    $unverifiedRows = $unverifiedStmt->fetchAll();
                    $unverifiedStmt->closeCursor();
                    $preview['unverified_modules'] = $unverifiedRows;
                }
            }
        }
    