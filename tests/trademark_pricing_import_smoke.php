<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
setlocale(LC_CTYPE,'C');
require_once __DIR__.'/../includes/ProjectTrademarkCost.php';
require_once __DIR__.'/../includes/ProjectOrderSource.php';
require_once __DIR__.'/require_isolated_database.php';
$pdo=db();require_isolated_test_database($pdo);
if(DB_HOST!=='127.0.0.1'||(string)DB_PORT!=='13399')throw new RuntimeException('只允许本地隔离库');
require __DIR__.'/../migrations/apply_management_accounts.php';
$tag=bin2hex(random_bytes(5));$prefix='TM-IMPORT-'.$tag.'-';$employeeIds=[];$uid=0;$fileId=0;$stored=null;$templateIds=[];$proFileId=0;$proStored=null;
$check=function($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";};
$run=function($post){$_SERVER['SCRIPT_NAME']='/project/import.php';$_SERVER['REQUEST_METHOD']='POST';$_POST=$post+['csrf'=>'tm-pricing'];$_GET=[];$_FILES=[];ob_start();include __DIR__.'/../project/import.php';ob_end_clean();return[$imported??0,$error??''];};
try{
    foreach(['注册'=>270,'转让'=>450,'续展'=>450,'补证'=>450,'变更'=>0,'超期续展'=>675,'许可备案'=>135,'注销'=>0,'撤回'=>0,'更正'=>0,'注册多选项目加收'=>27,'成品商标成本'=>1,'国际商标成本'=>1,'法务外包成本'=>1]as$name=>$price){
        $unit=in_array($name,['成品商标成本','国际商标成本','法务外包成本'],true)?'元':($name==='注册多选项目加收'?'个':'件');
        $full=$unit==='元'?$name:'商标'.$name;
        $q=$pdo->prepare("SELECT id FROM project_cost_templates WHERE business_scope='商标' AND name=? AND is_active=1");$q->execute([$full]);
        if(!$q->fetchColumn()){$pdo->prepare("INSERT INTO project_cost_templates(category,business_scope,name,specification,unit,price_mode,cost_kind,price,requires_proof,auto_approve,version)VALUES('other','商标',?,'导入回归',?,'fixed','one_time',?,0,?,1)")->execute([$full,$unit,$price,$unit==='元'?0:1]);$templateIds[]=(int)$pdo->lastInsertId();}
    }
    foreach(['主管回归'.$tag,'客服回归'.$tag]as$name){$pdo->prepare('INSERT INTO employees(name,department,password)VALUES(?,?,?)')->execute([$name,'商标',md5($tag)]);$employeeIds[]=(int)$pdo->lastInsertId();}
    $pdo->prepare("INSERT INTO project_users(employee_id,username,password_hash,role)VALUES(?,?,?,'management')")->execute([$employeeIds[0],'tm-import-'.$tag,password_hash($tag,PASSWORD_DEFAULT)]);$uid=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_user_businesses(user_id,business_name,is_default)VALUES(?,'商标',1)")->execute([$uid]);ps_account_management_save($uid,'management',['management_scope'=>'assigned']);
    $_SESSION['project_user_id']=$uid;$_SESSION['project_csrf']='tm-pricing';unset($_SESSION['admin_id']);
    // 复现线上许可备案已有错误成本270，补充上传必须降到135并更正成本项目。
    $pdo->prepare("INSERT INTO project_orders(order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date)VALUES(?,'客户','商标','普通订单','测试微信',235,CURDATE())")->execute([$prefix.'2']);$legacy=(int)$pdo->lastInsertId();
    ps_save_business_details($legacy,'商标',['trademark_count'=>'1','service_type'=>'网报']);ps_source_record($legacy,'manual','客户','','许可备案');
    ps_intake_participants($legacy,['technical'=>[],'customer_service'=>[$employeeIds[1]=>['id'=>$employeeIds[1],'role'=>'客服']]],'商标');
    ps_intake_add_template_cost($legacy,ptc_find_service(ptc_templates(),'注册'),['type'=>'system','id'=>0,'role'=>'finance'],'成本修复：注册 × 1 件',1,'approved');
    $head="日期,店铺,付款昵称,付款流水号,订单编号,售价,成本,客服,商标名称,个数,类型（网报，加急）,办理事项,多选项目总数\n";
    $data=[['超期续展',1600,'',2,'',''],['许可备案',235,'',1,'',''],['变更',300,'',1,'',''],['',1000,'',2,'注册',3],['',1500,888,1,'成品商标成本',''],['',700,'',2,'',''],['续展和转让',1000,'',2,'',''],['续展',1000,'','1.5','',''],['注册号17112551 许可使用',235,'',1,'',''],['',1600,'',3,'','']];
    $csv=$head;
    foreach($data as$i=>$row){[$ref,$sale,$cost,$count,$service,$extra]=$row;$webType=$i===8?'商标许可备案':($i===9?'商标续展1商标转让2':'网报');if($i===8)$ref='注册号17112551';$csv.=date('Y-m-d').",测试微信,客户,$ref,".$prefix.($i+1).",$sale,$cost,客服回归$tag,图形9类,$count,$webType,$service,$extra\n";}
    $tmp=tempnam(sys_get_temp_dir(),'tm-pricing-');file_put_contents($tmp,$csv);$stored=ps_private_store('imports',$tmp,'tm_pricing_'.$tag.'.csv');unlink($tmp);
    $pdo->prepare("INSERT INTO project_import_files(business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id)VALUES('商标','商标成本回归.csv',?,?,'employee',?,?)")->execute([$stored,strlen($csv),$uid,$employeeIds[0]]);$fileId=(int)$pdo->lastInsertId();
    [$count,$error]=$run(['action'=>'repreview','business'=>'商标','file_id'=>$fileId,'all_sheets'=>1]);$preview=$_SESSION['project_import_preview']??[];
    $check($error===''&&count($preview)===10,'真实控制器完成十行预览');
    foreach([0,1,2,3,4,8,9]as$i)$check(!empty($preview[$i]['base_valid']),'明确事项可导入第'.($i+2).'行 '.($preview[$i]['error']??''));
    foreach([5,6,7]as$i)$check(empty($preview[$i]['base_valid']),'事项不明、混合事项、小数件数不按默认注册导入第'.($i+2).'行');
    [$count,$error]=$run(['action'=>'commit','business'=>'商标','auto_import'=>1]);$check($error==='','真实控制器提交成功 '.$error);
    $costs=function($i)use($pdo,$prefix){$q=$pdo->prepare("SELECT c.* FROM project_costs c JOIN project_orders o ON o.id=c.order_id WHERE o.order_no=? AND c.review_status<>'rejected' ORDER BY c.id");$q->execute([$prefix.$i]);return$q->fetchAll();};
    foreach([1=>1350,2=>135,3=>0,4=>621,5=>888,9=>135,10=>1350]as$i=>$amount){$rows=$costs($i);$total=array_sum(array_map(function($r){return(float)$r['amount'];},$rows));$check($rows&&$total==(float)$amount,'实际落账第'.$i.'单成本'.$amount);}
    $late=$costs(1)[0];$check((float)$late['unit_price']===675.0&&(float)$late['quantity']===2.0,'超期续展每件675且两件1350');
    $lic=$costs(2)[0];$check(strpos($lic['item_name'],'许可备案')!==false&&(float)$lic['unit_price']===135.0,'已有许可订单补充上传纠正项目、单价和金额');
    $check($costs(5)[0]['review_status']==='pending','成品实际成本进入财务审核');
    $q=$pdo->prepare('SELECT COUNT(*) FROM project_orders WHERE order_no IN (?,?,?)');$q->execute([$prefix.'6',$prefix.'7',$prefix.'8']);$check((int)$q->fetchColumn()===0,'无效行未落入订单');
    [$count,$error]=$run(['action'=>'repair_preview','business'=>'商标','file_id'=>$fileId,'all_sheets'=>1,'fix_tm_service'=>[7=>'注册']]);$preview=$_SESSION['project_import_preview'];
    $fixed=null;foreach($preview as$r)if($r['line']===7)$fixed=$r;
    $check(!empty($fixed['base_valid'])&&$fixed['details']['trademark_service']==='注册','预览直接选办理事项后可提交 '.$error.' '.json_encode($fixed,JSON_UNESCAPED_UNICODE));
    [$count,$error]=$run(['action'=>'commit','business'=>'商标','auto_import'=>1]);$check($error==='','补填事项后提交成功 '.$error);
    $check(array_sum(array_column($costs(6),'amount'))==540,'补填注册后两件540且没有错误猜测');
    [$count,$error]=$run(['action'=>'repreview','business'=>'商标','file_id'=>$fileId,'all_sheets'=>1]);
    [$count,$error]=$run(['action'=>'commit','business'=>'商标','auto_import'=>1]);
    $check($error==='','重新上传整表再次提交成功');
    $check(count($costs(1))===1&&count($costs(2))===1&&count($costs(4))===2,'重复上传不重复加收服务或多选项目费');
    require_once __DIR__.'/../includes/ProjectTrademarkTemplate.php';
    $proHead=ps_business_import_headers('商标');
    $proBase=['日期'=>date('Y-m-d'),'店铺/渠道'=>'测试微信','付款昵称'=>'客户','订单销售金额（元）'=>'2000','办理事项（必选）'=>'商标注册','商标名称'=>'回归标志','尼斯类别（1-45）'=>'30','计价件数（商标×类别）'=>'2','申报方式'=>'公司网报','交付状态'=>'已完成','客服'=>'客服回归'.$tag];
    $proCases=[
        201=>[],202=>['计价件数（商标×类别）'=>''],
        203=>['办理事项（必选）'=>'商标超期续展','订单销售金额（元）'=>'1600'],
        204=>['办理事项（必选）'=>'商标许可备案','计价件数（商标×类别）'=>'1','订单销售金额（元）'=>'235'],
        205=>['办理事项（必选）'=>'混合业务','计价件数（商标×类别）'=>'5','混合业务计价明细'=>'注册2+超期续展3','订单销售金额（元）'=>'3000'],
        206=>['办理事项（必选）'=>'成品商标成本','实际直接成本（元）'=>'888','费用说明'=>'供应商已询价888元'],
        207=>['办理事项（必选）'=>'混合业务','计价件数（商标×类别）'=>'4','混合业务计价明细'=>'注册2+超期续展3','订单销售金额（元）'=>'3000'],
        208=>['办理事项（必选）'=>''],209=>['办理事项（必选）'=>'商标变更'],
        210=>['实际直接成本（元）'=>'270'],211=>['计价件数（商标×类别）'=>'1.5'],
        212=>['订单销售金额（元）'=>'320'],213=>['订单销售金额（元）'=>'320','价格异常说明'=>'核对后确认活动价'],
        214=>['实际直接成本（元）'=>'待询价'],215=>['尼斯类别（1-45）'=>'46'],
        216=>['商标名称'=>'','申请号/注册号'=>'00123456789012345678'],
        217=>['办理事项（必选）'=>'成品商标成本','实际直接成本（元）'=>'888'],
        218=>['实际直接成本（元）'=>'700'],219=>['实际直接成本（元）'=>'700','费用说明'=>'标准540另有服务费160待审核'],
        220=>['计价件数（商标×类别）'=>'1','注册额外小项总数'=>'3'],221=>[],
    ];
    $buffer=fopen('php://temp','r+');fputcsv($buffer,$proHead);
    foreach($proCases as$i=>$changes){$values=array_merge($proBase,$changes,['订单编号'=>$prefix.$i]);$cells=array_map(function($h)use($values){return $values[$h]??'';},$proHead);fputcsv($buffer,$cells);if($i===221)fputcsv($buffer,$cells);}
    rewind($buffer);$proCsv=stream_get_contents($buffer);fclose($buffer);
    $tmp=tempnam(sys_get_temp_dir(),'tm-professional-');file_put_contents($tmp,$proCsv);$proStored=ps_private_store('imports',$tmp,'tm_professional_'.$tag.'.csv');unlink($tmp);
    $pdo->prepare("INSERT INTO project_import_files(business_name,original_name,stored_name,file_size,uploaded_by_type,uploaded_by_id,employee_id)VALUES('商标','商标专业模板回归.csv',?,?,'employee',?,?)")->execute([$proStored,strlen($proCsv),$uid,$employeeIds[0]]);$proFileId=(int)$pdo->lastInsertId();
    [$count,$error]=$run(['action'=>'repreview','business'=>'商标','file_id'=>$proFileId,'all_sheets'=>1]);$preview=$_SESSION['project_import_preview']??[];
    $check($error===''&&count($preview)===21,'专业表头真实预览22行，同号重复归为一笔待处理记录');
    $byNo=[];foreach($preview as$r)$byNo[$r['order_no']]=$r;
    $proExpected=[201=>540,203=>1350,204=>135,205=>2565,206=>888,209=>0,213=>540,216=>540,219=>700,220=>351];
    foreach($proExpected as$i=>$amount)$check(!empty($byNo[$prefix.$i]['base_valid']),'专业模板有效第'.$i.'笔：'.($byNo[$prefix.$i]['error']??''));
    foreach([202,207,208,210,211,212,214,215,217,218,221]as$i)$check(empty($byNo[$prefix.$i]['base_valid']),'专业模板拦截缺件数、错事项、明细冲突、单价误填、亏损未说明或重复单：'.$i);
    [$count,$error]=$run(['action'=>'commit','business'=>'商标','auto_import'=>1]);$check($error==='','专业模板实际提交成功 '.$error);
    foreach($proExpected as$i=>$amount)$check($costs($i)&&array_sum(array_column($costs($i),'amount'))==$amount,'专业模板实际成本'.$i.' = '.$amount);
    foreach([202,207,208,210,211,212,214,215,217,218,221]as$i)$check(!$costs($i),'专业模板错误行未计入成本：'.$i);
    $q=$pdo->prepare('SELECT d.details_json FROM project_order_details d JOIN project_orders o ON o.id=d.order_id WHERE o.order_no=?');$q->execute([$prefix.'216']);$reg=json_decode($q->fetchColumn(),true);$check($reg['trademark_number']==='00123456789012345678','申请号/注册号完整保留前导零与长编号');
    $check(count($costs(219))===2&&$costs(219)[1]['review_status']==='pending','标准价外真实服务费单独等待财务审核');
    [$count,$error]=$run(['action'=>'repreview','business'=>'商标','file_id'=>$proFileId,'all_sheets'=>1]);[$count,$error]=$run(['action'=>'commit','business'=>'商标','auto_import'=>1]);
    $check($error===''&&count($costs(205))===2&&count($costs(219))===2,'专业模板重复上传不重复计费，混合业务明细保留');
    echo "PASS real trademark preview, supplement, commit and retry\n";
}finally{
    $q=$pdo->prepare('SELECT id FROM project_orders WHERE order_no LIKE ?');$q->execute([$prefix.'%']);
    foreach($q->fetchAll(PDO::FETCH_COLUMN)as$oid){foreach(['project_import_result_rows','project_order_items','project_commission_snapshots','project_commission_adjustments','project_cash_movements','project_costs','project_participants','project_order_sources','project_order_resources','project_order_details','project_department_orders','project_department_uploaders','project_auto_reviews','project_order_requests','project_order_credentials','project_refund_import_rows']as$table){try{$pdo->prepare('DELETE FROM '.$table.' WHERE order_id=?')->execute([$oid]);}catch(PDOException$e){if(!in_array($e->getCode(),['42S02','42S22'],true))throw$e;}}$pdo->prepare('DELETE FROM project_orders WHERE id=?')->execute([$oid]);}
    foreach([$fileId,$proFileId]as$cleanupFileId)if($cleanupFileId){try{$pdo->prepare('DELETE FROM project_import_result_rows WHERE file_id=?')->execute([$cleanupFileId]);}catch(PDOException$e){if($e->getCode()!=='42S02')throw$e;}$pdo->prepare('DELETE FROM project_import_files WHERE id=?')->execute([$cleanupFileId]);}
    if($uid){$pdo->prepare('DELETE FROM project_users WHERE id=?')->execute([$uid]);}
    foreach($employeeIds as$id)$pdo->prepare('DELETE FROM employees WHERE id=?')->execute([$id]);
    foreach($templateIds as$id)$pdo->prepare('DELETE FROM project_cost_templates WHERE id=?')->execute([$id]);
    if($stored)ps_private_delete('imports',$stored);
    if($proStored)ps_private_delete('imports',$proStored);
}
