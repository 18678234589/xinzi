<?php
require_once __DIR__.'/../includes/BizcnRegistrationStore.php';
$checks=0;
function bt($value,$message){global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
function reject($fn){try{$fn();return false;}catch(RuntimeException $e){return true;}catch(InvalidArgumentException $e){return true;}}
function fixture_db($path=':memory:'){
    $p=new PDO('sqlite:'.$path);$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->exec('PRAGMA busy_timeout=15000');return $p;
}
function fixture_schema($p){
    $p->exec("CREATE TABLE bizcn_registration_settings (provider_account TEXT PRIMARY KEY,daily_limit INTEGER DEFAULT 12,revision INTEGER DEFAULT 1,templates_json TEXT,ns_json TEXT,updated_by INTEGER);
    CREATE TABLE bizcn_registration_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,provider_account TEXT,token_hash TEXT UNIQUE,actor_key TEXT,domainname TEXT,quote_json TEXT,expires_at INTEGER,status TEXT DEFAULT 'quoted',domain_lock TEXT UNIQUE,usage_day TEXT,confirmed_day TEXT,provider_started_at INTEGER,order_id TEXT DEFAULT '',result_json TEXT,resolution_note TEXT DEFAULT '');
    CREATE TABLE bizcn_registration_templates(provider_account TEXT,template_id TEXT,label TEXT,owner_label TEXT,refreshed_at INTEGER,PRIMARY KEY(provider_account,template_id));");
}
function fq($domain,$rev=1){return ['domain'=>$domain,'price_cents'=>5800,'premium'=>false,'term'=>1,'revision'=>$rev,'template'=>['id'=>'fixture','label'=>'测试','owner'=>'测试主体'],'ns'=>['ns1.example.com','ns2.example.com']];}
if(defined('BR_FIXTURES_ONLY'))return;
$actor=['type'=>'employee','id'=>1];$admin=['type'=>'admin','id'=>1];$now=strtotime('2026-10-12 10:00:00 UTC');
if(($argv[1] ?? '')==='worker'){
    $path=$argv[2];if(!preg_match('/_test\.sqlite$/D',$path) || realpath(dirname($path))!==realpath(__DIR__.'/../.deploy'))throw new RuntimeException('Unsafe test database path');
    $p=fixture_db($path);$i=(int)$argv[3];$d='worker'.$i.'.example.com';$q=br_quote_save($p,'fixture',$actor,fq($d),$now);
    try{br_reserve($p,'fixture',$actor,$q['token'],$d,'1',$now);echo 'reserved';}catch(RuntimeException $e){echo 'quota';}exit;
}
$p=fixture_db();fixture_schema($p);$s=br_settings($p,'fixture');bt($s['daily_limit']===12,'default cap12');
bt(br_day(strtotime('2026-10-12 16:01 UTC'))==='2026-10-13','Beijing day');
bt(!br_admin(['type'=>'employee','role'=>'management']),'supervisor cannot set cap');
bt(reject(function()use($p,$actor){br_save_settings($p,'fixture',$actor,100,[],['a','b'],1);}), 'employee cannot change cap');
br_save_settings($p,'fixture',$admin,12,[],['ns1.example.com','ns2.example.com'],1);
bt(br_settings($p,'fixture')['revision']===2,'config revision');
bt(reject(function()use($p,$admin){br_save_settings($p,'fixture',$admin,12,[],['a','b'],1);}), 'stale config rejected');
foreach(['-1','12.1','1001','x'] as $limit)bt(reject(function()use($p,$admin,$limit){br_save_settings($p,'fixture',$admin,$limit,[],['a','b'],2);}), 'invalid cap');
$q=br_quote_save($p,'fixture',$actor,fq('one.example.com',2),$now);
bt(reject(function()use($p,$actor,$q,$now){br_reserve($p,'fixture',$actor,$q['token'],'one.example.com','0',$now);}), 'no ack rejected');
bt(reject(function()use($p,$q,$now){br_reserve($p,'fixture',['type'=>'employee','id'=>2],$q['token'],'one.example.com','1',$now);}), 'other actor rejected');
bt(reject(function()use($p,$actor,$q,$now){br_reserve($p,'fixture',$actor,$q['token'],'wrong.example.com','1',$now);}), 'wrong confirmation rejected');
$r=br_reserve($p,'fixture',$actor,$q['token'],'one.example.com','1',$now);
bt(br_reserve($p,'fixture',$actor,$q['token'],'one.example.com','1',$now)['id']===$r['id'],'same token idempotent');
bt(br_mark_started($p,$r['id'],$now),'first worker may purchase');bt(!br_mark_started($p,$r['id'],$now),'second worker never purchases');
bt(br_usage($p,'fixture','2026-10-13')===1,'unknown carries quota across midnight');
$other=br_quote_save($p,'fixture',$actor,fq('one.example.com',2),$now);
bt(reject(function()use($p,$actor,$other,$now){br_reserve($p,'fixture',$actor,$other['token'],'one.example.com','1',$now);}), 'second token same domain rejected');
bt(br_record_result($p,$r['id'],['status'=>'1','orderid'=>'o1','domainname'=>'one.example.com'],$now)==='processing','processing not success');
bt(br_record_result($p,$r['id'],['status'=>'200','totalprice'=>'59.00'],$now)==='unknown','billed price mismatch');
bt(br_record_result($p,$r['id'],['status'=>'200','totalprice'=>'58.00'],$now+86400)==='registered','confirmed success');
bt(br_usage($p,'fixture','2026-10-13')===1,'later success counted on confirmation day');
bt(br_usage($p,'fixture','2026-10-14')===0,'closed prior days not counted');
$exp=br_quote_save($p,'fixture',$actor,fq('expired.example.com',2),$now);
bt(reject(function()use($p,$actor,$exp,$now){br_reserve($p,'fixture',$actor,$exp['token'],'expired.example.com','1',$now+301);}), 'expired quote rejected');
$pre=fq('premium.example.com',2);$pre['premium']=true;$pre=br_quote_save($p,'fixture',$actor,$pre,$now);
bt(reject(function()use($p,$actor,$pre,$now){br_reserve($p,'fixture',$actor,$pre['token'],'premium.example.com','1',$now);}), 'premium employee blocked');
bt(reject(function()use($p){br_request($p,'fixture',['type'=>'employee','id'=>9],1);}), 'history privacy');
for($i=0;$i<11;$i++){$d='cap'.$i.'.example.com';$v=br_quote_save($p,'fixture',$actor,fq($d,2),$now);br_reserve($p,'fixture',$actor,$v['token'],$d,'1',$now);}
bt(br_usage($p,'fixture','2026-10-12')===12,'exact cap12');
$v=br_quote_save($p,'fixture',$actor,fq('overflow.example.com',2),$now);bt(reject(function()use($p,$actor,$v,$now){br_reserve($p,'fixture',$actor,$v['token'],'overflow.example.com','1',$now);}), '13th blocked');
$r=br_request($p,'fixture',$actor,5); // Determine a reserved row without relying on fixture insert sequence.
$r=$p->query("SELECT * FROM bizcn_registration_requests WHERE status='reserved' LIMIT 1")->fetch(PDO::FETCH_ASSOC);br_mark_started($p,$r['id'],$now);br_record_result($p,$r['id'],['status'=>'3'],$now);
bt(br_usage($p,'fixture','2026-10-12')===11,'explicit failure releases quota');
bt(br_state(['result'=>['code'=>'200']])==='unknown','response200 alone not success');
// Real concurrent processes, an isolated local SQLite file only; no production bootstrap or APIs.
$path=realpath(__DIR__.'/../.deploy').'/bizcn_registration_'.bin2hex(random_bytes(6)).'_test.sqlite';$p2=fixture_db($path);fixture_schema($p2);br_settings($p2,'fixture');$p2=null;
$jobs=[];for($i=0;$i<24;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,'-d','extension=pdo_sqlite',__FILE__,'worker',$path,(string)$i],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$jobs[]=[$proc,$pipes];}
$results=['reserved'=>0,'quota'=>0];foreach($jobs as $j){$out=trim(stream_get_contents($j[1][1]));$err=stream_get_contents($j[1][2]);fclose($j[1][1]);fclose($j[1][2]);$code=proc_close($j[0]);bt($code===0 && isset($results[$out]),'worker failed '.$err);$results[$out]++;}
$p2=fixture_db($path);bt(br_usage($p2,'fixture','2026-10-12')===12 && $results['reserved']===12 && $results['quota']===12,'concurrent exact cap');$p2=null;unlink($path);
echo json_encode(['passed'=>$checks,'failed'=>0,'parallel'=>$results,'paid_api_calls'=>0],JSON_UNESCAPED_UNICODE)."\n";
