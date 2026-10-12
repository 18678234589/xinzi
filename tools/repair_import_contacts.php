<?php
// CLI only. Default is a read-only plan; --apply fills missing contacts, never replaces a saved contact.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$appRoot = getenv('HEZUOSHANG_PROJECT_ROOT') ?: dirname(__DIR__);
require_once $appRoot . '/includes/ProjectIntake.php';
require_once $appRoot . '/includes/ProjectRenewalImport.php';
require_once $appRoot . '/includes/ProjectSiteProjects.php';
require_once $appRoot . '/includes/ProjectOrderSource.php';
$apply = in_array('--apply', $argv, true); $backupDir = '';
foreach ($argv as $arg) if (strpos($arg, '--backup-dir=') === 0) $backupDir = substr($arg, 13);
if ($apply) {
    $privateRoot = getenv('HEZUOSHANG_REPAIR_BACKUP_ROOT') ?: $appRoot . '-runtime';
    if (!is_dir($privateRoot)) mkdir($privateRoot,0700,true);
    $runtime = realpath($privateRoot);
    if ($runtime===realpath($appRoot) || strpos($runtime,realpath($appRoot).DIRECTORY_SEPARATOR)===0) throw new RuntimeException('备份根目录不能位于网站内');
    if (!$runtime || strpos($backupDir, $runtime . DIRECTORY_SEPARATOR) !== 0 || strpos($backupDir, '..') !== false) throw new RuntimeException('备份必须放在站点之外的私有 runtime 目录');
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true)) throw new RuntimeException('无法创建私有备份目录');
    if (strpos(realpath($backupDir), $runtime . DIRECTORY_SEPARATOR) !== 0) throw new RuntimeException('备份目录越界');
}
$p = db(); $actor = ['type'=>'system','id'=>0,'employee_id'=>null,'role'=>'finance'];
$knownShops = $p->query('SELECT name FROM shops')->fetchAll(PDO::FETCH_COLUMN);
$employees = ps_import_employee_index();
$files = $p->query("SELECT * FROM project_import_files WHERE status='imported' ORDER BY id DESC")->fetchAll();
$find = $p->prepare('SELECT id,order_no,project_type FROM project_orders WHERE order_no=?');
$allowed = $p->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? UNION ALL SELECT 1 FROM project_department_uploaders WHERE order_id=? AND employee_id=? LIMIT 1');
$out = ['mode'=>$apply?'apply':'dry_run','files_scanned'=>0,'orders_planned'=>0,'orders_updated'=>0,'already_complete'=>0,'ambiguous_contacts'=>[],'unmatched_sites'=>[],'skipped_files'=>[],'by_employee'=>[]];
$seenFiles = []; $candidates = [];
foreach ($files as $meta) {
    if (!in_array($meta['business_name'], ['AI网站定制','网站模板','网站续费','网站修改','备案-提成','小程序开发'], true)) continue;
    $fileKey = $meta['uploaded_by_type'] . ':' . $meta['uploaded_by_id'] . ':' . $meta['business_name'] . ':' . $meta['original_name'];
    if (isset($seenFiles[$fileKey])) continue; $seenFiles[$fileKey] = true;
    try {
        $file = ps_import_file_get((int)$meta['id'], $actor); $sheets = ps_import_file_sheets($file); $out['files_scanned']++;
        foreach ($sheets as $sheet=>$rows) {
            if ($meta['sheets_used'] !== '' && !in_array((string)$sheet, explode(',', $meta['sheets_used']), true)) continue;
            // Only committed edits are replayed. Unsaved financial/header corrections are never applied here.
            $ed = $p->prepare('SELECT row_no,col_idx,applied_value FROM project_import_edits WHERE file_id=? AND sheet=? AND applied_value IS NOT NULL');
            $ed->execute([(int)$meta['id'],(string)$sheet]);
            foreach ($ed->fetchAll() as $cell) if (isset($rows[(int)$cell['row_no']])) $rows[(int)$cell['row_no']][(int)$cell['col_idx']]=$cell['applied_value'];
            $head=[]; $map=null; $offset=0;
            if ($rows && ps_import_row_is_data($rows[0])) {
                $map=ps_import_headerless_map($rows,$knownShops,$employees);
                if ($map) $head=array_fill(0,count($rows[0]),'');
            } else {
                foreach (array_slice($rows,0,10,true) as $index=>$possibleHead) {
                    try { $test=ps_business_import_map($meta['business_name'],$possibleHead,false); } catch (RuntimeException $e) { continue; }
                    if (isset($test['order_no'])) { $head=$possibleHead; $map=$test; $offset=$index+1; break; }
                }
            }
            if (!$map || !isset($map['order_no'])) continue;
            foreach (array_slice($rows,$offset,null,true) as $index=>$row) {
                $fields=pr_import_fields($head,$row,$map);
                if (empty($fields['phone']) && empty($fields['wechat'])) continue;
                $no=ps_order_no_resolve((string)($row[$map['order_no']]??'')); if ($no==='') continue;
                $find->execute([$no]); $order=$find->fetch(); if (!$order) continue;
                $siteKey=trim((string)($row[$map['site_project_key']??-1]??''));
                if ($siteKey==='') $siteKey=trim((string)($row[$map['detail:website_url']??-1]??''));
                $root=psp_order((int)$order['id']);
                if ($root) {
                    if ($siteKey!=='') { $site=psp_lookup($no,$siteKey); if (!$site) { $out['unmatched_sites'][]=['file_id'=>(int)$meta['id'],'line'=>$index+1,'order_id'=>(int)$order['id']]; continue; } $order['id']=(int)$site['order_id']; }
                    else {
                        $members=psp_members((int)$root['root_order_id']);
                        if (count($members)>1) {
                            $amount=str_replace([',','¥','￥',' '],'',(string)($row[$map['contract_amount']??-1]??''));
                            $matches=is_numeric($amount)?array_values(array_filter($members,function($m)use($amount){return abs((float)$m['contract_amount']-(float)$amount)<0.005;})):[];
                            if (count($matches)!==1) { $out['unmatched_sites'][]=['file_id'=>(int)$meta['id'],'line'=>$index+1,'order_id'=>(int)$order['id']]; continue; }
                            $order['id']=(int)$matches[0]['order_id'];
                        }
                    }
                }
                $id=(int)$order['id'];
                if ($meta['employee_id']) { $eid=(int)$meta['employee_id']; $allowed->execute([$id,$eid,$id,$eid]); if (!$allowed->fetchColumn()) continue; }
                if (isset($candidates[$id]) && $candidates[$id]['file_id']!==(int)$meta['id']) continue; // newest relevant upload wins
                if (!isset($candidates[$id])) $candidates[$id]=['file_id'=>(int)$meta['id'],'employee_id'=>(int)$meta['employee_id'],'phones'=>[],'wechats'=>[]];
                if (!empty($fields['phone'])) $candidates[$id]['phones'][$fields['phone']]=true;
                if (!empty($fields['wechat'])) $candidates[$id]['wechats'][$fields['wechat']]=true;
            }
        }
    } catch (Throwable $e) { $out['skipped_files'][]=['file_id'=>(int)$meta['id'],'error_class'=>get_class($e)]; }
}
foreach ($candidates as $id=>$c) {
    if (count($c['phones'])>1 || count($c['wechats'])>1) { $out['ambiguous_contacts'][]=['order_id'=>$id,'file_id'=>$c['file_id']]; continue; }
    $contact=['phone'=>key($c['phones'])?:'','wechat'=>key($c['wechats'])?:'','resources'=>[]];
    $p->beginTransaction();
    try {
        $lock=$p->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');$lock->execute([$id]);$order=$lock->fetch(); if(!$order)throw new RuntimeException('订单已变更');
        $q=$p->prepare('SELECT * FROM project_renewal_items WHERE order_id=? ORDER BY id FOR UPDATE');$q->execute([$id]);$all=$q->fetchAll();
        $items=array_values(array_filter($all,function($r){return $r['status']!=='closed';}));
        $complete=false;foreach($all as $r)if (($r['owner']??'ours')==='customer' || ($r['status']!=='closed' && (!empty($r['phone_hash']) || !empty($r['wechat_hash'])))) $complete=true;
        if ($complete) { $out['already_complete']++; $p->rollBack(); continue; }
        $out['orders_planned']++;$eid=$c['employee_id'];$out['by_employee'][$eid]=($out['by_employee'][$eid]??0)+1;
        if (!$apply) { $p->rollBack(); continue; }
        $backup=$backupDir.'/contact_'.$id.'.json';
        if (!file_exists($backup)) { if (file_put_contents($backup,json_encode(['order'=>$order,'items'=>$all],JSON_UNESCAPED_UNICODE))===false)throw new RuntimeException('备份失败'); chmod($backup,0600); }
        if (!$items) pr_import_apply($id,$contact,$actor);
        else {
            $item=$items[0];$phone=$contact['phone'];$wx=$contact['wechat'];
            $p->prepare('UPDATE project_renewal_items SET phone_cipher=?,phone_hash=?,wechat_cipher=?,wechat_hash=?,revision=revision+1,updated_at=NOW() WHERE id=? AND phone_hash=\'\' AND COALESCE(wechat_hash,\'\')=\'\'')->execute([$phone!==''?pv_encrypt($phone):'', $phone!==''?hash_hmac('sha256',$phone,pv_key()):'', $wx!==''?pv_encrypt($wx):'', $wx!==''?hash_hmac('sha256',mb_strtolower($wx),pv_key()):'',(int)$item['id']]);
            ps_audit('renewal',(int)$item['id'],'repair_import_contact',$actor,['order_id'=>$id,'file_id'=>$c['file_id'],'phone_restored'=>$phone!=='','wechat_restored'=>$wx!=='']);
        }
        $p->commit(); $out['orders_updated']++;
    } catch (Throwable $e) { if($p->inTransaction())$p->rollBack(); throw $e; }
}
echo json_encode($out,JSON_UNESCAPED_UNICODE).PHP_EOL;
