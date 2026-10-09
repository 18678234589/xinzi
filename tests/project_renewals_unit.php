<?php
require_once __DIR__.'/../includes/ProjectRenewalMath.php';
$n=0;
function check($name,$actual,$expected) { global $n; $n++; if ($actual!==$expected) throw new RuntimeException($name.': '.json_encode($actual)); }
function reject($name,$fn) { global $n; $n++; try { $fn(); } catch (RuntimeException $e) { return; } throw new RuntimeException($name.' did not reject'); }
check('ordinary year',pr_default_expiry('2026-09-22'),'2027-09-22');
check('leap clamp',pr_default_expiry('2024-02-29'),'2025-02-28');
check('no date invented',pr_default_expiry(''),null);
check('bad order date',pr_default_expiry('2026-02-30'),null);
check('empty date',pr_date(''),null);
reject('invalid leap',function(){pr_date('2026-02-29');});
reject('year out of range',function(){pr_date('9999-01-01');});
check('ten days',pr_days('2026-10-12','2026-10-02'),10);
check('overdue',pr_days('2026-10-01','2026-10-02'),-1);
check('same day',pr_days('2026-10-02','2026-10-02'),0);
check('cross year',pr_days('2027-01-01','2026-12-29'),3);
$item=['status'=>'active','sms_enabled'=>1,'phone_hash'=>'fixture','resource_name'=>'example.com','expires_on'=>'2026-10-12'];
foreach([10,3,1] as $d) { $item['expires_on']=(new DateTimeImmutable('2026-10-02'))->modify('+'.$d.' days')->format('Y-m-d'); check('node '.$d,pr_reminder_day($item,'2026-10-02'),$d); }
foreach([0,2,9,11,-1] as $d) { $item['expires_on']=(new DateTimeImmutable('2026-10-02'))->modify(($d>=0?'+':'').$d.' days')->format('Y-m-d'); check('not node '.$d,pr_reminder_day($item,'2026-10-02'),null); }
$item['expires_on']='2026-10-05';
foreach(['paused','closed'] as $s){ $item['status']=$s; check('stopped '.$s,pr_reminder_day($item,'2026-10-02'),null); }
$item['status']='active';$item['sms_enabled']=0;check('opt-out',pr_reminder_day($item,'2026-10-02'),null);
$item['sms_enabled']=1;$item['phone_hash']='';check('missing phone',pr_reminder_day($item,'2026-10-02'),null);
check('mainland normalize',pr_phone('+86 138-0000-0000'),'13800000000');
check('empty phone',pr_phone(''),'');
reject('foreign phone not silently sent',function(){pr_phone('+15555555555',true);});
check('foreign number allowed as contact',pr_phone('+15555555555'),'+15555555555');
reject('wrong phone',function(){pr_phone('123456');});
check('finance all',pr_policy_scope('finance','','',[]),'all');
check('aftersales web',pr_policy_scope('customer_service','网站售后部','刘某',[]),'web');
check('refund aftersales web',pr_policy_scope('customer_service','售后退款部','刘某',[]),'web');
check('website CS own',pr_policy_scope('customer_service','网站客服','某人',[]),'own');
check('mini CS own',pr_policy_scope('customer_service','小程序客服','某人',[]),'own');
check('Wangya own',pr_policy_scope('customer_service','标书小程序','王亚',[]),'own');
check('technical linked resources',pr_policy_scope('technical','定制后端','某人',['AI网站定制']),'own');
check('unrelated excluded',pr_policy_scope('customer_service','代写','某人',['代写']),'none');
check('vault excluded',pr_policy_scope('vault','网站客服','某人',[]),'none');
check('governance excluded',pr_policy_scope('governance','网站售后部','某人',[]),'none');
require_once __DIR__.'/../includes/ProjectRenewalSms.php';
require_once __DIR__.'/../includes/ProjectRenewalImport.php';
check('response accepted',pr_sms_response('{"Code":"OK","BizId":"fixture"}',0,200)['state'],'sent');
check('definite rejection',pr_sms_response('{"Code":"isv.INVALID_PARAMETERS"}',0,400)['state'],'failed');
check('timeout uncertain',pr_sms_response('',28,0)['state'],'unknown');
check('proxy response uncertain',pr_sms_response('<html>error</html>',0,502)['state'],'unknown');
check('OK abnormal HTTP uncertain',pr_sms_response('{"Code":"OK"}',0,502)['state'],'unknown');
reject('arbitrary template field',function(){pr_sms_map('{"secret":"password"}');});
reject('list map invalid',function(){pr_sms_map('["resource"]');});
check('one template variable',pr_sms_map('{"deadline":"date"}'),['deadline'=>'date']);
$fields=pr_import_fields(['域名','域名到期日期','客户手机号','小程序认证到期日','小程序名称','客服电话'],['example.com','2027-09-01','13800000000','2027-10-02','示例小程序','13900000000']);
check('domain import', $fields['resources']['domain']['expires_on'],'2027-09-01');
check('mini import', $fields['resources']['miniapp_certification']['expires_on'],'2027-10-02');
check('customer not staff phone',$fields['phone'],'13800000000');
check('bad optional date not blocking',pr_import_fields(['域名到期日'],['不清楚'])['resources'],[]);
check('space not treated as domain expiry',pr_import_fields(['域名 / 空间到期日期'],['2027-09-01'])['resources'],[]);
check('narrative not used as expiry header',pr_import_fields([str_repeat('备注',25).'域名到期'],['2027-09-01'])['resources'],[]);
check('ambiguous contacts not guessed',pr_import_fields(['客户电话'],['13800000000 13900000000'])['phone'],'');
$config=['access_key_id'=>'fixture-ak','secret_cipher'=>pv_encrypt('fixture-secret'),'sign_name'=>'测试','template_code'=>'SMS_fixture','param_map'=>'{"date":"date"}'];
$request=pr_sms_request($config,'13800000000',['resource'=>'域名','date'=>'2026-10-12','days'=>'10'],'fixture-id','2026-10-02T02:00:00Z','fixture-nonce');
check('fixed HTTPS',strpos($request['url'],'https://dysmsapi.aliyuncs.com/?')===0,true);
check('no AK in URL',strpos($request['url'],'fixture-ak')===false,true);
check('no secret in URL',strpos($request['url'],'fixture-secret')===false,true);
check('v3 signed',strpos($request['headers']['Authorization'],'ACS3-HMAC-SHA256 Credential=fixture-ak,')===0,true);
echo "PASS {$n} renewal unit checks\n";
