<?php

require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';
require_once (dirname(__DIR__, 1)) . '/../includes/SalaryCalculator.php';
require_login();

$page_title = '原系统项目报酬结算';
$success = '';
$error = '';
$preview = null;

// 保险扣除配置（全员统一金额，在保险管理页面修改）
$insuranceConfig = @include (dirname(__DIR__, 1)) . '/../config/insurance.php';
$insuranceAmount = (float)($insuranceConfig['amount'] ?? 0);

// 网站售后部独立配置（与通用 dept_fee.php 隔离，避免其他分支修改影响）
$deptConfig = @include (dirname(__DIR__, 1)) . '/../config/dept_config.php';
$deptConfigName = $deptConfig['dept_name'] ?? '';
$deptConfigRate = (float)($deptConfig['service_fee_rate'] ?? 0);
$deptConfigShare = ($deptConfig['dept_share'] ?? true) ? true : false;

// 计算全勤奖（先加后扣模式）
// 规则：请假 ≥8h 全扣、≥4h 扣一半、<4h 不扣；无考勤记录或不启用则净额为0
// 返回 ['base'=>满勤金额, 'deduct'=>扣除, 'net'=>净额, 'status'=>说明, 'has_att'=>是否有考勤]
/* split: salaries/settle/helpers/calcFullAttendanceBonus.php */ require_once (dirname(__DIR__, 1)) . '/settle/helpers/calcFullAttendanceBonus.php';

// 按出勤天数折算固定服务费（分母固定30）
// 规则：请假≤4天 → 固定服务费−固定服务费/30×请假天数；请假>4天 → 固定服务费/30×实际出勤天数
// 两段统一为 固定服务费/30×实际出勤天数；满勤不折；无考勤按满勤发全额
// 返回 ['original'=>原始固定服务费, 'actual_days'=>实际出勤天数, 'leave_days'=>请假天数,
//        'prorated'=>折算后固定服务费, 'status'=>说明, 'has_att'=>是否有考勤]
/* split: salaries/settle/helpers/calcProratedBaseSalary.php */ require_once (dirname(__DIR__, 1)) . '/settle/helpers/calcProratedBaseSalary.php';

// 在 $result 上应用固定服务费折算（合作人员表固定服务费与固定服务费类模块——阶梯固定服务费/客服绩效固定服务费——各自独立折算）
// 直接修改 $result，返回 base_info 数组
/* split: salaries/settle/helpers/applyProratedBaseSalary.php */ require_once (dirname(__DIR__, 1)) . '/settle/helpers/applyProratedBaseSalary.php';

/**
 * 加载合作人员当月订单（含部门订单虚拟拆分）
 * 1. 查该合作人员当月个人订单（排除旧的物理拆分行 __from_dept__）
 * 2. 查该合作人员所在部门的汇总行（employee_id=0, __dept__=部门名），按 __dept_modules__ 虚拟生成拆分行
 * 3. 合并返回订单列表和总金额
 *
 * @param int    $employeeId
 * @param string $month     'YYYY-MM'
 * @param string $deptName  合作人员所属部门名
 * @param bool   $deptShare 是否参与部门订单项目分成（false 则跳过虚拟拆分）
 * @return array ['orders'=>[], 'order_total'=>float]
 */
/* split: salaries/settle/helpers/loadEmployeeOrdersWithDept.php */ require_once (dirname(__DIR__, 1)) . '/settle/helpers/loadEmployeeOrdersWithDept.php';

// 处理结算
/* split: salaries/settle/actions/dispatch.php */ include (dirname(__DIR__, 1)) . '/settle/actions/dispatch.php';

$departments = get_departments();
$employees   = get_employees();

// 检查某合作人员某月是否已结算
$existing = null;
if ($preview) {
    $stmt = db()->prepare("SELECT * FROM salaries WHERE employee_id = ? AND month = ?");
    $stmt->execute([$preview['employee']['id'], $preview['month']]);
    $existing = $stmt->fetch();
}

define('BASE_PATH', dirname((dirname(__DIR__, 1))));
