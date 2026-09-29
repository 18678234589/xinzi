<?php
// 只读回放：用当前代码、以原上传人身份重新预览已上传的原始表格，统计可导入行数与出错 / 提示原因。
// 每个文件单独事务，AI 托底临时关闭，结束全部回滚，不改动任何数据。
// 用法：php tools/import_replay.php [天数=14] [文件ID,...]
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSystem.php';

$days = (int)($argv[1] ?? 14);
$GLOBALS["replayDump"] = !empty($argv[3]);
$only = isset($argv[2]) ? array_map('intval', explode(',', $argv[2])) : [];
$orderBusinesses = array_keys(ps_business_catalog());
$q = db()->prepare("SELECT f.*,e.name AS employee_name FROM project_import_files f LEFT JOIN employees e ON e.id=f.employee_id WHERE f.created_at>=DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY f.id DESC");
$q->execute([$days]);
$files = array_values(array_filter($q->fetchAll(), function ($f) use ($only, $orderBusinesses) { return ($only ? in_array((int)$f['id'], $only, true) : true) && in_array($f['business_name'], $orderBusinesses, true); }));
// 同一人同名文件只回放最新一份
$seen = [];
$files = array_values(array_filter($files, function ($f) use (&$seen) { $k = $f['uploaded_by_type'] . ':' . $f['uploaded_by_id'] . ':' . $f['original_name'] . ':' . $f['business_name']; if (isset($seen[$k])) return false; return $seen[$k] = true; }));

function replay_file($file)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        ps_setting_set('ai', ['enabled' => false] + (array)ps_setting_get('ai', []), null);
        if ($file['uploaded_by_type'] === 'admin') { $_SESSION = ['admin_id' => (int)$file['uploaded_by_id'], 'project_csrf' => 'replay']; $actor = ['type' => 'admin', 'id' => (int)$file['uploaded_by_id'], 'employee_id' => null, 'role' => 'finance']; }
        else {
            $_SESSION = ['project_user_id' => (int)$file['uploaded_by_id'], 'project_csrf' => 'replay'];
            $u = $pdo->prepare('SELECT id,employee_id,role FROM project_users WHERE id=?');
            $u->execute([(int)$file['uploaded_by_id']]);
            $row = $u->fetch();
            $actor = ['type' => 'employee', 'id' => (int)$row['id'], 'employee_id' => (int)$row['employee_id'], 'role' => $row['role']];
        }
        $scope = ps_department_import_allowed($actor, $file['business_name']) && $actor['role'] !== 'finance' ? 'department' : 'personal';
        $_SERVER['SCRIPT_NAME'] = '/project/import.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = [];
        $_POST = ['csrf' => 'replay', 'action' => 'repreview', 'business' => $file['business_name'], 'scope' => $scope, 'file_id' => (int)$file['id'], 'all_sheets' => 1];
        $error = '';
        ob_start();
        include __DIR__ . '/../project/import.php';
        ob_end_clean();
        $preview = $_SESSION['project_import_preview'] ?? [];
        $sheets = $_SESSION['project_import_sheets'] ?? [];
        return ['error' => $error, 'preview' => $preview, 'sheets' => $sheets, 'business' => $selectedBusiness ?? $file['business_name']];
    } catch (Throwable $e) {
        return ['error' => get_class($e) . ': ' . $e->getMessage(), 'preview' => [], 'sheets' => [], 'business' => $file['business_name']];
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ps_setting_get('ai', null, true);
    }
}

$normalize = function ($text) { return preg_replace(['/第 \d+ 行/u', '/“[^”]{0,40}”/u', '/¥?\d+(\.\d+)?/u'], ['第 N 行', '“…”', 'N'], $text); };
foreach ($files as $file) {
    $r = replay_file($file);
    $total = count($r['preview']);
    $valid = count(array_filter($r['preview'], function ($x) { return !empty($x['base_valid']); }));
    printf("\n#%d %s｜%s｜%s｜原状态 %s\n", $file['id'], $file['employee_name'] ?: '财务', $file['business_name'] . ($r['business'] !== $file['business_name'] ? '→' . $r['business'] : ''), $file['original_name'], $file['status']);
    if ($r['error'] !== '') { echo "   ✗ 预览失败：", $r['error'], "\n"; continue; }
    $used = []; $unused = [];
    foreach ($r['sheets'] as $name => $info) if (!empty($info['used'])) $used[] = $name . '(' . ($info['rows'] ?? '?') . ')'; else $unused[] = $name . '：' . ($info['reason'] ?? '');
    echo "   分表：", implode('、', $used), $unused ? '；未读：' . implode('；', $unused) : '', "\n";
    echo "   订单 {$total}，可导入 {$valid}，需处理 " . ($total - $valid) . "\n";
    $errors = []; $warnings = [];
    foreach ($r['preview'] as $x) {
        if (empty($x['base_valid'])) { $k = $normalize($x['error']); $errors[$k][] = ($x['line'] % 10000) . '行 ' . $x['order_no'] . '：' . $x['error']; }
        if (!empty($GLOBALS["replayDump"]) && empty($x["base_valid"])) echo "     · ", ($x["line"] % 10000), "行 ", $x["order_no"], " 客服=", implode("/", array_column($x["people"]["customer_service"] ?? [], "name")), " 技术=", implode("/", array_column($x["people"]["technical"] ?? [], "name")), " ：", mb_substr($x["error"], 0, 80), "
";
        foreach (array_filter(explode('；', (string)$x['warning'])) as $w) { $k = $normalize($w); $warnings[$k] = ($warnings[$k] ?? 0) + 1; }
    }
    uasort($errors, function ($a, $b) { return count($b) <=> count($a); });
    foreach (array_slice($errors, 0, 6, true) as $k => $list) echo '   ✗ ', count($list), ' 行：', mb_substr($list[0], 0, 150), "\n";
    arsort($warnings);
    foreach (array_slice($warnings, 0, 5, true) as $k => $n) echo '   △ ', $n, ' 行：', mb_substr($k, 0, 120), "\n";
}
