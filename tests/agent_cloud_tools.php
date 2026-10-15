<?php
require_once __DIR__.'/../includes/AgentCloudTools.php';
require_once __DIR__.'/dns_fake_provider.php';
function check_cloud($ok, $label) { if (!$ok) throw new RuntimeException($label); }
function rejected_cloud($run) { try { $run(); return false; } catch (RuntimeException $e) { return true; } }
$clears=0; $audits=0;
function bda_clear_cache() { $GLOBALS['clears']++; }
function ps_audit($type,$id,$action,$actor,$details) { $GLOBALS['audits']++; }
$secret=str_repeat('test-secret-',6); $now=time(); $actor=['type'=>'employee','id'=>36];
$directory=sys_get_temp_dir().'/agent-cloud-test-'.bin2hex(random_bytes(6));
$call=function($action,$params)use($actor,$secret,$directory,$now){return ac_dispatch($action,$params,$actor,$secret,$directory,$now);};
$commit=function($preview)use($call){return $call('commit',['ticket'=>$preview['ticket'],'confirm_domain'=>$preview['preview']['domain'],'ack'=>true]);};
try {
    $raw=json_encode(['subject'=>'project:36','action'=>'dns_zones','params'=>[]]);
    check_cloud(ac_authenticate($raw,(string)$now,ac_signature($raw,$now,$secret),$secret,$now)['subject']==='project:36','signed identity');
    check_cloud(rejected_cloud(function()use($raw,$now,$secret){ac_authenticate(str_replace('36','1',$raw),$now,ac_signature($raw,$now,$secret),$secret,$now);}), 'identity tampering rejected');
    check_cloud(rejected_cloud(function()use($raw,$now,$secret){ac_authenticate($raw,$now-31,ac_signature($raw,$now-31,$secret),$secret,$now);}), 'expired request rejected');
    $ticket=ac_ticket('employee:36','dns',['domain'=>'example.com'],$secret,$now);
    foreach ([['employee:1','example.com',true,$now],['employee:36','other.com',true,$now],['employee:36','example.com',false,$now],['employee:36','example.com',true,$now+601]] as $v) {
        check_cloud(rejected_cloud(function()use($ticket,$v,$secret){ac_ticket_read($ticket,$v[0],$v[1],$v[2],$secret,$v[3]);}), 'preview actor/domain/ack/expiry binding');
    }
    echo "auth and preview scope passed\n";

    $state=dns_fake_state(); dns_fake_install($state);
    $zone=$commit($call('dns_preview',['domain'=>'example.com','operation'=>'create_zone']));
    check_cloud($zone['synchronized'] && $zone['zone']['domain']==='example.com','zone creation invalidates site cache');
    $add=$call('dns_preview',['domain'=>'example.com','type'=>'A','name'=>'www','value'=>'1.2.3.4','ttl'=>600]);
    $result=$commit($add); $id=$result['record']['id'];
    check_cloud($result['synchronized'] && $result['record']['value']==='1.2.3.4','DNS provider readback');
    $again=$commit($add);
    check_cloud($again['replayed'] && count($state['dnspod']['records']['example.com'])===1,'ticket executes only once');
    $edit=['domain'=>'example.com','record_id'=>$id,'type'=>'CNAME','name'=>'www','value'=>'cdn.example.net','ttl'=>600,'enabled'=>true];
    $stale=$call('dns_preview',$edit); $state['dnspod']['records']['example.com'][$id]['TTL']=601;
    check_cloud($commit($stale)['ok']===false && $state['dnspod']['records']['example.com'][$id]['Type']==='A','stale preview does not clobber changes');
    $changed=$commit($call('dns_preview',$edit));
    check_cloud($changed['synchronized'] && $call('dns_records',['domain'=>'example.com'])['records'][0]['type']==='CNAME','website reads changed provider record');
    $disabled=$commit($call('dns_preview',['domain'=>'example.com','operation'=>'status','record_id'=>$id,'enabled'=>false]));
    check_cloud($disabled['synchronized'] && !$disabled['record']['enabled'],'DNSPod pause is verified');
    $before=$changed['before'];
    $restored=$commit($call('dns_preview',['domain'=>'example.com','record_id'=>$id]+$before));
    check_cloud($restored['synchronized'] && $restored['record']['type']==='A' && $restored['record']['enabled'] && $GLOBALS['clears']>=5,'rollback snapshot restores A and status, invalidates website cache');
    echo "DNS create/update/status/rollback, site readback and replay passed\n";

    $domains=[]; $posts=0;
    $GLOBALS['qc_mock']=function($service,$method,$path,$body)use(&$domains,&$posts){
        if ($method==='GET' && strpos($path,'/domain?')===0) return [200,['domains'=>array_values($domains)]];
        $name=rawurldecode(substr($path,strlen('/domain/')));
        if ($method==='GET') return isset($domains[$name])?[200,$domains[$name]]:[404,['code'=>404001,'error'=>'not found']];
        if ($method==='POST') {
            $posts++; $domains[$name]=['name'=>$name,'cname'=>'edge.qiniudns.com','operatingState'=>'processing']+$body;
            if ($name==='uncertain.example.com') throw new RuntimeException('添加结果不确定，请先查询，禁止自动重试');
            return [200,['code'=>200]];
        }
        throw new RuntimeException('unexpected fake operation');
    };
    $preview=$call('cdn_preview',['domain'=>'cdn.example.com','source_type'=>'ip','source_value'=>'1.2.3.4','source_host'=>'www.example.com','source_scheme'=>'https','test_path'=>'/health.txt','geo_cover'=>'foreign']);
    check_cloud($posts===0,'CDN preview never creates');
    $created=$commit($preview); $repeat=$commit($preview);
    check_cloud($created['synchronized'] && $created['domain']['cname']==='edge.qiniudns.com' && $created['domain']['source_scheme']==='https' && $posts===1 && $repeat['replayed'],'CDN accepted readback, HTTPS origin and exactly-once ticket');
    check_cloud($call('cdn_domains',[])['domains']['rows'][0]['name']==='cdn.example.com','website CDN list sees created domain');
    check_cloud($created['domain']['operatingState']==='processing','accepted creation is not misreported as active');
    $uncertain=$call('cdn_preview',['domain'=>'uncertain.example.com','source_type'=>'ip','source_value'=>'1.2.3.4','test_path'=>'/','geo_cover'=>'foreign']);
    check_cloud($commit($uncertain)['ok']===false && $commit($uncertain)['replayed'] && $posts===2,'uncertain accepted creation is never posted again');
    check_cloud($call('cdn_detail',['domain'=>'uncertain.example.com'])['domain']['name']==='uncertain.example.com','uncertain creation is reconciled by querying the website data source');
    echo "CDN preview/create and website list synchronization passed\n";

    $lock=fopen($directory.'/resource-'.hash('sha256','locked-domain').'.lock','c');flock($lock,LOCK_EX);
    $ran=false;
    check_cloud(rejected_cloud(function()use($directory,&$ran){ac_once($directory,'another-ticket',function()use(&$ran){$ran=true;return ['ok'=>true];},'locked-domain');}) && !$ran,'concurrent domain writes do not run');
    flock($lock,LOCK_UN);fclose($lock);
    $unknown='uncertain-ticket'; $started=$directory.'/'.hash('sha256',$unknown).'.json';file_put_contents($started,json_encode(['started_at'=>$now]));
    check_cloud(rejected_cloud(function()use($directory,$unknown,&$ran){ac_once($directory,$unknown,function()use(&$ran){$ran=true;return ['ok'=>true];},'uncertain-domain');}) && !$ran,'unfinished operation is not blindly retried');
    echo "resource locking and uncertain-result protection passed\n";
} finally {
    foreach (glob($directory.'/*')?:[] as $file) unlink($file);
    if(is_dir($directory))rmdir($directory);
}
