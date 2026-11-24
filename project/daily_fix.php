<?php
require_once __DIR__ . '/../includes/ProjectDailyFix.php';
// 每日“待完善订单”弹窗的接口：GET ?auto=1 今天首次且有问题才返回内容；GET 返回内容（手动打开）；POST 提交更正 / 无法更正，返回 JSON。
$actor = ps_actor();
if (!$actor || !pdf_eligible($actor)) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['show' => false]); exit; }
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $msg = pdf_apply($actor, (string)($_POST['type'] ?? ''), (int)($_POST['order_id'] ?? 0), $_POST);
        echo json_encode(['ok' => true, 'msg' => $msg, 'left' => count(pdf_issues($actor))], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e instanceof RuntimeException ? $e->getMessage() : '保存失败，请稍后再试'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
if (!empty($_GET['auto']) && !pdf_take_today($actor)) { echo json_encode(['show' => false]); exit; }
$issues = pdf_issues($actor);
if (!$issues) { echo json_encode(['show' => !empty($_GET['manual']), 'html' => '<div class="p-4 text-center text-muted">没有待完善的订单，谢谢！</div>', 'count' => 0], JSON_UNESCAPED_UNICODE); exit; }
$csrf = ps_csrf_token();
ob_start();
?>
<p class="small text-muted mb-3">下面是你参与的、还没审核的订单里<b>资料不完整</b>的地方，不改就不能结算、也不会有提成。请你直接在这里改；<b>只要没改好，每天首次打开系统都会提醒</b>。确实改不了（不知道怎么填 / 不是你负责的）就点“无法更正，交给财务”，之后不再提醒。</p>
<?php foreach ($issues as $x): $k = e($x['key']); ?>
<div class="border rounded p-3 mb-3 pdf-card" data-key="<?php echo $k; ?>">
  <div class="d-flex justify-content-between flex-wrap"><div><b><?php echo e($x['title']); ?></b> <span class="badge badge-light"><?php echo e($x['business']); ?></span></div>
    <a class="small" target="_blank" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$x['order_id']; ?>">打开订单 <?php echo e($x['order_no']); ?>（售价 ¥<?php echo number_format($x['amount'], 2); ?>）</a></div>
  <p class="small my-2" style="line-height:1.7"><?php echo $x['help']; ?></p>
  <form class="pdf-form form-inline" data-order="<?php echo (int)$x['order_id']; ?>">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="type" value="<?php echo e($x['type']); ?>"><input type="hidden" name="order_id" value="<?php echo (int)$x['order_id']; ?>">
    <?php if ($x['type'] === 'kind'): ?>
      <select name="order_kind" class="form-control form-control-sm mr-2" required><option value="">请选择订单类型</option><?php foreach ($x['fields']['kinds'] as $kd): ?><option value="<?php echo e($kd); ?>"><?php echo e($kd); ?></option><?php endforeach; ?></select>
    <?php elseif ($x['type'] === 'cost'): $def = $x['fields']['reported'] ?? $x['fields']['std']; ?>
      <input type="hidden" name="item_id" value="<?php echo (int)$x['item_id']; ?>">
      <label class="small mr-2">真实成本 ¥</label><input name="amount" class="form-control form-control-sm mr-2" style="width:110px" inputmode="decimal" value="<?php echo $def !== null ? e(number_format($def, 2, '.', '')) : ''; ?>" required>
      <?php if ($x['fields']['reported'] !== null): ?><button type="button" class="btn btn-sm btn-link pdf-fill" data-v="<?php echo e(number_format($x['fields']['reported'], 2, '.', '')); ?>">按表格 ¥<?php echo number_format($x['fields']['reported'], 2); ?></button><?php endif; ?>
      <?php if ($x['fields']['std'] !== null): ?><button type="button" class="btn btn-sm btn-link pdf-fill" data-v="<?php echo e(number_format($x['fields']['std'], 2, '.', '')); ?>">按标准价 ¥<?php echo number_format($x['fields']['std'], 2); ?></button><?php endif; ?>
    <?php else: ?><span class="small mr-2">确认这单域名 / 空间不需要我们另外付费</span>
    <?php endif; ?>
    <button class="btn btn-sm btn-success mr-2"><?php echo $x['type'] === 'domain' ? '确认无需另计' : '提交更正'; ?></button>
  </form>
  <form class="pdf-skip form-inline mt-2" data-order="<?php echo (int)$x['order_id']; ?>">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="type" value="skip"><input type="hidden" name="order_id" value="<?php echo (int)$x['order_id']; ?>"><input type="hidden" name="issue" value="<?php echo e($x['type']); ?>">
    <input name="reason" class="form-control form-control-sm mr-2" style="width:260px" maxlength="300" placeholder="无法更正的原因（选填，如：不知道真实成本）"><button class="btn btn-sm btn-outline-secondary">无法更正，交给财务</button>
  </form>
  <div class="small mt-2 pdf-msg"></div>
</div>
<?php endforeach;
echo json_encode(['show' => true, 'html' => ob_get_clean(), 'count' => count($issues)], JSON_UNESCAPED_UNICODE);
