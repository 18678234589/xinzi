<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('HEZUOSHANG_APP') ?: dirname(__DIR__);
require_once $root.'/includes/ProjectSettlementRequest.php';
require_once $root.'/includes/commission_explain.php';
function fnr_check($name,$ok) { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
function fnr_denied($fn) { try { $fn(); } catch (RuntimeException $e) { return true; } return false; }
$p=db(); $month='2000-01'; $eid=0;
psr_ensure(); ppf_ensure(); ps_corr_ensure(); ps_payroll_extras_ensure();
try {
    $p->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute(['FIN-NET-'.bin2hex(random_bytes(6)),'测试','x']); $eid=(int)$p->lastInsertId();
    pspr_month($eid,$month);
    $finance=['type'=>'admin','id'=>5,'role'=>'finance'];
    $person=['type'=>'employee','id'=>0,'employee_id'=>$eid,'role'=>'technical'];
    $mapping=[]; $finances=[];
    foreach (ps_admin_reviewers() as $a) if (!empty($a['employee_id'])) {
        $mapping[$a['username']]=(int)$a['employee_id']; $finances[]=['type'=>'admin','id'=>(int)$a['id'],'role'=>'finance'];
        pspr_month((int)$a['employee_id'],'2026-09');
    }
    fnr_check('finance self identity uses existing real-name mapping',($mapping['sunman']??0)===58 && ($mapping['songwenna']??0)===61 && pspr_self_employee($finance)===58 && pspr_self_employee(['type'=>'admin','id'=>1])===0);
    fnr_check('self application cannot target another employee',fnr_denied(function()use($finance,$eid){pspr_submit($finance,'2026-09',1,$eid);}) && fnr_denied(function()use($person){pspr_submit($person,'2026-09',1,58);}));
    $p->beginTransaction();
    foreach ([['测试补助',6000],['测试扣款',-500]] as $v) $p->prepare("INSERT INTO project_payroll_manual_items (employee_id,payroll_month,kind,label,amount) VALUES (?,?,'other',?,?)")->execute([$eid,$month,$v[0],$v[1]]);
    $p->prepare("INSERT INTO project_payroll_flags (employee_id,payroll_month,color,reason) VALUES (?,?,'green','核对无异议')")->execute([$eid,$month]);
    $row=pps_sheet($month,$eid)['rows'][0];
    $p->exec('SAVEPOINT self_submit'); $ownFinance=$person; $ownFinance['role']='finance';
    $self=pspr_submit($ownFinance,$month,'5500',$eid);
    $p->exec('ROLLBACK TO SAVEPOINT self_submit');
    foreach ($finances as $a) {
        $p->exec('SAVEPOINT admin_self_submit'); $selfId=pspr_self_employee($a); $s=pspr_status($selfId,'2026-09');
        if ($s['can_apply']) { $applied=pspr_submit($a,'2026-09',number_format($s['amount'],2,'.',''),$selfId); if ($applied['amount']!==$s['amount']) throw new RuntimeException('Finance own amount mismatch'); }
        elseif (!$s['request']) throw new RuntimeException('Finance has no own application path');
        $p->exec('ROLLBACK TO SAVEPOINT admin_self_submit');
    }
    fnr_check('finance self submission uses net after deductions',(float)$row['payable']===6000.0 && (float)$row['net']===5500.0 && $self['amount']===5500.0);
    $make=function($status,$amount,$order)use($p,$eid,$month){
        $p->prepare('INSERT INTO project_settlement_requests (employee_id,payroll_month,status,amount,order_count,settled_at) VALUES (?,?,?,?,1,?)')->execute([$eid,$month,$status,$amount,$status==='settled'?'2000-02-01 00:00:00':null]);$rid=(int)$p->lastInsertId();
        $p->prepare('INSERT INTO project_settlement_request_items (request_id,employee_id,order_id,payroll_month,amount,settled_at) VALUES (?,?,?,?,?,?)')->execute([$rid,$eid,$order,$month,$amount,$status==='settled'?'2000-02-01 00:00:00':null]);return $rid;
    };
    $paid=$make('settled',1000,PSPR_NET_ITEM);$a=$make('pending',1200,800000002);$b=$make('pending',300,800000003);
    $paidBefore=$p->query('SELECT * FROM project_settlement_requests WHERE id='.$paid)->fetch();$paidItems=$p->query('SELECT * FROM project_settlement_request_items WHERE request_id='.$paid)->fetchAll();
    fnr_check('refresh rejects non-finance and stale expected amount',fnr_denied(function()use($person,$eid,$month){pspr_sync_pending($person,$eid,$month,'4500');}) && fnr_denied(function()use($finance,$eid,$month){pspr_sync_pending($finance,$eid,$month,'5500');}));
    $r=pspr_sync_pending($finance,$eid,$month,'4500');$m=pspr_month($eid,$month);
    $pending=$p->query("SELECT id,amount FROM project_settlement_requests WHERE employee_id=$eid AND status='pending'")->fetchAll();
    fnr_check('legacy claims merge to net minus paid without changing paid history',count($pending)===1 && $r['amount']===4500.0 && $m['paid']===1000.0 && (int)$m['request']['id']===$a && $paidBefore===$p->query('SELECT * FROM project_settlement_requests WHERE id='.$paid)->fetch() && $paidItems===$p->query('SELECT * FROM project_settlement_request_items WHERE request_id='.$paid)->fetchAll() && $p->query('SELECT status FROM project_settlement_requests WHERE id='.$b)->fetchColumn()==='rejected');
    $req=$p->query('SELECT * FROM project_settlement_requests WHERE id='.$a)->fetch();$check=psr_check($req);
    fnr_check('amount change resets approval and payment remains held',!ppf_is_green($eid,$month) && !$check['ok'] && !$check['sync_needed'] && $req['status']==='pending' && $req['settled_at']===null);
    $again=pspr_sync_pending($finance,$eid,$month,'4500');
    fnr_check('repeated refresh cannot duplicate or bypass review',$again['id']===$r['id'] && (int)$p->query("SELECT COUNT(*) FROM project_settlement_request_items WHERE employee_id=$eid AND order_id BETWEEN 4000000000 AND 4294967295")->fetchColumn()===2 && !ppf_is_green($eid,$month) && fnr_denied(function()use($person,$month){pspr_submit($person,$month,4500);}));
    fnr_check('fixture transaction stayed intact',$p->inTransaction());
    $p->rollBack();
} finally {
    if ($p->inTransaction()) $p->rollBack();
    if ($eid) $p->prepare('DELETE FROM employees WHERE id=? AND name LIKE ?')->execute([$eid,'FIN-NET-%']);
}
