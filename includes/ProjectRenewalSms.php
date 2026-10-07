<?php
require_once __DIR__ . '/ProjectRenewals.php';

function pr_sms_config()
{
    return db()->query('SELECT * FROM project_renewal_sms_config WHERE id=1')->fetch();
}
function pr_sms_map($json)
{
    $map = json_decode((string)$json, true);
    if (!is_array($map) || !$map || count($map) > 4) throw new RuntimeException('模板变量映射需要是 JSON 对象，例如 {"resource":"resource","order":"order","date":"date","days":"days"}');
    foreach ($map as $key=>$field) if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,29}$/D', (string)$key) || !in_array($field,['resource','order','date','days'],true)) throw new RuntimeException('变量值只能映射到 resource、order、date 或 days');
    return $map;
}
function pr_sms_save_config($source, $actor)
{
    if (!pr_is_super($actor)) throw new RuntimeException('只有超级管理员可以配置短信');
    $old = pr_sms_config();
    if ((int)$old['revision'] !== (int)($source['revision'] ?? 0)) throw new RuntimeException('配置已被更新，请刷新');
    $ak = trim((string)($source['access_key_id'] ?? '')); $secret = trim((string)($source['access_key_secret'] ?? ''));
    if (strlen($ak)>120 || strlen($secret)>200 || preg_match('/[\r\n]/',$ak.$secret)) throw new RuntimeException('短信凭据格式不正确');
    if ($ak !== $old['access_key_id'] && !$secret) throw new RuntimeException('更换 AccessKey ID 时，需要同时填写新 Secret');
    $cipher = $secret !== '' ? pv_encrypt($secret) : $old['secret_cipher'];
    $sign = trim((string)($source['sign_name'] ?? '')); $template = trim((string)($source['template_code'] ?? ''));
    if (mb_strlen($sign)>80 || ($template !== '' && !preg_match('/^SMS_[A-Za-z0-9]+$/D',$template))) throw new RuntimeException('请填写已审核的短信签名和 SMS_ 开头的模板编号');
    $map = pr_sms_map($source['param_map'] ?? '');
    $hour = filter_var($source['send_hour'] ?? '',FILTER_VALIDATE_INT); $limit = filter_var($source['daily_limit'] ?? '',FILTER_VALIDATE_INT);
    if ($hour===false || $hour<9 || $hour>17 || $limit===false || $limit<1 || $limit>10000) throw new RuntimeException('发送时间为 9—17 点，每日上限为 1—10000 条');
    $enabled = !empty($source['enabled']) ? 1 : 0;
    if ($enabled && (!$ak || !$cipher || !$sign || !$template || empty($source['confirm_approved']))) throw new RuntimeException('启用前需要完整凭据，并确认签名、模板已审核通过');
    $q = db()->prepare('UPDATE project_renewal_sms_config SET enabled=?,access_key_id=?,secret_cipher=?,sign_name=?,template_code=?,param_map=?,send_hour=?,daily_limit=?,revision=revision+1,updated_at=NOW() WHERE id=1 AND revision=?');
    $q->execute([$enabled,$ak,$cipher,$sign,$template,json_encode($map),$hour,$limit,$old['revision']]);
    if (!$q->rowCount()) throw new RuntimeException('配置已被同事更新，请刷新');
    ps_audit('renewal_sms',1,'configure',$actor,['enabled'=>$enabled,'provider'=>'aliyun','send_hour'=>$hour,'daily_limit'=>$limit]);
}
/** Official Aliyun ACS3 signing. Fixed HTTPS endpoint; no secrets in URLs or logs. */
function pr_sms_request($config, $phone, $values, $outId, $timestamp = null, $nonce = null)
{
    $map = pr_sms_map($config['param_map']); $params = [];
    foreach ($map as $key=>$field) $params[$key] = (string)$values[$field];
    $query = ['PhoneNumbers'=>$phone,'SignName'=>$config['sign_name'],'TemplateCode'=>$config['template_code'],'TemplateParam'=>json_encode($params,JSON_UNESCAPED_UNICODE),'OutId'=>$outId];
    ksort($query,SORT_STRING); $queryString = http_build_query($query,'','&',PHP_QUERY_RFC3986);
    $headers = ['host'=>'dysmsapi.aliyuncs.com','x-acs-action'=>'SendSms','x-acs-content-sha256'=>hash('sha256',''),'x-acs-date'=>$timestamp ?: gmdate('Y-m-d\TH:i:s\Z'),'x-acs-signature-nonce'=>$nonce ?: bin2hex(random_bytes(16)),'x-acs-version'=>'2017-05-25'];
    ksort($headers,SORT_STRING); $canonicalHeaders = '';
    foreach ($headers as $key=>$value) $canonicalHeaders .= $key . ':' . trim($value) . "\n";
    $signed = implode(';',array_keys($headers));
    $canonical = "POST\n/\n" . $queryString . "\n" . $canonicalHeaders . "\n" . $signed . "\n" . hash('sha256','');
    $signature = hash_hmac('sha256',"ACS3-HMAC-SHA256\n" . hash('sha256',$canonical),pv_decrypt($config['secret_cipher']));
    $headers['Authorization'] = 'ACS3-HMAC-SHA256 Credential=' . $config['access_key_id'] . ',SignedHeaders=' . $signed . ',Signature=' . $signature;
    return ['url'=>'https://dysmsapi.aliyuncs.com/?' . $queryString,'headers'=>$headers];
}
function pr_sms_response($body, $transportError, $httpCode)
{
    $json = json_decode((string)$body,true);
    if ($transportError || !is_array($json) || !isset($json['Code'])) return ['state'=>'unknown','code'=>'TRANSPORT_RESULT_UNKNOWN','request_id'=>'','biz_id'=>''];
    $code = preg_replace('/[^A-Za-z0-9._-]/','',(string)$json['Code']);
    $state = $code==='OK' && $httpCode===200 ? 'sent' : ($code==='OK' ? 'unknown' : 'failed');
    return ['state'=>$state,'code'=>substr($code,0,100),'request_id'=>substr(preg_replace('/[^A-Za-z0-9_-]/','',(string)($json['RequestId']??'')),0,120),'biz_id'=>substr(preg_replace('/[^A-Za-z0-9_-]/','',(string)($json['BizId']??'')),0,120)];
}
function pr_sms_send($config,$phone,$values,$outId)
{
    $request = pr_sms_request($config,$phone,$values,$outId); $headers=[];
    foreach ($request['headers'] as $key=>$value) $headers[]=$key.': '.$value;
    $ch = curl_init($request['url']);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'',CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
    $body=curl_exec($ch); $error=curl_errno($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return pr_sms_response($body,$error,$http);
}

function pr_sms_run($send = false, $transport = null, $now = null)
{
    $pdo=db(); $summary=['mode'=>$send?'send':'dry_run','queued'=>0,'sent'=>0,'failed'=>0,'unknown'=>0,'cancelled'=>0,'deferred'=>0];
    $now=$now ?: new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')); $now=$now->setTimezone(new DateTimeZone('Asia/Shanghai'));
    $today=$now->format('Y-m-d'); $hour=(int)$now->format('G');
    $config=pr_sms_config();
    if (!$config['enabled']) { $summary['reason']='disabled'; return $summary; }
    if ($hour<(int)$config['send_hour'] || $hour>=18) { $summary['reason']='outside_daytime_window'; return $summary; }
    if (!$pdo->query("SELECT GET_LOCK('xinzi_renewal_sms_worker',0)")->fetchColumn()) { $summary['reason']='already_running'; return $summary; }
    try {
        $candidates=$pdo->query("SELECT * FROM project_renewal_items WHERE status='active' AND sms_enabled=1 AND phone_hash<>'' AND resource_name<>'' AND expires_on IN (DATE_ADD('$today',INTERVAL 10 DAY),DATE_ADD('$today',INTERVAL 3 DAY),DATE_ADD('$today',INTERVAL 1 DAY)) ORDER BY expires_on,id")->fetchAll();
        if (!$send) { $summary['eligible_resources']=count($candidates); return $summary; }
        $pdo->exec("UPDATE project_renewal_sms SET state='unknown',provider_code='WORKER_INTERRUPTED',updated_at=NOW() WHERE state='sending' AND updated_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)");
        $q=$pdo->prepare('INSERT IGNORE INTO project_renewal_sms (item_id,expires_on,remind_days,phone_hash) VALUES (?,?,?,?)');
        foreach ($candidates as $item) { $q->execute([$item['id'],$item['expires_on'],pr_reminder_day($item,$today),$item['phone_hash']]); $summary['queued']+=$q->rowCount(); }
        $jobs=$pdo->query("SELECT id FROM project_renewal_sms WHERE state='pending' OR (state='failed' AND attempts<3 AND next_attempt_at<=NOW()) ORDER BY expires_on,id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($jobs as $id) {
            $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT * FROM project_renewal_sms WHERE id=? FOR UPDATE'); $q->execute([$id]); $job=$q->fetch();
            $q=$pdo->prepare('SELECT * FROM project_renewal_items WHERE id=? FOR UPDATE'); $q->execute([$job['item_id']]); $item=$q->fetch();
            $fresh=$pdo->query('SELECT * FROM project_renewal_sms_config WHERE id=1 FOR UPDATE')->fetch();
            if (!$fresh['enabled']) { $pdo->rollBack(); $summary['reason']='disabled_during_run'; break; }
            if (!$item || pr_reminder_day($item,$today)!==(int)$job['remind_days'] || $item['expires_on']!==$job['expires_on'] || $item['phone_hash']!==$job['phone_hash']) {
                $pdo->prepare("UPDATE project_renewal_sms SET state='cancelled',provider_code='STALE_RESOURCE_OR_DAY',updated_at=NOW() WHERE id=?")->execute([$id]); $pdo->commit(); $summary['cancelled']++; continue;
            }
            $q=$pdo->prepare("SELECT COUNT(*) FROM project_renewal_sms WHERE attempt_day=? AND state IN ('sent','sending','unknown') AND phone_hash=?"); $q->execute([$today,$item['phone_hash']]);
            $daily=$pdo->prepare("SELECT COUNT(*) FROM project_renewal_sms WHERE attempt_day=? AND state IN ('sent','sending','unknown')"); $daily->execute([$today]);
            if ($q->fetchColumn() || (int)$daily->fetchColumn()>=(int)$fresh['daily_limit']) { $pdo->rollBack(); $summary['deferred']++; continue; }
            $phone=pr_phone(pv_decrypt($item['phone_cipher']));
            if (!$phone) { $pdo->prepare("UPDATE project_renewal_sms SET state='cancelled',provider_code='PHONE_UNAVAILABLE',updated_at=NOW() WHERE id=?")->execute([$id]); $pdo->commit(); $summary['cancelled']++; continue; }
            $pdo->prepare("UPDATE project_renewal_sms SET state='sending',attempts=attempts+1,attempt_day=?,updated_at=NOW() WHERE id=?")->execute([$today,$id]);
            // Persist sending intent BEFORE network IO: a crash can never silently retry it.
            $pdo->commit(); $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT * FROM project_renewal_items WHERE id=? FOR UPDATE'); $q->execute([$job['item_id']]); $locked=$q->fetch();
            $fresh=$pdo->query('SELECT * FROM project_renewal_sms_config WHERE id=1 FOR UPDATE')->fetch();
            if (!$locked || !$fresh['enabled'] || (int)$locked['revision']!==(int)$item['revision']) {
                $pdo->prepare("UPDATE project_renewal_sms SET state='cancelled',provider_code='CHANGED_BEFORE_SEND',updated_at=NOW() WHERE id=?")->execute([$id]); $pdo->commit(); $summary['cancelled']++; continue;
            }
            // Resource/config locks keep renewal confirmation or disablement from racing this request (15s timeout).
            $oq=$pdo->prepare('SELECT order_no FROM project_orders WHERE id=?'); $oq->execute([$item['order_id']]);
            $values=['resource'=>(pr_type_labels()[$item['resource_type']] ?? '资源'),'order'=>(string)$oq->fetchColumn(),'date'=>$item['expires_on'],'days'=>(string)$job['remind_days']];
            try { $result=$transport ? $transport($fresh,$phone,$values,'renewal-'.$id) : pr_sms_send($fresh,$phone,$values,'renewal-'.$id); }
            catch (Throwable $e) { $result=['state'=>'unknown','code'=>'TRANSPORT_EXCEPTION','request_id'=>'','biz_id'=>'']; }
            $pdo->prepare('UPDATE project_renewal_sms SET state=?,provider_code=?,provider_request_id=?,provider_biz_id=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL 1 HOUR),updated_at=NOW() WHERE id=?')->execute([$result['state'],$result['code'],$result['request_id'],$result['biz_id'],$id]);
            $pdo->commit(); $summary[$result['state']]++;
        }
        $pdo->prepare('INSERT INTO project_renewal_job_runs (summary_json) VALUES (?)')->execute([json_encode($summary)]);
        return $summary;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('xinzi_renewal_sms_worker')"); }
}
