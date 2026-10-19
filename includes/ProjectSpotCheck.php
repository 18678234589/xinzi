<?php
/**
 * 财务抽查任务：免财务审核的业务（PA_SPOT_CHECK_BUSINESSES，见 ProjectAutoReviewMath.php）由系统自动结算，
 * 每个业务每月随机抽 PSC_PER_BUSINESS 单交给财务事后核对。任务在 project_spot_checks，处理页 project/spot_checks.php。
 * 提醒：每个财务页面顶部常驻提示（includes/spot_check_banner.php）、站内信（有员工账号的财务）、订单页提示。
 */
require_once __DIR__ . '/ProjectAutoReview.php';

const PSC_PER_BUSINESS = 5;

function psc_ensure()
{
    static $done = false;
    if ($done) return;
    try { db()->query('SELECT 1 FROM project_spot_checks LIMIT 0'); $done = true; return; }
    catch (PDOException $e) { if ((string)$e->getCode() !== '42S02') throw $e; }
    db()->exec("CREATE TABLE IF NOT EXISTS project_spot_checks (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      kind ENUM('order','refund') NOT NULL DEFAULT 'order',
      order_id BIGINT UNSIGNED NOT NULL, month CHAR(7) NOT NULL, business VARCHAR(60) NOT NULL,
      status ENUM('pending','ok','problem') NOT NULL DEFAULT 'pending', note VARCHAR(500) NOT NULL DEFAULT '',
      handled_by VARCHAR(60) NULL, handled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uk_item (kind, order_id), KEY idx_month (month, business, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

const PSC_REFUND_BUSINESS = '退款自动结案';

/** 给免审业务补足某月的抽查任务（每个业务共 PSC_PER_BUSINESS 单，已抽过的算在内）；返回新增条数。 */
function psc_assign($month)
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) return 0;
    psc_ensure();
    $pdo = db(); $added = 0; $byBusiness = [];
    $from = $month . '-01'; $until = date('Y-m-d', strtotime($from . ' +1 month'));
    $have = $pdo->prepare('SELECT COUNT(*) FROM project_spot_checks WHERE month=? AND business=?');
    $pick = $pdo->prepare("SELECT o.id FROM project_orders o JOIN project_auto_reviews a ON a.order_id=o.id LEFT JOIN project_spot_checks c ON c.kind='order' AND c.order_id=o.id
        WHERE o.project_type=? AND o.order_date>=? AND o.order_date<? AND a.state='spot_check' AND c.id IS NULL ORDER BY RAND() * IF(EXISTS (SELECT 1 FROM project_orders o2 JOIN project_participants p2 ON p2.order_id=o2.id WHERE o2.id<>o.id AND o2.project_type=o.project_type AND o2.order_date=o.order_date AND o2.contract_amount=o.contract_amount AND EXISTS (SELECT 1 FROM project_participants p1 WHERE p1.order_id=o.id AND p1.employee_id=p2.employee_id)), 0.25, 1) LIMIT ?");
    // 同一人、同业务、同一天、同金额还有别的订单（可能是重复导入，也可能真是两单）：抽中的几率约为普通订单的 4 倍，只提醒财务核对，不拦截
    $ins = $pdo->prepare("INSERT IGNORE INTO project_spot_checks (kind,order_id,month,business) VALUES ('order',?,?,?)");
    foreach (PA_SPOT_CHECK_BUSINESSES as $business) {
        $have->execute([$month, $business]);
        $need = PSC_PER_BUSINESS - (int)$have->fetchColumn();
        if ($need <= 0) continue;
        $pick->bindValue(1, $business); $pick->bindValue(2, $from); $pick->bindValue(3, $until); $pick->bindValue(4, $need, PDO::PARAM_INT);
        $pick->execute();
        foreach ($pick->fetchAll(PDO::FETCH_COLUMN) as $oid) {
            $ins->execute([(int)$oid, $month, $business]);
            if ($ins->rowCount()) { $added++; $byBusiness[$business] = ($byBusiness[$business] ?? 0) + 1; }
        }
    }
    // 系统自动结案（未报单）的退款：同样每月抽 5 条（order_id 存退款记录 id，kind='refund'）
    require_once __DIR__ . '/ProjectRefundMatch.php';
    $have->execute([$month, PSC_REFUND_BUSINESS]);
    $need = PSC_PER_BUSINESS - (int)$have->fetchColumn();
    if ($need > 0) {
        $pr = $pdo->prepare("SELECT r.id FROM project_refund_import_rows r LEFT JOIN project_spot_checks c ON c.kind='refund' AND c.order_id=r.id
            WHERE r.review_status='approved' AND r.order_id IS NULL AND r.reason LIKE ? AND r.reviewed_at>=? AND r.reviewed_at<? AND c.id IS NULL ORDER BY RAND() LIMIT " . (int)$need);
        $pr->execute(['%' . PRM_AUTO_TAG . '%', $from, $until]);
        $insRefund = $pdo->prepare("INSERT IGNORE INTO project_spot_checks (kind,order_id,month,business) VALUES ('refund',?,?,?)");
        foreach ($pr->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            $insRefund->execute([(int)$rid, $month, PSC_REFUND_BUSINESS]);
            if ($insRefund->rowCount()) { $added++; $byBusiness[PSC_REFUND_BUSINESS] = ($byBusiness[PSC_REFUND_BUSINESS] ?? 0) + 1; }
        }
    }
    if ($added) psc_notify($byBusiness);
    return $added;
}

/** 新任务站内信：发给能对应到员工的财务人员（财务账号本身没有收件箱的，靠页面顶部常驻提示）。 */
function psc_notify(array $byBusiness)
{
    try {
        if (!function_exists('ps_admin_reviewers')) require_once __DIR__ . '/ProjectSystem.php';
        $names = array_values(array_unique(array_map(function ($r) { return $r['real_name']; }, ps_admin_reviewers())));
        if (!$names) return;
        $q = db()->prepare('SELECT id FROM employees WHERE name IN (' . implode(',', array_fill(0, count($names), '?')) . ')');
        $q->execute($names);
        $text = implode('、', array_map(function ($b, $n) { return $b . ' ' . $n . ' 单'; }, array_keys($byBusiness), $byBusiness));
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')
                ->execute([(int)$eid, 'spot_check', '有新的自动结算订单待抽查', '系统自动结算的订单需要财务抽查：' . $text . '。打开订单核对收款、成本、交付，没问题点“无误”。', '/project/spot_checks.php', 'spot:' . date('YmdH') . ':' . (int)$eid]);
        }
    } catch (Throwable $e) {}
}

/** 待抽查任务总数（所有月份）。表还没建时返回 0。 */
function psc_pending_count()
{
    try { psc_ensure(); return (int)db()->query("SELECT COUNT(*) FROM project_spot_checks WHERE status='pending'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/** 待抽查任务按业务的条数，如 ['设计' => 5, '网站模板' => 3]。 */
function psc_pending_by_business()
{
    try { psc_ensure(); return db()->query("SELECT business,COUNT(*) FROM project_spot_checks WHERE status='pending' GROUP BY business ORDER BY business")->fetchAll(PDO::FETCH_KEY_PAIR); }
    catch (Throwable $e) { return []; }
}

/** 财务处理一条抽查：$do = ok（无误）/ problem（有问题，须写明情况）。 */
function psc_handle(array $actor, $id, $do, $note = '')
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('只有财务可以抽查');
    if (!in_array($do, ['ok', 'problem'], true)) throw new RuntimeException('操作无效');
    $note = trim((string)$note);
    if ($do === 'problem' && mb_strlen($note) < 3) throw new RuntimeException('有问题请写明情况');
    psc_ensure();
    $by = function_exists('ps_finance_username') ? (string)ps_finance_username($actor) : (string)($actor['username'] ?? '');
    $u = db()->prepare("UPDATE project_spot_checks SET status=?,note=?,handled_by=?,handled_at=NOW() WHERE id=? AND status='pending'");
    $u->execute([$do, mb_substr($note, 0, 500), $by, (int)$id]);
    if (!$u->rowCount()) throw new RuntimeException('这条抽查已处理或不存在');
    ps_audit('spot_check', (int)$id, $do, $actor, ['note' => $note]);
}
