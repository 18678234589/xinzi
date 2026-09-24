<?php
require_once __DIR__ . '/../includes/ProjectWelfare.php';
function welfare_check($okay,$why) { if (!$okay) throw new RuntimeException($why); }
$policy=pw_policy();
welfare_check((float)$policy['chair_quarter_target']===10000.0,'chair quarterly target');
welfare_check((float)$policy['committee_person_target']===1000.0,'committee target is per person, not 3000');
welfare_check((float)$policy['quarterly_award_rate']===.3,'quarterly welfare limit');
welfare_check(pw_quarter('2026-09-14')==='2026-Q3' && pw_quarter('2026-09-15')==='2026-Q4','handover day starts a new quarter');
welfare_check(pw_quarter('2026-12-14')==='2026-Q4' && pw_quarter('2026-12-15')==='2027-Q1','no amount crosses to successor term');
welfare_check(pw_bounds('2026-Q4')===['2026-09-15','2026-12-15'],'term boundaries');
welfare_check(pw_bounds('2027-Q1')===['2026-12-15','2027-03-15'],'successor has own three-month term');
welfare_check(pw_previous_quarter('2026-12-15')==='2026-Q4' && pw_previous_quarter('2027-01-01')==='2026-Q4','daily job keeps settling the completed term across calendar year');
welfare_check(pw_quarter('2026-09-24') >= $policy['effective_quarter'],'current term contributions are allowed now');
welfare_check(pw_chair_earned($policy,3000,0,0)===10000.0,'chair can earn full 10000 within one term');
welfare_check(pw_chair_earned($policy,5000,0,0)===10000.0,'innovation cannot exceed the 3000 cap');
welfare_check(pw_chair_earned($policy,0,-500,-300)===6200.0,'manual and automatic deductions each count once');
welfare_check(pw_committee_earned(1000,20,0)===1000.0,'committee member can earn full 1000');
welfare_check(pw_committee_earned(1000,21,0)===1000.0 && pw_committee_earned(1000,1,100)===150.0,'committee cap and explicit award do not double-count');
db()->beginTransaction();
try {
    $before = pw_balance();
    $beforeFirstTerm = pw_balance_as_of('2026-Q4');
    pw_ledger('smoke:cumulative:2026-Q4','quarter_remainder',200,'2026-Q4',null,'回滚测试：第一届结转');
    pw_ledger('smoke:cumulative:2027-Q1','quarter_remainder',300,'2027-Q1',null,'回滚测试：第二届结转');
    welfare_check(pw_balance() === round($before+500,2),'welfare pool accumulates across terms');
    welfare_check(pw_balance_as_of('2026-Q4') === round($beforeFirstTerm+200,2),'successor funding does not enter prior term');
} finally { if (db()->inTransaction()) db()->rollBack(); }
$blocked=false;
try { pw_close_quarter('2026-Q3'); } catch (RuntimeException $e) { $blocked=true; }
welfare_check($blocked,'no automatic retrospective funding');
if (date('Y-m-d') < '2026-12-15') {
    $blocked=false;
    try { pw_award_quarter('2026-Q4'); } catch (RuntimeException $e) { $blocked=true; }
    welfare_check($blocked,'no award before term ends');
}
$handover=db()->query("SELECT h.handover_date,p.name AS previous,n.name AS next_name FROM project_governance_handovers h JOIN employees p ON p.id=h.previous_chair_employee_id JOIN employees n ON n.id=h.next_chair_employee_id WHERE h.handover_date='2026-09-15'")->fetch();
welfare_check($handover && $handover['previous']==='于洋' && $handover['next_name']==='栾鑫','confirmed handover');
$rotation=db()->query("SELECT r.*,g.penalty_effective_from FROM project_governance_rotations r JOIN employees e ON e.id=r.chair_employee_id LEFT JOIN project_governance_rotation_guard g ON g.rotation_id=r.id WHERE e.name='栾鑫' AND r.start_date='2026-09-15' LIMIT 1")->fetch();
welfare_check($rotation && $rotation['end_date']==='2026-12-14' && $rotation['penalty_effective_from']>='2026-09-15','three-month term with retrospective penalty guard');
db()->beginTransaction();
try {
    $before = pg_chair_pool('2026-09-15');
    $reviewer = (int)db()->query("SELECT employee_id FROM project_governance_members WHERE governance_role='committee' AND is_active=1 ORDER BY employee_id LIMIT 1")->fetchColumn();
    db()->prepare("INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,review_state,created_by_employee_id) VALUES ('chair',?,'2026-09-24','三天脑洞','回滚测试：有效脑洞默认奖励','approved',?)")
        ->execute([(int)$rotation['chair_employee_id'],$reviewer]);
    db()->prepare("INSERT INTO project_governance_records (record_kind,owner_employee_id,record_date,category,description,review_state,created_by_employee_id) VALUES ('chair',?,'2026-12-15','三天脑洞','回滚测试：下一任期不能计入本届','approved',?)")
        ->execute([(int)$rotation['chair_employee_id'],$reviewer]);
    $after = pg_chair_pool('2026-09-15');
    welfare_check(round($after['approved_reward']-$before['approved_reward'],2)===100.0,'approved idea receives 100 once and successor-term records stay separate');
} finally { if (db()->inTransaction()) db()->rollBack(); }
welfare_check(pg_election_schedule('2026-09-15')===['end'=>'2026-12-14','reminder'=>'2026-12-07'],'remind committee seven days before handover');
welfare_check(pw_balance()>=0.0,'welfare ledger balance must not overdraw');
$_SERVER['SCRIPT_NAME']='/project/welfare.php';
$_SERVER['REQUEST_METHOD']='GET';
$_SESSION['admin_id']=1;
$_SESSION['admin_username']='测试财务';
ob_start();
include __DIR__ . '/../project/welfare.php';
$html=ob_get_clean();
welfare_check(strpos($html,'全员福利池')!==false && strpos($html,'¥1,000.00')!==false,'finance welfare view and correct per-member amount');
welfare_check(strpos($html,'池余额跨任期累计')!==false,'page distinguishes per-term cap from cumulative welfare pool');
welfare_check(strpos($html,'生成待核对名单')!==false && strpos($html,'确认本年度名单')!==false,'finance must review the roster before year-end awards');
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
