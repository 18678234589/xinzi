<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/SalaryCalculator.php';
require_login();

$page_title = '薪资结算';
$success = '';
$error = '';
$preview = null;

// 计算全勤奖（先加后扣模式）
// 规则：请假 ≥8h 全扣、≥4h 扣一半、<4h 不扣；无考勤记录或不启用则净额为0
// 返回 ['base'=>满勤金额, 'deduct'=>扣除, 'net'=>净额, 'status'=>说明, 'has_att'=>是否有考勤]
function calcFullAttendanceBonus($empId, $month, $bonusBase) {
    $attInfo = null;
    $mp = explode('-', (string)$month);
    if (count($mp) === 2) {
        $attInfo = get_attendance((int)$empId, (int)$mp[0], (int)$mp[1]);
    }
    if (!$attInfo || $bonusBase <= 0) {
        return ['base' => 0, 'deduct' => 0, 'net' => 0,
                'status' => $attInfo ? '未启用全勤奖' : '未录入考勤，不发全勤奖',
                'has_att' => (bool)$attInfo];
    }
    $absent = (float)$attInfo['absent_hours'];
    if ($absent >= 8) {
        $deduct = $bonusBase;            // 全扣
    } elseif ($absent >= 4) {
        $deduct = $bonusBase / 2;         // 扣一半
    } else {
        $deduct = 0;                      // 不扣
    }
    if ($absent >= 8) {
        $status = '请假≥8h，全勤奖全部扣除';
    } elseif ($absent >= 4) {
        $status = '请假≥4h，扣除全勤奖一半';
    } elseif ($absent > 0) {
        $status = '请假<4h，全勤奖不扣';
    } else {
        $status = '满勤，全勤奖全额发放';
    }
    return ['base' => $bonusBase, 'deduct' => $deduct,
            'net' => $bonusBase - $deduct, 'status' => $status, 'has_att' => true];
}

// 按出勤天数折算底薪（分母固定30）
// 规则：请假≤4天 → 底薪−底薪/30×请假天数；请假>4天 → 底薪/30×实际出勤天数
// 两段统一为 底薪/30×实际出勤天数；满勤不折；无考勤按满勤发全额
// 返回 ['original'=>原始底薪, 'actual_days'=>实际出勤天数, 'leave_days'=>请假天数,
//        'prorated'=>折算后底薪, 'status'=>说明, 'has_att'=>是否有考勤]
function calcProratedBaseSalary($empId, $month, $baseSalary) {
    $attInfo = null;
    $mp = explode('-', (string)$month);
    if (count($mp) === 2) {
        $attInfo = get_attendance((int)$empId, (int)$mp[0], (int)$mp[1]);
    }
    if (!$attInfo) {
        return ['original' => $baseSalary, 'actual_days' => 0, 'leave_days' => 0,
                'prorated' => $baseSalary, 'status' => '未录入考勤，底薪按满勤发放', 'has_att' => false];
    }
    $workH   = (float)$attInfo['work_hours'];
    $absentH = (float)$attInfo['absent_hours'];
    $leaveDays  = $absentH / 8;                    // 请假天数
    $actualDays = max(0, ($workH - $absentH) / 8); // 实际出勤天数（满勤天数−请假天数）
    if ($leaveDays <= 0) {
        $prorated = $baseSalary;                   // 满勤不折
        $status   = '满勤，底薪全额发放';
    } elseif ($leaveDays <= 4) {
        // 请假≤4天：底薪 − 底薪/30 × 请假天数（基数用30，与满勤天数无关）
        $prorated = round($baseSalary - $baseSalary / 30 * $leaveDays, 2);
        $actualDays = 30 - $leaveDays;             // 显示用：30−请假天数
        $status = sprintf('请假%.1f天(≤4天)，底薪−底薪/30×请假天数', $leaveDays);
    } else {
        // 请假>4天：底薪/30 × 实际出勤天数（实际出勤=满勤天数−请假天数）
        $prorated = round($baseSalary / 30 * $actualDays, 2);
        $status = sprintf('请假%.1f天(>4天)，底薪/30×实际出勤%.1f天', $leaveDays, $actualDays);
    }
    return ['original' => $baseSalary, 'actual_days' => $actualDays, 'leave_days' => $leaveDays,
            'prorated' => $prorated, 'status' => $status, 'has_att' => true];
}

// 在 $result 上应用底薪折算（处理 base_salary 字段与 base_salary_tiered 模块两种来源）
// 直接修改 $result，返回 base_info 数组
function applyProratedBaseSalary(&$result, $empId, $month) {
    $rawBase = (float)($result['base_salary'] ?? 0);
    // 检测阶梯底薪模块（其金额在 modules 里，不在 base_salary 字段）
    $baseModIdx = -1;
    $baseModAmount = 0;
    foreach (($result['modules'] ?? []) as $mi => $mod) {
        if (($mod['type'] ?? '') === 'base_salary_tiered') {
            $baseModIdx = $mi;
            $baseModAmount = (float)$mod['amount'];
            break;
        }
    }
    $effectiveBase = $rawBase + $baseModAmount;
    $baseInfo = calcProratedBaseSalary($empId, $month, $effectiveBase);

    if ($baseModIdx >= 0) {
        // 阶梯底薪：折算模块金额，并重算 module_total / net_pay
        $result['modules'][$baseModIdx]['amount']  = round($baseInfo['prorated'], 2);
        $result['modules'][$baseModIdx]['formula'] = $baseInfo['status'] . '（原 ¥' . number_format($baseModAmount, 2) . '）';
        $result['module_total'] = round(array_sum(array_column($result['modules'], 'amount')), 2);
        $result['net_pay']      = round($rawBase + $result['module_total'], 2);
    } else {
        // 自定义底薪或员工表底薪：折算 base_salary 字段
        $baseDiff = round($baseInfo['prorated'] - $rawBase, 2);
        $result['base_salary'] = $baseInfo['prorated'];
        $result['net_pay']     = round(($result['net_pay'] ?? 0) + $baseDiff, 2);
    }
    return $baseInfo;
}

// 处理结算
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'preview') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $month       = $_POST['month'] ?? '';

        if ($employee_id <= 0 || $month === '') {
            $error = '请选择员工和月份';
        } else {
            $emp = get_employee($employee_id);
            if (!$emp) {
                $error = '员工不存在';
            } else {
                // 汇总当月订单（排除异常数据；含部门拆分记录 __from_dept__，这些是员工提成的来源）
                $stmt = db()->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(order_amount),0) as total FROM orders WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ? AND COALESCE(is_abnormal,0) = 0");
                $stmt->execute([$employee_id, $month]);
                $orderInfo = $stmt->fetch();

                $order_total = (float)$orderInfo['total'];
                // 获取当月订单明细（排除异常，供算法使用；含 __from_dept__ 拆分记录）
                $ostmt = db()->prepare("SELECT *, order_amount, order_date, project FROM orders WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ? AND COALESCE(is_abnormal,0) = 0 ORDER BY order_date");
                $ostmt->execute([$employee_id, $month]);
                $orderList = $ostmt->fetchAll();
                
                if (count($orderList) === 0) {
                    $error = "未找到该员工在 {$month} 的订单记录";
                } else {
                    // 自定义额外金额（正数加、负数减）
                    $extraAmount = (float)($_POST['extra_amount'] ?? 0);

                    // 全勤奖（先加后扣：自动抓取考勤，按请假小时数扣除）
                    $bonusBase = (float)($_POST['full_attendance_bonus'] ?? 200);
                    $bonus = calcFullAttendanceBonus($emp['id'], $month, $bonusBase);

                    // 调用薪资算法（自动选择员工专属算法或默认算法）
                    $result = SalaryCalculator::calculate($emp, $orderList, $order_total, $month);

                    // 底薪按出勤天数折算（分母固定30；处理 base_salary 字段与 base_salary_tiered 模块两种来源）
                    $baseInfo = applyProratedBaseSalary($result, $emp['id'], $month);

                    // 将自定义金额叠加到最终结果
                    $result['net_pay']    = round($result['net_pay'] + $extraAmount, 2);
                    $result['module_total'] = round(($result['module_total'] ?? 0) + $extraAmount, 2);
                    // 在模块列表末尾追加一项，便于明细展示
                    if ($extraAmount != 0) {
                        $result['modules'][] = [
                            'name'   => '自定义额外金额',
                            'amount' => round($extraAmount, 2),
                            'formula'=> sprintf('手动调整 %+.2f', $extraAmount),
                            'type'   => 'extra_amount',
                        ];
                    }

                    // 全勤奖叠加（先加后扣模式：净额累加到实发工资）
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
                        'order_count'    => $orderInfo['cnt'],
                        'order_total'    => $order_total,
                        'commission'     => $result['module_total'] ?? $result['commission'],
                        'net_pay'        => $result['net_pay'],
                        'extra_amount'   => $extraAmount,
                        'full_attendance_bonus' => $bonusBase,
                        'bonus_info'     => $bonus,
                        'base_info'      => $baseInfo,
                        'modules'        => $result['modules'] ?? [],
                        'module_total'   => $result['module_total'] ?? $result['commission'],
                        'base_salary'    => $result['base_salary'] ?? (float)$emp['base_salary'],
                        'formula_text'   => $result['formula_text'],
                        'algorithm_name' => $result['algorithm_name'],
                        'is_custom'      => $result['is_custom'],
                    ];
                }
            }
        }
    } elseif ($action === 'settle') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $month       = $_POST['month'] ?? '';

        if ($employee_id <= 0 || $month === '') {
            $error = '参数错误';
        } else {
            $emp = get_employee($employee_id);
            if (!$emp) {
                $error = '员工不存在';
            } else {
                $stmt = db()->prepare("SELECT COALESCE(SUM(order_amount),0) as total FROM orders WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ? AND COALESCE(is_abnormal,0) = 0");
                $stmt->execute([$employee_id, $month]);
                $order_total = (float)$stmt->fetchColumn();
                // 获取当月订单明细（排除异常，供算法使用；含 __from_dept__ 拆分记录）
                $ostmt = db()->prepare("SELECT *, order_amount, order_date, project FROM orders WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ? AND COALESCE(is_abnormal,0) = 0 ORDER BY order_date");
                $ostmt->execute([$employee_id, $month]);
                $orderList = $ostmt->fetchAll();

                // 调用薪资算法
                $result = SalaryCalculator::calculate($emp, $orderList, $order_total, $month);
                $extraAmount = (float)($_POST['extra_amount'] ?? 0);

                // 全勤奖（先加后扣：自动抓取考勤）
                $bonusBase = (float)($_POST['full_attendance_bonus'] ?? 200);
                $bonus = calcFullAttendanceBonus($emp['id'], $month, $bonusBase);
                $bonusNet = (float)$bonus['net'];

                // 底薪按出勤天数折算（直接修改 $result，处理阶梯底薪模块）
                $baseInfo = applyProratedBaseSalary($result, $emp['id'], $month);

                $commission = $result['module_total'] ?? $result['commission'];
                $net_pay    = round($result['net_pay'] + $extraAmount + $bonusNet, 2);
                $commission = round($commission + $extraAmount + $bonusNet, 2);

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
                        db()->exec("ALTER TABLE `salaries` ADD COLUMN `base_salary_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '折算后底薪' AFTER `full_attendance_bonus`");
                    }
                    $stmt = db()->prepare("
                        INSERT INTO salaries (employee_id, month, order_total, commission, net_pay, extra_amount, full_attendance_bonus, base_salary_amount)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            order_total = VALUES(order_total),
                            commission = VALUES(commission),
                            net_pay = VALUES(net_pay),
                            extra_amount = VALUES(extra_amount),
                            full_attendance_bonus = VALUES(full_attendance_bonus),
                            base_salary_amount = VALUES(base_salary_amount),
                            created_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$employee_id, $month, $order_total, $commission, $net_pay, $extraAmount, $bonusNet, $baseInfo['prorated']]);
                    $success = sprintf('薪资结算成功！%s %s：订单总额 ¥%s，提成 ¥%s，实发 ¥%s（%s）',
                        $emp['name'], $month, money($order_total), money($commission), money($net_pay), $result['algorithm_name']);

                    // 追加全勤奖模块到模块列表（便于结算后预览展示）
                    $settleModules = $result['modules'] ?? [];
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

                    $preview = [
                        'employee'       => $emp,
                        'month'          => $month,
                        'order_count'    => 0,
                        'order_total'    => $order_total,
                        'commission'     => $commission,
                        'net_pay'        => $net_pay,
                        'extra_amount'   => $extraAmount,
                        'full_attendance_bonus' => $bonusBase,
                        'bonus_info'     => $bonus,
                        'base_info'      => $baseInfo,
                        'modules'        => $settleModules,
                        'module_total'   => $result['module_total'] ?? $commission,
                        'base_salary'    => $result['base_salary'] ?? (float)$emp['base_salary'],
                        'formula_text'   => $result['formula_text'],
                        'algorithm_name' => $result['algorithm_name'],
                        'is_custom'      => $result['is_custom'],
                    ];
                    $cstmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ?");
                    $cstmt->execute([$employee_id, $month]);
                    $preview['order_count'] = $cstmt->fetchColumn();
                } catch (PDOException $ex) {
                    $error = '结算失败: ' . $ex->getMessage();
                }
            }
        }
    }
}

$departments = get_departments();
$employees   = get_employees();

// 检查某员工某月是否已结算
$existing = null;
if ($preview) {
    $stmt = db()->prepare("SELECT * FROM salaries WHERE employee_id = ? AND month = ?");
    $stmt->execute([$preview['employee']['id'], $preview['month']]);
    $existing = $stmt->fetch();
}

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-calculator"></i> 薪资结算</h4>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<div class="row">
    <!-- 结算表单 -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-sliders-h text-primary"></i> 选择结算条件</h5></div>
            <div class="card-body">
                <form method="post" id="settleForm">
                    <input type="hidden" name="action" value="preview">
                    <div class="form-group">
                        <label>选择部门</label>
                        <select name="department" id="deptSel" class="form-control">
                            <option value="">-- 全部部门 --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo e($d); ?>"><?php echo e($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>选择员工 <span class="required">*</span></label>
                        <div class="position-relative">
                            <input type="text" id="empSearch" class="form-control" placeholder="输入员工姓名搜索…" autocomplete="off" required>
                            <input type="hidden" name="employee_id" id="empSel" required>
                            <div id="empSuggest" class="list-group" style="display:none;position:absolute;z-index:1060;max-height:260px;overflow-y:auto;width:100%;box-shadow:0 4px 10px rgba(0,0,0,.2)"></div>
                        </div>
                        <small class="text-muted"><i class="fas fa-info-circle"></i> 可先选部门缩小范围；选中员工后部门自动匹配</small>
                    </div>
                    <div class="form-group">
                        <label>选择月份 <span class="required">*</span></label>
                        <input type="month" name="month" class="form-control" value="<?php echo e($preview['month'] ?? date('Y-m')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-hand-holding-usd text-warning"></i> 自定义额外金额</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text">¥</span></div>
                            <input type="number" name="extra_amount" class="form-control" step="0.01" value="<?php echo e($_POST['extra_amount'] ?? '0'); ?>" placeholder="手动调整金额，如 -50 或 100">
                        </div>
                        <small class="text-muted">用于无法通过订单计算的金额，正数加、负数减，结算时累加到实发工资</small>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-award text-success"></i> 全勤奖金额</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text">¥</span></div>
                            <input type="number" name="full_attendance_bonus" class="form-control" step="0.01" value="<?php echo e($_POST['full_attendance_bonus'] ?? ($preview['full_attendance_bonus'] ?? '200')); ?>" placeholder="满勤奖金额，默认200">
                        </div>
                        <small class="text-muted">自动抓取考勤：请假≥4h扣一半，≥8h全扣；无考勤记录不发</small>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-money-bill-wave text-primary"></i> 底薪</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text">¥</span></div>
                            <input type="text" class="form-control" value="<?php echo e($preview['base_info']['original'] ?? ($emp['base_salary'] ?? '')); ?>" readonly>
                        </div>
                        <small class="text-muted">自动抓取员工底薪（自定义底薪或阶梯底薪），按出勤天数折算：底薪/30×实际出勤天数</small>
                    </div>
                    <button type="submit" class="btn btn-info btn-block"><i class="fas fa-eye"></i> 预览计算</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 结算预览 -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-receipt text-success"></i> 薪资结算单</h5></div>
            <div class="card-body">
                <?php if ($preview): ?>
                <?php
                    $emp   = $preview['employee'];
                    $mods  = $preview['modules'] ?? [];
                ?>

                <table class="table table-bordered mb-2">
                    <tbody class="bg-white">
                        <tr><th colspan="4" class="py-2 bg-primary text-white"><i class="fas fa-file-invoice mr-1"></i> 基础信息</th></tr>
                        <tr><th width="20%">员工</th><td><?php echo e($emp['name']); ?></td><th width="20%">部门</th><td><?php echo e($emp['department']); ?></td></tr>
                        <tr><th>结算月份</th><td><?php echo e($preview['month']); ?></td><th>当月订单数</th><td><?php echo $preview['order_count']; ?> 笔</td></tr>
                        <tr><th>订单总额</th><td class="text-primary font-weight-bold" colspan="3">¥<?php echo money($preview['order_total']); ?></td></tr>
                        <?php
                        // 读取当月考勤
                        $attInfo = null;
                        $mp = explode('-', (string)$preview['month']);
                        if (count($mp) === 2) {
                            $attInfo = get_attendance((int)$emp['id'], (int)$mp[0], (int)$mp[1]);
                        }
                        $attAbsent = $attInfo ? (float)$attInfo['absent_hours'] : 0;
                        $attWork   = $attInfo ? (float)$attInfo['work_hours'] : 0;
                        $bInfo = $preview['bonus_info'] ?? null;
                        ?>
                        <tr>
                            <th>考勤（应出勤/请假）</th>
                            <td colspan="3">
                                <?php if ($attInfo): ?>
                                    <span class="text-muted">应出勤 <b><?php echo number_format($attWork, 1); ?>h</b></span>
                                    <span class="ml-3 <?php echo $attAbsent > 0 ? 'text-warning' : 'text-success'; ?>">
                                        请假 <b><?php echo number_format($attAbsent, 1); ?>h</b>
                                    </span>
                                    <?php if ($attAbsent >= 8): ?>
                                        <span class="badge badge-danger ml-2">全勤奖全部扣除</span>
                                    <?php elseif ($attAbsent >= 4): ?>
                                        <span class="badge badge-warning ml-2">全勤奖扣除一半</span>
                                    <?php elseif ($attAbsent > 0): ?>
                                        <span class="badge badge-info ml-2">全勤奖不扣</span>
                                    <?php else: ?>
                                        <span class="badge badge-success ml-2">满勤</span>
                                    <?php endif; ?>
                                    <?php if ($bInfo && $bInfo['base'] > 0): ?>
                                        <span class="ml-3 text-success">
                                            全勤奖 ¥<?php echo money($bInfo['base']); ?>
                                            <?php if ($bInfo['deduct'] > 0): ?> − 扣除 ¥<?php echo money($bInfo['deduct']); ?><?php endif; ?>
                                            = <b>净 ¥<?php echo money($bInfo['net']); ?></b>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted"><i class="fas fa-info-circle"></i> 未录入考勤，不发全勤奖</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php $bi = $preview['base_info'] ?? null; if ($bi): ?>
                        <tr>
                            <th>底薪（按出勤折算）</th>
                            <td colspan="3">
                                <span class="text-muted">原底薪 <b>¥<?php echo money($bi['original']); ?></b></span>
                                <?php if ($bi['has_att']): ?>
                                    <span class="ml-3 text-muted">实际出勤 <b><?php echo number_format($bi['actual_days'], 1); ?>天</b></span>
                                    <span class="ml-3 text-muted">请假 <b><?php echo number_format($bi['leave_days'], 1); ?>天</b></span>
                                <?php endif; ?>
                                <span class="ml-3 text-primary font-weight-bold">折算后 ¥<?php echo money($bi['prorated']); ?></span>
                                <small class="text-muted d-block"><?php echo e($bi['status']); ?></small>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    
                    <!-- DEBUG 调试信息 -->
                    <?php if (isset($debug_info)): ?>
                    <tbody>
                        <tr class="bg-info text-white"><th colspan="4" class="py-1">🔍 调试信息</th></tr>
                        <tr><td colspan="4" style="font-family:monospace;font-size:12px;white-space:pre-wrap;max-height:300px;overflow-y:auto;"><?php echo htmlspecialchars($debug_info); ?></td></tr>
                    </tbody>
                    <?php endif; ?>

                    <?php if (!empty($mods)): ?>
                    <tbody>
                        <tr class="bg-light"><th colspan="4" class="py-1 text-center"><strong><i class="fas fa-layer-group mr-1"></i>薪资模块明细（共 <?php echo count($mods); ?> 个模块，合计 ¥<?php echo money($preview['module_total']); ?>）</strong></th></tr>
                        <tr class="table-secondary"><th>#</th><th>模块名称</th><th>类型</th><th class="text-right">金额</th></tr>
                        <?php foreach ($mods as $mi => $m):
                            $cls = $m['amount'] >= 0 ? 'text-success' : 'text-danger';
                        ?>
                            <tr class="<?php echo $cls; ?>">
                                <td class="text-muted"><?php echo $mi + 1; ?></td>
                                <td><strong><?php echo e($m['name']); ?></strong>
                                    <?php if (!empty($m['formula'])): ?>
                                        <div class="text-muted" style="font-size:11px;line-height:1.2;"><?php echo e($m['formula']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-<?php
                                    $typeColors = ['standard'=>'primary','tiered'=>'warning','per_order'=>'info','attendance_full'=>'success','attendance_daily'=>'teal','attendance_deduct'=>'danger'];
                                    echo $typeColors[$m['type']] ?? 'secondary';
                                ?>"><?php
                                    $typeNames = ['standard'=>'标准比例','tiered'=>'阶梯','per_order'=>'每笔奖励','attendance_full'=>'全勤奖','attendance_daily'=>'考勤日薪','attendance_deduct'=>'缺勤扣款'];
                                    echo $typeNames[$m['type']] ?? $m['type'];
                                ?></span></td>
                                <td class="font-weight-bold"><?php echo $m['amount'] >= 0 ? '+' : ''; ?>¥<?php echo money(abs($m['amount'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php endif; ?>

                    <tbody>
                        <?php if (abs((float)($preview['extra_amount'] ?? 0)) > 0.001): ?>
                        <tr class="table-warning">
                            <th colspan="3" class="text-right h6 mb-0"><i class="fas fa-hand-holding-usd text-warning"></i> 自定义额外金额</th>
                            <td class="h6 mb-0 <?php echo $preview['extra_amount'] >= 0 ? 'text-success' : 'text-danger'; ?> font-weight-bold"><?php echo $preview['extra_amount'] >= 0 ? '+' : ''; ?>¥<?php echo money($preview['extra_amount']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($preview['bonus_info']) && $preview['bonus_info']['base'] > 0): ?>
                        <tr class="table-info">
                            <th colspan="3" class="text-right h6 mb-0"><i class="fas fa-award text-success"></i> 全勤奖（<?php echo e($preview['bonus_info']['status']); ?>）</th>
                            <td class="h6 mb-0 text-success font-weight-bold">+¥<?php echo money($preview['bonus_info']['net']); ?>
                                <?php if ($preview['bonus_info']['deduct'] > 0): ?>
                                    <small class="text-danger d-block">满勤¥<?php echo money($preview['bonus_info']['base']); ?> − 扣除¥<?php echo money($preview['bonus_info']['deduct']); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($preview['base_info']) && abs($preview['base_info']['prorated'] - $preview['base_info']['original']) > 0.001): ?>
                        <tr class="table-light">
                            <th colspan="3" class="text-right h6 mb-0"><i class="fas fa-money-bill-wave text-primary"></i> 底薪折算（<?php echo e($preview['base_info']['status']); ?>）</th>
                            <td class="h6 mb-0 text-primary font-weight-bold">¥<?php echo money($preview['base_info']['prorated']); ?>
                                <small class="text-muted d-block">原 ¥<?php echo money($preview['base_info']['original']); ?></small>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr class="table-success">
                            <th colspan="3" class="text-right h5 mb-0">底薪 + 模块合计 <?php if (abs((float)($preview['extra_amount'] ?? 0)) > 0.001) echo '+ 自定义'; ?> <?php if (!empty($preview['bonus_info']) && $preview['bonus_info']['base'] > 0) echo '+ 全勤奖'; ?> → 实发工资</th>
                            <td class="h4 mb-0 text-success font-weight-bold">¥<?php echo money($preview['net_pay']); ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="text-muted small mb-3 mt-2 p-2 bg-light rounded border">
                    <i class="fas fa-calculator"></i>
                    <strong>计算明细：</strong>
                    <?php 
                    $baseSalaryAmount = (float)($preview['base_salary'] ?? $emp['base_salary']);
                    $parts = [];
                    if ($baseSalaryAmount > 0) {
                        $bi = $preview['base_info'] ?? null;
                        if ($bi && abs($bi['prorated'] - $bi['original']) > 0.001) {
                            $parts[] = '底薪 ¥' . money($baseSalaryAmount) . '（按出勤折算）';
                        } else {
                            $parts[] = '底薪 ¥' . money($baseSalaryAmount);
                        }
                    }
                    foreach ($mods as $m) {
                        $parts[] = ($m['amount']>=0?'':'') . money(abs($m['amount'])) . '(' . e($m['name']) . ')';
                    }
                    echo implode(' + ', $parts);
                    ?>
                    = <strong>¥<?php echo money($preview['net_pay']); ?></strong>
                </div>
                <?php if ($existing): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 该员工 <?php echo e($preview['month']); ?> 月薪资已结算过（创建于 <?php echo $existing['created_at']; ?>），再次生成将覆盖原记录。
                    </div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="settle">
                    <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                    <input type="hidden" name="month" value="<?php echo e($preview['month']); ?>">
                    <input type="hidden" name="extra_amount" value="<?php echo e($preview['extra_amount'] ?? 0); ?>">
                    <input type="hidden" name="full_attendance_bonus" value="<?php echo e($preview['full_attendance_bonus'] ?? 200); ?>">
                    <button type="submit" class="btn btn-success btn-lg btn-block"
                        onclick="return confirm('确认生成<?php echo e($emp['name']); ?> <?php echo e($preview['month']); ?>月的薪资记录？<?php echo $existing ? '将覆盖已有记录。' : ''; ?>')">
                        <i class="fas fa-check-double"></i> 确认生成薪资记录
                    </button>
                </form>
                <?php else: ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-calculator fa-4x mb-3"></i>
                        <p>请在左侧选择部门、员工和月份，点击"预览计算"查看薪资详情</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
var allEmployees = <?php echo json_encode($employees, JSON_UNESCAPED_UNICODE); ?>;

$(function() {
    var $dept = $('#deptSel');
    var $input = $('#empSearch');
    var $hidden = $('#empSel');
    var $drop = $('#empSuggest');

    function showSuggestions(q) {
        q = (q || '').trim().toLowerCase();
        var dept = $dept.val();
        var matches = allEmployees.filter(function(emp) {
            if (dept && emp.department !== dept) return false;
            if (!q) return true;
            return emp.name.toLowerCase().indexOf(q) !== -1 || String(emp.id) === q;
        });
        $drop.empty();
        if (matches.length === 0) {
            $drop.append('<div class="list-group-item list-group-item-action text-muted small py-2">无匹配员工</div>');
        } else {
            matches.forEach(function(emp) {
                $drop.append(
                    '<button type="button" class="list-group-item list-group-item-action py-2 px-3" data-id="' + emp.id + '" data-name="' + emp.name.replace(/"/g, '&quot;') + '" data-dept="' + (emp.department || '').replace(/"/g, '&quot;') + '">' +
                    '<span>' + emp.name + '</span>' +
                    '<span class="text-muted ml-2 small">' + (emp.department || '') + '</span>' +
                    '</button>'
                );
            });
        }
        $drop.show();
    }

    // 焦点：展示全部（按部门过滤）
    $input.on('focus', function() { showSuggestions($input.val()); });

    // 输入变化：清空已选值并重新过滤
    $input.on('input', function() {
        $hidden.val('');
        showSuggestions($input.val());
    });

    // 点击建议项：选中员工，自动回填部门
    $drop.on('click', 'button[data-id]', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var dept = $(this).data('dept');
        $hidden.val(id);
        $input.val(name);
        $drop.hide();
        // 自动匹配部门
        if (dept && $dept.val() !== dept) {
            $dept.val(dept);
        }
    });

    // 失焦延迟关闭
    $input.on('blur', function() { setTimeout(function() { $drop.hide(); }, 200); });

    // 切换部门：清空已选员工，便于按新部门重新搜索
    $dept.on('change', function() {
        $hidden.val('');
        $input.val('');
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
