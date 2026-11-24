<?php
/**
 * 每日“待完善订单”弹窗：员工本人参与、还没审核、并且有资料缺口（如商品成本没填、订单类型没选、域名使用没确认）的订单，
 * 每天首次打开页面弹一次，在弹窗里直接更正；没更正次日继续弹。确实无法更正的可选“无法更正，交给财务”，之后不再提醒，
 * 财务在“月度上传后自检”里能看到。只列这个人有权限、也知道怎么改的问题（成本、域名使用只给技术；订单类型给客服 / 技术）。
 */
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectOrderItems.php';
require_once __DIR__ . '/ProjectIntake.php';
require_once __DIR__ . '/ProjectWriting.php';

function pdf_ensure()
{
    db()->exec("CREATE TABLE IF NOT EXISTS project_daily_fix_shown (employee_id INT NOT NULL, day DATE NOT NULL, PRIMARY KEY (employee_id, day)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS project_daily_fix_skip (
        employee_id INT NOT NULL, order_id BIGINT UNSIGNED NOT NULL, issue VARCHAR(20) NOT NULL, reason VARCHAR(300) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (employee_id, order_id, issue)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function pdf_eligible(array $actor)
{
    return ($actor['type'] ?? '') === 'employee' && (int)($actor['employee_id'] ?? 0) > 0 && ($actor['role'] ?? '') !== 'finance';
}

/**
 * 这个人当前能更正的问题：[['key','order_id','order_no','business','amount','type','title','help','fields'=>…], …]。
 * type：cost（技术）/ kind（客服、技术）/ domain（技术）。
 */
function pdf_issues(array $actor)
{
    if (!pdf_eligible($actor)) return [];
    pdf_ensure(); poi_ensure();
    $emp = (int)$actor['employee_id']; $role = (string)($actor['role'] ?? '');
    $since = date('Y-m-01', strtotime('-1 month'));
    $q = db()->prepare("SELECT DISTINCT o.id,o.order_no,o.project_type,o.order_kind,o.contract_amount,p.commission_group g FROM project_orders o JOIN project_participants p ON p.order_id=o.id
        WHERE p.employee_id=? AND o.order_date>=? AND o.settlement_status IN ('draft','review') AND o.contract_amount>0 ORDER BY o.order_date,o.id LIMIT 80");
    $q->execute([$emp, $since]);
    $skip = db()->prepare('SELECT issue FROM project_daily_fix_skip WHERE employee_id=? AND order_id=?');
    $out = []; $catalog = ps_business_catalog();
    foreach ($q->fetchAll() as $o) {
        $skip->execute([$emp, (int)$o['id']]); $skipped = array_flip($skip->fetchAll(PDO::FETCH_COLUMN));
        $isTech = $o['g'] === 'technical' || $role === 'technical';
        $base = ['order_id' => (int)$o['id'], 'order_no' => $o['order_no'], 'business' => $o['project_type'], 'amount' => (float)$o['contract_amount']];
        $def = $catalog[ps_business_normalize($o['project_type'])] ?? [];
        // 1. 订单类型没选
        if (!empty($def['kind_required']) && trim((string)$o['order_kind']) === '' && !isset($skipped['kind']) && in_array($role, ['customer_service', 'technical'], true)) {
            $kinds = array_values(array_filter(ps_business_order_kinds($o['project_type']), 'strlen'));
            if ($kinds) $out[] = $base + ['type' => 'kind', 'title' => '订单类型没选', 'help' => '订单类型决定分成比例和每单补助，请按这单实际情况选一个。选错会让提成算错；拿不准就点“无法更正”交给财务。', 'fields' => ['kinds' => $kinds]];
        }
        // 2. 商品要求有成本、却没关联上（技术才有权限填成本）
        if ($isTech && $role === 'technical' && !isset($skipped['cost'])) {
            foreach (poi_items((int)$o['id']) as $it) {
                if ($it['cost_id'] || (empty($it['template_id']) && (float)($it['reported_cost'] ?? 0) <= 0)) continue;
                $std = null;
                if (!empty($it['template_id'])) { $t = db()->prepare('SELECT price,name,specification FROM project_cost_templates WHERE id=?'); $t->execute([(int)$it['template_id']]); $std = $t->fetch() ?: null; }
                $rep = $it['reported_cost'] !== null ? (float)$it['reported_cost'] : null;
                $out[] = $base + ['type' => 'cost', 'title' => '商品“' . $it['item_name'] . '”没有成本', 'item_id' => (int)$it['id'], 'item_name' => $it['item_name'], 'sale' => (float)$it['sale_amount'],
                    'help' => '表格里' . ($rep !== null ? '写的成本是 ¥' . number_format($rep, 2) : '没写成本') . ($std ? '，成本中心标准价（' . $std['name'] . ' ' . $std['specification'] . '）是 ¥' . number_format((float)$std['price'], 2) : '') . '，两者不一致 / 缺失，系统不能自己选。请填这个商品<b>真实的程序成本</b>（采购价，不含域名、SSL 等其他成本），由财务审核通过后才生效。',
                    'fields' => ['reported' => $rep, 'std' => $std ? (float)$std['price'] : null]];
                break; // 一单一次，改完下一条再弹
            }
        }
        // 3. 域名使用没确认（技术）
        if ($role === 'technical' && !isset($skipped['domain']) && !empty($def['resources'])) {
            $r = db()->prepare('SELECT domain_mode FROM project_order_resources WHERE order_id=?'); $r->execute([(int)$o['id']]); $mode = $r->fetchColumn();
            if ($mode === 'pending') $out[] = $base + ['type' => 'domain', 'title' => '域名使用没确认', 'help' => '这单的域名 / 空间怎么来的还没确认。客户自己的域名、或程序套餐已含域名的，选“无需另计”；需要我们另外注册域名的请点“打开订单”选域名后缀。', 'fields' => []];
        }
    }
    foreach ($out as $i => $x) $out[$i]['key'] = $x['type'] . '-' . $x['order_id'] . '-' . ($x['item_id'] ?? 0);
    return $out;
}

/** 今天还没弹过且有问题时返回 true，并记下今天已弹。 */
function pdf_take_today(array $actor)
{
    if (!pdf_eligible($actor)) return false;
    pdf_ensure(); $emp = (int)$actor['employee_id'];
    $q = db()->prepare('SELECT 1 FROM project_daily_fix_shown WHERE employee_id=? AND day=CURDATE()'); $q->execute([$emp]);
    if ($q->fetchColumn()) return false;
    if (!pdf_issues($actor)) return false;
    db()->prepare('INSERT IGNORE INTO project_daily_fix_shown (employee_id,day) VALUES (?,CURDATE())')->execute([$emp]);
    return true;
}

/** 提交更正；$type = cost / kind / domain / skip。返回提示文字。 */
function pdf_apply(array $actor, $type, $orderId, array $post)
{
    if (!pdf_eligible($actor)) throw new RuntimeException('无权限');
    pdf_ensure();
    $emp = (int)$actor['employee_id']; $orderId = (int)$orderId;
    $a = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=?'); $a->execute([$orderId, $emp]);
    if (!$a->fetchColumn()) throw new RuntimeException('你已不在这张订单里');
    $q = db()->prepare('SELECT * FROM project_orders WHERE id=?'); $q->execute([$orderId]); $o = $q->fetch();
    if (!$o || !in_array($o['settlement_status'], ['draft', 'review'], true)) throw new RuntimeException('订单已审核或不存在，不用再改');
    $role = (string)($actor['role'] ?? '');
    if ($type === 'skip') {
        $issue = (string)($post['issue'] ?? ''); if (!in_array($issue, ['kind', 'cost', 'domain'], true)) throw new RuntimeException('操作无效');
        $reason = mb_substr(trim((string)($post['reason'] ?? '')), 0, 300);
        db()->prepare('INSERT INTO project_daily_fix_skip (employee_id,order_id,issue,reason) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason)')->execute([$emp, $orderId, $issue, $reason]);
        ps_audit('order', $orderId, 'daily_fix_skip', $actor, ['issue' => $issue, 'reason' => $reason]);
        return '已记下，交给财务处理，不再提醒你';
    }
    if ($type === 'kind') {
        if (!in_array($role, ['customer_service', 'technical'], true)) throw new RuntimeException('无权限修改订单类型');
        $kind = ps_order_kind_valid(ps_business_normalize($o['project_type']), $post['order_kind'] ?? '');
        if ($kind === '') throw new RuntimeException('请选择订单类型');
        psw_change_kind($orderId, (string)$o['order_kind'], $kind, $actor);
        ps_audit('order', $orderId, 'order_kind', $actor, ['before' => (string)$o['order_kind'], 'order_kind' => $kind, 'via' => '每日待完善弹窗']);
        return '订单类型已改为“' . $kind . '”';
    }
    if ($role !== 'technical') throw new RuntimeException('只有技术可以更正这一项');
    if ($type === 'domain') {
        $pdo = db(); $pdo->beginTransaction();
        try { ps_intake_confirm_resources($orderId, 'none', 0, 0, $actor); $pdo->commit(); } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        return '已确认“无需另计域名成本”';
    }
    if ($type === 'cost') {
        $itemId = (int)($post['item_id'] ?? 0); $amt = trim((string)($post['amount'] ?? ''));
        if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', $amt)) throw new RuntimeException('请填写成本金额（数字，最多两位小数）');
        $it = null; foreach (poi_items($orderId) as $x) if ((int)$x['id'] === $itemId) $it = $x;
        if (!$it || $it['cost_id']) throw new RuntimeException('这个商品已经有成本了，请刷新');
        if ((float)$amt > (float)$it['sale_amount'] + 0.001) throw new RuntimeException('成本不能高于这个商品的售价 ¥' . number_format((float)$it['sale_amount'], 2));
        $pdo = db(); $pdo->beginTransaction();
        try {
            $tpl = !empty($it['template_id']) ? db()->prepare('SELECT * FROM project_cost_templates WHERE id=?') : null; $t = null;
            if ($tpl) { $tpl->execute([(int)$it['template_id']]); $t = $tpl->fetch() ?: null; }
            $reason = '商品明细：' . $it['item_name'] . '（技术在每日待完善弹窗确认成本 ¥' . $amt . ($t ? '，标准价 ¥' . number_format((float)$t['price'], 2) : '') . '）';
            $pdo->prepare("INSERT INTO project_costs (order_id,template_id,template_version,category,item_name,quantity,unit,unit_price,amount,supplier_amount,cost_kind,is_custom,reason,review_status,submitted_by_employee) VALUES (?,?,?,?,?,1,'项',?,?,NULL,'one_time',0,?,'pending',?)")
                ->execute([$orderId, $t['id'] ?? null, $t['version'] ?? null, $it['category'], $it['item_name'], $amt, $amt, $reason, $emp]);
            $costId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE project_order_items SET cost_id=? WHERE id=? AND cost_id IS NULL')->execute([$costId, $itemId]);
            ps_audit('cost', $costId, 'create', $actor, ['order_id' => $orderId, 'amount' => $amt, 'status' => 'pending', 'via' => '每日待完善弹窗']);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        return '已填写成本 ¥' . $amt . '，等财务审核通过后生效';
    }
    throw new RuntimeException('操作无效');
}
