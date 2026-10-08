<?php
require_once __DIR__ . '/../includes/ProjectOrderItems.php';
$n=0;
function check_item($ok,$label) { global $n; if(!$ok) throw new RuntimeException($label); $n++; }
$ts=[
    ['id'=>131,'category'=>'program','name'=>'青站（标准）','specification'=>'1年空间+域名'],
    ['id'=>132,'category'=>'program','name'=>'青站（标准）','specification'=>'1年仅空间'],
    ['id'=>166,'category'=>'certificate','name'=>'域名SSL证书','specification'=>'1年'],
    ['id'=>167,'category'=>'certificate','name'=>'泛域名SSL证书','specification'=>'1年'],
    ['id'=>156,'category'=>'plugin','name'=>'https加密功能/SSL证书软件','specification'=>''],
    ['id'=>1,'category'=>'program','name'=>'JSP展示中级版','specification'=>'1年空间+域名'],
];
check_item(poi_template('青站',$ts)['id']===131,'青站标准别名');
check_item(poi_template('青站',$ts,'空间')['id']===132,'仅空间不能错选含域名');
check_item(poi_template('jsp展示中级版',$ts)['id']===1,'大小写兼容');
check_item(poi_template('ssl证书',$ts)['id']===166,'SSL证书识别');
check_item(poi_template('SSL一年',$ts)['id']===166,'旧表SSL一年识别');
check_item(poi_template('一年ssl',$ts)['id']===166,'旧表一年SSL识别');
check_item(poi_template('HTTPS',$ts)===null,'HTTPS缺少成本不猜证书或软件');
check_item(poi_template('泛域名SSL证书',$ts)['id']===167,'泛域名证书独立');
check_item(poi_template('SSL证书软件',$ts)['id']===156,'软件不能混为证书');
check_item(poi_template('青站商城版',$ts)===null,'不猜未配置版本');
$a=poi_from_row('青站',550,['孙妍要成本','成本'],[146,196],$ts,3);
$b=poi_from_row('SSL证书',200,['孙妍要成本','成本'],[30,30],$ts,5);
$items=array_merge($a,$b);
check_item(count($items)===2,'同号两行保留两件商品');
check_item(array_sum(array_column($items,'sale_amount'))==750,'总价750');
check_item($b[0]['reported_cost']==='30.00','证书成本30，不是售价200');
check_item($a[0]['technical_cost']==='146.00','技术采购列单独保留');
$c=poi_from_row('JSP展示中级版800元+SSL证书200元',1000,[],[],$ts,2);
check_item(count($c)===2 && $c[0]['sale_amount']==='800.00' && $c[1]['sale_amount']==='200.00','单行复合商品拆分');
$d=poi_from_row('JSP展示中级版+SSL证书',1000,[],[],$ts,2);
check_item(count($d)===2 && $d[0]['sale_amount']===null && $d[1]['sale_amount']===null,'未拆价不猜分配');
$e=poi_from_row('JSP展示中级版800元+SSL证书200元',900,[],[],$ts,2);
check_item($e[0]['sale_amount']===null,'明细价与总价冲突不能误分配');
check_item(poi_keyed($items)[1]['item_key']===poi_keyed(array_merge($a,poi_from_row('ssl证书',200,[],[],$ts,99)))[1]['item_key'],'重复上传行号大小写变化仍去重');
check_item(poi_keyed(array_merge($b,$b))[0]['item_key']!==poi_keyed(array_merge($b,$b))[1]['item_key'],'两份相同商品分别保留');
check_item(poi_from_row('',100,[],[],$ts,2)===[],'无商品名不造商品');
echo "PASS $n item parsing checks\n";
