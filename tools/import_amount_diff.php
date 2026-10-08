<?php
// 表格金额与系统不一致的订单号按原因归类（用店铺流水价当裁判）。默认只读。
// 用法：php tools/import_amount_diff.php 文件ID,... [每类样例数] [--apply]
// --apply：只对“B 店铺流水价 = 表格价、系统价不同”且仅一张订单、未审核的订单，把系统售价更正为流水价（同 poj_price_verdict 规则），更正前后记入备份 JSON 和审计。
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
require_once $root . '/includes/ProjectIntake.php';
require_once $root . '/includes/ProjectSystem.php';
require_once $root . '/includes/ProjectOrderJoin.php';
$GLOBALS['project_import_cli'] = true;
$ids = array_values(array_filter(array_map('intval', explode(',', $argv[1] ?? ''))));
$apply = in_array('--apply', $argv, true);
$samples = isset($argv[2]) && ctype_digit($argv[2]) ? (int)$argv[2] : 4;
$changed = [];
$actor = ['type' => 'system', 'id' => 0, 'role' => 'finance'];
function ir_preview($fid, $f)
{
    global $root;
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
        $error = ''; ob_start(); include $root . '/project/import.php'; ob_end_clean();
        $rows = $_SESSION['project_import_preview'] ?? [];
    } catch (Throwable $e) { $error = $e->getMessage(); }
    finally { while (ob_get_level() > 0) ob_end_clean(); if ($pdo->inTransaction()) $pdo->rollBack(); ps_setting_get('ai', null, true); }
    return [$rows, $error];
}
$pdo = db();
$orderQ = $pdo->prepare('SELECT o.id,o.order_no,o.project_type,o.contract_amount,o.settlement_status,COALESCE(s.price_source,"") price_source FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.order_no=? OR o.order_no LIKE ?');
$out = [];
foreach ($ids as $fid) {
    $q = $pdo->prepare('SELECT f.*,e.name emp FROM project_import_files f LEFT JOIN employees e ON e.id=f.employee_id WHERE f.id=?'); $q->execute([$fid]); $f = $q->fetch();
    if (!$f) continue;
    [$rows, $error] = ir_preview($fid, $f);
    $groups = [];
    foreach ($rows as $r) {
        $base = preg_replace('/[~#].*$/u', '', (string)(($r['site_external_no'] ?? '') !== '' ? $r['site_external_no'] : $r['order_no']));
        if ($base === '') continue;
        $g = &$groups[$base]; if (!$g) $g = ['rows' => 0, 'sum' => 0.0, 'blank' => 0, 'lines' => []];
        $g['rows']++; $amt = (string)($r['contract_amount'] ?? ''); if ($amt === '') $g['blank']++; else $g['sum'] += (float)$amt; $g['lines'][] = (int)$r['line'] % 10000; unset($g);
    }
    $family = in_array($f['business_name'], ['网站模板', 'AI网站定制'], true) ? ['网站模板', 'AI网站定制'] : [$f['business_name']];
    foreach ($groups as $no => $g) {
        if ($g['blank'] === $g['rows']) continue;
        $esc = addcslashes($no, '\\%_'); $orderQ->execute([$no, $esc . '~%']); $all = $orderQ->fetchAll();
        $os = array_values(array_filter($all, function ($o) use ($family) { return in_array($o['project_type'], $family, true); }));
        if (!$os) continue;
        $dbsum = array_sum(array_column($os, 'contract_amount'));
        if (abs($dbsum - $g['sum']) < 0.01) continue;
        $own = null; foreach ($os as $o) if (abs((float)$o['contract_amount'] - $g['sum']) < 0.01) $own = $o;
        $flow = []; foreach (ps_shop_order_lookup($no) as $m) if ($m['price'] !== null) $flow[] = round((float)$m['price'], 2);
        $flow = array_values(array_unique($flow));
        $cat = 'D_无流水可裁判';
        if ($own) $cat = 'A_表格价=库内某张订单的价（多张订单分摊，不是真冲突）';
        elseif ($flow) {
            $sheetHit = in_array(round($g['sum'], 2), $flow, true); $dbHit = in_array(round((float)$dbsum, 2), $flow, true); $singleHit = false;
            foreach ($os as $o) if (in_array(round((float)$o['contract_amount'], 2), $flow, true)) $singleHit = true;
            if ($sheetHit && !$dbHit) $cat = 'B_店铺流水支持表格价';
            elseif ($dbHit || $singleHit) $cat = 'C_店铺流水支持库内价';
            else $cat = 'E_有流水但两边都不等于流水价';
        } elseif ($g['sum'] > 0 && abs($g['sum'] - $dbsum) <= 50) $cat = 'F_无流水，差额不超过50';
        elseif ($g['sum'] < $dbsum) $cat = 'G_无流水，表格价小于库内价（可能只写了自己那份）';
        if ($apply && strpos($cat, 'B_') === 0 && count($os) === 1) {
            $verdict = poj_price_verdict(['id' => (int)$os[0]['id'], 'order_no' => $os[0]['order_no'], 'contract_amount' => $os[0]['contract_amount'], 'price_source' => $os[0]['price_source']], $g['sum'], $no);
            if ($verdict['verdict'] === 'adopt_sheet' && poj_apply_price((int)$os[0]['id'], $g['sum'], $verdict['flow'], $actor, ['file_id' => $fid, 'tool' => 'import_amount_diff'])) {
                $changed[] = ['order_id' => (int)$os[0]['id'], 'order_no' => $os[0]['order_no'], 'from' => (float)$os[0]['contract_amount'], 'to' => round($g['sum'], 2), 'flow' => $verdict['flow'], 'file_id' => $fid];
                $cat = 'B2_已按店铺流水价更正';
            }
        }
        $out[$cat][] = [$fid, $f['emp'], $f['business_name'], $no, $g['sum'], $dbsum, count($os), implode('/', array_column($os, 'project_type')), implode(',', $flow), $os[0]['price_source'] . '/' . $os[0]['settlement_status']];
    }
}
ksort($out); $tot = 0; foreach ($out as $rows) $tot += count($rows);
echo "金额不一致的订单号合计 $tot\n";
foreach ($out as $cat => $rows) {
    echo "\n【{$cat}】 ", count($rows), " 个\n";
    foreach (array_slice($rows, 0, $samples) as $r) printf("   #%d %s %s %s 表格%.2f 库内%.2f（%d 张：%s）流水[%s] %s\n", $r[0], $r[1], $r[2], mb_substr($r[3], 0, 24), $r[4], $r[5], $r[6], $r[7], $r[8], $r[9]);
}
if ($apply) {
    echo "
已更正 ", count($changed), " 张订单的售价
";
    if ($changed) {
        $dir = is_dir($root . '/.deploy') && is_writable($root . '/.deploy') ? $root . '/.deploy' : sys_get_temp_dir();
        $file = $dir . '/amount_arbitrated_' . date('Ymd_His') . '.json';
        file_put_contents($file, json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo "更正明细（可据此还原）：$file
";
    }
}
