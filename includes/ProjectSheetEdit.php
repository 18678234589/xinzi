<?php
/**
 * 原始表格在线编辑（像 Excel 一样改，自动保存，提交后更正自己的订单）。
 * - 原始上传文件永不改动；编辑内容单独存 project_import_edits（只存与原表不同的格子），随时可恢复。
 * - 原表全部字段可编辑；核对导入读取保存后的表格，原始附件保持不变。
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
    try {
        db()->query('SELECT 1 FROM project_import_edits LIMIT 0');
        $done = true;
        return;
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '42S02') throw $e;
    }
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
function pse_parse($file, $sheetName, $actor, $sheets = null)
{
    if ($sheets === null) $sheets = ps_import_file_sheets($file);
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
        if (preg_match('/^域名归属/u', $label)) $kinds[$i] = 'domain_owner';
        elseif (preg_match('/服务器.*(到期|有效期)|(到期|有效期).*服务器/u', $label) && !preg_match('/域名/u', $label)) $kinds[$i] = 'server_expiry';
        elseif (preg_match('/海外.*微信|^客户微信号$|^微信号$/u', $label) && !preg_match('/客服/u', $label)) $kinds[$i] = 'wechat';
        elseif (preg_match('/^(客户)?(手机号?码?|电话|联系电话|联系方式)$/u', $label)) $kinds[$i] = 'phone'; // 只认专门的手机号列；“备注（写客户电话或者微信）”常写微信号，不当手机号
        elseif (in_array($label, ['域名', '域名地址', '网站域名', '客户域名'], true)) $kinds[$i] = 'domain';
        elseif (preg_match('/日期|时间/u', $label)) $kinds[$i] = 'date';
    }
    // 虚拟列：模板里没有、但本岗位需要的列（客户手机号 / 客户域名）
    $virtual = 0;
    // 保存后固定补充列的含义，财务代导与上传人看到同一布局。
    pse_ensure();
    $layoutQuery = db()->prepare('SELECT value FROM project_import_edits WHERE file_id=? AND sheet=? AND row_no=0 AND col_idx=0');
    $layoutQuery->execute([(int)$file['id'], (string)$sheetName]);
    $layout = json_decode((string)($layoutQuery->fetchColumn() ?: ''), true);
    if (!$layout) {
        $legacy = db()->prepare('SELECT updated_by_type,updated_by_id FROM project_import_edits WHERE file_id=? AND sheet=? AND row_no>0 AND col_idx>=? ORDER BY updated_at DESC LIMIT 1');
        $legacy->execute([(int)$file['id'], (string)$sheetName, $width]); $writer = $legacy->fetch();
        if ($writer && $writer['updated_by_type'] === 'admin') $actor = ['role' => 'finance'];
        elseif ($writer) {
            $roleQuery = db()->prepare('SELECT role FROM project_users WHERE id=?'); $roleQuery->execute([(int)$writer['updated_by_id']]);
            $writerRole = $roleQuery->fetchColumn(); if ($writerRole) $actor = ['role' => $writerRole];
        }
    }
    $extras = ps_import_role_extras((string)$file['business_name'], $actor, $head);
    if (!$extras && ($actor['role'] ?? '') === 'finance') {
        // 财务查看 / 更正：网站类订单补手机号 + 域名，小程序补手机号 + 服务器到期日（已有同名列则不重复）
        $biz = (string)$file['business_name'];
        $want = $biz === '小程序开发' ? ['客户手机号', '海外客户微信号', '服务器到期日'] : (in_array($biz, ['AI网站定制', '网站模板', '网站续费', '网站修改', '备案-提成'], true) ? ['客户手机号', '海外客户微信号', '客户域名', '域名归属'] : []);
        foreach ($want as $w) {
            if ($w === '海外客户微信号') {
                if (!in_array('wechat', $kinds, true)) $extras[] = $w;
                continue;
            }
            $has = false;
            foreach ($head as $h) { $l = preg_replace('/[\s（）()：:]/u', '', (string)$h); if ($w === '域名归属' ? preg_match('/^域名归属/u', $l) : ($w === '客户手机号' ? preg_match('/^(客户)?(手机号?码?|电话|联系电话|联系方式)$/u', $l) : ($w === '客户域名' ? in_array($l, ['域名', '域名地址', '网站域名', '客户域名'], true) : preg_match('/服务器.*(到期|有效期)/u', $l)))) $has = true; }
            if (!$has) $extras[] = $w;
        }
    }
    if (is_array($layout) && array_slice($layout, 0, $width) === $head) $extras = array_slice($layout, $width);
    foreach ($extras as $extra) {
        $head[] = $extra; $kinds[] = $extra === '客户手机号' ? 'phone' : ($extra === '海外客户微信号' ? 'wechat' : ($extra === '服务器到期日' ? 'server_expiry' : ($extra === '域名归属' ? 'domain_owner' : 'domain'))); $virtual++;
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

function pse_editable_kind($kind) { return in_array($kind, ['phone', 'wechat', 'domain', 'domain_owner', 'server_expiry', 'amount', 'shop', 'nick'], true); }

/** 全部原表列可修改；文件权限仍由 ps_import_file_get 校验。 */
function pse_can_edit($file, $actor)
{
    return ($actor['role'] ?? '') === 'finance' || ps_management_can_business($actor, $file['business_name']) || ($file['uploaded_by_type'] === ($actor['type'] ?? '') && (int)$file['employee_id'] === (int)($actor['employee_id'] ?? 0));
}

/** 导入时读取编辑后的数据，包括补充列；原始文件和源表行号不变。 */
function pse_import_sheets($file, $actor)
{
    $revision = pse_revision((int)$file['id']);
    $sheets = ps_import_file_sheets($file);
    foreach ($sheets as $name => &$rows) {
        $p = pse_parse($file, (string)$name, $actor, $sheets);
        if (!$rows) continue;
        $rows = array_values(array_filter($rows, 'is_array'));
        $rows[0] = $p['head'];
        foreach (pse_overlay((int)$file['id'], (string)$name) as $no => $cells) {
            if ($no < 1 || !isset($rows[$no])) continue;
            foreach ($cells as $col => $edit) if ($col >= 0 && $col < $p['width']) $rows[$no][$col] = $edit['value'];
            ksort($rows[$no]);
            $rows[$no] = array_replace(array_fill(0, $p['width'], ''), $rows[$no]);
        }
    }
    unset($rows);
    if ($revision !== pse_revision((int)$file['id'])) throw new RuntimeException('表格正在被修改，请保存完成后重新核对');
    $_SESSION['pse_import_revisions'][(int)$file['id']] = $revision;
    return $sheets;
}

/** 防止核对后又编辑表格却提交旧预览；提交事务内锁住该文件的编辑记录。 */
function pse_revision($fileId, $lock = false)
{
    pse_ensure();
    $q = db()->prepare('SELECT sheet,row_no,col_idx,value FROM project_import_edits WHERE file_id=? ORDER BY sheet,row_no,col_idx' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([(int)$fileId]);
    return hash('sha256', json_encode($q->fetchAll(PDO::FETCH_NUM), JSON_UNESCAPED_UNICODE));
}

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
    $editable = array_fill(0, $p['width'], true);
    // 缓存供自动保存校验（避免每次保存都重新解析整张表）
    $orig = [];
    foreach ($p['rows'] as $no => $cells) $orig[$no] = $cells;
    $_SESSION['pse_cache'][(int)$file['id'] . '|' . $sheet] = ['head' => $p['head'], 'kinds' => $p['kinds'], 'width' => $p['width'], 'orig' => $orig];
    return ['head' => $p['head'], 'kinds' => $p['kinds'], 'editable' => $editable, 'canEdit' => pse_can_edit($file, $actor), 'rows' => $rows, 'rowNos' => $rowNos, 'orderCol' => $p['orderCol'], 'virtual' => $p['virtual'], 'truncated' => $p['truncated'], 'pending' => $pending, 'changed' => $changed,
            'file' => ['id' => (int)$file['id'], 'name' => $file['original_name'], 'business' => $file['business_name']]];
}

/** 自动保存：edits = [['row'=>源表行号,'col'=>列,'v'=>值]…]。与原值相同则撤销该格编辑。 */
function pse_save($fileId, $sheet, array $edits, $actor)
{
    $file = pse_file($fileId, $actor);
    if (!pse_can_edit($file, $actor)) throw new RuntimeException('没有编辑这份表格的权限');
    $key = (int)$file['id'] . '|' . $sheet;
    $cache = $_SESSION['pse_cache'][$key] ?? null;
    if (!$cache || !isset($cache['head']) || count($cache['orig'] ? reset($cache['orig']) : []) !== (int)$cache['width']) {
        $p = pse_parse($file, $sheet, $actor);
        $orig = [];
        foreach ($p['rows'] as $no => $cells) $orig[$no] = $cells;
        $cache = ['head' => $p['head'], 'kinds' => $p['kinds'], 'width' => $p['width'], 'orig' => $orig];
        $_SESSION['pse_cache'][$key] = $cache;
    }
    pse_ensure();
    $up = db()->prepare('INSERT INTO project_import_edits (file_id,sheet,row_no,col_idx,value,updated_by_type,updated_by_id) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_by_type=VALUES(updated_by_type),updated_by_id=VALUES(updated_by_id)');
    $del = db()->prepare('DELETE FROM project_import_edits WHERE file_id=? AND sheet=? AND row_no=? AND col_idx=?');
    $saved = 0;
    if (count($edits) > 500) throw new RuntimeException('单次最多保存 500 个单元格，请分批提交');
    foreach ($edits as $e) {
        $row = (int)($e['row'] ?? 0); $col = (int)($e['col'] ?? -1); $v = trim((string)($e['v'] ?? ''));
        if ($row < 1 || $col < 0 || $col >= $cache['width'] || !array_key_exists($row, $cache['orig'])) throw new RuntimeException('单元格位置无效，请重新载入表格');
        if (mb_strlen($v) > 10000) throw new RuntimeException('单元格内容超过 10000 字，请缩短后保存');
    }
    $pdo = db(); $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT sheet_edit_save'); else $pdo->beginTransaction();
    try {
      $lock = $pdo->prepare('SELECT id FROM project_import_files WHERE id=? FOR UPDATE'); $lock->execute([(int)$file['id']]);
      if (!$lock->fetchColumn()) throw new RuntimeException('表格已删除，请返回列表');
      $layoutQuery = $pdo->prepare('SELECT value FROM project_import_edits WHERE file_id=? AND sheet=? AND row_no=0 AND col_idx=0'); $layoutQuery->execute([(int)$file['id'], $sheet]);
      $existingHead = $layoutQuery->fetchColumn(); $headJson = json_encode($cache['head'], JSON_UNESCAPED_UNICODE);
      if ($existingHead !== false && $existingHead !== $headJson) throw new RuntimeException('表格列布局已更新，请重新载入后编辑');
      if ($existingHead === false) $pdo->prepare('INSERT INTO project_import_edits (file_id,sheet,row_no,col_idx,value,applied_value,updated_by_type,updated_by_id) VALUES (?,?,0,0,?,?,?,?)')->execute([(int)$file['id'], $sheet, $headJson, $headJson, $actor['type'], (int)$actor['id']]);
      foreach ($edits as $e) {
        $row = (int)$e['row']; $col = (int)$e['col']; $v = trim((string)($e['v'] ?? ''));
        if ($v === (string)($cache['orig'][$row][$col] ?? '')) $del->execute([(int)$file['id'], $sheet, $row, $col]);
        else $up->execute([(int)$file['id'], $sheet, $row, $col, $v, $actor['type'], (int)$actor['id']]);
        $saved++;
      }
      if ($nested) $pdo->exec('RELEASE SAVEPOINT sheet_edit_save'); else $pdo->commit();
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT sheet_edit_save'); elseif ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
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

/** 原有在线更正入口仍可修改微信号；批量补全入口另行坚持只填空白。 */
function pse_set_wechat($orderId, $wechat, $actor)
{
    $wechat = pr_wechat($wechat);
    if ($wechat === '') throw new RuntimeException('微信号为空');
    if (!pr_ready()) throw new RuntimeException('续费资料暂时无法保存');
    pr_order($orderId, $actor);
    $hash = hash_hmac('sha256', mb_strtolower($wechat), pv_key());
    $q = db()->prepare("SELECT id,wechat_hash FROM project_renewal_items WHERE order_id=? AND status<>'closed'");
    $q->execute([(int)$orderId]); $rows = $q->fetchAll();
    if (!$rows) { pr_import_apply((int)$orderId, ['wechat'=>$wechat, 'resources'=>[]], $actor); return; }
    foreach ($rows as $r) {
        if (($r['wechat_hash']??'') === $hash) continue;
        db()->prepare('UPDATE project_renewal_items SET wechat_cipher=?,wechat_hash=?,revision=revision+1,updated_at=NOW() WHERE id=?')->execute([pv_encrypt($wechat), $hash, (int)$r['id']]);
        ps_audit('renewal', (int)$r['id'], 'sheet_wechat', $actor, ['order_id'=>(int)$orderId]);
    }
}

/** 单元格文字 → [归属, 备注]。“客户自有” / “客户自有：已交付源码” / “我们代管”。 */
function pse_parse_owner($v)
{
    $v = trim((string)$v);
    if (preg_match('/^(客户自有|客户自备|客户|自有|自备)/u', $v)) {
        $note = trim(preg_replace('/^(客户自有|客户自备|客户|自有|自备)[:：\s-]*/u', '', $v));
        return ['customer', $note !== '' ? $note : '表格标注为客户自有，需核对'];
    }
    if (preg_match('/^(我们|代管|公司|我方)/u', $v)) return ['ours', ''];
    throw new RuntimeException('域名归属请填“我们代管”或“客户自有”（可写“客户自有：已交付源码”）');
}

/** 设置订单域名的归属；没有域名条目时：客户自有会新建一条占位条目，我们代管则无需处理。 */
function pse_set_domain_owner($orderId, $owner, $note, $actor)
{
    pr_order($orderId, $actor);
    $pdo = db();
    $q = $pdo->prepare("SELECT id FROM project_renewal_items WHERE order_id=? AND resource_type='domain' ORDER BY (status<>'closed') DESC,id LIMIT 1");
    $q->execute([(int)$orderId]);
    $itemId = (int)$q->fetchColumn();
    if (!$itemId) {
        if ($owner !== 'customer') return;
        $pdo->prepare("INSERT INTO project_renewal_items (order_id,resource_type,resource_name,expiry_source,status) VALUES (?,'domain','客户自备域名','estimated','active')")->execute([(int)$orderId]);
        $itemId = (int)$pdo->lastInsertId();
    }
    pr_set_owner($itemId, $owner, $note, $actor);
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
                elseif ($kind === 'wechat') { if ($v !== '') pse_set_wechat((int)$order['id'], $v, $actor); $done[] = $c; }
                elseif ($kind === 'domain') { if ($v !== '') pse_set_domain((int)$order['id'], $v, $actor); $done[] = $c; }
                elseif ($kind === 'domain_owner') { if ($v !== '') { [$ow, $ownNote] = pse_parse_owner($v); pse_set_domain_owner((int)$order['id'], $ow, $ownNote, $actor); } $done[] = $c; }
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
            if (array_intersect(array_map(function ($c) use ($p) { return $p['kinds'][$c]; }, $done), ['phone', 'wechat', 'domain', 'domain_owner', 'server_expiry'])) $msg[] = '联系方式/域名/域名归属/服务器到期日已写入续费资料';
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
