<?php
if(PHP_SAPI!=='cli')exit;
require_once dirname(__DIR__).'/includes/ProjectSettlementRequest.php';
require_once dirname(__DIR__).'/includes/commission_explain.php';
require_once dirname(__DIR__).'/includes/ProjectSystem.php';
function entry_check($ok,$why){if(!$ok)throw new RuntimeException($why);}
psr_ensure();ppf_ensure();ppp_ensure();ps_corr_ensure();
foreach([9,10,11,12,13,41,81] as $eid){pspr_month($eid,'2026-09');psr_orders($eid,'2026-09');}
$pdo=db();$pdo->beginTransaction();
try{
    // 可先申请；审核未完成时，财务导出及确认结算仍必须拦截。
    unset($_SESSION['admin_id']);$payrollRequestMonth='2026-09';
    $finance=['type'=>'admin','id'=>1,'role'=>'finance'];
    foreach([9=>68,10=>69,11=>70,12=>71,13=>72] as $eid=>$uid){
        $pdo->exec('SAVEPOINT trade_case');
        $pdo->prepare("UPDATE project_payroll_flags SET color='yellow',reason='待财务核对' WHERE employee_id=? AND payroll_month='2026-09'")->execute([$eid]);
        $_SESSION['project_user_id']=$uid;$payrollRequestEmployee=$eid;
        $s=pspr_status($eid,'2026-09');entry_check($s['can_apply'],'Unreviewed trademark applicant disabled');
        ob_start();include dirname(__DIR__).'/includes/payroll_request_button.php';$html=ob_get_clean();
        entry_check(strpos($html,'<button type="submit">')!==false&&strpos($html,' disabled')===false&&strpos($html,'固定服务费')!==false,'Trademark full-salary button disabled');
        $r=pspr_submit(['type'=>'employee','id'=>$uid,'employee_id'=>$eid,'role'=>'customer_service'],'2026-09',$s['amount']);
        entry_check($r['amount']===$s['amount'],'Trademark request omitted fixed pay or prior claim deduction');
        $q=$pdo->prepare('SELECT * FROM project_settlement_requests WHERE id=?');$q->execute([$r['id']]);$req=$q->fetch();
        entry_check(!psr_check($req)['ok']&&!psr_export_data([$r['id']])['people'],'Unreviewed request entered payment export');
        $blocked=false;try{psr_settle($r['id'],$finance);}catch(RuntimeException $e){$blocked=true;}entry_check($blocked,'Unreviewed request marked paid');
        if($s['pending_n']>0){$pdo->prepare("UPDATE project_payroll_flags SET color='green',reason='测试核对' WHERE employee_id=? AND payroll_month='2026-09'")->execute([$eid]);/* 现行规则（待审核分成放行规则，19d6d48）：财务标绿并确认后，待审核的分成估算不再拦截支付；原“Pending estimates allowed payment”断言已删除 */}
        entry_check(!pspr_status($eid,'2026-09')['can_apply'],'Duplicate application enabled');
        $pdo->exec('ROLLBACK TO SAVEPOINT trade_case');
    }
    foreach([41=>26,81=>43] as $eid=>$uid){
        $pdo->exec('SAVEPOINT entry_case');
        $_SESSION['project_user_id']=$uid;$payrollRequestEmployee=$eid;
        $state=pspr_status($eid,'2026-09');entry_check($state['can_apply'],'Reviewed salary-only/offset employee cannot apply');
        ob_start();include dirname(__DIR__).'/includes/payroll_request_button.php';$html=ob_get_clean();
        entry_check(strpos($html,' disabled')===false&&strpos($html,'request_payroll')!==false,'Ready monthly button disabled');
        $actor=['type'=>'employee','id'=>$uid,'employee_id'=>$eid,'role'=>'technical'];
        $changed=false;try{pspr_submit($actor,'2026-09','0.01');}catch(RuntimeException $e){$changed=strpos($e->getMessage(),'金额已变化')!==false;}
        entry_check($changed,'Stale displayed amount accepted');
        $r=pspr_submit($actor,'2026-09');entry_check($r['amount']===$state['amount'],'Request excludes fixed salary/deductions');
        entry_check(!pspr_status($eid,'2026-09')['can_apply']&&!isset(pspr_past($eid)['months']['2026-09']),'Other entry can duplicate existing request');
        $q=$pdo->prepare('SELECT * FROM project_settlement_requests WHERE id=?');$q->execute([$r['id']]);
        entry_check(psr_check($q->fetch())['ok']&&psr_export_data([$r['id']])['total']===$r['amount'],'Finance amount differs');
        $pdo->exec('ROLLBACK TO SAVEPOINT entry_case');
    }
    entry_check(!pspr_status(41,date('Y-m'))['can_apply'],'Current month accepted');
    $_SESSION['project_user_id']=26;$payrollRequestEmployee=81;
    ob_start();include dirname(__DIR__).'/includes/payroll_request_button.php';$html=ob_get_clean();
    entry_check(strpos($html,'仅本人或财务可提交结算申请')!==false&&strpos($html,'<button')===false,'Other employee action enabled');
    entry_check($pdo->inTransaction(),'Fixture transaction lost');$pdo->rollBack();
    echo "PASS: 5 trademark users can submit full salary before review; payment export/settlement blocked until reviewed, prior claims/duplicates, fixed salary, ownership and current month; all fixtures rolled back\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
