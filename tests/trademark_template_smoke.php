<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../includes/ProjectIntake.php';
require_once __DIR__.'/../includes/ProjectMiniappTemplate.php';
require_once __DIR__.'/require_isolated_database.php';
require_isolated_test_database(db());
if(DB_HOST!=='127.0.0.1'||(string)DB_PORT!=='13399')throw new RuntimeException('只允许本地隔离库');
$check=function($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";};
$path=tempnam(sys_get_temp_dir(),'tm-template-');
try{
    $xlsx=pmt_xlsx('商标');file_put_contents($path,$xlsx);
    $sheets=ps_xlsx_sheets($path);$headers=pmt_headers('商标');
    $check(isset($sheets['订单'],$sheets['填写说明'])&&$sheets['订单'][0]===$headers,'专业Excel经真实读取器读取，表头与两张工作表完整');
    $map=ps_business_import_map('商标',$headers,false);
    foreach(['contract_amount','direct_cost','frontend','backend','detail:trademark_service','detail:trademark_count','detail:trademark_number','detail:trademark_classes','detail:trademark_breakdown','detail:trademark_cost_note','detail:trademark_price_note']as$key)$check(isset($map[$key]),'专业表头正确对应导入字段 '.$key);
    $check(ptt_is_professional_header($headers)&&!ptt_is_professional_header(['商标名称','个数','类型（网报，加急）']),'专业模板强校验与旧表兼容明确区分');
    $zip=new ZipArchive();$check($zip->open($path)===true,'Excel压缩包有效');
    $sheet=simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));$zip->close();
    $check((string)$sheet->sheetViews->sheetView->pane['xSplit']==='3'&&(string)$sheet->sheetViews->sheetView->pane['ySplit']==='1','表头和前三列冻结，横向查看仍能识别订单');
    $rules=[];foreach($sheet->dataValidations->dataValidation as$v)$rules[(string)$v['sqref']]=$v;
    $letter=function($label)use($headers){return pmt_col_letter(array_search($label,$headers,true));};
    $service=$rules[$letter('办理事项（必选）').'2:'.$letter('办理事项（必选）').'1000'];
    $check(strpos((string)$service->formula1,'商标超期续展')!==false&&strpos((string)$service->formula1,'商标许可备案')!==false,'业务下拉明确区分超期续展和许可备案');
    $quantity=$rules[$letter('计价件数（商标×类别）').'2:'.$letter('计价件数（商标×类别）').'1000'];
    $check((string)$quantity['type']==='whole'&&(string)$quantity->formula1==='1'&&(string)$quantity->formula2==='1000','Excel数量规则仅允许整数计价件数');
    $samples=ptt_examples();
    foreach([540,1350,135,2565,888]as$i=>$expected){
        $row=$samples[$i];$record=['contract_amount'=>$row['订单销售金额（元）'],'direct_cost'=>$row['实际直接成本（元）']??'','details'=>[]];
        foreach($map as$key=>$index)if(strpos($key,'detail:')===0)$record['details'][substr($key,7)]=$row[$headers[$index]]??'';
        ptt_prepare_record($record);ptt_validate_price($record);$plan=ptc_cost_plan(ptc_templates(),$record['details'],'',$record['direct_cost']);
        $check($plan['total']===(float)$expected,'模板示例可核算，成本'.$expected);
    }
    $help=json_encode($sheets['填写说明'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $check(strpos($help,'135.00/件')!==false&&strpos($help,'675.00/件')!==false&&strpos($help,'变量成本')!==false,'填写说明包含成本中心参考价格和特殊费用规则');
    echo "PASS trademark professional XLSX template and round trip\n";
}finally{if(is_file($path))unlink($path);}
