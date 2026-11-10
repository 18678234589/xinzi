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
        if (prm_is_partial_refund($r, prm_flow_entry($r['order_no']))) continue; // 部分退款：订单应该在项目系统里，不当成未报单结案
        $reason = mb_substr(trim($r['reason'] . '；' . PRM_AUTO_TAG . '店铺流水里有该订单，项目系统里没有对应订单（' . $graceDays . ' 天内无人录入），无分成可扣（原写：' . $r['order_no'] . '）；订单补录后会自动重新打开并扣减', '；'), 0, 300);
        $u = db()->prepare("UPDATE project_refund_import_rows SET reason=?,review_status='approved',reviewed_by_admin=0,reviewed_at=NOW() WHERE id=? AND review_status='pending' AND order_id IS NULL");
        $u->execute([$reason, (int)$r['id']]);
        if ($u->rowCount()) { $done++; ps_audit('refund_import', (int)$r['id'], 'auto_unreported', $actor, ['order_no' => $r['order_no'], 'amount' => $r['amount']]); }
    }
    return $done;
}

/**
 * 已核算月份（含）以前的退款只留档、不自动扣减。其中项目系统里根本没有对应订单的（无分成可扣、也没有订单会被它拦住），
 * 留在“待财务审核”只会堆成噪音：自动记为留档结案，原写订单号保留在备注里；订单之后补录进来会由 prm_auto_reopen 重新打开。
 * 已经对上订单的历史退款不在这里处理（财务要看一眼，它们会拦住对应订单的自动通过）。
 */
function prm_auto_archive_history($actor, $limit = 300)
{
    if (!function_exists('ps_refund_settled_through')) require_once __DIR__ . '/ProjectRefundImport.php';
    $m = ps_refund_settled_through();
    prm_reopen_archived($actor, $m);
    if ($m === '') return 0;
    $until = date('Y-m-t', strtotime($m . '-01'));
    $rows = db()->query("SELECT * FROM project_refund_import_rows WHERE review_status='pending' AND order_id IS NULL AND refund_date<='" . $until . "'" . prt_active_sql() . " ORDER BY id LIMIT " . max(1, min((int)$limit, 1000)))->fetchAll();
    $done = 0;
    foreach ($rows as $r) {
        if (trim((string)$r['order_no']) === '' && trim((string)($r['source_payment_reference'] ?? '')) === '') continue;
        if (prm_candidates($r['order_no'])) continue;
        [$order] = ps_refund_resolve_order($r['order_no'], $r['source_payment_reference'] ?? '');
        if ($order) continue;
        // 只结案“整单退完”的：部分退款说明订单还在、客服应该报过单，没对上是要查的问题，不能当成没报单悄悄结案
        $flow = prm_flow_entry($r['order_no']);
        if (!$flow || prm_is_partial_refund($r, $flow)) continue;
        $reason = mb_substr(trim($r['reason'] . '；' . PRM_AUTO_TAG . '退款日期在已核算月份（' . $m . '）及以前，只留档；项目系统里没有对应订单，无分成可扣（原写：' . $r['order_no'] . '）；订单之后补录会自动重新打开'), 0, 300);
        $u = db()->prepare("UPDATE project_refund_import_rows SET reason=?,review_status='approved',reviewed_by_admin=0,reviewed_at=NOW() WHERE id=? AND review_status='pending' AND order_id IS NULL");
        $u->execute([$reason, (int)$r['id']]);
        if ($u->rowCount()) { $done++; ps_audit('refund_import', (int)$r['id'], 'auto_archive_history', $actor, ['order_no' => $r['order_no'], 'amount' => $r['amount'], 'settled_through' => $m]); }
    }
    return $done;
}

/** 退款金额小于店铺流水里该订单的金额 = 部分退款（订单还在继续服务，项目系统里应该有这张订单）。 */
function prm_is_partial_refund(array $refundRow, $flow)
{
    if (mb_strpos((string)($refundRow['reason'] ?? ''), PRM_SMALL_TAG) !== false) return false; // 小额返款不是原订单的部分退款
    $price = is_array($flow) ? (float)($flow['price'] ?? 0) : 0.0;
    return $price > 0 && (float)$refundRow['amount'] + 0.004 < $price;
}

/**
 * 小额返款（客服给小额引流客户返的 ¥10 左右）：退款表里的订单号是客户原来的订单，不是小额引流订单，对不上；
 * 小额返款以售后上传的退款表为准直接认定（客服不需要报小额引流订单），没有订单就没有分成可扣，自动结案并写明原因。
 * 之后客服补报了小额引流订单，财务可以在退款页手工对号；退款能按订单号对上项目订单的，不在这里处理。
 */
function prm_auto_close_small($actor, $limit = 300)
{
    if (!function_exists('ps_refund_resolve_order')) require_once __DIR__ . '/ProjectRefundImport.php';
    $rows = db()->query("SELECT * FROM project_refund_import_rows WHERE review_status='pending' AND order_id IS NULL AND reason LIKE '%" . PRM_SMALL_TAG . "%' AND refund_date<=CURDATE()" . prt_active_sql() . " ORDER BY id LIMIT " . max(1, min((int)$limit, 1000)))->fetchAll();
    $done = 0;
    foreach ($rows as $r) {
        [$order] = ps_refund_resolve_order($r['order_no'], $r['source_payment_reference'] ?? '');
        if ($order) continue;
        $reason = mb_substr(trim($r['reason'] . '；' . PRM_AUTO_TAG . '售后上传的小额返款，直接认定；客服不需要报小额引流订单，无分成可扣（原写：' . $r['order_no'] . '）'), 0, 300);
        $u = db()->prepare("UPDATE project_refund_import_rows SET reason=?,review_status='approved',reviewed_by_admin=0,reviewed_at=NOW() WHERE id=? AND review_status='pending' AND order_id IS NULL");
        $u->execute([$reason, (int)$r['id']]);
        if ($u->rowCount()) { $done++; ps_audit('refund_import', (int)$r['id'], 'auto_close_small', $actor, ['order_no' => $r['order_no'], 'amount' => $r['amount']]); }
    }
    return $done;
}

/** 把之前按“已核算月份”留档结案、但现在不该结案的退款重新打开：退款日期已晚于已核算月份（月份设置改过），或是部分退款。 */
function prm_reopen_archived($actor, $settledThrough)
{
    $rows = db()->query("SELECT id,order_no,amount,refund_date,reason FROM project_refund_import_rows WHERE review_status='approved' AND order_id IS NULL AND reason LIKE '%" . PRM_AUTO_TAG . "退款日期在已核算月份%'" . prt_active_sql() . " LIMIT 1000")->fetchAll();
    $until = $settledThrough !== '' ? date('Y-m-t', strtotime($settledThrough . '-01')) : '';
    $n = 0;
    foreach ($rows as $r) {
        $live = $until === '' || $r['refund_date'] > $until;
        if (!$live && !prm_is_partial_refund($r, prm_flow_entry($r['order_no']))) continue;
        $pos = mb_strpos($r['reason'], PRM_AUTO_TAG);
        $reason = trim($pos === false ? $r['reason'] : mb_substr($r['reason'], 0, $pos), '；');
        $u = db()->prepare("UPDATE project_refund_import_rows SET reason=?,review_status='pending',reviewed_by_admin=NULL,reviewed_at=NULL WHERE id=? AND review_status='approved' AND order_id IS NULL");
        $u->execute([mb_substr($reason, 0, 300), (int)$r['id']]);
        if ($u->rowCount()) { $n++; ps_audit('refund_import', (int)$r['id'], 'reopen_archived', $actor, ['reason' => $live ? 'month_live' : 'partial_refund']); }
    }
    return $n;
}

/** 自动结案过的“未报单”退款，如果对应的项目订单后来录进来了：重新打开（待审、关联订单），由自动扣减接着处理。 */
function prm_auto_reopen($actor, $limit = 300)
{
    if (!function_exists('ps_refund_resolve_order')) require_once __DIR__ . '/ProjectRefundImport.php';
    $rows = db()->query("SELECT id,order_no,source_payment_reference,reason FROM project_refund_import_rows WHERE review_status='approved' AND order_id IS NULL AND reason LIKE '%" . PRM_AUTO_TAG . "%' AND reason NOT LIKE '%" . PRM_SMALL_TAG . "%'" . prt_active_sql() . " ORDER BY id LIMIT " . max(1, min((int)$limit, 500)))->fetchAll();
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

/** 按店铺流水的商品标题推测业务类别。网站类标题（建站 / 模板 / 定制）在多个业务里通用，只能给出范围；认不出返回空串。 */
function prm_title_category($title)
{
    $title = (string)$title;
    if (preg_match('/代写|文章|论文|征文|文案|演讲稿/u', $title)) return '软文代写';
    if (preg_match('/服务器|ECS|环境配置|PHP\/MySQL/u', $title)) return '环境配置';
    if (preg_match('/小程序/u', $title)) return '小程序开发';
    if (preg_match('/网站|建站|模板|wordpress/iu', $title)) return '网站类（网站模板 / AI网站定制 / 网站续费 / 网站修改，同一商品标题无法区分）';
    return '';
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

/** 退款原表里该行的某列内容（按表头关键词匹配）：读不到返回 ''。 */
function prm_source_cell(array $refundRow, array $headerKeywords)
{
    if (empty($refundRow['import_file_id']) || empty($refundRow['source_row'])) return '';
    try {
        if (!function_exists('ps_import_file_get')) require_once __DIR__ . '/ProjectImportFiles.php';
        $f = ps_import_file_get((int)$refundRow['import_file_id'], ['type' => 'admin', 'id' => 0, 'role' => 'finance', 'employee_id' => null]);
        foreach (ps_import_file_sheets($f) as $sheet => $grid) {
            if ((string)$sheet !== (string)($refundRow['source_sheet'] ?? $sheet) && ($refundRow['source_sheet'] ?? '') !== '') continue;
            $head = (array)($grid[0] ?? []); $col = null;
            foreach ($head as $i => $h) {
                $hStr = trim((string)$h);
                foreach ($headerKeywords as $kw) {
                    if (mb_strpos($hStr, $kw) !== false) { $col = $i; break 2; }
                }
            }
            if ($col === null) continue;
            return trim((string)(($grid[(int)$refundRow['source_row'] - 1] ?? [])[$col] ?? ''));
        }
    } catch (Throwable $e) {}
    return '';
}

/** 退款原表里该行的“接单客服”（退款表常有这一列）：返回 [员工id, 姓名]；读不到或对不上返回 [0, '']。 */
function prm_source_cs(array $refundRow)
{
    $name = prm_source_cell($refundRow, ['接单客服', '客服', '接单人']);
    if ($name === '') return [0, ''];
    try {
        $q = db()->prepare('SELECT id,name FROM employees WHERE name=? ORDER BY id LIMIT 2');
        $q->execute([$name]);
        $m = $q->fetchAll();
        if (count($m) === 1) return [(int)$m[0]['id'], $m[0]['name']];
    } catch (Throwable $e) {}
    return [0, $name];
}

/**
 * 补建原订单并扣减：退款对应的订单客服当时没报单（项目系统里没有），但店铺流水里有这张订单，退款表也写了接单客服。
 * 按流水（订单号 / 店铺 / 金额 / 日期）建出原订单，挂上客服，再走正常的退款审核——订单所属月份已经核算过的，
 * 由系统在退款当月给该客服补扣（算法和普通退款补扣一样），所属月份的分成不动。建出的订单只用来算这笔补扣：
 * 记为“已锁定、无分成快照”，不会进入所属月份的报酬，也不会再产生新的提成。
 * 仅财务可用；一次只处理一笔，全程一个事务。
 */
function prm_rebuild_order($refundId, $business, $employeeId, $method, $actor)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅财务可补建订单');
    if (!function_exists('ps_refund_review')) require_once __DIR__ . '/ProjectRefundImport.php';
    if (!function_exists('ps_recalculate_cash')) require_once __DIR__ . '/settlement/audit_db.php';
    $business = ps_business_normalize($business);
    if (!in_array($business, ['网站模板', 'AI网站定制', '网站续费', '网站修改'], true)) throw new RuntimeException('请选择业务类型');
    $employeeId = (int)$employeeId;
    $e = db()->prepare('SELECT id,name FROM employees WHERE id=?'); $e->execute([$employeeId]); $emp = $e->fetch();
    if (!$emp) throw new RuntimeException('请选择接单客服');
    $method = ps_refund_method($method);
    if ($method === '待核渠道') throw new RuntimeException('请先确认退款渠道');
    $pdo = db();
    $q = $pdo->prepare('SELECT * FROM project_refund_import_rows WHERE id=?'); $q->execute([(int)$refundId]); $row = $q->fetch();
    if (!$row || $row['review_status'] !== 'pending' || $row['order_id'] !== null || !empty($row['deleted_at'])) throw new RuntimeException('这笔退款已处理或已对上订单，请刷新');
    $orderNo = trim((string)$row['order_no']);
    [$exists] = ps_refund_resolve_order($orderNo, $row['source_payment_reference'] ?? '');
    if ($exists) throw new RuntimeException('项目系统里已经有这张订单，请直接“核实并通过”');
    $flow = prm_flow_entry($orderNo);
    if (!$flow || (float)($flow['price'] ?? 0) <= 0) throw new RuntimeException('店铺流水里没有这张订单（或金额为 0），不能补建；请先补传店铺流水');
    $price = round((float)$flow['price'], 2); $amount = (float)$row['amount'];
    if ($amount > $price + 0.004) throw new RuntimeException('退款 ¥' . number_format($amount, 2) . ' 超过流水里的订单金额 ¥' . number_format($price, 2) . '，请先核对');
    $date = substr((string)($flow['date'] ?? ''), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('流水里没有订单日期，不能补建');
    $orderMonth = substr($date, 0, 7);
    $effMonth = substr((string)$row['refund_date'], 0, 7);
    if ($effMonth <= $orderMonth) throw new RuntimeException('原订单月份（' . $orderMonth . '）不在退款月份（' . $effMonth . '）之前，不是历史未报订单；请在项目订单中正常录入');
    if (ps_refund_month_locked($effMonth)) throw new RuntimeException($effMonth . ' 报酬已锁定，请先处理锁月');
    $customerName = prm_source_cell($row, ['付款账号', '旺旺名', '买家昵称', '客户名', '客户ID']);
    if ($customerName === '') $customerName = (string)($flow['nickname'] ?? '');
    if ($customerName === '') $customerName = '待补充';

    $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT prm_rebuild_order'); else $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,refund_amount,order_date,delivery_status,settlement_status,note,created_by_admin) VALUES (?,?,?,'',?,?,0,0,?,'finished','draft',?,?)")
            ->execute([$orderNo, mb_substr($customerName, 0, 100), $business, (string)$flow['shop'], $price, $date, '补建原订单：客服当时未报单，按店铺流水建立，仅用于计算退款补扣（退款 #' . (int)$row['id'] . '）', (int)$actor['id']]);
        $oid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO project_order_sources (order_id,payment_nickname,price_source,nickname_source,status_source) VALUES (?,?,?,?,?)')->execute([$oid, mb_substr($customerName, 0, 100), 'shop_upload', 'shop_upload', 'missing']);
        $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$oid, $employeeId]);
        $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'receipt',?,?,'approved',?,?,?,NOW())")
            ->execute([$oid, $price, '补建原订单：按店铺流水实付登记收款', $actor['type'], $actor['id'], $actor['id']]);
        ps_recalculate_cash($oid);
        ps_audit('order', $oid, 'rebuild_for_refund', $actor, ['refund_id' => (int)$row['id'], 'business' => $business, 'employee_id' => $employeeId, 'price' => $price, 'order_date' => $date]);

        // 正常退款审核：写退款流水、算补扣（ps_refund_review 支持在已有事务中使用 SAVEPOINT）
        ps_refund_review((int)$row['id'], 'approved', $actor, $effMonth, true, $orderNo, '', $method, '');
        // 建出的订单只用来算补扣：锁定，不进所属月份报酬
        $pdo->prepare("UPDATE project_orders SET settlement_status='locked',row_version=row_version+1 WHERE id=? AND settlement_status='draft'")->execute([$oid]);
        $adj = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM project_commission_adjustments WHERE order_id=?'); $adj->execute([$oid]);
        $adjAmount = round((float)$adj->fetchColumn(), 2);
        if ($nested) $pdo->exec('RELEASE SAVEPOINT prm_rebuild_order'); else $pdo->commit();
    } catch (Throwable $ex) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT prm_rebuild_order');
        elseif ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    return ['order_id' => $oid, 'adjustment' => $adjAmount, 'employee' => $emp['name'], 'month' => $effMonth];
}
