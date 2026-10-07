<?php
/**
 * 原始表格在线编辑（像 Excel 一样改，自动保存，提交后更正自己的订单）。
 * - 原始上传文件永不改动；编辑内容单独存 project_import_edits（只存与原表不同的格子），随时可恢复。
 * - 可编辑列只有：客户手机号、客户域名、售价、店铺、付款昵称；其余列只读（订单号、日期等）。
 * - 提交更正：手机号 / 域名直接写入该订单的续费资料；售价 / 店铺 / 付款昵称走现有“订单更正”（财务直接生效，其他人提交财务确认）。
 */
require_once __DIR__ . '/ProjectIntake.php';
require_once __DIR__ . '/ProjectBusiness.php';
require_once __DIR__ . '/ProjectOrderFix.php';
require_once __DIR__ . '/ProjectRenewalImport.php';
require_once __DIR__ . '/ProjectImportResult.php';

const PSE_MAX_ROWS = 3000;

function pse_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_import_edits (
        file_id INT NOT NULL, sheet VARCHAR(120) NOT NULL, row_no INT NOT NULL, col_idx SMALLINT NOT NULL,
        value TEXT NOT NULL, applied_value TEXT NULL,
        updated_by_type VARCHAR(20) NOT NULL DEFAULT '', updated_by_id INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (file_id, sheet, row_no, col_idx)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** 文件访问：财务看全部，其他人只能是本人上传的（由 ps_import_file_get 校验）。 */
function pse_file($fileId, $actor)
{
    return ps_import_file_get((int)$fileId, $actor);
}

function pse_cell($v)
{
    if ($v === null) return '';
    if (is_int($v) || is_float($v)) { $s = rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.'); return $s === '' ? '0' : $s; }
    return trim((string)$v);
}

/** Excel 日期序列号 → Y-m-d（仅用于只读列显示）。 */
function pse_excel_date($s)
{
    if (!preg_match('/^\d{5}(?:\.\d+)?$/', $s) || (float)$s < 30000 || (float)$s > 80000) return $s;
    return date('Y-m-d', (int)round(((float)$s - 25569) * 86400));
}

/** 解析一张工作表：列含义、虚拟列、行数据（原值，不含编辑内容）。 */
function pse_parse($file, $sheetName, $actor)
{
    $sheets = ps_import_file_sheets($file);
    if (!isset($sheets[$sheetName])) throw new RuntimeException('找不到这张工作表');
    $rows = array_values(array_filter($sheets[$sheetName], 'is_array'));
    if (!$rows) return ['head' => [], 'kinds' => [], 'width' => 0, 'rows' => [], 'orderCol' => null, 'virtual' => 0, 'truncated' => false];
    $head = array_map('pse_cell', array_values($rows[0]));
    $width = $head ? count($head) : 0;
    foreach ($rows as $r) $width = max($width, count($r));
    $head = array_pad($head, $width, '');
    $map = [];
    try { $map = ps_business_import_map((string)$file['business_name'], $head, false); } catch (Throwable $e) { $map = []; }
    $kinds = array_fill(0, $width, '');
    foreach (['order_no' => 'order_no', 'contract_amount' => 'amount', 'shop' => 'shop', 'payment_nickname' => 'nick', 'order_date' => 'date'] as $key => $kind) if (isset($map[$key])) $kinds[(int)$map[$key]] = $kind;
    foreach ($head as $i => $h) {
        $label = preg_replace('/[\s（）()：:]/u', '', $h);
        if ($kinds[$i] !== '') continue;
        if (preg_match('/服务器.*(到期|有效期)|(到期|有效期).*服务器/u', $label) && !preg_match('/域名/u', $label)) $kinds[$i] = 'server_expiry';
        elseif (preg_match('/^(客户)?(手机号?码?|电话|联系电话|联系方式)$/u', $label)) $kinds[$i] = 'phone'; // 只认专门的手机号列；“备注（写客户电话或者微信）”常写微信号，不当手机号
        elseif (in_array($label, ['域名', '域名地址', '网站域名', '客户域名'], true)) $kinds[$i] = 'domain';
        elseif (preg_match('/日期|时间/u', $label)) $kinds[$i] = 'date';
    }
    // 虚拟列：模板里没有、但本岗位需要的列（客户手机号 / 客户域名）
    $virtual = 0;
    $extras = ps_import_role_extras((string)$file['business_name'], $actor, $head);
    if (!$extras && ($actor['role'] ?? '') === 'finance') {
        // 财务查看 / 更正：网站类订单补手机号 + 域名，小程序补手机号 + 服务器到期日（已有同名列则不重复）
        $biz = (string)$file['business_name'];
        $want = $biz === '小程序开发' ? ['客户手机号', '服务器到期日'] : (in_array($biz, ['AI网站定制', '网站模板', '网站续费', '网站修改', '备案-提成'], true) ? ['客户手机号', '客户域名'] : []);
        foreach ($want as $w) {
            $has = false;
            foreach ($head as $h) { $l = preg_replace('/[\s（）()：:]/u', '', (string)$h); if ($w === '客户手机号' ? preg_match('/^(客户)?(手机号?码?|电话|联系电话|联系方式)$/u', $l) : ($w === '客户域名' ? in_array($l, ['域名', '域名地址', '网站域名', '客户域名'], true) : preg_match('/服务器.*(到期|有效期)/u', $l))) $has = true; }
            if (!$has) $extras[] = $w;
        }
    }
    foreach ($extras as $extra) {
        $head[] = $extra; $kinds[] = $extra === '客户手机号' ? 'phone' : ($extra === '服务器到期日' ? 'server_expiry' : 'domain'); $virtual++;
    }
    $total = count($head);
    $data = []; $truncated = false;
    foreach ($rows as $i => $r) {
        if ($i === 0) continue;
        if (count($data) >= PSE_MAX_ROWS) { $truncated = true; break; }
        $r = array_values($r);
        $cells = [];
        for ($c = 0; $c < $total; $c++) {
            $s = $c < $width ? pse_cell($r[$c] ?? '') : '';
            $cells[] = in_array($kinds[$c], ['date', 'server_expiry'], true) ? pse_excel_date($s) : $s;
        }
        $data[$i] = $cells;
    }
    $orderCol = array_search('order_no', $kinds, true);
    return ['head' => $head, 'kinds' => $kinds, 'width' => $total, 'rows' => $data, 'orderCol' => $orderCol === false ? null : $orderCol, 'virtual' => $virtual, 'truncated' => $truncated];
}

function pse_editable_kind($kind) { return in_array($kind, ['phone', 'domain', 'server_expiry', 'amount', 'shop', 'nick'], true); }

function pse_overlay($fileId, $sheet)
{
    pse_ensure();
    $q = db()->prepare('SELECT row_no,col_idx,value,applied_value FROM project_import_edits WHERE file_id=? AND sheet=?');
    $q->execute([(int)$fileId, $sheet]);
    $out = [];
    foreach ($q->fetchAll() as $r) $out[(int)$r['row_no']][(int)$r['col_idx']] = ['value' => $r['value'], 'applied' => $r['applied_value']];
    return $out;
}

/** 前端加载：表头、可编辑标记、合并了编辑内容的行。 */
function pse_load($fileId, $sheet, $actor)
{
    $file = pse_file($fileId, $actor);
    $p = pse_parse($file, $sheet, $actor);
    $over = pse_overlay((int)$file['id'], $sheet);
    $rows = []; $rowNos = []; $pending = 0; $changed = [];
    foreach ($p['rows'] as $no => $cells) {
        $y = count($rows);
        foreach ($over[$no] ?? [] as $c => $o) {
            if ($c < $p['width']) {
                $cells[$c] = $o['value'];
                $isPending = $o['applied'] === null || $o['applied'] !== $o['value'];
                if ($isPending) $pending++;
                $changed[] = [$y, $c, $isPending ? 0 : 1]; // 0=待提交 1=已提交
            }
        }
        $rows[] = $cells; $rowNos[] = $no;
    }
    $editable = array_map('pse_editable_kind', $p['kinds']);
    // 缓存供自动保存校验（避免每次保存都重新解析整张表）
    $orig = [];
    foreach ($p['rows'] as $no => $cells) foreach ($p['kinds'] as $c => $k) if (pse_editable_kind($k)) $orig[$no][$c] = $cells[$c];
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['pse_cache'][(int)$file['id'] . '|' . $sheet] = ['kinds' => $p['kinds'], 'width' => $p['width'], 'orig' => $orig];
    return ['head' => $p['head'], 'kinds' => $p['kinds'], 'editable' => $editable, 'rows' => $rows, 'rowNos' => $rowNos, 'orderCol' => $p['orderCol'], 'virtual' => $p['virtual'], 'truncated' => $p['truncated'], 'pending' => $pending, 'changed' => $changed,
            'file' => ['id' => (int)$file['id'], 'name' => $file['original_name'], 'business' => $file['business_name']]];
}

/** 自动保存：edits = [['row'=>源表行号,'col'=>列,'v'=>值]…]。与原值相同则撤销该格编辑。 */
function pse_save($fileId, $sheet, array $edits, $actor)
{
    $file = pse_file($fileId, $actor);
    $key = (int)$file['id'] . '|' . $sheet;
    $cache = $_SESSION['pse_cache'][$key] ?? null;
    if (!$cache) {
        $p = pse_parse($file, $sheet, $actor);
        $orig = [];
        foreach ($p['rows'] as $no => $cells) foreach ($p['kinds'] as $c => $k) if (pse_editable_kind($k)) $orig[$no][$c] = $cells[$c];
        $cache = ['kinds' => $p['kinds'], 'width' => $p['width'], 'orig' => $orig];
        $_SESSION['pse_cache'][$key] = $cache;
    }
    pse_ensure();
    $up = db()->prepare('INSERT INTO project_import_edits (file_id,sheet,row_no,col_idx,value,updated_by_type,updated_by_id) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_by_type=VALUES(updated_by_type),updated_by_id=VALUES(updated_by_id)');
    $del = db()->prepare('DELETE FROM project_import_edits WHERE file_id=? AND sheet=? AND row_no=? AND col_idx=?');
    $saved = 0;
    foreach (array_slice($edits, 0, 500) as $e) {
        $row = (int)($e['row'] ?? 0); $col = (int)($e['col'] ?? -1); $v = trim((string)($e['v'] ?? ''));
        if ($row < 1 || $col < 0 || $col >= $cache['width'] || !pse_editable_kind($cache['kinds'][$col] ?? '') || !array_key_exists($row, $cache['orig'])) continue;
        if (mb_strlen($v) > 200) $v = mb_substr($v, 0, 200);
        if ($v === (string)($cache['orig'][$row][$col] ?? '')) $del->execute([(int)$file['id'], $sheet, $row, $col]);
        else $up->execute([(int)$file['id'], $sheet, $row, $col, $v, $actor['type'], (int)$actor['id']]);
        $saved++;
    }
    return $saved;
}

function pse_domain_clean($v)
{
    $v = strtolower(trim($v));
    $v = preg_replace('#^https?://#', '', $v);
    $v = preg_replace('#[/?\#].*$#', '', $v);
    $v = preg_replace('#^www\.#', '', $v) ?: $v;
    return $v;
}

/** 把手机号写入订单续费资料（覆盖原号码；取消待发短信）。 */
function pse_set_phone($orderId, $phone, $actor)
{
    $phone = pr_phone($phone);
    if ($phone === '') throw new RuntimeException('手机号为空');
    pr_order($orderId, $actor); // 权限与业务范围校验
    $pdo = db();
    $hash = hash_hmac('sha256', $phone, pv_key());
    $items = $pdo->prepare("SELECT id,phone_hash FROM project_renewal_items WHERE order_id=? AND status<>'closed'");
    $items->execute([(int)$orderId]);
    $rows = $items->fetchAll();
    if (!$rows) { pr_import_apply((int)$orderId, ['phone' => $phone, 'resources' => []], $actor); return; }
    $upd = $pdo->prepare('UPDATE project_renewal_items SET phone_cipher=?,phone_hash=?,revision=revision+1,updated_at=NOW() WHERE id=?');
    foreach ($rows as $r) {
        if ($r['phone_hash'] === $hash) continue;
        $upd->execute([pv_encrypt($phone), $hash, (int)$r['id']]);
        try { $pdo->prepare("UPDATE project_renewal_sms SET state='cancelled',provider_code='RESOURCE_UPDATED',updated_at=NOW() WHERE item_id=? AND state IN ('pending','failed') AND phone_hash<>?")->execute([(int)$r['id'], $hash]); } catch (Throwable $e) { /* 短信表未启用时忽略 */ }
        ps_audit('renewal', (int)$r['id'], 'sheet_phone', $actor, ['order_id' => (int)$orderId]);
    }
}

/** 把服务器到期日写入订单续费资料（实际日期，来源记为已核实）：已有服务器条目就改日期，没有就新建。 */
function pse_set_server_expiry($orderId, $value, $actor)
{
    $date = null;
    if (function_exists('ps_import_date')) { try { $date = ps_import_date($value); } catch (Throwable $e) { $date = null; } }
    if (!$date) $date = pr_date($value);
    $date = pr_date($date);
    pr_order($orderId, $actor);
    $pdo = db();
    $q = $pdo->prepare("SELECT id,expires_on FROM project_renewal_items WHERE order_id=? AND resource_type='server' AND status<>'closed' ORDER BY id LIMIT 1");
    $q->execute([(int)$orderId]); $item = $q->fetch();
    if (!$item) {
        $pdo->prepare("INSERT INTO project_renewal_items (order_id,resource_type,resource_name,expires_on,expiry_source,status) VALUES (?,'server','服务器',?,'confirmed','active')")->execute([(int)$orderId, $date]);
        $itemId = (int)$pdo->lastInsertId(); $old = null;
    } else {
        $itemId = (int)$item['id']; $old = $item['expires_on'];
        if ($old === $date) return;
        $pdo->prepare("UPDATE project_renewal_items SET expires_on=?,expiry_source='confirmed',revision=revision+1,updated_at=NOW() WHERE id=?")->execute([$date, $itemId]);
        try { $pdo->prepare("UPDATE project_renewal_sms SET state='cancelled',provider_code='RESOURCE_UPDATED',updated_at=NOW() WHERE item_id=? AND state IN ('pending','failed') AND expires_on<>?")->execute([$itemId, $date]); } catch (Throwable $e) { /* 短信表未启用时忽略 */ }
    }
    try { $pdo->prepare('INSERT INTO project_renewal_history (item_id,action,actor_type,actor_id,old_expiry,new_expiry,details_json) VALUES (?,?,?,?,?,?,?)')->execute([$itemId, 'sheet_server_expiry', $actor['type'], (int)$actor['id'], $old, $date, json_encode(['order_id' => (int)$orderId, 'source' => 'sheet_edit'], JSON_UNESCAPED_UNICODE)]); } catch (Throwable $e) { /* 历史表异常不影响写入 */ }
    ps_audit('renewal', $itemId, 'sheet_server_expiry', $actor, ['order_id' => (int)$orderId, 'old_expiry' => $old, 'new_expiry' => $date]);
}

/** 把域名写入订单续费资料：已有一个域名条目就改名，没有就新建，有多个则请去续费工作台处理。 */
function pse_set_domain($orderId, $domain, $actor)
{
    $domain = pse_domain_clean($domain);
    if (!preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,63}$/D', $domain)) throw new RuntimeException('域名格式不正确：' . $domain);
    pr_order($orderId, $actor);
    $pdo = db();
    $q = $pdo->prepare("SELECT id,resource_name FROM project_renewal_items WHERE order_id=? AND resource_type='domain' AND status<>'closed' ORDER BY id");
    $q->execute([(int)$orderId]);
    $rows = $q->fetchAll();
    foreach ($rows as $r) if ($r['resource_name'] === $domain) return;
    if (count($rows) > 1) throw new RuntimeException('这个订单有多个域名条目，请到续费工作台修改');
    if (!$rows) { pr_import_apply((int)$orderId, ['phone' => '', 'resources' => ['domain' => ['resource_name' => $domain]]], $actor); return; }
    $pdo->prepare('UPDATE project_renewal_items SET resource_name=?,revision=revision+1,updated_at=NOW() WHERE id=?')->execute([$domain, (int)$rows[0]['id']]);
    ps_audit('renewal', (int)$rows[0]['id'], 'sheet_domain', $actor, ['order_id' => (int)$orderId, 'domain' => $domain]);
}

/**
 * 提交更正。$dry=true 只返回将要做什么，不写入。
 * @return array 每个订单一条：['row','order_no','changes'=>['售价'=>'…'],'result'=>'待提交|已更正|已提交财务确认|失败','message']
 */
function pse_submit($fileId, $sheet, $actor, $dry = false)
{
    $file = pse_file($fileId, $actor);
    $p = pse_parse($file, $sheet, $actor);
    if ($p['orderCol'] === null) throw new RuntimeException('这张表没有订单号列，无法对应订单；请在含订单号的工作表里编辑');
    $over = pse_overlay((int)$file['id'], $sheet);
    $results = [];
    $find = db()->prepare('SELECT id,order_no,shop,contract_amount FROM project_orders WHERE order_no=?');
    $mark = db()->prepare('UPDATE project_import_edits SET applied_value=value WHERE file_id=? AND sheet=? AND row_no=? AND col_idx=?');
    foreach ($over as $no => $cells) {
        $pendingCells = [];
        foreach ($cells as $c => $o) if (($o['applied'] === null || $o['applied'] !== $o['value']) && isset($p['kinds'][$c]) && pse_editable_kind($p['kinds'][$c])) $pendingCells[$c] = $o['value'];
        if (!$pendingCells) continue;
        $orderNo = trim((string)($p['rows'][$no][$p['orderCol']] ?? ''));
        $labels = [];
        foreach ($pendingCells as $c => $v) $labels[$p['head'][$c]] = $v;
        $res = ['row' => $no + 1, 'order_no' => $orderNo, 'changes' => $labels, 'result' => '待提交', 'message' => ''];
        if ($orderNo === '') { $res['result'] = '失败'; $res['message'] = '这一行没有订单号'; $results[] = $res; continue; }
        $find->execute([$orderNo]); $order = $find->fetch();
        if (!$order) { $res['result'] = '失败'; $res['message'] = '系统里没有这张订单（可能还没导入）'; $results[] = $res; continue; }
        if ($dry) { $results[] = $res; continue; }
        try {
            $money = []; $done = [];
            foreach ($pendingCells as $c => $v) {
                $kind = $p['kinds'][$c];
                if ($kind === 'phone') { if ($v !== '') pse_set_phone((int)$order['id'], $v, $actor); $done[] = $c; }
                elseif ($kind === 'domain') { if ($v !== '') pse_set_domain((int)$order['id'], $v, $actor); $done[] = $c; }
                elseif ($kind === 'server_expiry') { if ($v !== '') pse_set_server_expiry((int)$order['id'], $v, $actor); $done[] = $c; }
                elseif ($kind === 'amount') { if ($v !== '' && abs((float)$v - (float)$order['contract_amount']) > 0.004) $money['contract_amount'] = $v; $done[] = $c; }
                elseif ($kind === 'shop') { if ($v !== '' && $v !== (string)$order['shop']) $money['shop'] = $v; $done[] = $c; }
                elseif ($kind === 'nick') { if ($v !== '') $money['payment_nickname'] = $v; $done[] = $c; }
            }
            $msg = [];
            if ($money) {
                $r = pof_submit($actor, (int)$order['id'], $money, '在线编辑原始表格后提交更正');
                $msg[] = $r['mode'] === 'applied' ? '售价/店铺/昵称已更正' : '售价/店铺/昵称已提交财务确认';
                $res['result'] = $r['mode'] === 'applied' ? '已更正' : '已提交财务确认';
            } else $res['result'] = '已更正';
            if (array_intersect(array_map(function ($c) use ($p) { return $p['kinds'][$c]; }, $done), ['phone', 'domain', 'server_expiry'])) $msg[] = '手机号/域名/服务器到期日已写入续费资料';
            $res['message'] = implode('；', $msg);
            foreach ($done as $c) $mark->execute([(int)$file['id'], $sheet, $no, $c]);
        } catch (Throwable $e) {
            $res['result'] = '失败'; $res['message'] = $e instanceof RuntimeException ? $e->getMessage() : '处理失败，请重试';
        }
        $results[] = $res;
    }
    if (!$dry && $results) ps_audit('import_file', (int)$file['id'], 'sheet_correction', $actor, ['sheet' => $sheet, 'rows' => count($results), 'failed' => count(array_filter($results, function ($r) { return $r['result'] === '失败'; }))]);
    return $results;
}
