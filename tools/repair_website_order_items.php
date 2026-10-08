<?php
// 从保留的原始网站模板表补回被同号合并丢失的明细；默认只报告，--apply 才写入。
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
poi_ensure();
$apply = in_array('--apply',$argv,true);
$actor = ['type'=>'system','id'=>0,'role'=>'finance','employee_id'=>null];
$templates=ps_intake_templates(null,'网站模板');
$files=db()->prepare("SELECT id FROM project_import_files WHERE business_name=? AND status='imported' ORDER BY id DESC");
$files->execute(['网站模板']);
$seen=[]; $report=['mode'=>$apply?'apply':'dry_run','files'=>0,'candidates'=>0,'changed'=>0,'unchanged'=>0,'locked'=>0,'conflicts'=>0,'errors'=>[],'orders'=>[]];
foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $fileId) {
    try {
        $sheets=ps_import_file_sheets(ps_import_file_get($fileId,$actor)); $report['files']++;
        foreach ($sheets as $sheet=>$rows) {
            $map=[]; $head=[];
            foreach (array_slice($rows,0,15,true) as $hi=>$hrow) {
                foreach ($hrow as $i=>$h) {
                    $h=preg_replace('/\s+/u','',(string)$h);
                    if (in_array($h,['订单编号','订单号'],true)) $map['order']=$i;
                    if (in_array($h,['程序名称','程序套餐'],true)) $map['program']=$i;
                    if (in_array($h,['售价','成交金额'],true)) $map['sale']=$i;
                }
                if (count($map)===3) { $head=$hrow; break; } $map=[];
            }
            if (!$head) continue;
            $groups=[];
            foreach ($rows as $i=>$row) {
                if ($i<=$hi) continue;
                $no=trim((string)($row[$map['order']]??''));
                $sale=poi_money($row[$map['sale']]??'');
                if ($no==='' || $sale===null) continue;
                $items=poi_from_row($row[$map['program']]??'', $sale, $head, $row, $templates, $i+1);
                if ($items) $groups[$no]=array_merge($groups[$no]??[],$items);
            }
            foreach ($groups as $no=>$items) {
                if (count($items)<2) continue;
                $q=db()->prepare('SELECT id,settlement_status,contract_amount,receipt_amount,refund_amount FROM project_orders WHERE order_no=? AND project_type=?');
                $q->execute([(string)$no,'网站模板']); $order=$q->fetch();
                if (!$order || isset($seen[$order['id']])) continue;
                $sum=array_sum(array_map(function($i){return (float)$i['sale_amount'];},$items));
                $conflict = count(array_filter($items,function($i){return $i['sale_amount']===null;})) || abs($sum-(float)$order['contract_amount'])>.001;
                if ($conflict) { $report['conflicts']++; foreach($items as &$item) { $item['sale_amount']=null; $item['resource_hint']='原表分项金额与整单金额不同，待财务核对'; } unset($item); }
                $seen[$order['id']]=true; $report['candidates']++;
                $metadataOnly=$conflict || $order['settlement_status']!=='draft';
                if ($order['settlement_status']!=='draft') $report['locked']++;
                $before=poi_items($order['id']);
                if ($apply) {
                    db()->beginTransaction();
                    try {
                        poi_save($order['id'],$items,$fileId,$actor,$metadataOnly);
                        $after=poi_items($order['id']);
                        if ($after!==$before) {
                            db()->prepare('UPDATE project_orders SET row_version=row_version+1 WHERE id=?')->execute([$order['id']]);
                            ps_audit('order',$order['id'],'restore_multi_item_import',$actor,['file_id'=>$fileId,'before'=>$before,'after'=>$after,'receipt_unchanged'=>$order['receipt_amount'],'metadata_only'=>$metadataOnly]);
                            $report['changed']++;
                        } else $report['unchanged']++;
                        db()->commit();
                    } catch(Throwable $e) { if(db()->inTransaction()) db()->rollBack(); throw $e; }
                }
                $report['orders'][]=['id'=>(int)$order['id'],'order_no'=>(string)$no,'file_id'=>(int)$fileId,'items'=>array_column($items,'item_name'),'sale'=>$sum,'metadata_only'=>$metadataOnly,'conflict'=>(bool)$conflict];
            }
        }
    } catch(Throwable $e) { $report['errors'][]=['file_id'=>(int)$fileId,'error'=>$e->getMessage()]; }
}
echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
