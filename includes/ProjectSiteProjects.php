<?php
/** One payment may fund several distinct website projects. Each site has one stable identity and one commission order. */

function psp_is_website($business)
{
    return in_array(ps_business_normalize((string)$business), ['网站模板', 'AI网站定制'], true);
}

function psp_key($raw)
{
    $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$raw)), 'UTF-8');
    if ($key === '' || mb_strlen($key) > 160) throw new RuntimeException('网站项目标识不能为空且不能超过160字');
    return $key;
}

function psp_child_no($external, $key)
{
    $no = (string)$external . '~site-' . substr(hash('sha256', $key), 0, 12);
    if (strlen($no) > 100) throw new RuntimeException('订单号过长，无法生成网站项目编号，请联系财务');
    return $no;
}

function psp_lookup($external, $key)
{
    $q = db()->prepare('SELECT p.*,o.order_no FROM project_site_projects p JOIN project_orders o ON o.id=p.order_id WHERE p.external_order_no=? AND p.site_key=? LIMIT 1');
    $q->execute([(string)$external, psp_key($key)]);
    return $q->fetch() ?: null;
}

function psp_order($orderId)
{
    $q = db()->prepare('SELECT * FROM project_site_projects WHERE order_id=?');
    $q->execute([(int)$orderId]);
    return $q->fetch() ?: null;
}

function psp_members($rootId, $lock = false)
{
    $q = db()->prepare("SELECT p.*,o.order_no,o.project_type,o.contract_amount,o.receipt_amount,o.refund_amount,o.settlement_status,
        (SELECT COALESCE(SUM(c.amount),0) FROM project_cash_movements c WHERE c.order_id=o.id AND c.review_status='approved' AND c.movement_type='receipt') AS ledger_receipt,
        (SELECT COALESCE(SUM(c.amount),0) FROM project_cash_movements c WHERE c.order_id=o.id AND c.review_status='approved' AND c.movement_type='refund') AS ledger_refund
        FROM project_site_projects p JOIN project_orders o ON o.id=p.order_id WHERE p.root_order_id=? ORDER BY p.order_id" . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([(int)$rootId]);
    return $q->fetchAll();
}

function psp_register($orderId, $rootId, $external, $rawKey, $actor)
{
    $key = psp_key($rawKey);
    $existing = psp_lookup($external, $key);
    if ($existing && (int)$existing['order_id'] !== (int)$orderId) throw new RuntimeException('该网站项目标识已关联另一张项目订单，不能重复计单');
    $own = psp_order($orderId);
    if ($own && ($own['site_key'] !== $key || (int)$own['root_order_id'] !== (int)$rootId || $own['external_order_no'] !== (string)$external)) throw new RuntimeException('此项目订单已绑定其他网站，不能覆盖原标识');
    if ($own) return;
    db()->prepare('INSERT INTO project_site_projects (order_id,root_order_id,external_order_no,site_key,created_by_type,created_by_id) VALUES (?,?,?,?,?,?)')
        ->execute([(int)$orderId, (int)$rootId, (string)$external, $key, (string)$actor['type'], (int)$actor['id']]);
    ps_audit('order', $orderId, 'site_identity', $actor, ['external_order_no' => $external, 'site_key' => $key, 'root_order_id' => (int)$rootId]);
}

function psp_allocation_hash($members)
{
    $parts = [];
    foreach ($members as $m) $parts[] = implode('|', [(int)$m['order_id'], $m['site_key'], (int)round((float)$m['contract_amount'] * 100), (int)round((float)$m['receipt_amount'] * 100), (int)round((float)$m['refund_amount'] * 100)]);
    return hash('sha256', implode(';', $parts));
}

function psp_validate_allocation($members, $paid)
{
    if (count($members) < 2) throw new RuntimeException('至少两张独立网站项目订单才能核对共用付款');
    $paidCents = (int)round((float)$paid * 100);
    if ($paidCents <= 0) throw new RuntimeException('请填写真实付款总额');
    $contract = 0; $receipt = 0;
    foreach ($members as $m) {
        $sale = (int)round((float)$m['contract_amount'] * 100);
        $cash = (int)round((float)$m['receipt_amount'] * 100);
        if (isset($m['ledger_receipt']) && $cash !== (int)round((float)$m['ledger_receipt'] * 100)) throw new RuntimeException('项目实收与已审核收款流水不一致，请财务核对');
        if (isset($m['ledger_refund']) && (int)round((float)$m['refund_amount'] * 100) !== (int)round((float)$m['ledger_refund'] * 100)) throw new RuntimeException('项目退款与已审核退款流水不一致，请财务核对');
        if ($sale <= 0 || $cash < 0 || $cash > $sale) throw new RuntimeException('每个网站必须填写正数分配售价，且实收不能超过该项目售价');
        if ($cash !== $sale) throw new RuntimeException('每个网站的已审核实收必须等于本项目分配售价；请先逐单核对收款');
        $contract += $sale; $receipt += $cash;
    }
    if ($contract !== $paidCents || $receipt !== $paidCents) throw new RuntimeException('各网站分配售价及已审核实收之和必须等于客户真实付款总额');
}

function psp_verify($rootId, $paidText, $note, $actor)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅财务可确认共用付款分配');
    if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', (string)$paidText)) throw new RuntimeException('付款总额格式无效');
    $note = trim((string)$note);
    if ($note === '' || mb_strlen($note) > 500) throw new RuntimeException('请填写付款流水或核对凭证说明');
    $members = psp_members($rootId, true);
    psp_validate_allocation($members, $paidText);
    require_once __DIR__ . '/ProjectAutoReview.php';
    $rootQuery = db()->prepare('SELECT * FROM project_orders WHERE id=?');
    $rootQuery->execute([(int)$rootId]);
    $rootOrder = $rootQuery->fetch();
    if (!$rootOrder || (int)$members[0]['order_id'] !== (int)$rootId) throw new RuntimeException('原网站项目订单不存在');
    $sourceQuery = db()->prepare('SELECT * FROM project_order_sources WHERE order_id=?');
    $sourceQuery->execute([(int)$rootId]);
    $payment = pa_payment_evidence(pa_sources($rootOrder, $sourceQuery->fetch() ?: [], false), trim((string)$rootOrder['shop']), date('Y-m-d H:i:s'));
    if ($payment['paid_cents'] !== null && (int)$payment['paid_cents'] !== (int)round((float)$paidText * 100)) throw new RuntimeException('填写的真实付款总额与店铺实付流水不一致，请先核对交易或退款');
    $hash = psp_allocation_hash($members);
    db()->prepare('INSERT INTO project_site_verifications (root_order_id,paid_amount,allocation_hash,evidence_note,verified_by_admin) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE paid_amount=VALUES(paid_amount),allocation_hash=VALUES(allocation_hash),evidence_note=VALUES(evidence_note),verified_by_admin=VALUES(verified_by_admin),verified_at=NOW()')
        ->execute([(int)$rootId, (float)$paidText, $hash, $note, (int)$actor['id']]);
    ps_audit('order', $rootId, 'site_allocation_verified', $actor, ['paid_amount' => $paidText, 'project_order_ids' => array_map(function ($m) { return (int)$m['order_id']; }, $members)]);
}

function psp_verification($rootId)
{
    $q = db()->prepare('SELECT * FROM project_site_verifications WHERE root_order_id=?');
    $q->execute([(int)$rootId]);
    return $q->fetch() ?: null;
}

function psp_approve_guard($orderId)
{
    $site = psp_order($orderId);
    if (!$site) return;
    $members = psp_members((int)$site['root_order_id'], true);
    if (count($members) < 2) return;
    $verification = psp_verification((int)$site['root_order_id']);
    if (!$verification || !hash_equals((string)$verification['allocation_hash'], psp_allocation_hash($members))) throw new RuntimeException('同一付款号的多个网站项目尚未由财务逐项核对实收分配，不能生成分成');
    psp_validate_allocation($members, $verification['paid_amount']);
}
