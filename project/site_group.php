<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectOrderSplit.php';
require_once __DIR__ . '/../includes/ProjectSiteProjects.php';
$actor = ps_require_actor();
$id = (int)($_GET['id'] ?? 0);
$order = ps_order($id, $actor);
$error = '';
if (!psp_is_website($order['project_type'])) { http_response_code(404); exit('此订单不是网站项目'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        if ($actor['role'] !== 'finance') throw new RuntimeException('仅财务可确认网站项目和付款分配');
        db()->beginTransaction();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'register') {
            if (pos_parent_of($id)) throw new RuntimeException('请在原网站项目订单登记第一个网站');
            psp_register($id, $id, (string)$order['order_no'], (string)($_POST['site_key'] ?? ''), $actor);
        } elseif ($action === 'verify') {
            $site = psp_order($id);
            if (!$site) throw new RuntimeException('请先登记网站项目标识');
            psp_verify((int)$site['root_order_id'], (string)($_POST['paid_amount'] ?? ''), (string)($_POST['evidence_note'] ?? ''), $actor);
        } else throw new RuntimeException('操作无效');
        db()->commit();
        header('Location: ' . BASE_URL . '/project/site_group.php?id=' . $id . '&saved=1'); exit;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = $e->getMessage();
    }
}
$site = psp_order($id);
$members = $site ? psp_members((int)$site['root_order_id']) : [];
$verification = count($members) > 1 ? psp_verification((int)$site['root_order_id']) : null;
$current = $verification && hash_equals((string)$verification['allocation_hash'], psp_allocation_hash($members));
$page_title = '网站项目与付款分配';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h4>网站项目与付款分配</h4><a class="btn btn-outline-secondary btn-sm" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo $id; ?>">返回订单</a></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">已保存</div><?php endif; ?>
<div class="card"><div class="card-body">
<p>客户付款号：<strong><?php echo e($site['external_order_no'] ?? $order['order_no']); ?></strong>。每个独立网站有自己的项目订单、参与人、成本和分成；同一笔付款只分配一次。</p>
<?php if (!$site): ?>
<p class="text-warning">本单尚未绑定网站项目标识。如果客户用同一付款号购买多个网站，请先给第一个网站绑定域名或明确的项目名称，再导入其他网站。</p>
<?php if ($actor['role'] === 'finance'): ?><form method="post" class="form-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="register"><input name="site_key" class="form-control mr-2" maxlength="160" placeholder="第一个网站的域名或项目名称" required><button class="btn btn-primary">登记本单网站</button></form><?php endif; ?>
<?php else: ?>
<p>本网站项目：<strong><?php echo e($site['site_key']); ?></strong>；同一付款号共 <?php echo count($members); ?> 个网站项目。</p>
<?php if ($actor['role'] === 'finance' && count($members) > 1): ?>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>网站项目</th><th>项目订单</th><th>分配售价</th><th>已审核实收</th><th>分成状态</th></tr></thead><tbody><?php foreach ($members as $member): ?><tr><td><?php echo e($member['site_key']); ?></td><td><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$member['order_id']; ?>"><?php echo e($member['order_no']); ?></a></td><td>¥<?php echo money($member['contract_amount']); ?></td><td>¥<?php echo money($member['receipt_amount']); ?></td><td><?php echo e(ps_label('settlement', $member['settlement_status'])); ?></td></tr><?php endforeach; ?></tbody></table></div>
<div class="alert <?php echo $current ? 'alert-success' : 'alert-warning'; ?>"><?php echo $current ? '本组付款分配已核对。任一项目金额变更后需重新确认。' : '付款分配待财务确认；确认前不能生成新的项目分成。'; ?></div>
<form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="verify"><div class="form-group col-md-2"><label>客户实际付款总额</label><input type="number" min="0.01" step="0.01" name="paid_amount" class="form-control" value="<?php echo e($verification['paid_amount'] ?? ''); ?>" required></div><div class="form-group col-md-7"><label>付款流水或核对凭证说明</label><input name="evidence_note" maxlength="500" class="form-control" value="<?php echo e($verification['evidence_note'] ?? ''); ?>" required></div><div class="form-group col-md-3"><button class="btn btn-primary btn-block">确认整组付款分配</button></div></form><small class="text-muted">先在每张项目订单记录对应实收。各网站售价及已审核实收之和须与客户实际付款相等。</small>
<?php elseif (count($members) > 1): ?><div class="alert <?php echo $current ? 'alert-success' : 'alert-warning'; ?>"><?php echo $current ? '财务已核对整组付款分配。' : '等待财务核对整组付款分配。'; ?></div><?php endif; ?>
<?php endif; ?>
</div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
