<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../classes/SimpleXLSX.php';

$page_title = '考勤上传';
$success = '';
$error = '';

ensureAttendanceTable();

$year  = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);
if ($year <= 0 || $month < 1 || $month > 12) {
    header('Location: ' . BASE_URL . '/attendance/index.php');
    exit;
}

// ===== 后端处理 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 手动添加单条
    if ($action === 'manual_add') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $wh = (float)($_POST['work_hours'] ?? 0);
        $ah = (float)($_POST['absent_hours'] ?? 0);
        $rm = trim($_POST['remark'] ?? '');
        if ($empId <= 0) {
            $error = '请选择员工';
        } else {
            try {
                $stmt = db()->prepare("INSERT INTO attendances (employee_id, year, month, work_hours, absent_hours, remark)
                                       VALUES (?, ?, ?, ?, ?, ?)
                                       ON DUPLICATE KEY UPDATE work_hours=VALUES(work_hours), absent_hours=VALUES(absent_hours), remark=VALUES(remark)");
                $stmt->execute([$empId, $year, $month, $wh, $ah, $rm]);
                $success = '考勤已添加';
            } catch (PDOException $ex) {
                $error = '添加失败: ' . $ex->getMessage();
            }
        }
    }
    // 单条删除
    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            db()->prepare("DELETE FROM attendances WHERE id=? AND year=? AND month=?")->execute([$id, $year, $month]);
            $success = '已删除';
        } catch (PDOException $ex) { $error = '删除失败'; }
    }
    // 批量删除
    elseif ($action === 'batch_delete') {
        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        if (empty($ids)) { $error = '请勾选'; }
        else {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("DELETE FROM attendances WHERE id IN ($ph) AND year=? AND month=?")->execute(array_merge($ids, [$year, $month]));
            $success = '已批量删除 ' . count($ids) . ' 条';
        }
    }
    // 上传 Excel/CSV
    elseif ($action === 'upload') {
        $csvData = trim($_POST['csv_data'] ?? '');
        $hasFile = isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK;

        if ($csvData === '' && !$hasFile) {
            $error = '请选择要上传的文件';
        } else {
            try {
                $rows = [];
                if ($csvData !== '') {
                    $decoded = json_decode($csvData, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $r) {
                            if (is_array($r) && count(array_filter($r, fn($v) => trim($v) !== '')) > 0) $rows[] = $r;
                        }
                    } else { $error = '数据格式错误'; }
                } else {
                    $file = $_FILES['excel_file'];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $tmp = $file['tmp_name'];
                    if (!in_array($ext, ['xlsx', 'csv'])) { $error = '仅支持 .xlsx / .csv'; }
                    else {
                        if ($ext === 'csv') {
                            $fp = fopen($tmp, 'r');
                            $bom = fread($fp, 3);
                            if ($bom !== "\xEF\xBB\xBF") rewind($fp);
                            while (($d = fgetcsv($fp)) !== false) $rows[] = $d;
                            fclose($fp);
                        } else {
                            $rows = SimpleXLSX::parse($tmp);
                        }
                    }
                }

                if ($error === '' && !empty($rows)) {
                    $firstRow = $rows[0];
                    $dataRows = array_slice($rows, 1);

                    // 规范化表头 + 列映射
                    $colMap = [];
                    foreach ($firstRow as $ci => $cv) {
                        $cv = trim(preg_replace('/[\x00-\x1F\x80-\x9F\xEF\xBB\xBF\xC2\xA0]/u', '', $cv));
                        if ($cv === '') continue;
                        $colMap[$cv] = $ci;
                    }
                    // 模糊匹配列
                    $idxName = $idxWork = $idxAbsent = $idxRemark = null;
                    foreach ($colMap as $k => $idx) {
                        if ($idxName === null && (mb_strpos($k, '姓名') !== false || mb_strpos($k, '员工') !== false || stripos($k, 'name') !== false)) $idxName = $idx;
                        if ($idxWork === null && (mb_strpos($k, '应出勤') !== false || mb_strpos($k, '出勤') !== false || mb_strpos($k, '应到') !== false)) $idxWork = $idx;
                        if ($idxAbsent === null && (mb_strpos($k, '请假') !== false || mb_strpos($k, '缺勤') !== false)) $idxAbsent = $idx;
                        if ($idxRemark === null && (mb_strpos($k, '备注') !== false || mb_strpos($k, '说明') !== false || stripos($k, 'remark') !== false)) $idxRemark = $idx;
                    }
                    if ($idxName === null) {
                        $error = '表头缺少"员工姓名"列';
                    } elseif ($idxWork === null && $idxAbsent === null) {
                        $error = '表头至少需要"应出勤"或"请假"中的一个列';
                    } else {
                        // 预载员工名单（按名查ID）
                        $empList = get_employees();
                        $empByName = [];
                        foreach ($empList as $e) $empByName[trim($e['name'])] = (int)$e['id'];

                        $inserted = 0; $skipped = 0; $notFound = [];
                        $ins = db()->prepare("INSERT INTO attendances (employee_id, year, month, work_hours, absent_hours, remark)
                                              VALUES (?, ?, ?, ?, ?, ?)
                                              ON DUPLICATE KEY UPDATE work_hours=VALUES(work_hours), absent_hours=VALUES(absent_hours), remark=VALUES(remark)");
                        db()->beginTransaction();
                        foreach ($dataRows as $r) {
                            if (count(array_filter($r, fn($v) => trim($v) !== '')) === 0) continue;
                            $empName = trim($r[$idxName] ?? '');
                            if ($empName === '') continue;
                            $empId = $empByName[$empName] ?? 0;
                            if ($empId <= 0) { $notFound[] = $empName; $skipped++; continue; }
                            $wh = $idxWork !== null ? (float)preg_replace('/[^\d.]/', '', trim($r[$idxWork] ?? '')) : 0;
                            $ah = $idxAbsent !== null ? (float)preg_replace('/[^\d.]/', '', trim($r[$idxAbsent] ?? '')) : 0;
                            $rm = $idxRemark !== null ? trim($r[$idxRemark] ?? '') : '';
                            $ins->execute([$empId, $year, $month, $wh, $ah, $rm]);
                            $inserted++;
                        }
                        db()->commit();
                        $msg = "导入完成：成功 {$inserted} 条";
                        if ($skipped > 0) $msg .= "，跳过 {$skipped} 条";
                        if (!empty($notFound)) $msg .= "，未匹配员工：" . implode('、', array_slice($notFound, 0, 5)) . (count($notFound) > 5 ? ' 等' : '');
                        $success = $msg;
                    }
                } elseif ($error === '') {
                    $error = '文件无数据';
                }
            } catch (Exception $ex) {
                if (db()->inTransaction()) db()->rollBack();
                $error = '解析失败: ' . $ex->getMessage();
            }
        }
    }
}

// ===== 数据查询 =====
$employees = get_employees();
$records = get_attendances_by_month($year, $month);
$total = count($records);
$totalAbsent = array_sum(array_column($records, 'absent_hours'));
$fullCount = 0;
foreach ($records as $r) if ((float)$r['absent_hours'] == 0) $fullCount++;

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
    <div>
        <a href="<?php echo BASE_URL; ?>/attendance/year.php?year=<?php echo $year; ?>" class="btn btn-outline-secondary btn-sm mr-2">
            <i class="fas fa-arrow-left"></i> 返回<?php echo $year; ?>年
        </a>
        <h4 class="font-weight-bold mb-0 d-inline-block">
            <i class="fas fa-calendar-day text-primary"></i> <?php echo $year; ?>年<?php echo $month; ?>月考勤
        </h4>
        <span class="badge badge-info ml-2">已录 <?php echo $total; ?> 人</span>
        <span class="badge badge-success ml-1">满勤 <?php echo $fullCount; ?> 人</span>
        <?php if ($totalAbsent > 0): ?><span class="badge badge-warning ml-1">请假 <?php echo number_format($totalAbsent, 1); ?>h</span><?php endif; ?>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<div class="row">
    <!-- 左侧：上传 + 手动添加 -->
    <div class="col-md-5">
        <!-- 上传考勤表 -->
        <div class="card mb-3">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-file-excel text-success"></i> 批量上传考勤表</h5></div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="action" value="upload">
                    <div class="form-group">
                        <label>归属月份</label>
                        <input type="text" class="form-control" value="<?php echo $year; ?>年<?php echo $month; ?>月" disabled>
                    </div>
                    <div class="form-group">
                        <label>选择文件 <span class="required">*</span></label>
                        <div class="upload-area" id="uploadArea" style="border:2px dashed #ced4da;border-radius:6px;padding:20px;text-align:center;cursor:pointer;">
                            <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-2"></i>
                            <p class="mb-1" id="uploadTip">点击或拖拽文件到此处</p>
                            <p class="text-muted small mb-0">支持 .xlsx / .csv</p>
                            <input type="file" name="excel_file" id="excelFile" class="d-none" accept=".xlsx,.csv">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>或粘贴表格数据</label>
                        <textarea name="csv_data" id="csvData" class="form-control" rows="3" placeholder="姓名,应出勤,请假,备注&#10;张三,176,0,&#10;李四,176,4,病假"></textarea>
                        <small class="text-muted">用逗号分隔，第一行为表头</small>
                    </div>
                    <button type="submit" class="btn btn-success btn-block"><i class="fas fa-upload"></i> 开始上传</button>
                </form>
                <hr>
                <div class="text-muted small">
                    <b><i class="fas fa-info-circle text-info"></i> 文件格式要求：</b><br>
                    表头需包含：<code>姓名/员工</code> + <code>应出勤</code> 和/或 <code>请假</code>，可选 <code>备注</code><br>
                    员工姓名必须与系统员工名一致，否则该行跳过<br>
                    请假小时支持小数（半天=4）
                </div>
            </div>
        </div>

        <!-- 手动添加单条 -->
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-hand-pointer text-primary"></i> 手动添加单条</h5></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="manual_add">
                    <div class="form-group">
                        <label>员工 <span class="required">*</span></label>
                        <select name="employee_id" class="form-control" required>
                            <option value="">-- 选择员工 --</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo e($emp['name']); ?>（<?php echo e($emp['department']); ?>）</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>应出勤(h)</label>
                            <input type="number" name="work_hours" class="form-control" step="0.1" min="0" value="176">
                        </div>
                        <div class="form-group col-md-6">
                            <label>请假(h)</label>
                            <input type="number" name="absent_hours" class="form-control" step="0.1" min="0" value="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>备注</label>
                        <input type="text" name="remark" class="form-control" placeholder="如：病假">
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-plus"></i> 添加</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 右侧：已上传考勤记录 -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list text-info"></i> 考勤记录</h5>
                    <?php if ($total > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="batchDelete()">
                        <i class="fas fa-trash-alt"></i> 批量删除
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($records)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>暂无考勤数据，请上传或手动添加
                    </div>
                <?php else: ?>
                <form method="post" id="delForm">
                    <input type="hidden" name="action" value="batch_delete">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width:36px"><input type="checkbox" id="chkAll" onclick="document.querySelectorAll('.row-chk').forEach(c=>c.checked=this.checked)"></th>
                                    <th>员工</th>
                                    <th>部门</th>
                                    <th class="text-right">应出勤</th>
                                    <th class="text-right">请假</th>
                                    <th>备注</th>
                                    <th style="width:70px">状态</th>
                                    <th style="width:60px">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($records as $r):
                                $isFull = (float)$r['absent_hours'] == 0;
                            ?>
                                <tr>
                                    <td><input type="checkbox" name="ids[]" value="<?php echo $r['id']; ?>" class="row-chk"></td>
                                    <td><strong><?php echo e($r['name']); ?></strong></td>
                                    <td><span class="badge badge-info"><?php echo e($r['department']); ?></span></td>
                                    <td class="text-right"><?php echo number_format($r['work_hours'], 1); ?>h</td>
                                    <td class="text-right <?php echo $isFull ? '' : 'text-warning font-weight-bold'; ?>"><?php echo number_format($r['absent_hours'], 1); ?>h</td>
                                    <td><small class="text-muted"><?php echo e($r['remark']); ?></small></td>
                                    <td>
                                        <?php if ($isFull): ?>
                                            <span class="badge badge-success">满勤</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">请假</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" class="d-inline" onsubmit="return confirm('删除该考勤记录？')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                            <button class="btn btn-sm btn-link p-0 text-danger"><i class="fas fa-trash-alt"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// 文件上传区交互
(function(){
    var area = document.getElementById('uploadArea');
    var file = document.getElementById('excelFile');
    var tip = document.getElementById('uploadTip');
    if (!area || !file) return;
    area.addEventListener('click', function(){ file.click(); });
    file.addEventListener('change', function(){ if (file.files.length) tip.textContent = file.files[0].name; });
    ['dragover','dragenter'].forEach(function(ev){ area.addEventListener(ev, function(e){ e.preventDefault(); area.style.borderColor='#28a745'; }); });
    ['dragleave','drop'].forEach(function(ev){ area.addEventListener(ev, function(e){ e.preventDefault(); area.style.borderColor=''; }); });
    area.addEventListener('drop', function(e){ if (e.dataTransfer.files.length){ file.files = e.dataTransfer.files; tip.textContent = file.files[0].name; } });
})();
function batchDelete(){
    var n = document.querySelectorAll('.row-chk:checked').length;
    if (n === 0) { alert('请先勾选要删除的记录'); return; }
    if (!confirm('确定删除选中的 ' + n + ' 条考勤记录？')) return;
    document.getElementById('delForm').submit();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
