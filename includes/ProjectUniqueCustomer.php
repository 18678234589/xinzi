<?php
/**
 * 按客户旺旺去重的单量补助（规则类型 unique_customer）：月度结果由 includes/monthly/types/unique_customer.php 调用 puc_analyze 计算。
 * 系统拿不准的旺旺（订单部分退款、旺旺与另一个很像）先不计，登记到 project_unique_customer_checks 提醒财务，
 * 财务在 project/customer_checks.php 标记“可以结算”（计入）或“无需结算”（不计）。
 */
const PUC_STATUS = ['pending' => ['待核对', 'warning'], 'pay' => ['已完成·可以结算', 'success'], 'skip' => ['无需结算', 'secondary']];

function puc_ensure()
{
    static $done = false;
    if ($done) return;
    try { db()->query('SELECT 1 FROM project_unique_customer_checks LIMIT 0'); $done = true; return; }
    catch (PDOException $e) { if ((string)$e->getCode() !== '42S02') throw $e; }
    db()->exec("CREATE TABLE IF NOT EXISTS project_unique_customer_checks (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      rule_id INT NOT NULL, month CHAR(7) NOT NULL, employee_id INT NOT NULL,
      nick_key VARCHAR(120) NOT NULL, nick_text VARCHAR(190) NOT NULL, reason VARCHAR(255) NOT NULL,
      status ENUM('pending','pay','skip') NOT NULL DEFAULT 'pending', note VARCHAR(500) NOT NULL DEFAULT '',
      handled_by VARCHAR(60) NULL, handled_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uk_item (rule_id, month, nick_key), KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function puc_key($nick)
{
    return mb_substr(preg_replace('/[\s\x{3000}\x{00A0}]+/u', '', mb_strtolower((string)$nick)), 0, 120);
}

function puc_similar($a, $b)
{
    if (mb_strlen($a) < 3 || mb_strlen($b) < 3) return false;
    if (mb_strpos($a, $b) !== false || mb_strpos($b, $a) !== false) return true;
    $x = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY); $y = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
    $m = count($x); $n = count($y);
    if (abs($m - $n) > 1) return false;
    $d = range(0, $n);
    for ($i = 1; $i <= $m; $i++) {
        $prev = $d; $d[0] = $i;
        for ($j = 1; $j <= $n; $j++) $d[$j] = min($prev[$j] + 1, $d[$j - 1] + 1, $prev[$j - 1] + ($x[$i - 1] === $y[$j - 1] ? 0 : 1));
    }
    return $d[$n] <= 1;
}

/** 范围内本人订单按旺旺归并：返回 ['customers' => [key => [text,paid,flag,...]], 'noNick' => n]；flag 非空 = 要财务核对。 */
function puc_analyze(array $rule, array $snapshots)
{
    $orderSnaps = [];
    foreach ($snapshots as $snap) {
        if (!ps_monthly_snapshot_matches($rule, $snap)) continue;
        $oid = (int)$snap['order_id'];
        $orderSnaps[$oid] = ($orderSnaps[$oid] ?? false) || (float)($snap['subsidy_amount'] ?? 0) > 0;
    }
    $customers = []; $noNick = 0;
    if (!$orderSnaps) return ['customers' => $customers, 'noNick' => 0];
    $q = db()->query('SELECT o.id,o.contract_amount,o.receipt_amount,o.refund_amount,s.payment_nickname FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.id IN (' . implode(',', array_keys($orderSnaps)) . ') ORDER BY o.id');
    foreach ($q->fetchAll() as $o) {
        $refund = (float)$o['refund_amount'];
        $net = ((float)$o['receipt_amount'] > 0 ? (float)$o['receipt_amount'] : (float)$o['contract_amount']) - $refund;
        if ($refund > 0 && $net <= 0) continue; // 全额退款：不计
        $key = puc_key($o['payment_nickname']);
        if ($key === '') { $noNick++; continue; }
        if (!isset($customers[$key])) $customers[$key] = ['text' => trim((string)$o['payment_nickname']), 'paid' => false, 'clean' => false, 'partial' => false, 'flag' => ''];
        $customers[$key]['paid'] = $customers[$key]['paid'] || $orderSnaps[(int)$o['id']];
        if ($refund > 0) $customers[$key]['partial'] = true; else $customers[$key]['clean'] = true;
    }
    $seen = [];
    foreach ($customers as $key => $c) {
        $flag = '';
        if (!$c['clean'] && $c['partial']) $flag = '订单有部分退款（客户仍有实付），是否还算一单请财务确认';
        elseif (!$c['paid']) foreach ($seen as $prevKey) if (puc_similar($prevKey, $key)) { $flag = '旺旺与「' . $customers[$prevKey]['text'] . '」很像，可能是同一客户，请财务确认'; break; }
        $customers[$key]['flag'] = $flag;
        $seen[] = $key;
    }
    return ['customers' => $customers, 'noNick' => $noNick];
}

function puc_decisions($ruleId, $month)
{
    try { puc_ensure(); $q = db()->prepare('SELECT nick_key,status FROM project_unique_customer_checks WHERE rule_id=? AND month=?'); $q->execute([(int)$ruleId, $month]); return $q->fetchAll(PDO::FETCH_KEY_PAIR); }
    catch (Throwable $e) { return []; }
}

/** 把本月要财务核对的旺旺登记成待办（已登记的不动）；返回新增条数。 */
function puc_sync($month)
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', (string)$month)) return 0;
    puc_ensure();
    $snapshots = null; $added = 0;
    $ins = db()->prepare('INSERT IGNORE INTO project_unique_customer_checks (rule_id,month,employee_id,nick_key,nick_text,reason) VALUES (?,?,?,?,?,?)');
    foreach (ps_monthly_rules_for($month) as $rule) {
        if ($rule['rule_type'] !== 'unique_customer' || $rule['employee_id'] === null) continue;
        if ($snapshots === null) $snapshots = ps_monthly_snapshots($month);
        foreach (puc_analyze($rule, $snapshots)['customers'] as $key => $c) {
            if ($c['flag'] === '') continue;
            $ins->execute([(int)$rule['id'], $month, (int)$rule['employee_id'], $key, mb_substr($c['text'], 0, 190), $c['flag']]);
            $added += $ins->rowCount();
        }
    }
    if ($added) puc_notify($added);
    return $added;
}

function puc_notify($n)
{
    try {
        if (!function_exists('ps_admin_reviewers')) require_once __DIR__ . '/ProjectSystem.php';
        $names = array_values(array_unique(array_map(function ($r) { return $r['real_name']; }, ps_admin_reviewers())));
        if (!$names) return;
        $q = db()->prepare('SELECT id FROM employees WHERE name IN (' . implode(',', array_fill(0, count($names), '?')) . ')');
        $q->execute($names);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')
                ->execute([(int)$eid, 'spot_check', '有单量补助的旺旺待核对', '有 ' . (int)$n . ' 个客户旺旺系统拿不准（部分退款或旺旺很像），请财务标记“可以结算”或“无需结算”。', '/project/customer_checks.php', 'puc:' . date('YmdH') . ':' . (int)$eid]);
        }
    } catch (Throwable $e) {}
}

function puc_pending_count()
{
    try { puc_ensure(); return (int)db()->query("SELECT COUNT(*) FROM project_unique_customer_checks WHERE status='pending'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/** 财务标记：$do = pay（已完成，可以结算）/ skip（无需结算）。 */
function puc_handle(array $actor, $id, $do, $note = '')
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('只有财务可以核对');
    if (!in_array($do, ['pay', 'skip'], true)) throw new RuntimeException('操作无效');
    puc_ensure();
    $by = function_exists('ps_finance_username') ? (string)ps_finance_username($actor) : (string)($actor['username'] ?? '');
    $u = db()->prepare('UPDATE project_unique_customer_checks SET status=?,note=?,handled_by=?,handled_at=NOW() WHERE id=?');
    $u->execute([$do, mb_substr(trim((string)$note), 0, 500), $by, (int)$id]);
    if (!$u->rowCount()) throw new RuntimeException('记录不存在或结果没有变化');
    ps_audit('unique_customer_check', (int)$id, $do, $actor, ['note' => trim((string)$note)]);
}
