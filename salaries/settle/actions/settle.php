<?php

        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $month       = $_POST['month'] ?? '';

        if ($employee_id <= 0 || $month === '') {
            $error = '参数错误';
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

                // 调用项目报酬算法
                $result = SalaryCalculator::calculate($emp, $orderList, $order_total, $month);

                // 自定义额外金额（多项，每项含金额+备注，求和为总额）
                $extraItems = [];
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

                // 全勤奖（先加后扣：自动抓取考勤）
                $bonusBase = (float)($_POST['full_attendance_bonus'] ?? 200);
                $bonus = calcFullAttendanceBonus($emp['id'], $month, $bonusBase);
                $bonusNet = (float)$bonus['net'];

                // 固定服务费按出勤天数折算（直接修改 $result，处理阶梯固定服务费/客服绩效固定服务费等固定服务费类模块）
                $baseInfo = applyProratedBaseSalary($result, $emp['id'], $month);

                $commission = $result['module_total'] ?? $result['commission'];
                $net_pay    = round($result['net_pay'] + $extraAmount + $bonusNet, 2);
                $commission = round($commission + $extraAmount + $bonusNet, 2);

                // 保险扣除（勾选时扣除，默认勾选）
                $deductInsurance = isset($_POST['deduct_insurance']);
                $insuranceDeduct = ($deductInsurance && $insuranceAmount > 0) ? $insuranceAmount : 0;
                if ($insuranceDeduct > 0) {
                    $net_pay    = round($net_pay - $insuranceDeduct, 2);
                    $commission = round($commission - $insuranceDeduct, 2);
                }

                try {
                    // 确保 salaries 表有 extra_amount / full_attendance_bonus 字段
                    $hasExtra = db()->query("SHOW COLUMNS FROM `salaries` LIKE 'extra_amount'")->fetchAll();
                    if (empty($hasExtra)) {
                        db()->exec("ALTER TABLE `salaries` ADD COLUMN `extra_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '自定义额外金额' AFTER `net_pay`");
                    }
                    $hasBonus = db()->query("SHOW COLUMNS FROM `salaries` LIKE 'full_attendance_bonus'")->fetchAll();
                    if (empty($hasBonus)) {
                        db()->exec("ALTER TABLE `salaries` ADD COLUMN `full_attendance_bonus` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '全勤奖净额' AFTER `extra_amount`");
                    }
                    $hasBaseAmt = db()->query("SHOW COLUMNS FROM `salaries` LIKE 'base_salary_amount'")->fetchAll();
                    if (empty($hasBaseAmt)) {
                        db()->exec("ALTER TABLE `salaries` ADD COLUMN `base_salary_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '折算后固定服务费' AFTER `full_attendance_bonus`"
    );
                    }
                    $hasIns = db()->query("SHOW COLUMNS FROM `salaries` LIKE 'insurance_amount'")->fetchAll();
                    if (empty($hasIns)) {
                        db()->exec("ALTER TABLE `salaries` ADD COLUMN `insurance_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '保险扣除金额' AFTER `base_salary_amount`"
    );
                    }
                    $stmt = db()->prepare("
                        INSERT INTO salaries (employee_id, month, order_total, commission, net_pay, extra_amount, full_attendance_bonus, base_salary_amount, insurance_amount)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            order_total = VALUES(order_total),
                            commission = VALUES(commission),
                            net_pay = VALUES(net_pay),
                            extra_amount = VALUES(extra_amount),
                            full_attendance_bonus = VALUES(full_attendance_bonus),
                            base_salary_amount = VALUES(base_salary_amount),
                            insurance_amount = VALUES(insurance_amount),
                            created_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$employee_id, $month, $order_total, $commission, $net_pay, $extraAmount, $bonusNet, $baseInfo['prorated'], $insuranceDeduct]);
                    $success = sprintf('项目结算成功！%s %s：订单总额 ¥%s，原系统绩效等净额 ¥%s，应结算金额 ¥%s（%s）',
                        $emp['name'], $month, money($order_total), money($commission), money($net_pay), $result['algorithm_name']);

                    // 追加全勤奖模块到模块列表（便于结算后预览展示）
                    $settleModules = $result['modules'] ?? [];
                    // 逐项追加自定义额外金额模块
                    foreach ($extraItems as $ei) {
                        if ($ei['amount'] != 0) {
                            $settleModules[] = [
                                'name'   => $ei['remark'] !== '' ? $ei['remark'] : '自定义额外金额',
                                'amount' => round($ei['amount'], 2),
                                'formula'=> $ei['remark'] !== '' ? sprintf('手动调整 %+.2f（%s）', $ei['amount'], $ei['remark']) : sprintf('手动调整 %+.2f', $ei['amount']
    ),
                                'type'   => 'extra_amount',
                            ];
                        }
                    }
                    if ($bonusNet != 0) {
                        $settleModules[] = [
                            'name'   => '全勤奖',
                            'amount' => round($bonus['base'], 2),
                            'formula'=> $bonus['status'],
                            'type'   => 'attendance_full',
                        ];
                        if ($bonus['deduct'] > 0) {
                            $settleModules[] = [
                                'name'   => '全勤扣除',
                                'amount' => -round($bonus['deduct'], 2),
                                'formula'=> sprintf('请假扣减 -%.2f', $bonus['deduct']),
                                'type'   => 'attendance_deduct',
                            ];
                        }
                    }
                    if ($insuranceDeduct > 0) {
                        $settleModules[] = [
                            'name'   => '保险扣除',
                            'amount' => -round($insuranceDeduct, 2),
                            'formula'=> sprintf('保险扣除 -%.2f', $insuranceDeduct),
                            'type'   => 'insurance',
                        ];
                    }

                    $preview = [
                        'employee'       => $emp,
                        'month'          => $month,
                        'order_count'    => 0,
                        'order_total'    => $order_total,
                        'commission'     => $commission,
                        'net_pay'        => $net_pay,
                        'extra_amount'   => $extraAmount,
                        'extra_items'    => $extraItems,
                        'full_attendance_bonus' => $bonusBase,
                        'bonus_info'     => $bonus,
                        'base_info'      => $baseInfo,
                        'modules'        => $settleModules,
                        'module_total'   => $result['module_total'] ?? $commission,
                        'base_salary'    => $result['base_salary'] ?? (float)$emp['base_salary'],
                        'formula_text'   => $result['formula_text'],
                        'algorithm_name' => $result['algorithm_name'],
                        'is_custom'      => $result['is_custom'],
                        'insurance_amount' => $insuranceDeduct,
                    ];
                    $cstmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ?");
                    $cstmt->execute([$employee_id, $month]);
                    $preview['order_count'] = $cstmt->fetchColumn();
                } catch (PDOException $ex) {
                    $error = '结算失败: ' . $ex->getMessage();
                }
            }
        }
    