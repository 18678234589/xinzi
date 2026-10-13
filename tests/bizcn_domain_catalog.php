<?php
require_once __DIR__.'/../includes/BizcnDomainValues.php';
require_once __DIR__.'/../includes/BizcnDomainCatalog.php';
$n=0;
function bc_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function bc_reject($fn){try{$fn();return false;}catch(InvalidArgumentException $e){return true;}catch(RuntimeException $e){return true;}}
$head=['check','域名','注册商','注册时间','过期时间','状态','用户名'];
$row=[123,'EXAMPLE.COM','商中','2025-10-12','2026-10-12','转移保护 ','jishu003'];
$plan=bc_normalize_sheets(['Sheet0'=>[$head,$row]]);
bc_check(count($plan['rows'])===1,'one domain');bc_check($plan['rows'][0]['domainname']==='example.com','domain lowercase');
bc_check($plan['rows'][0]['owner_username']==='jishu003','subaccount preserved');bc_check($plan['rows'][0]['provider_domain_id']==='123','check not treated as domain');
bc_check($plan['rows'][0]['status']==='转移保护','status trim');bc_check($plan['rows'][0]['source_row']===2,'source row');
$idn=$row;$idn[1]='xn--b9ww0pz4bdy6d.com(鸿源盛祥.com)';$idnPlan=bc_normalize_sheets(['s'=>[$head,$idn]]);
bc_check($idnPlan['rows'][0]['domainname']==='xn--b9ww0pz4bdy6d.com','IDN canonical domain');bc_check($idnPlan['rows'][0]['display_name']===$idn[1],'IDN display preserved');
$plan=bc_normalize_sheets(['Sheet0'=>[$head,$row,$row]]);bc_check(count($plan['rows'])===1&&$plan['duplicates']===1,'exact duplicate dedup');
$conflict=$row;$conflict[4]='2027-10-12';bc_check(bc_reject(function()use($head,$row,$conflict){bc_normalize_sheets(['s'=>[$head,$row,$conflict]]);}), 'conflicting duplicate rejected');
bc_check(bc_reject(function(){bc_normalize_sheets(['s'=>[['域名'],['example.com']]]);}), 'missing headers');
$bad=$row;$bad[1]='https://example.com';bc_check(bc_reject(function()use($head,$bad){bc_normalize_sheets(['s'=>[$head,$bad]]);}), 'invalid domain');
$bad=$row;$bad[4]='2026-02-30';bc_check(bc_reject(function()use($head,$bad){bc_normalize_sheets(['s'=>[$head,$bad]]);}), 'impossible date');
$bad=$row;$bad[4]='2024-01-01';bc_check(bc_reject(function()use($head,$bad){bc_normalize_sheets(['s'=>[$head,$bad]]);}), 'expiry precedes registration');
bc_check(bc_date('2026/10/12')==='2026-10-12','date normalization');bc_check(bc_date('')===null,'missing dates not invented');
bc_check(bc_reject(function(){bc_date('',false);}), 'snapshot date required');
bc_check(bc_date('45000')==='2023-03-15','1900 date system');bc_check(bc_date('45000',true,true)==='2027-03-16','1904 date system');
bc_check(bc_reject(function(){bc_import('missing','source','2026-10-12',['type'=>'employee','id'=>1]);}), 'ordinary users cannot replace inventory');
if(isset($argv[1])){$p=bc_read_export($argv[1]);bc_check(count($p['rows'])===7114,'real export count');bc_check($p['duplicates']===0,'real export unique');echo json_encode(['source_domains'=>count($p['rows']),'first'=>$p['rows'][0]['domainname'],'last'=>$p['rows'][count($p['rows'])-1]['domainname']])."\n";}
echo json_encode(['passed'=>$n,'failed'=>0])."\n";
