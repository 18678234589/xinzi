<?php
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__).'/includes/ProjectPayrollSheet.php';
function director_check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function director_rejected($fn){try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException('Expected rejection');}
ps_payroll_extras_ensure(); ppf_ensure(); ppp_ensure(); pps_sheet('2096-06',19,true);
$pdo=db();$m='2096-07';$n='2096-08';$eid=19;
$finance=['type'=>'admin','id'=>(int)$pdo->query('SELECT MIN(id) FROM admins')->fetchColumn(),'role'=>'finance'];
$pdo->beginTransaction();
try{
    $q=$pdo->query("SELECT COUNT(*) FROM project_payroll_manual_items WHERE employee_id=19 AND payroll_month IN ('2096-07','2096-08')");
    director_check(!$q->fetchColumn(),'Reserved test data exists');
    $q=$pdo->query("SELECT COUNT(*) FROM project_payroll_periods WHERE period IN ('2096-07','2096-08')");
    director_check(!$q->fetchColumn(),'Reserved test periods exist');
    foreach([$m,$n] as $month)$pdo->prepare('INSERT INTO project_payroll_periods (period) VALUES (?)')->execute([$month]);
    $base=pps_sheet($m,$eid)['rows'][0];
    ps_director_save($eid,$m,'1234.56','本月董事会核定',$finance);
    ps_director_save($eid,$m,'1234.56','重复保存',$finance);
    $q=$pdo->prepare("SELECT COUNT(*) FROM project_payroll_manual_items WHERE employee_id=? AND payroll_month=? AND label=?");$q->execute([$eid,$m,PS_DIRECTOR_LABEL]);
    director_check((int)$q->fetchColumn()===1,'Save duplicated allowance');
    director_rejected(function()use($m,$eid,$finance){ps_payroll_extras_action('add_manual_item',$m,$eid,['label'=>PS_DIRECTOR_LABEL,'amount'=>'100'],$finance);});
    $q=$pdo->prepare('SELECT id FROM project_payroll_manual_items WHERE employee_id=? AND payroll_month=? AND label=?');$q->execute([$eid,$m,PS_DIRECTOR_LABEL]);$itemId=(int)$q->fetchColumn();
    director_rejected(function()use($m,$eid,$itemId,$finance){ps_payroll_extras_action('delete_manual_item',$m,$eid,['item_id'=>$itemId],$finance);});
    $row=pps_sheet($m,$eid)['rows'][0];
    director_check(abs($row['other']-$base['other']-1234.56)<0.005 && abs($row['net']-$base['net']-1234.56)<0.005,'Canonical salary omitted or duplicated monthly allowance');
    director_check(ps_director_settings($m)[$eid]['confirmed'] && ps_director_settings($n)[$eid]['amount']===null,'Amount carried into next month');
    ps_director_save($eid,$m,'800','调整',$finance);
    director_check(ps_director_settings($m)[$eid]['amount']===800.0,'Amount edit failed');
    ps_director_save($eid,$m,'0','本月不发',$finance);
    director_check(ps_director_settings($m)[$eid]['amount']===0.0 && ps_director_settings($m)[$eid]['confirmed'],'Explicit zero not retained');
    foreach(['','-1','abc','1.001','10000000'] as $bad)director_rejected(function()use($eid,$m,$bad,$finance){ps_director_save($eid,$m,$bad,'',$finance);});
    director_rejected(function()use($eid,$m){ps_director_save($eid,$m,'1','',['type'=>'employee','role'=>'management','id'=>80,'employee_id'=>55]);});
    director_rejected(function()use($m,$finance){ps_director_save(41,$m,'1','',$finance);});
    $pdo->prepare("INSERT INTO project_payroll_flags (employee_id,payroll_month,color) VALUES (?,?,'green')")->execute([$eid,$m]);
    ps_director_save($eid,$m,'100','变动需重新核对',$finance);
    $q=$pdo->prepare('SELECT color FROM project_payroll_flags WHERE employee_id=? AND payroll_month=?');$q->execute([$eid,$m]);
    director_check($q->fetchColumn()==='yellow','Stale approval survived changed amount');
    $pdo->prepare("UPDATE project_payroll_flags SET color='yellow',reason='核对一致' WHERE employee_id=? AND payroll_month=?")->execute([$eid,$m]);
    ps_director_save($eid,$m,'101','变动清除旧核对说明',$finance);
    director_check(!ppf_is_green($eid,$m),'Legacy approval text survived changed amount');
    $pdo->prepare("UPDATE project_payroll_periods SET status='locked' WHERE period=?")->execute([$m]);
    director_rejected(function()use($eid,$m,$finance){ps_director_save($eid,$m,'200','',$finance);});
    director_check($pdo->inTransaction(),'Fixture transaction was committed');$pdo->rollBack();
    echo "PASS: monthly amount/zero/edit, no carryover/duplicates, canonical net, finance-only auth, validation, review reset and locked history; all fixtures rolled back\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
