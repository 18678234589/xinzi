<?php
if (PHP_SAPI !== 'cli') exit(1);
$root=getenv('HEZUOSHANG_APP') ?: dirname(__DIR__);
require_once $root.'/includes/ProjectSettlementRequest.php';
function pay_speed_check($name,$ok){if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
$p=db();$employee=0;$orders=[];$requests=[];$month='2099-01';$actor=['type'=>'admin','id'=>1,'role'=>'finance'];
try {
    $p->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute(['PAY-SPEED-'.bin2hex(random_bytes(6)),'测试','x']);$employee=(int)$p->lastInsertId();
    foreach ([13,7] as $i=>$amount) {
        $p->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,order_date,delivery_status,settlement_status) VALUES (?,'speed test','网站模板','speed test',100,'2099-01-01','unfinished','draft')")->execute(['PAY-SPEED-'.$employee.'-'.$i]);$oid=(int)$p->lastInsertId();$orders[]=$oid;
        $p->prepare("INSERT INTO project_commission_snapshots (order_id,employee_id,commission_group,role_name,income_amount,direct_cost,service_fee,contribution_profit,rule_id,calc_mode,rate,group_weight,commission_amount,commission_exact,subsidy_amount,calc_note,payroll_month) VALUES (?,?,'technical','test',100,0,0,100,0,'pool',0.1,1,?,?,0,'test',?)")->execute([$oid,$employee,$i===0?15:7,$i===0?15:7,$month]);
        $p->prepare("INSERT INTO project_settlement_requests (employee_id,payroll_month,status,amount,order_count) VALUES (?,?,'pending',?,1)")->execute([$employee,$month,$amount]);$rid=(int)$p->lastInsertId();$requests[]=$rid;
        $p->prepare('INSERT INTO project_settlement_request_items (request_id,employee_id,order_id,payroll_month,amount) VALUES (?,?,?,?,?)')->execute([$rid,$employee,$oid,$month,$amount]);
    }
    $p->prepare("INSERT INTO project_commission_adjustments (order_id,employee_id,commission_group,amount,payroll_month,reason,calc_note,created_by_admin) VALUES (?,?,'technical',-2,?,'test','test',1)")->execute([$orders[0],$employee,$month]);
    $get=function($rid)use($p){return $p->query('SELECT * FROM project_settlement_requests WHERE id='.(int)$rid)->fetch();};
    pay_speed_check('ordinary check includes refund adjustments',psr_check($get($requests[0]))['ok']);
    $p->exec('UPDATE project_commission_snapshots SET commission_amount=8 WHERE order_id='.$orders[1]);
    pay_speed_check('changed amount blocks payment confirmation',!psr_check($get($requests[1]))['ok'] && $get($requests[1])['status']==='pending');
    $export=psr_export_data($requests);
    pay_speed_check('export excludes disputed amounts and totals by person',count($export['people'])===1 && (float)$export['total']===13.0 && count($export['held'])===1);
    $batch=psr_settle_many([$requests[0],$requests[0],$requests[1]],$actor,'test confirmation');
    pay_speed_check('batch confirms each valid request once, disputed request stays pending',$batch['ok']===1 && count($batch['fail'])===1 && $get($requests[0])['status']==='settled' && $get($requests[1])['status']==='pending');
    $blocked=false;try{psr_settle($requests[0],$actor);}catch(RuntimeException $e){$blocked=true;}
    $audits=(int)$p->query("SELECT COUNT(*) FROM project_audit_logs WHERE entity_type='settlement_request' AND entity_id=".$requests[0]." AND action='settled'")->fetchColumn();
    pay_speed_check('repeated confirmation creates no duplicate settlement',$blocked && $audits===1);
} finally {
    foreach($requests as $rid){$p->exec("DELETE FROM project_audit_logs WHERE entity_type='settlement_request' AND entity_id=".$rid);$p->exec('DELETE FROM project_settlement_request_items WHERE request_id='.$rid);$p->exec('DELETE FROM project_settlement_requests WHERE id='.$rid);}
    foreach($orders as $oid){$p->exec('DELETE FROM project_commission_adjustments WHERE order_id='.$oid);$p->exec('DELETE FROM project_commission_snapshots WHERE order_id='.$oid);$p->exec('DELETE FROM project_orders WHERE id='.$oid);}
    if($employee){$p->exec('DELETE FROM project_messages WHERE employee_id='.$employee);$p->exec('DELETE FROM employees WHERE id='.$employee);}
}
