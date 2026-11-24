<?php
// 完成情况汇总表是依据：未完成 → 自动撤回已审核分成、并阻止结算 / 随手标完成 / 预计分成；表里改为完成后放行。仅限本地隔离库。
if (PHP_SAPI !== 'cli') exit(1);
$root = getenv('HEZUOSHANG_APP') ?: dirname(__DIR__);
require_once $root . '/includes/ProjectCompletionSync.php';
require_once $root . '/includes/ProjectMiniappDelivery.php';
function sheet_auth_check($name, $ok) { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
function sheet_auth_rejects($fn) { try { $fn(); } catch (RuntimeException $e) { return $e->getMessage(); } return ''; }
$p = db();
if (!preg_match('/_(test|ci|sandbox)$/', (string)$p->query('SELECT DATABASE()')->fetchColumn())) exit("refuse: not an isolated database\n");
$finance = ['type' => 'admin', 'id' => 1, 'role' => 'finance'];
$tech = ['type' => 'employee', 'id' => 33, 'employee_id' => 33, 'role' => 'technical'];
$tag = 'SHEET-AUTH-' . bin2hex(random_bytes(5)); $ids = []; $nos = [];
try {
    $ins = $p->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,order_date,delivery_status,settlement_status) VALUES (?,'sheet test','AI网站定制','sheet test',1000,'2099-01-01',?,?)");
    foreach ([['finished', 'approved'], ['finished', 'draft'], ['finished', 'approved']] as $i => $r) { $nos[$i] = $tag . '-' . $i; $ins->execute([$nos[$i], $r[0], $r[1]]); $ids[$i] = (int)$p->lastInsertId(); }
    foreach ([0, 2] as $i) $p->exec("INSERT INTO project_commission_snapshots (order_id,employee_id,commission_group,role_name,income_amount,direct_cost,contribution_profit,rule_id,rate,group_weight,commission_amount,calc_note,payroll_month) VALUES ({$ids[$i]},33,'technical','前端',1000,0,1000,1,0.2,1,200,'t','2099-01')");
    // 订单 2 已核算月份的快照：撤不了，要列出来
    $p->exec("DELETE FROM project_commission_snapshots WHERE order_id={$ids[2]}");
    $p->exec("INSERT INTO project_commission_snapshots (order_id,employee_id,commission_group,role_name,income_amount,direct_cost,contribution_profit,rule_id,rate,group_weight,commission_amount,calc_note,payroll_month) VALUES ({$ids[2]},33,'technical','前端',1000,0,1000,1,0.2,1,200,'t','2000-01')");
    $old = ps_setting_get('refund_settled_through', ''); ps_setting_set('refund_settled_through', '2000-12', 1);

    $map = [$nos[0] => 'unf', $nos[1] => 'unf', $nos[2] => 'unf', $tag . '-NOTYET' => 'unf'];
    $plan = pcs_plan($map);
    $res = pcs_apply($plan, true, $finance, '订单汇总表', $map);
    $o0 = $p->query("SELECT settlement_status,delivery_status FROM project_orders WHERE id={$ids[0]}")->fetch();
    sheet_auth_check('approved + sheet unfinished → auto revoked, commission removed', $res['reopened'] === 1 && $o0['settlement_status'] === 'draft' && $o0['delivery_status'] === 'unfinished'
        && !(int)$p->query("SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id={$ids[0]}")->fetchColumn());
    sheet_auth_check('settled-month order is reported, not touched', count($res['skipped']) === 1 && strpos($res['skipped'][0], $nos[2]) === 0
        && $p->query("SELECT settlement_status FROM project_orders WHERE id={$ids[2]}")->fetchColumn() === 'approved');
    sheet_auth_check('sheet status persisted (incl. order not in system yet)', pcs_sheet_status($nos[0]) === 'unf' && pcs_sheet_status($tag . '-NOTYET') === 'unf');
    sheet_auth_check('unapproved finished order downgraded', $p->query("SELECT delivery_status FROM project_orders WHERE id={$ids[1]}")->fetchColumn() === 'unfinished');

    // 即使有人又把它标成完成，也不能结算 / 随手标完成，预计分成也不计
    $p->exec("UPDATE project_orders SET delivery_status='finished' WHERE id={$ids[0]}");
    $row = $p->query("SELECT * FROM project_orders WHERE id={$ids[0]}")->fetch();
    sheet_auth_check('approval refused while sheet says unfinished', strpos(sheet_auth_rejects(function () use ($ids, $finance) { ps_approve_order($ids[0], $finance, '2099-01'); }), '汇总表') !== false);
    sheet_auth_check('hand-marking finished refused', strpos(sheet_auth_rejects(function () use ($ids, $tech) { ps_can_mark_order_finished($tech, $ids[0]); }), '汇总表') !== false);
    sheet_auth_check('forecast skips website order the sheet says unfinished', ps_forecast_group_skip($row, 'technical') && ps_forecast_unshipped($row));

    // 财务按订单号确认 / 下次上传写完成 → 放行
    pcs_manual_finish($ids[0], $finance);
    $row = $p->query("SELECT * FROM project_orders WHERE id={$ids[0]}")->fetch();
    sheet_auth_check('manual confirmation by finance lifts the block', pcs_sheet_status($nos[0]) === 'fin' && !ps_forecast_group_skip($row, 'technical') && sheet_auth_rejects(function () use ($tech, $ids) { ps_can_mark_order_finished($tech, $ids[0]); }) === '');
    sheet_auth_check('later upload saying finished overrides', (function () use ($nos, $finance, $p) { pcs_record([$nos[1] => 'fin'], $finance, '订单汇总表'); return pcs_sheet_status($nos[1]) === 'fin'; })());
} finally {
    if (isset($old)) ps_setting_set('refund_settled_through', $old, 1);
    foreach ($ids as $id) {
        $p->exec('DELETE FROM project_commission_snapshots WHERE order_id=' . $id);
        $p->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id=" . $id);
        $p->exec('DELETE FROM project_orders WHERE id=' . $id);
    }
    try { $p->exec("DELETE FROM project_completion_sheet WHERE order_no LIKE '" . $tag . "%'"); } catch (Throwable $e) {}
}
