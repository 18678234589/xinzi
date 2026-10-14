<?php
/**
 * 疑似重复订单：没有真实订单号的行（系统自己生成 WX- / EB- 内部号的），只要“同业务、同日期、同金额”，又没有明显不是同一位客户的迹象
 * （付款昵称都写了却不同、或域名都写了却不同），默认按同一订单处理——沿用已有订单，不再新建，避免同一笔业务算两次提成。
 * 业务负责人认为确实是不同订单：在“重复订单申诉”里写明理由并上传证据，财务通过后，这一行才允许作为独立订单导入。
 * 通过的申诉按“业务 + 日期 + 金额 + 昵称 + 域名”记住（pda_row_key），之后重传同一行不会再被拦。
 */
const PDA_EVIDENCE_EXT = ['png', 'jpg', 'jpeg', 'webp', 'pdf', 'xlsx', 'xls'];

function pda_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_dup_appeals (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      business VARCHAR(60) NOT NULL,
      row_key CHAR(40) NOT NULL,
      existing_order_id BIGINT UNSIGNED NOT NULL,
      existing_order_no VARCHAR(100) NOT NULL,
      order_date DATE NULL, amount DECIMAL(14,2) NULL,
      nickname VARCHAR(200) NOT NULL DEFAULT '', domain VARCHAR(200) NOT NULL DEFAULT '', shop VARCHAR(100) NOT NULL DEFAULT '',
      employee_id INT NOT NULL,
      reason TEXT NOT NULL,
      evidence_stored VARCHAR(120) NOT NULL DEFAULT '', evidence_name VARCHAR(200) NOT NULL DEFAULT '',
      status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      handled_by VARCHAR(80) NULL, handled_note VARCHAR(500) NULL, handled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uk_row (business, row_key),
      KEY idx_status (status, created_at), KEY idx_emp (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function pda_row_key($business, $date, $amount, $nickname, $domain)
{
    return sha1(mb_strtolower(trim((string)$business) . '|' . trim((string)$date) . '|' . number_format((float)$amount, 2, '.', '') . '|' . trim((string)$nickname) . '|' . trim((string)$domain)));
}

/** 这一行是否已有通过的申诉。返回申诉 id（0 = 没有）。 */
function pda_approved_id($business, $rowKey)
{
    try {
        pda_ensure();
        $q = db()->prepare("SELECT id FROM project_dup_appeals WHERE business=? AND row_key=? AND status='approved'");
        $q->execute([$business, $rowKey]);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/**
 * 找“同一订单”：同业务、同日期、同金额的已有订单（不含已删除 / 回收站），且不是明显另一位客户。
 * 恰好一张时返回它；多张时取付款昵称相同的；仍不唯一返回 null（不猜）。
 */
function pda_find_existing($business, $date, $amount, $nickname, $domain, $excludeOrderNo = '')
{
    if ($business === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) || (float)$amount <= 0) return null;
    $q = db()->prepare("SELECT o.id,o.order_no,o.settlement_status,o.shop,COALESCE(s.payment_nickname,'') nick,COALESCE(d.details_json,'') details FROM project_orders o
        LEFT JOIN project_order_sources s ON s.order_id=o.id LEFT JOIN project_order_details d ON d.order_id=o.id
        WHERE o.project_type=? AND o.order_date=? AND ABS(o.contract_amount-?)<0.005 AND o.order_no<>?");
    $q->execute([$business, $date, round((float)$amount, 2), $excludeOrderNo]);
    $nickname = trim((string)$nickname); $domain = mb_strtolower(trim((string)$domain));
    $same = [];
    foreach ($q->fetchAll() as $o) {
        $on = trim((string)$o['nick']);
        $od = mb_strtolower(trim((string)((json_decode($o['details'], true) ?: [])['domain_name'] ?? '')));
        if ($nickname !== '' && $on !== '' && mb_strtolower($nickname) !== mb_strtolower($on)) continue; // 付款昵称都写了却不同：不是同一位客户
        if ($domain !== '' && $od !== '' && $domain !== $od) continue;
        if ($nickname !== '' && $on === '' && !($domain !== '' && $domain === $od)) continue; // 已有订单没留昵称：除非域名一致，否则没有证据说明是同一位客户                                    // 域名都写了却不同：不是同一个站
        $same[] = $o + ['nick_equal' => $nickname !== '' && mb_strtolower($nickname) === mb_strtolower($on)];
    }
    if (count($same) === 1) return $same[0];
    $byNick = array_values(array_filter($same, function ($o) { return $o['nick_equal']; }));
    return count($byNick) === 1 ? $byNick[0] : null;
}

/**
 * 二次备案（没有金额）：同业务、同日期、同域名的已有二次备案订单，按出现顺序一一对应——表里第 N 次出现的“同日期同域名”行，对应库里第 N 张。
 * 库里已有的张数不够（表里这天这个域名写了更多行）时返回 null，多出来的行才作为新订单。
 */
function pda_find_existing_secondary($business, $date, $domain, $seq)
{
    $domain = mb_strtolower(trim((string)$domain));
    if ($business === '' || $domain === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) || $seq < 1) return null;
    $q = db()->prepare("SELECT o.id,o.order_no,o.settlement_status,COALESCE(d.details_json,'') details FROM project_orders o LEFT JOIN project_order_details d ON d.order_id=o.id WHERE o.project_type=? AND o.order_kind='二次备案' AND o.order_date=? ORDER BY o.id");
    $q->execute([$business, $date]);
    $same = [];
    foreach ($q->fetchAll() as $o) if (mb_strtolower(trim((string)((json_decode($o['details'], true) ?: [])['domain_name'] ?? ''))) === $domain) $same[] = $o;
    return $same[$seq - 1] ?? null;
}

/** 预览里给疑似重复行用的申诉入口链接。 */
function pda_appeal_url($business, array $existing, $date, $amount, $nickname, $domain, $shop)
{
    return BASE_URL . '/project/dup_appeals.php?' . http_build_query(['new' => 1, 'business' => $business, 'existing' => (int)$existing['id'], 'date' => $date, 'amount' => number_format((float)$amount, 2, '.', ''), 'nickname' => $nickname, 'domain' => $domain, 'shop' => $shop]);
}

function pda_submit(array $actor, array $in, array $file)
{
    pda_ensure();
    $eid = (int)($actor['employee_id'] ?? 0);
    if ($eid <= 0) throw new RuntimeException('只有业务账号可以提交申诉');
    $business = ps_business_normalize((string)($in['business'] ?? ''));
    $date = (string)($in['date'] ?? ''); $amount = (float)($in['amount'] ?? 0);
    $nickname = mb_substr(trim((string)($in['nickname'] ?? '')), 0, 200); $domain = mb_substr(trim((string)($in['domain'] ?? '')), 0, 200); $shop = mb_substr(trim((string)($in['shop'] ?? '')), 0, 100);
    $reason = trim((string)($in['reason'] ?? ''));
    $q = db()->prepare('SELECT id,order_no,project_type FROM project_orders WHERE id=?'); $q->execute([(int)($in['existing'] ?? 0)]); $existing = $q->fetch();
    if (!$existing || $existing['project_type'] !== $business) throw new RuntimeException('没有找到被判为重复的已有订单');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $amount < 0) throw new RuntimeException('日期或金额无效，请从导入预览里的链接进入');
    if (mb_strlen($reason) < 10) throw new RuntimeException('请写清楚为什么这是另一笔订单（至少 10 个字），例如：不同客户、各自独立的付款、各自备案不同域名');
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('请上传证明不是同一订单的证据（付款截图、聊天记录、合同等）');
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, PDA_EVIDENCE_EXT, true)) throw new RuntimeException('证据文件支持 ' . implode(' / ', PDA_EVIDENCE_EXT));
    if ((int)$file['size'] > 5 * 1024 * 1024) throw new RuntimeException('证据文件不能超过 5MB');
    $key = pda_row_key($business, $date, $amount, $nickname, $domain);
    $dup = db()->prepare('SELECT id,status FROM project_dup_appeals WHERE business=? AND row_key=?'); $dup->execute([$business, $key]); $old = $dup->fetch();
    if ($old && $old['status'] !== 'rejected') throw new RuntimeException($old['status'] === 'approved' ? '这一行的申诉已经通过，请直接重新上传表格' : '这一行已经提交过申诉，请等财务处理');
    $stored = ps_private_store('dup_appeals', $file['tmp_name'], bin2hex(random_bytes(16)) . '.' . $ext);
    if ($old) {
        db()->prepare("UPDATE project_dup_appeals SET existing_order_id=?,existing_order_no=?,employee_id=?,reason=?,evidence_stored=?,evidence_name=?,shop=?,status='pending',handled_by=NULL,handled_note=NULL,handled_at=NULL,created_at=NOW() WHERE id=?")
            ->execute([(int)$existing['id'], $existing['order_no'], $eid, $reason, $stored, mb_substr((string)$file['name'], 0, 200), $shop, (int)$old['id']]);
        $id = (int)$old['id'];
    } else {
        db()->prepare('INSERT INTO project_dup_appeals (business,row_key,existing_order_id,existing_order_no,order_date,amount,nickname,domain,shop,employee_id,reason,evidence_stored,evidence_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$business, $key, (int)$existing['id'], $existing['order_no'], $date, $amount, $nickname, $domain, $shop, $eid, $reason, $stored, mb_substr((string)$file['name'], 0, 200)]);
        $id = (int)db()->lastInsertId();
    }
    ps_audit('dup_appeal', $id, 'submit', $actor, ['business' => $business, 'existing' => $existing['order_no'], 'date' => $date, 'amount' => $amount]);
    return $id;
}

function pda_handle($id, array $actor, $decision, $note)
{
    if (($actor['type'] ?? '') !== 'admin') throw new RuntimeException('只有财务可以处理申诉');
    pda_ensure();
    if (!in_array($decision, ['approved', 'rejected'], true)) throw new RuntimeException('处理结果无效');
    $note = trim((string)$note);
    if ($decision === 'rejected' && mb_strlen($note) < 3) throw new RuntimeException('驳回请写明原因');
    $q = db()->prepare("SELECT * FROM project_dup_appeals WHERE id=? AND status='pending'"); $q->execute([(int)$id]); $row = $q->fetch();
    if (!$row) throw new RuntimeException('这条申诉不存在或已处理');
    $by = (string)db()->query('SELECT username FROM admins WHERE id=' . (int)$actor['id'])->fetchColumn();
    db()->prepare('UPDATE project_dup_appeals SET status=?,handled_by=?,handled_note=?,handled_at=NOW() WHERE id=?')->execute([$decision, $by, mb_substr($note, 0, 500), (int)$id]);
    ps_audit('dup_appeal', (int)$id, $decision === 'approved' ? 'approve' : 'reject', $actor, ['note' => $note, 'existing' => $row['existing_order_no']]);
    require_once __DIR__ . '/dup_feedback.php';
    pd_message((int)$row['employee_id'], $decision === 'approved' ? '重复订单申诉已通过，请重新上传表格' : '重复订单申诉被驳回',
        ($decision === 'approved' ? '这一行（' . $row['order_date'] . '，¥' . number_format((float)$row['amount'], 2) . '，' . ($row['nickname'] ?: $row['domain']) . '）已确认是独立订单。请把原表格重新上传一次，已有订单不会重复，这一行会作为新订单导入。' : '这一行仍按已有订单 ' . $row['existing_order_no'] . ' 处理。驳回原因：' . $note),
        '/project/dup_appeals.php?id=' . (int)$id, 'dup_appeal:' . (int)$id . ':' . $decision);
}

function pda_pending_count()
{
    try { pda_ensure(); return (int)db()->query("SELECT COUNT(*) FROM project_dup_appeals WHERE status='pending'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/** 当前账号看得到的申诉：财务全部，业务人员只看自己的。 */
function pda_list(array $actor)
{
    pda_ensure();
    if (($actor['type'] ?? '') === 'admin') return db()->query("SELECT a.*,e.name emp_name FROM project_dup_appeals a LEFT JOIN employees e ON e.id=a.employee_id ORDER BY FIELD(a.status,'pending','approved','rejected'), a.id DESC LIMIT 200")->fetchAll();
    $q = db()->prepare("SELECT a.*,e.name emp_name FROM project_dup_appeals a LEFT JOIN employees e ON e.id=a.employee_id WHERE a.employee_id=? ORDER BY FIELD(a.status,'pending','approved','rejected'), a.id DESC LIMIT 200");
    $q->execute([(int)($actor['employee_id'] ?? 0)]);
    return $q->fetchAll();
}
