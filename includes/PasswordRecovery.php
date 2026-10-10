<?php
require_once __DIR__.'/AuthPassword.php';

class PasswordRecovery
{
    private $pdo, $key, $transport, $clock;
    public function __construct(PDO $pdo, $key, callable $transport, callable $clock = null)
    {
        $this->pdo=$pdo; $this->key=$key; $this->transport=$transport; $this->clock=$clock ?: function () { return time(); };
    }
    private function now() { return (int)call_user_func($this->clock); }
    private function hash($value) { return hash_hmac('sha256', $value, $this->key); }
    private function lock() { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' FOR UPDATE' : ''; }
    private function row($sql, $args=[]) { $q=$this->pdo->prepare($sql); $q->execute($args); return $q->fetch(PDO::FETCH_ASSOC); }
    private function run($sql, $args=[]) { $q=$this->pdo->prepare($sql); $q->execute($args); return $q; }
    private function table($type) { if (!in_array($type,['admin','employee'],true)) throw new RuntimeException('账号类型无效'); return $type==='admin'?'admins':'project_users'; }
    private function account($type, $id, $lock=false) {
        return $this->row('SELECT * FROM '.$this->table($type).' WHERE id=?'.($type==='employee'?' AND is_active=1':'').($lock?$this->lock():''),[$id]);
    }
    private function boundPhone($type, $account) {
        $contact=$this->row('SELECT * FROM account_recovery_contacts WHERE account_type=? AND account_id=?',[$type,$account['id']]);
        return $contact ? pv_decrypt($contact['phone_cipher']) : ($type==='employee'?(string)($account['phone']??''):'');
    }
    public function send($purpose, $username, $phone, $sessionId, $ip, $actor=null, $currentPassword='')
    {
        if (!in_array($purpose,['reset','bind'],true)) throw new RuntimeException('请求无效');
        if (!preg_match('/^1[3-9][0-9]{9}$/D',$phone)) throw new RuntimeException('请输入有效的 11 位手机号');
        $now=$this->now(); $phoneHash=$this->hash('phone:'.$phone); $ipHash=$this->hash('ip:'.$ip);
        $this->pdo->beginTransaction();
        try {
            // Serialize reservations across workers so simultaneous sends cannot bypass limits.
            $settings=$this->row('SELECT * FROM account_recovery_config WHERE id=1'.$this->lock());
            if (!$settings || !$settings['enabled']) throw new RuntimeException('短信找回尚未启用，请联系管理员');
            $account=null; $type='admin';
            if ($purpose==='bind') {
                if (!$actor) throw new RuntimeException('请先登录');
                $type=$actor['type']; $account=$this->account($type,(int)$actor['id'],true);
                if (!$account || !auth_password_verify($currentPassword,$account[$type==='admin'?'password':'password_hash'])) throw new RuntimeException('当前密码不正确');
                $other=$this->row('SELECT account_id FROM account_recovery_contacts WHERE phone_hash=? AND NOT (account_type=? AND account_id=?)',[$phoneHash,$type,$account['id']]);
                $staff=$this->row('SELECT id FROM project_users WHERE phone=? AND id<>?',[$phone,$type==='employee'?$account['id']:0]);
                if ($other || $staff) throw new RuntimeException('此手机号已绑定其他账号');
            } else {
                $account=$this->row('SELECT * FROM admins WHERE username=?',[$username]);
                if (!$account) { $type='employee'; $account=$this->row('SELECT * FROM project_users WHERE (username=? OR phone=?) AND is_active=1',[$username,$username]); }
                if ($account && !hash_equals($phone,$this->boundPhone($type,$account))) $account=null;
            }
            $last=$this->row('SELECT MAX(created_at) AS latest FROM account_recovery_challenges WHERE phone_hash=? OR ip_hash=?',[$phoneHash,$ipHash]);
            if ($last['latest']!==null && $now-(int)$last['latest']<60) throw new RuntimeException('请等待 60 秒后再获取验证码');
            $count=$this->row('SELECT COUNT(*) AS total FROM account_recovery_challenges WHERE phone_hash=? AND created_at>?',[$phoneHash,$now-86400]);
            $ipCount=$this->row('SELECT COUNT(*) AS total FROM account_recovery_challenges WHERE ip_hash=? AND created_at>?',[$ipHash,$now-3600]);
            $total=$this->row('SELECT COUNT(*) AS total FROM account_recovery_challenges WHERE created_at>?',[$now-86400]);
            if ($count['total']>=10 || $ipCount['total']>=30 || $total['total']>=200) throw new RuntimeException('短信请求较多，请稍后再试');
            $id=bin2hex(random_bytes(32)); $code=(string)random_int(100000,999999);
            $this->run("UPDATE account_recovery_challenges SET state='superseded' WHERE session_hash=? AND purpose=? AND state IN ('sent','sending','unknown')",[$this->hash('session:'.$sessionId),$purpose]);
            $this->run("INSERT INTO account_recovery_challenges (id,purpose,account_type,account_id,auth_version,phone_hash,phone_cipher,code_hash,session_hash,ip_hash,state,attempts,created_at,expires_at,provider_code) VALUES (?,?,?,?,?,?,?,?,?,?,'sending',0,?,?,?)",
                [$id,$purpose,$type,$account?(int)$account['id']:0,(int)($account['auth_version']??0),$phoneHash,pv_encrypt($phone),$this->hash('code:'.$id.':'.$code),$this->hash('session:'.$sessionId),$ipHash,$now,$now+300,'']);
            $this->run('DELETE FROM account_recovery_challenges WHERE created_at<?',[$now-604800]);
            $this->pdo->commit();
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
        // Unknown accounts receive the same public response and consume the same rate limit.
        $result=['state'=>'sent','code'=>'NO_MATCH'];
        if ($account) {
            try { $result=call_user_func($this->transport,$phone,$code,$id); }
            catch (Throwable $e) { $result=['state'=>'unknown','code'=>'TRANSPORT_RESULT_UNKNOWN']; }
        }
        $state=in_array($result['state']??'', ['sent','failed','unknown'],true)?$result['state']:'unknown';
        $this->run("UPDATE account_recovery_challenges SET state=?,provider_code=? WHERE id=? AND state='sending'",[$state,substr(preg_replace('/[^A-Za-z0-9._-]/','',(string)($result['code']??'')),0,100),$id]);
        return $id;
    }
    public function finish($id, $purpose, $code, $sessionId, $password='', $confirmation='')
    {
        $this->pdo->beginTransaction();
        try {
            $config=$this->row('SELECT * FROM account_recovery_config WHERE id=1'.$this->lock());
            if (!$config || !$config['enabled']) throw new RuntimeException('短信找回已停用，请联系管理员');
            $challenge=$this->row('SELECT * FROM account_recovery_challenges WHERE id=?'.$this->lock(),[$id]);
            if (!$challenge || !hash_equals($challenge['session_hash'],$this->hash('session:'.$sessionId)) || $challenge['purpose']!==$purpose) throw new RuntimeException('验证请求已失效，请重新获取验证码');
            if ($challenge['state']!=='sent' || (int)$challenge['expires_at']<=$this->now() || (int)$challenge['attempts']>=5) throw new RuntimeException('验证码已失效，请重新获取');
            if (!preg_match('/^[0-9]{6}$/D',$code) || !hash_equals($challenge['code_hash'],$this->hash('code:'.$id.':'.$code))) {
                $this->run('UPDATE account_recovery_challenges SET attempts=attempts+1 WHERE id=?',[$id]);
                $this->pdo->commit();
                throw new RuntimeException('验证码不正确');
            }
            $type=$challenge['account_type']; $account=$this->account($type,(int)$challenge['account_id'],true);
            if (!$account || (int)$account['auth_version']!==(int)$challenge['auth_version']) throw new RuntimeException('账号信息已变更，请重新获取验证码');
            $phone=pv_decrypt($challenge['phone_cipher']); $version=(int)$account['auth_version']+1;
            if ($purpose==='reset') {
                if (!hash_equals($phone,$this->boundPhone($type,$account))) throw new RuntimeException('手机号已变更，请重新获取验证码');
                auth_password_validate($password,$confirmation,$account['username']);
                $column=$type==='admin'?'password':'password_hash';
                $sql='UPDATE '.$this->table($type).' SET '.$column.'=?,auth_version=?'.($type==='employee'?',password_changed_at=CURRENT_TIMESTAMP':'').' WHERE id=?';
                $this->run($sql,[password_hash($password,PASSWORD_DEFAULT),$version,$account['id']]);
            } elseif ($purpose==='bind') {
                $other=$this->row('SELECT account_id FROM account_recovery_contacts WHERE phone_hash=? AND NOT (account_type=? AND account_id=?)',[$challenge['phone_hash'],$type,$account['id']]);
                $staff=$this->row('SELECT id FROM project_users WHERE phone=? AND id<>?',[$phone,$type==='employee'?$account['id']:0]);
                if ($other || $staff) throw new RuntimeException('此手机号已绑定其他账号');
                $this->run('DELETE FROM account_recovery_contacts WHERE account_type=? AND account_id=?',[$type,$account['id']]);
                $this->run('INSERT INTO account_recovery_contacts (account_type,account_id,phone_hash,phone_cipher,verified_at) VALUES (?,?,?,?,?)',[$type,$account['id'],$challenge['phone_hash'],$challenge['phone_cipher'],$this->now()]);
                $this->run('UPDATE '.$this->table($type).' SET auth_version=? WHERE id=?',[$version,$account['id']]);
                if ($type==='employee') $this->run('UPDATE project_users SET phone=? WHERE id=?',[$phone,$account['id']]);
            } else { throw new RuntimeException('请求无效'); }
            $this->run("UPDATE account_recovery_challenges SET state='consumed',code_hash='' WHERE id=?",[$id]);
            $this->run("UPDATE account_recovery_challenges SET state='superseded' WHERE account_type=? AND account_id=? AND id<>? AND state IN ('sent','sending','unknown')",[$type,$account['id'],$id]);
            $this->pdo->commit();
            return $version;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
}
