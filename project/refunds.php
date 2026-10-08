<?php
require_once __DIR__ . '/../includes/ProjectRefundImport.php';
require_once __DIR__ . '/../includes/ProjectRefundMatch.php';
$actor = ps_require_actor();
$isFinance = $actor['role'] === 'finance';
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="project-refunds.csv"');
    echo "\xEF\xBB\xBF";
    $csv = fopen('php://output', 'wb');
    fputcsv($csv, ['原订单号', '原支付流水号', '退款日期', '退款金额', '退款方式', '退款流水号', '退款原因']);
    fclose($csv); exit;
}
$error = ''; $success = ''; $reports = [];
$owner = $actor['type'] . ':' . $actor['id'];
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
            $fileId = ps_import_file_store($file, '项目退款与返现', $actor, $_FILES['parsed_file'] ?? null);
            [$preview, $reports] = ps_refund_parse_file(ps_import_file_get($fileId, $actor), $actor);
            ps_import_file_mark($fileId, 'preview', ['sheets_used' => mb_substr(implode('、', array_keys($reports)), 0, 500), 'rows_total' => count($preview)]);
            $_SESSION['ps_refund_preview'] = $preview; $_SESSION['ps_refund_file'] = $fileId; $_SESSION['ps_refund_owner'] = $owner;
            $_SESSION['ps_refund_ai_touched'] = ps_ai_touched();
        } elseif ($action === 'preview_manual') {
            $input = [
                'order_no' => $_POST['order_no'] ?? '', 'refund_date' => $_POST['refund_date'] ?? '',
                'amount' => $_POST['amount'] ?? '', 'reference' => $_POST['reference'] ?? '',
                'source_reference' => $_POST['source_reference'] ?? '',
                'reason' => $_POST['reason'] ?? '', 'method' => $_POST['method'] ?? '',
            ];
            if (trim((string)$input['order_no']) === '' && trim((string)$input['source_reference']) === '' && trim((string)$input['reference']) === '') throw new RuntimeException('请至少填写原订单号、原支付流水号或退款流水号，方便财务核对');
            $preview = [ps_refund_preview_row($input, ps_refund_fingerprint($input), $actor)]; $fileId = 0;
            $_SESSION['ps_refund_preview'] = $preview; $_SESSION['ps_refund_file'] = 0; $_SESSION['ps_refund_owner'] = $owner; $_SESSION['ps_refund_ai_touched'] = [];
        } elseif ($action === 'commit') {
            if (!$preview) throw new RuntimeException('预览已失效，请重新上传或录入');
            $selected = array_map('strval', (array)($_POST['rows'] ?? []));
            foreach ($selected as $index) {
                if (!isset($preview[$index])) throw new RuntimeException('所选退款行已变化，请重新预览');
                $original = $preview[$index]; $fix = (array)(($_POST['fix'] ?? [])[$index] ?? []);
                $input = [
                    'order_no' => $fix['order_no'] ?? $original['order_no'],
                    'source_reference' => $fix['source_reference'] ?? ($original['source_reference'] ?? ''),
                    'refund_date' => $fix['refund_date'] ?? $original['refund_date'],
                    'amount' => $fix['amount'] ?? $original['amount'],
                    'method' => $fix['method'] ?? $original['method'],
                    'reference' => $fix['reference'] ?? $original['reference'],
                    'reason' => $original['reason'],
                    'business' => $original['source_business'] ?? $original['business'],
                    'completion' => $original['completion'] ?? '',
                    'payer_note' => $original['payer_note'] ?? '',
                ];
                $preview[$index] = ps_refund_preview_row($input, ps_refund_fingerprint($input), $actor, $original['file_id'], $original['sheet'], $original['line']);
            }
            $_SESSION['ps_refund_preview'] = $preview;
            $done = ps_refund_commit_rows($preview, $selected, $actor, $month);
            ps_ai_mark_applied($_SESSION['ps_refund_ai_touched'] ?? []);
            if ($fileId) ps_import_file_mark($fileId, 'imported', ['imported_count' => $done, 'skipped_count' => count($preview) - $done]);
            unset($_SESSION['ps_refund_preview'], $_SESSION['ps_refund_file'], $_SESSION['ps_refund_owner'], $_SESSION['ps_refund_ai_touched']);
            $preview = []; $success = '已登记 ' . $done . ' 笔退款线索，状态为待财务审核。核实原订单和渠道后才冲减实收、重算客服与技术分成；不会重复记入项目成本。';
        } elseif ($action === 'review') {
            if (!$isFinance) throw new RuntimeException('仅财务可审核');
            ps_refund_review((int)($_POST['refund_id'] ?? 0), (string)($_POST['decision'] ?? ''), $actor, $month, false, (string)($_POST['order_no'] ?? ''), (string)($_POST['source_reference'] ?? ''), (string)($_POST['method'] ?? ''), (string)($_POST['reference'] ?? ''));
            $success = '退款审核已完成';
        } elseif ($action === 'set_settled') {
            if (!$isFinance) throw new RuntimeException('仅财务可设置');
            $m = (string)($_POST['settled_month'] ?? '');
            if ($m !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) throw new RuntimeException('月份格式不正确');
            ps_setting_set('refund_settled_through', $m, (int)$actor['id']);
            ps_audit('refund_import', 0, 'set_settled_through', $actor, ['month' => $m]);
            $success = $m === '' ? '已取消“已核算月份”限制' : '已设置：' . $m . ' 及以前的退款只留档，不再自动扣减';
        } elseif ($action === 'trash_refund') {
            $trashResult = prt_change((int)($_POST['refund_id'] ?? 0), false, (string)($_POST['note'] ?? ''), $actor);
            prt_after_change($trashResult);
            $success = '已移入退款回收站，不再自动匹配或扣减。原始表格保留，财务可在回收站恢复。';
        } elseif ($action === 'match_refund') {
            $success = prm_resolve((int)($_POST['refund_id'] ?? 0), (string)($_POST['order_no'] ?? ''), (string)($_POST['mode'] ?? ''), (string)($_POST['note'] ?? ''), $actor);
        } else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$page_title = '项目退款与返现';
if ($isFinance) ps_refund_reconcile_pending($actor);
$q = $isFinance
    ? db()->query("SELECT r.*,o.project_type FROM project_refund_import_rows r LEFT JOIN project_orders o ON o.id=r.order_id WHERE 1=1" . prt_active_sql('r.') . " ORDER BY (r.review_status='pending') DESC,r.id DESC LIMIT 100")
    : db()->prepare('SELECT r.*,o.project_type FROM project_refund_import_rows r LEFT JOIN project_orders o ON o.id=r.order_id WHERE r.submitted_by_type=? AND r.submitted_by_id=?' . prt_active_sql('r.') . ' ORDER BY r.id DESC LIMIT 50');
if (!$isFinance) $q->execute([$actor['type'], $actor['id']]);
$recent = $q->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<div class="project-intake-page">
<?php if ($isFinance && prt_storage_available()): ?><div class="d-flex flex-wrap justify-content-between align-items-center mb-3" style="gap:8px"><small class="text-muted">误登记退款可移入回收站，随时恢复；已入账退款须财务更正，不直接删除。</small><a class="btn btn-outline-danger" href="<?php echo BASE_URL; ?>/project/refund_trash.php"><i class="fas fa-trash-restore mr-1" aria-hidden="true"></i>退款回收站 <span class="badge badge-light"><?php echo (int)db()->query('SELECT COUNT(*) FROM project_refund_import_rows WHERE deleted_at IS NOT NULL')->fetchColumn(); ?></span></a></div><?php endif; ?>
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 售后退款</div><h2>项目退款与返现</h2><p>淘宝店铺、支付宝、微信、银行卡退款都可登记。系统优先用原订单号或原支付流水关联客服与技术共用的订单；匹配不到也能先留待审。财务审核后冲减实收并按原业务规则重算分成，不重复计入成本。</p></div><div class="project-hero-actions"><a class="btn btn-light" href="<?php echo BASE_URL; ?>/project/index.php">查看项目订单</a></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
<?php if ($isFinance): $bd = ps_refund_pending_breakdown(); $settledM = ps_refund_settled_through(); ?>
<div class="card mb-3" style="border-color:#e3d9bd"><div class="card-body py-3 d-flex flex-wrap justify-content-between align-items-center" style="gap:10px">
  <div><strong><i class="fas fa-lock text-warning mr-1"></i> 已核算月份</strong><div class="small text-muted">该月及以前的退款只留档：不自动关联、不自动扣减，也不会冲进后面月份的分成（财务仍可在下方手动特批）。<?php if ($bd['history']): ?> 当前有 <strong><?php echo (int)$bd['history']; ?></strong> 笔历史退款留档。<?php endif; ?></div></div>
  <form method="post" class="form-inline" style="gap:8px"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="set_settled"><span class="small text-muted">已核算至</span><input type="month" class="form-control form-control-sm" name="settled_month" value="<?php echo e($settledM); ?>"><button class="btn btn-sm btn-outline-secondary">保存</button></form>
</div></div>
<?php if ($bd['total']): ?>
<div class="card mb-3" style="border-color:#bfdccb"><div class="card-body py-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center"><strong><i class="fas fa-magic text-success mr-1"></i> 退款自动扣减状态</strong><span class="small text-muted">待处理 <?php echo (int)$bd['total']; ?> 笔 · 合计 ¥<?php echo number_format($bd['amount'], 2); ?></span></div>
  <div class="small mt-2" style="line-height:1.9">
    <?php if ($bd['ready']): ?><div><span class="badge badge-success">可自动扣减 <?php echo (int)$bd['ready']; ?></span> 已对上订单，系统会自动从订单扣减（上传、导入订单后立即处理，另有每 30 分钟的定时任务）。</div><?php endif; ?>
    <?php if ($bd['no_order_in_shop']): ?><div><span class="badge badge-warning">没有对应项目订单 <?php echo (int)$bd['no_order_in_shop']; ?></span> 订单号在店铺订单流水里有，但项目系统里还没有这张订单——对应业务上传订单表格后会自动对上并扣减。</div><?php endif; ?>
    <?php if ($bd['no_order_unknown']): ?><div><span class="badge badge-secondary">查不到订单 <?php echo (int)$bd['no_order_unknown']; ?></span> 订单号在店铺流水和项目订单里都查不到（可能写成了昵称 / 微信号 / 其他编号），请核对原订单号或补原支付流水号。</div><?php endif; ?>
    <?php if ($bd['channel']): ?><div><span class="badge badge-info">渠道未写明 <?php echo (int)$bd['channel']; ?></span> 退款表里没写渠道的退款会照常自动扣减，记录上保留“待核渠道”标记，财务可随时补充（不影响金额）。</div><?php endif; ?>
    <?php if ($bd['blocked']): ?><div><span class="badge badge-danger">需财务处理 <?php echo (int)$bd['blocked']; ?></span> 原订单已审核（需选调整月份）或退款超过可退金额。</div><?php endif; ?>
  </div></div></div>
<?php endif; endif; ?>
<div class="row"><div class="col-lg-6 mb-3"><div class="card project-form-card h-100"><div class="card-body"><div class="project-section-title"><span class="project-step">01</span><div><h5>上传已有退款表</h5><p>支持 XLSX / XLS / CSV，兼容售后原表。可用原订单号或原支付流水定位；渠道和业务不确定的行先留待核。</p></div></div>
<form method="post" enctype="multipart/form-data" id="refundUploadForm" data-legacy-xls-upload><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="preview_file"><input type="file" name="parsed_file" hidden><label for="refundFile" class="project-drop-zone" id="refundDropZone"><i class="fas fa-cloud-upload-alt"></i><strong>拖拽退款表到这里，或点击选择</strong><span id="refundFileName">尚未选择文件</span><input type="file" name="file" id="refundFile" accept=".xlsx,.xls,.csv" required></label><button type="submit" class="btn btn-success mt-3">上传并核对</button> <a class="btn btn-link mt-3" href="?template=1">下载标准表头</a><small class="d-block text-muted mt-2" data-xls-status>旧版 XLS 可直接上传，原件会保留。</small></form>
<small class="text-muted d-block mt-2">原表会完整留档。只有退款日期或金额无效、或同笔退款已登记时才需修正；未匹配订单不会直接扣款。</small></div></div></div>
<div class="col-lg-6 mb-3"><div class="card project-form-card h-100"><div class="card-body"><div class="project-section-title"><span class="project-step">02</span><div><h5>单笔在线登记</h5><p>有原订单号填订单号；微信、支付宝或银行卡付款没有店铺单号，可填原支付流水。退款流水用于防重。</p></div></div>
<form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="preview_manual"><div class="form-row">
<div class="form-group col-sm-6"><label>原订单号（有则填）</label><input class="form-control" name="order_no" maxlength="100"></div>
<div class="form-group col-sm-6"><label>原支付流水号 / 支付订单号</label><input class="form-control" name="source_reference" maxlength="200"></div>
<div class="form-group col-sm-6"><label>退款日期</label><input class="form-control" name="refund_date" type="date" value="<?php echo e(date('Y-m-d')); ?>" required></div>
<div class="form-group col-sm-6"><label>退款金额</label><input class="form-control" name="amount" type="number" step="0.01" min="0.01" required></div>
<div class="form-group col-sm-6"><label>退款渠道</label><select class="form-control" name="method"><option value="支付宝">支付宝</option><option value="微信">微信</option><option value="银行卡">银行卡</option><option value="店铺">淘宝 / 店铺</option><option value="待核渠道">待确认</option></select></div>
<div class="form-group col-sm-6"><label>退款流水号（可后补）</label><input class="form-control" name="reference" maxlength="150"></div></div>
<div class="form-group"><label>退款原因 / 备注</label><input class="form-control" name="reason" maxlength="240"></div><button class="btn btn-outline-success">核对这笔退款</button></form></div></div></div></div>
<?php if ($reports): ?><div class="alert alert-info"><?php foreach ($reports as $sheet => $message): ?><div><?php echo e($sheet . '：' . $message); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($preview): $ready = count(array_filter($preview, function ($r) { return $r['error'] === ''; })); ?>
<form method="post" class="card project-form-card mb-3">
<input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="commit">
<div class="card-body"><div class="project-section-title"><span class="project-step">03</span><div><h5>核对并登记</h5><p><?php echo count($preview); ?> 笔中 <?php echo $ready; ?> 笔资料可直接登记。黄色提示可先留待核；红色行修正后再勾选。登记本身不扣款。</p></div></div>
<?php if ($isFinance): ?><label>分成调整计入月份 <input type="month" class="form-control d-inline-block ml-2" style="width:auto" name="month" value="<?php echo e($month); ?>" required></label><small class="text-muted ml-2">仅在财务审核通过时使用，已锁定月份不可修改。</small><?php else: ?><input type="hidden" name="month" value="<?php echo e($month); ?>"><?php endif; ?></div>
<div class="table-responsive"><table class="table project-preview-table mb-0"><thead><tr><th>选择</th><th>来源</th><th>原订单 / 原支付流水</th><th>日期 / 金额</th><th>退款渠道 / 流水</th><th>核对结果</th></tr></thead><tbody>
<?php foreach ($preview as $i => $row): ?><tr class="<?php echo $row['error'] ? 'table-danger' : ($row['warning'] ? 'table-warning' : ''); ?>">
<td><input type="checkbox" name="rows[]" value="<?php echo (int)$i; ?>" <?php echo $row['error'] ? '' : 'checked'; ?> aria-label="选择第<?php echo (int)($row['line'] ?: $i + 1); ?>行退款"></td>
<td><?php echo e($row['sheet'] ? $row['sheet'] . ' 第' . $row['line'] . '行' : '手动录入'); ?></td>
<td><input class="form-control form-control-sm mb-1" name="fix[<?php echo (int)$i; ?>][order_no]" maxlength="100" value="<?php echo e($row['order_no']); ?>" placeholder="原订单号" aria-label="原订单号">
<input class="form-control form-control-sm" name="fix[<?php echo (int)$i; ?>][source_reference]" maxlength="200" value="<?php echo e($row['source_reference'] ?? ''); ?>" placeholder="或原支付流水号" aria-label="原支付流水号">
<small><?php echo e($row['business'] ?: '待匹配业务'); ?></small><?php if ($row['order_id'] && ps_refund_can_view_order($actor, $row['order_id'])): ?> · <a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$row['order_id']; ?>">打开原订单</a><?php endif; ?></td>
<td><input class="form-control form-control-sm mb-1" type="date" name="fix[<?php echo (int)$i; ?>][refund_date]" value="<?php echo e($row['refund_date'] ?: ''); ?>" aria-label="退款日期"><input class="form-control form-control-sm" type="number" step="0.01" min="0.01" name="fix[<?php echo (int)$i; ?>][amount]" value="<?php echo e($row['amount'] ?: ''); ?>" placeholder="退款金额" aria-label="退款金额"></td>
<td><select class="form-control form-control-sm mb-1" name="fix[<?php echo (int)$i; ?>][method]" aria-label="退款渠道"><?php foreach (['待核渠道','店铺','支付宝','微信','银行卡'] as $methodOption): ?><option value="<?php echo e($methodOption); ?>" <?php echo $row['method'] === $methodOption ? 'selected' : ''; ?>><?php echo e($methodOption === '店铺' ? '淘宝 / 店铺' : $methodOption); ?></option><?php endforeach; ?></select><input class="form-control form-control-sm" name="fix[<?php echo (int)$i; ?>][reference]" maxlength="150" value="<?php echo e($row['reference']); ?>" placeholder="退款流水号，可后补" aria-label="退款流水号"><small><?php echo e($row['reason'] ?: '—'); ?></small></td>
<td><span class="badge badge-<?php echo $row['error'] ? 'danger' : ($row['warning'] ? 'warning' : 'success'); ?>"><?php echo e($row['status']); ?></span><?php if ($row['error']): ?><div class="small text-danger"><?php echo e($row['error']); ?></div><?php endif; ?><?php if ($isFinance && $row['available'] !== '—'): ?><div class="small">可退余额 ¥<?php echo e($row['available']); ?></div><?php endif; ?><?php if (!empty($row['warning'])): ?><div class="small text-warning"><?php echo e($row['warning']); ?></div><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><div class="card-body border-top"><button class="btn btn-success btn-lg" onclick="return confirm('确认登记所选退款线索？财务审核通过前不会冲减订单或项目报酬。')">登记所选退款</button><?php if ($fileId): ?> <a class="btn btn-link" href="<?php echo BASE_URL; ?>/project/files.php?view=<?php echo $fileId; ?>" target="_blank" rel="noopener">查看原始表格</a><?php endif; ?></div></form>
<?php endif; ?>
<?php if (ps_refund_after_sales($actor)): $unmatched = prm_pending_unmatched(60); if ($unmatched): ?>
<div class="card project-form-card mb-3" id="unmatchedRefunds"><div class="card-body"><div class="project-section-title"><span class="project-step">!</span><div><h5>待对号退款 <span class="badge badge-warning"><?php echo count($unmatched); ?></span></h5><p>只列需要人判断的：退款表里写成客户ID、客户名或别的编号，系统没法自己确定订单。已按客户ID找出候选订单，请售后确认后一键处理。</p></div></div></div>
<div class="table-responsive"><table class="table project-preview-table mb-0"><thead><tr><th>退款表原文</th><th>退款日期 / 金额</th><th>候选订单与处理</th></tr></thead><tbody>
<?php foreach ($unmatched as $u): ?><tr><td><strong><?php echo e($u['order_no']); ?></strong><br><small><?php echo e($u['payment_method']); ?><?php echo $u['reason'] !== '' ? ' · ' . e($u['reason']) : ''; ?></small></td><td><?php echo e($u['refund_date']); ?><br><strong>¥<?php echo e($u['amount']); ?></strong></td>
<td><form method="post" class="d-flex flex-wrap align-items-center" style="gap:6px" onsubmit="return confirm('确认按所选方式处理这笔退款？操作会留痕。')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="match_refund"><input type="hidden" name="refund_id" value="<?php echo (int)$u['id']; ?>">
<?php if ($u['candidates']): ?><select class="form-control form-control-sm" style="max-width:340px" name="order_no" aria-label="候选订单"><?php foreach ($u['candidates'] as $c): ?><option value="<?php echo e($c['order_no']); ?>"><?php echo e($c['order_no'] . ' · ¥' . $c['contract_amount'] . ' · ' . $c['order_date'] . ($c['shop'] !== '' ? ' · ' . $c['shop'] : '')); ?></option><?php endforeach; ?></select>
<?php else: ?><input class="form-control form-control-sm" style="width:230px" name="order_no" placeholder="查不到候选，填项目订单号" aria-label="项目订单号"><?php endif; ?>
<select class="form-control form-control-sm" style="width:210px" name="mode" aria-label="处理方式"><option value="deduct" <?php echo $u['candidates'] && strpos($u['candidates'][0]['hint'], '重复付款') !== false ? '' : 'selected'; ?>>订单被退款：扣减订单</option><option value="duplicate" <?php echo $u['candidates'] && strpos($u['candidates'][0]['hint'], '重复付款') !== false ? 'selected' : ''; ?>>重复付款已退回：不扣订单</option><option value="unreported">客服未报单：无对应订单</option></select>
<input class="form-control form-control-sm" style="width:180px" name="note" placeholder="备注（可选）" aria-label="备注"><button class="btn btn-sm btn-success">确认处理</button></form>
<?php $refundTrashRow = $u; include __DIR__ . '/../includes/refund_trash_action.php'; ?>
<?php if ($u['candidates']): ?><div class="small text-muted mt-1"><?php echo e($u['candidates'][0]['hint']); ?></div><?php if (count($u['candidates']) > 1): ?><div class="small text-muted">该客户ID共 <?php echo count($u['candidates']); ?> 张订单，请核对后选择。</div><?php endif; ?><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; $waiting = prm_waiting_upload(); if ($waiting): $waitN = 0; $waitAmt = 0; foreach ($waiting as $g) { $waitN += count($g['rows']); $waitAmt += $g['amount']; } ?>
<div class="card project-form-card mb-3" id="waitingRefunds"><div class="card-body"><div class="project-section-title"><span class="project-step"><i class="fas fa-hourglass-half"></i></span><div><h5>等待订单上传 <span class="badge badge-secondary"><?php echo $waitN; ?> 笔 · ¥<?php echo number_format($waitAmt, 2); ?></span></h5><p>这些退款的订单号格式正常，只是项目系统里还没有对应订单。<strong>无需处理</strong>——对应业务上传订单后，系统会自动对上并扣减。可提醒下面各店铺 / 业务的同事尽快补传。</p></div></div></div>
<div class="px-3 pb-3"><?php foreach ($waiting as $shop => $g): ?><details class="mb-2"><summary><strong><?php echo e($shop); ?></strong> <span class="text-muted small">· <?php echo count($g['rows']); ?> 笔 · ¥<?php echo number_format($g['amount'], 2); ?></span></summary><div class="small text-muted pl-3 pt-1" style="line-height:1.8"><?php foreach ($g['rows'] as $w): ?><span class="d-inline-block mr-3"><?php echo e($w['order_no']); ?> <b>¥<?php echo e($w['amount']); ?></b> <?php echo e(substr($w['refund_date'], 5)); ?> <form method="post" class="d-inline" onsubmit="return confirm('确认这笔退款对应的订单客服没有报单（系统里没有该订单）？将记录并结案，不扣分成。')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="match_refund"><input type="hidden" name="refund_id" value="<?php echo (int)$w['id']; ?>"><input type="hidden" name="mode" value="unreported"><input type="hidden" name="order_no" value="<?php echo e($w['order_no']); ?>"><button class="btn btn-link btn-sm p-0 align-baseline" type="submit">标记未报单</button></form></span><?php endforeach; ?></div></details><?php endforeach; ?></div></div>
<?php endif; endif; ?>
<div class="card project-form-card mb-3"><div class="card-body"><div class="project-section-title"><span class="project-step">04</span><div><h5><?php echo $isFinance ? '待审与最近退款' : '我的退款提交记录'; ?></h5><p><?php echo $isFinance ? '核实原订单、原支付流水、退款渠道和可退余额后再通过；通过后原订单客服和技术按业务算法同步调整分成。' : '这里可以查看财务处理状态；待审不会改变项目报酬。'; ?></p></div></div></div>
<div class="table-responsive"><table class="table project-preview-table mb-0"><thead><tr><th>原订单 / 业务</th><th>退款日期 / 金额</th><th>渠道与交易流水</th><th>状态 / 来源</th><?php if ($isFinance): ?><th>财务核对</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($recent as $r): ?><tr><td><?php if ($isFinance && (int)$r['order_id'] > 0): ?><a href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$r['order_id']; ?>"><?php echo e($r['order_no']); ?></a><?php else: ?><?php echo e($r['order_no'] ?: '待匹配'); ?><?php endif; ?><br><small><?php echo e($r['project_type'] ?: '待匹配业务'); ?></small></td><td><?php echo e($r['refund_date']); ?><br><strong>¥<?php echo e($r['amount']); ?></strong></td><td><?php echo e($r['payment_method']); ?> · <?php echo e($r['payment_reference'] ?: '无退款流水号'); ?><br><small>原支付流水：<?php echo e($r['source_payment_reference'] ?: '—'); ?></small><br><small><?php echo e($r['reason']); ?></small></td><td><span class="badge badge-<?php echo $r['review_status'] === 'approved' ? 'success' : ($r['review_status'] === 'rejected' ? 'danger' : 'warning'); ?>"><?php echo e(['approved' => '已审核', 'rejected' => '未通过', 'pending' => '待财务审核'][$r['review_status']] ?? $r['review_status']); ?></span><br><small><?php echo e($r['source_sheet'] ? $r['source_sheet'] . ' 第' . $r['source_row'] . '行' : '手动录入'); ?></small></td>
<?php if ($isFinance): ?><td><?php if ($r['review_status'] === 'pending'): ?><form method="post" class="d-flex flex-wrap align-items-center" style="gap:6px" onsubmit="return confirm('核实渠道、流水号、原订单和可退余额后通过？这会冲减原订单实收并重算项目分成。')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="review"><input type="hidden" name="refund_id" value="<?php echo (int)$r['id']; ?>">
<input class="form-control form-control-sm" style="width:160px" name="order_no" value="<?php echo e($r['order_no']); ?>" placeholder="原订单号" aria-label="核对或修正原订单号">
<input class="form-control form-control-sm" style="width:170px" name="source_reference" value="<?php echo e($r['source_payment_reference']); ?>" placeholder="或原支付流水" aria-label="原支付流水号">
<select class="form-control form-control-sm" style="width:115px" name="method" aria-label="退款渠道"><?php foreach (['待核渠道','店铺','支付宝','微信','银行卡'] as $methodOption): ?><option value="<?php echo e($methodOption); ?>" <?php echo $r['payment_method'] === $methodOption ? 'selected' : ''; ?>><?php echo e($methodOption); ?></option><?php endforeach; ?></select>
<input class="form-control form-control-sm" style="width:155px" name="reference" value="<?php echo e($r['payment_reference']); ?>" placeholder="退款流水号" aria-label="退款流水号">
<input type="month" class="form-control form-control-sm" style="width:145px" name="month" value="<?php echo e($month); ?>" required><button class="btn btn-sm btn-success" name="decision" value="approved">核实并通过</button><button class="btn btn-sm btn-outline-danger" name="decision" value="rejected">驳回</button></form><?php else: ?>—<?php endif; ?>
<?php $refundTrashRow = $r; include __DIR__ . '/../includes/refund_trash_action.php'; ?>
</td><?php endif; ?></tr><?php endforeach; ?><?php if (!$recent): ?><tr><td colspan="<?php echo $isFinance ? 5 : 4; ?>" class="text-muted text-center">暂无退款提交记录</td></tr><?php endif; ?></tbody></table></div></div>
</div>
<script>(function(){var zone=document.getElementById('refundDropZone'),input=document.getElementById('refundFile'),label=document.getElementById('refundFileName');if(!zone||!input)return;input.addEventListener('change',function(){label.textContent=input.files.length?input.files[0].name:'尚未选择文件'});['dragenter','dragover'].forEach(function(n){zone.addEventListener(n,function(e){e.preventDefault();zone.classList.add('is-dragging')})});['dragleave','drop'].forEach(function(n){zone.addEventListener(n,function(e){e.preventDefault();zone.classList.remove('is-dragging')})});zone.addEventListener('drop',function(e){if(!e.dataTransfer.files.length)return;input.files=e.dataTransfer.files;label.textContent=input.files[0].name})})();</script>
<script src="<?php echo BASE_URL; ?>/assets/lib/xlsx.full.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/project-xls-upload.js"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
