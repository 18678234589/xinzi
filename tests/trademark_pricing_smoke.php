<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; };
$actor = ['type'=>'system','id'=>0,'role'=>'finance'];
$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE project_cost_templates SET is_active=0 WHERE business_scope='商标'");
    $golden = ['注册'=>270,'转让'=>450,'续展'=>450,'补证'=>450,'变更'=>0,'超期续展'=>675,'许可备案'=>135,'注销'=>0,'撤回'=>0,'更正'=>0];
    $templates = [];
    foreach ($golden + ['注册多选项目加收'=>27,'成品商标成本'=>1,'国际商标成本'=>1,'法务外包成本'=>1] as $name=>$price) {
        $unit = isset($golden[$name]) ? '件' : ($name==='注册多选项目加收' ? '个' : '元');
        $fullName = in_array($unit,['件','个'],true) ? '商标'.$name : $name;
        $pdo->prepare("INSERT INTO project_cost_templates (category,business_scope,name,specification,unit,price_mode,cost_kind,price,requires_proof,auto_approve,version) VALUES ('other','商标',?,'测试标准',?,'fixed','one_time',?,0,?,1)")->execute([$fullName,$unit,$price,$unit==='元'?0:1]);
    }
    $templates = ptc_templates();
    foreach ($golden as $name=>$price) {
        $plan = ptc_cost_plan($templates,['trademark_service'=>$name,'trademark_count'=>'2']);
        $check($plan['status']==='ready' && (float)$plan['total']===$price*2.0, "$name 两件成本 ".($price*2));
    }
    foreach (['宽展期续展','逾期续展','超 期 续 展','续展（超期）','续展，已过期','超期的商标续展','延期续展'] as $alias) $check(ptc_keyword(ptc_detect_service($templates,$alias))==='超期续展','超期续展别名 '.$alias);
    $check(ptc_keyword(ptc_detect_service($templates,'未超期续展'))==='续展','未超期续展不误用宽展价');
    foreach (['商标许可','使用许可备案','许可使用','许可合同备案'] as $alias) $check(ptc_keyword(ptc_detect_service($templates,$alias))==='许可备案','许可备案别名 '.$alias);
    $check(ptc_keyword(ptc_detect_service($templates,'注册号17112551 许可备案'))==='许可备案','注册号不被识别成注册业务');
    $check(ptc_detect_service($templates,'网报 图形9类')===null,'缺少办理事项不默认注册');
    $check(ptc_detect_service($templates,'续展和转让')===null,'混合办理事项不挑最长名称计整单');
    foreach (['续展1转让2'=>[3,1350],'变更5续展1'=>[6,450],'注册2变更6'=>[8,540],'注册1变更21'=>[22,270],'注册2转让7'=>[9,3690],'注册2超期续展3'=>[5,2565],'续展1超期续展2'=>[3,1800]] as $mix=>$expected) {
        $p=ptc_cost_plan($templates,['trademark_service'=>$mix,'trademark_count'=>(string)$expected[0]]);
        $check($p['status']==='ready'&&$p['total']===(float)$expected[1],'混合事项逐项计价 '.$mix.' = '.$expected[1]);
    }
    $check(ptc_cost_plan($templates,['trademark_service'=>'注册2变更6','trademark_count'=>'2'])['status']==='unresolved','混合事项数量不一致不能落账');
    $check(ptc_cost_plan($templates,['trademark_service'=>'注册1.5转让2.5','trademark_count'=>'3'])['status']==='unresolved','混合事项不截断小数件数');
    $check(ptc_cost_plan($templates,['trademark_service'=>'续展','trademark_count'=>'2'],'已过期续展')['total']===1350.0,'续展事项另有过期备注时不能漏收宽展费');
    $check(ptc_cost_plan($templates,['trademark_service'=>'注册','trademark_count'=>'1'],'许可备案')['status']==='unresolved','事项与备注矛盾不静默按低价注册');
    $check(ptc_cost_plan($templates,['trademark_service'=>'注册','trademark_count'=>'1.5'])['status']==='unresolved','拒绝小数件数');
    $check(ptc_cost_plan($templates,['trademark_service'=>'注册','trademark_count'=>'2'],'多选项目')['status']==='unresolved','缺少多选项目数不漏算附加费');
    $extra = ptc_cost_plan($templates,['trademark_service'=>'注册','trademark_count'=>'2','trademark_extra_count'=>'3']);
    $check($extra['total']===621.0,'两件注册加整单三个多选项目共621，不重复乘件数');
    foreach (['成品商标成本','国际商标成本','法务外包成本'] as $name) {
        $check(ptc_cost_plan($templates,['trademark_service'=>$name,'trademark_count'=>'2'])['status']==='unresolved',"$name 未询价不套注册价");
        $plan=ptc_cost_plan($templates,['trademark_service'=>$name], '',876);
        $check($plan['total']===876.0 && $plan['lines'][0]['status']==='pending',"$name 以实际成本876待审");
    }
    $duplicate=$templates; $duplicate[]=$templates[0];
    $check(ptc_cost_plan($duplicate,['trademark_service'=>'注册','trademark_count'=>'2'])['status']==='unresolved','同名启用成本模板冲突须核对');
    $makeOrder=function($service,$count) use($pdo,$templates,$actor) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status) VALUES (?,'测试','商标','普通订单','测试',1600,1600,CURDATE(),'finished')")->execute(['TM-PRICE-'.bin2hex(random_bytes(5))]);
        $id=(int)$pdo->lastInsertId();
        ps_save_business_details($id,'商标',['service_type'=>'网报','trademark_name'=>'17112658 郧山红30类','trademark_count'=>(string)$count]);
        ps_source_record($id,'manual','测试','',$service);
        ps_intake_add_template_cost($id,ptc_find_service($templates,'注册'),$actor,'成本修复：注册 × '.$count.' 件',$count,'approved');
        return $id;
    };
    foreach (['超期续展'=>[2,1350,675],'许可备案'=>[1,135,135],'变更'=>[1,0,0],'注销'=>[1,0,0],'撤回'=>[1,0,0],'更正'=>[1,0,0]] as $name=>$expected) {
        [$count,$amount,$price]=$expected;
        $id=$makeOrder($name,$count);
        $failed=false;try{ptc_approval_guard($id,ps_costs($id));}catch(RuntimeException $e){$failed=true;}
        $check($failed, "$name 的旧错误注册成本不能生成分成");
        $check(ptc_sync_order_cost($id,$actor,'成本修复')==='corrected', "$name 纠正已自动通过的旧成本");
        $costs=array_values(array_filter(ps_costs($id),function($c){return $c['review_status']!=='rejected';}));
        $check(count($costs)===1 && (float)$costs[0]['amount']==$amount && (float)$costs[0]['unit_price']==$price && (float)$costs[0]['quantity']==$count && (int)$costs[0]['template_id']===(int)ptc_find_service($templates,$name)['id'], "$name 金额、单价、数量与成本模板一致");
        ptc_approval_guard($id,$costs);
        $check(ptc_sync_order_cost($id,$actor,'重复上传')==='ok', "$name 重复同步不重复或回退成本");
        $pdo->prepare("UPDATE project_orders SET settlement_status='approved' WHERE id=?")->execute([$id]);
        $check(ptc_sync_order_cost($id,$actor,'成本修复')==='skip', "$name 已审核结算不改写");
    }
    $id=$makeOrder('网报',2);
    $check(ptc_sync_order_cost($id,$actor,'成本修复')==='unresolved','历史事项不明的错误成本不会继续默认注册');
    $id=$makeOrder('续展',2);
    $pdo->prepare("UPDATE project_costs SET reason='财务人工录入' WHERE order_id=?")->execute([$id]);
    $check(ptc_sync_order_cost($id,$actor,'成本修复')==='manual','人工成本保留，由财务核对');
    $id=$makeOrder('注册',2);
    $check(ptc_sync_order_cost($id,$actor,'Excel 导入',700)==='corrected','明确的较高Excel成本保留差额');
    $costs=ps_costs($id); $sum=0;$pending=0;
    foreach($costs as $c)if($c['review_status']!=='rejected'){$sum+=(float)$c['amount'];if($c['review_status']==='pending')$pending+=(float)$c['amount'];}
    $check($sum===700.0 && $pending===160.0,'标准540加实际差额160待审，不损坏标准单价');
    $check(ptc_sync_order_cost($id,$actor,'补充上传')==='ok','补充上传保留实际成本差额，且不重复加收');
    echo "PASS trademark pricing and reconciliation regression\n";
} finally { if($pdo->inTransaction())$pdo->rollBack(); }
