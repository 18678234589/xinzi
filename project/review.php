<?php
require_once __DIR__.'/../includes/ProjectAutoReview.php';
$actor = ps_require_finance();
$filterFinance = ps_finance_filter($actor, $_GET['filter_finance'] ?? null);
$financeWhere = ps_finance_business_condition($filterFinance);
$month = (string)($_GET['month'] ?? date('Y-m',strtotime('first day of last month')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month)) $month=date('Y-m');
$state=(string)($_GET['state']??''); $error=''; $result=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    ps_check_csrf();
    try {
        if (!pa_storage_available()) throw new RuntimeException('自动核对表尚未迁移');
        $action=(string)($_POST['action']??'');
        if ($action==='check_order') {
            $id=(int)($_POST['order_id']??0); ps_order($id,$actor);
            pa_check_order($id,true,true);
            header('Location: '.BASE_URL.'/project/order.php?id='.$id); exit;
        } elseif ($action==='switch') {
            ps_setting_set('auto_review_enabled',isset($_POST['enabled']),$actor['id']);
            ps_audit('setting',0,'auto_review_switch',$actor,['enabled'=>isset($_POST['enabled'])]);
        } elseif (in_array($action,['refresh','apply'],true)) {
            if ($action==='apply'&&!ps_setting_get('auto_review_enabled',false)) throw new RuntimeException('请先启用自动核对开关；关闭时仍可刷新核对结果');
            // Keep the web request bounded; the worker continues the rest of the queue.
            $result=pa_batch(200,$action==='apply',true,$month,$filterFinance);
        } else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { $error=$e instanceof PDOException?'核对未完成，本单金额未提交，请查看服务器日志':$e->getMessage(); }
}
$counts=[]; $rows=[];
if (pa_storage_available()) {
    $q=db()->prepare('SELECT a.state,COUNT(*) n FROM project_auto_reviews a JOIN project_orders o ON o.id=a.order_id WHERE o.order_date>=? AND o.order_date<DATE_ADD(?,INTERVAL 1 MONTH) AND '.$financeWhere.' GROUP BY a.state');$q->execute([$month.'-01',$month.'-01']);foreach($q->fetchAll() as $r)$counts[$r['state']]=(int)$r['n'];
    $where='o.order_date>=? AND o.order_date<DATE_ADD(?,INTERVAL 1 MONTH) AND '.$financeWhere;$params=[$month.'-01',$month.'-01'];
    if(in_array($state,['wait_sync','wait_finance','wait_data','exception','ready','auto_passed','settled','queued'],true)){ $where.=' AND COALESCE(a.state,\'queued\')=?';$params[]=$state; }
    $q=db()->prepare('SELECT o.id,o.order_no,o.project_type,o.order_date,o.settlement_status,a.state,a.reasons_json,a.checked_at FROM project_orders o LEFT JOIN project_auto_reviews a ON a.order_id=o.id WHERE '.$where." ORDER BY (a.state='exception') DESC,o.order_date DESC,o.id DESC LIMIT 100");$q->execute($params);$rows=$q->fetchAll();
}
$page_title='系统自动核对';include __DIR__.'/../includes/header.php';
?>
<style>.ar-hero{border:1px solid #cce4db;border-radius:22px;padding:26px;background:radial-gradient(ellipse at 90% 0,#d6edf1,transparent 60%),linear-gradient(125deg,#edf8f1,#fff8ed);margin-bottom:20px}.ar-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}.ar-stat{background:rgba(255,255,255,.8);border:1px solid #dde9e2;border-radius:16px;padding:16px;color:#244c40;text-decoration:none!important}.ar-stat strong{font-size:26px;display:block}.ar-reasons{max-width:480px;white-space:normal}.ar-hero .btn-primary{color:#fff!important}@media(max-width:600px){.ar-hero{padding:18px}.ar-grid{grid-template-columns:repeat(2,1fr)}}</style>
<section class="ar-hero"><small style="letter-spacing:.12em;color:#42745f">EVIDENCE FIRST</small><h3 class="mt-2">系统核对，财务只看异常</h3><p class="text-muted">按可信收退款、实际交付和规则中心核算。售价不当实收，付款不代替交付；已锁定月份、历史已结算金额不自动覆盖。</p><div class="d-flex flex-wrap" style="gap:10px"><a class="btn btn-outline-primary" href="<?php echo BASE_URL; ?>/project/index.php?month=<?php echo e($month); ?>&amp;filter_finance=<?php echo e($filterFinance); ?>">返回项目订单</a><form method="get" class="d-flex" style="gap:8px"><label class="sr-only" for="reviewMonth">订单归属月份</label><input type="month" id="reviewMonth" class="form-control" name="month" value="<?php echo e($month); ?>"><label class="sr-only" for="reviewFinance">核算财务</label><select class="form-control" name="filter_finance" id="reviewFinance"><option value="all" <?php echo $filterFinance === 'all' ? 'selected' : ''; ?>>全部业务</option><?php foreach (ps_admin_reviewers() as $finance): $login = strtolower($finance['username']); ?><option value="<?php echo e($login); ?>" <?php echo $filterFinance === $login ? 'selected' : ''; ?>><?php echo e($finance['real_name']); ?>负责的业务</option><?php endforeach; ?></select><button class="btn btn-primary" type="submit">查看月份</button></form></div></section>
<?php if($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if($result): ?><div class="alert alert-info">本次核对 <?php echo (int)$result['checked']; ?> 笔，自动结算 <?php echo (int)$result['applied']; ?> 笔，执行异常 <?php echo count($result['errors']); ?> 笔。每次最多处理 200 笔，剩余记录由后台接续，也可继续点击。只刷新核对结果不会更改收款、退款或分成。</div><?php endif; ?>
<div class="ar-grid mb-3"><?php foreach(['auto_passed','wait_sync','wait_finance','wait_data','exception','ready','settled'] as $s): $meta=pa_state_meta($s); ?><a class="ar-stat" href="?month=<?php echo e($month); ?>&amp;state=<?php echo e($s); ?>&amp;filter_finance=<?php echo e($filterFinance); ?>"><span><?php echo e($meta[0]); ?></span><strong><?php echo (int)($counts[$s]??0); ?></strong></a><?php endforeach; ?></div>
<div class="card mb-3"><div class="card-body d-flex flex-wrap align-items-center" style="gap:14px"><form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="switch"><label class="mb-0 mr-2"><input type="checkbox" name="enabled" value="1" <?php echo ps_setting_get('auto_review_enabled',false)?'checked':''; ?>> 开启定时自动核对与结算</label><button class="btn btn-outline-primary btn-sm">保存</button></form><form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><button name="action" value="refresh" class="btn btn-outline-primary">仅刷新核对结果</button> <button name="action" value="apply" class="btn btn-success" onclick="return confirm('仅处理当前核算财务范围内本月证据齐全、未结算的订单；不改历史已结算金额、不自动发款。继续？')">自动处理符合条件订单</button></form><small class="text-muted">定时任务每 5 分钟接续处理；原始资料保留，核对依据写入系统日志。</small></div></div>
<div class="card"><div class="card-header">核对明细 · <?php echo e($month); ?> · <?php echo $filterFinance === 'all' ? '全部业务' : e(ps_finance_name($filterFinance)) . '负责的业务'; ?>（最多显示最近 100 笔，可在项目订单继续筛选）</div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>订单</th><th>业务／日期／核算财务</th><th>系统核对</th><th>原因</th><th></th></tr></thead><tbody><?php foreach($rows as $r):$meta=pa_state_meta($r['state']??'queued');$reasons=json_decode($r['reasons_json']??'',true)?:[]; ?><tr><td><?php echo e($r['order_no']); ?></td><td><?php echo e($r['project_type']); ?><div class="small text-muted"><?php echo e($r['order_date']); ?></div><div class="small text-muted">核算财务：<?php echo e(ps_finance_name(ps_business_reviewer($r['project_type']))); ?></div></td><td><span class="badge badge-<?php echo e($meta[1]); ?>"><?php echo e($meta[0]); ?></span><div class="small text-muted"><?php echo e($r['checked_at']??'尚未核对'); ?></div></td><td class="ar-reasons small"><?php foreach($reasons as $reason): ?><div><?php echo e($reason['text']); ?></div><?php endforeach; ?></td><td><a class="btn btn-sm btn-outline-primary" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$r['id']; ?>">打开结算单</a></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">暂无对应核对记录</td></tr><?php endif; ?></tbody></table></div></div>
<?php include __DIR__.'/../includes/footer.php'; ?>
