<?php
define('BR_FIXTURES_ONLY',true);require __DIR__.'/bizcn_registration.php';
require __DIR__.'/../includes/BizcnDomainValues.php';
require __DIR__.'/../includes/BizcnRegistration.php';
$p=fixture_db();fixture_schema($p);$calls=0;$mockPrice='58.00';$mockPremium='false';$mockResult=['status'=>'1','orderid'=>'fixture-order'];$mockAuth='pass';$purchaseArgs=[];
function db(){global $p;return $p;}
function bd_config(){return ['username'=>'fixture'];}
function bc_find($domain){return null;}
function ps_audit(){return;}
function bd_call($service,$method,$args){
    global $mockPrice,$mockPremium,$mockAuth,$mockResult;
    if($method==='queryTemplateAuthStatus')return ['auditstatus'=>$mockAuth];
    if($method==='addTemplate')return ['contid'=>'bizcreated001'];
    if($method==='checkPremium')return ['domains'=>['domain'=>['name'=>$args['domainname'],'avail'=>'1','premium'=>$mockPremium,'price'=>$mockPrice]]];
    if($method==='queryDomainOrder')return $mockResult;
    if($method==='getTemplateList')return ['pageno'=>500,'totalresult'=>2,'domaintemplatelist'=>['domaintemplate'=>[['contid'=>'f1','templatename'=>'公司甲','org_m'=>'甲主体'],['contid'=>'f2','templatename'=>'公司乙','org_m'=>'乙主体']]]];
    throw new RuntimeException('Unexpected mock read');
}
function bd_transport($service,$method,$args){global $calls,$mockResult,$purchaseArgs;$calls++;$purchaseArgs=$args;if($mockResult==='timeout')throw new RuntimeException('fixture timeout');return $mockResult;}
$a=['type'=>'employee','id'=>1];$admin=['type'=>'admin','id'=>1];br_settings($p,'fixture');
bt(br_dispatch('registration_config',[],$a)['daily_limit']===12,'default configured limit');
bt(!br_dispatch('registration_config',[],$a)['ready'],'no accidental default owner or NS');
br_dispatch('registration_settings',['daily_limit'=>12,'revision'=>1,'ns'=>'','templates'=>'[]'],$admin);
bt(br_dispatch('registration_config',[],$a)['revision']===2,'admin can set quota before templates or NS');
bt(reject(function()use($a){br_dispatch('registration_settings',['daily_limit'=>1000],$a);}), 'settings denied before network');
br_dispatch('registration_settings',['daily_limit'=>12,'revision'=>2,'ns'=>"ns1.example.com\nns2.example.com",'templates'=>json_encode([['id'=>'fixture','label'=>'公司注册','owner'=>'测试实名主体']])],$admin);
bt(br_dispatch('registration_config',[],$a)['ready'],'approved settings ready');
$q=br_dispatch('registration_quote',['domain'=>'one.example.com','template_id'=>'fixture'],$a);bt($calls===0,'quote never purchases');
bt($q['quote']['price_cents']===5800 && $q['quote']['term']===1,'server quote');
bt(reject(function()use($q,$a){br_dispatch('registration_submit',['token'=>$q['token'],'confirm'=>'one.example.com','ack'=>'0'],$a);}), 'ack required');
$mockPrice='59.00';bt(reject(function()use($q,$a){br_dispatch('registration_submit',['token'=>$q['token'],'confirm'=>'one.example.com','ack'=>'1','price'=>'1'],$a);}), 'changed price blocks purchase');bt($calls===0,'price check before purchase');$mockPrice='58.00';
$input=['token'=>$q['token'],'confirm'=>'one.example.com','ack'=>'1','price'=>'0.01','term'=>10,'template_id'=>'other','ns'=>'bad'];
$r=br_dispatch('registration_submit',$input,$a);bt($r['status']==='processing','processing not success');bt($calls===1,'one purchase mock');
bt($purchaseArgs['price']==='58.00' && $purchaseArgs['term']===1 && $purchaseArgs['contid']==='fixture' && $purchaseArgs['dns_host1']==='ns1.example.com','posted price owner term NS ignored');
br_dispatch('registration_submit',$input,$a);bt($calls===1,'same submitted token no repeat paid call');
$mockResult=['status'=>'3','orderid'=>'fixture-order','domainname'=>'one.example.com'];bt(br_dispatch('registration_status',['id'=>$r['id']],$a)['status']==='failed','explicit failure reconciliation');bt(br_usage($p,'fixture',br_day(time()))===0,'failure releases quota');
$q=br_dispatch('registration_quote',['domain'=>'timeout.example.com','template_id'=>'fixture'],$a);$mockResult='timeout';
$input=['token'=>$q['token'],'confirm'=>'timeout.example.com','ack'=>'1'];$r=br_dispatch('registration_submit',$input,$a);bt($r['status']==='unknown','timeout saved unknown');br_dispatch('registration_submit',$input,$a);bt($calls===2,'timeout retry does not purchase');
bt(br_usage($p,'fixture',br_day(time()))===1,'unknown retains quota');bt(count(br_dispatch('registration_history',[],$a)['rows'])===2,'history includes failed and unknown');
bt(count(br_dispatch('registration_history',[],['type'=>'employee','id'=>2])['rows'])===0,'other actor history hidden');
$mockPremium='true';bt(reject(function()use($a){br_dispatch('registration_quote',['domain'=>'premium.example.com','template_id'=>'fixture'],$a);}), 'premium excluded for employee');$mockPremium='false';$mockAuth='unpass';
bt(reject(function()use($a){br_dispatch('registration_quote',['domain'=>'auth.example.com','template_id'=>'fixture'],$a);}), 'revoked template blocks');bt($calls===2,'no additional writes from failures');
bt(reject(function()use($admin){br_dispatch('registration_templates',['search'=>'公司'],$admin);}), 'no bogus provider filter before cache');
bt(reject(function()use($a){br_dispatch('registration_sync_templates',['page'=>1],$a);}), 'staff cannot cache all templates');
$sync=br_dispatch('registration_sync_templates',['page'=>1],$admin);bt($sync['received']===2&&$sync['done'],'minimal template cache synced');
$filter=br_dispatch('registration_templates',['search'=>'甲主体'],$admin);bt($filter['total']===1&&$filter['rows'][0]['id']==='f1','local real-name search');
$filter=br_dispatch('registration_templates',['contid'=>'f2'],$admin);bt($filter['total']===1&&$filter['rows'][0]['id']==='f2','local exact id search');
$filter=br_dispatch('registration_templates',['search'=>'%'],$admin);bt($filter['total']===0,'search wildcard escaped');
$found=br_dispatch('registration_find_template',['search'=>'乙主体'],$a);bt(count($found['rows'])===1&&$found['rows'][0]['id']==='f2','staff can find a directory subject');
bt(reject(function()use($a){br_dispatch('registration_find_template',['search'=>'乙'],$a);}), 'one-character search refused');
$found=br_dispatch('registration_find_template',['search'=>'%%'],$a);bt(count($found['rows'])===0,'staff search wildcard escaped');
$mockAuth='pass';$before=$calls;
$q=br_dispatch('registration_quote',['domain'=>'newcustomer.example.com','template_id'=>'f2'],$a);bt($q['quote']['template']['owner']==='乙主体'&&$calls===$before,'directory subject can be quoted without admin approval, no purchase');
bt(reject(function()use($a){br_dispatch('registration_quote',['domain'=>'ghost.example.com','template_id'=>'not-in-directory'],$a);}), 'unknown subject refused');
$mockAuth='unpass';bt(reject(function()use($a){br_dispatch('registration_quote',['domain'=>'pending.example.com','template_id'=>'f1'],$a);}), 'unaudited directory subject blocked live');$mockAuth='pass';
$cfg=br_dispatch('registration_config',[],$a);bt($cfg['directory_count']===2&&$cfg['ready'],'directory alone makes registration ready');
$pc=br_dispatch('registration_precheck',['domain'=>'wx.example.com','owner'=>'乙主体'],$a);
bt($pc['domain']['state']==='available'&&$pc['domain']['price_cents']===5800&&$pc['templates'][0]['id']==='f2'&&$pc['templates'][0]['status']==='pass','precheck shows price and ready subject');
$before=$calls;$mockAuth='cnnicauditing';$pc=br_dispatch('registration_precheck',['domain'=>'','owner'=>'乙主体'],$a);bt($pc['templates'][0]['status']==='cnnicauditing'&&$pc['domain']===null,'precheck shows auditing subject without domain');$mockAuth='pass';
$pc=br_dispatch('registration_precheck',['domain'=>'new.example.com','owner'=>'没有模板的公司'],$a);bt($pc['templates']===[],'precheck: unknown subject has no template');
$pc=br_dispatch('registration_precheck',['domain'=>'https://bad','owner'=>''],$a);bt($pc['domain']['state']==='invalid','precheck: invalid domain reported not thrown');
$mockPremium='true';$pc=br_dispatch('registration_precheck',['domain'=>'vip.example.com','owner'=>''],$a);bt($pc['domain']['premium_blocked']===true,'precheck: premium blocked for staff');$pc=br_dispatch('registration_precheck',['domain'=>'vip.example.com','owner'=>''],$admin);bt($pc['domain']['premium_blocked']===false,'precheck: premium allowed for admin');$mockPremium='false';
bt($calls===$before,'precheck never buys');
// 实名与注册一起提交：审核中的模板可报价并注册，未通过的不行
$mockAuth='cnnicauditing';$before=$calls;
$q=br_dispatch('registration_quote',['domain'=>'together.example.com','template_id'=>'f2'],$a);bt($q['quote']['template_pending']===true&&$calls===$before,'auditing template can be quoted, flagged pending, no purchase');
$mockResult=['status'=>'1','orderid'=>'pending-order'];$r=br_dispatch('registration_submit',['token'=>$q['token'],'confirm'=>'together.example.com','ack'=>'1'],$a);bt($r['status']==='processing'&&$calls===$before+1,'pending-audit template registration reaches provider once');
$q=br_dispatch('registration_quote',['domain'=>'together2.example.com','template_id'=>'f2'],$a);$mockAuth='unpass';
bt(reject(function()use($q,$a){br_dispatch('registration_submit',['token'=>$q['token'],'confirm'=>'together2.example.com','ack'=>'1'],$a);}),'template rejected between quote and submit blocks purchase');bt($calls===$before+1,'no purchase after rejection');
$mockAuth='pass';$q=br_dispatch('registration_quote',['domain'=>'together3.example.com','template_id'=>'f2'],$a);bt($q['quote']['template_pending']===false,'passed template not flagged');
// 新建实名后立即可选
define('BT_TEST_UPLOADS',true);$img=sys_get_temp_dir().'/flow_cert.jpg';$im=imagecreatetruecolor(1600,1000);mt_srand(5);for($i=0;$i<4000;$i++)imagefilledrectangle($im,mt_rand(0,1600),mt_rand(0,1000),mt_rand(0,1600),mt_rand(0,1000),imagecolorallocate($im,mt_rand(0,255),mt_rand(0,255),mt_rand(0,255)));imagejpeg($im,$img,95);
$fields=['kind'=>'company','cert_type'=>'YYZZ','template_name'=>'新客户公司','owner_name'=>'新客户有限公司','cert_no'=>'91350100M000100Y43','surname'=>'张','given_name'=>'三','province'=>'山东省','city'=>'济南市','address'=>'历下区经十路1号','postcode'=>'250000','phone'=>'13812345678','email'=>'a@b.com','org_e'=>'New Co','surname_e'=>'Zhang','given_name_e'=>'San','province_e'=>'Shandong','city_e'=>'Jinan','address_e'=>'No.1 Road'];
$cr=br_dispatch('registration_template_create',['fields'=>json_encode($fields,JSON_UNESCAPED_UNICODE),'ack'=>'1','_files'=>['images'=>['tmp_name'=>[$img],'error'=>[0]]]],$a);
bt($cr['contid']==='bizcreated001','template created via dispatch');
$found=br_dispatch('registration_find_template',['search'=>'新客户有限公司'],$a);bt(count($found['rows'])===1&&$found['rows'][0]['id']==='bizcreated001','new template cached and findable immediately');
unlink($img);
echo json_encode(['passed'=>$checks,'failed'=>0,'mock_paid_calls'=>$calls,'real_paid_api_calls'=>0],JSON_UNESCAPED_UNICODE)."\n";
