<?php
require_once __DIR__ . '/../includes/ProjectWelfare.php';
function welfare_check($okay,$why) { if (!$okay) throw new RuntimeException($why); }
$policy=pw_policy();
welfare_check((float)$policy['chair_quarter_target']===10000.0,'chair quarterly target');
welfare_check((float)$policy['committee_person_target']===1000.0,'committee target is per person, not 3000');
welfare_check((float)$policy['quarterly_award_rate']===.3,'quarterly welfare limit');
welfare_check(pw_quarter('2026-09-15')==='2026-Q3','quarter key');
welfare_check(pw_bounds('2026-Q4')===['2026-10-01','2027-01-01'],'quarter boundaries');
$blocked=false;
try { pw_close_quarter('2026-Q3'); } catch (RuntimeException $e) { $blocked=true; }
welfare_check($blocked,'no automatic retrospective funding');
$blocked=false;
try { pw_award_quarter('2026-Q4'); } catch (RuntimeException $e) { $blocked=true; }
welfare_check($blocked,'no award before quarter ends');
$handover=db()->query("SELECT h.handover_date,p.name AS previous,n.name AS next_name FROM project_governance_handovers h JOIN employees p ON p.id=h.previous_chair_employee_id JOIN employees n ON n.id=h.next_chair_employee_id WHERE h.handover_date='2026-09-15'")->fetch();
welfare_check($handover && $handover['previous']==='于洋' && $handover['next_name']==='栾鑫','confirmed handover');
$rotation=db()->query("SELECT r.*,g.penalty_effective_from FROM project_governance_rotations r JOIN employees e ON e.id=r.chair_employee_id LEFT JOIN project_governance_rotation_guard g ON g.rotation_id=r.id WHERE e.name='栾鑫' AND r.start_date='2026-09-15' LIMIT 1")->fetch();
welfare_check($rotation && $rotation['end_date']==='2026-12-14' && $rotation['penalty_effective_from']>='2026-09-15','three-month term with retrospective penalty guard');
welfare_check(pg_election_schedule('2026-09-15')===['end'=>'2026-12-14','reminder'=>'2026-12-07'],'remind committee seven days before handover');
welfare_check(pw_balance()===0.0,'no premature welfare funding');
$_SERVER['SCRIPT_NAME']='/project/welfare.php';
$_SERVER['REQUEST_METHOD']='GET';
$_SESSION['admin_id']=1;
$_SESSION['admin_username']='测试财务';
ob_start();
include __DIR__ . '/../project/welfare.php';
$html=ob_get_clean();
welfare_check(strpos($html,'全员福利池')!==false && strpos($html,'¥1,000.00')!==false,'finance welfare view and correct per-member amount');
welfare_check(strpos($html,'批量确认活跃人员')!==false,'finance year-end roster entry');
welfare_check(strpos($html,'部门主管代录权限')!==false,'manager authorization entry');
unset($_SESSION['admin_id'],$_SESSION['admin_username']);
$_SESSION['project_user_id']=(int)db()->query("SELECT u.id FROM project_users u JOIN project_governance_members m ON m.employee_id=u.employee_id WHERE m.governance_role='committee' AND m.is_active=1 AND u.is_active=1 AND u.phone IS NOT NULL AND u.password_changed_at IS NOT NULL ORDER BY u.id LIMIT 1")->fetchColumn();
welfare_check($_SESSION['project_user_id']>0,'committee test login');
$_SERVER['SCRIPT_NAME']='/project/governance_election.php';
ob_start();
include __DIR__ . '/../project/governance_election.php';
$electionHtml=ob_get_clean();
welfare_check(strpos($electionHtml,'2026-12-07')!==false && strpos($electionHtml,'仅监委会可见')!==false,'committee election notice page');
$_SERVER['SCRIPT_NAME']='/project/welfare.php';
ob_start();
include __DIR__ . '/../project/welfare.php';
$staffHtml=ob_get_clean();
welfare_check(strpos($staffHtml,'我的贡献与奖励')!==false && strpos($staffHtml,'部门主管代录权限')===false,'staff sees own welfare without finance controls');
echo "project welfare smoke OK\n";
