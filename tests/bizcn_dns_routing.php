<?php
require_once __DIR__.'/../includes/BizcnDnsRouting.php';
$calls=[];$audits=[];$bizcnCalls=0;$ns=['sixteen.dnspod.net','carl.dnspod.net'];$assigned=$ns;$provider='dnspod';
$record=['RecordId'=>42,'Type'=>'A','Name'=>'www','Value'=>'192.0.2.1','TTL'=>1800,'Line'=>'电信','Status'=>'ENABLE'];
function bd_require_owned($domain){global $ns;return ['dns_host1'=>$ns[0],'dns_host2'=>$ns[1]];}
function bd_call($service,$method,$args){global $bizcnCalls;$bizcnCalls++;return ['records'=>['record'=>[['host'=>'www','type'=>'A','value'=>'192.0.2.1']]]];}
function bd_rows($rows){return $rows;}
function ps_audit($kind,$id,$action,$actor,$data){global $audits;$audits[]=$action;}
$GLOBALS['dns_mock']=function($p,$method,$path,$body)use(&$calls,&$assigned,&$record){
    $calls[]=[$p,$method,$path,$body];
    if($path==='DescribeDomain')return ['DomainInfo'=>['DomainId'=>8,'DnspodNsList'=>$assigned]];
    if($path==='DescribeRecordList')return ['RecordList'=>[$record,['RecordId'=>43,'Type'=>'NS','Name'=>'@','Value'=>'carl.dnspod.net.','TTL'=>600,'Status'=>'ENABLE']]];
    if($path==='CreateRecord')return ['RecordId'=>99];
    if($p==='dnspod')return [];
    if(strpos($path,'/zones?')===0)return ['success'=>true,'result'=>[['id'=>'zone42','name'=>'example.com','name_servers'=>$assigned]]];
    if(strpos($path,'/dns_records?')!==false)return ['success'=>true,'result'=>[['id'=>'rec42','type'=>'A','name'=>'www.example.com','content'=>'192.0.2.1','ttl'=>1,'proxied'=>true]],'result_info'=>['total_pages'=>1]];
    return ['success'=>true,'result'=>['id'=>'created']];
};
$n=0;
function check_route($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function rejects_route($fn,$label){try{$fn();}catch(RuntimeException $e){check_route(true,$label);return;}throw new RuntimeException($label);}
function writes_route(){global $calls;return count(array_filter($calls,function($c){return in_array($c[2],['CreateRecord','ModifyRecord','DeleteRecord'],true)||in_array($c[1],['PATCH','DELETE'],true)||($c[0]==='cloudflare'&&$c[1]==='POST');}));}
check_route(bdr_provider(['bizcn1.dnspod.net','bizcn2.dnspod.net'])==='bizcn','merchant DNSPod remains Bizcn');
check_route(bdr_provider(['ns1.cnmsn.net','ns2.cnmsn.net'])==='bizcn','merchant NS');
check_route(bdr_provider(['SIXTEEN.DNSPOD.NET.','carl.dnspod.net'])==='dnspod','normalize NS');
check_route(bdr_provider(['ns1.dnsv2.com','ns2.dnsv2.com'])==='dnspod','DNSPod alternate suffix');
check_route(bdr_provider(['sixteen.dnspod.net','amy.ns.cloudflare.com'])==='mixed','mixed NS not routed arbitrarily');
$ctx=bdr_context('example.com');$listing=bdr_records('example.com',$ctx);
check_route($listing['provider']==='dnspod'&&count($listing['rows'])===2&&$bizcnCalls===0,'DNSPod list never reads registrar records');
$row=$listing['rows'][0];
check_route($row['host']==='www'&&$row['ttl']===1800&&$row['line']==='电信','record metadata preserved');
check_route(!$listing['rows'][1]['editable'],'root NS read only');
$post=['provider'=>'dnspod','confirm'=>'example.com','record_id'=>'42','record_revision'=>$row['revision'],'type'=>'CNAME','host'=>'www','value'=>'target.example.net','ttl'=>1800];
$result=bdr_save('record_edit','example.com',$post,[],$ctx);$write=end($calls);
check_route($write[2]==='ModifyRecord'&&$write[3]['RecordId']===42&&$write[3]['RecordLine']==='电信'&&$write[3]['TTL']===1800,'edit exact DNSPod ID preserves line and TTL');
check_route(strpos($result['message'],'DNSPod')!==false&&$audits===['record_edit_requested','record_edit_success'],'provider success and audits');
$before=writes_route();$wrong=$post;$wrong['provider']='bizcn';rejects_route(function()use($wrong,$ctx){bdr_save('record_edit','example.com',$wrong,[],$ctx);},'stale provider rejected');
$record['Value']='192.0.2.2';rejects_route(function()use($post,$ctx){bdr_save('record_delete','example.com',$post,[],$ctx);},'stale record rejected');
check_route(writes_route()===$before,'stale checks make zero writes');$record['Value']='192.0.2.1';
bdr_save('record_delete','example.com',$post,[],$ctx);$write=end($calls);
check_route($write[2]==='DeleteRecord'&&$write[3]['RecordId']===42,'delete exact DNSPod record');
$record['Status']='DISABLE';$disabled=bdr_records('example.com',$ctx)['rows'][0];check_route(!$disabled['editable'],'disabled record stays read only');$record['Status']='ENABLE';
$assigned=['wrong.dnspod.net','other.dnspod.net'];rejects_route(function(){bdr_context('example.com');},'wrong DNSPod account NS rejected');
$ns=['amy.ns.cloudflare.com','bob.ns.cloudflare.com'];$assigned=$ns;$ctx=bdr_context('example.com');$row=bdr_records('example.com',$ctx)['rows'][0];
$post=['provider'=>'cloudflare','confirm'=>'example.com','record_id'=>$row['id'],'record_revision'=>$row['revision'],'type'=>'A','host'=>'www','value'=>'192.0.2.2','ttl'=>1,'proxied'=>'1'];
bdr_save('record_edit','example.com',$post,[],$ctx);$write=end($calls);
check_route($write[1]==='PATCH'&&$write[2]==='/zones/zone42/dns_records/rec42'&&$write[3]['proxied']===true,'Cloudflare exact record and proxy');
$ns=['unknown.example','other.example'];rejects_route(function(){bdr_context('example.com');},'unsupported NS never falls back to Bizcn');
$ns=['ns1.cnmsn.net','ns2.cnmsn.net'];$listing=bdr_records('example.com',bdr_context('example.com'));
check_route($listing['provider']==='bizcn'&&$bizcnCalls===1,'Bizcn NS still uses merchant API');
echo json_encode(['passed'=>$n,'failed'=>0,'real_dns_writes'=>0])."\n";
