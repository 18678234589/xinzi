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
    $cost = db()->prepare("SELECT amount,reason,review_status FROM project_costs WHERE order_id=? AND review_status<>'rejected' AND unit='件' ORDER BY id");
    $groups = [];
    foreach ($rows as $r) {
        $d = json_decode((string)$r['details_json'], true) ?: [];
        $service = ptc_order_service($d);
        $name = (string)($d['trademark_name'] ?? '');
        $identity = preg_match('/\d{6,}/', $name, $m) ? $m[0] : preg_replace('/[\s\x{3000}]+/u', '', $name);
        if ($service === '' || $identity === '') continue;
        $cost->execute([(int)$r['id']]);
        $costs = $cost->fetchAll();
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
        $same = ['同一客户', '同一商标' . (preg_match('/^\d{6,}$/', $parts[1]) ? '（注册号 ' . $parts[1] . '）' : '名称'), '同一办理事项（' . $parts[2] . '）'];
        $diff = [];
        if (!preg_match('/^\d{6,}$/', $parts[1])) $diff[] = '缺少商标注册号，仅凭名称无法确认是同一件';
        if (count($dates) === 1) $same[] = '同一天下单（' . $dates[0] . '）'; else $diff[] = '下单日期不同（' . implode('、', $dates) . '）';
        if (count($amounts) === 1) $same[] = '售价相同'; else $diff[] = '售价不同';
        $dupe = round(array_sum(array_column($g, 'cost')) - max(array_column($g, 'cost')), 2);
        $out[] = ['key' => sha1($k . '|' . json_encode($g, JSON_UNESCAPED_UNICODE)), 'customer' => $parts[0], 'trademark' => $g[0]['trademark_name'], 'service' => $parts[2], 'orders' => $g, 'dupe' => $dupe, 'same' => $same, 'diff' => $diff,
            'blocked' => (bool)array_filter($g, function ($o) { return $o['frozen']; })];
    }
    return $out;
}

/** 待核对组数；任何错误都按 0 处理，不能影响页面顶部。 */
function ptd_count()
{
    static $n = null;
    if ($n === null) { try { $n = count(ptd_candidates()); } catch (Throwable $e) { $n = 0; } }
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
    foreach (ptd_candidates() as $g) if (hash_equals($g['key'], (string)$groupKey)) { $group = $g; break; }
    if (!$group) throw new RuntimeException('这组订单已被处理或已变化，请刷新页面');
    if ($decision === 'same' && $group['blocked']) throw new RuntimeException('其中有已审核 / 锁定结算的订单，请先通过“分成更正申请”处理');
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
            $q = $pdo->prepare("SELECT COUNT(*) FROM project_orders WHERE id IN (" . implode(',', array_map('intval', $siblings)) . ") AND settlement_status IN ('approved','locked')");
            $q->execute();
            if ((int)$q->fetchColumn() > 0) throw new RuntimeException('其中有已审核 / 锁定结算的订单，重复成本不能自动作废，请先通过“分成更正申请”处理');
            ptc_sync_order_cost($carrier, $actor, '财务确认同一商标');
            foreach ($siblings as $sid) if (ptc_order_has_costs($sid)) throw new RuntimeException('订单 #' . $sid . ' 含人工录入或待核对的成本，不能自动作废，请先在该订单里核对成本');
            $message = '已合并：官费保留在订单 #' . $carrier . '，其余订单的重复官费已作废（保留记录）';
        }
        if ($own) $pdo->commit();
        return $message;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
