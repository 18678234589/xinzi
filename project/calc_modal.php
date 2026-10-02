<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/commission_explain.php';
// 订单列表点金额：按需返回“分成计算过程”弹窗内容（HTML 片段）。权限同订单页：非财务只能看自己参与的订单，且只看自己的那一行。
$actor = ps_require_actor();
$id = (int)($_GET['order_id'] ?? 0);
$employeeId = (int)($_GET['employee_id'] ?? 0);
$group = (string)($_GET['group'] ?? '');
$order = ps_order($id, $actor);
if ($actor['role'] !== 'finance' && $employeeId !== (int)$actor['employee_id']) { http_response_code(403); exit('只能查看自己的分成'); }
$people = ps_participants($id);
$person = null;
foreach ($people as $p) if ((int)$p['employee_id'] === $employeeId && $p['commission_group'] === $group) { $person = $p; break; }
if (!$person) { http_response_code(404); exit('未找到该参与人'); }
$snapshot = null;
$calc = null;
$estimated = null;
if (in_array($order['settlement_status'], ['approved', 'locked'], true)) {
    $q = db()->prepare('SELECT * FROM project_commission_snapshots WHERE order_id=? AND employee_id=? AND commission_group=? ORDER BY id DESC LIMIT 1');
    $q->execute([$id, $employeeId, $group]);
    $snapshot = $q->fetch() ?: null;
} else {
    $sum = ps_summary($order, ps_costs($id), $people);
    foreach ($sum['groups'][$group]['people'] ?? [] as $sp) if ((int)$sp['employee_id'] === $employeeId) { $person = $sp; $calc = $sp['calc']; $estimated = $sp['estimated_calc']; }
}
header('Content-Type: text/html; charset=UTF-8');
echo ps_render_calc_content('calcAjax' . $id . '_' . $employeeId . '_' . $group, $person + ['rule' => $person['rule'] ?? null], $order, $calc, $estimated, $snapshot, $id, ps_corr_for_order($id), true);
