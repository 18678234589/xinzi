<?php
/**
 * 森动备案成本：首次备案（备案 / 备案-淘宝）表里不写成本时，从成本中心“适用业务 = 森动备案”的启用模板自动带入（默认 ¥80 / 单，可在成本中心改价）。
 * 二次备案按单量计提，没有成本；表里自己写了成本的行以表为准；订单已有成本时不重复带入。
 */
require_once __DIR__ . '/ProjectTrademarkCost.php';

function pfc_template()
{
    $q = db()->query("SELECT * FROM project_cost_templates WHERE is_active=1 AND requires_proof=0 AND business_scope='森动备案' AND price_mode='fixed' AND name='森动备案成本' ORDER BY id DESC LIMIT 1");
    return $q ? ($q->fetch() ?: null) : null;
}

/** 这个订单类型是否按成本中心自动带成本。 */
function pfc_applies($orderKind)
{
    return in_array($orderKind, ['备案', '备案-淘宝'], true);
}

/** 写入一笔成本中心成本；返回 1 表示已带入。 */
function pfc_apply($orderId, $orderKind, $actor, $origin, ?array $template = null)
{
    if (!pfc_applies($orderKind) || ptc_order_has_costs($orderId)) return 0;
    $template = $template ?? pfc_template();
    if (!$template) return 0;
    ps_intake_add_template_cost($orderId, $template, $actor, $origin . '：' . $template['name'], 1, (int)$template['auto_approve'] === 1 ? 'approved' : 'pending');
    return 1;
}
