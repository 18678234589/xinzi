<?php
require_once __DIR__.'/../includes/ProjectBatchFill.php';
$actor=ps_require_actor();pr_require($actor);
$error='';$results=[];$month=trim((string)($_GET['month']??''));$keyword=trim((string)($_GET['q']??''));$orderId=(int)($_GET['order_id']??0);
$identity=$actor['type'].':'.$actor['id'].':'.(int)($actor['employee_id']??0);
if(isset($_GET['download'])){
    try{
        $orders=pbf_missing_orders($actor,$month,$keyword,$orderId);$data=[array_values(pbf_columns())];
        foreach(array_slice($orders,0,PBF_MAX_ROWS) as $o){$data[]=[(string)$o['id'],(string)$o['order_no'],$o['project_type'],$o['customer_name'],implode(' / ',$o['missing']),'','','','','','','','','',''];}
        header('Cache-Control: no-store');
        if($_GET['download']==='csv'){
            header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="order-fill-template.csv"');echo "\xEF\xBB\xBF";
            $h=fopen('php://output','w');foreach($data as $row){foreach($row as &$v)if(preg_match('/^[=+@\-]/u',$v))$v="'".$v;unset($v);fputcsv($h,$row);}fclose($h);
        }else{header('Content-Type: application/json; charset=UTF-8');echo json_encode(['rows'=>$data,'count'=>count($data)-1],JSON_UNESCAPED_UNICODE);}
    }catch(Throwable $e){http_response_code(400);header('Content-Type: application/json; charset=UTF-8');echo json_encode(['error'=>$e instanceof RuntimeException?$e->getMessage():'模板暂时无法读取'],JSON_UNESCAPED_UNICODE);}
    exit;
}
$stored=$_SESSION['project_batch_fill']??null;
if($stored && ($stored['identity']!==$identity || $stored['expires']<time())){unset($_SESSION['project_batch_fill']);$stored=null;}
if($_SERVER['REQUEST_METHOD']==='POST'){
    ps_check_csrf();
    try{
        if(($_POST['action']??'')==='preview'){
            $file=$_FILES['file']??[];$ext=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));
            if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']??'') || ($file['size']??0)>20*1024*1024 || !in_array($ext,['xlsx','xls','csv'],true))throw new RuntimeException('请选择不超过 20 MB 的 XLSX、XLS 或 CSV 文件');
            $parseFile=$file;
            if($ext==='xls'){
                $parseFile=$_FILES['parsed_file']??[];
                if(($parseFile['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($parseFile['tmp_name']??'') || strtolower(pathinfo($parseFile['name']??'',PATHINFO_EXTENSION))!=='xlsx' || ($parseFile['size']??0)>25*1024*1024)throw new RuntimeException('旧版 XLS 正在转换，请刷新后再上传；也可另存为 XLSX');
            }
            $rows=pbf_parse_sheets(ps_import_file_parse(['path'=>$parseFile['tmp_name'],'stored_name'=>$ext==='xls'?'converted.xlsx':$file['name']]));
            $plans=pbf_preview($rows,$actor);$token=bin2hex(random_bytes(24));$name=ps_import_original_name($file['name']);
            $privateName=ps_private_store('batch_fill',$file['tmp_name'],$token.'.'.$ext);
            ps_audit('order_fill',0,'preview',$actor,['batch'=>$token,'original_name'=>$name,'stored_name'=>$privateName,'rows'=>count($rows),'sha256'=>hash_file('sha256',$file['tmp_name'])]);
            $stored=['identity'=>$identity,'expires'=>time()+7200,'token'=>$token,'name'=>$name,'rows'=>$rows,'plans'=>$plans];
            $_SESSION['project_batch_fill']=$stored;
        }elseif(($_POST['action']??'')==='commit'){
            if(!$stored || !hash_equals($stored['token'],(string)($_POST['token']??'')))throw new RuntimeException('这次核对已提交或已过期，请重新上传；不会重复写入');
            $selected=array_unique(array_map('intval',(array)($_POST['selected']??[])));
            if(!$selected)throw new RuntimeException('请勾选至少一行可补全资料');
            foreach($selected as $index){
                if(!isset($stored['rows'][$index]) || empty($stored['plans'][$index]['changes']))continue;
                try{
                    // Commit rechecks access + current values; only fields explicitly shown in this preview are eligible.
                    $keys=array_column($stored['plans'][$index]['changes'],'key');
                    $results[]=pbf_commit_row($stored['rows'][$index],$actor,$stored['token'],$keys);
                }catch(Throwable $e){$p=$stored['plans'][$index];$p['applied']=[];$p['issues'][]=$e instanceof RuntimeException?$e->getMessage():'保存失败，本行已回滚，请重试';$results[]=$p;}
            }
            unset($_SESSION['project_batch_fill']);$stored=null;
        }elseif(($_POST['action']??'')==='discard'){unset($_SESSION['project_batch_fill']);$stored=null;}
    }catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'暂时无法核对，请稍后重试';}
}
try{$orders=pbf_missing_orders($actor,$month,$keyword,$orderId);}catch(Throwable $e){$orders=[];$error=$error?:($e instanceof RuntimeException?$e->getMessage():'待补订单暂时无法读取');}
$page_title='批量补全订单资料';include __DIR__.'/../includes/header.php';
$download=BASE_URL.'/project/batch_fill.php?'.http_build_query(['download'=>'json','month'=>$month,'q'=>$keyword,'order_id'=>$orderId?:null]);
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/batch_fill.css?v=20261008.1">
<main class="pbf-page">
 <section class="pbf-hero"><div><span class="pbf-eyebrow">TOGETHER / 把资料接力好</span><h1>一次补齐，轻松交接</h1><p>客服先录单，技术接着补。共同参与的订单直接更新，同事打开同一单就能看到，不必重复录入。</p><div class="pbf-tags"><span>只补空白</span><span>重复上传自动跳过</span><span>金额与分成不改动</span></div></div><div class="pbf-hero-links"><a class="pbf-button pbf-secondary" href="<?php echo BASE_URL; ?>/project/index.php">项目订单</a><a class="pbf-button pbf-secondary" href="<?php echo BASE_URL; ?>/project/renewal_gaps.php">在线逐单补全</a></div></section>
 <?php if($error): ?><div class="alert alert-warning" role="alert"><?php echo e($error); ?></div><?php endif; ?>
 <?php if($results):$filled=count(array_filter($results,function($r){return !empty($r['applied']);})); ?>
 <section class="pbf-panel pbf-success" role="status"><h2>已补全 <?php echo $filled; ?> 张订单的资料</h2><p>未新建订单，未覆盖已有信息。相同信息自动跳过，仍有差异的请使用原有更正入口。</p><a href="<?php echo BASE_URL; ?>/project/index.php">查看项目订单 →</a></section>
 <?php endif; ?>
 <div class="pbf-grid">
 <section class="pbf-panel"><span class="pbf-step">01</span><h2>下载已经写好订单号的模板</h2><p>只列出你有权限维护的缺项订单。参考列不用修改，填写后面的空白资料即可。</p>
  <form method="get" class="pbf-filters"><label>订单月份<input type="month" name="month" value="<?php echo e($month); ?>"></label><label>订单号 / 客户<input name="q" maxlength="100" value="<?php echo e($keyword); ?>" placeholder="全部缺项或输入关键词"></label><?php if($orderId): ?><input type="hidden" name="order_id" value="<?php echo $orderId; ?>"><?php endif; ?><button class="pbf-button pbf-secondary">筛选</button></form>
  <div class="pbf-download"><button type="button" class="pbf-button" id="pbfDownload" data-url="<?php echo e($download); ?>">下载 Excel 补全模板</button><a href="<?php echo e(str_replace('download=json','download=csv',$download)); ?>" class="pbf-text-link">备用 CSV</a><span id="pbfDownloadStatus" role="status"><?php echo count($orders)>=PBF_MAX_ROWS?'本次最多 500 张，可按月份分批':'当前 '.count($orders).' 张待补'; ?></span></div>
  <details class="pbf-help"><summary>模板可以填写什么？</summary><p>客户手机号 / 海外客户微信号（二选一）、域名与归属、域名 / 服务器 / 微信认证 / 备案到期日、小程序名称。预计到期日可补为实际日期；已经核实的日期不会覆盖。售价、收款、成本、参与人、分成请继续使用原有更正入口。</p></details>
 </section>
 <section class="pbf-panel"><span class="pbf-step">02</span><h2>拖进来，先核对再保存</h2><p>也兼容原来表格里的同名列。有差异的字段单独保留，不影响其他空白资料补全。</p>
  <form method="post" enctype="multipart/form-data" id="pbfUpload" data-legacy-xls-upload><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="preview"><input type="file" name="parsed_file" hidden>
   <label class="pbf-drop" id="pbfDrop"><i class="fas fa-cloud-upload-alt" aria-hidden="true"></i><strong>拖拽表格，或点击选择</strong><span id="pbfFileName">支持 XLSX / XLS / CSV · 20 MB 以内</span><input type="file" name="file" accept=".xlsx,.xls,.csv" required></label>
   <button type="submit" class="pbf-button">上传并核对</button><small class="pbf-hint" data-xls-status>此处不会新建订单；找不到或未关联你的订单会保留提示。</small>
  </form>
 </section></div>
 <?php $plans=$results?:($stored['plans']??[]);if($plans): ?>
 <section class="pbf-panel"><span class="pbf-step">03</span><h2><?php echo $results?'补全结果':'核对预览'; ?></h2><p><?php echo $stored?e($stored['name']).' · ':''; ?>绿色字段可补入；相同内容跳过，差异内容不覆盖。提交时还会重新核对同事的最新修改。</p>
 <?php if(!$results): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="commit"><input type="hidden" name="token" value="<?php echo e($stored['token']); ?>"><?php endif; ?>
 <div class="pbf-table-wrap"><table class="pbf-table"><thead><tr><th><?php if(!$results): ?><input type="checkbox" id="pbfSelectAll" checked aria-label="全选可补全行"><?php else: ?>结果<?php endif; ?></th><th>订单 / 来源</th><th>本次补入</th><th>保持原样</th><th>需要你核对</th></tr></thead><tbody>
 <?php foreach($plans as $i=>$p): ?><tr><td><?php if(!$results): ?><input type="checkbox" name="selected[]" value="<?php echo $i; ?>" <?php echo $p['changes']?'checked':'disabled'; ?> aria-label="选择第 <?php echo $i+1; ?> 行"><?php else: ?><span class="pbf-pill"><?php echo !empty($p['applied'])?'已补全':'未改动'; ?></span><?php endif; ?></td>
  <td><strong><?php echo e($p['order_no']?:'订单号待核对'); ?></strong><small><?php echo e(($p['business']?:'').' · '.$p['source']); ?></small><?php if($p['order_id']): ?><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$p['order_id']; ?>">打开原订单 →</a><?php endif; ?></td>
  <td><?php foreach($p['changes'] as $c): ?><span class="pbf-change"><?php echo e($c['label']); ?>：<?php echo e($c['key']==='owner'?'客户自有':$c['value']); ?></span><?php endforeach; ?><?php if(!$p['changes']): ?><small>没有空白项需要写入</small><?php endif; ?></td>
  <td><small><?php echo e($p['same']?implode('、',$p['same']).'（相同，已跳过）':'其余已填写资料保持不变'); ?></small></td>
  <td><?php foreach($p['issues'] as $issue): ?><span class="pbf-issue"><?php echo e($issue); ?></span><?php endforeach; ?><?php if(!$p['issues']): ?><span class="pbf-ok">核对通过</span><?php endif; ?></td></tr><?php endforeach; ?>
 </tbody></table></div>
 <?php if(!$results): ?><div class="pbf-confirm"><button class="pbf-button">确认补全勾选订单</button><small>只填空白，不覆盖、不新建、不重复记账。</small></div></form><form method="post" class="pbf-discard"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="discard"><button class="pbf-text-link">放弃本次核对，重新上传</button></form><?php endif; ?>
 <?php $issues=array_filter($plans,function($p){return !empty($p['issues']);});if($issues): ?><details class="pbf-help" open><summary>需核对的差异单独保留 · <?php echo count($issues); ?> 行</summary><p>系统没有覆盖这些内容。可以调整表格重新上传，或打开原订单使用已有“更正订单 / 更新续费资料”入口处理。</p></details><?php endif; ?>
 </section><?php endif; ?>
</main>
<script src="<?php echo BASE_URL; ?>/assets/lib/xlsx.full.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/project-xls-upload.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/batch_fill.js?v=20261008.1"></script>
<?php include __DIR__.'/../includes/footer.php'; ?>
