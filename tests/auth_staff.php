<?php
require_once __DIR__.'/../includes/AuthStaff.php';
$n=0;
function staff_check($ok,$label) { global $n; if(!$ok) throw new RuntimeException($label);$n++; }
staff_check(auth_staff_pinyin(' LiXue ')==='lixue','ASCII login');
if(class_exists('Transliterator')) {
 foreach(['李雪'=>'lixue','宁学娜'=>'ningxuena','李岩玉'=>'liyanyu','王鑫'=>'wangxin','张光明'=>'zhangguangming','孙迎迎'=>'sunyingying'] as $name=>$expected) staff_check(auth_staff_pinyin($name)===$expected,'pinyin name');
}
staff_check(auth_staff_unique_username('lixue',82,function($x){return false;})==='lixue','available username');
staff_check(auth_staff_unique_username('lixue',82,function($x){return $x==='lixue';})==='lixue82','collision suffix');
try {auth_staff_unique_username('lixue',82,function($x){return true;});staff_check(false,'must reject occupied suffix');}catch(RuntimeException $e){staff_check(true,'reject occupied suffix');}
foreach(['technical','customer_service','management','governance','vault'] as $role) {
 $u=['role'=>$role,'phone'=>'','password_changed_at'=>null];
 staff_check(auth_staff_destination($u)==='/project/profile.php?first=1','first login phone');
 $u['phone']='13800000000';staff_check(auth_staff_destination($u)==='/project/profile.php?password=1','all initial passwords gated');
 $u['password_changed_at']='2026-10-11 00:00:00';staff_check(!auth_staff_needs_password_change($u),'changed password preserved');
}
staff_check(auth_staff_destination(['role'=>'governance','phone'=>'13800000000','password_changed_at'=>'2026-10-11'])==='/project/governance_ideas.php','governance route');
staff_check(auth_staff_destination(['role'=>'vault','phone'=>'13800000000','password_changed_at'=>'2026-10-11'])==='/project/vault.php','vault route');
staff_check(auth_staff_destination(['role'=>'technical','phone'=>'13800000000','password_changed_at'=>'2026-10-11'])==='/project/index.php','normal route');
echo json_encode(['passed'=>$n,'failed'=>0])."\n";
