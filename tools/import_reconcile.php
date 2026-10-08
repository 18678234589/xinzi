<?php
// 只读核对：以原上传人身份回放已上传的原始表格（事务内全部回滚），再逐订单号核对系统：
//   OK 订单已在系统且上传人已关联、金额一致；ABSENT 系统里没有；NOT_LINKED 有单但上传人没关联；
//   OTHER_BIZ_ONLY 只在别的业务下有单；AMOUNT_DIFF 金额对不上；BOTH 未关联且金额对不上。
// 用法：php tools/import_reconcile.php 文件ID,文件ID... [--detail]
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSystem.php';
$GLOBALS['project_import_cli'] = true;
$ids = array_values(array_filter(array_map('intval', explode(',', $argv[1] ?? ''))));
$detail = in_array('--detail', $argv, true);
if (!$ids) { fwrite(STDERR, "用法：php tools/import_reconcile.php 文件ID,... [--detail]\n"); exit(2); }
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
$find = $pdo->prepare('SELECT id,order_no,project_type,contract_amount FROM project_orders WHERE order_no=? OR order_no LIKE ?');
$part = $pdo->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=?');
$totals = [];
foreach ($ids as $fid) {
    $q = $pdo->prepare('SELECT f.*,e.name emp FROM project_import_files f LEFT JOIN employees e ON e.id=f.employee_id WHERE f.id=?'); $q->execute([$fid]); $f = $q->fetch();
    if (!$f) { echo "#$fid 不存在\n"; continue; }
    [$rows, $error] = ir_preview($fid, $f);
    $groups = [];
    foreach ($rows as $r) {
        $base = preg_replace('/[~#].*$/u', '', (string)(($r['site_external_no'] ?? '') !== '' ? $r['site_external_no'] : $r['order_no']));
        if ($base === '') continue;
        $g = &$groups[$base];
        if (!$g) $g = ['rows' => 0, 'sum' => 0.0, 'blank' => 0, 'lines' => []];
        $g['rows']++; $amt = (string)($r['contract_amount'] ?? ''); if ($amt === '') $g['blank']++; else $g['sum'] += (float)$amt;
        $g['lines'][] = (int)$r['line'] % 10000; unset($g);
    }
    $family = in_array($f['business_name'], ['网站模板', 'AI网站定制'], true) ? ['网站模板', 'AI网站定制'] : [$f['business_name']];
    $c = ['OK' => 0, 'ABSENT' => 0, 'NOT_LINKED' => 0, 'OTHER_BIZ_ONLY' => 0, 'AMOUNT_DIFF' => 0, 'BOTH' => 0]; $bad = [];
    foreach ($groups as $no => $g) {
        $esc = addcslashes($no, '\\%_');
        $find->execute([$no, $esc . '~%']); $all = $find->fetchAll();
        if (!$all) { $find->execute(['订单编号：' . $no, '订单编号：' . $esc . '%']); $all = $find->fetchAll(); }
        $os = array_values(array_filter($all, function ($o) use ($family) { return in_array($o['project_type'], $family, true); }));
        if (!$os) { $k = $all ? 'OTHER_BIZ_ONLY' : 'ABSENT'; $c[$k]++; $bad[] = [$k, $no, $g, 0]; continue; }
        $linked = false; $dbsum = 0.0;
        foreach ($os as $o) { $dbsum += (float)$o['contract_amount']; $part->execute([$o['id'], (int)$f['employee_id']]); if ($part->fetchColumn()) $linked = true; }
        $amtOk = $g['blank'] === $g['rows'] || abs($dbsum - $g['sum']) < 0.01;
        $k = $linked ? ($amtOk ? 'OK' : 'AMOUNT_DIFF') : ($amtOk ? 'NOT_LINKED' : 'BOTH');
        $c[$k]++; if ($k !== 'OK') $bad[] = [$k, $no, $g, $dbsum];
    }
    foreach ($c as $k => $v) $totals[$k] = ($totals[$k] ?? 0) + $v;
    printf("#%d %s｜%s｜%s%s\n   订单号 %d：正常 %d，系统没有 %d，只在别的业务有 %d，未关联 %d，金额不符 %d，未关联且金额不符 %d\n", $fid, $f['emp'], $f['business_name'], mb_substr($f['original_name'], 0, 24), $error !== '' ? '｜回放出错：' . mb_substr($error, 0, 60) : '', count($groups), $c['OK'], $c['ABSENT'], $c['OTHER_BIZ_ONLY'], $c['NOT_LINKED'], $c['AMOUNT_DIFF'], $c['BOTH']);
    if ($detail) foreach ($bad as [$k, $no, $g, $dbsum]) printf("      %-15s %-28s 表格 %d 行合计 %s｜系统合计 %s｜行 %s\n", $k, mb_substr($no, 0, 28), $g['rows'], $g['blank'] === $g['rows'] ? '未写' : $g['sum'], $dbsum, implode(',', array_slice($g['lines'], 0, 4)));
}
echo "\n合计 ", json_encode($totals, JSON_UNESCAPED_UNICODE), "\n";
