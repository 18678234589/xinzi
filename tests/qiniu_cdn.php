<?php
require_once __DIR__.'/../includes/QiniuCdn.php';
$checks=0;
function qc_test($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function qc_reject($fn){try{$fn();return false;}catch(RuntimeException $e){return true;}}
$input=['domain'=>'CDN.Example.com.','platform'=>'web','geo_cover'=>'foreign','source_type'=>'ip','source_value'=>'1.2.3.4, 1.2.3.4 2.3.4.5','test_path'=>'/health.txt'];
$payload=qc_payload($input);
qc_test($payload['name']==='cdn.example.com','normalize domain');
qc_test($payload['body']['source']['sourceIPs']===['1.2.3.4','2.3.4.5'],'deduplicate source addresses');
qc_test($payload['body']['source']['sourceURLScheme']==='http','correct origin protocol API field');
qc_test($payload['body']['cache']['cacheControls'][0]['type']==='follow'&&!$payload['body']['cache']['ignoreParam'],'follow origin and retain query parameters');
foreach (['geo_cover'=>'global','platform'=>'invalid','protocol'=>'ftp','source_value'=>'1.2.3.999','test_path'=>'https://host/file','source_host'=>'a.com\r\nHeader:x','cache_mode'=>'invalid','domain'=>'https://example.com'] as $field=>$bad) {
 qc_test(qc_reject(function()use($input,$field,$bad){qc_payload(array_replace($input,[$field=>$bad]));}),'reject '.$field);
}
$dom=array_replace($input,['source_type'=>'domain','source_value'=>'origin.example.com','source_host'=>'www.example.com']);
qc_test(qc_payload($dom)['body']['source']['sourceDomain']==='origin.example.com','domain origin');
qc_test(qc_reject(function()use($dom){qc_payload(array_replace($dom,['source_value'=>'cdn.example.com']));}),'prevent origin CNAME loop');
$bucket=array_replace($input,['source_type'=>'qiniuBucket','source_value'=>'example-bucket']);
qc_test(qc_payload($bucket)['body']['source']['sourceQiniuBucket']==='example-bucket','bucket origin');
qc_test(qc_reject(function()use($bucket){qc_payload(array_replace($bucket,['platform'=>'dynamic']));}),'dynamic bucket disallowed');
qc_test(qc_payload(array_replace($input,['geo_cover'=>'china','register_no'=>'京ICP备12345678号']))['body']['registerNo']==='京ICP备12345678号','备案 passed for domestic region');
qc_test(qc_reject(function()use($input){qc_payload(array_replace($input,['protocol'=>'https']));}),'HTTPS requires certificate');
$cert=['certid'=>'fixture','common_name'=>'*.example.com','not_before'=>time()-100,'not_after'=>time()+10000];
qc_test(qc_certificate_matches($cert,'cdn.example.com',time()),'wildcard covers one subdomain');
qc_test(!qc_certificate_matches($cert,'x.cdn.example.com',time()),'wildcard does not cover multiple levels');
qc_test(!qc_certificate_matches($cert,'example.com',time()),'wildcard does not cover apex');
qc_test(!qc_certificate_matches(array_replace($cert,['not_after'=>time()-1]),'cdn.example.com',time()),'expired certificate');
$safe=qc_public_domain(['name'=>'a.com','source'=>['sourceType'=>'domain','sourceDomain'=>'origin.example.com','sourceAuthInfo'=>['accessKeySecret'=>'private-fixture']],'https'=>['pri'=>'private-fixture']]);
qc_test(strpos(json_encode($safe),'private-fixture')===false,'public domain projection strips secrets');
qc_test(qc_public_domain(['name'=>'a.com'])['source_value']==='','summary without source');
$calls=[];$exists=false;$mode='success';
$GLOBALS['qc_mock']=function($service,$method,$path,$body)use(&$calls,&$exists,&$mode,$cert){
 $calls[]=[$service,$method,$path,$body];
 if($service==='fusion')return [200,['code'=>0,'certs'=>[$cert],'marker'=>'100']];
 if($method==='GET'&&strpos($path,'/domain?')===0)return [200,['domains'=>[['name'=>'cdn.example.com','operatingState'=>'success']],'marker'=>'next+/=']];
 if($method==='GET')return $exists?[200,['name'=>'cdn.example.com']]:[404,['code'=>404001,'error'=>'无此域名']];
 if($mode==='timeout')throw new RuntimeException('fixture timeout');
 if($mode==='reject')return [200,['code'=>400020,'error'=>'域名未备案']];
 if($mode==='malformed')return [200,[]];
 return [200,['code'=>200]];
};
qc_test(qc_list('a+/=')['marker']==='next+/=','paged list, response without code');
qc_test(strpos($calls[0][2],'marker=a%2B%2F%3D')!==false,'query marker encoded before signing');
qc_test(qc_certificates()[0]['certid']==='fixture','certificate metadata via fusion');
$calls=[];qc_create($payload);
qc_test(count($calls)===2&&$calls[1][1]==='POST'&&$calls[1][2]==='/domain/cdn.example.com','read before one create call');
qc_test($calls[1][3]['cache']['cacheControls'][0]['type']==='follow','create includes mandatory cache fallback');
$exists=true;$calls=[];
qc_test(qc_reject(function()use($payload){qc_create($payload);})&&count($calls)===1,'existing domain blocks creation');
$exists=false;$mode='reject';
qc_test(qc_reject(function()use($payload){qc_create($payload);}), 'provider logical error despite HTTP 200');
$mode='malformed';
qc_test(qc_reject(function()use($payload){qc_create($payload);}), 'empty HTTP success must not claim creation accepted');
$mode='success';$calls=[];
qc_create(qc_payload(array_replace($input,['protocol'=>'https','cert_id'=>'fixture'])));
qc_test(count(array_filter($calls,function($c){return $c[0]==='fusion';}))===1,'HTTPS certificate checked before creation');
qc_test(count(array_filter($calls,function($c){return $c[1]==='POST';}))===1,'HTTPS only one create request');
$tickets=['token'=>['actor'=>'employee:1','expires'=>time()+100,'payload'=>$payload]];
qc_test(qc_reject(function()use(&$tickets){qc_ticket_take($tickets,'token','employee:2','cdn.example.com','1',time());})&&isset($tickets['token']),'preview belongs to its actor');
qc_test(qc_reject(function()use(&$tickets){qc_ticket_take($tickets,'token','employee:1','wrong.com','1',time());})&&isset($tickets['token']),'exact domain confirmation');
qc_test(qc_reject(function()use(&$tickets){qc_ticket_take($tickets,'token','employee:1','cdn.example.com','0',time());}),'ack required');
$taken=qc_ticket_take($tickets,'token','employee:1','cdn.example.com','1',time());$mode='timeout';$calls=[];
qc_test(qc_reject(function()use($taken){qc_create($taken);}), 'timeout surfaced');
qc_test(qc_reject(function()use(&$tickets){qc_ticket_take($tickets,'token','employee:1','cdn.example.com','1',time());}), 'timeout cannot replay preview');
qc_test(count(array_filter($calls,function($c){return $c[1]==='POST';}))===1,'no automatic retry after ambiguous result');
$zones=['dnspod'=>['example.com'=>[],'nested.example.com'=>[],'example.com.cn'=>[]],'cloudflare'=>['example.com'=>[]]];
qc_test(dns_cdn_prefill('cdn.nested.example.com','target.qiniudns.com',$zones,'dnspod')['domain']==='nested.example.com','longest actual account zone');
qc_test(dns_cdn_prefill('cdn.example.com.cn','target.qiniudns.com',$zones,'dnspod')['name']==='cdn','no guessed public suffix');
qc_test(dns_cdn_prefill('notexample.com','target.qiniudns.com',$zones,'dnspod')===null,'zone suffix label boundary');
qc_test(dns_cdn_prefill('cdn.example.com','target.qiniudns.com',$zones,'cloudflare')['provider']==='cloudflare','selected provider preserved');
qc_test(dns_cdn_prefill('new.example.com.cn','target.qiniudns.com',$zones,'cloudflare')===null,'do not fall back to another account provider');
// 域名所有权验证：用主域名查验证信息 / 提交校验；失败给中文提示
$verifyState='doing';$verifyCalls=[];$verifyFail=false;
$prevMock=$GLOBALS['qc_mock'];
$GLOBALS['qc_mock']=function($service,$method,$path,$body)use(&$verifyState,&$verifyCalls,&$verifyFail,$prevMock){
    if(strpos($path,'/verify/info')!==false){$verifyCalls[]=[$method,$path];return [200,['domain'=>'example.com','dns'=>['host'=>'verification','recordType'=>'TXT','recordValue'=>'verify_abc'],'file'=>['fileName'=>'verification.html','fileContent'=>'verify_abc'],'state'=>$verifyState]];}
    if(strpos($path,'/verify/check')!==false){$verifyCalls[]=[$method,$path,$body];if($verifyFail)return [400,['code'=>400932,'error'=>'verify failed']];$verifyState='success';return [200,[]];}
    return call_user_func($prevMock,$service,$method,$path,$body);
};
$vi=qc_verify_info('cdn.example.com');
qc_test($vi['zone']==='example.com'&&$vi['state']==='doing'&&$vi['host']==='verification'&&$vi['value']==='verify_abc'&&strpos($verifyCalls[0][1],'/domain/example.com/verify/info?product=cdn')===0,'verification asked for the main domain, not the subdomain');
qc_test(qc_verify_info('www.example.com.cn')['zone']==='example.com.cn','com.cn zone detected');
qc_test(qc_verify_product('dynamic')==='dcdn'&&qc_verify_product('web')==='cdn'&&qc_verify_product('vod')==='cdn','platform product mapping');
qc_test(qc_verify_info('cdn.example.com','dynamic')['product']==='dcdn','verify info dcdn product');
qc_test($payload['body']['source']['skipCheckSource']===true,'skipCheckSource enabled');
qc_test($payload['body']['source']['sourceHost']==='cdn.example.com','sourceHost defaults to domain when omitted');
$verifyFail=true;
try{qc_verify_check('cdn.example.com');qc_test(false,'failed check must throw');}catch(RuntimeException $e){qc_test(strpos($e->getMessage(),'TXT')!==false&&strpos($e->getMessage(),'verification')!==false,'friendly message on 400932');}
$verifyFail=false;$verifyCalls=[];
qc_test(qc_verify_check('cdn.example.com')['state']==='success','check succeeds and returns the new state');
qc_test($verifyCalls[0][0]==='GET'&&$verifyCalls[1][0]==='POST'&&$verifyCalls[1][2]===['type'=>'dns','product'=>'cdn'],'info viewed before check (Qiniu requires it)');
$verifyCalls=[];qc_verify_check('cdn.example.com');qc_test(count(array_filter($verifyCalls,function($c){return $c[0]==='POST';}))===0,'already verified: no extra check call');
$verifyCalls=[];qc_verify_check('cdn.example.com','cdn',true);qc_test(count(array_filter($verifyCalls,function($c){return $c[0]==='POST';}))===1,'force check: sends POST even if already verified');
$GLOBALS['qc_mock']=$prevMock;
// DNS 托管服务商探测与自动加 TXT 解析测试
$GLOBALS['qc_dns_provider_mock']=function($zone){
    if($zone==='derczn.com')return ['provider'=>'bizcn','label'=>'商务中国','zone'=>'derczn.com'];
    if($zone==='ousweixin.com')return ['provider'=>'dnspod','label'=>'DNSPod','zone'=>'ousweixin.com'];
    if($zone==='cfdomain.com')return ['provider'=>'cloudflare','label'=>'Cloudflare','zone'=>'cfdomain.com','id'=>'cf123'];
    return null;
};
qc_test(qc_find_dns_provider('derczn.com')['provider']==='bizcn','detect bizcn provider');
qc_test(qc_find_dns_provider('ousweixin.com')['provider']==='dnspod','detect dnspod provider');
qc_test(qc_find_dns_provider('cfdomain.com')['provider']==='cloudflare','detect cloudflare provider');
qc_test(qc_find_dns_provider('unknown.com')===null,'unknown domain returns null');
$autoTxtLog=[];
$GLOBALS['qc_auto_txt_mock']=function($providerInfo,$host,$value)use(&$autoTxtLog){
    $autoTxtLog[]=[$providerInfo['provider'],$providerInfo['zone'],$host,$value];
    return true;
};
$bizcnP=qc_find_dns_provider('derczn.com');
qc_test(qc_auto_add_txt_record($bizcnP,'verification','val123')===true,'auto add txt returns true');
qc_test(count($autoTxtLog)===1&&$autoTxtLog[0]===['bizcn','derczn.com','verification','val123'],'auto add txt payload passed correctly');
$GLOBALS['qc_dns_provider_mock']=null;
$GLOBALS['qc_auto_txt_mock']=null;
qc_test(!function_exists('db')&&!function_exists('pas_get'),'offline test never loads credentials or database');
echo json_encode(['passed'=>$checks,'failed'=>0,'real_api_writes'=>0],JSON_UNESCAPED_UNICODE)."\n";
