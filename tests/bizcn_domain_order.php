<?php
require_once __DIR__.'/../includes/BizcnDomainCatalog.php';
$catalogDb=new PDO('sqlite::memory:');
$catalogDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$catalogDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function db(){global $catalogDb;return $catalogDb;}
function bd_config(){return ['username'=>'test-account'];}
function order_check($ok,$label){if(!$ok)throw new RuntimeException($label);}
db()->exec('CREATE TABLE bizcn_domain_catalog (provider_account TEXT,domainname TEXT,display_name TEXT,registrar TEXT,owner_username TEXT,regdate TEXT,expireddate TEXT,status TEXT,source_as_of TEXT,import_id INTEGER)');
$insert=db()->prepare('INSERT INTO bizcn_domain_catalog VALUES (?,?,NULL,NULL,NULL,NULL,?,NULL,?,1)');
$today=date('Y-m-d');
$insert->execute(['test-account','expired.example',date('Y-m-d',strtotime('-1 day')),$today]);
$insert->execute(['test-account','unknown.example',null,$today]);
for($i=64;$i>=0;$i--)$insert->execute(['test-account',sprintf('day%02d.example',$i),date('Y-m-d',strtotime('+'.$i.' days')),$today]);
$insert->execute(['other-account','outsider.example',$today,$today]);
$first=bc_list('',1);$second=bc_list('',2);$third=bc_list('',3);
order_check($first['total']===67,'isolate provider account');
order_check(count($first['rows'])===30&&count($second['rows'])===30&&count($third['rows'])===7,'page sizes');
order_check($first['rows'][0]['domainname']==='day00.example','expires today is first');
order_check($first['rows'][29]['domainname']==='day29.example'&&$second['rows'][0]['domainname']==='day30.example','global order across page boundary');
order_check($third['rows'][4]['domainname']==='day64.example'&&$third['rows'][5]['domainname']==='expired.example'&&$third['rows'][6]['domainname']==='unknown.example','expired after upcoming, missing date last');
order_check(bc_list('',999)['page']===3,'last page clamping');
$insert->execute(['test-account','aaa.example',$today,$today]);
order_check(bc_list('',1)['rows'][0]['domainname']==='aaa.example','stable domain tie break');
echo "7 domain expiry order checks passed\n";
