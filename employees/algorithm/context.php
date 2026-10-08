<?php

require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';
require_once (dirname(__DIR__, 1)) . '/../includes/SalaryCalculator.php';
require_login();

$employee_id = (int)($_GET['employee_id'] ?? 0);
$employee = get_employee($employee_id);
if (!$employee) { header('Location: ' . BASE_URL . '/employees/index.php'); exit; }

$page_title = '项目报酬算法设置';
$success = '';
$error = '';

// 处理保存（AJAX或POST）
/* split: employees/algorithm/actions/dispatch.php */ include (dirname(__DIR__, 1)) . '/algorithm/actions/dispatch.php';

// 读取当前配置
$savedConfig  = SalaryCalculator::readModulesConfig($employee_id);
$isCodeMode  = !SalaryCalculator::hasCustomConfig($employee_id) && SalaryCalculator::hasCustomAlgorithm($employee_id);
$allTypes    = SalaryCalculator::getAvailableTypes();
$currentMods = $savedConfig['modules'] ?? [];

// 查询该合作人员所属部门的手续费率（优先 dept_config.php 独立配置，回退 dept_fee.php）
$deptFeeRate = 0;
$deptConfigFile = (dirname(__DIR__, 1)) . '/../config/dept_config.php';
if (file_exists($deptConfigFile) && !empty($employee['department'])) {
    $dc = include $deptConfigFile;
    if (is_array($dc) && $dc['dept_name'] === $employee['department'] && isset($dc['service_fee_rate'])) {
        $deptFeeRate = (float)$dc['service_fee_rate'];
    }
}
if ($deptFeeRate === 0) {
    $deptFeeFile = (dirname(__DIR__, 1)) . '/../config/dept_fee.php';
    if (file_exists($deptFeeFile) && !empty($employee['department'])) {
        $deptFeeMap = include $deptFeeFile;
        if (is_array($deptFeeMap) && isset($deptFeeMap[$employee['department']])) {
            $deptFeeRate = (float)$deptFeeMap[$employee['department']];
        }
    }
}

define('BASE_PATH', dirname((dirname(__DIR__, 1))));
