<?php
/** Operator-only bootstrap: a preconfigured phone remains unverified until a valid SMS OTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/ProjectVault.php';
$type=(string)($argv[1]??''); $username=(string)($argv[2]??''); $phone=(string)($argv[3]??'');
if (!in_array($type,['admin','employee'],true) || $username==='' || !preg_match('/^1[3-9][0-9]{9}$/D',$phone)) {
    file_put_contents('php://stderr',"Usage: php set_recovery_phone.php admin|employee username phone\n"); exit(1);
}
$p=db(); $table=$type==='admin'?'admins':'project_users'; $phoneHash=hash_hmac('sha256','phone:'.$phone,pv_key());
$p->beginTransaction();
try {
    $q=$p->prepare('SELECT * FROM '.$table.' WHERE username=?'.($type==='employee'?' AND is_active=1':'').' FOR UPDATE'); $q->execute([$username]); $account=$q->fetch(PDO::FETCH_ASSOC);
    if (!$account) throw new RuntimeException('Account not found');
    $q=$p->prepare('SELECT * FROM account_recovery_contacts WHERE account_type=? AND account_id=?'); $q->execute([$type,$account['id']]); $old=$q->fetch(PDO::FETCH_ASSOC);
    if ($old && (int)$old['verified_at']>0 && !hash_equals($old['phone_hash'],$phoneHash)) throw new RuntimeException('A verified phone already exists; use the authenticated phone-change flow');
    $q=$p->prepare('SELECT account_id FROM account_recovery_contacts WHERE phone_hash=? AND NOT (account_type=? AND account_id=?)'); $q->execute([$phoneHash,$type,$account['id']]);
    if ($q->fetchColumn()) throw new RuntimeException('Phone is assigned to another account');
    $q=$p->prepare('SELECT id FROM project_users WHERE phone=? AND id<>?'); $q->execute([$phone,$type==='employee'?$account['id']:0]);
    if ($q->fetchColumn()) throw new RuntimeException('Phone is assigned to another staff account');
    if (!$old || !hash_equals($old['phone_hash'],$phoneHash)) {
        $p->prepare('DELETE FROM account_recovery_contacts WHERE account_type=? AND account_id=?')->execute([$type,$account['id']]);
        $p->prepare('INSERT INTO account_recovery_contacts (account_type,account_id,phone_hash,phone_cipher,verified_at) VALUES (?,?,?,?,0)')->execute([$type,$account['id'],$phoneHash,pv_encrypt($phone)]);
        ps_audit('account',$account['id'],'preset_recovery_phone',['type'=>'system','id'=>0],['account_type'=>$type,'phone'=>substr($phone,0,3).'****'.substr($phone,-4),'verified'=>false]);
    }
    $p->commit();
    echo json_encode(['account'=>$username,'phone'=>substr($phone,0,3).'****'.substr($phone,-4),'verified'=>$old && (int)$old['verified_at']>0,'password_changed'=>false],JSON_UNESCAPED_UNICODE)."\n";
} catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack(); file_put_contents('php://stderr',$e->getMessage()."\n"); exit(1); }
