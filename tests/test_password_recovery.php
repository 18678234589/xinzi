<?php
/** Offline integration tests: SQLite database and an injected SMS transport. No real SMS is sent. */
require_once __DIR__.'/../includes/PasswordRecovery.php';
session_start();
function db() { return $GLOBALS['test_db']; }
function pv_encrypt($plain) {
    $iv=random_bytes(12); $tag='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',str_repeat('K',32),OPENSSL_RAW_DATA,$iv,$tag);
    return 'v1:'.base64_encode($iv.$tag.$cipher);
}
function pv_decrypt($stored) {
    $raw=base64_decode(substr($stored,3),true);
    return openssl_decrypt(substr($raw,28),'aes-256-gcm',str_repeat('K',32),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
}
$checks=0;
function check($ok,$message) { $GLOBALS['checks']++; if (!$ok) throw new RuntimeException('FAIL: '.$message); }
function rejects(callable $fn,$message) {
    try { $fn(); } catch (RuntimeException $e) { check(true,$message); return; }
    check(false,$message);
}
function fixture(&$now,&$messages,$state='sent') {
    $pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE admins(id INTEGER PRIMARY KEY,username TEXT,password TEXT,auth_version INT DEFAULT 0);
        CREATE TABLE project_users(id INTEGER PRIMARY KEY,username TEXT,phone TEXT,password_hash TEXT,is_active INT,auth_version INT DEFAULT 0,password_changed_at TEXT)");
    $sql=file_get_contents(__DIR__.'/../migrations/20261010_password_recovery.sql');
    $sql=preg_replace('/ ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/','',$sql);
    $sql=str_replace('INSERT IGNORE','INSERT OR IGNORE',$sql);
    $sql=preg_replace('/^\\s*KEY [^\n]+,?\n?/m','',$sql);
    $sql=preg_replace('/UNIQUE KEY uk_recovery_phone\\(phone_hash\\)/','UNIQUE(phone_hash)',$sql);
    $sql=preg_replace('/,\\s*\\)/',')',$sql);
    $pdo->exec($sql);
    $pdo->exec("UPDATE account_recovery_config SET enabled=1");
    $q=$pdo->prepare('INSERT INTO admins(id,username,password) VALUES(1,?,?)');$q->execute(['admin',md5('OriginalPassword!')]);
    $q=$pdo->prepare('INSERT INTO project_users(id,username,phone,password_hash,is_active) VALUES(1,?,?,?,1)');$q->execute(['staff','13900000002',password_hash('StaffPassword!',PASSWORD_DEFAULT)]);
    $GLOBALS['test_db']=$pdo; $now=1700000000; $messages=[];
    $service=new PasswordRecovery($pdo,str_repeat('H',32),function($phone,$code,$id)use(&$messages,$state) {
        $messages[$id]=['phone'=>$phone,'code'=>$code]; return ['state'=>$state,'code'=>$state==='sent'?'OK':'TEST_FAILURE'];
    },function()use(&$now){return $now;});
    return [$pdo,$service];
}
function bindAdmin($service,&$messages) {
    $id=$service->send('bind','','13900000001','s1','192.0.2.1',['type'=>'admin','id'=>1],'OriginalPassword!');
    $version=$service->finish($id,'bind',$messages[$id]['code'],'s1');
    return $version;
}
check(auth_password_verify('OriginalPassword!',md5('OriginalPassword!')),'legacy administrator login');
check(auth_password_verify('ModernPassword!',password_hash('ModernPassword!',PASSWORD_DEFAULT)),'modern administrator login');
check(!auth_password_verify('wrong',md5('OriginalPassword!')),'wrong legacy password rejected');
foreach ([['12345678','12345678','admin'],['administrator','administrator','administrator'],['GoodPassword!','mismatch','admin'],[str_repeat('a',73),str_repeat('a',73),'admin']] as $args) rejects(function()use($args){auth_password_validate(...$args);},'invalid new password');
auth_password_validate(' StrongPassword! ',' StrongPassword! ','admin');

[$pdo,$service]=fixture($now,$messages);
$pdo->exec('UPDATE account_recovery_config SET enabled=0');
rejects(function()use($service){$service->send('reset','admin','13900000001','s1','192.0.2.1');},'disabled SMS rejected');
check((int)$pdo->query('SELECT COUNT(*) FROM account_recovery_challenges')->fetchColumn()===0,'disabled sends reserve nothing');

[$pdo,$service]=fixture($now,$messages);
rejects(function()use($service){$service->send('bind','','13900000001','s1','192.0.2.1',['type'=>'admin','id'=>1],'wrong');},'binding requires current password');
check(count($messages)===0,'no SMS for invalid binding password');
$id=$service->send('bind','','13900000001','s1','192.0.2.1',['type'=>'admin','id'=>1],'OriginalPassword!');
$row=$pdo->query('SELECT * FROM account_recovery_challenges')->fetch(PDO::FETCH_ASSOC);
check(strlen($row['code_hash'])===64 && $row['code_hash']!==$messages[$id]['code'],'OTP stored as keyed hash');
check(strpos($row['phone_cipher'],'13900000001')===false && pv_decrypt($row['phone_cipher'])==='13900000001','phone stored encrypted');
rejects(function()use($service,$id,$messages){$service->finish($id,'bind',$messages[$id]['code'],'different-session');},'cross-session code rejected');
rejects(function()use($service,$id,$messages){$service->finish($id,'reset',$messages[$id]['code'],'s1','StrongPassword!','StrongPassword!');},'cross-purpose code rejected');
check($service->finish($id,'bind',$messages[$id]['code'],'s1')===1,'administrator binding succeeds');
check((int)$pdo->query('SELECT COUNT(*) FROM account_recovery_contacts')->fetchColumn()===1,'verified contact persisted');
rejects(function()use($service,$id,$messages){$service->finish($id,'bind',$messages[$id]['code'],'s1');},'binding code cannot be reused');
$_SESSION=['admin_id'=>1,'admin_username'=>'admin','auth_version'=>0];auth_session_validate();
check(!isset($_SESSION['admin_id']),'binding revokes previous sessions');
$_SESSION=['admin_id'=>1,'admin_username'=>'admin','auth_version'=>1];auth_session_validate();
check(isset($_SESSION['admin_id']),'current-version session remains valid');

[$pdo,$service]=fixture($now,$messages);bindAdmin($service,$messages);$now+=61;
$before=count($messages);$id=$service->send('reset','admin','13900000003','s2','192.0.2.2');
check(count($messages)===$before,'unmatched phone does not send SMS');
check(strlen($id)===64,'unmatched account gets indistinguishable challenge token');
rejects(function()use($service,$id){$service->finish($id,'reset','000000','s2','StrongPassword!','StrongPassword!');},'unmatched account cannot reset');
$now+=61;$id=$service->send('reset','admin','13900000001','s2','192.0.2.2');
rejects(function()use($service){$service->send('reset','admin','13900000001','s3','192.0.2.3');},'phone resend cooldown across sessions');
for ($i=0;$i<5;$i++) rejects(function()use($service,$id){$service->finish($id,'reset','000000','s2','StrongPassword!','StrongPassword!');},'wrong OTP attempt rejected');
rejects(function()use($service,$id,$messages){$service->finish($id,'reset',$messages[$id]['code'],'s2','StrongPassword!','StrongPassword!');},'five wrong attempts lock OTP');
$now+=61;$next=$service->send('reset','admin','13900000001','s2','192.0.2.2');
rejects(function()use($service,$id,$messages){$service->finish($id,'reset',$messages[$id]['code'],'s2','StrongPassword!','StrongPassword!');},'resend supersedes previous OTP');
rejects(function()use($service,$next,$messages){$service->finish($next,'reset',$messages[$next]['code'],'s2','12345678','12345678');},'weak reset password rejected');
check($service->finish($next,'reset',$messages[$next]['code'],'s2',' StrongPassword! ',' StrongPassword! ')===2,'reset after validation succeeds');
$hash=$pdo->query('SELECT password FROM admins WHERE id=1')->fetchColumn();
check(strlen($hash)>32 && auth_password_verify(' StrongPassword! ',$hash),'new administrator password can log in including whitespace');
check(!auth_password_verify('OriginalPassword!',$hash),'old password no longer works');
check($pdo->query('SELECT code_hash FROM account_recovery_challenges WHERE id='.$pdo->quote($next))->fetchColumn()==='','consumed OTP hash erased');
rejects(function()use($service,$next,$messages){$service->finish($next,'reset',$messages[$next]['code'],'s2','AnotherPassword!','AnotherPassword!');},'reset OTP is single use');
$_SESSION=['admin_id'=>1,'auth_version'=>1];auth_session_validate();check(!isset($_SESSION['admin_id']),'reset revokes old administrator sessions');

[$pdo,$service]=fixture($now,$messages);bindAdmin($service,$messages);$now+=61;
$id=$service->send('reset','admin','13900000001','s2','192.0.2.2');$now+=300;
rejects(function()use($service,$id,$messages){$service->finish($id,'reset',$messages[$id]['code'],'s2','StrongPassword!','StrongPassword!');},'OTP expires at five minutes');
check(auth_password_verify('OriginalPassword!',$pdo->query('SELECT password FROM admins')->fetchColumn()),'expiry preserves password');

[$pdo,$service]=fixture($now,$messages);bindAdmin($service,$messages);$now+=61;
$id=$service->send('reset','admin','13900000001','s2','192.0.2.2');
$pdo->prepare('UPDATE account_recovery_contacts SET phone_cipher=?')->execute([pv_encrypt('13900000003')]);
rejects(function()use($service,$id,$messages){$service->finish($id,'reset',$messages[$id]['code'],'s2','StrongPassword!','StrongPassword!');},'changed phone invalidates pending reset');
$pdo->exec('UPDATE admins SET auth_version=auth_version+1');
rejects(function()use($service,$id,$messages){$service->finish($id,'reset',$messages[$id]['code'],'s2','StrongPassword!','StrongPassword!');},'changed authentication version invalidates reset');

[$pdo,$service]=fixture($now,$messages);
$id=$service->send('reset','staff','13900000002','staff-session','192.0.2.2');
check($service->finish($id,'reset',$messages[$id]['code'],'staff-session','NewStaffPassword!','NewStaffPassword!')===1,'staff can recover using pre-existing bound phone');
$staff=$pdo->query('SELECT * FROM project_users')->fetch(PDO::FETCH_ASSOC);
check(password_verify('NewStaffPassword!',$staff['password_hash']) && !password_verify('StaffPassword!',$staff['password_hash']) && $staff['password_changed_at']!==null,'staff password and first-login flag updated');
$_SESSION=['project_user_id'=>1,'auth_version'=>0];auth_session_validate();check(!isset($_SESSION['project_user_id']),'old staff sessions revoked');
$now+=61;$id=$service->send('reset','staff','13900000002','s3','192.0.2.3');
$pdo->exec('UPDATE project_users SET is_active=0');
rejects(function()use($service,$id,$messages){$service->finish($id,'reset',$messages[$id]['code'],'s3','AnotherPassword!','AnotherPassword!');},'inactive staff cannot recover');

foreach (['unknown','failed'] as $state) {
    [$pdo,$service]=fixture($now,$messages,$state);
    $id=$service->send('reset','staff','13900000002','s1','192.0.2.1');
    rejects(function()use($service,$id,$messages){$service->finish($id,'reset',$messages[$id]['code'],'s1','StrongPassword!','StrongPassword!');},'failed or unknown provider result cannot reset');
    check(count($messages)===1,'unknown result is not automatically retried');
}
[$pdo,$service]=fixture($now,$messages);bindAdmin($service,$messages);$now+=61;
$id=$service->send('reset','admin','13900000001','s2','192.0.2.2');$pdo->exec('UPDATE account_recovery_config SET enabled=0');
rejects(function()use($service,$id,$messages){$service->finish($id,'reset',$messages[$id]['code'],'s2','StrongPassword!','StrongPassword!');},'disabling recovery invalidates pending OTP');

[$pdo,$service]=fixture($now,$messages);
for($i=0;$i<10;$i++) { $service->send('reset','missing-account','13900000003','s1','192.0.2.1');$now+=61; }
rejects(function()use($service){$service->send('reset','missing-account','13900000003','s1','192.0.2.1');},'phone daily cap includes unmatched requests');
check(count($messages)===0,'unknown accounts never call SMS transport');
check($pdo->query("SELECT state FROM account_recovery_challenges LIMIT 1")->fetchColumn()==='not_sent','unmatched requests never claim sent status');

[$pdo,$service]=fixture($now,$messages);
$pdo->prepare('INSERT INTO account_recovery_contacts (account_type,account_id,phone_hash,phone_cipher,verified_at) VALUES (?,?,?,?,0)')->execute(['admin',1,hash_hmac('sha256','phone:13900000001',str_repeat('H',32)),pv_encrypt('13900000001')]);
$id=$service->send('reset','13900000001','13900000001','s1','192.0.2.1');
check(count($messages)===1,'preconfigured administrator phone can receive a recovery OTP without password login');
check((int)$pdo->query('SELECT verified_at FROM account_recovery_contacts')->fetchColumn()===0,'sending OTP does not mark phone verified');
rejects(function()use($service,$id){$service->finish($id,'reset','000000','s1','StrongPassword!','StrongPassword!');},'wrong OTP cannot verify preconfigured phone');
check((int)$pdo->query('SELECT verified_at FROM account_recovery_contacts')->fetchColumn()===0,'wrong OTP leaves preconfigured phone unverified');
rejects(function()use($service,$id,$messages){$service->finish($id,'reset',$messages[$id]['code'],'s1','12345678','12345678');},'weak password cannot complete preconfigured recovery');
check((int)$pdo->query('SELECT verified_at FROM account_recovery_contacts')->fetchColumn()===0,'failed password validation leaves phone unverified');
$service->finish($id,'reset',$messages[$id]['code'],'s1','StrongPassword!','StrongPassword!');
check((int)$pdo->query('SELECT verified_at FROM account_recovery_contacts')->fetchColumn()===$now,'successful OTP reset verifies preconfigured phone atomically');
require_once __DIR__.'/../includes/RecoverySms.php';
$config=['access_key_id'=>'TestAccessKey','secret_cipher'=>pv_encrypt('TestSigningSecret'),'sign_name'=>'测试签名','template_code'=>'SMS_509735214'];
$request=recovery_sms_request($config,'13900000001','654321','test-request','2026-10-10T00:00:00Z','test-nonce');
parse_str(parse_url($request['url'],PHP_URL_QUERY),$params);
check(parse_url($request['url'],PHP_URL_HOST)==='dysmsapi.aliyuncs.com' && parse_url($request['url'],PHP_URL_SCHEME)==='https','provider endpoint is HTTPS and fixed');
check(json_decode($params['TemplateParam'],true)===['code'=>'654321'] && $params['TemplateCode']==='SMS_509735214','verification code uses independent code template');
check(strpos($request['url'],'TestSigningSecret')===false && strpos($request['url'],'TestAccessKey')===false,'provider credentials stay out of URL');
check($request['headers']['x-acs-date']==='2026-10-10T00:00:00Z' && $request['headers']['x-acs-action']==='SendSms','Aliyun action and timestamp correct');
check(strpos($request['headers']['Authorization'],'ACS3-HMAC-SHA256 Credential=TestAccessKey,')===0,'request uses ACS3 authorization');
check(preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/',recovery_sms_request($config,'13900000001','654321','test-request')['headers']['x-acs-date'])===1,'generated timestamp has UTC format');
check(recovery_sms_response('{"Code":"OK"}',0,200)['state']==='sent','provider OK accepted only with HTTP 200');
check(recovery_sms_response('{"Code":"OK"}',0,500)['state']==='unknown','ambiguous provider HTTP result is unknown');
check(recovery_sms_response('{"Code":"isv.AMOUNT_NOT_ENOUGH"}',0,200)['state']==='failed','provider rejection is failed');
check(recovery_sms_response('not-json',0,200)['state']==='unknown','invalid provider body is unknown');
check(recovery_sms_response('{"Code":"OK"}',28,200)['state']==='unknown','transport timeout stays unknown');
[$pdo,$service]=fixture($now,$messages);
rejects(function()use($service){$service->send('bind','','13900000002','s1','192.0.2.1',['type'=>'admin','id'=>1],'OriginalPassword!');},'cannot bind staff phone to another account');
$service->send('reset','staff','13900000002','s1','192.0.2.1');
rejects(function()use($service){$service->send('reset','missing','13900000003','s2','192.0.2.1');},'IP cooldown cannot be bypassed with another phone');

echo json_encode(['result'=>'passed','checks'=>$checks,'real_sms_sent'=>false],JSON_UNESCAPED_UNICODE)."\n";
