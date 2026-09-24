<?php
// 原始表格：合作人员 / 财务上传的订单表格原件。财务看全部，合作人员只看本人上传的；可在线查看各工作表、下载原文件。
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectBusiness.php';
$actor = ps_require_actor();
$isFinance = $actor['role'] === 'finance';

if (isset($_GET['download'])) {
    try { $file = ps_import_file_get((int)$_GET['download'], $actor); } catch (RuntimeException $e) { http_response_code(404); exit($e->getMessage()); }
    $name = $file['original_name'] !== '' ? $file['original_name'] : basename($file['stored_name']);
    header('Content-Type: ' . (substr($file['stored_name'], -4) === '.csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
    header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . filesize($file['path']));
    header('X-Content-Type-Options: nosniff');
    readfile($file['path']);
    exit;
}

$error = '';
$viewFile = null;
$sheets = [];
if (isset($_GET['view'])) {
    try {
        $viewFile = ps_import_file_get((int)$_GET['view'], $actor);
        $sheets = ps_import_file_sheets($viewFile);
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '表格无法读取，请下载原文件查看'; $viewFile = null; }
}

// 列表筛选：业务、上传人（财务）、月份、文件名 / 上传人关键字、状态
$business = (string)($_GET['business'] ?? '');
$employeeId = $isFinance ? (int)($_GET['employee_id'] ?? 0) : (int)$actor['employee_id'];
$month = (string)($_GET['month'] ?? '');
if ($month !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = '';
$keyword = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$where = ['1=1'];
$params = [];
if (!$isFinance) { $where[] = "f.employee_id=? AND f.uploaded_by_type='employee'"; $params[] = (int)$actor['employee_id']; }
elseif ($employeeId > 0) { $where[] = 'f.employee_id=?'; $params[] = $employeeId; }
if ($business !== '') { $where[] = 'f.business_name=?'; $params[] = $business; }
if ($month !== '') { $where[] = "DATE_FORMAT(f.created_at,'%Y-%m')=?"; $params[] = $month; }
if (in_array($status, ['preview', 'imported'], true)) { $where[] = 'f.status=?'; $params[] = $status; }
if ($keyword !== '') { $where[] = '(f.original_name LIKE ? OR e.name LIKE ? OR u.username LIKE ?)'; $like = '%' . $keyword . '%'; array_push($params, $like, $like, $like); }
$q = db()->prepare('SELECT f.*,e.name AS employee_name,e.department,u.username FROM project_import_files f LEFT JOIN employees e ON e.id=f.employee_id LEFT JOIN project_users u ON u.employee_id=f.employee_id WHERE ' . implode(' AND ', $where) . ' ORDER BY f.id DESC LIMIT 300');
$q->execute($params);
$files = $q->fetchAll();
$uploaders = $isFinance ? db()->query('SELECT DISTINCT e.id,e.name,e.department FROM project_import_files f JOIN employees e ON e.id=f.employee_id ORDER BY e.department,e.name')->fetchAll() : [];
$businesses = array_keys(array_filter(ps_business_catalog(), function ($d) { return empty($d['legacy']); }));
$admins = [];
foreach (db()->query('SELECT id,username FROM admins')->fetchAll() as $a) $admins[(int)$a['id']] = $a['username'];
$sizeText = function ($bytes) { return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, round($bytes / 1024)) . ' KB'; };
$uploaderText = function ($f) use ($admins) { return $f['uploaded_by_type'] === 'admin' ? '财务 ' . ($admins[(int)$f['uploaded_by_id']] ?? '') : ($f['employee_name'] ?? '—'); };

$page_title = $isFinance ? '原始表格' : '我上传的表格';
include __DIR__ . '/../includes/header.php';
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · <?php echo $isFinance ? '财务' : '我的账号'; ?></div><h2><?php echo e($page_title); ?></h2><p><?php echo $isFinance ? '合作人员拖入系统的订单表格原件都在这里：按人、业务、月份筛选，在线查看每张工作表，或下载原文件核对。' : '你上传过的订单表格原件，可随时在线查看或下载。'; ?></p></div><div class="project-hero-actions"><a class="btn btn-light" href="<?php echo BASE_URL; ?>/project/import.php">上传新表格</a></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

<?php if ($viewFile): $sheetNames = array_keys($sheets); $current = (string)($_GET['sheet'] ?? ($sheetNames[0] ?? '')); if (!isset($sheets[$current])) $current = (string)($sheetNames[0] ?? ''); $rowsToShow = $sheets[$current] ?? []; ?>
<div class="card mb-3 project-file-view"><div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px">
  <div><strong><?php echo e($viewFile['original_name']); ?></strong><div class="small text-muted"><?php echo e($viewFile['business_name']); ?> · <?php echo e($uploaderText($viewFile + ['employee_name' => db()->query('SELECT name FROM employees WHERE id=' . (int)$viewFile['employee_id'])->fetchColumn() ?: '—'])); ?> · <?php echo e($viewFile['created_at']); ?> · <?php echo $viewFile['status'] === 'imported' ? '已导入 ' . (int)$viewFile['imported_count'] . ' 单' : '仅预览未导入'; ?></div></div>
  <div class="d-flex" style="gap:6px"><a class="btn btn-sm btn-outline-secondary" href="<?php echo BASE_URL; ?>/project/files.php?<?php echo e(http_build_query(array_diff_key($_GET, ['view' => 1, 'sheet' => 1]))); ?>">返回列表</a><a class="btn btn-sm btn-primary" href="<?php echo BASE_URL; ?>/project/files.php?download=<?php echo (int)$viewFile['id']; ?>">下载原文件</a></div>
</div><div class="card-body">
  <?php if (count($sheetNames) > 1): ?><nav class="app-tabs mb-3" aria-label="工作表"><?php foreach ($sheetNames as $name): ?><a href="<?php echo BASE_URL; ?>/project/files.php?<?php echo e(http_build_query(array_merge($_GET, ['sheet' => $name]))); ?>" class="<?php echo $name === $current ? 'active' : ''; ?>"><?php echo e($name); ?> <small>(<?php echo max(count($sheets[$name]) - 1, 0); ?>)</small></a><?php endforeach; ?></nav><?php endif; ?>
  <input type="search" class="form-control mb-2" id="sheetSearch" placeholder="在本表中查找（订单号、客户、姓名…）" aria-label="在本表中查找">
  <div class="table-responsive project-sheet-wrap"><table class="table table-sm table-bordered mb-0 project-sheet" id="sheetTable"><tbody>
  <?php $maxCols = 0; foreach ($rowsToShow as $r) $maxCols = max($maxCols, count($r)); $shown = 0; foreach ($rowsToShow as $i => $r): if (++$shown > 3000) break; ?>
  <tr class="<?php echo $i === 0 ? 'is-head' : ''; ?>"><th class="text-muted small"><?php echo $i + 1; ?></th><?php for ($c = 0; $c < $maxCols; $c++): $v = $r[$c] ?? ''; ?><td><?php echo e(is_float($v) ? rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') : (string)$v); ?></td><?php endfor; ?></tr>
  <?php endforeach; ?>
  <?php if (!$rowsToShow): ?><tr><td class="text-center text-muted py-4">这张工作表是空的</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if (count($rowsToShow) > 3000): ?><p class="small text-muted mt-2 mb-0">只显示前 3000 行，完整内容请下载原文件。</p><?php endif; ?>
</div></div>
<script>
(function () {
  var box = document.getElementById('sheetSearch'), rows = document.querySelectorAll('#sheetTable tr:not(.is-head)');
  box.addEventListener('input', function () { var k = box.value.trim().toLowerCase(); rows.forEach(function (tr) { tr.hidden = k !== '' && tr.textContent.toLowerCase().indexOf(k) === -1; }); });
})();
</script>
<?php endif; ?>

<div class="card mb-3"><div class="card-body"><form method="get" class="form-row align-items-end">
  <div class="form-group col-6 col-md-2"><label>月份</label><input type="month" name="month" class="form-control" value="<?php echo e($month); ?>"></div>
  <div class="form-group col-6 col-md-2"><label>业务</label><select name="business" class="form-control"><option value="">全部业务</option><?php foreach ($businesses as $name): ?><option value="<?php echo e($name); ?>" <?php echo $business === $name ? 'selected' : ''; ?>><?php echo e($name); ?></option><?php endforeach; ?></select></div>
  <?php if ($isFinance): ?><div class="form-group col-6 col-md-2"><label>上传人</label><select name="employee_id" class="form-control"><option value="0">全部</option><?php foreach ($uploaders as $u): ?><option value="<?php echo (int)$u['id']; ?>" <?php echo $employeeId === (int)$u['id'] ? 'selected' : ''; ?>><?php echo e($u['name'] . ' · ' . $u['department']); ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="form-group col-6 col-md-2"><label>状态</label><select name="status" class="form-control"><option value="">全部</option><option value="imported" <?php echo $status === 'imported' ? 'selected' : ''; ?>>已导入</option><option value="preview" <?php echo $status === 'preview' ? 'selected' : ''; ?>>仅预览</option></select></div>
  <div class="form-group col-12 col-md-3"><label>关键字</label><input name="q" class="form-control" value="<?php echo e($keyword); ?>" placeholder="文件名 / 姓名 / 拼音登录名"></div>
  <div class="form-group col-12 col-md-1"><button class="btn btn-primary btn-block">筛选</button></div>
</form></div></div>

<div class="card"><div class="card-header">共 <?php echo count($files); ?> 个表格<?php echo count($files) >= 300 ? '（只显示最近 300 个，可用筛选缩小范围）' : ''; ?></div><div class="table-responsive"><table class="table table-hover mb-0 project-stack-table"><thead><tr><th>文件</th><th>业务</th><th>上传人</th><th>上传时间</th><th>工作表</th><th>结果</th><th></th></tr></thead><tbody>
<?php foreach ($files as $f): ?><tr>
  <td data-label="文件"><a href="<?php echo BASE_URL; ?>/project/files.php?<?php echo e(http_build_query(array_merge($_GET, ['view' => (int)$f['id']]))); ?>"><i class="fas fa-file-excel text-success mr-1"></i><?php echo e($f['original_name']); ?></a><div class="small text-muted"><?php echo e($sizeText((int)$f['file_size'])); ?></div></td>
  <td data-label="业务"><?php echo e($f['business_name']); ?></td>
  <td data-label="上传人"><?php echo e($uploaderText($f)); ?><?php if (!empty($f['department'])): ?><div class="small text-muted"><?php echo e($f['department']); ?></div><?php endif; ?></td>
  <td data-label="上传时间" class="text-nowrap"><?php echo e(substr($f['created_at'], 0, 16)); ?></td>
  <td data-label="工作表" class="small"><?php echo e($f['sheets_used'] ?: '—'); ?></td>
  <td data-label="结果"><?php echo $f['status'] === 'imported' ? '<span class="badge badge-success">已导入 ' . (int)$f['imported_count'] . ' 单</span>' . ((int)$f['skipped_count'] ? ' <span class="small text-muted">跳过 ' . (int)$f['skipped_count'] . '</span>' : '') : '<span class="badge badge-secondary">仅预览</span>'; ?></td>
  <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?php echo BASE_URL; ?>/project/files.php?<?php echo e(http_build_query(array_merge($_GET, ['view' => (int)$f['id']]))); ?>">查看</a> <a class="btn btn-sm btn-outline-secondary" href="<?php echo BASE_URL; ?>/project/files.php?download=<?php echo (int)$f['id']; ?>">下载</a></td>
</tr><?php endforeach; ?>
<?php if (!$files): ?><tr><td colspan="7" class="text-center text-muted py-4">还没有上传过的表格。合作人员在“拖拽上传 Excel”上传后会自动出现在这里。</td></tr><?php endif; ?>
</tbody></table></div></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
