<?php
/**
 * 森动备案成本：首次备案（备案 / 备案-淘宝）表里不写成本时，从成本中心“适用业务 = 森动备案”的启用模板自动带入（默认 ¥80 / 单，可在成本中心改价）。
 * 表里“成本”列有数（包括 0）就以表为准，每单不同；只有首次备案的成本格留空才用成本中心；
 * 二次备案（含备案售后等杂项）与首次备案成本无关，只认表里的成本，空 = 无成本；订单已有成本时不重复带入。
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

/** 同号订单补充上传：原单还没有任何成本时按表格“成本”补录；表格没填成本的首次备案才走成本中心。返回新增条数。 */
function pfc_supplement_cost($orderId, array $row, $actor)
{
    if (ptc_order_has_costs($orderId)) return 0;
    $cost = (string)($row['direct_cost'] ?? '');
    if ($cost === '') return pfc_apply($orderId, $row['order_kind'] ?? '', $actor, 'Excel 补充第' . $row['line'] . '行');
    $amount = round((float)$cost, 2);
    if ($amount == 0) return 0;
    // 与新建订单一致：¥500 以内自动通过，超过的由财务审核
    db()->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status,submitted_by_employee) VALUES (?,'outsourcing','备案成本',1,'项',?,?,'one_time',1,?,?,?)")
        ->execute([(int)$orderId, $amount, $amount, 'Excel 补充第' . $row['line'] . '行导入', abs($amount) <= 500 ? 'approved' : 'pending', $actor['employee_id'] ?? null]);
    return 1;
}
