<?php
/** Standalone Aliyun verification SMS transport; never logs request URLs or codes. */
function recovery_sms_request($config, $phone, $code, $outId, $timestamp=null, $nonce=null)
{
    $query = ['PhoneNumbers'=>$phone,'SignName'=>$config['sign_name'],'TemplateCode'=>$config['template_code'],'TemplateParam'=>json_encode(['code'=>$code]),'OutId'=>$outId];
    ksort($query, SORT_STRING);
    $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $headers = ['host'=>'dysmsapi.aliyuncs.com','x-acs-action'=>'SendSms','x-acs-content-sha256'=>hash('sha256',''),'x-acs-date'=>($timestamp ?: gmdate('Y-m-d\\TH:i:s\\Z')),'x-acs-signature-nonce'=>($nonce ?: bin2hex(random_bytes(16))),'x-acs-version'=>'2017-05-25'];
    ksort($headers, SORT_STRING);
    $canonicalHeaders = '';
    foreach ($headers as $key=>$value) $canonicalHeaders .= $key.':'.trim($value)."\n";
    $signed = implode(';', array_keys($headers));
    $canonical = "POST\n/\n".$queryString."\n".$canonicalHeaders."\n".$signed."\n".hash('sha256','');
    $signature = hash_hmac('sha256', "ACS3-HMAC-SHA256\n".hash('sha256',$canonical), pv_decrypt($config['secret_cipher']));
    $headers['Authorization'] = 'ACS3-HMAC-SHA256 Credential='.$config['access_key_id'].',SignedHeaders='.$signed.',Signature='.$signature;
    return ['url'=>'https://dysmsapi.aliyuncs.com/?'.$queryString,'headers'=>$headers];
}
function recovery_sms_response($body, $error, $http)
{
    $json = json_decode((string)$body,true);
    if ($error || !is_array($json) || !isset($json['Code'])) return ['state'=>'unknown','code'=>'TRANSPORT_RESULT_UNKNOWN'];
    $providerCode = substr(preg_replace('/[^A-Za-z0-9._-]/','',(string)$json['Code']),0,100);
    return ['state'=>$providerCode==='OK' && $http===200 ? 'sent' : ($providerCode==='OK' ? 'unknown' : 'failed'),'code'=>$providerCode];
}
function recovery_sms_send($config, $phone, $code, $outId)
{
    $request = recovery_sms_request($config,$phone,$code,$outId);
    $headers = $request['headers'];
    $lines = [];
    foreach ($headers as $key=>$value) $lines[] = $key.': '.$value;
    $ch = curl_init($request['url']);
    curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'',CURLOPT_HTTPHEADER=>$lines,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
    $body = curl_exec($ch); $error = curl_errno($ch); $http = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return recovery_sms_response($body,$error,$http);
}
