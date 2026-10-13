<?php
require_once __DIR__.'/../includes/BizcnDnsRecords.php';
$checks=0;
function replace_test($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function replace_error($fn){try{$fn();return '';}catch(RuntimeException $e){return $e->getMessage();}}
function replace_fixture($options=[]){
 $state=(object)['rows'=>$options['rows']??[['type'=>'A','host'=>'www','value'=>'1.2.3.4','mxlevel'=>'10']],'calls'=>[],'audits'=>[]];
 $call=function($service,$method,$body)use($state,$options){
  $state->calls[]=$method;
  if($method==='infoDnsRecord')return ['records'=>['record'=>$state->rows]];
  if($method==='addDnsRecord'){
   $r=$body['add'];
   if((!empty($options['fail_new'])&&$r['resolvetype']==='CNAME')||(!empty($options['fail_restore'])&&$r['resolvetype']==='A'))throw new RuntimeException('fixture add failed');
   foreach($state->rows as $row)if(strcasecmp($row['host'],$r['resolvehost'])===0&&($row['type']==='CNAME'||$r['resolvetype']==='CNAME'))throw new RuntimeException('A Resolverecord is Exist');
   $state->rows[]=['type'=>$r['resolvetype'],'host'=>$r['resolvehost'],'value'=>$r['resolvevalue'],'mxlevel'=>$r['mxlevel']??'10'];return [];
  }
  if($method==='delDnsRecord'){
   if(!empty($options['fail_delete']))throw new RuntimeException('fixture delete failed');
   $r=$body['del'];$state->rows=array_values(array_filter($state->rows,function($row)use($r){return !($row['type']===$r['resolvetype']&&strcasecmp($row['host'],$r['resolvehost'])===0&&$row['value']===$r['resolveoldvalue']);}));return [];
  }
  throw new RuntimeException('Unexpected API method');
 };
 $audit=function($action,$context)use($state,$options){if(!empty($options['fail_audit']))throw new RuntimeException('fixture audit failed');$state->audits[]=$action;};
 return [$state,['call'=>$call,'audit'=>$audit]];
}
$old=bd_record(['domain'=>'example.com','type'=>'A','host'=>'www','old_value'=>'1.2.3.4'],'delete');
$new=bd_record(['domain'=>'example.com','type'=>'CNAME','host'=>'www','value'=>'TARGET.example.net.'],'add');
replace_test($new['resolvevalue']==='target.example.net','normalize CNAME before writes');
foreach(['1.2.3.4','https://example.net','example.net/path','www.example.com'] as $bad){
 try{bd_record(['domain'=>'example.com','type'=>'CNAME','host'=>'www','value'=>$bad],'add');replace_test(false,'invalid CNAME accepted');}catch(InvalidArgumentException $e){replace_test(true,'reject invalid CNAME before deleting old record');}
}
$actor=['type'=>'employee','id'=>1];
[$s,$io]=replace_fixture();$message=bd_record_replace('example.com',$old,$new,$actor,$io);
replace_test($s->calls===['infoDnsRecord','delDnsRecord','addDnsRecord'],'A to CNAME deletes conflicting old record before adding');
replace_test(count($s->rows)===1&&$s->rows[0]['type']==='CNAME'&&$s->rows[0]['value']==='target.example.net','conversion changes final provider state');
replace_test(strpos($message,'成功')!==false&&end($s->audits)==='record_replace_success','conversion success feedback and audit');
[$s,$io]=replace_fixture(['fail_new'=>true]);$message=replace_error(function()use($old,$new,$actor,$io){bd_record_replace('example.com',$old,$new,$actor,$io);});
replace_test(count($s->rows)===1&&$s->rows[0]['type']==='A'&&$s->rows[0]['value']==='1.2.3.4','failed CNAME restores exact A value');
replace_test(strpos($message,'已自动恢复')!==false&&end($s->audits)==='record_replace_rolled_back','rollback feedback');
[$s,$io]=replace_fixture(['fail_new'=>true,'fail_restore'=>true]);$message=replace_error(function()use($old,$new,$actor,$io){bd_record_replace('example.com',$old,$new,$actor,$io);});
replace_test(strpos($message,'恢复失败')!==false&&strpos($message,'A www 1.2.3.4')!==false,'failed rollback reports exact recovery record');
[$s,$io]=replace_fixture(['fail_delete'=>true]);$message=replace_error(function()use($old,$new,$actor,$io){bd_record_replace('example.com',$old,$new,$actor,$io);});
replace_test($s->calls===['infoDnsRecord','delDnsRecord']&&$s->rows[0]['type']==='A','failed delete never attempts new record');
$multiple=[['type'=>'A','host'=>'www','value'=>'1.2.3.4'],['type'=>'A','host'=>'www','value'=>'5.6.7.8']];
[$s,$io]=replace_fixture(['rows'=>$multiple]);$message=replace_error(function()use($old,$new,$actor,$io){bd_record_replace('example.com',$old,$new,$actor,$io);});
replace_test($s->rows===$multiple&&$s->calls===['infoDnsRecord']&&strpos($message,'其它')!==false,'other same-name records block destructive conversion');
[$s,$io]=replace_fixture(['rows'=>[['type'=>'A','host'=>'www','value'=>'9.9.9.9']]]);$message=replace_error(function()use($old,$new,$actor,$io){bd_record_replace('example.com',$old,$new,$actor,$io);});
replace_test($s->calls===['infoDnsRecord']&&strpos($message,'已变化')!==false,'stale edit rejected without writes');
[$s,$io]=replace_fixture(['fail_audit'=>true]);$message=replace_error(function()use($old,$new,$actor,$io){bd_record_replace('example.com',$old,$new,$actor,$io);});
replace_test($s->calls===['infoDnsRecord']&&$s->rows[0]['type']==='A','required audit fails before mutation');
[$s,$io]=replace_fixture();$renamed=$new;$renamed['resolvehost']='cdn';bd_record_replace('example.com',$old,$renamed,$actor,$io);
replace_test($s->calls===['infoDnsRecord','addDnsRecord','delDnsRecord'],'nonconflicting rename retains add-first behavior');
[$s,$io]=replace_fixture(['rows'=>[['type'=>'CNAME','host'=>'www','value'=>'target.example.net']]]);
$cnameOld=bd_record(['domain'=>'example.com','type'=>'CNAME','host'=>'www','old_value'=>'target.example.net'],'delete');
$aNew=bd_record(['domain'=>'example.com','type'=>'A','host'=>'www','value'=>'2.3.4.5'],'add');bd_record_replace('example.com',$cnameOld,$aNew,$actor,$io);
replace_test($s->calls===['infoDnsRecord','delDnsRecord','addDnsRecord']&&$s->rows[0]['type']==='A','CNAME to A also handles exclusivity');
replace_test(!function_exists('db'),'isolated fake-provider test never loads database');
echo json_encode(['passed'=>$checks,'failed'=>0,'real_dns_writes'=>0],JSON_UNESCAPED_UNICODE)."\n";
