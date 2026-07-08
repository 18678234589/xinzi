<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/SalaryCalculator.php';
require_login();

$page_title = '薪资结算';
$success = '';
$error = '';
$preview = null;

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
                // 汇总当月订单（排除异常数据、排除拆分记录）
                $stmt = db()->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(order_amount),0) as total FROM orders WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ? AND COALESCE(is_abnormal,0) = 0 AND (raw_data IS NULL OR raw_data NOT LIKE '%\"__from_dept__%\":%')");
                $stmt->execute([$employee_id, $month]);
                $orderInfo = $stmt->fetch();

                $order_total = (float)$orderInfo['total'];
                // 获取当月订单明细（排除异常，供算法使用）
                $ostmt = db()->prepare("SELECT *, order_amount, order_date, project FROM orders WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ? AND COALESCE(is_abnormal,0) = 0 AND (raw_data IS NULL OR raw_data NOT LIKE '%\"__from_dept__%\":%') ORDER BY order_date");
                $ostmt->execute([$employee_id, $month]);
                $orderList = $ostmt->fetchAll();
                
                if (count($orderList) === 0) {
                    $error = "未找到该员工在 {$month} 的订单记录";
                } else {
                    // 自定义额外金额（正数加、负数减）
                    $extraAmount = (float)($_POST['extra_amount'] ?? 0);

                    // 调用薪资算法（自动选择员工专属算法或默认算法）
                    $result = SalaryCalculator::calculate($emp, $orderList, $order_total, $month);

                    // 将自定义金额叠加到最终结果
                    $result['net_pay'] = round($result['net_pay'] + $extraAmount, 2);
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
                $stmt = db()->prepare("SELECT COALESCE(SUM(order_amount),0) as total FROM orders WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ? AND COALESCE(is_abnormal,0) = 0 AND (raw_data IS NULL OR raw_data NOT LIKE '%\"__from_dept__%\":%')");
                $stmt->execute([$employee_id, $month]);
                $order_total = (float)$stmt->fetchColumn();
                // 获取当月订单明细（排除异常，供算法使用）
                $ostmt = db()->prepare("SELECT *, order_amount, order_date, project FROM orders WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ? AND COALESCE(is_abnormal,0) = 0 AND (raw_data IS NULL OR raw_data NOT LIKE '%\"__from_dept__%\":%') ORDER BY order_date");
                $ostmt->execute([$employee_id, $month]);
                $orderList = $ostmt->fetchAll();

                // 调用薪资算法
                $result = SalaryCalculator::calculate($emp, $orderList, $order_total, $month);
                $extraAmount = (float)($_POST['extra_amount'] ?? 0);
                $commission = $result['module_total'] ?? $result['commission'];
                $net_pay    = round($result['net_pay'] + $extraAmount, 2);
                $commission = round($commission + $extraAmount, 2);

                try {
                    // 确保 salaries 表有 extra_amount 字段
                    $hasExtra = db()->query("SHOW COLUMNS FROM `salaries` LIKE 'extra_amount'")->fetchAll();
                    if (empty($hasExtra)) {
                        db()->exec("ALTER TABLE `salaries` ADD COLUMN `extra_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '自定义额外金额' AFTER `net_pay`");
                    }
                    $stmt = db()->prepare("
                        INSERT INTO salaries (employee_id, month, order_total, commission, net_pay, extra_amount)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            order_total = VALUES(order_total),
                            commission = VALUES(commission),
                            net_pay = VALUES(net_pay),
                            extra_amount = VALUES(extra_amount),
                            created_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$employee_id, $month, $order_total, $commission, $net_pay, $extraAmount]);
                    $success = sprintf('薪资结算成功！%s %s：订单总额 ¥%s，提成 ¥%s，实发 ¥%s（%s）',
                        $emp['name'], $month, money($order_total), money($commission), money($net_pay), $result['algorithm_name']);

                    $preview = [
                        'employee'       => $emp,
                        'month'          => $month,
                        'order_count'    => 0,
                        'order_total'    => $order_total,
                        'commission'     => $commission,
                        'net_pay'        => $net_pay,
                        'extra_amount'   => $extraAmount,
                        'modules'        => $result['modules'] ?? [],
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
                        <select name="department" id="deptSel" class="form-control" onchange="loadEmp()">
                            <option value="">-- 选择部门 --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo e($d); ?>"><?php echo e($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>选择员工 <span class="required">*</span></label>
                        <select name="employee_id" id="empSel" class="form-control" required>
                            <option value="">-- 请先选择部门 --</option>
                        </select>
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
                                <td><strong><?php echo e($m['name']); ?></strong></td>
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
                        <tr class="table-success">
                            <th colspan="3" class="text-right h5 mb-0">底薪 + 模块合计 <?php if (abs((float)($preview['extra_amount'] ?? 0)) > 0.001) echo '+ 自定义'; ?> → 实发工资</th>
                            <td class="h4 mb-0 text-success font-weight-bold">¥<?php echo money($preview['net_pay']); ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="text-muted small mb-3 mt-2 p-2 bg-light rounded border">
                    <i class="fas fa-calculator"></i>
                    <strong>计算明细：</strong>
                    <?php 
                    $baseSalaryAmount = (float)$emp['base_salary'];
                    $parts = [];
                    if ($baseSalaryAmount > 0) {
                        $parts[] = '底薪 ¥' . money($baseSalaryAmount);
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

function loadEmp() {
    var dept = $('#deptSel').val();
    var $sel = $('#empSel');
    $sel.empty();
    if (!dept) {
        $sel.append('<option value="">-- 请先选择部门 --</option>');
        return;
    }
    $sel.append('<option value="">-- 选择员工 --</option>');
    allEmployees.forEach(function(emp) {
        if (emp.department === dept) {
            $sel.append('<option value="' + emp.id + '">' + emp.name + '</option>');
        }
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
