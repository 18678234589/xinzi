<?php
/**
 * 商标订单成本：原表没有成本列，成本从成本中心带入。成本中心里“适用业务 = 商标”的启用模板按单位分三类：
 *   单位“件”  服务项目（注册 / 转让 / 续展 …）：按订单的“商标个数”× 单价；导入时按表格文字里的服务名自动识别，识别不到按“商标注册”；
 *   单位“个”  附加项（如注册多选项目加收）：客服在订单页填个数追加；
 *   单位“元”  按实际金额（成品商标 / 国际商标 / 法务外包）：单价 ¥1，数量即实际金额，一律进财务审核。
 * 订单已有成本（如专员表自带的成本列）时不再自动带入，避免重复。
 */
require_once __DIR__ . '/ProjectIntake.php';

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

/** 按表格文字识别服务项目：取文字里出现的最长服务名；没有出现任何服务名时按“注册”。 */
function ptc_detect_service(array $templates, $text)
{
    $services = array_values(array_filter($templates, function ($t) { return ptc_kind($t) === 'service'; }));
    $best = null; $bestLength = 0; $default = null;
    foreach ($services as $service) {
        $keyword = ptc_keyword($service);
        if ($keyword === '') continue;
        if ($keyword === '注册') $default = $service;
        if (mb_strpos((string)$text, $keyword) !== false && mb_strlen($keyword) > $bestLength) { $best = $service; $bestLength = mb_strlen($keyword); }
    }
    return $best ?? $default;
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

/** 给一张商标订单自动带入服务成本，返回新增条数（0 或 1）。调用方负责事务。 */
function ptc_apply($orderId, $count, $actor, $origin, $text = '', ?array $templates = null)
{
    if (!is_numeric($count) || (float)$count <= 0 || ptc_order_has_costs($orderId)) return 0;
    $service = ptc_detect_service($templates ?? ptc_templates(), $text);
    if (!$service) return 0;
    // 成本中心的标准价：不受 ¥500 阈值限制，模板设为“不自动审”时才进财务审核
    ps_intake_add_template_cost($orderId, $service, $actor, $origin . '：' . ptc_keyword($service) . ' × ' . ptc_count_label($count) . ' 件', (float)$count, (int)$service['auto_approve'] === 1 ? 'approved' : 'pending');
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
    $quantity = trim((string)$quantity);
    if (!is_numeric($quantity) || (float)$quantity <= 0 || (float)$quantity > ($kind === 'variable' ? 9999999 : 1000)) throw new RuntimeException($kind === 'variable' ? '请填写实际成本金额（大于 0）' : '数量须大于 0');
    $quantity = round((float)$quantity, 2);
    if ($kind === 'service') {
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
    if (!ptc_detect_service($templates, '')) throw new RuntimeException('成本中心还没有“适用业务=商标、单位=件”的启用模板（如“商标注册”），请先添加');
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

/**
 * 商标订单成本的统一落账（新建、补充上传、修复工具共用）：
 *   自动成本 = 商标个数 × 成本中心服务项目单价（按文字识别服务名，识别不到按“注册”）；
 *   Excel 成本 = 本次表格“成本”列，或订单上此前从 Excel 导入的成本；
 *   订单成本取两者较大者——自动算不出来时以 Excel 为准，自动成本比 Excel 低时也以 Excel 为准；Excel 没写时用自动成本。
 * 商标成本都是标准官费，不再因超过 ¥500 卡在待审核（待审核的成本不进“直接成本”，订单会显示成本 ¥0）。
 * 订单上有人工填写的其他成本（非模板、非 Excel 导入）时不改动。返回做了什么：none / added / raised / approved / ok / manual / skip。
 */
function ptc_sync_order_cost($orderId, $actor, $origin, $excelCost = null, $text = null)
{
    $pdo = db();
    $order = $pdo->prepare('SELECT project_type,note,settlement_status FROM project_orders WHERE id=?');
    $order->execute([(int)$orderId]);
    $order = $order->fetch();
    if (!$order || $order['project_type'] !== '商标' || in_array($order['settlement_status'], ['approved', 'locked'], true)) return 'skip';
    $detail = $pdo->prepare('SELECT details_json FROM project_order_details WHERE order_id=?');
    $detail->execute([(int)$orderId]);
    $details = json_decode((string)$detail->fetchColumn(), true) ?: [];
    $count = $details['trademark_count'] ?? '';
    $text = $text ?? implode(' ', [$details['service_type'] ?? '', $details['trademark_name'] ?? '', $order['note'] ?? '']);
    $service = is_numeric($count) && (float)$count > 0 ? ptc_detect_service(ptc_templates(), $text) : null;
    $auto = null;
    if ($service) { [, $autoAmount] = ps_template_cost_amount($service, 0, (float)$count); $auto = round((float)$autoAmount, 2); }
    $excel = ($excelCost !== null && $excelCost !== '' && is_numeric($excelCost)) ? round((float)$excelCost, 2) : 0.0;

    $rows = $pdo->prepare("SELECT id,template_id,amount,quantity,review_status,reason FROM project_costs WHERE order_id=? AND review_status<>'rejected' ORDER BY id FOR UPDATE");
    $rows->execute([(int)$orderId]);
    $rows = $rows->fetchAll();
    $excelRows = []; $autoRows = []; $otherRows = [];
    foreach ($rows as $r) {
        if ($r['template_id'] !== null && (int)$r['template_id'] > 0) $autoRows[] = $r;
        elseif (strpos((string)$r['reason'], 'Excel') === 0) $excelRows[] = $r;
        else $otherRows[] = $r;
    }
    if ($otherRows) return 'manual';
    $excel = max($excel, round(array_sum(array_map(function ($r) { return (float)$r['amount']; }, $excelRows)), 2));
    $target = max($auto ?? 0.0, $excel);
    $insertExcel = function ($amount, $why) use ($pdo, $orderId, $actor) {
        $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status,submitted_by_employee) VALUES (?,'outsourcing','商标官费',1,'项',?,?,'one_time',1,?,'approved',?)")
            ->execute([(int)$orderId, $amount, $amount, $why, $actor['employee_id'] ?? null]);
        return (int)$pdo->lastInsertId();
    };
    if (!$rows) {
        if ($auto !== null && $auto >= $excel) { ps_intake_add_template_cost($orderId, $service, $actor, $origin . '：' . ptc_keyword($service) . ' × ' . ptc_count_label($count) . ' 件', (float)$count, (int)$service['auto_approve'] === 1 ? 'approved' : 'pending'); return 'added'; }
        if ($excel > 0) { $id = $insertExcel($excel, (strpos($origin, 'Excel') === 0 ? '' : 'Excel ') . $origin . '导入'); ps_audit('cost', $id, 'create_from_intake', $actor, ['order_id' => (int)$orderId, 'amount' => $excel, 'origin' => $origin]); return 'added'; }
        return 'none';
    }
    $done = 'ok';
    // 待审核的 Excel 成本：商标官费直接通过
    foreach ($excelRows as $r) if ($r['review_status'] === 'pending') {
        $pdo->prepare("UPDATE project_costs SET review_status='approved',review_note=CONCAT_WS('；',NULLIF(review_note,''),'商标成本按标准官费自动通过') WHERE id=?")->execute([(int)$r['id']]);
        ps_audit('cost', (int)$r['id'], 'auto_approve_trademark', $actor, ['order_id' => (int)$orderId, 'amount' => (float)$r['amount'], 'origin' => $origin]);
        $done = 'approved';
    }
    $current = round(array_sum(array_map(function ($r) { return (float)$r['amount']; }, $rows)), 2);
    if ($current + 0.004 < $target) {
        $why = '；' . $origin . '：成本取成本中心标准价与 Excel 中较大者 ¥' . number_format($target, 2, '.', '');
        if (count($rows) === 1) {
            $r = $rows[0]; $quantity = max((float)$r['quantity'], 1);
            $pdo->prepare("UPDATE project_costs SET amount=?,unit_price=ROUND(?/?,2),review_status='approved',reason=LEFT(CONCAT(reason,?),500) WHERE id=?")->execute([$target, $target, $quantity, $why, (int)$r['id']]);
            ps_audit('cost', (int)$r['id'], 'raise_trademark_cost', $actor, ['order_id' => (int)$orderId, 'from' => (float)$r['amount'], 'to' => $target, 'origin' => $origin]);
        } else {
            $id = $insertExcel(round($target - $current, 2), $origin . '：补差至成本中心标准价与 Excel 中较大者 ¥' . number_format($target, 2, '.', ''));
            ps_audit('cost', $id, 'raise_trademark_cost', $actor, ['order_id' => (int)$orderId, 'from' => $current, 'to' => $target, 'origin' => $origin]);
        }
        $done = 'raised';
    }
    return $done;
}
