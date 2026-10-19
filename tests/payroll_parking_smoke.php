<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/ProjectPayrollSheet.php';
ps_payroll_extras_ensure();
ppf_ensure();
ppp_ensure();
pps_sheet('2097-10', 55, true); // 事务前完成所有共享表初始化，测试月份的预计结果不缓存。
$pdo = db();
$actor = ['type' => 'admin', 'id' => 1, 'role' => 'finance'];
$eid = 55;
$m = '2097-11'; $n = '2097-12'; $future = '2098-01';
function parking_check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function parking_amount($month, $eid) {
    $items = ps_payroll_extras($month)[$eid]['items'];
    return -array_sum(array_map(function ($x) { return ps_manual_item_slot($x['label'], (float)$x['amount']) === 'parking' ? (float)$x['amount'] : 0; }, $items));
}
$pdo->beginTransaction();
try {
    $q = $pdo->query("SELECT COUNT(*) FROM project_payroll_periods WHERE period BETWEEN '2097-11' AND '2098-01'");
    parking_check(!(int)$q->fetchColumn(), 'Reserved test months already exist');
    $q = $pdo->query("SELECT COUNT(*) FROM project_payroll_parking WHERE employee_id=55 AND effective_month>='2097-11'");
    parking_check(!(int)$q->fetchColumn(), 'Reserved test settings already exist');
    foreach ([$m,$n,$future] as $month) $pdo->prepare('INSERT INTO project_payroll_periods (period) VALUES (?)')->execute([$month]);
    ps_parking_save($eid, $m, '100', $actor);
    parking_check(parking_amount($m,$eid) === 100.0 && parking_amount($n,$eid) === 100.0, 'Monthly inheritance failed');
    $before = ps_parking_settings($m);
    $other = array_diff_key($before, [$eid=>true]);
    $pdo->prepare("INSERT INTO project_payroll_manual_items (employee_id,payroll_month,kind,label,amount,note) VALUES (?,?,'other','车位费',-100,'parking smoke')")->execute([$eid,$m]);
    parking_check(parking_amount($m,$eid) === 100.0, 'Existing manual parking was charged twice');
    $row = pps_sheet($m,$eid)['rows'][0];
    parking_check($row['parking'] === 100.0 && abs($row['net'] - ($row['payable'] - $row['deduct'] - $row['parking'] - $row['reserve'] - $row['personal_ins'] - $row['unit_ins'])) < 0.005, 'Net pay does not include parking deduction');
    ps_parking_save($eid,$n,'150.50',$actor);
    parking_check(parking_amount($m,$eid)===100.0 && parking_amount($n,$eid)===150.5 && parking_amount($future,$eid)===150.5, 'Amount change did not propagate');
    ps_parking_save($eid,$n,'0',$actor);
    parking_check(parking_amount($m,$eid)===100.0 && parking_amount($n,$eid)==0 && parking_amount($future,$eid)==0, 'Cancellation did not persist');
    ps_parking_save($eid,$n,'75',$actor);
    ps_payroll_extras_freeze($n,[],$actor);
    ps_payroll_extras_freeze($n,[],$actor);
    $q=$pdo->prepare("SELECT COUNT(*) FROM project_payroll_manual_items WHERE employee_id=? AND payroll_month=? AND label='车位费'");$q->execute([$eid,$n]);
    parking_check((int)$q->fetchColumn()===1,'Freeze created duplicate parking deductions');
    $pdo->prepare("UPDATE project_payroll_periods SET status='locked' WHERE period=?")->execute([$n]);
    ps_parking_save($eid,$m,'120',$actor);
    parking_check(parking_amount($m,$eid)===120.0 && parking_amount($n,$eid)===75.0 && parking_amount($future,$eid)===120.0,'Locked history changed or old manual deduction survived editing');
    $rejected=false;try { ps_parking_save($eid,$n,'0',$actor); } catch(RuntimeException $e) { $rejected=true; }
    parking_check($rejected,'Locked month accepted changes');
    foreach (['-1','1.001','abc','10000000'] as $bad) {
        $rejected=false;try { ps_parking_save($eid,$m,$bad,$actor); } catch(RuntimeException $e) { $rejected=true; }
        parking_check($rejected,'Invalid amount accepted');
    }
    $rejected=false;try { ps_parking_save($eid,$m,'1',['type'=>'project','id'=>52,'role'=>'customer_service','employee_id'=>82]); } catch(RuntimeException $e) { $rejected=true; }
    parking_check($rejected,'Ordinary employee changed deductions');
    parking_check(array_diff_key(ps_parking_settings($m),[$eid=>true])===$other,'Another employee setting changed');
    parking_check($pdo->inTransaction(),'Transaction was interrupted');
    $pdo->rollBack();echo "PASS: monthly inheritance, no double deduction, net pay, edits/cancel, frozen history, authorization/validation; all fixtures rolled back\n";
} catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
