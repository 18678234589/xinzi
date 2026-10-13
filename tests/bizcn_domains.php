<?php
require_once __DIR__.'/../includes/BizcnDomainValues.php';
$checks=0;
function bd_test($value,$message) { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function bd_reject($fn) { try {$fn();return false;}catch(Throwable $e){return true;} }
bd_test(bd_domain('EXAMPLE.COM.')==='example.com','domain normalization');
foreach(['https://example.com','example.com/path','-bad.com','bad-.com','localhost','a.com<xml>','127.0.0.1'] as $value) bd_test(bd_reject(function()use($value){bd_domain($value);}), 'invalid domain');
bd_test(bd_xml(['dns_host'=>['ns1.example.com','ns2.example.com']])==='<dns_host>ns1.example.com</dns_host><dns_host>ns2.example.com</dns_host>','repeat SOAP elements');
bd_test(strpos(bd_xml(['value'=>'<x>&']), '&lt;x&gt;&amp;')!==false,'XML escaping');
bd_test(bd_reject(function(){bd_xml(['x><bad'=>'a']);}),'reject parameter injection');
$xml='<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><n:getDomainListResponse xmlns:n="http://ws/"><response><result code="200"><msg>OK</msg></result><domainlist><domain><domainname>a.com</domainname></domain><domain><domainname>b.com</domainname></domain></domainlist></response></n:getDomainListResponse></soap:Body></soap:Envelope>';
$data=bd_parse($xml);
bd_test($data['result']['code']==='200','attribute response code');
bd_test(count(bd_rows($data['domainlist']['domain']))===2,'multiple domains');
bd_test(count(bd_rows(['domainname'=>'one.com']))===1,'single domain');
bd_test(bd_rows([])===[],'empty rows');
$denied=str_replace('code="200"','code="531"',$xml);
foreach(['getDomainList','getDomainCount'] as $method) {
    try { bd_parse($denied,$method); bd_test(false,'authorization must reject'); }
    catch(RuntimeException $e) { bd_test(strpos($e->getMessage(),'列表接口')!==false && strpos($e->getMessage(),'不代表账户内没有域名')!==false,'list authorization explanation'); }
}
try { bd_parse($denied,'domainAuditQuery'); bd_test(false,'authorization must reject'); }
catch(RuntimeException $e) { bd_test(strpos($e->getMessage(),'该操作的账户权限')!==false,'operation authorization explanation'); }
$secretError=str_replace(['code="200"','<msg>OK</msg>'],['code="574"','<msg>fixture-secret is unavailable</msg>'],$xml);
try {bd_parse($secretError,'infoDnsRecord',['fixture-secret']);bd_test(false,'error must reject');}
catch(RuntimeException $e) {bd_test(strpos($e->getMessage(),'fixture-secret')===false,'provider error secret redaction');}
bd_test(bd_reject(function(){bd_parse('<!DOCTYPE x [<!ENTITY y SYSTEM "file:///etc/passwd">]><x>&y;</x>');}),'XXE blocked');
bd_test(bd_reject(function(){bd_parse('<broken');}),'broken XML');
bd_test(count(bd_ns("ns1.example.com\nns2.example.com"))===2,'nameservers');
bd_test(bd_reject(function(){bd_ns('ns1.example.com ns1.example.com');}),'duplicate nameservers');
$record=['domain'=>'example.com','type'=>'A','host'=>'www','value'=>'192.0.2.1'];
bd_test(bd_record($record,'add')['resolvevalue']==='192.0.2.1','A record');
bd_test(bd_reject(function()use($record){$record['value']='not-ip';bd_record($record,'add');}),'invalid IP');
bd_test(bd_reject(function()use($record){bd_record($record,'edit');}),'missing old value');
bd_test(bd_reject(function()use($record){$record['host']='www<';bd_record($record,'add');}),'invalid host');
foreach(['网站客服部','小程序部','网站售后部','备案部','服务器维护'] as $department) bd_test(bd_policy('customer_service',$department,[])===true,'business department access');
bd_test(bd_policy('technical','综合',['小程序开发']),'assigned business');
bd_test(bd_policy('governance','其他',[],true),'maintenance access');
bd_test(!bd_policy('technical','设计部',['设计']),'other business denied');
bd_test(!bd_policy('management','代写部',[]),'unrelated supervisor denied');
echo json_encode(['passed'=>$checks,'failed'=>0],JSON_UNESCAPED_UNICODE)."\n";
