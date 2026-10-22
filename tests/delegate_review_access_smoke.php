<?php
// 授权审核人员（如网站人事冯超）：能审核所授权业务订单的成本、确认资源；本人参与的订单、其他业务不行。
// 会写入测试数据，只允许本地隔离库：DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php tests/delegate_review_access_smoke.php
if (PHP_SAPI !== 'cli' || !preg_match('/_(test|ci|sandbox)$/', (string)getenv('DB_NAME')) || getenv('DB_PORT') !== '13399') { fwrite(STDERR, "只能在本地隔离库运行\n"); exit(1); }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectBatchReview.php';
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$ids = []; $emp = 0;
try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS project_batch_review_delegates (employee_id INT NOT NULL, business_name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (employee_id, business_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute(['dlg_' . $tag, '网站人事', 'x']); $emp = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO project_batch_review_delegates (employee_id,business_name) VALUES (?,?)')->execute([$emp, 'AI网站定制']);
    $mk = function ($type, $mine) use ($pdo, &$ids, $emp, $tag) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试',?,'','内部',100,0,'2026-09-05','unfinished','draft','测试')")->execute(['DG' . $tag . count($ids), $type]);
        $id = (int)$pdo->lastInsertId(); $ids[] = $id;
        if ($mine) $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','前端',1)")->execute([$id, $emp]);
        return ['id' => $id, 'project_type' => $type];
    };
    $actor = ['type' => 'employee', 'id' => 99999, 'employee_id' => $emp, 'role' => 'governance'];
    $own = $mk('AI网站定制', true); $other = $mk('AI网站定制', false); $wrongBiz = $mk('网站模板', false);
    $check(pbr_can_review_order($actor, $other) === true, '授权业务的他人订单：可审核');
    $check(pbr_can_review_order($actor, $own) === false, '本人参与的订单：不能自审');
    $check(pbr_can_review_order($actor, $wrongBiz) === false, '未授权的业务：不能审核');
    $check(pbr_can_review_order(['type' => 'employee', 'id' => 1, 'employee_id' => 1, 'role' => 'technical'], $other) === false, '没有授权的人：不能审核');
    $check(pbr_can_review_order(['type' => 'admin', 'id' => 1, 'role' => 'finance'], $other) === true, '财务：可以');
    // 成本审核动作
    $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status) VALUES (?,'outsourcing','外包',1,'项',5,5,'one_time',1,'测试','pending')")->execute([$other['id']]);
    $costId = (int)$pdo->lastInsertId();
    $run = function ($order) use ($pdo, $actor, $costId) {
        $_POST = ['cost_id' => $costId, 'decision' => 'approved', 'review_note' => '冯超审核']; $id = $order['id']; $finance = false; $reviewer = pbr_can_review_order($actor, $order);
        try { include __DIR__ . '/../project/order/actions/cost/review_cost.php'; return null; } catch (RuntimeException $e) { return $e->getMessage(); }
    };
    $check($run($wrongBiz) === '无权限', '未授权业务的成本动作被拒绝');
    $check($run($other) === null && $pdo->query("SELECT review_status FROM project_costs WHERE id=$costId")->fetchColumn() === 'approved', '授权业务的成本动作通过并记录');
} finally {
    foreach ($ids as $id) { foreach (['project_costs', 'project_participants'] as $t) $pdo->exec("DELETE FROM $t WHERE order_id=$id"); $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type IN ('order','cost') AND entity_id=$id"); $pdo->exec("DELETE FROM project_orders WHERE id=$id"); }
    if ($emp) { $pdo->exec("DELETE FROM project_batch_review_delegates WHERE employee_id=$emp"); $pdo->exec("DELETE FROM employees WHERE id=$emp"); }
}
echo "全部通过，测试数据已清理\n";
