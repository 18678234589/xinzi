<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=getenv('HEZUOSHANG_APP') ?: dirname(__DIR__);
require_once $root.'/includes/ProjectSettlementRequest.php';
require_once $root.'/includes/commission_explain.php';
function fpx_check($name,$ok) { if(!$ok)throw new RuntimeException($name);echo "PASS $name\n"; }
function fpx_denied($fn) { try{$fn();}catch(RuntimeException $e){return true;}return false; }
$p=db();$month='2000-01';$employees=[];
psr_ensure();ppf_ensure();ps_corr_ensure();ps_payroll_extras_ensure();
try {
    for($i=0;$i<3;$i++){$p->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute(['FIXED-PROXY-'.bin2hex(random_bytes(6)),'测试','x']);$employees[]=(int)$p->lastInsertId();}
    [$a,$b,$c]=$employees;foreach($employees as $eid)pspr_month($eid,$month);
    $admin=['type'=>'admin','id'=>1,'role'=>'finance'];$finance=['type'=>'admin','id'=>5,'role'=>'finance'];$staff=['type'=>'employee','id'=>0,'employee_id'=>$a,'role'=>'technical'];
    fpx_check('non-finance cannot submit another person or a proxy batch',fpx_denied(function()use($staff,$month,$b){pspr_submit($staff,$month,'1',$b);}) && fpx_denied(function()use($staff,$month,$b){pspr_submit_fixed($staff,$month,[$b=>'1']);}));
    $p->beginTransaction();
    foreach([$a=>2000,$b=>1200,$c=>1800] as $eid=>$base){
        $p->prepare("INSERT INTO project_payroll_manual_items (employee_id,payroll_month,kind,label,amount) VALUES (?,?,'other','测试固定服务费补单',?)")->execute([$eid,$month,$base]);
        $p->prepare("INSERT INTO project_payroll_manual_items (employee_id,payroll_month,kind,label,amount) VALUES (?,?,'other','测试扣款',-200)")->execute([$eid,$month]);
    }
    $p->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,order_date,delivery_status,settlement_status) VALUES (?,'proxy test','网站模板','proxy test',100,'2000-01-02','unfinished','draft')")->execute(['FIXED-PROXY-'.$c]);$oid=(int)$p->lastInsertId();
    $p->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','前端',1)")->execute([$oid,$c]);
    $eligible=pspr_fixed_people($month);
    fpx_check('fixed salary without orders is eligible; order participants are excluded',isset($eligible[$a],$eligible[$b]) && !isset($eligible[$c]) && fpx_denied(function()use($admin,$month,$c){pspr_submit($admin,$month,'1',$c);}));
    $netA=pspr_status($a,$month)['amount'];$netB=pspr_status($b,$month)['amount'];
    fpx_check('stale amount and invalid batch payload are rejected',fpx_denied(function()use($admin,$month,$a,$netA){pspr_submit($admin,$month,number_format($netA+1,2,'.',''),$a);}) && fpx_denied(function()use($admin,$month,$a){pspr_submit_fixed($admin,$month,[$a=>['invalid']]);}));
    $r=pspr_submit($admin,$month,number_format($netA,2,'.',''),$a);
    $q=$p->prepare("SELECT details_json FROM project_audit_logs WHERE entity_type='settlement_request' AND entity_id=? AND action='submit_payroll_net' ORDER BY id DESC LIMIT 1");$q->execute([$r['id']]);$audit=json_decode($q->fetchColumn(),true);
    fpx_check('administrator without employee mapping can proxy net salary with audit',$r['amount']===$netA && $netA<2000 && $audit['on_behalf'] && (int)$audit['employee_id']===$a);
    $p->prepare("INSERT INTO project_settlement_requests (employee_id,payroll_month,status,amount,order_count,settled_at) VALUES (?,?,'settled',100,1,'2000-02-01 00:00:00')")->execute([$b,$month]);$paid=(int)$p->lastInsertId();$paidBefore=$p->query('SELECT * FROM project_settlement_requests WHERE id='.$paid)->fetch();
    $expected=[$a=>number_format($netA,2,'.',''),$b=>number_format($netB-100,2,'.',''),$c=>'1'];$batch=pspr_submit_fixed($finance,$month,$expected);
    fpx_check('finance batch skips duplicates/ineligible staff and deducts prior payment',count($batch['done'])===1 && count($batch['skipped'])===2 && $batch['total']===round($netB-100,2) && $p->query('SELECT * FROM project_settlement_requests WHERE id='.$paid)->fetch()===$paidBefore);
    $q=$p->prepare('SELECT * FROM project_settlement_requests WHERE id=?');$q->execute([$r['id']]);$req=$q->fetch();
    fpx_check('proxy application remains pending until financial review',$req['status']==='pending' && $req['settled_at']===null && !psr_check($req)['ok'] && !psr_export_data([$r['id']])['people']);
    $again=pspr_submit_fixed($finance,$month,[$a=>$expected[$a],$b=>$expected[$b]]);
    fpx_check('repeating a batch cannot create duplicate requests',!$again['done'] && count($again['skipped'])===2 && (int)$p->query("SELECT COUNT(*) FROM project_settlement_requests WHERE employee_id IN ($a,$b) AND status='pending'")->fetchColumn()===2);
    fpx_check('all fixture changes and notifications remain in rollback transaction',$p->inTransaction());
    $p->rollBack();
}finally{
    if($p->inTransaction())$p->rollBack();
    foreach($employees as $eid)$p->prepare('DELETE FROM employees WHERE id=? AND name LIKE ?')->execute([$eid,'FIXED-PROXY-%']);
}
