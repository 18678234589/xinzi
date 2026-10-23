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
// 注册时关联订单：只能是自己参与的订单；注册成功后自动绑定
$p->exec('CREATE TABLE project_orders (id INTEGER PRIMARY KEY, order_no TEXT, customer_name TEXT)');$p->exec('CREATE TABLE project_participants (order_id INTEGER, employee_id INTEGER)');
$p->exec('CREATE TABLE bizcn_order_domains (order_id INTEGER, domainname TEXT, source TEXT DEFAULT "renewal", expires_on TEXT, ns_hosts TEXT DEFAULT "", ns_provider TEXT DEFAULT "", ns_checked_at INTEGER DEFAULT 0, created_by TEXT DEFAULT "", created_at TEXT, PRIMARY KEY(order_id,domainname))');
$p->exec("INSERT INTO project_orders VALUES (1,'ORD-1','客户一'),(2,'ORD-2','客户二')");$p->exec('INSERT INTO project_participants VALUES (1,77)');
$staff=['type'=>'employee','id'=>77,'employee_id'=>77,'role'=>'technical'];
$q=br_dispatch('registration_quote',['domain'=>'ordered.example.com','template_id'=>'f2','order_no'=>'ORD-1'],$staff);bt($q['quote']['order_id']===1&&$q['quote']['order_no']==='ORD-1','quote carries own order');
bt(reject(function()use($staff){br_dispatch('registration_quote',['domain'=>'ordered2.example.com','template_id'=>'f2','order_no'=>'ORD-2'],$staff);}),'cannot link to an order you are not on');
bt(reject(function()use($staff){br_dispatch('registration_quote',['domain'=>'ordered3.example.com','template_id'=>'f2','order_no'=>'NOPE'],$staff);}),'unknown order refused at quote');
try{br_catalog_registered(['status'=>'registered','result_json'=>'{}','confirmed_day'=>'2026-10-14','provider_account'=>'fixture','domainname'=>'ordered.example.com','quote_json'=>json_encode($q['quote'])]);}catch(Throwable $e){}
bt($p->query("SELECT source FROM bizcn_order_domains WHERE order_id=1 AND domainname='ordered.example.com'")->fetchColumn()==='registration','registered domain auto-bound to its order');
// 注册年限：1/2/5/10 年，多年总价=首年价+续费价×(年数-1)，提交按报价年限购买
$mockPrice='58.00';$mockResult=['status'=>'1','orderid'=>'multi-order'];
$q5=br_dispatch('registration_quote',['domain'=>'fiveyears.example.com','template_id'=>'f2','term'=>'5'],$a);
bt($q5['quote']['term']===5&&$q5['quote']['unit_price_cents']===5800&&$q5['quote']['renew_price_cents']===8600&&$q5['quote']['price_cents']===40200,'5-year quote total = 58 + 86 * 4 = 402');
// .cn 域名首年 35，续费 37（接口价 35）；接口价高于规则价时跟随接口价，低于规则价时不低于规则价，保证提交的 price 不低于接口价
$mockPrice='35.00';
$qCn=br_dispatch('registration_quote',['domain'=>'mytest.cn','template_id'=>'f2','term'=>'5'],$a);
bt($qCn['quote']['unit_price_cents']===3500&&$qCn['quote']['renew_price_cents']===3700&&$qCn['quote']['price_cents']===18300,'5-year .cn quote total = 35 + 37 * 4 = 183');
$purchaseBefore=$calls;$mockResult=['status'=>'1','orderid'=>'cn-order'];br_dispatch('registration_submit',['token'=>$qCn['token'],'confirm'=>'mytest.cn','ack'=>'1'],$a);
bt($calls===$purchaseBefore+1&&$purchaseArgs['price']==='183.00'&&$purchaseArgs['term']===5,'.cn purchase sends the quoted total');
$mockPrice='29.00';$qCn2=br_dispatch('registration_quote',['domain'=>'lowapi.cn','template_id'=>'f2','term'=>'1'],$a);bt($qCn2['quote']['price_cents']===3500,'.cn first year never below 35 even if provider price is lower');
$mockPrice='40.00';$qCn3=br_dispatch('registration_quote',['domain'=>'highapi.cn','template_id'=>'f2','term'=>'1'],$a);bt($qCn3['quote']['price_cents']===4000&&$qCn3['quote']['price_cents']>=4000,'.cn first year follows a higher provider price');
// 测试 net 域名首年 93，续费 97
$mockPrice='93.00';
$qNet=br_dispatch('registration_quote',['domain'=>'mytest.net','template_id'=>'f2','term'=>'5'],$a);
bt($qNet['quote']['unit_price_cents']===9300&&$qNet['quote']['renew_price_cents']===9700&&$qNet['quote']['price_cents']===48100,'5-year .net quote total = 93 + 97 * 4 = 481');
$mockPrice='58.00';
bt(reject(function()use($a){br_dispatch('registration_quote',['domain'=>'sixyears.example.com','template_id'=>'f2','term'=>'6'],$a);}),'unsupported term refused');
bt(reject(function()use($a){br_dispatch('registration_quote',['domain'=>'zeroyears.example.com','template_id'=>'f2','term'=>'0'],$a);}),'zero term refused');
$mockPremium='true';bt(reject(function()use($admin){br_dispatch('registration_quote',['domain'=>'vipmulti.example.com','template_id'=>'f2','term'=>'2'],$admin);}),'premium domain limited to 1 year');$mockPremium='false';
$before=$calls;$r=br_dispatch('registration_submit',['token'=>$q5['token'],'confirm'=>'fiveyears.example.com','ack'=>'1','term'=>1],$a);
bt($calls===$before+1&&$purchaseArgs['term']===5&&$purchaseArgs['price']==='402.00','multi-year purchase uses quoted term and total, not posted values');
$mockPrice='60.00';$q2=br_dispatch('registration_quote',['domain'=>'twoyears.example.com','template_id'=>'f2','term'=>'2'],$a);$mockPrice='61.00';
bt(reject(function()use($q2,$a){br_dispatch('registration_submit',['token'=>$q2['token'],'confirm'=>'twoyears.example.com','ack'=>'1'],$a);}),'unit price change blocks multi-year purchase');$mockPrice='58.00';
echo json_encode(['passed'=>$checks,'failed'=>0,'mock_paid_calls'=>$calls,'real_paid_api_calls'=>0],JSON_UNESCAPED_UNICODE)."\n";
