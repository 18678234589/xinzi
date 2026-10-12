<?php
/** Read-only comparison of the user's archived sales sheets and currently linked system sales. */
function ps_payroll_import_reconciliation($employeeId, $month, $actor)
{
    if ((int)$employeeId <= 0) return [];
    require_once __DIR__ . '/ProjectIntake.php';
    require_once __DIR__ . '/ProjectOrderSource.php';
    $p=db();$q=$p->prepare("SELECT * FROM project_import_files WHERE employee_id=? AND status='imported' ORDER BY id DESC LIMIT 30");$q->execute([(int)$employeeId]);
    $files=$q->fetchAll();$seen=[];$out=[];$shops=$p->query('SELECT name FROM shops')->fetchAll(PDO::FETCH_COLUMN);
    foreach($files as $meta) {
        $key=$meta['business_name'].':'.$meta['original_name'];if(isset($seen[$key]))continue;$seen[$key]=true;
        try {
            $file=ps_import_file_get((int)$meta['id'],$actor);$sheets=ps_import_file_sheets($file);$groups=[];$sourceRows=0;$sourceAmount=0.0;
            foreach($sheets as $sheet=>$rows) {
                if($meta['sheets_used']!==''&&!in_array((string)$sheet,explode(',',$meta['sheets_used']),true))continue;
                $ed=$p->prepare('SELECT row_no,col_idx,applied_value FROM project_import_edits WHERE file_id=? AND sheet=? AND applied_value IS NOT NULL');$ed->execute([(int)$meta['id'],(string)$sheet]);foreach($ed->fetchAll() as $c)if(isset($rows[(int)$c['row_no']]))$rows[(int)$c['row_no']][(int)$c['col_idx']]=$c['applied_value'];
                $map=null;$offset=0;
                if($rows&&ps_import_row_is_data($rows[0]))$map=ps_import_headerless_map($rows,$shops,[]);
                else foreach(array_slice($rows,0,10,true) as $i=>$head){try{$m=ps_business_import_map($meta['business_name'],$head,false);}catch(RuntimeException $e){continue;}if(isset($m['order_no'])){$map=$m;$offset=$i+1;break;}}
                if(!$map||!isset($map['order_no'],$map['contract_amount'],$map['order_date']))continue;
                foreach(array_slice($rows,$offset) as $row) {
                    $date=ps_import_date($row[$map['order_date']]??'');if(!$date||substr($date,0,7)!==$month)continue;
                    $no=ps_order_no_canonical($row[$map['order_no']]??'');if($no==='')continue;
                    $amount=str_replace([',','¥','￥',' '],'',trim((string)($row[$map['contract_amount']]??'')));
                    if(preg_match('/^\d+(?:\.\d+)?(?:[+＋]\d+(?:\.\d+)?)*$/u',$amount))$amount=array_sum(array_map('floatval',preg_split('/[+＋]/u',$amount)));else continue;
                    $sourceRows++;$sourceAmount+=(float)$amount;$groups[$no]=($groups[$no]??0)+(float)$amount;
                }
            }
            if(!$sourceRows)continue;
            $find=$p->prepare('SELECT DISTINCT o.id,o.contract_amount FROM project_orders o WHERE (o.order_no=? OR o.id IN (SELECT s.child_order_id FROM project_order_splits s JOIN project_orders root ON root.id=s.parent_order_id WHERE root.order_no=?)) AND (EXISTS(SELECT 1 FROM project_participants x WHERE x.order_id=o.id AND x.employee_id=?) OR EXISTS(SELECT 1 FROM project_department_uploaders x WHERE x.order_id=o.id AND x.employee_id=?))');
            $systemOrders=[];$differences=0;
            foreach($groups as $no=>$amount){$resolved=ps_order_no_resolve($no);$find->execute([$resolved,$resolved,(int)$employeeId,(int)$employeeId]);$matches=$find->fetchAll();$total=0.0;foreach($matches as $order){$total+=(float)$order['contract_amount'];$systemOrders[(int)$order['id']]=(float)$order['contract_amount'];}if(!$matches||abs($total-$amount)>0.005)$differences++;}
            $out[]=['file_id'=>(int)$meta['id'],'name'=>$meta['original_name'],'source_rows'=>$sourceRows,'payment_count'=>count($groups),'source_amount'=>$sourceAmount,'system_count'=>count($systemOrders),'system_amount'=>array_sum($systemOrders),'differences'=>$differences];
        } catch(Throwable $e) { $out[]=['file_id'=>(int)$meta['id'],'name'=>$meta['original_name'],'unavailable'=>true]; }
    }
    return $out;
}

function ps_payroll_import_reconciliation_render($rows)
{
    if(!$rows)return;
    ?><div class="card mb-3"><div class="card-header">已上传表格对账</div><div class="card-body"><p class="text-muted small">表格商品行包含 SSL、补款等附加项；付款编号相同的行合并金额，已明确登记的多个网站分别计系统订单。此处逐文件核对，本期全部订单还包括其他表格及手工录入。</p><div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr><th>原表</th><th>本月商品行</th><th>付款编号数</th><th>表格售价合计</th><th>对应系统订单</th><th>系统售价合计</th><th>对账结果</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?php echo e($r['name']);?></td><?php if(!empty($r['unavailable'])):?><td colspan="6">原表暂时无法读取，请联系财务核对</td><?php else:?><td><?php echo (int)$r['source_rows'];?></td><td><?php echo (int)$r['payment_count'];?></td><td>¥<?php echo money($r['source_amount']);?></td><td><?php echo (int)$r['system_count'];?></td><td>¥<?php echo money($r['system_amount']);?></td><td class="<?php echo $r['differences']?'text-warning':'text-success';?>"><?php echo $r['differences']?(int)$r['differences'].' 个付款编号待核对':'金额一致';?></td><?php endif;?></tr><?php endforeach;?></tbody></table></div></div></div><?php
}
