<?php
// 部门主管能看到本部门成员参与的订单（列表条件 / 打开结算单 / 预计分成），非本部门的人不行。
// 会写入测试数据，只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/dept_head_view_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectDeptHead.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会写入数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$emp = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=?'); $q->execute([$name]); return (int)$q->fetchColumn(); };
$fang = $emp('房烁'); $yu = $emp('于洋'); $song = $emp('宋倩倩');
$actorOf = function ($id) { return ['type' => 'employee', 'id' => 0, 'employee_id' => $id, 'role' => 'customer_service']; };
$ids = [];
try {
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','备案-提成','备案','美呀美',100,'2026-09-23','unfinished','draft','测试')")->execute(["DH$tag"]);
    $oid = (int)$pdo->lastInsertId(); $ids[] = $oid;
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$oid, $fang]);
    $check(pdh_departments($actorOf($yu)) === ['网站售后部'] || in_array('网站售后部', pdh_departments($actorOf($yu)), true), '于洋是网站售后部主管');
    $check(pdh_departments(['employee_id' => $yu, 'role' => 'finance']) === [], '财务账号不走主管范围（本来就能看全部）');
    $check(pdh_can_view_order($oid, $actorOf($yu)), '主管能看本部门成员房烁参与的订单');
    $check(!pdh_can_view_order($oid, $actorOf($song)), '不是主管的人（宋倩倩）不能借此看别人的订单');
    list($sql, $params) = pdh_order_condition($actorOf($yu));
    $q = $pdo->prepare("SELECT COUNT(*) FROM project_orders o WHERE o.id=? AND $sql"); $q->execute(array_merge([$oid], $params));
    $check((int)$q->fetchColumn() === 1, '列表条件能选出这张订单');
    $check(pdh_order_condition($actorOf($song)) === ['', []], '非主管没有额外的列表条件');
    $rp = ps_participants($oid)[0];
    $check(pdh_manages_person($actorOf($yu), $rp) && !pdh_manages_person($actorOf($song), $rp), '列表里主管能看到本部门成员的预计分成，别人不能');
    echo "全部通过\n";
} finally {
    foreach ($ids as $id) { $pdo->exec("DELETE FROM project_participants WHERE order_id=$id"); $pdo->exec("DELETE FROM project_orders WHERE id=$id"); }
}
