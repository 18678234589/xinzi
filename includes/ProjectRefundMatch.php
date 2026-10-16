<?php
// 退款对号：退款表里只写了客户ID（淘宝昵称）、或写的是先前微信付款金额时，由系统给候选、售后/财务一键处理。
// 两种处理方式：deduct = 订单确实被退款，关联后按退款流程扣减；duplicate = 客户重复付款后退回多付的那笔，只留记录、不扣订单。

function prm_candidates($text)
{
    $text = trim((string)$text);
    if ($text === '' || mb_strlen($text) > 120) return [];
    $q = db()->prepare('SELECT o.id,o.order_no,o.customer_name,o.shop,o.contract_amount,o.receipt_amount,o.refund_amount,o.order_date,o.settlement_status FROM project_order_sources s JOIN project_orders o ON o.id=s.order_id WHERE s.payment_nickname=? GROUP BY o.id ORDER BY o.order_date DESC,o.id DESC LIMIT 6');
    $q->execute([$text]);
    return $q->fetchAll();
}

function prm_base($order)
{
    return (float)$order['receipt_amount'] > 0 ? (float)$order['receipt_amount'] : (float)$order['contract_amount'];
}

/** 退款金额与订单的关系，给人看的一句话提示。 */
function prm_hint($row, $order)
{
    $left = round(prm_base($order) - (float)$order['refund_amount'], 2);
    $amount = (float)$row['amount'];
    if ($amount > $left) return '退款 ¥' . number_format($amount, 2, '.', '') . ' 大于订单可退 ¥' . number_format($left, 2, '.', '') . '：可能是客户先付了一笔（如微信）又在店铺下单，退的是多付的那笔，请选“重复付款已退回”';
    if ($amount == $left) return '退款金额等于订单剩余金额，是整单退款';
    return '部分退款，订单按退款后的金额算分成';
}

function prm_pending_unmatched($limit = 60)
{
    $rows = db()->query("SELECT * FROM project_refund_import_rows WHERE review_status='pending' AND order_id IS NULL" . ps_refund_live_sql() . " ORDER BY refund_date DESC,id DESC LIMIT 800")->fetchAll();
    $keep = [];
    foreach ($rows as &$r) {
        $r['candidates'] = prm_candidates($r['order_no']);
        if (!$r['candidates'] && $r['source_payment_reference'] !== '') $r['candidates'] = prm_candidates($r['source_payment_reference']);
        // 订单号格式正常、只是订单还没上传的，归“等待订单上传”，不用人处理
        if (!$r['candidates'] && prm_looks_like_order_no($r['order_no'])) continue;
        $keep[] = $r;
        foreach ($r['candidates'] as &$c) $c['hint'] = prm_hint($r, $c);
        unset($c);
    }
    unset($r);
    return array_slice($keep, 0, max(1, min((int)$limit, 200)));
}

/** 人工处理一笔待对号的退款。售后和财务都可操作；全程留审计。 */
function prm_resolve($id, $orderNo, $mode, $note, $actor)
{
    if (!ps_refund_after_sales($actor)) throw new RuntimeException('仅售后和财务可以处理待对号退款');
    if (!in_array($mode, ['deduct', 'duplicate', 'unreported'], true)) throw new RuntimeException('请选择处理方式');
    $note = trim((string)$note);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_refund_import_rows WHERE id=? FOR UPDATE');
        $q->execute([(int)$id]); $row = $q->fetch();
        if (!$row || $row['review_status'] !== 'pending' || !empty($row['deleted_at'])) throw new RuntimeException('这笔退款已被处理或已移入回收站，请刷新页面');
        if ($mode === 'unreported') {
            // 客户付款后马上取消、客服没有报单：项目系统里本来就没有这张订单，没有分成可扣。只留可追溯的记录并结案，不再催办。
            $typed = trim((string)$orderNo);
            $reason = mb_substr(trim($row['reason'] . '；【未报单】客户付款后取消，客服未报单，系统无对应订单，未扣减分成（原写：' . $row['order_no'] . ($typed !== '' && $typed !== $row['order_no'] ? ' / 核对单号：' . $typed : '') . '）' . ($note !== '' ? '：' . $note : ''), '；'), 0, 300);
            $pdo->prepare("UPDATE project_refund_import_rows SET reason=?,review_status='approved',reviewed_by_admin=0,reviewed_at=NOW() WHERE id=?")->execute([$reason, (int)$id]);
            ps_audit('refund_import', (int)$id, 'match_unreported', $actor, ['raw_cell' => mb_substr((string)$row['order_no'], 0, 120), 'typed' => $typed, 'amount' => $row['amount'], 'note' => $note]);
            $pdo->commit();
            return '已记录为“客服未报单”退款 ¥' . number_format((float)$row['amount'], 2, '.', '') . '：系统里没有对应订单，不扣分成。以后该订单若补报，请财务到订单里单独登记这笔退款。';
        }
        $o = $pdo->prepare('SELECT * FROM project_orders WHERE order_no=? FOR UPDATE');
        $o->execute([trim((string)$orderNo)]); $order = $o->fetch();
        if (!$order) throw new RuntimeException('找不到订单号 ' . trim((string)$orderNo) . '。如果是客服没有报单、系统里确实没有这笔订单，请把处理方式改成“客服未报单：无对应订单”；否则请从候选里选或核对单号后再填');
        $raw = (string)$row['order_no'];
        if ($mode === 'deduct' && ps_refund_is_history($row['refund_date'])) throw new RuntimeException('这笔退款发生在 ' . substr($row['refund_date'], 0, 7) . '，该月工资已核算，不再自动扣减订单；如确需扣减请财务在审核里手动处理');
        if ($mode === 'duplicate') {
            $reason = mb_substr(trim($row['reason'] . '；重复付款退回（原写：' . $raw . '），未扣减订单' . ($note !== '' ? '：' . $note : ''), '；'), 0, 300);
            $pdo->prepare("UPDATE project_refund_import_rows SET order_id=?,order_no=?,reason=?,review_status='approved',reviewed_by_admin=0,reviewed_at=NOW() WHERE id=?")->execute([(int)$order['id'], $order['order_no'], $reason, (int)$id]);
            ps_audit('refund_import', (int)$id, 'match_duplicate', $actor, ['order_id' => (int)$order['id'], 'raw_cell' => mb_substr($raw, 0, 120), 'amount' => $row['amount'], 'note' => $note]);
            ps_audit('order', (int)$order['id'], 'refund_duplicate_payment', $actor, ['refund_id' => (int)$id, 'amount' => $row['amount'], 'note' => $note]);
        } else {
            $pdo->prepare('UPDATE project_refund_import_rows SET order_id=?,order_no=? WHERE id=?')->execute([(int)$order['id'], $order['order_no'], (int)$id]);
            ps_audit('refund_import', (int)$id, 'match_link', $actor, ['order_id' => (int)$order['id'], 'raw_cell' => mb_substr($raw, 0, 120), 'note' => $note]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if ($mode === 'duplicate') return '已记录为重复付款退回，订单 ' . $order['order_no'] . ' 的金额和分成不受影响';
    // 关联后立即按正常退款扣减；超出可退金额等情况保留关联，提示人工选择
    try {
        ps_refund_review((int)$id, 'approved', ps_refund_system_actor(), substr($row['refund_date'], 0, 7));
        return '已关联订单 ' . $order['order_no'] . ' 并扣减 ¥' . number_format((float)$row['amount'], 2, '.', '');
    } catch (RuntimeException $e) {
        return '已关联订单 ' . $order['order_no'] . '，但暂未扣减：' . $e->getMessage() . '（如是重复付款退回，请改选“重复付款已退回”）';
    }
}

/** 系统自动：写的是客户ID、该ID只对应一张订单、且退款金额在订单可退范围内时自动关联（金额对不上的留给人工）。 */
function prm_auto_nickname($system, $limit = 300)
{
    $linked = 0;
    $rows = db()->query("SELECT * FROM project_refund_import_rows WHERE review_status='pending' AND order_id IS NULL AND order_no<>''" . ps_refund_live_sql() . " ORDER BY id LIMIT " . max(1, min((int)$limit, 1000)))->fetchAll();
    foreach ($rows as $row) {
        $cands = prm_candidates($row['order_no']);
        if (count($cands) !== 1) continue;
        $order = $cands[0];
        if (in_array($order['settlement_status'], ['approved', 'locked'], true)) continue;
        if ((float)$row['amount'] > round(prm_base($order) - (float)$order['refund_amount'], 2)) continue;
        $u = db()->prepare("UPDATE project_refund_import_rows SET order_id=?,order_no=? WHERE id=? AND order_id IS NULL AND review_status='pending'" . prt_active_sql());
        $u->execute([(int)$order['id'], $order['order_no'], (int)$row['id']]);
        if ($u->rowCount()) { $linked++; ps_audit('refund_import', (int)$row['id'], 'auto_link_nickname', $system, ['order_id' => (int)$order['id'], 'raw_cell' => mb_substr((string)$row['order_no'], 0, 120)]); }
    }
    return $linked;
}

/** 退款表原文是否像一个订单号（订单号 / 平台单号 / 流水号）；写成客户名等不算。 */
function prm_looks_like_order_no($cell)
{
    $cell = trim((string)$cell);
    if (preg_match('/^(WX-[0-9A-Fa-f]{8,}|[A-Za-z]{0,3}[0-9]{9,}[A-Za-z]?)$/', $cell)) return true;
    return (bool)ps_refund_order_tokens($cell);
}

const PRM_AUTO_TAG = '【未报单·系统自动】';

/**
 * 自动结案：退款的订单号格式正常、店铺流水里有这笔订单、项目系统里却没有对应订单，且退款已过 $graceDays 天仍没人录订单——
 * 没有项目订单就没有分成可扣，系统自动记为“未报单”结案，不再占着待办（退款页不用人再点“标记未报单”）。
 * 之后订单若补录进来，prm_auto_reopen 会把它重新打开并自动扣减。流水里也没有的（可能是缺流水）不自动结案。
 */
function prm_auto_unreported($actor, $graceDays = 14, $limit = 200)
{
    if (!function_exists('ps_shop_order_lookup')) require_once __DIR__ . '/ProjectOrderSource.php';
    $rows = db()->query("SELECT * FROM project_refund_import_rows WHERE review_status='pending' AND order_id IS NULL AND refund_date<=DATE_SUB(CURDATE(), INTERVAL " . (int)$graceDays . " DAY)" . ps_refund_live_sql() . " ORDER BY refund_date,id LIMIT " . max(1, min((int)$limit, 500)))->fetchAll();
    $done = 0;
    foreach ($rows as $r) {
        if (!prm_looks_like_order_no($r['order_no']) || prm_candidates($r['order_no'])) continue;
        $inFlow = false;
        foreach (array_merge([trim($r['order_no'])], ps_refund_order_tokens($r['order_no'])) as $t) { try { if (ps_shop_order_lookup($t)) { $inFlow = true; break; } } catch (Throwable $e) {} }
        if (!$inFlow) continue;
        $reason = mb_substr(trim($r['reason'] . '；' . PRM_AUTO_TAG . '店铺流水里有该订单，项目系统里没有对应订单（' . $graceDays . ' 天内无人录入），无分成可扣（原写：' . $r['order_no'] . '）；订单补录后会自动重新打开并扣减', '；'), 0, 300);
        $u = db()->prepare("UPDATE project_refund_import_rows SET reason=?,review_status='approved',reviewed_by_admin=0,reviewed_at=NOW() WHERE id=? AND review_status='pending' AND order_id IS NULL");
        $u->execute([$reason, (int)$r['id']]);
        if ($u->rowCount()) { $done++; ps_audit('refund_import', (int)$r['id'], 'auto_unreported', $actor, ['order_no' => $r['order_no'], 'amount' => $r['amount']]); }
    }
    return $done;
}

/** 自动结案过的“未报单”退款，如果对应的项目订单后来录进来了：重新打开（待审、关联订单），由自动扣减接着处理。 */
function prm_auto_reopen($actor, $limit = 300)
{
    if (!function_exists('ps_refund_resolve_order')) require_once __DIR__ . '/ProjectRefundImport.php';
    $rows = db()->query("SELECT id,order_no,source_payment_reference,reason FROM project_refund_import_rows WHERE review_status='approved' AND order_id IS NULL AND reason LIKE '%" . PRM_AUTO_TAG . "%'" . prt_active_sql() . " ORDER BY id LIMIT " . max(1, min((int)$limit, 500)))->fetchAll();
    $n = 0;
    foreach ($rows as $r) {
        [$order] = ps_refund_resolve_order($r['order_no'], $r['source_payment_reference']);
        if (!$order) continue;
        $reason = trim(preg_replace('/；?' . preg_quote(PRM_AUTO_TAG, '/') . '[^；]*(?:；[^；]*)?$/u', '', (string)$r['reason']), '；');
        $u = db()->prepare("UPDATE project_refund_import_rows SET order_id=?,order_no=?,reason=?,review_status='pending',reviewed_by_admin=NULL,reviewed_at=NULL WHERE id=? AND review_status='approved' AND order_id IS NULL");
        $u->execute([(int)$order['id'], $order['order_no'], mb_substr($reason, 0, 300), (int)$r['id']]);
        if ($u->rowCount()) { $n++; ps_audit('refund_import', (int)$r['id'], 'auto_reopen', $actor, ['order_id' => (int)$order['id']]); }
    }
    return $n;
}

/** 退款表原文对应的店铺流水记录（店铺、买家昵称、商品标题、订单金额等）；流水里没有返回 null。 */
function prm_flow_entry($cell)
{
    if (!function_exists('ps_shop_order_lookup')) require_once __DIR__ . '/ProjectOrderSource.php';
    if (!function_exists('ps_refund_order_tokens')) require_once __DIR__ . '/ProjectRefundImport.php';
    foreach (array_merge([trim((string)$cell)], ps_refund_order_tokens($cell)) as $t) {
        try { foreach (ps_shop_order_lookup($t) as $m) { if ($m['shop'] !== '') return $m; } } catch (Throwable $e) {}
    }
    return null;
}

/** “等待订单上传”：退款对应的订单号格式正常，只是项目系统里还没有这张订单；按店铺归类，提醒对应业务补传。 */
function prm_waiting_upload($limit = 400)
{
    $rows = db()->query("SELECT * FROM project_refund_import_rows WHERE review_status='pending' AND order_id IS NULL" . ps_refund_live_sql() . " ORDER BY refund_date DESC,id DESC LIMIT " . max(1, min((int)$limit, 800)))->fetchAll();
    if (!function_exists('ps_shop_order_lookup')) require_once __DIR__ . '/ProjectOrderSource.php';
    $groups = [];
    foreach ($rows as $r) {
        if (!prm_looks_like_order_no($r['order_no']) || prm_candidates($r['order_no'])) continue;
        $flow = prm_flow_entry($r['order_no']);
        $shop = $flow ? $flow['shop'] : '';
        $key = $shop !== '' ? $shop : '店铺流水里还没有这笔订单（可能是更早月份下的单，请财务补传该店铺对应月份的店铺流水）';
        $groups[$key]['rows'][] = $r;
        $groups[$key]['amount'] = ($groups[$key]['amount'] ?? 0) + (float)$r['amount'];
    }
    uasort($groups, function ($a, $b) { return count($b['rows']) <=> count($a['rows']); });
    return $groups;
}

/**
 * 店铺售中退款自动同步：退款部表只记售后和微信/支付宝退款，买家在店铺里直接退（售中退款、交易关闭）只出现在店铺订单流水里。
 * 对已在项目里的订单，店铺流水退款额 > 项目已记退款额时，补记差额（渠道“店铺”）；未审核订单直接扣减，已审核订单只生成待财务处理的记录。
 * 订单已有待审的退款部记录、或退款日期落在已核算月份时不动，避免重复扣。$dryRun=true 只返回将处理的清单。
 */
function prm_shop_refund_sync($dryRun = false, $limit = 3000)
{
    if (!function_exists('ps_shop_order_lookup')) require_once __DIR__ . '/ProjectOrderSource.php';
    $system = ps_refund_system_actor();
    $out = ['checked' => 0, 'applied' => 0, 'queued' => 0, 'skipped_history' => 0, 'status_filled' => 0, 'items' => []];
    $orders = db()->query("SELECT o.*,COALESCE(s.trade_status,'') ts,(SELECT COUNT(*) FROM project_refund_import_rows r WHERE r.order_id=o.id AND r.review_status='pending') pend FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.order_date>=DATE_SUB(CURDATE(),INTERVAL 120 DAY) AND o.order_no REGEXP '^[0-9]{16,19}\$' ORDER BY o.id DESC LIMIT " . max(1, min((int)$limit, 6000)))->fetchAll();
    $dateQ = db()->prepare("SELECT MAX(order_date) FROM orders WHERE order_no=? AND employee_id=0 AND order_scope='department' AND COALESCE(is_deleted,0)=0 AND order_amount<0");
    $fpQ = db()->prepare('SELECT 1 FROM project_refund_import_rows WHERE fingerprint=?');
    foreach ($orders as $o) {
        $out['checked']++;
        $entry = null;
        foreach (ps_shop_order_lookup($o['order_no']) as $m) {
            if ($o['shop'] !== '' && $m['shop'] === $o['shop']) { $entry = $m; break; }
            if ($entry === null) $entry = $m;
        }
        if (!$entry) continue;
        if ($o['ts'] === '' && preg_match('/交易关闭/u', (string)$entry['status'])) {
            if (!$dryRun) db()->prepare("UPDATE project_order_sources SET trade_status='交易关闭' WHERE order_id=? AND trade_status=''")->execute([(int)$o['id']]);
            $out['status_filled']++;
        }
        $shopRefund = round((float)$entry['refund_amount'], 2);
        $delta = round($shopRefund - (float)$o['refund_amount'], 2);
        if ($shopRefund <= 0 || $delta <= 0 || (int)$o['pend'] > 0) continue;
        $refundDate = date('Y-m-d'); // 店铺流水没有可靠的退款日期：按同步日期记；订单下月才退的，在退款月补扣
        if (ps_refund_is_history($refundDate)) { $out['skipped_history']++; continue; }
        $fp = hash('sha256', 'shop-refund|' . $o['order_no'] . '|' . number_format($shopRefund, 2, '.', ''));
        $fpQ->execute([$fp]);
        if ($fpQ->fetchColumn()) continue;
        $item = ['order_no' => $o['order_no'], 'price' => $o['contract_amount'], 'shop_refund' => $shopRefund, 'delta' => $delta, 'date' => $refundDate, 'status' => $o['settlement_status']];
        $out['items'][] = $item;
        if ($dryRun) continue;
        db()->prepare("INSERT INTO project_refund_import_rows (fingerprint,order_id,order_no,refund_date,amount,payment_method,source_payment_reference,payment_reference,reason,import_file_id,source_sheet,source_row,review_status,submitted_by_type,submitted_by_id) VALUES (?,?,?,?,?,'店铺','','',?,NULL,'',0,'pending','system',0)")
            ->execute([$fp, (int)$o['id'], $o['order_no'], $refundDate, $delta, '店铺售中退款（自动同步店铺流水，店铺退款合计 ¥' . number_format($shopRefund, 2, '.', '') . '）']);
        $rid = (int)db()->lastInsertId();
        ps_audit('refund_import', $rid, 'shop_sync', $system, ['order_id' => (int)$o['id'], 'shop_refund' => $shopRefund, 'delta' => $delta]);
        if (in_array($o['settlement_status'], ['approved', 'locked'], true)) { $out['queued']++; continue; }
        try { ps_refund_review($rid, 'approved', $system, substr($refundDate, 0, 7)); $out['applied']++; } catch (RuntimeException $e) { $out['queued']++; }
    }
    return $out;
}
