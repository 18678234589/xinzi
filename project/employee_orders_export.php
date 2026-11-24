<?php
require_once __DIR__ . '/../includes/ProjectEmployeeOrderExport.php';
// 导出某人某月的全部订单明细（含未审核），用来和手算表逐行核对：财务可导出任何人，合作人员只能导出自己的。
$actor = ps_require_actor();
$isFinance = ($actor['role'] ?? '') === 'finance';
$employeeId = $isFinance ? (int)($_GET['employee_id'] ?? 0) : (int)($actor['employee_id'] ?? 0); // 合作人员只能导出自己的
if (!$isFinance && $employeeId <= 0) { http_response_code(403); exit('无权限'); }
$month = (string)($_GET['month'] ?? '');
if ($employeeId <= 0 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) { http_response_code(400); exit('参数不正确'); }
try { peo_download($employeeId, $month, (string)($_GET['format'] ?? 'xls') === 'csv' ? 'csv' : 'xls'); } catch (Throwable $e) { http_response_code(400); echo e($e instanceof RuntimeException ? $e->getMessage() : '导出失败'); }
