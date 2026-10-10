<?php
require_once __DIR__ . '/ProjectTrademarkCost.php';

function ptt_services()
{
    return ['商标注册','商标转让','商标续展','商标超期续展','商标补证','商标许可备案','商标变更','商标注销','商标撤回','商标更正','成品商标成本','国际商标成本','法务外包成本','混合业务'];
}

/** 复用订单模板的 Excel 导出，计价信息与网报方式分别填写。 */
function ptt_columns()
{
    return [
        ['日期',12,'date',[],true,'本订单接单日期，如2026-10-09'],
        ['店铺/渠道',14,'plain',[],true,'实际成交店铺或渠道，如美丽街、微信'],
        ['订单编号',26,'text',[],true,'本订单唯一编号，按文本填写；不要把两笔付款重复当作整单收入'],
        ['付款昵称',18,'text',[],false,'客户付款账号或旺旺昵称'],
        ['订单销售金额（元）',18,'money',[],true,'本订单总销售额，不是单件售价、定金或本次补款金额；拆单时各订单金额不得重复'],
        ['办理事项（必选）',20,'list',ptt_services(),true,'从下拉选择实际办理事项；超期续展不能选普通续展，许可备案不能选注册。多种事项选混合业务'],
        ['商标名称',22,'text',[],false,'与申请号/注册号至少填写一项；多个商标可换行逐个写'],
        ['申请号/注册号',26,'text',[],false,'与商标名称至少填写一项，多个编号可换行；文本格式保留前导零和长编号'],
        ['尼斯类别（1-45）',18,'text',[],false,'写具体类别，如30、35；该列用于核对，不代替计价件数'],
        ['计价件数（商标×类别）',21,'integer',[1,1000],true,'标准业务必填1–1000整数：1个商标办2类计2件；2个商标各办1类也计2件。成品/国际/法务按实际金额计费时可留空'],
        ['注册额外小项总数',18,'integer',[0,100000],false,'只填注册超出基本项目部分的整单额外小项总数；没有填0或留空，不能填全部项目数，也不再乘计价件数'],
        ['混合业务计价明细',30,'text',[],false,'选混合业务时必填，如注册2+超期续展3，总计价件数须填5；每种事项写整数件数。变量成本混合、跨月合并费用须先由财务逐项核对'],
        ['申报方式',18,'list',['个人网报','公司网报','其他'],false,'个人/公司网报仅是办理方式，不决定官费；加急或其他服务费写入费用说明并核实实际成本'],
        ['实际直接成本（元）',20,'money',[],false,'标准事项留空，由系统按成本中心计算；成品商标、国际商标、法务外包须填写整单已询价实际成本。勿填单件单价或客户售价'],
        ['费用说明',30,'text',[],false,'变量成本或标准价之外的费用必填组成、供应商或依据；补款、跨月费用写明对应订单及以前是否已计成本'],
        ['价格异常说明',30,'text',[],false,'成本高于销售额时必填：先核对整单售价、计价件数和补款归属；确为亏损/活动价时说明原因，不能靠改低成本消除异常'],
        ['交付状态',16,'list',['已完成','未完成'],true,'实际业务交付状态，付款完成不等于业务交付完成'],
        ['客服',12,'text',[],false,'客服姓名；多人参与按系统支持的姓名分隔方式填写'],
        ['资料专员',12,'text',[],false,'实际负责资料的专员姓名'],
        ['提交专员',12,'text',[],false,'实际负责提交的专员姓名，不要把资料、提交两岗位混成一列'],
        ['客户联系方式/备注',26,'text',[],false,'客户电话、微信或需核对的业务备注'],
        ['微信交易流水号',28,'text',[],false,'实际支付流水号，按文本填写；不替代办理事项'],
    ];
}

function ptt_examples()
{
    $base=['日期'=>date('Y-m-d'),'店铺/渠道'=>'示例店铺','付款昵称'=>'示例客户','商标名称'=>'示例商标','申报方式'=>'公司网报','交付状态'=>'已完成'];
    return [
        $base+['订单编号'=>'示例-注册两类','订单销售金额（元）'=>'900','办理事项（必选）'=>'商标注册','尼斯类别（1-45）'=>'30、35','计价件数（商标×类别）'=>'2'],
        $base+['订单编号'=>'示例-两件超期续展','订单销售金额（元）'=>'1600','办理事项（必选）'=>'商标超期续展','计价件数（商标×类别）'=>'2'],
        $base+['订单编号'=>'示例-许可备案','订单销售金额（元）'=>'235','办理事项（必选）'=>'商标许可备案','计价件数（商标×类别）'=>'1'],
        $base+['订单编号'=>'示例-混合业务','订单销售金额（元）'=>'3000','办理事项（必选）'=>'混合业务','计价件数（商标×类别）'=>'5','混合业务计价明细'=>'注册2+超期续展3'],
        $base+['订单编号'=>'示例-成品商标询价','订单销售金额（元）'=>'1500','办理事项（必选）'=>'成品商标成本','实际直接成本（元）'=>'888','费用说明'=>'示例供应商报价888元，待财务核对'],
    ];
}

function ptt_notes()
{
    $notes=[['计价原则','','标准成本由系统按当前成本中心计算，销售金额与实际直接成本分别填写；空白不是已核实的0元成本。'],['一单一行','','同一订单编号填写一行；混合业务在该行写逐项计价明细，不要重复填多行而重复记收入或成本。'],['混合业务','','同一订单多种事项选混合业务并填写逐项件数，总计价件数须等于明细合计；不能整单套同一种单价。'],['续展区分','','普通续展与超期续展是两种事项，办理前先核对实际续展状态。'],['售价核对','','标准成本高于销售额时先核对总销售额、补款归属及件数；确为亏损订单填写价格异常说明。'],['特殊费用','','成品、国际、法务按真实询价填写金额与费用说明，待财务审核；跨月费用先确认以前订单是否已计成本，避免重复计入。']];
    $templates=ptc_templates();
    foreach(['注册','转让','续展','超期续展','补证','许可备案','变更','注销','撤回','更正']as$name){
        $template=ptc_find_service($templates,$name);
        $notes[]=['成本中心：'.$name,'', $template ? '下载时标准：¥'.money_plain($template['price']).'/件；导入时仍以当前成本中心为准。' : '成本中心缺少唯一启用模板，请先由财务核对。'];
    }
    return $notes;
}

function ptt_is_professional_header(array $head)
{
    foreach($head as$label)if(mb_strpos((string)$label,'计价件数')!==false)return true;
    return false;
}

/** 新模板必须明确填写计价依据；旧模板仍走原兼容逻辑。 */
function ptt_prepare_record(&$record, $choice = '', $rawCost = null)
{
    if(trim((string)($record['contract_amount']??''))==='')throw new RuntimeException('请填写本订单销售金额，不能用空白表示0元或漏记补款');
    if($rawCost!==null&&trim((string)$rawCost)!==''){
        $rawCost=str_replace([',','¥','￥',' '],'',trim((string)$rawCost));
        if(!preg_match('/^\d+(?:\.\d{1,2})?$/',$rawCost))throw new RuntimeException('实际直接成本须为非负金额，最多两位小数；文字、负数或无效金额不能忽略');
    }
    $details=&$record['details'];
    $service=$choice!=='' ? $choice : trim((string)($details['trademark_service']??''));
    if($choice!==''&&in_array('商标'.$service,ptt_services(),true))$service='商标'.$service;
    if($service==='')throw new RuntimeException('请在“办理事项（必选）”选择真实业务，不能用个人/公司网报代替');
    if(!in_array($service,ptt_services(),true))throw new RuntimeException('请使用办理事项下拉选项；多种事项请选择“混合业务”并填写逐项明细');
    if(trim((string)($details['trademark_name']??''))===''&&trim((string)($details['trademark_number']??''))==='')throw new RuntimeException('商标名称、申请号/注册号至少填写一项，便于核对计价对象');
    $mixed=trim((string)($details['trademark_breakdown']??''));
    if($service==='混合业务'){
        if($mixed==='')throw new RuntimeException('混合业务须逐项填写计价明细，例如注册2+超期续展3');
        $details['trademark_service']=$mixed;
    }else{
        if($mixed!=='')throw new RuntimeException('已填混合业务计价明细，请选择“混合业务”，避免明细被忽略');
        $details['trademark_service']=$service;
    }
    $variable=in_array($service,['成品商标成本','国际商标成本','法务外包成本'],true);
    $count=trim((string)($details['trademark_count']??''));
    if((!$variable||$count!=='')&&(!is_numeric($count)||(float)$count<1||(float)$count>1000||floor((float)$count)!=(float)$count))throw new RuntimeException('计价件数须填1–1000整数，不能空白、默认1件或按小数件计费');
    $classes=trim((string)($details['trademark_classes']??''));
    if($classes!==''){
        $parts=preg_split('/[、,，;；\s]+/u',preg_replace('/第|类/u','',$classes),-1,PREG_SPLIT_NO_EMPTY);
        if(!$parts)throw new RuntimeException('请填写明确的尼斯类别编号（1–45）');
        foreach($parts as$part)if(!ctype_digit($part)||(int)$part<1||(int)$part>45)throw new RuntimeException('尼斯类别须为1–45，多个类别用顿号或逗号分隔；类别不能代替计价件数');
    }
    if($variable&&trim((string)($details['trademark_cost_note']??''))==='')throw new RuntimeException('成品/国际/法务实际成本须填写费用说明及询价依据');
}

function ptt_validate_price($record)
{
    $details=$record['details'];
    $context=implode(' ',[$record['payment_reference']??'',$record['contact_note']??'',$record['business_text']??'']);
    $templates=ptc_templates();$actual=$record['direct_cost']??null;
    $plan=ptc_cost_plan($templates,$details,$context,$actual);
    if($plan['status']!=='ready')throw new RuntimeException($plan['message']);
    $base=ptc_cost_plan($templates,$details,$context,null);
    if($base['status']==='ready'&&$actual!==null&&$actual!==''){
        if((float)$actual+0.004<(float)$base['total'])throw new RuntimeException('填写的整单实际直接成本低于标准成本，请核对计价件数、单件单价与整单总成本；标准事项可留空由系统计算');
        if((float)$actual>(float)$base['total']+0.004&&trim((string)($details['trademark_cost_note']??''))==='')throw new RuntimeException('实际成本高于成本中心标准价，请在费用说明中填写差额组成和依据，由财务审核');
    }
    if((float)$plan['total']>(float)$record['contract_amount']+0.004&&trim((string)($details['trademark_price_note']??''))==='')throw new RuntimeException('成本高于订单销售金额，请先核对整单售价、计价件数及补款归属；确为亏损/活动价订单须填写价格异常说明');
}
