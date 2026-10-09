<?php
// 补齐状态：以原上传人身份回放已上传的表格，找出“表里写已完成 / 到账、系统里订单还是未完成”的订单（状态只前进，已审核 / 锁定订单不动）。
// 用法：php tools/import_status_catchup.php 文件ID,... [--apply]；不带 --apply 只列出（预览回放全部回滚）；--apply 才把这些订单更新为已完成，并记审计 + 备份 JSON。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSystem.php';
$GLOBALS['project_import_cli'] = true;
$ids = array_values(array_filter(array_map('intval', explode(',', $argv[1] ?? ''))));
$apply = in_array('--apply', $argv, true);
if (!$ids) { fwrite(STDERR, "用法：php tools/import_status_catchup.php 文件ID,... [--apply]
"); exit(2); }
/** 在独立作用域里回放预览（导入页的分片与本脚本共用变量名，必须隔离），事务内全部回滚。 */
function ir_preview($fid, $f)
{
    $pdo = db(); $rows = []; $error = '';
    $pdo->beginTransaction();
    try {
        ps_setting_set('ai', ['enabled' => false] + (array)ps_setting_get('ai', []), null);
        $u = $pdo->prepare('SELECT id,employee_id,role FROM project_users WHERE id=?'); $u->execute([(int)$f['uploaded_by_id']]); $u = $u->fetch();
        $actor = ['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role']];
        $_SESSION = ['project_user_id' => $actor['id'], 'project_csrf' => 'x'];
        $scope = ps_department_import_allowed($actor, $f['business_name']) && $actor['role'] !== 'finance' ? 'department' : 'personal';
        $_SERVER['SCRIPT_NAME'] = '/project/import.php'; $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = [];
        $_POST = ['csrf' => 'x', 'action' => 'repreview', 'business' => $f['business_name'], 'scope' => $scope, 'file_id' => $fid, 'all_sheets' => 1];
        $error = ''; ob_start(); include __DIR__ . '/../project/import.php'; ob_end_clean();
        $rows = $_SESSION['project_import_preview'] ?? [];
    } catch (Throwable $e) { $error = $e->getMessage(); }
    finally { while (ob_get_level() > 0) ob_end_clean(); if ($pdo->inTransaction()) $pdo->rollBack(); ps_setting_get('ai', null, true); }
    return [$rows, $error];
}

$pdo = db();
$actor = ['type' => 'system', 'id' => 0, 'role' => 'finance'];
$orderQ = $pdo->prepare('SELECT id,order_no,delivery_status,settlement_status,project_type FROM project_orders WHERE id=?');
$changed = []; $total = 0;
foreach ($ids as $fid) {
    $q = $pdo->prepare('SELECT f.*,e.name emp FROM project_import_files f LEFT JOIN employees e ON e.id=f.employee_id WHERE f.id=?'); $q->execute([$fid]); $f = $q->fetch();
    if (!$f) { echo "#$fid 不存在
"; continue; }
    [$rows, $error] = ir_preview($fid, $f);
    $hit = [];
    foreach ($rows as $r) {
        if (empty($r['base_valid']) || empty($r['existing_order_id']) || ($r['delivery_status'] ?? '') !== 'finished') continue;
        $orderQ->execute([(int)$r['existing_order_id']]); $o = $orderQ->fetch();
        if (!$o || $o['delivery_status'] !== 'unfinished' || in_array($o['settlement_status'], ['approved', 'locked'], true)) continue;
        $hit[$o['id']] = $o;
    }
    printf("#%d %s｜%s｜%s：表里已完成但系统未完成 %d 单%s
", $fid, $f['emp'], $f['business_name'], mb_substr($f['original_name'], 0, 20), count($hit), $error !== '' ? '｜回放出错：' . mb_substr($error, 0, 50) : '');
    foreach (array_slice($hit, 0, 6) as $o) echo "      ", mb_substr($o['order_no'], 0, 30), "
";
    foreach ($hit as $o) $changed[$o['id']] = $o + ['file_id' => $fid];
    $total += count($hit);
}
echo "
合计 ", count($changed), " 张订单（去重）
";
if ($apply && $changed) {
    $update = $pdo->prepare("UPDATE project_orders SET delivery_status='finished',row_version=row_version+1 WHERE id=? AND delivery_status='unfinished' AND settlement_status NOT IN ('approved','locked')");
    $n = 0;
    foreach ($changed as $o) { $update->execute([(int)$o['id']]); if ($update->rowCount()) { $n++; ps_audit('order', (int)$o['id'], 'import_delivery_finished', $actor, ['file_id' => $o['file_id'], 'from' => 'unfinished', 'to' => 'finished', 'tool' => 'import_status_catchup']); } }
    $dir = __DIR__ . '/../.deploy'; if (!is_dir($dir) || !is_writable($dir)) $dir = sys_get_temp_dir();
    $file = $dir . '/status_catchup_' . date('Ymd_His') . '.json';
    file_put_contents($file, json_encode(array_values($changed), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "已更新 $n 张为已完成；还原清单：$file
";
} elseif ($apply) echo "没有需要更新的订单
";
else echo "（只读预览；加 --apply 才会更新）
";
