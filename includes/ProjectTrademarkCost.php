<?php
/**
 * 商标订单成本：原表没有成本列，成本从成本中心带入。
 * 约定：成本中心里“适用业务 = 商标、单位 = 件、固定价”的启用模板，按订单的“商标个数”× 单价自动入账（官费、代理费等可各建一条）。
 * 没有商标个数的订单（如小额返款）不带入；已带入过的模板不重复入账。
 */
require_once __DIR__ . '/ProjectIntake.php';

function ptc_templates()
{
    return db()->query("SELECT * FROM project_cost_templates WHERE is_active=1 AND requires_proof=0 AND business_scope='商标' AND unit='件' AND price_mode='fixed' ORDER BY id")->fetchAll();
}

/** 给一张商标订单带入标准成本，返回新增条数。调用方负责事务。 */
function ptc_apply($orderId, $count, $actor, $origin, ?array $templates = null)
{
    if (!is_numeric($count) || (float)$count <= 0) return 0;
    $templates = $templates ?? ptc_templates();
    $has = db()->prepare('SELECT 1 FROM project_costs WHERE order_id=? AND template_id=? LIMIT 1');
    $added = 0;
    foreach ($templates as $template) {
        $has->execute([(int)$orderId, (int)$template['id']]);
        if ($has->fetchColumn()) continue;
        ps_intake_add_template_cost($orderId, $template, $actor, $origin . '：商标成本 × ' . rtrim(rtrim(number_format((float)$count, 2, '.', ''), '0'), '.') . ' 件', (float)$count);
        $added++;
    }
    return $added;
}

/** 成本中心配好后，给未审核的商标订单补带成本；已审核 / 锁定的订单不动。 */
function ptc_backfill($actor)
{
    $templates = ptc_templates();
    if (!$templates) throw new RuntimeException('成本中心还没有“适用业务=商标、单位=件”的启用模板，请先添加');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->query("SELECT o.id,d.details_json FROM project_orders o JOIN project_order_details d ON d.order_id=o.id WHERE o.project_type='商标' AND o.settlement_status IN ('draft','review') ORDER BY o.id FOR UPDATE");
        $orders = 0; $costs = 0;
        foreach ($q->fetchAll() as $row) {
            $details = json_decode((string)$row['details_json'], true) ?: [];
            $added = ptc_apply((int)$row['id'], $details['trademark_count'] ?? '', $actor, '补带', $templates);
            if ($added) { $orders++; $costs += $added; }
        }
        ps_audit('template', 0, 'trademark_cost_backfill', $actor, ['orders' => $orders, 'costs' => $costs]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['orders' => $orders, 'costs' => $costs];
}
