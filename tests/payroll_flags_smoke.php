<?php
// 结算核对标记：差距 5% 判定、标黄红须写理由、只有绿色本人才能申请结算、通知本人、原因分析不报错。只允许本地隔离库（会提交数据）。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('BASE_URL', '');
require_once __DIR__ . '/../includes/ProjectSettlementRequest.php';
require_once __DIR__ . '/../includes/ProjectPayrollSheet.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$throws = function (callable $f) { try { $f(); } catch (RuntimeException $e) { return $e->getMessage(); } return null; };
ppf_ensure(); psr_ensure();
$check(abs(ppf_diff(105, 100) - 0.05) < 1e-9 && ppf_diff(1, null) === null && ppf_diff(1, 0) === null, '差距计算 / 无对照金额返回 null');
$row = $pdo->query("SELECT c.employee_id,c.payroll_month FROM project_commission_snapshots c JOIN project_users u ON u.employee_id=c.employee_id AND u.is_active=1 WHERE c.payroll_month < DATE_FORMAT(CURDATE(),'%Y-%m') AND NOT EXISTS (SELECT 1 FROM project_payroll_flags f WHERE f.employee_id=c.employee_id AND f.payroll_month=c.payroll_month) AND NOT EXISTS (SELECT 1 FROM project_settlement_requests r WHERE r.employee_id=c.employee_id AND r.payroll_month=c.payroll_month) GROUP BY c.employee_id,c.payroll_month HAVING COUNT(DISTINCT c.order_id)>=3 ORDER BY c.payroll_month DESC LIMIT 1")->fetch();
$eid = (int)$row['employee_id']; $month = $row['payroll_month'];
$user = (int)$pdo->query("SELECT id FROM project_users WHERE employee_id=$eid AND is_active=1 LIMIT 1")->fetchColumn();
$actor = ['type' => 'employee', 'id' => $user, 'employee_id' => $eid, 'role' => 'customer_service'];
$fin = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT MIN(id) FROM admins')->fetchColumn(), 'employee_id' => null, 'role' => 'finance'];
$ids = array_slice(array_column(psr_orders($eid, $month), 'order_id'), 0, 2);
$check($throws(function () use ($actor, $month, $ids) { psr_submit($actor, $month, $ids); }) !== null, '财务没标绿：本人不能申请结算');
$check($throws(function () use ($actor, $month) { ppf_save($actor, 1, $month, 'green', '', '', false); }) !== null, '合作人员本人不能给自己标绿');
$check($throws(function () use ($fin, $eid, $month) { ppf_save($fin, $eid, $month, 'red', '', '100', false); }) !== null, '标红不写理由被拒');
$check($throws(function () use ($fin, $eid, $month) { ppf_save($fin, $eid, $month, 'purple', 'x', '', false); }) !== null, '颜色无效被拒');
ppf_save($fin, $eid, $month, 'yellow', '请补充 3 张订单的成本', '1000', true);
$flags = ppf_all($month);
$check($flags[$eid]['color'] === 'yellow' && (float)$flags[$eid]['ref_net'] === 1000.0, '标黄保存，对照金额 1000');
$msg = $pdo->query("SELECT body FROM project_messages WHERE employee_id=$eid AND category='payroll_check' ORDER BY id DESC LIMIT 1")->fetchColumn();
$check(is_string($msg) && mb_strpos($msg, '请补充 3 张订单的成本') !== false, '本人收到站内信，写明理由');
$check($throws(function () use ($actor, $month, $ids) { psr_submit($actor, $month, $ids); }) !== null, '标黄时仍不能申请结算');
$sheet = pps_sheet($month, $eid, true)['rows'][0];
$check($sheet['flag']['color'] === 'yellow' && $sheet['ref'] === 1000.0 && $sheet['diff'] !== null, '报酬表行带标记、对照金额、差距（财务视图）');
$own = pps_sheet($month, $eid, false)['rows'][0];
$check($own['flag']['color'] === 'yellow' && !array_key_exists('ref', $own), '本人视图只有颜色和理由，没有对照金额');
$rs = ppf_reasons($eid, $month, $sheet, 1000);
$check(is_array($rs) && count($rs) >= 1 && isset($rs[0]['text'], $rs[0]['who']), '差异原因分析：' . count($rs) . ' 条，如“' . $rs[0]['text'] . '”');
ppf_save($fin, $eid, $month, 'green', '核对无异议', '1000', true);
$rid = pspr_submit($actor, $month)['id'];
$check($rid > 0, '标绿后本人可以申请结算');
echo "\n=== 结算核对标记测试全部通过 ===\n";
