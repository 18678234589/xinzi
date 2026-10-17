<?php
/**
 * 商标成本核对：同一客户、同一商标、同一办理事项的多张订单都带了官费，疑似同一件商标重复计成本，交财务确认。
 * 能自动确认的（同客户 + 同注册号 + 同事项 + 同一天）由 ptc_cost_group() 直接处理；这里列出系统无法确定的：
 * 商标名没有注册号、或不在同一天。财务选“同一件”后官费只计一次；选“不是同一件”后各自保留成本并不再提醒。
 */
require_once __DIR__ . '/ProjectTrademarkCost.php';

function ptd_source_text($reason)
{
    $r = (string)$reason;
    if (preg_match('/Excel 第(\d+)行/u', $r, $m)) return '导入 Excel 第 ' . $m[1] . ' 行时自动带入';
    if (strpos($r, '成本修复') === 0) return '系统“成本修复”自动带入';
    if (strpos($r, '补带') !== false) return '成本中心“补带”自动带入';
    if (strpos($r, '订单页快捷录入') === 0) return '订单页手工录入';
    if (strpos($r, '新建订单') !== false) return '新建订单时自动带入';
    return mb_substr($r, 0, 30);
}

/** 疑似重复官费的订单分组。每组含：key、客户、商标、事项、订单列表、重复多计金额、相同点 / 不同点。 */
function ptd_candidates()
{
    $rows = db()->query("SELECT o.id,o.order_no,o.customer_name,o.order_kind,o.order_date,o.contract_amount,o.settlement_status,d.details_json
        FROM project_orders o JOIN project_order_details d ON d.order_id=o.id
        WHERE o.project_type='商标' AND EXISTS (SELECT 1 FROM project_costs c WHERE c.order_id=o.id AND c.review_status<>'rejected' AND c.unit='件')")->fetchAll();
    $costsBy = [];
    if ($rows) {
        $ids = implode(',', array_map('intval', array_column($rows, 'id')));
        foreach (db()->query("SELECT order_id,amount,reason FROM project_costs WHERE order_id IN ($ids) AND review_status<>'rejected' AND unit='件' ORDER BY id")->fetchAll() as $c) $costsBy[(int)$c['order_id']][] = $c;
    }
    $groups = [];
    foreach ($rows as $r) {
        $d = json_decode((string)$r['details_json'], true) ?: [];
        $service = ptc_order_service($d);
        $name = (string)($d['trademark_name'] ?? '');
        $identity = preg_match('/\d{6,}/', $name, $m) ? $m[0] : preg_replace('/[\s\x{3000}]+/u', '', $name);
        if ($service === '' || $identity === '') continue;
        $costs = $costsBy[(int)$r['id']] ?? [];
        $r['cost'] = round(array_sum(array_column($costs, 'amount')), 2);
        $r['source'] = ptd_source_text($costs[0]['reason'] ?? '');
        $r['frozen'] = in_array($r['settlement_status'], ['approved', 'locked'], true);
        $r['review'] = (string)($d['cost_review'] ?? '');
        $r['trademark_name'] = $name;
        $groups[ptc_customer_base($r['customer_name']) . '|' . $identity . '|' . $service][] = $r;
    }
    $out = [];
    foreach ($groups as $k => $g) {
        if (count($g) < 2) continue;
        foreach ($g as $o) if ($o['review'] === 'separate') continue 2;
        usort($g, function ($a, $b) { return [$a['order_date'], (int)$a['id']] <=> [$b['order_date'], (int)$b['id']]; });
        $parts = explode('|', $k);
        $dates = array_unique(array_column($g, 'order_date'));
        $amounts = array_unique(array_map('floatval', array_column($g, 'contract_amount')));
        $hasReg = (bool)preg_match('/^\d{6,}$/', $parts[1]);
        $same = ['同一客户', '同一商标' . ($hasReg ? '（注册号 ' . $parts[1] . '）' : '名称'), '同一办理事项（' . $parts[2] . '）'];
        $diff = [];
        if (!$hasReg) $diff[] = '缺少商标注册号，仅凭名称无法确认是同一件';
        if (count($dates) === 1) $same[] = '同一天下单（' . $dates[0] . '）'; else $diff[] = '下单日期不同（' . implode('、', $dates) . '）';
        if (count($amounts) === 1) $same[] = '售价相同'; else $diff[] = '售价不同';
        $dupe = round(array_sum(array_column($g, 'cost')) - max(array_column($g, 'cost')), 2);
        if ($dupe <= 0) continue; // 官费为 0 的事项（变更、注销等）没有多计，不必核对
        // 建议：同一天（拆单 / 补款最常见）倾向合并；隔了几天多半是各自办理。只是参考，最终由人确认。
        if (count($dates) === 1) { $advice = 'same'; $why = '同一天、同一客户、同一事项，更像一单拆成了两次付款，建议合并。'; }
        else { $advice = 'separate'; $why = '下单日期不同，更可能是客户先后办理了不同的案件，建议各自保留；若确认是同一件再合并。'; }
        $out[] = ['key' => sha1($k . '|' . json_encode($g, JSON_UNESCAPED_UNICODE)), 'msg_key' => sha1($k . '|' . implode(',', array_column($g, 'id'))), 'customer' => $parts[0], 'trademark' => $g[0]['trademark_name'], 'service' => $parts[2], 'orders' => $g, 'dupe' => $dupe, 'same' => $same, 'diff' => $diff,
            'advice' => $advice, 'advice_text' => $why, 'blocked' => (bool)array_filter($g, function ($o) { return $o['frozen']; })];
    }
    return $out;
}

/** 本次请求内缓存；处理完一组后 $fresh = true 重新计算。 */
function ptd_all($fresh = false)
{
    static $cache = null;
    if ($cache === null || $fresh) $cache = ptd_candidates();
    return $cache;
}

/** 谁能核对：财务，或负责商标业务的主管（管理账号）。 */
function ptd_can_review($actor)
{
    if (!is_array($actor)) return false;
    if (($actor['role'] ?? '') === 'finance') return true;
    try { return ps_management_can_business($actor, '商标'); } catch (Throwable $e) { return false; }
}

/** 订单 id → 所在待核对组（合作人员的订单列表据此提示“待财务核对”）。 */
function ptd_order_group($orderId)
{
    static $map = null;
    if ($map === null) {
        $map = [];
        try { foreach (ptd_all() as $g) foreach ($g['orders'] as $o) $map[(int)$o['id']] = $g; } catch (Throwable $e) { $map = []; }
    }
    return $map[(int)$orderId] ?? null;
}

/** 商标部主管（员工 id）：站内信发给他们。 */
function ptd_head_employee_ids()
{
    try { return array_map('intval', db()->query("SELECT DISTINCT employee_id FROM project_dept_heads WHERE department='商标'")->fetchAll(PDO::FETCH_COLUMN)); }
    catch (Throwable $e) { return []; }
}

/** 站内信同步：新出现的待核对组给商标主管发一条；已处理（不再待核对）的组，所有人的这条站内信一并删除，提醒随之消失。 */
function ptd_sync_messages(array $groups = null)
{
    $groups = $groups ?? ptd_all();
    try {
        $heads = ptd_head_employee_ids();
        $keep = [];
        $ins = db()->prepare("INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,'trademark_cost',?,?,?,?)");
        foreach ($groups as $g) {
            foreach ($heads as $eid) {
                $key = 'tmc:' . $g['msg_key'] . ':' . $eid;
                $keep[] = $key;
                $ins->execute([$eid, mb_substr('商标成本待核对：' . $g['customer'] . ' · ' . $g['service'], 0, 160),
                    $g['customer'] . ' 的商标“' . $g['trademark'] . '”（' . $g['service'] . '）有 ' . count($g['orders']) . ' 张订单都带入了官费，合计可能多计 ¥' . number_format($g['dupe'], 2) . "。\n" . $g['advice_text'] . "\n点“去处理”一键确认；有人处理后这条提醒会自动消失。",
                    '/project/trademark_costs.php', $key]);
            }
        }
        if ($keep) {
            $marks = implode(',', array_fill(0, count($keep), '?'));
            db()->prepare("DELETE FROM project_messages WHERE category='trademark_cost' AND dedupe_key NOT IN ($marks)")->execute($keep);
        } else {
            db()->exec("DELETE FROM project_messages WHERE category='trademark_cost'");
        }
    } catch (Throwable $e) { /* 站内信异常不影响核对 */ }
}

/** 待核对组数；同时把站内信与待核对列表对齐。任何错误都按 0 处理，不能影响页面顶部。 */
function ptd_count()
{
    static $n = null;
    if ($n === null) { try { $groups = ptd_all(); ptd_sync_messages($groups); $n = count($groups); } catch (Throwable $e) { $n = 0; } }
    return $n;
}

function ptd_save_details($orderId, array $details)
{
    db()->prepare("INSERT INTO project_order_details (order_id,business_name,details_json) VALUES (?,'商标',?) ON DUPLICATE KEY UPDATE details_json=VALUES(details_json)")
        ->execute([(int)$orderId, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
}

/** 财务确认：decision = same（同一件，官费只计一次）/ separate（不是同一件，各自计成本）。返回结果说明。 */
function ptd_decide($groupKey, $decision, $actor)
{
    if (!in_array($decision, ['same', 'separate'], true)) throw new RuntimeException('操作无效');
    $group = null;
    foreach (ptd_all(true) as $g) if (hash_equals($g['key'], (string)$groupKey)) { $group = $g; break; }
    if (!$group) throw new RuntimeException('这组订单已被处理或已变化，请刷新页面');
    // 含已审核订单的合并只有财务能做（已审核订单的分成快照不会自动变，见下方说明）。
    if ($decision === 'same' && $group['blocked'] && ($actor['role'] ?? '') !== 'finance') throw new RuntimeException('其中有已审核 / 锁定结算的订单，只有财务可以合并');
    $ids = array_map('intval', array_column($group['orders'], 'id'));
    $pdo = db();
    $own = !$pdo->inTransaction(); // 调用方已开事务时由调用方负责提交 / 回滚
    if ($own) $pdo->beginTransaction();
    try {
        // 锁定后复核页面上的订单及金额，避免两人同时处理或按过期页面合并。
        $pdo->query("SELECT o.id FROM project_orders o JOIN project_order_details d ON d.order_id=o.id JOIN project_costs c ON c.order_id=o.id WHERE o.id IN (" . implode(',', $ids) . ") ORDER BY o.id,c.id FOR UPDATE")->fetchAll();
        $fresh = false;
        foreach (ptd_candidates() as $g) if (hash_equals($g['key'], (string)$groupKey)) { $fresh = true; break; }
        if (!$fresh) throw new RuntimeException('这组订单已被处理或已变化，请刷新页面');
        $token = 'g' . min($ids);
        foreach ($ids as $id) {
            [$details] = ptc_order_pricing_data($id);
            if ($decision === 'same') $details['cost_group'] = $token; else $details['cost_review'] = 'separate';
            ptd_save_details($id, $details);
            ps_audit('order', $id, $decision === 'same' ? 'trademark_cost_same' : 'trademark_cost_separate', $actor, ['group' => $ids, 'by' => $actor['id'] ?? 0]);
        }
        $message = '已标记为各自独立的订单，保留各自成本，不再提醒';
        if ($decision === 'same') {
            [$details] = ptc_order_pricing_data($ids[0]);
            [$carrier, $siblings] = ptc_cost_group($ids[0], $details, ptc_order_service($details));
            if (!$carrier) throw new RuntimeException('无法确定承担订单，请联系技术人员');
            $q = $pdo->query("SELECT id FROM project_orders WHERE id IN (" . implode(',', array_map('intval', $siblings)) . ") AND settlement_status='locked'");
            if ($q->fetchColumn()) throw new RuntimeException('其中有已锁定结算的订单，重复成本不能自动作废，请先通过“分成更正申请”处理');
            $q = $pdo->query("SELECT id FROM project_orders WHERE id IN (" . implode(',', array_map('intval', $siblings)) . ") AND settlement_status='approved'");
            $approved = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            ptc_sync_order_cost($carrier, $actor, '财务确认同一商标'); // 承担订单已审核时这里不动，由下面逐个处理其余订单
            foreach ($siblings as $sid) {
                if (in_array($sid, $approved, true)) {
                    // 已审核订单：自动成本行直接作废（保留记录）；它的分成快照不会自动变，需要的话再走“分成更正申请”。
                    $pdo->prepare("UPDATE project_costs SET review_status='rejected',review_note=? WHERE order_id=? AND unit='件' AND review_status<>'rejected'")->execute(['同一商标的官费已由订单 #' . $carrier . ' 承担，本单不重复计入（财务确认）', $sid]);
                    ps_audit('order', $sid, 'trademark_cost_merged_after_approval', $actor, ['carrier' => $carrier]);
                } else ptc_reconcile_order_cost($sid, $actor, '同商标成本合并');
                if (ptc_order_has_costs($sid)) throw new RuntimeException('订单 #' . $sid . ' 含人工录入或待核对的成本，不能自动作废，请先在该订单里核对成本');
            }
            $message = '已合并：官费保留在订单 #' . $carrier . '，其余订单的重复官费已作废（保留记录）' . ($approved ? '。订单 #' . implode('、#', $approved) . ' 已审核，其分成快照没有自动改动；如需按新成本补发分成，请走“分成更正申请”' : '');
        }
        if ($own) $pdo->commit();
        ptd_sync_messages(ptd_all(true)); // 处理完立刻刷新列表，已处理的提醒随之消失
        return $message;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
