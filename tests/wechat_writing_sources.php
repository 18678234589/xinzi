<?php
if (PHP_SAPI !== 'cli') exit(2);
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';
require_once __DIR__ . '/require_isolated_database.php';
require_once __DIR__ . '/../includes/ProjectMiniappTemplate.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') exit(2);
$sql = file_get_contents(__DIR__ . '/../migrations/20261013_wechat_writing_sources.sql');
foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
require_once __DIR__ . '/clean_leftovers.php'; test_clean_leftovers($pdo, ['orders' => ['SRC%']]); // 上次失败遗留的数据
$stored = []; $tag = 'SRC' . bin2hex(random_bytes(5)); $month = '2026-12';
$orderMonth = date('Y-m');
$check = function ($ok, $text) { if (!$ok) throw new RuntimeException($text); echo "[OK] $text\n"; };
$E = function ($name) use ($pdo) { $q=$pdo->prepare('SELECT id FROM employees WHERE name=?');$q->execute([$name]);return (int)$q->fetchColumn(); };
$actorOf = function ($name) use ($pdo) { $q=$pdo->prepare('SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name=? AND u.is_active=1');$q->execute([$name]);$u=$q->fetch();return ['type'=>'employee','id'=>(int)$u['id'],'employee_id'=>(int)$u['employee_id'],'role'=>$u['role']]; };
$finance = ['type'=>'admin','id'=>(int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(),'employee_id'=>null,'role'=>'finance'];
$run = function ($actor, $post) {
    $_SESSION = ['project_user_id'=>$actor['id'],'project_csrf'=>'test-csrf'] + ($_SESSION ?? []);
    $_SERVER['SCRIPT_NAME']='/project/import.php';$_SERVER['REQUEST_METHOD']='POST';$_POST=$post+['csrf'=>'test-csrf'];$_GET=[];
    ob_start(); include __DIR__.'/../project/import.php'; ob_end_clean();
    return [$_SESSION['project_import_preview'] ?? [],$imported ?? 0,$error ?? ''];
};
$upload = function ($actor, $head, $rows) use ($pdo,&$stored) {
    $fp=fopen('php://temp','r+');fputcsv($fp,$head);foreach($rows as $row)fputcsv($fp,$row);rewind($fp);$csv="\xEF\xBB\xBF".stream_get_contents($fp);fclose($fp);
    $tmp=tempnam(sys_get_temp_dir(),'writing_');file_put_contents($tmp,$csv);
    $name=ps_private_store('imports',$tmp,'source-'.bin2hex(random_bytes(4)).'.csv');@unlink($tmp);$stored[]=$name;
    $pdo->prepare("INSERT INTO project_import_files(business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id) VALUES ('微信代写','来源测试.csv',?,?,'employee',?,?)")->execute([$name,strlen($csv),$actor['id'],$actor['employee_id']]);
    return (int)$pdo->lastInsertId();
};
$import = function ($actor, $fileId) use ($run) {
    [$preview,$n,$error]=$run($actor,['action'=>'repreview','business'=>'微信代写','file_id'=>$fileId,'all_sheets'=>1]);
    [$after,$count,$commitError]=$run($actor,['action'=>'commit','business'=>'微信代写','auto_import'=>1]);
    if ($commitError !== '' && count(array_filter($preview,function($r){return !empty($r['base_valid']);}))) throw new RuntimeException('提交失败：'.$commitError);
    return $preview;
};
$orderByNo = function ($no) use ($pdo) { $q=$pdo->prepare('SELECT * FROM project_orders WHERE order_no=?');$q->execute([$no]);return $q->fetch(); };
$approve = function ($order) use ($pdo,$finance,$month) {
    $pdo->prepare("UPDATE project_costs SET review_status='approved' WHERE order_id=?")->execute([$order['id']]);
    $pdo->prepare("UPDATE project_orders SET receipt_amount=contract_amount,delivery_status='finished' WHERE id=?")->execute([$order['id']]);
    ps_approve_order((int)$order['id'],$finance,$month);
};
try {
    $check(psw_source_from_row(['编辑员','店铺','订单号'],['韩菲菲','淘宝','1'])['source']==='editor','淘宝店铺的编辑行仍是编辑对接');
    $check(psw_source_from_row(['客服姓名','店铺'],['韩菲菲','校知网'])['kind']==='店铺订单','实际淘宝店名识别淘宝自接');
    $check(psw_source_from_row(['客服姓名','店铺'],['韩菲菲','微信'])['kind']==='微信付款','同一客服微信行识别微信自接');
    $check(psw_source_from_row(['接单方式','收款方式','编辑员'],['自接订单','淘宝','韩菲菲'])['source']==='self','显式接单方式优先于旧表编辑列');
    $check(psw_source_from_row(['客服姓名'],['韩菲菲'])['kind']==='','没有渠道证据不能默认发淘宝补助');
    $check(psw_source_from_row(['客服姓名','店铺','提成'],['韩菲菲','淘宝','0'])['kind']==='合并单','合并行不冒领淘宝补助');
    $check(psw_source_from_row(['客服姓名','店铺','售价','提成'],['韩菲菲','微信','100','0'])['kind']==='微信付款','微信无单补不等于合并单，正销售额保留微信自接身份');
    $check((float)ps_rule_for('technical','软文代写','2026-09-05','对接编辑','新订单')['per_order_subsidy']===2.5,'9 月对接编辑采用最新明确的 2.5 元口径');
    $check((float)ps_rule_for('technical','微信代写','2026-09-05','对接编辑','合并单')['per_order_subsidy']===0.0,'编辑合并单不计单量');
    $head=['日期','接单方式','收款方式','店铺','订单编号','付款昵称','售价','实际稿费','状态(填已完成/未完成)','客服','对接编辑'];
    $actors=[];$ownOrders=[];
    foreach (['韩菲菲','张钰琪','孙梦琦'] as $i=>$name) {
        $actor=$actors[$name]=$actorOf($name);
        $no1=$tag.'T'.$i;$no2=$tag.'W'.$i;$no3=$tag.'E'.$i;
        $rows=[
            [$orderMonth.'-05','自接订单','淘宝','校知网',$no1,'客户淘宝'.$i,'15000','6000','已完成',$name,''],
            [$orderMonth.'-06','自接订单','微信','微信',$no2,'客户微信'.$i,'10000','5000','已完成',$name,''],
            [$orderMonth.'-07','编辑对接','淘宝','校知网',$no3,'其他客服客户'.$i,'9999','','已完成','徐春',$name],
        ];
        $file=$upload($actor,$head,$rows);$preview=$import($actor,$file);
        $check(count($preview)===3 && count(array_filter($preview,function($r){return !empty($r['base_valid']);}))===3,$name.' 三种来源全部可导入：'.implode('；',array_column($preview,'error')));
        foreach ([$no1,$no2,$no3] as $no) $check((bool)$orderByNo($no),$name.' 来源订单已落库');
        $check(array_column($preview,'order_kind')===['店铺订单','微信付款','编辑对接'],$name.' 逐行区分来源，不按整表统一分类');
        $again=$import($actor,$file);$q=$pdo->prepare('SELECT COUNT(*) FROM project_orders WHERE order_no IN (?,?,?)');$q->execute([$no1,$no2,$no3]);
        $check((int)$q->fetchColumn()===3,$name.' 整表重传不重复建单');
        foreach ([$no1,$no2,$no3] as $no) $approve($orderByNo($no));
        $q=$pdo->prepare('SELECT o.order_no,s.commission_group,s.subsidy_amount FROM project_orders o JOIN project_commission_snapshots s ON s.order_id=o.id WHERE o.order_no IN (?,?,?) AND s.employee_id=? ORDER BY o.order_no');$q->execute([$no1,$no2,$no3,$actor['employee_id']]);$snap=[];
        foreach($q->fetchAll() as $s)$snap[$s['order_no']]=$s;
        $check($snap[$no1]['commission_group']==='customer_service' && (float)$snap[$no1]['subsidy_amount']===3.0,$name.' 淘宝自接每单补助 3 元');
        $check($snap[$no2]['commission_group']==='customer_service' && (float)$snap[$no2]['subsidy_amount']===0.0,$name.' 微信自接按利润池，无淘宝单量补助');
        $check($snap[$no3]['commission_group']==='technical' && (float)$snap[$no3]['subsidy_amount']===2.5,$name.' 对接只得 2.5 元，不作为自接客服');
        $check((float)$orderByNo($no3)['contract_amount']===0.0 && (float)$orderByNo($no3)['receipt_amount']===0.0,$name.' 对接独立记录不把参考金额记成销售收入');
        $ownOrders[$name]=$orderByNo($no1);
    }
    $root=$ownOrders['韩菲菲'];
    // 参与人新增必须在未审核原单进行；本地测试模拟财务退回草稿后核对对接。
    $pdo->prepare("UPDATE project_orders SET settlement_status='draft' WHERE id=?")->execute([$root['id']]);
    $pdo->prepare('DELETE FROM project_commission_snapshots WHERE order_id=?')->execute([$root['id']]);
    foreach (['张钰琪','孙梦琦'] as $name) {
        $actor=$actors[$name];$file=$upload($actor,$head,[[$orderMonth.'-05','编辑对接','淘宝','校知网',$root['order_no'],'客户淘宝0','300','','已完成','韩菲菲',$name]]);
        $preview=$import($actor,$file);$check(!empty($preview[0]['join_parent']) && !empty($preview[0]['base_valid']),'编辑对接微信代写原单只加入编辑，不再建单：'.($preview[0]['error']??''));
    }
    $root=$orderByNo($root['order_no']);$approve($root);
    $check((float)$root['contract_amount']===15000.0,'编辑表参考金额不同也不能覆盖原销售额');
    $q=$pdo->prepare("SELECT employee_id,subsidy_amount FROM project_commission_snapshots WHERE order_id=? AND commission_group='technical'");$q->execute([$root['id']]);$editorSnaps=$q->fetchAll();
    $check(count($editorSnaps)===2 && array_sum(array_column($editorSnaps,'subsidy_amount'))===5.0,'两名编辑同单各 2.5 元，不能把 2.5 元再均分');
    $pool=[];foreach(ps_monthly_results($month,true) as $r)if($r['rule_name']==='微信代写部门利润池')$pool[(int)$r['employee_id']]=(float)$r['amount'];
    // 部门利润 3 人 ×（淘宝 15000−6000 + 微信 10000−5000）= 42000；2026-09 起扣减 5000（此前 6000）：(42000−5000)×10%=3700，编辑按毛利占比分 68%（各 1/3）
foreach($actors as $name=>$actor)$check(abs(($pool[$actor['employee_id']]??0)-round((42000-5000)*0.10*0.68/3,2))<0.01,$name.' 部门利润只取淘宝+微信自接，编辑金额不重复进入利润池');
    foreach(['姚鹏','李雪'] as $name)$check(abs(($pool[$E($name)]??0)-round((42000-5000)*0.10*0.13,2))<0.01,$name.' 无个人订单仍按原部门利润池固定比例分成（3700 × 13%）');
    $q=$pdo->prepare('SELECT COUNT(*) FROM project_orders o JOIN project_participants p ON p.order_id=o.id WHERE o.order_no LIKE ? AND p.employee_id IN (?,?)');$q->execute([$tag.'%',$E('姚鹏'),$E('李雪')]);$check((int)$q->fetchColumn()===0,'经理和人事没有伪造订单或单量补助');
    $no=$tag.'RETAG';$actor=$actors['韩菲菲'];$file=$upload($actor,$head,[[$orderMonth.'-09','自接订单','淘宝','淘宝',$no,'换类测试','100','','未完成','韩菲菲','']]);$import($actor,$file);$o=$orderByNo($no);
    psw_change_kind($o['id'],'店铺订单','编辑对接',$finance);$p=ps_participants($o['id']);$check(count($p)===1 && $p[0]['commission_group']==='technical','快捷改成编辑对接会同步岗位');
    $check((float)$orderByNo($no)['contract_amount']===0.0,'改成编辑独立记录后参考金额不保留为销售额');
    $blocked=false;try{ps_save_customer_intake($o['id'],['contract_amount'=>'100'],$finance);}catch(RuntimeException $e){$blocked=true;}$check($blocked,'买家资料补填不能把编辑参考金额重新变成营业额');
    $check(ps_sync_project_from_shop_order(1,$no,'淘宝',['__original_price__'=>100],100)===false,'店铺同步不能给编辑记录自动灌入销售收入');
    psw_change_kind($o['id'],'编辑对接','微信付款',$finance);$p=ps_participants($o['id']);$check(count($p)===1 && $p[0]['commission_group']==='customer_service','改回微信自接同步岗位，不留下重复参与');
    $blocked=false;try{psw_change_kind($root['id'],'店铺订单','编辑对接',$finance);}catch(RuntimeException $e){$blocked=true;}$check($blocked,'已审核跨接单身份修改不会偷偷改历史快照');
    $noShopNumbers=[];
    foreach (['淘宝','微信'] as $channel) {
        $file=$upload($actor,$head,[[$orderMonth.'-09','自接订单',$channel,'','','无单号同名客户','100','20','未完成','韩菲菲','']]);
        $preview=$import($actor,$file);$noShopNumbers[]=$preview[0]['order_no'];
        $again=$import($actor,$file);$check($again[0]['order_no']===$preview[0]['order_no'],'无订单号的'.$channel.'自接单重传编号稳定');
    }
    $check($noShopNumbers[0]!==$noShopNumbers[1],'同日同名同金额的淘宝与微信单不会混成同一个内部编号');
    require_once __DIR__.'/../classes/SimpleXLSX.php';$tmp=tempnam(sys_get_temp_dir(),'writing_xlsx');file_put_contents($tmp,pmt_xlsx('微信代写'));$sheets=SimpleXLSX::parseAll($tmp);@unlink($tmp);$rows=reset($sheets);
    $check(count($sheets)===2 && $rows[0]===pmt_headers('微信代写'),'下载标准模板的真实 xlsx 可由上传解析器读取');
    $check(in_array('接单方式',$rows[0],true)&&in_array('收款方式',$rows[0],true),'模板分别填写接单方式与收款渠道');
    echo "Wechat writing source/commission flow passed\n";
} finally { foreach($stored as $name)@unlink(ps_private_dir('imports').'/'.basename($name).'.php'); }
