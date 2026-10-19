<?php
require_once __DIR__ . '/../includes/ProjectCompletionSync.php';
// 财务上传网站订单汇总表 → 预览 → 按表标记网站订单的完成情况。
$actor = ps_require_actor();
if (!pcs_can_access($actor)) { http_response_code(403); exit('仅冯超和财务可以使用'); }
$canConfirm = ($actor['role'] ?? '') === 'finance';
$error = ''; $success = ''; $plan = null; $parsed = null; $result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'preview') {
            $f = $_FILES['sheet'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 20 * 1024 * 1024) throw new RuntimeException('请选择不超过 20MB 的 xlsx 订单汇总表');
            if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('只支持 .xlsx 文件（.xls 请先另存为 xlsx）');
            $parsed = pcs_parse($f['tmp_name']);
            if (!$parsed['map']) throw new RuntimeException('没有识别到订单：表头需要有“订单编号”和“到账情况”（或“状态”）两列');
            $_SESSION['pcs_map'] = $parsed['map'];
            $plan = pcs_plan($parsed['map']);
        } elseif ($do === 'manual_finish') {
            $result = pcs_manual_finish((int)($_POST['order_id'] ?? 0), $actor);
            if ($result['skipped']) throw new RuntimeException(implode('；', $result['skipped']));
            $success = $result['finished'] ? '已确认交付完成，可按正常审核流程核算结算；收款、成本和分成条件仍须满足。' : '该订单已标记完成，可查看订单的审核结算状态。';
        } elseif ($do === 'apply') {
            $map = $_SESSION['pcs_map'] ?? null;
            if (!$map) throw new RuntimeException('预览已过期，请重新上传');
            $plan = pcs_plan($map);
            $result = pcs_apply($plan, !empty($_POST['reopen']), $actor);
            unset($_SESSION['pcs_map']);
            $success = '已标记完成 ' . $result['finished'] . ' 单、标记未完成 ' . $result['unfinished'] . ' 单' . (!empty($_POST['reopen']) ? '、撤回已审核 ' . $result['reopened'] . ' 单' : '');
            $plan = null;
        } else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '操作失败，请稍后再试'; }
}
$orderNo = trim((string)($_POST['order_no'] ?? $_GET['order_no'] ?? ''));
$manualOrders = [];
if ($orderNo !== '' && strlen($orderNo) <= 100) {
    $lookup = db()->prepare("SELECT id,order_no,customer_name,project_type,contract_amount,order_date,delivery_status,settlement_status FROM project_orders WHERE order_no=? AND project_type IN ('网站模板','AI网站定制','网站定制') ORDER BY id");
    $lookup->execute([$orderNo]); $manualOrders = $lookup->fetchAll();
}
$page_title = '网站订单完成同步';
include __DIR__ . '/../includes/header.php';
$sumComm = function (array $list) { $t = 0.0; foreach ($list as $o) $t += (float)$o['comm']; return $t; };
$listRows = function (array $list, $max = 40) { $out = ''; foreach (array_slice($list, 0, $max) as $o) $out .= '<tr><td>' . e($o['order_no']) . '</td><td>' . e($o['project_type']) . '</td><td class="text-right">¥' . number_format((float)$o['contract_amount'], 2) . '</td><td class="text-right">¥' . number_format((float)$o['comm'], 2) . '</td></tr>'; if (count($list) > $max) $out .= '<tr><td colspan="4" class="text-muted">…另有 ' . (count($list) - $max) . ' 单</td></tr>'; return $out; };
?>
<div class="container-fluid" style="max-width:1100px">
  <h4 class="mt-3">网站订单完成同步</h4>
  <p class="text-muted small">仅用于<b>网站模板、AI 网站定制（含旧名称“网站定制”）</b>。上传网站订单汇总表，表里“到账情况”写 <b>已完成 / 到账</b> 的订单标为已完成，写 <b>未完成 / 未发货 / 暂不发货 / 欠尾款</b> 的标为未完成，交易关闭的不处理。小程序及其他业务会自动跳过。确认完成后，订单仍须满足收款、成本及分成审核条件才能结算。</p>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
  <?php if (!$canConfirm): ?><div class="alert alert-info">可查询网站订单及上传汇总表预览，完成状态由财务确认。</div><?php endif; ?>
  <div class="card mb-4"><div class="card-header">按订单号查询 / 手动确认完成</div><div class="card-body">
    <form method="get" class="form-inline mb-3"><label for="completionOrderNo" class="mr-2">平台订单号</label><input id="completionOrderNo" name="order_no" value="<?php echo e($orderNo); ?>" class="form-control mr-2" maxlength="100" required><button class="btn btn-outline-primary">查询网站订单</button></form>
    <?php if ($orderNo !== '' && !$manualOrders): ?><p class="text-muted">未找到对应的网站业务订单，请核对完整订单号。</p><?php endif; ?>
    <?php foreach ($manualOrders as $o): ?>
      <div class="border rounded p-3 mb-2"><b><?php echo e($o['order_no']); ?></b> · <?php echo e($o['project_type']); ?> · <?php echo e($o['customer_name']); ?> · ¥<?php echo number_format((float)$o['contract_amount'],2); ?>
        <p class="mb-2 mt-2">交付：<?php echo $o['delivery_status']==='finished' ? '已完成' : '未完成'; ?>；结算：<?php echo e(ps_label('settlement',$o['settlement_status'])); ?></p>
        <a class="btn btn-sm btn-outline-secondary" href="order.php?id=<?php echo (int)$o['id']; ?>">查看订单及结算条件</a>
        <?php if ($canConfirm && $o['delivery_status']!=='finished' && in_array($o['settlement_status'],['draft','review'],true)): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('确认该网站订单已实际交付完成？标记后仍按正常规则审核结算。')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="manual_finish"><input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>"><input type="hidden" name="order_no" value="<?php echo e($o['order_no']); ?>"><button class="btn btn-sm btn-success">确认完成，进入结算审核</button></form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div></div>
  <?php if ($result && $result['skipped']): ?><div class="alert alert-warning"><b>这些没处理：</b><ul class="mb-0"><?php foreach (array_slice($result['skipped'], 0, 60) as $s): ?><li><?php echo e($s); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="form-inline mb-4">
    <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="preview">
    <input type="file" name="sheet" accept=".xlsx" class="form-control-file mr-2" required><button class="btn btn-primary">上传并预览</button>
  </form>
<?php if ($plan): $mf = $plan['mark_finished']; $mu = $plan['mark_unfinished']; $au = $plan['approved_unfinished']; ?>
  <div class="card mb-3"><div class="card-header">预览（读到 <?php echo count($_SESSION['pcs_map']); ?> 个订单号，<?php echo (int)$parsed['rows']; ?> 行<?php echo $parsed['conflicts'] ? '；' . count($parsed['conflicts']) . ' 个订单号在表里状态不一致，已按未完成' : ''; ?>）</div><div class="card-body">
    <ul>
      <li>将标记为 <b>已完成</b>：<?php echo count($mf); ?> 单（系统里目前是未完成）</li>
      <li>将标记为 <b>未完成</b>：<?php echo count($mu); ?> 单（系统里目前是已完成，且还没审核）</li>
      <li>已审核、但表里是未完成：<b><?php echo count($au); ?> 单</b>，已生成的分成合计 ¥<?php echo number_format($sumComm($au), 2); ?>（默认不动，下面可选撤回）</li>
      <li>已一致：<?php echo (int)$plan['same']; ?> 单；交易关闭不处理：<?php echo (int)$plan['closed']; ?> 单；非网站业务跳过：<?php echo (int)$plan['not_website']; ?> 单；系统里找不到：<?php echo count($plan['missing']); ?> 个订单号</li>
    </ul>
    <?php if ($au): ?><details class="mb-3"><summary>已审核但表里未完成的订单</summary><table class="table table-sm mt-2"><thead><tr><th>订单号</th><th>业务</th><th class="text-right">售价</th><th class="text-right">已生成分成</th></tr></thead><tbody><?php echo $listRows($au); ?></tbody></table></details><?php endif; ?>
    <?php if ($plan['missing']): ?><details class="mb-3"><summary>系统里找不到的订单号（前 40 个）</summary><div class="small"><?php echo e(implode('、', array_slice($plan['missing'], 0, 40))); ?></div></details><?php endif; ?>
    <?php if ($canConfirm): ?><form method="post" onsubmit="return confirm('确认按表标记？未审核订单的完成情况会被修改。')">
      <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="apply">
      <?php if ($au): ?><div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="reopen" value="1" id="pcsReopen"><label class="form-check-label" for="pcsReopen">同时撤回上面“已审核但未完成”的 <?php echo count($au); ?> 单：删除它们的分成快照、回到未审核（备份在审计日志里；已锁月、已申请结算或有调整的会跳过）。不勾则这些订单的分成保持现状。</label></div><?php endif; ?>
      <button class="btn btn-success">确认标记</button> <a class="btn btn-outline-secondary" href="completion_sync.php">取消</a>
    </form><?php else: ?><p class="text-muted">以上为预览，请由财务确认完成状态。</p><?php endif; ?>
  </div></div>
<?php endif; ?>
  <p class="small text-muted">没有汇总表时，也可以在订单里或订单列表批量选中“标记交付完成”。已完成的订单再走“订单审核”批量通过，分成即计入工资。</p>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
