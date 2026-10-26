<?php
/**
 * 我的在线表格：合作人员每个月、每个业务一张在线表格（表头就是该业务的订单模板），像 Excel 一样直接录入 / 粘贴，
 * 修改自动保存，停手几秒后自动同步到系统（走和“上传表格”完全相同的核对与导入流程，只处理有改动的行）。
 * 底层复用上传表格的在线编辑：表格是一条 project_import_files 记录（表头 CSV），所有单元格改动存在 project_import_edits。
 * 每行的同步结果（已同步 / 需处理：原因）和对应订单号存在 project_online_sheet_rows，页面上显示在“系统状态”列。
 */
require_once __DIR__ . '/ProjectSheetEdit.php';
require_once __DIR__ . '/ProjectOrderDelete.php';

const POLS_START_ROWS = 30;

function pols_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_online_sheets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL, business_name VARCHAR(100) NOT NULL, month CHAR(7) NOT NULL, file_id BIGINT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_online_sheet (employee_id,business_name,month), KEY idx_online_file (file_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS project_online_sheet_rows (
        file_id BIGINT UNSIGNED NOT NULL, row_no INT NOT NULL, row_hash CHAR(40) NOT NULL DEFAULT '',
        state VARCHAR(12) NOT NULL DEFAULT '', text VARCHAR(500) NOT NULL DEFAULT '', order_no VARCHAR(160) NULL,
        synced_at DATETIME NULL, PRIMARY KEY (file_id,row_no), KEY idx_online_order (order_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** 这张文件是不是某人的在线表格：返回 project_online_sheets 行或 null。 */
function pols_by_file($fileId)
{
    try {
        pols_ensure();
        $q = db()->prepare('SELECT * FROM project_online_sheets WHERE file_id=?');
        $q->execute([(int)$fileId]);
        return $q->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

/** 能用在线表格的业务：本账号被分配的、非历史业务；财务和管理账号不用（他们不是订单参与人）。 */
function pols_businesses(array $actor)
{
    if (($actor['role'] ?? '') === 'finance' || ps_is_management($actor) || empty($actor['employee_id'])) return [];
    return array_values(array_filter(ps_actor_businesses($actor), function ($name) { return empty(ps_business_catalog()[$name]['legacy']); }));
}

function pols_month_ok($month)
{
    return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month) && $month >= '2026-01' && $month <= date('Y-m', strtotime('+1 month'));
}

function pols_headers($business, array $actor)
{
    require_once __DIR__ . '/ProjectMiniappTemplate.php';
    return in_array($business, pmt_businesses(), true) ? pmt_headers($business) : ps_business_import_headers($business, $actor);
}

/** 打开（没有就新建）某月某业务的在线表格，返回 project_import_files 的 id。 */
function pols_open(array $actor, $business, $month)
{
    pols_ensure(); pse_ensure();
    if (!pols_month_ok($month)) throw new RuntimeException('请选择正确的月份');
    $business = ps_business_normalize($business);
    if (!in_array($business, pols_businesses($actor), true)) throw new RuntimeException('当前账号没有这个业务的在线表格');
    $emp = (int)$actor['employee_id'];
    $q = db()->prepare('SELECT s.file_id FROM project_online_sheets s JOIN project_import_files f ON f.id=s.file_id WHERE s.employee_id=? AND s.business_name=? AND s.month=?');
    $q->execute([$emp, $business, $month]);
    $fileId = (int)$q->fetchColumn();
    if ($fileId) return $fileId;
    db()->prepare('DELETE FROM project_online_sheets WHERE employee_id=? AND business_name=? AND month=?')->execute([$emp, $business, $month]); // 文件记录没了（财务删过）：重建
    $csv = implode(',', array_map(function ($h) { return '"' . str_replace('"', '""', $h) . '"'; }, pols_headers($business, $actor))) . "\n";
    $tmp = tempnam(sys_get_temp_dir(), 'ols_'); file_put_contents($tmp, $csv);
    try { $stored = ps_private_store('imports', $tmp, date('Ym') . '_online_' . bin2hex(random_bytes(10)) . '.csv'); } finally { @unlink($tmp); }
    $pdo = db();
    $pdo->prepare('INSERT INTO project_import_files (business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES (?,?,?,?,?,?,?)')
        ->execute([$business, '在线表格 ' . $month . '（' . $business . '）', $stored, strlen($csv), $actor['type'], (int)$actor['id'], $emp]);
    $fileId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO project_online_sheets (employee_id,business_name,month,file_id) VALUES (?,?,?,?)')->execute([$emp, $business, $month, $fileId]);
    pse_set_capacity($fileId, 'CSV', POLS_START_ROWS, $actor);
    return $fileId;
}

/** 本人所有在线表格（按月份倒序），带行数和同步情况。 */
function pols_list(array $actor)
{
    pols_ensure();
    $q = db()->prepare('SELECT s.id,s.business_name,s.month,s.file_id,s.created_at FROM project_online_sheets s JOIN project_import_files f ON f.id=s.file_id WHERE s.employee_id=? ORDER BY s.month DESC,s.business_name');
    $q->execute([(int)($actor['employee_id'] ?? 0)]);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $c = db()->prepare("SELECT SUM(state<>'' AND state<>'cleared'),SUM(state='ok'),SUM(state IN ('bad','wait','retry')) FROM project_online_sheet_rows WHERE file_id=?");
        $c->execute([(int)$r['file_id']]); [$rows, $ok, $bad] = array_map('intval', $c->fetch(PDO::FETCH_NUM));
        $out[] = $r + ['rows' => $rows, 'ok' => $ok, 'bad' => $bad];
    }
    return $out;
}

/** 每行的同步状态：[行号 => ['s'=>ok|bad|wait|retry|cleared, 't'=>说明]]。 */
function pols_states($fileId)
{
    $out = [];
    try {
        pols_ensure();
        $q = db()->prepare("SELECT row_no,state,text FROM project_online_sheet_rows WHERE file_id=? AND state<>''");
        $q->execute([(int)$fileId]);
        foreach ($q->fetchAll() as $r) $out[(int)$r['row_no']] = ['s' => $r['state'], 't' => $r['text']];
    } catch (Throwable $e) { /* 表还没建：没有状态 */ }
    return $out;
}

function pols_row_hash(array $cells)
{
    return sha1(json_encode(array_values($cells), JSON_UNESCAPED_UNICODE));
}

/** 合并了编辑内容的行：[行号 => 单元格数组]，只含有内容的行。 */
function pols_merged_rows($file, $sheet, array $actor)
{
    $p = pse_parse($file, $sheet, $actor);
    $over = pse_overlay((int)$file['id'], $sheet);
    $rows = [];
    foreach ($p['rows'] as $no => $cells) {
        foreach ($over[$no] ?? [] as $c => $o) if ($c < $p['width']) $cells[$c] = $o['value'];
        $rows[$no] = $cells;
    }
    return [$p, $rows];
}

/** 在当前请求里跑一遍导入流程（和“上传表格”完全相同的核对 / 导入代码）；返回 [预览行, 错误, 导入单数]。 */
function pols_run_import(array $post)
{
    $GLOBALS['project_import_api'] = true; $GLOBALS['project_import_online'] = true;
    $backup = [$_POST, $_GET, $_FILES, $_SERVER['REQUEST_METHOD'] ?? null, $_SERVER['SCRIPT_NAME'] ?? null];
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['SCRIPT_NAME'] = '/project/import.php';
    $_POST = $post + ['csrf' => ps_csrf_token()]; $_GET = []; $_FILES = [];
    $error = ''; $imported = 0; $preview = [];
    try {
        ob_start();
        include __DIR__ . '/../project/import.php';
    } finally {
        ob_end_clean();
        [$_POST, $_GET, $_FILES] = [$backup[0], $backup[1], $backup[2]];
        if ($backup[3] === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $backup[3];
        if ($backup[4] === null) unset($_SERVER['SCRIPT_NAME']); else $_SERVER['SCRIPT_NAME'] = $backup[4];
        unset($GLOBALS['project_import_api'], $GLOBALS['project_import_online']);
    }
    return [$preview, $error, $imported];
}

/** 把本次同步的导入回执并进“整张表格累计”的回执（对应订单 / 撤销上传按整张表格算）。 */
function pols_merge_report($fileId, array $before, array $actor)
{
    $now = ps_import_result_get($fileId);
    if (!$now) return;
    $ids = array_values(array_unique(array_map('intval', array_merge((array)($before['order_ids'] ?? []), (array)($now['order_ids'] ?? [])))));
    $written = array_values(array_unique(array_map('intval', array_merge((array)($before['written_order_ids'] ?? []), (array)($now['written_order_ids'] ?? [])))));
    if ($ids) {
        $alive = db()->query('SELECT id FROM project_orders WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN);
        $alive = array_flip(array_map('intval', $alive));
        $ids = array_values(array_filter($ids, function ($i) use ($alive) { return isset($alive[$i]); }));
        $written = array_values(array_filter($written, function ($i) use ($alive) { return isset($alive[$i]); }));
    }
    $now['order_ids'] = $ids; $now['written_order_ids'] = $written; $now['written'] = count($written);
    $now['existing'] = max(0, count($ids) - count($written)); $now['pending'] = 0; $now['conflict'] = 0;
    ps_audit('import_file', (int)$fileId, 'import_result', $actor, $now);
}

/** 行号 ↔ 预览行号：CSV 数据行 n（从 1 起）在预览里是第 n+1 行。 */
function pols_line_to_row($line)
{
    return ((int)$line % 10000) - 1;
}

/**
 * 同步：把有改动的行核对并导入系统，返回每行状态。
 * - 只处理“内容和上次同步时不一样”的行（以及上次因故没同步成的行）；没改过的行不重复处理。
 * - 订单号改了：旧订单是还没审核的草稿就一并撤掉（进回收站），否则保留并在状态里提醒；行被清空：订单保留，提醒去订单页处理。
 * - 不产生“待补全”弹窗和站内信：问题直接写在该行的“系统状态”里。
 * @return array ['states'=>[行号=>['s','t']], 'synced'=>本次同步成功行数, 'bad'=>需处理行数, 'busy'=>bool]
 */
function pols_sync($fileId, array $actor)
{
    pols_ensure(); pse_ensure(); poi_ensure();
    $online = pols_by_file($fileId);
    if (!$online || (int)$online['employee_id'] !== (int)($actor['employee_id'] ?? 0)) throw new RuntimeException('这不是你的在线表格');
    $file = pse_file($fileId, $actor);
    $pdo = db();
    $lock = $pdo->prepare('SELECT GET_LOCK(?,0)'); $lock->execute(['pols_sync_' . (int)$fileId]);
    if (!(int)$lock->fetchColumn()) return ['states' => pols_states($fileId), 'synced' => 0, 'bad' => 0, 'busy' => true];
    try {
        $sheets = ps_import_file_sheets($file);
        $sheet = (string)array_key_first($sheets);
        [$p, $rows] = pols_merged_rows($file, $sheet, $actor);
        $stored = [];
        $q = $pdo->prepare('SELECT row_no,row_hash,state,order_no FROM project_online_sheet_rows WHERE file_id=?'); $q->execute([(int)$fileId]);
        foreach ($q->fetchAll() as $r) $stored[(int)$r['row_no']] = $r;
        $blankHash = pols_row_hash(array_fill(0, $p['width'], ''));
        $dirty = []; $hash = []; $cleared = [];
        foreach ($rows as $no => $cells) {
            $h = pols_row_hash($cells); $hash[$no] = $h;
            $blank = $h === $blankHash;
            $old = $stored[$no] ?? null;
            if ($blank) { if ($old && $old['state'] !== '' && $old['state'] !== 'cleared') $cleared[] = $no; continue; }
            if (!$old || $old['row_hash'] !== $h || $old['state'] === 'retry') $dirty[] = $no;
        }
        $mark = $pdo->prepare('INSERT INTO project_online_sheet_rows (file_id,row_no,row_hash,state,text,order_no,synced_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE row_hash=VALUES(row_hash),state=VALUES(state),text=VALUES(text),order_no=VALUES(order_no),synced_at=NOW()');
        foreach ($cleared as $no) {
            $on = (string)($stored[$no]['order_no'] ?? '');
            $mark->execute([(int)$fileId, $no, $blankHash, 'cleared', $on !== '' ? '这一行已清空；订单 ' . $on . ' 仍在系统里，如要删除请到订单页删除' : '', null]);
        }
        if (!$dirty) return ['states' => pols_states($fileId), 'synced' => 0, 'bad' => 0, 'busy' => false];

        $beforeReport = ps_import_result_get($fileId);
        $saved = []; foreach ($_SESSION as $k => $v) if (strpos((string)$k, 'project_import_') === 0 || $k === 'pse_import_revisions') $saved[$k] = $v;
        foreach (array_keys($saved) as $k) unset($_SESSION[$k]);
        $post = ['action' => 'repreview', 'business' => (string)$file['business_name'], 'file_id' => (int)$fileId, 'resume_file' => (int)$fileId, 'all_sheets' => 1];
        try {
            $GLOBALS['pse_only_rows'] = [$sheet => $dirty];
            [$preview, $error] = pols_run_import($post);
            $byRow = [];
            foreach ($preview as $pv) $byRow[pols_line_to_row($pv['line'] ?? 0)] = $pv;
            // 订单号改了：旧订单是还没审核的草稿就先撤掉
            $notes = [];
            foreach ($dirty as $no) {
                $pv = $byRow[$no] ?? null; $oldNo = (string)($stored[$no]['order_no'] ?? '');
                if (!$pv || empty($pv['base_valid']) || $oldNo === '' || $oldNo === (string)($pv['order_no'] ?? '')) continue;
                $notes[$no] = pols_drop_old_order($oldNo, (int)$fileId, $no, $actor);
            }
            $imported = 0; $commitError = '';
            if ($error === '' && array_filter($preview, function ($r) { return !empty($r['base_valid']); })) {
                [$left, $commitError, $imported] = pols_run_import(['action' => 'commit', 'business' => (string)$file['business_name'], 'auto_import' => 1]);
            }
        } finally {
            unset($GLOBALS['pse_only_rows']);
            foreach (array_keys($_SESSION) as $k) if (strpos((string)$k, 'project_import_') === 0 || $k === 'pse_import_revisions') unset($_SESSION[$k]);
            foreach ($saved as $k => $v) $_SESSION[$k] = $v;
        }
        $synced = 0; $bad = 0;
        foreach ($dirty as $no) {
            $pv = $byRow[$no] ?? null;
            if ($error !== '' && !$pv) { $mark->execute([(int)$fileId, $no, $hash[$no], 'bad', mb_substr('需处理：' . $error, 0, 480), null]); $bad++; continue; }
            if (!$pv) { $mark->execute([(int)$fileId, $no, $hash[$no], 'wait', '还没填完整：至少要有订单编号、日期和售价；填完后会自动同步', null]); continue; }
            if (!empty($pv['base_valid'])) {
                if ($commitError !== '') { $mark->execute([(int)$fileId, $no, '', 'retry', mb_substr('同步没成功，稍后会自动重试：' . $commitError, 0, 480), null]); $bad++; continue; }
                $text = '已同步' . (!empty($pv['order_no']) ? ' · 订单 ' . $pv['order_no'] : '') . (!empty($pv['warning']) ? '（' . mb_substr((string)$pv['warning'], 0, 120) . '）' : '') . (!empty($notes[$no]) ? '；' . $notes[$no] : '');
                $mark->execute([(int)$fileId, $no, $hash[$no], 'ok', mb_substr($text, 0, 480), (string)($pv['order_no'] ?? '')]); $synced++;
            } else {
                $why = trim((string)($pv['error'] ?? '')) !== '' ? (string)$pv['error'] : (string)($pv['status'] ?? '需处理');
                $state = in_array($pv['status'] ?? '', ['已导入过', '他人订单'], true) ? 'wait' : 'bad';
                $mark->execute([(int)$fileId, $no, $hash[$no], $state, mb_substr(($state === 'bad' ? '需处理：' : '') . $why, 0, 480), null]); $bad++;
            }
        }
        pols_merge_report((int)$fileId, $beforeReport, $actor);
        return ['states' => pols_states($fileId), 'synced' => $synced, 'bad' => $bad, 'busy' => false];
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['pols_sync_' . (int)$fileId]);
    }
}

/** 订单号改了：旧订单能撤（没审核的草稿、没收款、本人参与）就进回收站撤掉，否则保留并返回提醒。 */
function pols_drop_old_order($oldNo, $fileId, $rowNo, array $actor)
{
    $q = db()->prepare('SELECT id,order_no,project_type,order_date,settlement_status FROM project_orders WHERE order_no=?');
    $q->execute([$oldNo]); $o = $q->fetch();
    if (!$o) return '';
    $other = db()->prepare('SELECT COUNT(*) FROM project_online_sheet_rows WHERE file_id=? AND order_no=? AND row_no<>?');
    $other->execute([(int)$fileId, $oldNo, (int)$rowNo]);
    if ((int)$other->fetchColumn() > 0) return '';
    $why = pod_blocker($o, $actor);
    if ($why !== '') return '原订单号 ' . $oldNo . ' 的订单还在系统里（' . $why . '）';
    $pdo = db(); $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $trash = pod_backup((int)$o['id'], $oldNo, $actor);
        pod_delete_rows((int)$o['id']);
        ps_audit('order', (int)$o['id'], 'online_sheet_replace', $actor, ['file_id' => (int)$fileId, 'row' => (int)$rowNo, 'trash' => $trash]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        return '原订单号 ' . $oldNo . ' 的订单没能自动撤掉，请联系财务处理';
    }
    return '原订单号 ' . $oldNo . ' 的订单已自动撤销（进回收站，30 天内可还原）';
}
