<?php
require_once __DIR__ . '/../includes/ProjectImportResult.php';
function ir_assert($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
ir_assert(ps_import_numeric_summary(['', '258850', '97356.57', '63916.57', '33440'], '', '', ''), 'numeric totals ignored');
ir_assert(!ps_import_numeric_summary(['', '100'], '', '', ''), 'incomplete real order not dropped');
ir_assert(!ps_import_numeric_summary(['', '100', '80', '20'], 'ORDER-1', '', ''), 'order identity protected');
ir_assert(!ps_import_numeric_summary(['', '100', '80', '20'], '', 'WX-123', ''), 'payment identity protected');
ir_assert(!ps_import_numeric_summary(['2026-09-30', '100', '80', '20'], '', '', '2026-09-30'), 'dated row protected');
ir_assert(!ps_import_numeric_summary(['客户', '100', '80', '20'], '', '', ''), 'text row preserved');
$source = file_get_contents(__DIR__ . '/../project/index.php');
ir_assert(strpos($source, 'DESC LIMIT 500') === false, 'no 500 order truncation');
ir_assert(strpos($source, '最近录入 / 导入') !== false && strpos($source, 'date_basis') !== false, 'entry date filter');
$import = file_get_contents(__DIR__ . '/../project/import.php');
ir_assert(strpos($import, 'name="auto_import" value="1" checked') !== false, 'valid rows auto-import by default');
ir_assert(strpos($import, 'ps_import_upload_actor') !== false, 'resume uses original uploader');
ir_assert(strpos($import, "$" . "row['domain_mode'] = 'pending'") !== false, 'missing resources do not block order entry');
ir_assert(strpos($import, 'existing_snapshot') !== false && strpos($import, 'ps_customer_intake_conflicts($existing, $record)') === false, 'same-order additions checked after merge');
ir_assert(strpos($import, 'pd_ensure();') < strpos($import, "exec('SAVEPOINT project_order_import')"), 'lazy duplicate DDL before transaction');
$files = file_get_contents(__DIR__ . '/../project/files.php');
ir_assert(strpos($files, '继续导入') !== false && strpos($files, '对应订单') !== false, 'file recovery and visibility shortcuts');
