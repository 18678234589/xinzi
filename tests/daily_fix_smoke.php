<?php
// 每日待完善弹窗：检测 → 更正（成本 / 订单类型）→ 无法更正不再提醒 → 一天只弹一次。仅限本地隔离库。
if (PHP_SAPI !== 'cli') exit(1);
$root = getenv('HEZUOSHANG_APP') ?: dirname(__DIR__);
require_once $root . '/includes/ProjectDailyFix.php';
function df_check($n, $ok) { if (!$ok) throw new RuntimeException($n); echo "PASS $n\n"; }
function df_rej($fn) { try { $fn(); } catch (RuntimeException $e) { return $e->getMessage(); } return ''; }
$p = db();
if (!preg_match('/_(test|ci|sandbox)$/', (string)$p->query('SELECT DATABASE()')->fetchColumn())) exit("refuse: not an isolated database\n");
$emp = (int)$p->query('SELECT id FROM employees ORDER BY id LIMIT 1')->fetchColumn();
$tech = ['type' => 'employee', 'id' => 900, 'employee_id' => $emp, 'role' => 'technical'];
$cs = ['type' => 'employee', 'id' => 901, 'employee_id' => $emp, 'role' => 'customer_service'];
$tag = 'DF-' . bin2hex(random_bytes(4)); $ids = [];
try {
    pdf_ensure(); poi_ensure();
    $p->prepare("DELETE FROM project_daily_fix_shown WHERE employee_id=?")->execute([$emp]);
    $today = date('Y-m-d');
    $p->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,order_date,delivery_status,settlement_status) VALUES (?,'df','网站模板','df',1300,?,'finished','draft')")->execute([$tag, $today]);
    $oid = (int)$p->lastInsertId(); $ids[] = $oid;
    $p->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','模板技术',1)")->execute([$oid, $emp]);
    $tid = (int)$p->query("SELECT id FROM project_cost_templates WHERE name='JSP展示中级版' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    $p->prepare("INSERT INTO project_order_items (order_id,item_key,item_name,category,sale_amount,reported_cost,template_id) VALUES (?,'k1','5年JSP展示中级版','program',1300,590,?)")->execute([$oid, $tid ?: null]);
    $iss = array_values(array_filter(pdf_issues($tech), function ($x) use ($oid) { return $x['order_id'] === $oid; }));
    df_check('技术看到“商品没有成本”，并给出表格成本', count($iss) >= 1 && $iss[0]['type'] === 'cost' && $iss[0]['fields']['reported'] == 590);
    df_check('客服看不到成本问题（无权限不打扰）', !array_filter(pdf_issues($cs), function ($x) use ($oid) { return $x['order_id'] === $oid && $x['type'] === 'cost'; }));
    df_check('一天只弹一次：第一次弹，第二次不弹', pdf_take_today($tech) === true && pdf_take_today($tech) === false);
    df_check('成本超过售价被拒', df_rej(function () use ($tech, $oid, $iss) { pdf_apply($tech, 'cost', $oid, ['item_id' => $iss[0]['item_id'], 'amount' => '9999']); }) !== '');
    pdf_apply($tech, 'cost', $oid, ['item_id' => $iss[0]['item_id'], 'amount' => '590']);
    $c = $p->query("SELECT c.amount,c.review_status FROM project_order_items i JOIN project_costs c ON c.id=i.cost_id WHERE i.order_id=$oid")->fetch();
    df_check('更正后成本进入待财务审核并关联到商品，不再提醒', $c && (float)$c['amount'] == 590 && $c['review_status'] === 'pending' && !array_filter(pdf_issues($tech), function ($x) use ($oid) { return $x['order_id'] === $oid && $x['type'] === 'cost'; }));
    // 订单类型：网站模板需要选类型时
    $p->exec("UPDATE project_orders SET order_kind='' WHERE id=$oid");
    $k = array_values(array_filter(pdf_issues($cs), function ($x) use ($oid) { return $x['order_id'] === $oid && $x['type'] === 'kind'; }));
    if ($k) {
        pdf_apply($cs, 'kind', $oid, ['order_kind' => $k[0]['fields']['kinds'][0]]);
        df_check('选订单类型后写回订单', $p->query("SELECT order_kind FROM project_orders WHERE id=$oid")->fetchColumn() === $k[0]['fields']['kinds'][0]);
    } else echo "SKIP 该业务不要求选订单类型\n";
    // 无法更正 → 不再提醒
    $p->exec("DELETE c FROM project_costs c WHERE c.order_id=$oid"); $p->exec("UPDATE project_order_items SET cost_id=NULL WHERE order_id=$oid");
    df_check('删掉成本后重新出现', (bool)array_filter(pdf_issues($tech), function ($x) use ($oid) { return $x['order_id'] === $oid && $x['type'] === 'cost'; }));
    pdf_apply($tech, 'skip', $oid, ['issue' => 'cost', 'reason' => '不知道真实成本']);
    df_check('选“无法更正”后不再提醒', !array_filter(pdf_issues($tech), function ($x) use ($oid) { return $x['order_id'] === $oid && $x['type'] === 'cost'; }));
    df_check('别人的订单不能改', df_rej(function () use ($oid) { pdf_apply(['type' => 'employee', 'id' => 1, 'employee_id' => 99999, 'role' => 'technical'], 'skip', $oid, ['issue' => 'cost']); }) !== '');
    df_check('财务账号不弹窗', pdf_issues(['type' => 'admin', 'id' => 1, 'role' => 'finance']) === []);
} finally {
    $p->prepare("DELETE FROM project_daily_fix_shown WHERE employee_id=?")->execute([$emp]);
    foreach ($ids as $id) {
        $p->exec("DELETE FROM project_daily_fix_skip WHERE order_id=$id");
        foreach (['project_order_items', 'project_costs', 'project_participants', 'project_order_resources', 'project_order_sources'] as $t) { try { $p->exec("DELETE FROM $t WHERE order_id=$id"); } catch (Throwable $e) {} }
        $p->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id=$id"); $p->exec("DELETE FROM project_orders WHERE id=$id");
    }
}
