<?php
include (dirname(__DIR__, 1)) . '/../includes/header.php';
?>
<style><?php /* split: assets/css/project_import_1.css */ include __DIR__ . '/../../assets/css/project_import_1.css'; ?></style>
<?php
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · <?php echo $departmentMode ? '网站售后部门订单' : '批量录入'; ?></div><h2><?php
    echo $departmentMode ? '上传网站售后部门订单' : '导入' . e($selectedBusiness ?: '项目') . '订单'; ?></h2><p><?php echo $departmentMode ? '按网站续费、网站修改或备案模板上传，表格中的参与人逐单匹配；未写姓名时可选择本批默认参与人。部门代录不要求上传人参与每一单，实收仍由财务确认。'
    : '先选业务模板，再拖入 Excel 逐行核对。已关联人员上传相同订单号时补充原单，不会重复建单；售价不直接作为实收。'; ?></p></div><div class="project-hero-actions"><a class="btn btn-light" href="<?php
    echo BASE_URL; ?>/project/index.php?business=<?php echo rawurlencode($selectedBusiness ?: ''); ?>">返回订单录入</a></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($importReport): ?><div class="alert alert-<?php echo $importReport['pending'] ? 'warning' : 'success'; ?>">本次已写入 / 补充 <?php echo (int)$importReport['written'
    ]; ?> 单，已在库 <?php echo (int)$importReport['existing']; ?> 单<?php echo $importReport['pending'] ? '；还有 ' . (int)$importReport['pending'] . ' 行待补全，其他订单已成功保存'
    : '，核对已完成'; ?>。实收仍须财务确认。<a class="btn btn-sm btn-success ml-2" href="<?php echo BASE_URL; ?>/project/index.php?import_file=<?php echo $resultFileId
    ; ?>">查看这份表格对应订单</a></div><?php elseif ($imported): ?><div class="alert alert-success">已导入 <?php echo $imported; ?> 个订单。实收仍须财务确认。</div><?php
    endif; ?>
<?php if ($resumeFile): ?><div class="card mb-3"><div class="card-body"><strong>继续核对：<?php echo e($resumeFile['original_name']); ?></strong><p class="small text-muted mt-2">直接读取已保存原件，无需重传。按原上传人的业务和参与关系导入，已审核订单保持不变。</p><form method="post"><input type="hidden" name="csrf" value="<?php
    echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="repreview"><input type="hidden" name="resume_file" value="<?php echo $resumeFileId; ?>"><input type="hidden" name="file_id" value="<?php
    echo $resumeFileId; ?>"><input type="hidden" name="business" value="<?php echo e($selectedBusiness); ?>"><input type="hidden" name="scope" value="<?php echo e($scope); ?>"><input type="hidden" name="all_sheets" value="1"><input type="hidden" name="auto_import" value="1"><button class="btn btn-success">核对并导入有效订单</button></form></div></div><?php
    endif; ?>
<?php if (!$selectedBusiness): ?><div class="alert alert-warning">当前账户尚未分配业务，请联系财务配置。</div><?php else: ?>
<div class="card project-form-card mb-3"><div class="card-body"><div class="project-section-title"><span class="project-step">01</span><div><h5><?php echo $departmentMode ? '部门订单 · 批量上传'
    : '上传订单表'; ?></h5><p><?php echo $departmentMode ? '支持 .xlsx / .xls / .csv，最多 1500 行、20 MB；只允许网站售后部成员及财务代录。' : '支持 .xlsx / .xls / .csv，最多 1500 行、20 MB。技术和客服只能导入写有本人参与的订单；网站客服新单须指定接单技术。'
    ; ?></p></div></div>
<?php if ($departmentBusinesses): ?><div class="mb-3"><a class="btn btn-sm <?php echo $departmentMode ? 'btn-outline-secondary' : 'btn-success'; ?>" href="?business=<?php echo rawurlencode
    ($departmentMode ? $selectedBusiness : ($departmentBusinesses[0] ?? '网站续费')); ?>&scope=<?php echo $departmentMode ? 'personal' : 'department'; ?>"><?php echo $departmentMode
    ? '返回个人订单导入' : '切换到网站售后部门订单'; ?></a></div><?php endif; ?>
<?php /* split: project/import/view/render_15.php */ include __DIR__ . '/view/render_15.php'; ?><form method="post" enctype="multipart/form-data" id="projectUploadForm" data-legacy-xls-upload><input type="hidden" name="csrf" value="<?php
    echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="preview"><input type="hidden" name="business" value="<?php echo e($selectedBusiness); ?>"><input type="hidden" name="scope" value="<?php
    echo $departmentMode ? 'department' : 'personal'; ?>"><input type="hidden" name="rule_month" value="<?php echo e($ruleMonth); ?>">
<?php if ($departmentMode): ?>
<div class="p-3 mb-3" style="background:#f0f7f2;border:1px solid #d8eadc;border-radius:14px">
  <strong>本批默认参与人</strong>
  <div class="small text-muted mb-2">表格有客服 / 技术姓名时按每行姓名归属；没有姓名时由下方所选人员共同分单，逐单报酬默认等权。网站续费的部门共享比例独立按规则中心计算，选择参与人不会重复分配部门共享提成。</div>
  <div class="d-flex flex-wrap" style="gap:8px 18px"><?php foreach ($departmentChoices as $person): ?><label class="mb-0"><input type="checkbox" name="dept_people[]" value="<?php echo
    (int)$person['id']; ?>" <?php echo isset($departmentDefaults[(int)$person['id']]) ? 'checked' : ''; ?>> <?php echo e($person['name']); ?><?php if (isset($renewalRates[(int)$person
    ['id']])): ?> <small class="text-success">续费共享 <?php echo e(rtrim(rtrim(number_format($renewalRates[(int)$person['id']] * 100, 4), '0'), '.')); ?>%</small><?php endif;
    ?></label><?php endforeach; ?></div>
  <div class="small text-muted mt-2">显示的是 <?php echo e($ruleMonth); ?> 生效规则；<?php if ($actor['role'] === 'finance'): ?>修改比例请到 <a href="<?php echo BASE_URL
    ; ?>/project/rules.php">规则中心</a><?php else: ?>比例由财务在规则中心维护<?php endif; ?>。实际结算按结算月份生效规则计算。</div>
</div>
<?php endif; ?>
<input type="file" name="parsed_file" hidden><label for="projectImportFile" id="projectDropZone" class="project-drop-zone"><i class="fas fa-cloud-upload-alt"></i><strong>拖拽 Excel 到这里，或点击选择文件</strong><span id="projectFileName">尚未选择文件</span><input type="file" id="projectImportFile" name="file" accept=".xlsx,.xls,.csv" required></label><label class="d-block mt-3"><input type="checkbox" name="auto_import" value="1" checked> 核对通过的订单直接导入，问题行保留待补全</label><button class="btn btn-success btn-lg mt-2" type="submit">上传并导入有效订单</button><small class="d-block text-muted mt-2" data-xls-status>旧版 XLS 可直接上传，原件会保留。取消上方勾选可先预览、不导入。</small></form></div></div>
<?php endif; ?>
<?php
$previewSheets = $preview ? array_filter($_SESSION['project_import_sheets'] ?? [], function ($i) { return !empty($i['used']); }) : [];
$sheetReport = $preview ? ($_SESSION['project_import_sheets'] ?? []) : [];
$previewFileId = $preview ? (int)($_SESSION['project_import_file'] ?? 0) : 0;
?>
<?php if ($fixGuides): ?>
<div class="modal fade" id="importFixGuide" tabindex="-1" role="dialog" aria-labelledby="importFixGuideTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable" role="document"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title" id="importFixGuideTitle"><i class="fas fa-hand-point-right text-warning mr-1"></i>表格有 <?php echo count($fixGuides); ?> 处填写需要改一下</h5><button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button></div>
<div class="modal-body"><p class="text-muted small mb-3">系统按下面的写法才能自动识别。改好原表后重新上传即可；缺日期、缺订单号的行也可以直接在预览表格里补填。<?php
    echo $baseValidCount ? '已通过的 ' . $baseValidCount . ' 行可先导入，不受影响。' : ''; ?></p>
<?php foreach ($fixGuides as $guide): $guideLines = array_values(array_unique($guide['lines'])); ?><div class="p-3 mb-2" style="background:#fff8e6;border:1px solid #f3dca4;border-radius:12px"><strong><?php
    echo e($guide['title']); ?></strong><?php if ($guideLines): ?> <span class="badge badge-warning"><?php echo count($guideLines); ?> 行</span> <small class="text-muted">第 <?php
    echo e(implode('、', array_slice($guideLines, 0, 10))) . (count($guideLines) > 10 ? ' 等' : ''); ?> 行</small><?php endif; ?><div class="mt-1"><?php echo e($guide['how']); ?></div><?php
    if ($guide['example'] !== ''): ?><div class="mt-1 small">正确示例：<code style="font-size:.95em"><?php echo e($guide['example']); ?></code></div><?php endif; ?></div><?php
    endforeach; ?>
</div>
<div class="modal-footer"><?php if ($templateUrl): ?><a class="btn btn-outline-success" href="<?php echo e($templateUrl); ?>"><i class="fas fa-download mr-1"></i>下载“<?php echo
    e($selectedBusiness); ?>”正确模板（含示例行）</a><?php endif; ?><button type="button" class="btn btn-primary" data-dismiss="modal">我知道了</button></div>
</div></div></div>
<script><?php /* split: assets/js/project_import_2.js */ include __DIR__ . '/../../assets/js/project_import_2.js'; ?></script>
<?php endif; ?>
<?php if ($businessDetectionNote): ?><div class="alert alert-info"><?php echo e($businessDetectionNote); ?></div><?php endif; ?>
<?php if (count($allowedBusinesses) > 1 && ($previewFileId || $retryFileId)): ?>
<form method="post" class="card project-form-card mb-3"><div class="card-body form-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="repreview"><input type="hidden" name="all_sheets" value="1"><input type="hidden" name="file_id" value="<?php
    echo $previewFileId ?: $retryFileId; ?>"><label class="mr-2" for="previewBusiness">这张表实际属于</label><select class="form-control mr-2" name="business" id="previewBusiness"><?php
    foreach ($allowedBusinesses as $businessName): ?><option value="<?php echo e($businessName); ?>" <?php echo $businessName === $selectedBusiness ? 'selected' : ''; ?>><?php echo
    e($businessName); ?></option><?php endforeach; ?></select><button class="btn btn-outline-primary" type="submit">按此业务重新核对</button><small class="text-muted ml-2">归属不对时切换一次，不用重传；导入成功后会记住同版式。</small></div></form>
<?php endif; ?>
<?php if ($preview && count($sheetReport) > 1): ?>
<form method="post" class="card project-form-card mb-3"><div class="card-body"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="repreview"><input type="hidden" name="business" value="<?php
    echo e($selectedBusiness); ?>"><input type="hidden" name="file_id" value="<?php echo $previewFileId; ?>">
<div class="project-mini-title mb-2">这个表格有 <?php echo count($sheetReport); ?> 张工作表 <small>表头能对上“<?php echo e($selectedBusiness); ?>”的已自动勾选；取消不需要的分表后点“重新预览”，不用重新上传。</small></div>
<div class="d-flex flex-wrap" style="gap:8px 16px"><?php foreach ($sheetReport as $name => $info): ?><label class="mb-0 <?php echo empty($info['matchable']) ? 'text-muted' : ''; ?>"><input type="checkbox" name="sheets[]" value="<?php
    echo e($name); ?>" <?php echo !empty($info['used']) ? 'checked' : ''; ?> <?php echo empty($info['matchable']) ? 'disabled' : ''; ?>> <?php echo e($name); ?><?php echo isset($info
    ['rows']) ? ' · ' . (int)$info['rows'] . ' 行' : ''; ?><?php echo empty($info['matchable']) ? '（' . e(mb_strimwidth($info['reason'], 0, 40, '…')) . '）' : ''; ?></label><?php
    endforeach; ?></div>
<button class="btn btn-outline-primary btn-sm mt-2">重新预览所选工作表</button> <a class="btn btn-link btn-sm mt-2" href="<?php echo BASE_URL; ?>/project/files.php?view=<?php
    echo $previewFileId; ?>" target="_blank" rel="noopener">查看原始表格</a>
</div></form>
<?php endif; ?>
<?php $previewKinds = $preview ? ps_business_order_kinds($selectedBusiness) : []; ?>
<?php foreach ($previewSheets as $sheetName => $info): if (empty($info['ai'])) continue; ?><div class="alert alert-info"><i class="fas fa-robot mr-1"></i><?php echo count($previewSheets
    ) > 1 ? '【' . e($sheetName) . '】' : ''; ?><?php echo e($info['ai']); ?> 请核对下方识别结果。</div><?php endforeach; ?>
<?php if ($preview): ?>
<?php $blankTotal = array_sum(array_map(function ($i) { return (int)($i['blank'] ?? 0); }, $previewSheets)); if ($blankTotal): ?><div class="alert alert-light border small"><i class="fas fa-eraser mr-1 text-muted"></i>已自动略过 <?php
    echo $blankTotal; ?> 行没有订单号也没有金额的空白 / 备注行（如预先填好姓名的空行），它们不是订单，无需处理。</div><?php endif; ?>
<?php if ($invalidCount): ?><div class="alert alert-info"><strong><?php echo $baseValidCount; ?> 行资料已通过，<?php echo $invalidCount; ?> 行需处理。</strong>可先导入合格行，红色行不会写入订单；也可在缺失处在线补填后点“重新核对”。请填写真实店铺订单号，或微信交易流水号；后者会生成可追溯的内部关联号。</div><?php
    endif; ?>
<?php if ($skipCounts): ?><div class="alert alert-secondary"><?php echo e(implode('，', array_map(function ($status, $n) { return $n . ' 行' . ($status === '已导入过' ? '已导入并经财务审核'
    : '写的是其他同事（由本人上传或财务导入）'); }, array_keys($skipCounts), $skipCounts))); ?>，已自动跳过（灰色行），无需处理。</div><?php endif
    ; ?>
<form method="post" class="card project-form-card mb-3"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="business" value="<?php
    echo e($selectedBusiness); ?>"><div class="card-body pb-2"><?php if ($previewKinds): ?><div class="project-kind-bulk d-flex flex-wrap align-items-center mb-2" style="gap:8px"><span class="small text-muted">订单类型已自动带入，无需逐行选择；发现误判时可修改。</span><select class="form-control form-control-sm" id="kindBulk" style="width:auto" aria-label="批量修正订单类型"><option value="">批量修正类型…</option><?php
    foreach ($previewKinds as $k): ?><option value="<?php echo e($k); ?>"><?php echo e($k); ?></option><?php endforeach; ?></select><label class="small mb-0"><input type="checkbox" id="kindBulkAll"> 已选的行也改</label></div>
<script><?php /* split: assets/js/project_import_3.js */ include __DIR__ . '/../../assets/js/project_import_3.js'; ?></script><?php endif; ?><div class="project-section-title"><span class="project-step">02</span><div><h5>核对预览</h5><p><?php
    echo $baseValidCount; ?> 行基础资料通过<?php echo $resourceSelection ? '；缺失域名规格的行请选标准模板，服务器成本可选填' : '；客服提交后由技术在同一订单确认资源与成本'
    ; ?>。</p></div></div></div><div class="table-responsive"><table class="table project-preview-table mb-0"><thead><tr><th>行 / 订单</th><th>日期 / 售价</th><th>参与人</th><?php
    if ($businessDefinition['fields']): ?><th>业务信息</th><?php endif; ?><?php if ($resourceSelection && $usesProgram): ?><th>程序套餐</th><?php endif; ?><?php if ($resourceSelection
    ): ?><th>域名选择与标准成本</th><th>服务器成本</th><?php endif; ?><th>核对结果</th></tr></thead><tbody>
<?php foreach ($preview as $row): $skipRow = $isSkipRow($row); ?><tr class="<?php echo $skipRow ? 'import-row--skipped' : (empty($row['base_valid']) ? 'import-row--action' : ($row[
    'status'] === '可导入' ? '' : 'import-row--review')); ?>">
<?php /* split: project/import/view/render_62.php */ include __DIR__ . '/view/render_62.php'; ?><?php /* split: project/import/view/render_63.php */ include __DIR__ . '/view/render_63.php'; ?><td><small>客服：<?php
    echo e(implode('、', array_column($row['people']['customer_service'], 'name')) ?: '—'); ?><br>技术：<?php echo e(implode('、', array_column($row['people']['technical'],
    'name')) ?: '—'); ?></small><?php foreach ($row['items'] ?? [] as $oi): ?><div class="small text-muted mt-1"><?php echo e($oi['item_name']); ?><?php echo $oi['sale_amount']===
    null ? ' · 整单计价' : ' · ¥' . money($oi['sale_amount']); ?><?php echo $oi['category']==='certificate' ? ' · 证书独立保留' : ''; ?></div><?php endforeach; ?></td>
<?php if ($businessDefinition['fields']): ?><td><small><?php foreach ($businessDefinition['fields'] as $key => $label): ?><?php echo e($label . '：' . ps_contact_for($actor, ($row
    ['details'][$key] ?? '') ?: '—', $key === 'customer_wechat')); ?><br><?php endforeach; ?></small></td><?php endif; ?>
<?php if ($resourceSelection && $usesProgram): ?><td><?php if (!empty($row['resource_locked'])): ?><span class="text-muted">原单已确认</span><?php elseif (!empty($row['base_valid'
    ])): ?><select class="form-control form-control-sm" name="program_choice[<?php echo (int)$row['line']; ?>]" aria-label="第<?php echo (int)$row['line']; ?>行程序套餐"><option value="0">不使用程序套餐</option><?php
    foreach ($programTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" <?php echo (int)($row['program_template_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php
    echo e($t['name'] . ' · ' . $t['specification'] . ' · ¥' . money($t['price'])); ?></option><?php endforeach; ?></select><small class="text-muted">原表：<?php echo e(($row
    ['program_name'] ?? '') ?: '未写程序名称'); ?></small><?php else: ?>—<?php endif; ?></td><?php endif; ?>
<?php if ($resourceSelection): ?>
<td><?php if (!empty($row['resource_locked'])): ?><span class="text-muted">原单已确认</span><?php elseif (!empty($row['base_valid'])): ?><select class="form-control form-control-sm" name="domain_choice[<?php
    echo (int)$row['line']; ?>]" aria-label="第<?php echo (int)$row['line']; ?>行域名"><option value="">请选择域名方式</option><?php if (($row['domain_mode'] ?? '') ===
    'pending'): ?><option value="pending" selected>待技术确认</option><?php endif; ?><option value="none" <?php echo $row['domain_mode'] === 'none' ? 'selected' : ($row['domain_mode'
    ] === 'template' ? 'disabled' : ''); ?>>无需域名</option><?php foreach ($domainTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" <?php echo (int)$row['domain_template_id'
    ] === (int)$t['id'] ? 'selected' : ($row['domain_mode'] === 'none' ? 'disabled' : ''); ?>><?php echo e(trim($t['name'] . ' ' . $t['specification']) . ' · ¥' . money($t['price'
    ])); ?></option><?php endforeach; ?></select><small class="text-muted">原表：<?php echo e(($row['domain_used'] ?: '空白') . ' · ' . ($row['resource_note'] ?: '未写域名/空间'
    )); ?></small><?php else: ?>—<?php endif; ?></td>
<td><?php if (!empty($row['resource_locked'])): ?>—<?php elseif (!empty($row['base_valid'])): ?><select class="form-control form-control-sm" name="server_template_id[<?php echo (int)
    $row['line']; ?>]"><option value="0">不自动计服务器</option><?php foreach ($serverTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>"><?php echo e(trim($t['name'
    ] . ' ' . $t['specification']) . ' · ¥' . money($t['price'])); ?></option><?php endforeach; ?></select><?php else: ?>—<?php endif; ?></td>
<?php endif; ?>
<td class="import-result-cell"><span class="import-status import-status--<?php echo $skipRow ? 'skipped' : (empty($row['base_valid']) ? 'action' : ($row['status'] === '可导入' ?
    'ready' : 'review')); ?>"><i class="fas fa-<?php echo $skipRow ? 'minus-circle' : (empty($row['base_valid']) ? 'exclamation-circle' : ($row['status'] === '可导入' ? 'check-circle'
    : 'info-circle')); ?>" aria-hidden="true"></i><?php echo e($row['status']); ?></span><?php if ($row['error']): ?><div class="import-notice import-notice--<?php echo $skipRow ?
    'skipped' : 'action'; ?>" role="<?php echo $skipRow ? 'note' : 'alert'; ?>"><div class="import-notice-label"><?php echo $skipRow ? '跳过原因' : '需要处理'; ?></div><div class="import-notice-message"><?php
    echo e($row['error']); ?></div><?php if (!$skipRow && ($rowGuide = ps_import_fix_guide($row['error'], $selectedBusiness))): ?><div class="import-notice-guide"><strong>处理方法</strong><span><?php
    echo e($rowGuide['how']); ?><?php echo $rowGuide['example'] !== '' ? '　例：' . e($rowGuide['example']) : ''; ?></span></div><?php endif; ?></div><?php if (!$skipRow && !empty
    ($row['conflict_detail'])): ?><div class="small import-detail mt-1"><i class="fas fa-not-equal mr-1"></i><?php echo e($row['conflict_detail']); ?></div><?php endif; ?><?php if
    (!$skipRow && !empty($row['fix_panel']) && $row['fix_panel']['order_id'] > 0 && $row['fix_panel']['fields']): $fp = $row['fix_panel']; ?>
<div class="import-fix mt-2" data-order-id="<?php echo (int)$fp['order_id']; ?>">
  <?php if ($fp['hint'] !== ''): ?><div class="import-fix-hint"><i class="fas fa-lightbulb"></i> <?php echo e($fp['hint']); ?></div><?php endif; ?>
  <div class="import-fix-title">表里是对的？直接更正原单（勾选要改的）</div>
  <?php foreach ($fp['fields'] as $f): ?><label class="import-fix-row"><input type="checkbox" class="js-fixf" value="<?php echo e($f[0]); ?>" data-new="<?php echo e($f[3]); ?>" <?php
    echo ($f[6] && !($fp['same_prev'] && $f[0] !== 'contract_amount')) ? 'checked' : ''; ?>> <?php echo e($f[1]); ?>：<s><?php echo e($f[4] !== '' ? $f[4] : '（空）'); ?></s> → <b><?php
    echo e($f[5]); ?></b></label><?php endforeach; ?>
  <div class="import-fix-actions"><button type="button" class="btn btn-sm btn-success js-fix-submit"><?php echo $actor['role'] === 'finance' ? '立即更正原单' : '提交财务确认'
    ; ?></button> <span class="small js-fix-msg" role="status"></span></div>
  <div class="import-fix-foot"><?php echo $actor['role'] === 'finance' ? '更正后点上方“重新核对”即可导入。' : '财务确认后，回到这里点“重新核对”就能导入；也可以改表格后重新上传。'
    ; ?></div>
</div>
<?php endif; ?><?php endif; ?><?php if ($row['warning']): ?><div class="small import-warn mt-1"><?php echo e($row['warning']); ?></div><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><div class="card-body border-top d-flex flex-wrap justify-content-between align-items-center" style="gap:10px;position:sticky;bottom:0;z-index:20;background:#fff;box-shadow:0 -3px 10px rgba(15,64,40,.12);border-radius:0 0 14px 14px"><small class="text-muted">红色行不会入账；可先处理已通过的行。绿色按钮点击后订单才真正写入，仅上传预览不会入库。售价不会直接变成实收<?php
    echo $businessDefinition['resources'] ? '，SSL 报备价不会直接入成本' : ''; ?>。</small><div class="d-flex flex-wrap" style="gap:8px"><?php if ($repairableCount || $siteKeyFixCount
    ): ?><button class="btn btn-outline-primary mt-2" type="submit" name="action" value="repair_preview">应用补填并重新核对</button><?php endif; ?><button class="btn btn-success btn-lg mt-2" type="submit" name="action" value="commit" <?php
    echo $baseValidCount ? '' : 'disabled'; ?>><?php echo $invalidCount ? '先导入 ' . $baseValidCount . ' 行合格订单' : '确认导入已核对订单'; ?></button></div></div></form>
<?php endif; ?>
</div>
<script><?php /* split: assets/js/project_import_4.js */ include __DIR__ . '/../../assets/js/project_import_4.js'; ?></script>
<script src="<?php echo BASE_URL; ?>/assets/lib/xlsx.full.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/project-xls-upload.js"></script>
<script><?php /* split: project/import/view/view/js_5.php */ include __DIR__ . '/view/view/js_5.php'; ?></script>
<?php include (dirname(__DIR__, 1)) . '/../includes/footer.php'; ?>
