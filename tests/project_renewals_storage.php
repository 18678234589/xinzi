<?php
/** Isolated connection-local MySQL tables; no real orders/config/messages ever written. */
if (PHP_SAPI!=='cli' || !in_array('--isolated-temporary-tables',$argv,true)) exit('Use --isolated-temporary-tables');
require_once __DIR__.'/../includes/ProjectRenewalSms.php';
require_once __DIR__.'/../includes/ProjectRenewalImport.php';
$pdo=db();
foreach(['project_orders','project_order_resources','project_costs','project_audit_logs','project_participants','project_department_uploaders','employees','project_users','project_user_businesses','project_kb_super_admins'] as $table) {
    $ddl=$pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1];
    $ddl=preg_replace('/^\s*CONSTRAINT .*FOREIGN KEY.*\n?/m','',$ddl);
    $ddl=preg_replace('/,\n\)/',"\n)",$ddl);
    $pdo->exec(preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl,1));
}
$ddl=file_get_contents(__DIR__.'/../migrations/20261002_project_renewals.sql');
$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',$ddl));
$n=0;
function check($name,$actual,$expected) { global $n; $n++; if ($actual!==$expected) throw new RuntimeException($name.': '.json_encode($actual)); }
function reject($name,$fn) { global $n; $n++; try { $fn(); } catch (RuntimeException $e) { return; } throw new RuntimeException($name.' did not reject'); }
$finance=['type'=>'admin','id'=>1,'role'=>'finance'];
$pdo->exec("INSERT INTO project_orders (id,order_no,project_type,order_date) VALUES (910001,'__renewal_fixture_web','AI网站定制','2026-10-02'),(910002,'__renewal_fixture_mini','小程序开发','2026-10-02')");
pr_seed(); check('two seed resources',(int)$pdo->query('SELECT COUNT(*) FROM project_renewal_items')->fetchColumn(),2);
pr_seed(); check('seed dedupe',(int)$pdo->query('SELECT COUNT(*) FROM project_renewal_items')->fetchColumn(),2);
$row=$pdo->query('SELECT * FROM project_renewal_items WHERE order_id=910001')->fetch();
check('year default',$row['expires_on'],'2027-10-02');check('estimate marked',$row['expiry_source'],'estimated');
$input=['id'=>$row['id'],'order_id'=>910001,'resource_type'=>'domain','resource_name'=>'example.com','expires_on'=>'2026-10-12','expiry_confirmed'=>1,'phone'=>'13800000000','sms_enabled'=>1,'status'=>'active','revision'=>$row['revision']];
pr_save($input,$finance); $item=pr_item($row['id'],$finance);
check('real expiry',$item['expires_on'],'2026-10-12');check('phone encrypted',strpos($item['phone_cipher'],'13800000000')===false,true);
reject('stale edit',function()use($input,$finance){pr_save($input,$finance);});
reject('duplicate resource',function()use($input,$finance){$x=$input;$x['id']=0;pr_save($x,$finance);});
pr_seed();check('verified expiry survives',pr_item($row['id'],$finance)['expires_on'],'2026-10-12');
$pdo->exec("UPDATE project_orders SET order_date='2026-09-01' WHERE id=910002");pr_seed();check('estimates follow synced order date',$pdo->query('SELECT expires_on FROM project_renewal_items WHERE order_id=910002')->fetchColumn(),'2027-09-01');
pr_import_apply(910002,['phone'=>'13900000000','resources'=>['miniapp_certification'=>['resource_name'=>'示例小程序','expires_on'=>'2026-10-05']]],$finance);
check('import actual date',$pdo->query('SELECT expiry_source FROM project_renewal_items WHERE order_id=910002')->fetchColumn(),'imported');
pr_import_apply(910002,['resources'=>['miniapp_certification'=>['expires_on'=>'2026-11-05']]],$finance);
check('conflict kept real date',$pdo->query('SELECT expires_on FROM project_renewal_items WHERE order_id=910002')->fetchColumn(),'2026-10-05');
check('conflict audited',(int)$pdo->query("SELECT COUNT(*) FROM project_renewal_history WHERE action='import_conflict'")->fetchColumn(),1);
check('disabled never sends',pr_sms_run(true,function(){throw new RuntimeException('must not call');},new DateTimeImmutable('2026-10-02T10:00:00+08:00'))['reason'],'disabled');
$pdo->exec("UPDATE project_renewal_sms_config SET enabled=1,access_key_id='fixture',secret_cipher='',sign_name='fixture',template_code='SMS_fixture'");
$calls=0;$ok=function()use(&$calls){$calls++;return ['state'=>'sent','code'=>'OK','request_id'=>'fixture-request','biz_id'=>'fixture-biz'];};
$time=new DateTimeImmutable('2026-10-02T10:00:00+08:00');
check('dry run no mutation',pr_sms_run(false,$ok,$time)['eligible_resources'],1);check('dry run no call',$calls,0);
check('night never calls',pr_sms_run(true,$ok,new DateTimeImmutable('2026-10-02T20:00:00+08:00'))['reason'],'outside_daytime_window');
check('send accepted',pr_sms_run(true,$ok,$time)['sent'],1);check('one call',$calls,1);
pr_sms_run(true,$ok,$time);check('repeat job never resends',$calls,1);
check('audit not phone',(int)$pdo->query("SELECT COUNT(*) FROM project_audit_logs WHERE details_json LIKE '%13800000000%'")->fetchColumn(),0);
// Separate node, timeout is uncertain and must not auto retry.
$next=new DateTimeImmutable('2026-10-09T10:00:00+08:00');
$uncertain=function()use(&$calls){$calls++;return ['state'=>'unknown','code'=>'TRANSPORT_RESULT_UNKNOWN','request_id'=>'','biz_id'=>''];};
check('unknown recorded',pr_sms_run(true,$uncertain,$next)['unknown'],1);pr_sms_run(true,$ok,$next);check('unknown not retried',$calls,2);
$item=pr_item($row['id'],$finance);$input['revision']=$item['revision'];$input['expires_on']='2027-10-12';$input['action']='renew';pr_save($input,$finance);
check('new cycle expiry',pr_item($row['id'],$finance)['expires_on'],'2027-10-12');
check('old sent evidence retained',(int)$pdo->query("SELECT COUNT(*) FROM project_renewal_sms WHERE state='sent'")->fetchColumn(),1);
check('renew history',(int)$pdo->query("SELECT COUNT(*) FROM project_renewal_history WHERE action='renew'")->fetchColumn(),1);
pr_sms_run(true,$ok,new DateTimeImmutable('2026-10-11T10:00:00+08:00'));check('renewed not reminded old cycle',$calls,2);
$pdo->exec("INSERT INTO project_orders (id,order_no,project_type,order_date) VALUES (910004,'__renewal_fixture_repeat','AI网站定制','2026-10-02')");
$third=$input;$third['id']=0;$third['order_id']=910004;$third['expires_on']='2026-10-12';$third['action']='save';$thirdId=pr_save($third,$finance);
check('same customer daily cap',pr_sms_run(true,$ok,$time)['deferred'],1);check('daily cap no call',$calls,2);
$failureCalls=0;$failed=function()use(&$failureCalls){$failureCalls++;return ['state'=>'failed','code'=>'isv.INVALID_PARAMETERS','request_id'=>'fixture','biz_id'=>''];};
$failTime=new DateTimeImmutable('2026-10-11T10:00:00+08:00');
check('failed recorded',pr_sms_run(true,$failed,$failTime)['failed'],1);
for($i=0;$i<3;$i++) { $pdo->exec("UPDATE project_renewal_sms SET next_attempt_at=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE state='failed'");pr_sms_run(true,$failed,$failTime); }
check('bounded explicit failure retries',$failureCalls,3);
check('no customer phone in SMS rows',strpos(json_encode($pdo->query('SELECT * FROM project_renewal_sms')->fetchAll()),'13800000000')===false,true);
// Staff scope uses participants, never someone else's order.
$pdo->exec("INSERT INTO employees (id,name,department,password) VALUES (910001,'测试客服','网站客服','not-a-login-hash'),(910002,'测试售后','网站售后部','not-a-login-hash'),(910003,'王亚','标书小程序','not-a-login-hash')");
$pdo->exec("INSERT INTO project_users (id,employee_id,username,password_hash,role) VALUES (910001,910001,'__renewal_cs','not-a-login-hash','customer_service'),(910002,910002,'__renewal_after','not-a-login-hash','customer_service'),(910003,910003,'__renewal_wang','not-a-login-hash','customer_service')");
$pdo->exec("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (910001,910001,'customer_service','客服',1),(910002,910003,'customer_service','客服',1)");
$staff=['type'=>'employee','id'=>910001,'employee_id'=>910001,'role'=>'customer_service'];check('own visible',pr_order(910001,$staff)['order_no'],'__renewal_fixture_web');
reject('others denied',function()use($staff){pr_order(910002,$staff);});
$after=['type'=>'employee','id'=>910002,'employee_id'=>910002,'role'=>'customer_service'];check('aftersales shared web',pr_order(910001,$after)['order_no'],'__renewal_fixture_web');
reject('aftersales unrelated mini excluded',function()use($after){pr_order(910002,$after);});
$wang=['type'=>'employee','id'=>910003,'employee_id'=>910003,'role'=>'customer_service'];check('wangya own mini',pr_order(910002,$wang)['order_no'],'__renewal_fixture_mini');
echo "PASS {$n} isolated renewal storage checks; mock SMS only\n";
