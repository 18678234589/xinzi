<?php
/**
 * 商标订单成本：原表没有成本列，成本从成本中心带入。成本中心里“适用业务 = 商标”的启用模板按单位分三类：
 *   单位“件”  服务项目（注册 / 转让 / 续展 …）：按明确的办理事项与商标件数计费，无法识别时要求核对；
 *   单位“个”  附加项（如注册多选项目加收）：客服在订单页填个数追加；
 *   单位“元”  按实际金额（成品商标 / 国际商标 / 法务外包）：单价 ¥1，数量即实际金额，一律进财务审核。
 * 订单已有成本（如专员表自带的成本列）时不再自动带入，避免重复。
 */
require_once __DIR__ . '/ProjectIntake.php';
require_once __DIR__ . '/ProjectTrademarkPricing.php';

function ptc_templates()
{
    return db()->query("SELECT * FROM project_cost_templates WHERE is_active=1 AND requires_proof=0 AND business_scope='商标' AND price_mode='fixed' ORDER BY FIELD(unit,'件','个','元'),id")->fetchAll();
}

function ptc_kind($template)
{
    return ['件' => 'service', '个' => 'extra', '元' => 'variable'][$template['unit']] ?? 'other';
}

/** 服务名关键词：模板名去掉“商标”前缀（商标注册 → 注册）。 */
function ptc_keyword($template)
{
    return trim(preg_replace('/^商标/u', '', (string)$template['name']));
}

/** 只识别唯一的明确事项；宽展 / 超期续展优先于普通续展，不能默认注册。 */
function ptc_detect_service(array $templates, $text)
{
    $names = ptc_service_names($text);
    if (count($names) !== 1) return null;
    $service = ptc_find_service($templates, $names[0]);
    return $service && ptc_kind($service) === 'service' ? $service : null;
}

function ptc_order_has_costs($orderId)
{
    $q = db()->prepare("SELECT 1 FROM project_costs WHERE order_id=? AND review_status<>'rejected' LIMIT 1");
    $q->execute([(int)$orderId]);
    return (bool)$q->fetchColumn();
}

function ptc_count_label($count)
{
    return rtrim(rtrim(number_format((float)$count, 2, '.', ''), '0'), '.');
}

/** 订单办理事项：已确认的 trademark_service，否则取办理事项文字里唯一明确的一项（还没同步过的订单也能比对）。 */
function ptc_order_service(array $d)
{
    $s = trim((string)($d['trademark_service'] ?? ''));
    if ($s !== '') return $s;
    $names = ptc_service_names((string)($d['service_type'] ?? ''));
    return count($names) === 1 ? $names[0] : '';
}

/** 客户名去掉“（微信群聊）”之类的括注。 */
function ptc_customer_base($name)
{
    return trim(preg_replace('/[（(].*$/u', '', (string)$name));
}

/**
 * 多张订单共用一份标准成本（官费），只有“承担订单”自动带入，其余订单不再带入，避免同一件商标的官费被重复计入。
 * 属于同一组：① 同一客户、同一商标注册号（商标名里 6 位以上数字）、同一办理事项、同一天（拆单 / 补款，如 400 + 100）；
 * 或 ② 财务在“商标成本核对”里确认过是同一件商标（订单资料里有相同的 cost_group）。商标名没有注册号的不会自动合并。
 * 承担订单：已冻结结算的优先，其次售价最高，再取最早建单。
 * 返回 [承担订单 id, 同组其余订单 id 列表]；没有同组订单返回 [0, []]。
 */
function ptc_cost_group($orderId, array $details, $service)
{
    $service = trim((string)$service);
    if (($details['cost_review'] ?? '') === 'separate') return [0, []];
    $token = trim((string)($details['cost_group'] ?? ''));
    $hasReg = preg_match('/\d{6,}/', (string)($details['trademark_name'] ?? ''), $m);
    if (!$token && ($service === '' || !$hasReg)) return [0, []];
    $me = db()->prepare('SELECT order_date,customer_name FROM project_orders WHERE id=?');
    $me->execute([(int)$orderId]);
    $me = $me->fetch();
    if (!$me) return [0, []];
    $customer = ptc_customer_base($me['customer_name']);
    $like = addcslashes($customer, '\\%_') . '%';
    $q = db()->prepare("SELECT o.id,o.customer_name,o.order_date,o.contract_amount,o.settlement_status,d.details_json FROM project_orders o JOIN project_order_details d ON d.order_id=o.id WHERE o.project_type='商标' AND o.customer_name LIKE ?");
    $q->execute([$like]);
    $group = [];
    foreach ($q->fetchAll() as $r) {
        $d = json_decode((string)$r['details_json'], true) ?: [];
        if (($d['cost_review'] ?? '') === 'separate') continue;
        if (ptc_customer_base($r['customer_name']) !== $customer) continue;
        if ((int)$r['id'] !== (int)$orderId) {
            $confirmed = $token && trim((string)($d['cost_group'] ?? '')) === $token;
            $sameCase = $hasReg && $r['order_date'] === $me['order_date'] && preg_match('/\d{6,}/', (string)($d['trademark_name'] ?? ''), $n) && $n[0] === $m[0] && ptc_order_service($d) === $service;
            if (!$confirmed && !$sameCase) continue;
        }
        $r['frozen'] = in_array($r['settlement_status'], ['approved', 'locked'], true) ? 0 : 1;
        $group[] = $r;
    }
    if (count($group) < 2) return [0, []];
    usort($group, function ($a, $b) { return [$a['frozen'], -(float)$a['contract_amount'], (int)$a['id']] <=> [$b['frozen'], -(float)$b['contract_amount'], (int)$b['id']]; });
    $carrier = (int)$group[0]['id'];
    return [$carrier, array_values(array_filter(array_map(function ($r) { return (int)$r['id']; }, $group), function ($id) use ($carrier) { return $id !== $carrier; }))];
}

/** 本单的官费是否由同组另一订单承担：是则返回承担订单 id，否则 0。 */
function ptc_cost_carried_by($orderId)
{
    [$details] = ptc_order_pricing_data($orderId);
    [$carrier] = ptc_cost_group($orderId, $details, ptc_order_service($details));
    return $carrier && $carrier !== (int)$orderId ? $carrier : 0;
}

/** 给一张商标订单自动带入服务成本，返回新增条数（0 或 1）。调用方负责事务。 */
function ptc_apply($orderId, $count, $actor, $origin, $text = '', ?array $templates = null)
{
    if (ptc_order_has_costs($orderId)) return 0;
    [$details, $storedText] = ptc_order_pricing_data($orderId);
    $details['trademark_count'] = $count;
    $plan = ptc_cost_plan($templates ?? ptc_templates(), $details, $storedText . ' ' . $text);
    if ($plan['status'] !== 'ready') return 0;
    [$carrier] = ptc_cost_group($orderId, $details, $plan['service']);
    if ($carrier && $carrier !== (int)$orderId) return 0; // 同一商标的官费已由同组另一订单承担
    foreach ($plan['lines'] as $line) ps_intake_add_template_cost($orderId, $line['template'], $actor, '商标自动：' . $origin . '；' . $plan['message'], $line['quantity'], $line['status']);
    return 1;
}

/** 订单页快捷录入：服务项目（替换原服务成本）、附加项、按实际金额项。调用方负责事务与权限。 */
function ptc_user_add($orderId, $templateId, $quantity, $actor)
{
    $q = db()->prepare("SELECT * FROM project_cost_templates WHERE id=? AND is_active=1 AND business_scope='商标' AND price_mode='fixed' AND requires_proof=0");
    $q->execute([(int)$templateId]);
    $template = $q->fetch();
    if (!$template) throw new RuntimeException('成本项目不可用，请刷新页面');
    $kind = ptc_kind($template);
    if ($kind === 'other') throw new RuntimeException('此成本项目请由财务录入');
    if ($kind === 'variable' && (float)$template['price'] !== 1.0) throw new RuntimeException('实际金额成本模板单价必须为1元，请财务核对成本中心');
    $quantity = trim((string)$quantity);
    if ($kind === 'variable') {
        if (!is_numeric($quantity) || (float)$quantity <= 0 || (float)$quantity > 9999999) throw new RuntimeException('请填写实际成本金额（大于 0）');
    } elseif ($problem = ptc_count_problem($quantity)) throw new RuntimeException($problem);
    $quantity = round((float)$quantity, 2);
    if ($kind === 'service') {
        [$details] = ptc_order_pricing_data($orderId);
        $details['trademark_service'] = ptc_keyword($template);
        $details['trademark_count'] = ptc_count_label($quantity);
        db()->prepare("INSERT INTO project_order_details (order_id,business_name,details_json) VALUES (?,'商标',?) ON DUPLICATE KEY UPDATE details_json=VALUES(details_json)")->execute([(int)$orderId, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $old = db()->prepare("SELECT c.id FROM project_costs c JOIN project_cost_templates t ON t.id=c.template_id WHERE c.order_id=? AND t.business_scope='商标' AND t.unit='件' AND c.review_status IN ('approved','pending')");
        $old->execute([(int)$orderId]);
        foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $costId) {
            db()->prepare("UPDATE project_costs SET review_status='rejected',review_note='客服改选服务项目，原服务成本作废' WHERE id=?")->execute([(int)$costId]);
            ps_audit('cost', (int)$costId, 'replaced_by_service', $actor, ['order_id' => (int)$orderId]);
        }
    }
    $status = $kind === 'variable' ? 'pending' : ((int)$template['auto_approve'] === 1 ? 'approved' : 'pending');
    $label = $kind === 'variable' ? '按实际金额 ¥' . ptc_count_label($quantity) : '× ' . ptc_count_label($quantity) . ' ' . $template['unit'];
    return ps_intake_add_template_cost($orderId, $template, $actor, '订单页快捷录入：' . $template['name'] . ' ' . $label, $quantity, $status);
}

/** 成本中心配好后，给未审核且还没有任何成本的商标订单补带服务成本；已审核 / 锁定的订单不动。 */
function ptc_backfill($actor)
{
    $templates = ptc_templates();
    if (!$templates) throw new RuntimeException('成本中心还没有启用的商标成本模板，请先添加');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->query("SELECT o.id,o.note,d.details_json FROM project_orders o JOIN project_order_details d ON d.order_id=o.id WHERE o.project_type='商标' AND o.settlement_status IN ('draft','review') ORDER BY o.id FOR UPDATE");
        $orders = 0; $costs = 0;
        foreach ($q->fetchAll() as $row) {
            $details = json_decode((string)$row['details_json'], true) ?: [];
            $text = implode(' ', [$details['trademark_name'] ?? '', $details['service_type'] ?? '', $row['note'] ?? '']);
            $added = ptc_apply((int)$row['id'], $details['trademark_count'] ?? '', $actor, '补带', $text, $templates);
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

/** 商标自动成本统一落账：按已确认事项纠正项目、件数和单价；人工成本及冻结结算不变。 */
function ptc_sync_order_cost($orderId, $actor, $origin, $excelCost = null, $text = null)
{
    require_once __DIR__ . '/ProjectTrademarkReconcile.php';
    return ptc_reconcile_order_cost($orderId, $actor, $origin, $excelCost, $text);
}
