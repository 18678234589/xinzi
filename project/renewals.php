<?php
require_once __DIR__ . '/../includes/ProjectRenewalSms.php';
$actor=ps_require_actor(); pr_require($actor); pr_seed(); $error='';
$viewActor=$actor; $viewEmployee=0;
if ($actor['role']==='finance' && !empty($_GET['employee_id'])) {
    $viewEmployee=(int)$_GET['employee_id'];
    $vq=db()->prepare('SELECT id,employee_id,role FROM project_users WHERE employee_id=? ORDER BY is_active DESC,id LIMIT 1'); $vq->execute([$viewEmployee]);
    $viewActor=$vq->fetch() ?: ['type'=>'employee','role'=>'customer_service','id'=>0,'employee_id'=>$viewEmployee];
    $viewActor['type']='employee';
}
$success=isset($_GET['saved']) ? '续费资料已保存，同事可在同一订单中看到更新。' : '';
$edit=null; $order=null;
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        ps_check_csrf();
        $ownerPost=(string)($_POST['owner']??'');
        if (($_POST['resource_type']??'')==='domain' && $ownerPost==='customer' && trim((string)($_POST['note']??''))==='') throw new RuntimeException('客户自有域名请在“备注”里写一句说明（如：客户自备域名，已交付源码）');
        $id=pr_save($_POST,$actor);
        if (($_POST['resource_type']??'')==='domain' && in_array($ownerPost,['ours','customer'],true)) pr_set_owner($id,$ownerPost,(string)($_POST['note']??''),$actor);
        header('Location: '.BASE_URL.'/project/renewals.php?edit='.$id.'&saved=1'); exit;
    }
    if (!empty($_GET['edit'])) { $edit=pr_item((int)$_GET['edit'],$actor); $order=pr_order($edit['order_id'],$actor); }
    elseif (!empty($_GET['order_id'])) $order=pr_order((int)$_GET['order_id'],$actor);
} catch (Throwable $e) {
    $error=$e instanceof RuntimeException ? $e->getMessage() : '暂时无法保存，请稍后再试';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        try { $order=pr_order((int)($_POST['order_id']??0),$actor); $edit=$_POST; $edit['phone_cipher']=''; }
        catch (Throwable $ignored) { $order=null; $edit=null; }
    }
}
$filter=(string)($_GET['filter']??'all'); if (!in_array($filter,['due','all','incomplete','overdue','paused'],true)) $filter='all';
$search=trim((string)($_GET['q']??'')); $params=[]; $where=pr_order_where($viewActor,$params); $today=pr_today();
if ($filter==='due') $where.=" AND r.status='active' AND r.expires_on<=DATE_ADD('$today',INTERVAL 30 DAY)";
elseif ($filter==='overdue') $where.=" AND r.status='active' AND r.expires_on<'$today'";
elseif ($filter==='incomplete') $where.=" AND r.status='active' AND (r.expiry_source='estimated' OR r.expires_on IS NULL OR r.resource_name='' OR r.phone_hash='')";
elseif ($filter==='paused') $where.=" AND r.status<>'active'";
else $where.=" AND r.status='active'";
if ($search!=='') { $where.=' AND (o.order_no LIKE ? OR o.customer_name LIKE ? OR r.resource_name LIKE ?)'; $needle='%'.mb_substr($search,0,100).'%'; $params=array_merge($params,[$needle,$needle,$needle]); }
$count=db()->prepare('SELECT COUNT(*) FROM project_renewal_items r JOIN project_orders o ON o.id=r.order_id WHERE '.$where); $count->execute($params); $total=(int)$count->fetchColumn();
$page=max(1,min((int)($_GET['page']??1),max(1,(int)ceil($total/40)))); $offset=($page-1)*40;
$q=db()->prepare("SELECT r.*,o.order_no,o.customer_name,o.project_type,(SELECT s.state FROM project_renewal_sms s WHERE s.item_id=r.id AND s.expires_on=r.expires_on ORDER BY s.id DESC LIMIT 1) sms_state FROM project_renewal_items r JOIN project_orders o ON o.id=r.order_id WHERE $where ORDER BY r.expires_on IS NULL,r.expires_on ASC,r.id LIMIT 40 OFFSET $offset"); $q->execute($params); $rows=$q->fetchAll();
$stats=pr_stats($viewActor); $config=pr_sms_config(); $scope=pr_scope($viewActor); $page_title='续费工作台';
include __DIR__.'/../includes/header.php';
function pr_url($filter,$search='',$page=1) { global $viewEmployee; return BASE_URL.'/project/renewals.php?'.http_build_query(['filter'=>$filter,'q'=>$search,'page'=>$page,'employee_id'=>$viewEmployee?:null]); }
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/renewals.css?v=20261002.2">
<main class="pr-page">
 <section class="pr-hero"><div><span class="pr-eyebrow">CARE / 持续陪伴</span><h1>让每一次续费，都从容一点</h1><p>到期近的订单排在前面。提前把日期和联系方式补好，我们一起照顾好客户的下一年。</p><div class="pr-pills"><span>到期前 10 · 3 · 1 天</span><span>北京时间 · 白天提醒</span><span><?php echo $scope==='web'?'网站售后服务范围':($scope==='all'?'全业务续费统览':'我的关联订单'); ?></span></div></div><div class="pr-orbit" aria-hidden="true"><i class="fas fa-leaf"></i></div></section>
 <?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endif; ?>
 <?php if ($success): ?><div class="alert alert-success" role="status"><?php echo e($success); ?></div><?php endif; ?>
 <section class="pr-stats" aria-label="续费数量">
  <a href="<?php echo e(pr_url('all')); ?>"><small>待续费订单 · 维护中</small><strong><?php echo (int)$stats['active_orders']; ?></strong><span>按订单去重，点击查看</span></a>
  <a href="<?php echo e(pr_url('due')); ?>"><small>近期到期 · 30 天内及已到期</small><strong><?php echo (int)$stats['due_orders']; ?></strong><span>优先安排联系与续费</span></a>
  <a href="<?php echo e(pr_url('overdue')); ?>"><small>已到期资源</small><strong><?php echo (int)$stats['overdue']; ?></strong><span>优先联系，避免服务中断</span></a>
  <a href="<?php echo e(pr_url('incomplete')); ?>"><small>待补资料 / 待核实资源</small><strong><?php echo (int)$stats['incomplete']; ?></strong><span>预计日期、资源名或手机号待完善</span></a>
 </section>
 <div class="pr-notice"><i class="fas fa-info-circle"></i><span>实际填写／表格到期日优先；未填写时按下单日期 + 1 年标为“预计到期”。<strong><?php echo $config['enabled']?'阿里云自动短信已启用':'自动短信暂未启用'; ?></strong>；每条资源还需启用客户续费通知。<?php if (pr_is_super($actor)): ?> <a href="<?php echo BASE_URL; ?>/project/renewal_sms.php">配置阿里云短信 →</a><?php endif; ?></span></div>
 <?php if ($order): $form=$edit?:['id'=>0,'order_id'=>$order['id'],'resource_type'=>pr_default_type($order['project_type']),'resource_name'=>'','expires_on'=>pr_default_expiry($order['order_date']),'expiry_source'=>'estimated','sms_enabled'=>0,'status'=>'active','revision'=>0,'note'=>'']; $phone=array_key_exists('phone',$form)?$form['phone']:pv_decrypt($form['phone_cipher']??''); ?>
 <section class="pr-panel" id="renewal-form"><div class="pr-panel-title"><div><small><?php echo e($order['project_type'].' · '.$order['order_no']); ?></small><h2><?php echo $edit?'快速更新续费资料':'登记续费资源'; ?></h2></div><a href="<?php echo e(pr_url($filter,$search,$page)); ?>" class="pr-link">收起 ×</a></div>
 <form method="post" class="pr-form">
  <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>"><input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>"><input type="hidden" name="revision" value="<?php echo (int)$form['revision']; ?>">
  <label>资源类型<select name="resource_type" required><?php foreach(pr_types_for($order['project_type']) as $key=>$label): ?><option value="<?php echo e($key); ?>" <?php echo $form['resource_type']===$key?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></label>
  <label>名称（域名 / 小程序名 / 服务器 / 备案号）<input name="resource_name" maxlength="180" placeholder="域名和微信认证需填写，如 example.com / XX 小程序；备案、服务器可留空" value=""<?php echo e($form['resource_name']); ?>"></label>
  <label class="<?php echo ($form['expiry_source']??'estimated')==='estimated'?'pr-needs-date':''; ?>">到期日期<input name="expires_on" type="date" min="2000-01-01" max="2100-12-31" value="<?php echo e($form['expires_on']??''); ?>"><small>填写与预计不同的日期即作为实际日期；日期相同可勾选下方“已核实”。</small></label>
  <label>客户手机号<input name="phone" type="tel" inputmode="tel" autocomplete="off" maxlength="20" placeholder="客户手机号，不是合作人员手机号" value="<?php echo e($phone); ?>"></label>
  <label>域名归属<select name="owner"><?php foreach(pr_owner_labels() as $key=>$label): ?><option value="<?php echo e($key); ?>" <?php echo (($form['owner']??'ours')===$key)?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select><small>客户自备域名、已交付源码的选“客户自有”（需在备注写一句说明）：不再提醒续费，也不算缺资料。只对“域名”有效。</small></label>
  <label>服务状态<select name="status"><?php foreach(['active'=>'持续维护 / 待续费','paused'=>'暂停联系（不发短信）','closed'=>'结束维护（不发短信）'] as $key=>$label): ?><option value="<?php echo e($key); ?>" <?php echo $form['status']===$key?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></label>
  <label>备注<input name="note" maxlength="500" placeholder="可填写已支付待续费、联系情况等" value="<?php echo e($form['note']); ?>"></label>
  <label class="pr-check"><input type="checkbox" name="expiry_confirmed" value="1" <?php echo (in_array($form['expiry_source']??'',['confirmed','imported'],true)||!empty($form['expiry_confirmed']))?'checked':''; ?>> 已从域名 / 认证后台核实此到期日期</label>
  <label class="pr-check"><input type="checkbox" name="sms_enabled" value="1" <?php echo !empty($form['sms_enabled'])?'checked':''; ?>> 客户同意接收续费通知，启用 10 / 3 / 1 天短信提醒</label>
  <div class="pr-form-actions"><button class="pr-button" name="action" value="save">保存资料</button><?php if (!empty($form['id'])): ?><button class="pr-button pr-secondary" name="action" value="renew">已完成续费，保存新到期日</button><?php endif; ?><span>续费须填写新的实际到期日；本页不会自动新增收入或成本。</span></div>
 </form>
 <?php if (!empty($form['id'])): $hq=db()->prepare('SELECT action,old_expiry,new_expiry,details_json,created_at FROM project_renewal_history WHERE item_id=? ORDER BY id DESC LIMIT 8'); $hq->execute([(int)$form['id']]); ?><details class="mt-3"><summary>查看日期变更与续费记录</summary><?php foreach($hq->fetchAll() as $history): $detail=json_decode($history['details_json'],true)?:[]; ?><p class="small mt-2 mb-2"><?php echo e($history['created_at']); ?> · <?php echo e(['renew'=>'已完成续费','save'=>'在线更新','import'=>'表格补充','import_conflict'=>'表格日期与已有日期不同，请核对'][$history['action']]??$history['action']); ?> · <?php echo e(($history['old_expiry']?:'未登记').' → '.($history['new_expiry']?:'待补')); ?><?php if ($history['action']==='import_conflict'): ?> <strong>表格日期：<?php echo e($detail['uploaded_expiry']??''); ?>（未覆盖已有日期）</strong><?php endif; ?></p><?php endforeach; ?></details><?php endif; ?>
 </section>
 <?php endif; ?>
 <section class="pr-panel"><div class="pr-panel-title"><h2>待续费清单 <small><?php echo $total; ?> 条资源</small></h2><form method="get" class="pr-search"><input type="hidden" name="filter" value="<?php echo e($filter); ?>"><input name="q" maxlength="100" placeholder="订单号 / 客户 / 域名" aria-label="搜索续费订单" value="<?php echo e($search); ?>"><button class="pr-button">查找</button></form></div>
 <nav class="pr-tabs" aria-label="续费列表筛选"><?php foreach(['all'=>'待续费 · 全部维护中','due'=>'30 天内到期','overdue'=>'已到期','incomplete'=>'待补资料','paused'=>'暂停 / 结束'] as $key=>$label): ?><a class="<?php echo $filter===$key?'active':''; ?>" href="<?php echo e(pr_url($key,$search)); ?>"><?php echo e($label); ?></a><?php endforeach; ?></nav>
 <div class="pr-table-wrap"><table class="pr-table"><thead><tr><th>订单 / 客户</th><th>资源</th><th>到期时间 ↑</th><th>客户联系 / 短信</th><th>操作</th></tr></thead><tbody>
 <?php foreach($rows as $row): $days=pr_days($row['expires_on'],$today); $estimated=$row['expiry_source']==='estimated'; $masked=pv_decrypt($row['phone_cipher']); $masked=$masked?substr($masked,0,3).'****'.substr($masked,-4):'待补客户手机号'; ?>
 <tr><td><strong><?php echo e($row['order_no']); ?></strong><span><?php echo e($row['customer_name']?:'客户昵称待补'); ?></span><small><?php echo e($row['project_type']); ?></small></td><td><strong><?php echo e($row['resource_name']?:'资源名称待补'); ?></strong><small><?php echo e(pr_type_labels()[$row['resource_type']]??$row['resource_type']); ?></small></td>
 <td><a class="pr-date <?php echo $estimated?'pr-needs-date':''; ?>" href="<?php echo BASE_URL; ?>/project/renewals.php?edit=<?php echo (int)$row['id']; ?>#renewal-form"><strong><?php echo e($row['expires_on']?:'请补到期日期'); ?></strong><?php if ($estimated): ?><small>预计到期 · 请核实 / 补录</small><?php else: ?><small>已核实日期</small><?php endif; ?></a><span class="pr-badge <?php echo $days!==null && $days<=3?'pr-urgent':''; ?>"><?php echo $days===null?'日期待补':($days<0?'已到期 '.abs($days).' 天':($days===0?'今天到期':'还有 '.$days.' 天')); ?></span></td>
 <td><span><?php echo e($masked); ?></span><small><?php echo $row['sms_enabled']?'续费通知开启':'续费通知未开启'; ?></small><?php if ($row['sms_state']): ?><small><?php echo e(['sent'=>'服务商已受理','failed'=>'发送失败，请管理员核对','unknown'=>'发送结果待核对，不自动重发','cancelled'=>'旧提醒已停止','pending'=>'提醒已排队','sending'=>'发送处理中'][$row['sms_state']]??$row['sms_state']); ?></small><?php endif; ?></td>
 <td><a class="pr-button pr-secondary" href="<?php echo BASE_URL; ?>/project/renewals.php?edit=<?php echo (int)$row['id']; ?>#renewal-form">补录 / 续费</a></td></tr>
 <?php endforeach; ?>
 <?php if (!$rows): ?><tr><td colspan="5" class="pr-empty"><i class="fas fa-seedling"></i><strong>这里暂时没有符合条件的资源</strong><p>可以切换“待补资料”，或从下面选择订单登记域名 / 小程序认证。</p></td></tr><?php endif; ?>
 </tbody></table></div>
 <?php if ($total>40): ?><div class="pr-pagination"><?php if ($page>1): ?><a href="<?php echo e(pr_url($filter,$search,$page-1)); ?>">上一页</a><?php endif; ?><span><?php echo $page.' / '.ceil($total/40); ?></span><?php if ($page*40<$total): ?><a href="<?php echo e(pr_url($filter,$search,$page+1)); ?>">下一页</a><?php endif; ?></div><?php endif; ?>
 </section>
 <?php $find=trim((string)($_GET['find_order']??'')); $p=[]; $w=pr_order_where($viewActor,$p); if ($find!=='') { $w.=' AND (o.order_no LIKE ? OR o.customer_name LIKE ?)'; $p[]='%'.mb_substr($find,0,100).'%'; $p[]='%'.mb_substr($find,0,100).'%'; } $lookup=db()->prepare('SELECT o.id,o.order_no,o.customer_name,o.project_type FROM project_orders o WHERE '.$w.' ORDER BY o.order_date DESC,o.id DESC LIMIT 20'); $lookup->execute($p); ?>
 <details class="pr-panel pr-order-picker" <?php echo $find!==''?'open':''; ?>><summary>＋ 为已有订单登记续费资源</summary><p>一个订单可以登记多个域名 / 小程序认证；只展示你有续费服务权限的订单。</p><form method="get" class="pr-search"><input name="find_order" maxlength="100" placeholder="输入订单号或客户昵称" value="<?php echo e($find); ?>"><button class="pr-button">查找订单</button></form><div class="pr-order-results"><?php foreach($lookup->fetchAll() as $o): ?><a href="<?php echo BASE_URL; ?>/project/renewals.php?order_id=<?php echo (int)$o['id']; ?>#renewal-form"><strong><?php echo e($o['order_no']); ?></strong><span><?php echo e($o['customer_name'].' · '.$o['project_type']); ?></span><small>登记资源 →</small></a><?php endforeach; ?></div></details>
</main>
<?php include __DIR__.'/../includes/footer.php'; ?>
