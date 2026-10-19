<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/ProjectSettlementRequest.php';
require_once dirname(__DIR__) . '/includes/ProjectSystem.php';
require_once dirname(__DIR__) . '/includes/commission_explain.php';
function net_check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function net_rejected($fn, $text) {
    try { $fn(); } catch (RuntimeException $e) { net_check(strpos($e->getMessage(), $text) !== false, $e->getMessage()); return; }
    throw new RuntimeException('Expected rejection: ' . $text);
}
psr_ensure(); ppf_ensure(); ppp_ensure(); ps_corr_ensure();
// 初始化全部依赖表后才启动事务；所有真实员工申请、通知和测试扣款均回滚。
foreach ([41,55,81] as $eid) { pspr_month($eid, '2026-09'); psr_orders($eid, '2026-09'); }
$pdo = db(); $month = '2026-09';
$finance = ['type'=>'admin','id'=>(int)$pdo->query('SELECT MIN(id) FROM admins')->fetchColumn(),'role'=>'finance'];
$pdo->beginTransaction();
try {
    foreach ([41,55,81] as $eid) {
        $pdo->exec('SAVEPOINT payroll_case');
        $u=$pdo->query('SELECT id FROM project_users WHERE is_active=1 AND employee_id=' . $eid)->fetchColumn();
        net_check($u && ppf_is_green($eid,$month), 'Expected a reviewed active account');
        $actor=['type'=>'employee','id'=>(int)$u,'employee_id'=>$eid,'role'=>$eid===55?'governance':'technical'];
        $before=pspr_month($eid,$month);
        net_check(!$before['request'] && $before['amount']>0, 'Monthly request already exists; do not alter live requests');
        $canonical=pps_sheet($month,$eid)['rows'][0]['net'];
        net_check(abs($before['amount']+$before['claimed']-$canonical)<0.005,'Existing claims were not deducted');
        $result=pspr_submit($actor,$month);
        $q=$pdo->prepare('SELECT * FROM project_settlement_requests WHERE id=?');$q->execute([$result['id']]);$req=$q->fetch();
        net_check(abs((float)$req['amount']-$before['amount'])<0.005 && $req['status']==='pending','Wrong request amount/status');
        net_check(psr_check($req)['ok'],'Finance review rejected unchanged salary');
        $export=psr_export_data([$req['id']]);
        net_check($export['total']===$before['amount'] && count($export['orders'])===1 && strpos($export['orders'][0]['order_no'],'整月实结算')!==false,'Finance export amount or description differs');
        net_rejected(function()use($actor,$month){pspr_submit($actor,$month);},'请勿重复');
        net_rejected(function()use($actor,$month){psr_submit($actor,$month,[1]);},'不能再单独申请提成');
        net_check(!psr_submit_all($actor)['done'],'One-click duplicated an existing monthly request');
        $pdo->prepare("INSERT INTO project_payroll_manual_items (employee_id,payroll_month,kind,label,amount,note) VALUES (?,?,'other','测试扣款',-100,'net request rollback test')")->execute([$eid,$month]);
        $deduct=(int)$pdo->lastInsertId();
        net_check(!psr_check($req)['ok'] && !psr_export_data([$req['id']])['people'],'Changed net salary was exported');
        net_rejected(function()use($req,$finance){psr_settle($req['id'],$finance);},'有异议');
        $pdo->prepare('DELETE FROM project_payroll_manual_items WHERE id=?')->execute([$deduct]);
        psr_reject($req['id'],$finance,'回滚测试退回');
        $again=pspr_submit($actor,$month);
        net_check($again['amount']===$before['amount'],'Rejected application did not release monthly balance');
        psr_settle($again['id'],$finance,'回滚测试结算');
        net_rejected(function()use($actor,$month){pspr_submit($actor,$month);},'请勿重复');
        net_check($pdo->inTransaction(),'Fixture transaction was implicitly committed');
        $pdo->exec('ROLLBACK TO SAVEPOINT payroll_case');
    }
    $actor=['type'=>'employee','id'=>26,'employee_id'=>41,'role'=>'technical'];
    net_rejected(function()use($finance,$month){pspr_submit($finance,$month);},'本人');
    net_rejected(function()use($actor){pspr_submit($actor,date('Y-m'));},'往月');
    $pdo->exec('SAVEPOINT zero_balance');
    $pdo->prepare("INSERT INTO project_payroll_manual_items (employee_id,payroll_month,kind,label,amount) VALUES (41,?,'other','测试扣款',-100000)")->execute([$month]);
    net_rejected(function()use($actor,$month){pspr_submit($actor,$month);},'没有剩余');
    $pdo->exec('ROLLBACK TO SAVEPOINT zero_balance');
    $pdo->prepare("UPDATE project_payroll_flags SET color='red',reason='待核对' WHERE employee_id=41 AND payroll_month=?")->execute([$month]);
    net_check(isset(pspr_past(41)['months'][$month]),'Unreviewed month cannot be submitted for review');
    $r=pspr_submit($actor,$month);$q=$pdo->prepare('SELECT * FROM project_settlement_requests WHERE id=?');$q->execute([$r['id']]);
    net_check(!psr_check($q->fetch())['ok']&&!psr_export_data([$r['id']])['people'],'Unreviewed request entered payment export');
    net_check($pdo->inTransaction(),'Fixture transaction was lost');
    $pdo->rollBack();
    echo "PASS: full net (fixed-only/offset commissions/mixed), prior claim balance, duplicates, finance export/change blocking, reject/reapply/settle, ownership and review gates; all fixtures rolled back\n";
} catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
