<?php
/** Database-only registration ledger. No network or application bootstrap; injectable for isolated tests. */
function br_actor_key($actor) { return (string)$actor['type'].':'.(int)$actor['id']; }
// Company default for new registrations (Bizcn built-in DNS, same as existing domains). Administrators can override it in settings.
function br_default_ns() { return ['ns7.cnmsn.net','ns8.cnmsn.net']; }
function br_admin($actor) { return ($actor['type'] ?? '') === 'admin'; }
function br_day($now) { return (new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d'); }
function br_settings($pdo, $account)
{
    $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    $pdo->prepare($sql.' INTO bizcn_registration_settings (provider_account,templates_json,ns_json) VALUES (?,?,?)')->execute([$account,'[]','[]']);
    $q=$pdo->prepare('SELECT * FROM bizcn_registration_settings WHERE provider_account=?');$q->execute([$account]);$s=$q->fetch(PDO::FETCH_ASSOC);
    $s['daily_limit']=(int)$s['daily_limit'];$s['revision']=(int)$s['revision'];$s['templates']=json_decode($s['templates_json'],true) ?: [];$s['ns']=json_decode($s['ns_json'],true) ?: br_default_ns();
    return $s;
}
function br_lock_settings($pdo,$account)
{
    // Both drivers serialize quota decisions on the same account settings row.
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$pdo->prepare('UPDATE bizcn_registration_settings SET revision=revision WHERE provider_account=?')->execute([$account]);
    $q=$pdo->prepare('SELECT * FROM bizcn_registration_settings WHERE provider_account=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$account]);return $q->fetch(PDO::FETCH_ASSOC);
}
function br_usage($pdo,$account,$day)
{
    // Carry unresolved earlier requests forward; confirmations on a later day also consume that day.
    $q=$pdo->prepare("SELECT COUNT(*) FROM bizcn_registration_requests WHERE provider_account=? AND (status IN ('reserved','unknown','processing') OR (status='registered' AND (usage_day=? OR confirmed_day=?)))");$q->execute([$account,$day,$day]);return (int)$q->fetchColumn();
}
function br_save_settings($pdo,$account,$actor,$limit,$templates,$ns,$expectedRevision)
{
    if(!br_admin($actor))throw new RuntimeException('只有管理员可以设置注册额度和实名模板');
    if(!preg_match('/^\d{1,4}$/D',(string)$limit) || (int)$limit>1000)throw new InvalidArgumentException('每日注册上限请输入 0–1000 的整数；0 为暂停注册');
    if(count($templates)>50 || !in_array(count($ns),[0,2],true) || (count($templates)>0 && count($ns)!==2))throw new InvalidArgumentException('请选择最多50个已通过实名模板，并设置两个不同的默认 NS；未启用模板时可先只设置额度');
    $pdo->beginTransaction();try {
        $s=br_lock_settings($pdo,$account);if((int)$s['revision']!==(int)$expectedRevision)throw new RuntimeException('其他管理员已更新配置，请刷新后重试');
        $pdo->prepare('UPDATE bizcn_registration_settings SET daily_limit=?,templates_json=?,ns_json=?,revision=revision+1,updated_by=? WHERE provider_account=?')->execute([(int)$limit,json_encode($templates,JSON_UNESCAPED_UNICODE),json_encode($ns),$actor['id'],$account]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function br_quote_save($pdo,$account,$actor,$quote,$now)
{
    $token=bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO bizcn_registration_requests (provider_account,token_hash,actor_key,domainname,quote_json,expires_at) VALUES (?,?,?,?,?,?)')->execute([$account,hash('sha256',$token),br_actor_key($actor),$quote['domain'],json_encode($quote,JSON_UNESCAPED_UNICODE),$now+300]);
    return ['id'=>(int)$pdo->lastInsertId(),'token'=>$token,'expires_at'=>$now+300,'quote'=>$quote];
}
function br_request($pdo,$account,$actor,$id)
{
    $q=$pdo->prepare('SELECT * FROM bizcn_registration_requests WHERE provider_account=? AND id=?');$q->execute([$account,(int)$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r || (!br_admin($actor) && $r['actor_key']!==br_actor_key($actor)))throw new RuntimeException('无法查看此注册申请');return $r;
}
function br_reserve($pdo,$account,$actor,$token,$confirm,$ack,$now)
{
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)$token) || $ack!=='1')throw new InvalidArgumentException('请核对注册资料并确认真实扣费');
    $pdo->beginTransaction();try {
        $s=br_lock_settings($pdo,$account);
        $q=$pdo->prepare('SELECT * FROM bizcn_registration_requests WHERE provider_account=? AND token_hash=?');$q->execute([$account,hash('sha256',$token)]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r || $r['actor_key']!==br_actor_key($actor) || $r['domainname']!==$confirm)throw new RuntimeException('注册确认信息不匹配，请重新查询');
        if($r['status']!=='quoted'){$pdo->commit();return $r;}
        $quote=json_decode($r['quote_json'],true);
        if((int)$r['expires_at']<$now || (int)$quote['revision']!==(int)$s['revision'])throw new RuntimeException('报价已过期或配置已变化，请重新查询');
        if(!empty($quote['premium']) && !br_admin($actor))throw new RuntimeException('高价域名仅允许管理员注册');
        $day=br_day($now);
        if(br_usage($pdo,$account,$day)>=(int)$s['daily_limit'])throw new RuntimeException('今日注册额度已用完，或有未确认的申请占用额度，请联系管理员');
        $domainLock=hash('sha256',$account.'|'.$r['domainname']);
        $dupe=$pdo->prepare('SELECT id FROM bizcn_registration_requests WHERE domain_lock=?');$dupe->execute([$domainLock]);if($dupe->fetchColumn())throw new RuntimeException('该域名已有注册申请，请先核对申请记录，勿重复购买');
        $pdo->prepare("UPDATE bizcn_registration_requests SET status='reserved',domain_lock=?,usage_day=? WHERE id=? AND status='quoted'")->execute([$domainLock,$day,$r['id']]);$pdo->commit();return br_request($pdo,$account,$actor,$r['id']);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function br_mark_started($pdo,$id,$now)
{
    // Persist the ambiguity BEFORE the paid call. Never retry a potentially billed request.
    $q=$pdo->prepare("UPDATE bizcn_registration_requests SET status='unknown',provider_started_at=? WHERE id=? AND status='reserved' AND provider_started_at IS NULL");$q->execute([$now,$id]);return $q->rowCount()===1;
}
function br_state($data)
{
    if((string)($data['status'] ?? '')==='200')return 'registered';
    if((string)($data['status'] ?? '')==='3')return 'failed';
    if((string)($data['status'] ?? '')==='1')return 'processing';
    return 'unknown';
}
function br_record_result($pdo,$id,$data,$now)
{
    $state=br_state($data);$pdo->beginTransaction();try {
        $q=$pdo->prepare('SELECT * FROM bizcn_registration_requests WHERE id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r)throw new RuntimeException('注册记录不存在');
        if(!in_array($r['status'],['unknown','processing'],true)){$pdo->commit();return $r['status'];}
        if(!empty($data['domainname']) && strtolower($data['domainname'])!==$r['domainname'])throw new RuntimeException('注册商返回域名不匹配，需要管理员核对');
        if(!empty($r['order_id']) && !empty($data['orderid']) && $r['order_id']!==(string)$data['orderid'])throw new RuntimeException('注册商订单号不匹配');
        $safe=array_intersect_key($data,array_flip(['orderid','status','domainname','createdate','expirationdate','expirationtime','registrar','totalprice']));
        // Validate totalprice against the confirmed quote; a mismatched billed amount needs reconciliation.
        $quote=json_decode($r['quote_json'],true);
        if($state==='registered' && isset($data['totalprice']) && (int)round((float)$data['totalprice']*100)!==(int)$quote['price_cents'])$state='unknown';
        $pdo->prepare('UPDATE bizcn_registration_requests SET status=?,order_id=?,result_json=?,confirmed_day=?,domain_lock=? WHERE id=?')->execute([$state,(string)(!empty($data['orderid']) ? $data['orderid'] : $r['order_id']),json_encode($safe,JSON_UNESCAPED_UNICODE),$state==='registered'?br_day($now):null,$state==='failed'?null:$r['domain_lock'],$id]);
        $pdo->commit();return $state;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function br_release_unknown($pdo,$account,$actor,$id,$note,$confirm)
{
    if(!br_admin($actor))throw new RuntimeException('仅管理员可核对未确认申请');
    $r=br_request($pdo,$account,$actor,$id);$note=trim((string)$note);
    if($confirm!==$r['domainname'] || mb_strlen($note)<8 || mb_strlen($note)>500)throw new InvalidArgumentException('请输入核验依据（至少8字），并确认域名；须向注册商确认未注册且未扣款');
    if($r['provider_started_at']!==null && (int)$r['provider_started_at']+120>time())throw new RuntimeException('注册请求仍可能在处理，请至少等待两分钟再核验');
    // Releasing a reserved request while a worker is about to call the provider would bypass quota.
    if($r['status']==='reserved' && (int)$r['expires_at']+300>time())throw new RuntimeException('申请刚提交，请稍后再核验，避免与注册请求同时操作');
    $q=$pdo->prepare("UPDATE bizcn_registration_requests SET status='failed',domain_lock=NULL,resolution_note=? WHERE id=? AND provider_account=? AND status IN ('unknown','reserved') AND provider_started_at ".($r['provider_started_at']===null?'IS NULL':'=?'));
    $values=['管理员'.$actor['id'].'：'.$note,$id,$account];if($r['provider_started_at']!==null)$values[]=$r['provider_started_at'];$q->execute($values);
    if($q->rowCount()!==1)throw new RuntimeException('申请状态已变化，不能释放，请刷新核对');
}
