<?php
require_once __DIR__ . '/../includes/ProjectRefundImport.php';
$actor = ps_require_actor();
$isFinance = $actor['role'] === 'finance';
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="website-alipay-refunds.csv"');
    echo "\xEF\xBB\xBF";
    $csv = fopen('php://output', 'wb');
    fputcsv($csv, ['订单编号', '退款日期', '退款金额', '支付宝退款流水号', '退款原因', '退款方式']);
    fclose($csv); exit;
}
$error = ''; $success = ''; $reports = [];
$owner = 'finance:' . $actor['id'];
$preview = ($_SESSION['ps_refund_owner'] ?? '') === $owner ? ($_SESSION['ps_refund_preview'] ?? []) : [];
$fileId = ($_SESSION['ps_refund_owner'] ?? '') === $owner ? (int)($_SESSION['ps_refund_file'] ?? 0) : 0;
$month = (string)($_POST['month'] ?? date('Y-m'));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'preview_file') {
            $file = $_FILES['file'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) > 20 * 1024 * 1024) throw new RuntimeException('请选择不超过 20 MB 的 XLSX、XLS 或 CSV 退款表');
            $fileId = ps_import_file_store($file, '网站支付宝退款', $actor, $_FILES['parsed_file'] ?? null);
            [$preview, $reports] = ps_refund_parse_file(ps_import_file_get($fileId, $actor), $actor);
            ps_import_file_mark($fileId, 'preview', ['sheets_used' => mb_substr(implode('、', array_keys($reports)), 0, 500), 'rows_total' => count($preview)]);
            $_SESSION['ps_refund_preview'] = $preview; $_SESSION['ps_refund_file'] = $fileId; $_SESSION['ps_refund_owner'] = $owner;
            $_SESSION['ps_refund_ai_touched'] = ps_ai_touched();
        } elseif ($action === 'preview_manual') {
            $input = [
                'order_no' => $_POST['order_no'] ?? '', 'refund_date' => $_POST['refund_date'] ?? '',
                'amount' => $_POST['amount'] ?? '', 'reference' => $_POST['reference'] ?? '',
                'reason' => $_POST['reason'] ?? '', 'method' => '支付宝',
            ];
            if (trim((string)$input['reference']) === '') throw new RuntimeException('手动登记须填写支付宝退款流水号，以免重复扣回');
            $fingerprint = hash('sha256', 'alipay-ref|' . trim((string)$input['order_no']) . '|' . trim((string)$input['reference']));
            $preview = [ps_refund_preview_row($input, $fingerprint, $actor)]; $fileId = 0;
            $_SESSION['ps_refund_preview'] = $preview; $_SESSION['ps_refund_file'] = 0; $_SESSION['ps_refund_owner'] = $owner; $_SESSION['ps_refund_ai_touched'] = [];
        } elseif ($action === 'commit') {
            if (!$preview) throw new RuntimeException('预览已失效，请重新上传或录入');
            $selected = array_map('strval', (array)($_POST['rows'] ?? []));
            $done = ps_refund_commit_rows($preview, $selected, $actor, $month);
            ps_ai_mark_applied($_SESSION['ps_refund_ai_touched'] ?? []);
            if ($fileId) ps_import_file_mark($fileId, 'imported', ['imported_count' => $done, 'skipped_count' => count($preview) - $done]);
            unset($_SESSION['ps_refund_preview'], $_SESSION['ps_refund_file'], $_SESSION['ps_refund_owner'], $_SESSION['ps_refund_ai_touched']);
            $preview = []; $success = $isFinance ? '已登记 ' . $done . ' 笔退款；有流水号且无疑点的匹配记录自动审核，其余待财务逐笔核实。' : '已提交 ' . $done . ' 笔支付宝退款，等待财务核对。审核前不会扣减订单或项目报酬。';
        } elseif ($action === 'review') {
            if (!$isFinance) throw new RuntimeException('仅财务可审核');
            ps_refund_review((int)($_POST['refund_id'] ?? 0), (string)($_POST['decision'] ?? ''), $actor, $month, false, (string)($_POST['order_no'] ?? ''));
            $success = '退款审核已完成';
        } else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$page_title = '网站支付宝退款';
$q = $isFinance
    ? db()->query("SELECT r.*,o.project_type FROM project_refund_import_rows r LEFT JOIN project_orders o ON o.id=r.order_id ORDER BY (r.review_status='pending') DESC,r.id DESC LIMIT 100")
    : db()->prepare('SELECT r.*,o.project_type FROM project_refund_import_rows r LEFT JOIN project_orders o ON o.id=r.order_id WHERE r.submitted_by_type=? AND r.submitted_by_id=? ORDER BY r.id DESC LIMIT 50');
if (!$isFinance) $q->execute([$actor['type'], $actor['id']]);
$recent = $q->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 售后退款</div><h2>网站支付宝退款</h2><p>上传不通过淘宝店铺、由支付宝退给客户的记录。按原订单号核对，不新建负售价订单；<?php echo $isFinance ? '财务确认后才计入退款与分成调整。' : '提交后由财务审核，审核前不改变项目结算。'; ?></p></div><div class="project-hero-actions"><a class="btn btn-light" href="<?php echo BASE_URL; ?>/project/index.php">查看项目订单</a></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
<div class="row"><div class="col-lg-6 mb-3"><div class="card project-form-card h-100"><div class="card-body"><div class="project-section-title"><span class="project-step">01</span><div><h5>上传已有退款表</h5><p>支持 XLSX / XLS / CSV，兼容退款部“店铺订单号、退款金额、退款类型、店铺+业务”原表；先预览，再勾选确认。</p></div></div>
<form method="post" enctype="multipart/form-data" id="refundUploadForm" data-legacy-xls-upload><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="preview_file"><input type="file" name="parsed_file" hidden><label for="refundFile" class="project-drop-zone" id="refundDropZone"><i class="fas fa-cloud-upload-alt"></i><strong>拖拽退款表到这里，或点击选择</strong><span id="refundFileName">尚未选择文件</span><input type="file" name="file" id="refundFile" accept=".xlsx,.xls,.csv" required></label><button type="submit" class="btn btn-success mt-3">上传并核对</button> <a class="btn btn-link mt-3" href="?template=1">下载标准表头</a><small class="d-block text-muted mt-2" data-xls-status>旧版 XLS 可直接上传，原件会保留。</small></form>
<small class="text-muted d-block mt-2">原表“退款类型”须写支付宝；其他渠道与非网站业务会在预览中排除，不影响原文件留档。</small></div></div></div>
<div class="col-lg-6 mb-3"><div class="card project-form-card h-100"><div class="card-body"><div class="project-section-title"><span class="project-step">02</span><div><h5>单笔手动提交</h5><p>零星退款无需整理 Excel；填写支付宝流水号，系统会防止重复登记。</p></div></div>
<form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="preview_manual"><div class="form-row"><div class="form-group col-sm-6"><label>原订单号</label><input class="form-control" name="order_no" required></div><div class="form-group col-sm-6"><label>退款日期</label><input class="form-control" name="refund_date" type="date" value="<?php echo e(date('Y-m-d')); ?>" required></div><div class="form-group col-sm-6"><label>退款金额</label><input class="form-control" name="amount" type="number" step="0.01" min="0.01" required></div><div class="form-group col-sm-6"><label>支付宝退款流水号</label><input class="form-control" name="reference" required></div></div><div class="form-group"><label>退款原因 / 备注</label><input class="form-control" name="reason" maxlength="240"></div><button class="btn btn-outline-success">核对这笔退款</button></form></div></div></div></div>
<?php if ($reports): ?><div class="alert alert-info"><?php foreach ($reports as $sheet => $message): ?><div><?php echo e($sheet . '：' . $message); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($preview): $ready = count(array_filter($preview, function ($r) { return $r['error'] === ''; })); ?>
<form method="post" class="card project-form-card mb-3"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="commit"><div class="card-body"><div class="project-section-title"><span class="project-step">03</span><div><h5>核对并确认</h5><p><?php echo count($preview); ?> 笔中 <?php echo $ready; ?> 笔可提交。勾选需要登记的行；已提交过的流水不会重复扣回。</p></div></div><?php if ($isFinance): ?><label>分成调整计入月份 <input type="month" class="form-control d-inline-block ml-2" style="width:auto" name="month" value="<?php echo e($month); ?>" required></label><small class="text-muted ml-2">已审核订单按原规则重算分成；已锁定月份不可修改。</small><?php else: ?><input type="hidden" name="month" value="<?php echo e($month); ?>"><small class="text-muted">上传后进入财务待审，暂不影响订单金额或项目报酬。</small><?php endif; ?></div><div class="table-responsive"><table class="table project-preview-table mb-0"><thead><tr><th>选择</th><th>来源</th><th>原订单 / 业务</th><th>退款日期 / 金额</th><th>支付宝流水号 / 原因</th><th>核对</th></tr></thead><tbody><?php foreach ($preview as $i => $row): ?><tr class="<?php echo $row['error'] ? 'table-danger' : ''; ?>"><td><input type="checkbox" name="rows[]" value="<?php echo (int)$i; ?>" <?php echo $row['error'] ? 'disabled' : 'checked'; ?>></td><td><?php echo e($row['sheet'] ? $row['sheet'] . ' 第' . $row['line'] . '行' : '手动录入'); ?></td><td><?php if ($row['order_id'] && ps_refund_can_view_order($actor, $row['order_id'])): ?><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$row['order_id']; ?>"><?php echo e($row['order_no']); ?></a><?php else: ?><?php echo e($row['order_no'] ?: '—'); ?><?php endif; ?><br><small><?php echo e($row['business'] ?: '—'); ?></small></td><td><?php echo e($row['refund_date'] ?: '—'); ?><br><strong>¥<?php echo e($row['amount'] ?: '—'); ?></strong></td><td><small><?php echo e($row['reference'] ?: '无流水号（按原文件行防重）'); ?><br><?php echo e($row['reason'] ?: '支付宝线下退款'); ?></small></td><td><span class="badge badge-<?php echo $row['error'] ? 'danger' : 'success'; ?>"><?php echo e($row['status']); ?></span><?php if ($row['error']): ?><div class="small text-danger"><?php echo e($row['error']); ?></div><?php elseif ($isFinance && $row['order_id']): ?><div class="small text-muted">可退余额 ¥<?php echo e($row['available']); ?></div><?php else: ?><div class="small text-muted">等待财务核对实收与历史退款</div><?php endif; ?><?php if (!empty($row['warning'])): ?><div class="small text-warning"><?php echo e($row['warning']); ?></div><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><div class="card-body border-top"><button class="btn btn-success btn-lg" <?php echo $ready ? '' : 'disabled'; ?> onclick="return confirm('确认提交所选支付宝退款？<?php echo $isFinance ? '将直接登记并调整结算。' : '将进入财务审核，暂不调整结算。'; ?>')"><?php echo $isFinance ? '确认登记所选退款' : '提交财务审核'; ?></button><?php if ($fileId): ?> <a class="btn btn-link" href="<?php echo BASE_URL; ?>/project/files.php?view=<?php echo $fileId; ?>" target="_blank" rel="noopener">查看原始表格</a><?php endif; ?></div></form>
<?php endif; ?>
<div class="card project-form-card mb-3"><div class="card-body"><div class="project-section-title"><span class="project-step">04</span><div><h5><?php echo $isFinance ? '待审与最近退款' : '我的退款提交记录'; ?></h5><p><?php echo $isFinance ? '售后同事提交后在这里核对并审核；只有审核通过才更新订单和分成。' : '可查看财务处理状态；需要纠正订单号或渠道时联系财务。'; ?></p></div></div></div><div class="table-responsive"><table class="table project-preview-table mb-0"><thead><tr><th>订单 / 业务</th><th>退款日期 / 金额</th><th>支付宝流水号</th><th>状态 / 来源</th><?php if ($isFinance): ?><th>财务操作</th><?php endif; ?></tr></thead><tbody><?php foreach ($recent as $r): ?><tr><td><?php if ($isFinance && (int)$r['order_id'] > 0): ?><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$r['order_id']; ?>"><?php echo e($r['order_no']); ?></a><?php else: ?><?php echo e($r['order_no']); ?><?php endif; ?><br><small><?php echo e($r['project_type'] ?: '—'); ?></small></td><td><?php echo e($r['refund_date']); ?><br><strong>¥<?php echo e($r['amount']); ?></strong></td><td><?php echo e($r['payment_reference'] ?: '原文件行防重'); ?><br><small><?php echo e($r['reason']); ?></small></td><td><span class="badge badge-<?php echo $r['review_status'] === 'approved' ? 'success' : ($r['review_status'] === 'rejected' ? 'danger' : 'warning'); ?>"><?php echo e(['approved' => '已审核', 'rejected' => '未通过', 'pending' => '待财务审核'][$r['review_status']] ?? $r['review_status']); ?></span><br><small><?php echo e($r['source_sheet'] ? $r['source_sheet'] . ' 第' . $r['source_row'] . '行' : '手动录入'); ?></small></td><?php if ($isFinance): ?><td><?php if ($r['review_status'] === 'pending'): ?><form method="post" class="d-flex flex-wrap align-items-center" style="gap:6px" onsubmit="return confirm('确认审核这笔支付宝退款？通过后会更新原订单和项目分成。')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="review"><input type="hidden" name="refund_id" value="<?php echo (int)$r['id']; ?>"><input class="form-control form-control-sm" style="width:190px" name="order_no" value="<?php echo e($r['order_no']); ?>" aria-label="核对或修正原订单号" title="核对或修正原订单号"><input type="month" class="form-control form-control-sm" style="width:145px" name="month" value="<?php echo e($month); ?>" required><button class="btn btn-sm btn-success" name="decision" value="approved">通过</button><button class="btn btn-sm btn-outline-danger" name="decision" value="rejected">驳回</button></form><?php else: ?>—<?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?><?php if (!$recent): ?><tr><td colspan="<?php echo $isFinance ? 5 : 4; ?>" class="text-muted text-center">暂无退款提交记录</td></tr><?php endif; ?></tbody></table></div></div>
</div>
<script>(function(){var zone=document.getElementById('refundDropZone'),input=document.getElementById('refundFile'),label=document.getElementById('refundFileName');if(!zone||!input)return;input.addEventListener('change',function(){label.textContent=input.files.length?input.files[0].name:'尚未选择文件'});['dragenter','dragover'].forEach(function(n){zone.addEventListener(n,function(e){e.preventDefault();zone.classList.add('is-dragging')})});['dragleave','drop'].forEach(function(n){zone.addEventListener(n,function(e){e.preventDefault();zone.classList.remove('is-dragging')})});zone.addEventListener('drop',function(e){if(!e.dataTransfer.files.length)return;input.files=e.dataTransfer.files;label.textContent=input.files[0].name})})();</script>
<script src="<?php echo BASE_URL; ?>/assets/lib/xlsx.full.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/project-xls-upload.js"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
