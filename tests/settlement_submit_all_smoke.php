<?php
// 一键申请往月全部可结算订单：按月各提交一份，没有核对确认（绿色）的月份跳过并说明，重复点不会重复申请。只允许本地隔离库（会提交数据）。
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
$past = psr_available_past($eid);
$check($past['n'] > 0 && $past['months'] && $past['amount'] !== 0.0 || $past['n'] > 0, '往月有可申请订单：' . $past['n'] . ' 单 / ' . count($past['months']) . ' 个月');
$months = array_keys($past['months']);
$green = $months[0];
foreach ($months as $m) if ($m !== $green) $pdo->prepare('DELETE FROM project_payroll_flags WHERE employee_id=? AND payroll_month=?')->execute([$eid, $m]);
ppf_save($fin, $eid, $green, 'green', '测试核对无异议', '', false);
$res = psr_submit_all($actor);
$check(count($res['done']) === 1 && $res['done'][0][0] === $green && $res['done'][0][1] === count($past['months'][$green]), "已核对的 $green 整月提交");
$check(count($res['skipped']) === count($months) - 1, '没核对确认的月份跳过：' . count($res['skipped']));
$again = psr_submit_all($actor);
$check(!$again['done'], '重复点不会重复申请');
$check(psr_available_past($eid)['n'] === $past['n'] - count($past['months'][$green]), '提交后可申请订单数相应减少');
$thrown = false; try { psr_submit_all($fin); } catch (RuntimeException $e) { $thrown = true; }
$check($thrown, '财务账号不能代替本人一键申请');
echo "settlement_submit_all_smoke 通过\n";
