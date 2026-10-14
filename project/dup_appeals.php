<?php
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectDupAppeal.php';
// 重复订单申诉：导入时“日期 + 金额相同”的行默认按已有订单处理；确实是另一笔订单的，在这里写理由并上传证据，财务通过后才能作为独立订单导入。
$actor = ps_require_actor();
pda_ensure();
$isFinance = ($actor['type'] ?? '') === 'admin';
$error = ''; $success = '';

// 证据下载：财务或提交人本人
if (isset($_GET['file'])) {
    $q = db()->prepare('SELECT * FROM project_dup_appeals WHERE id=?'); $q->execute([(int)$_GET['file']]); $row = $q->fetch();
    if (!$row || !($isFinance || (int)$row['employee_id'] === (int)($actor['employee_id'] ?? -1)) || !preg_match('/^[a-f0-9]{32}\.(?:png|jpg|jpeg|webp|pdf|xlsx|xls)$/', $row['evidence_stored'])) { http_response_code(404); exit('文件不存在'); }
    $data = ps_private_read('dup_appeals', $row['evidence_stored']);
    if ($data === null) { http_response_code(404); exit('文件不存在'); }
    $ext = strtolower(pathinfo($row['evidence_stored'], PATHINFO_EXTENSION));
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . (strpos($mime, 'image/') === 0 ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($row['evidence_name']));
    header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: default-src 'none'; sandbox"); header('Cache-Control: private, no-store');
    echo $data; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'submit') { pda_submit($actor, $_POST, $_FILES['evidence'] ?? []); $success = '申诉已提交，财务处理后会收到站内信。'; }
        elseif ($do === 'handle') { pda_handle((int)($_POST['id'] ?? 0), $actor, (string)($_POST['decision'] ?? ''), (string)($_POST['note'] ?? '')); $success = '已处理'; }
        else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '操作失败，请稍后再试'; }
}

// 从导入预览点进来：带着被判重复的那一行
$form = null;
if (isset($_GET['new']) && !$isFinance) {
    $q = db()->prepare('SELECT id,order_no,project_type,order_date,contract_amount,settlement_status FROM project_orders WHERE id=?'); $q->execute([(int)($_GET['existing'] ?? 0)]); $ex = $q->fetch();
    if ($ex) $form = ['business' => (string)($_GET['business'] ?? ''), 'existing' => $ex, 'date' => (string)($_GET['date'] ?? ''), 'amount' => (string)($_GET['amount'] ?? ''), 'nickname' => (string)($_GET['nickname'] ?? ''), 'domain' => (string)($_GET['domain'] ?? ''), 'shop' => (string)($_GET['shop'] ?? '')];
}
$rows = pda_list($actor);
$label = ['pending' => ['待财务处理', 'warning'], 'approved' => ['已通过', 'success'], 'rejected' => ['已驳回', 'danger']];
$page_title = '重复订单申诉';
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid" style="max-width:1100px">
  <div style="background:linear-gradient(135deg,#f3f6f4,#fff);border:1px solid #dfe7e2;border-radius:18px;padding:20px 26px;margin:14px 0">
    <h2 style="margin:0 0 6px;font-size:1.35rem;font-weight:700;color:#1f3a2c"><i class="fas fa-clone mr-2"></i>重复订单申诉</h2>
    <p style="margin:0;color:#5d6f64;font-size:.9rem;line-height:1.7">没有订单号的订单，只要<b>日期和金额相同</b>、又看不出是别的客户，系统默认它和已有订单是<b>同一笔</b>，不会新建，避免同一笔业务算两次提成。确实是另一笔订单的，请在这里写明理由并上传证据（付款截图、聊天记录、合同等），财务通过后，重新上传表格即可作为独立订单导入。</p>
  </div>
  <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

  <?php if ($form): ?>
  <div class="card mb-4 border-warning"><div class="card-header" style="background:#fff8e6">提交申诉：这一行不是同一笔订单</div><div class="card-body">
    <p class="small mb-3">系统认为这一行和已有订单 <b><?php echo e($form['existing']['order_no']); ?></b>（<?php echo e($form['existing']['order_date']); ?>，¥<?php echo e(number_format((float)$form['existing']['contract_amount'], 2)); ?>）是同一笔。你这一行：<?php echo e($form['date']); ?>，¥<?php echo e($form['amount']); ?><?php echo $form['nickname'] !== '' ? '，付款昵称“' . e($form['nickname']) . '”' : ''; ?><?php echo $form['domain'] !== '' ? '，域名 ' . e($form['domain']) : ''; ?>。</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="submit">
      <?php foreach (['business', 'date', 'amount', 'nickname', 'domain', 'shop'] as $k): ?><input type="hidden" name="<?php echo $k; ?>" value="<?php echo e($form[$k]); ?>"><?php endforeach; ?>
      <input type="hidden" name="existing" value="<?php echo (int)$form['existing']['id']; ?>">
      <div class="form-group"><label class="small text-muted mb-1">为什么是另一笔订单？（至少 10 个字）</label><textarea name="reason" class="form-control" rows="3" required minlength="10" placeholder="例如：这是另一位客户的备案，付款人不同，域名也不同；已和已有订单分别收款"></textarea></div>
      <div class="form-group"><label class="small text-muted mb-1">证据文件（付款截图 / 聊天记录 / 合同，png jpg pdf xlsx，5MB 内）</label><input type="file" name="evidence" class="form-control-file" required accept=".png,.jpg,.jpeg,.webp,.pdf,.xlsx,.xls"></div>
      <button class="btn btn-warning">提交申诉</button> <a class="btn btn-link" href="<?php echo BASE_URL; ?>/project/dup_appeals.php">取消</a>
    </form></div></div>
  <?php endif; ?>

  <div class="card mb-4"><div class="card-header"><?php echo $isFinance ? '全部申诉' : '我的申诉'; ?></div>
    <div class="table-responsive"><table class="table table-hover mb-0">
      <thead class="thead-light"><tr><th>这一行</th><th>被判为同一笔的已有订单</th><th>理由 / 证据</th><th>状态</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $r): [$lt, $lc] = $label[$r['status']]; ?>
        <tr>
          <td class="small"><b><?php echo e($r['business']); ?></b><div><?php echo e($r['order_date']); ?> · ¥<?php echo e(number_format((float)$r['amount'], 2)); ?></div><div class="text-muted"><?php echo e($r['nickname'] !== '' ? $r['nickname'] : '—'); ?><?php echo $r['domain'] !== '' ? ' · ' . e($r['domain']) : ''; ?></div><div class="text-muted">提交人：<?php echo e($r['emp_name'] ?: '—'); ?> · <?php echo e(substr($r['created_at'], 0, 16)); ?></div></td>
          <td class="small"><?php echo e($r['existing_order_no']); ?><div><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$r['existing_order_id']; ?>">打开已有订单</a></div></td>
          <td class="small" style="max-width:320px"><?php echo nl2br(e($r['reason'])); ?><div class="mt-1"><a href="<?php echo BASE_URL; ?>/project/dup_appeals.php?file=<?php echo (int)$r['id']; ?>" target="_blank"><i class="fas fa-paperclip mr-1"></i><?php echo e($r['evidence_name']); ?></a></div></td>
          <td><span class="badge badge-<?php echo $lc; ?>"><?php echo $lt; ?></span><?php if ($r['handled_at']): ?><div class="small text-muted"><?php echo e($r['handled_by']); ?> · <?php echo e(substr($r['handled_at'], 0, 16)); ?><?php echo $r['handled_note'] ? '<br>' . e($r['handled_note']) : ''; ?></div><?php endif; ?></td>
          <td class="text-nowrap"><?php if ($isFinance && $r['status'] === 'pending'): ?>
            <form method="post" class="form-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="do" value="handle"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
              <input name="note" class="form-control form-control-sm mr-1" placeholder="备注（驳回必填）" style="width:150px">
              <button name="decision" value="approved" class="btn btn-sm btn-success mr-1" onclick="return confirm('确认这是另一笔独立订单？通过后重新上传即可作为新订单导入。')">通过</button>
              <button name="decision" value="rejected" class="btn btn-sm btn-outline-danger">驳回</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-5">还没有申诉。导入时被判为重复的行，会在预览里出现“不是同一笔订单？去申诉”的入口。</td></tr><?php endif; ?>
      </tbody></table></div></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
