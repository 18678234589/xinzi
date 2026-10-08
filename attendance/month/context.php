<?php

require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';
require_login();
require_once (dirname(__DIR__, 1)) . '/../classes/SimpleXLSX.php';

$page_title = '考勤上传';
$success = '';
$error = '';

ensureAttendanceTable();

$year  = (int)($_REQUEST['year'] ?? 0);
$month = (int)($_REQUEST['month'] ?? 0);
if ($year <= 0 || $month < 1 || $month > 12) {
    header('Location: ' . BASE_URL . '/attendance/index.php');
    exit;
}

// ===== 后端处理 =====
/* split: attendance/month/actions/dispatch.php */ include (dirname(__DIR__, 1)) . '/month/actions/dispatch.php';

// ===== 数据查询 =====
$employees = get_employees();
$records = get_attendances_by_month($year, $month);
$total = count($records);
$totalAbsent = array_sum(array_column($records, 'absent_hours'));
$fullCount = 0;
foreach ($records as $r) if ((float)$r['absent_hours'] == 0) $fullCount++;

// 待匹配考勤（上传时合作人员尚未添加的行，合作人员添加后自动补录）
$pendingRows = [];
try {
    $ps = db()->prepare("SELECT * FROM attendance_pending WHERE year=? AND month=? ORDER BY employee_name");
    $ps->execute([$year, $month]);
    $pendingRows = $ps->fetchAll();
} catch (\Throwable $e) {}
$pendingCount = count($pendingRows);

define('BASE_PATH', dirname((dirname(__DIR__, 1))));
