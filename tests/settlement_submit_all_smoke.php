<?php
// 往月工资可先申请、后财务核对；重复点不会重复申请。只允许本地隔离库（会提交数据）。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectSettlementRequest.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
psr_ensure();
$from = date('Y-m', strtotime('first day of -12 month'));
$row = $pdo->query("SELECT c.employee_id,COUNT(DISTINCT c.payroll_month) m FROM project_commission_snapshots c JOIN project_users u ON u.employee_id=c.employee_id AND u.is_active=1 WHERE c.payroll_month>='$from' AND c.payroll_month<DATE_FORMAT(CURDATE(),'%Y-%m') GROUP BY c.employee_id ORDER BY m DESC LIMIT 1")->fetch();
if (!$row) { echo "  [SKIP] 隔离库里近 12 个月没有已审核订单\n"; exit(0); }
$eid = (int)$row['employee_id'];
$user = $pdo->query("SELECT id FROM project_users WHERE employee_id=$eid AND is_active=1 LIMIT 1")->fetchColumn();
$actor = ['type' => 'employee', 'id' => (int)$user, 'employee_id' => $eid, 'role' => 'customer_service'];
$fin = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT MIN(id) FROM admins')->fetchColumn(), 'employee_id' => null, 'role' => 'finance'];
$pdo->prepare('DELETE i FROM project_settlement_request_items i WHERE i.employee_id=?')->execute([$eid]);
$pdo->prepare('DELETE FROM project_settlement_requests WHERE employee_id=?')->execute([$eid]);
$q=$pdo->prepare('SELECT DISTINCT payroll_month FROM project_commission_snapshots WHERE employee_id=? AND payroll_month>=? AND payroll_month<? ORDER BY payroll_month DESC');
$q->execute([$eid,$from,date('Y-m')]);$months=$q->fetchAll(PDO::FETCH_COLUMN);
$green = $months[0];
foreach ($months as $m) if ($m !== $green) $pdo->prepare('DELETE FROM project_payroll_flags WHERE employee_id=? AND payroll_month=?')->execute([$eid, $m]);
ppf_save($fin, $eid, $green, 'yellow', '待财务核对', '', false);
$past = psr_available_past($eid);
$check($past['n'] === 1 && $past['amount'] > 0, '已有工资记录的往月可以先提交待财务核对的申请');
$res = psr_submit_all($actor);
$check(count($res['done']) === 1 && $res['done'][0][0] === $green && $res['done'][0][1] === $past['months'][$green], "$green 按实结算金额提交财务审核");
$check(!$res['skipped'], '待核对月份正常进入申请队列');
$again = psr_submit_all($actor);
$check(!$again['done'], '重复点不会重复申请');
$check(psr_available_past($eid)['n'] === 0, '提交后不再重复显示可申请月份');
$thrown = false; try { psr_submit_all($fin); } catch (RuntimeException $e) { $thrown = true; }
$check($thrown, '财务账号不能代替本人一键申请');
echo "settlement_submit_all_smoke 通过\n";
