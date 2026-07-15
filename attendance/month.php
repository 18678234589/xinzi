<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../classes/SimpleXLSX.php';

$page_title = '考勤上传';
$success = '';
$error = '';

ensureAttendanceTable();

$year  = (int)($_REQUEST['year'] ?? 0);
$month = (int)($_REQUEST['month'] ?? 0);
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
        $fullDays = (float)($_POST['full_days'] ?? 0);
        $actualDays = (float)($_POST['actual_days'] ?? $fullDays);
        $rm = trim($_POST['remark'] ?? '');
        if ($empId <= 0) {
            $error = '请选择员工';
        } else {
            try {
                // 满勤天数 × 8 = 应出勤小时；(满勤-实际出勤) × 8 = 请假小时
                $wh = $fullDays * 8;
                $ah = max(0, ($fullDays - $actualDays) * 8);
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
        if (empty($ids)) { $error = '请勾选要删除的记录'; }
        else {
            try {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmt = db()->prepare("DELETE FROM attendances WHERE id IN ($ph) AND year=? AND month=?");
                $stmt->execute(array_merge($ids, [$year, $month]));
                $delCnt = $stmt->rowCount();
                $success = "已批量删除 {$delCnt} 条（选中" . count($ids) . "条）";
            } catch (PDOException $ex) {
                $error = '删除失败: ' . $ex->getMessage();
            }
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
                // 优先使用上传的文件；仅在无文件时才用粘贴的表格数据
                if ($hasFile) {
                    $file = $_FILES['excel_file'];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $tmp = $file['tmp_name'];
                    if (!in_array($ext, ['xlsx', 'csv'])) { $error = '仅支持 .xlsx / .csv'; }
                    else {
                        if ($ext === 'csv') {
                            // 读取原始内容，自动处理编码（中文 Excel 常为 GBK）
                            $raw = file_get_contents($tmp);
                            // 去掉 UTF-8 BOM
                            if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
                            // 非 UTF-8 则尝试从 GBK 转码
                            if (!mb_check_encoding($raw, 'UTF-8')) {
                                $converted = @iconv('GBK', 'UTF-8//IGNORE', $raw);
                                if ($converted !== false) $raw = $converted;
                            }
                            // 自动检测分隔符（Tab / 逗号 / 分号）
                            $firstLine = strtok($raw, "\n");
                            $delim = ',';
                            $tabN = substr_count($firstLine, "\t");
                            $comN = substr_count($firstLine, ',');
                            $semN = substr_count($firstLine, ';');
                            if ($tabN >= $comN && $tabN >= $semN && $tabN > 0) $delim = "\t";
                            elseif ($semN > $comN) $delim = ';';
                            // 解析（按检测到的分隔符切分）
                            $fp = fopen('php://temp', 'r+');
                            fwrite($fp, $raw);
                            rewind($fp);
                            while (($d = fgetcsv($fp, 0, $delim)) !== false) $rows[] = $d;
                            fclose($fp);
                        } else {
                            // 多工作表处理：优先选择含"满勤天数/实际出勤天数"或"应出勤/请假"列的汇总表，
                            // 而非原始打卡明细表（打卡时间表）。
                            $sheetNames = SimpleXLSX::sheetNames($tmp);
                            $rows = null;
                            if (count($sheetNames) > 1) {
                                $allSheets = SimpleXLSX::parseAll($tmp);
                                $bestScore = -1;
                                foreach ($allSheets as $sName => $sRows) {
                                    if (empty($sRows)) continue;
                                    // 检查表头行是否含文档记录格式列
                                    $headerRow = null;
                                    foreach ($sRows as $sr) {
                                        $rowText = implode(' ', array_filter($sr, function($v){ return trim($v) !== ''; }));
                                        if (mb_strpos($rowText, '姓名') !== false || mb_strpos($rowText, '员工') !== false) {
                                            $headerRow = $sr;
                                            break;
                                        }
                                    }
                                    if ($headerRow === null) continue;
                                    $score = 0;
                                    foreach ($headerRow as $cv) {
                                        $cv = trim($cv);
                                        if ($cv === '') continue;
                                        if (mb_strpos($cv, '满勤天数') !== false || mb_strpos($cv, '满勤') !== false
                                            || mb_strpos($cv, '应出勤天数') !== false || mb_strpos($cv, '应出勤') !== false
                                            || mb_strpos($cv, '实际出勤') !== false || mb_strpos($cv, '实到') !== false
                                            || mb_strpos($cv, '出勤天数') !== false
                                            || mb_strpos($cv, '应出勤小时') !== false || mb_strpos($cv, '出勤小时') !== false
                                            || mb_strpos($cv, '请假') !== false || mb_strpos($cv, '缺勤') !== false) {
                                            $score++;
                                        }
                                    }
                                    if ($score > $bestScore) {
                                        $bestScore = $score;
                                        $rows = $sRows;
                                    }
                                }
                            }
                            // 没有匹配到汇总表，退回第一个工作表
                            if ($rows === null) {
                                $rows = SimpleXLSX::parse($tmp, 0);
                            }
                        }
                    }
                } else {
                    $decoded = json_decode($csvData, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $r) {
                            if (is_array($r) && count(array_filter($r, fn($v) => trim($v) !== '')) > 0) $rows[] = $r;
                        }
                    } else { $error = '数据格式错误'; }
                }

                if ($error === '' && !empty($rows)) {
                    // 自动定位表头行：查找包含"姓名"的行作为表头（跳过标题/说明行）
                    $headerRowIdx = 0;
                    foreach ($rows as $ri => $row) {
                        $rowText = implode(' ', array_filter($row, function($v){ return trim($v) !== ''; }));
                        if (mb_strpos($rowText, '姓名') !== false || mb_strpos($rowText, '员工') !== false) {
                            $headerRowIdx = $ri;
                            break;
                        }
                    }
                    $firstRow = $rows[$headerRowIdx];
                    $dataRows = array_slice($rows, $headerRowIdx + 1);
                    // 跳过日期行：若第一行数据姓名列为空且其余列多为纯数字/星期，视为日期子表头
                    if (!empty($dataRows)) {
                        $firstData = $dataRows[0];
                        $nameCell = trim($firstData[$idxName ?? 0] ?? '');
                        $numCount = 0;
                        $nonEmptyCount = 0;
                        for ($ci = 0; $ci < count($firstData); $ci++) {
                            $v = trim($firstData[$ci] ?? '');
                            if ($v === '') continue;
                            $nonEmptyCount++;
                            if (preg_match('/^\d{1,2}$/', $v) || in_array($v, ['六','日','端午节'], true)) $numCount++;
                        }
                        if ($nameCell === '' && $nonEmptyCount > 0 && $numCount / $nonEmptyCount > 0.5) {
                            array_shift($dataRows);
                        }
                    }
                    // 过滤掉空行和尾部说明行
                    $dataRows = array_values(array_filter($dataRows, function($r) {
                        $nonEmpty = array_filter($r, function($v) { return trim($v) !== ''; });
                        return count($nonEmpty) > 0;
                    }));

                    // 规范化表头 + 列映射
                    $colMap = [];
                    foreach ($firstRow as $ci => $cv) {
                        $cv = trim(preg_replace('/[\x00-\x1F\x80-\x9F\xEF\xBB\xBF\xC2\xA0]/u', '', $cv));
                        if ($cv === '') continue;
                        $colMap[$cv] = $ci;
                    }
                    // 模糊匹配列
                    $idxName = $idxWork = $idxAbsent = $idxRemark = null;
                    $idxFullDays = $idxActualDays = null;
                    foreach ($colMap as $k => $idx) {
                        if ($idxName === null && (mb_strpos($k, '姓名') !== false || mb_strpos($k, '员工') !== false || mb_strpos($k, '名字') !== false || stripos($k, 'name') !== false)) $idxName = $idx;
                        // 满勤天数（新格式）—— 支持"满勤天数/满勤/应出勤天数/应出勤/全勤天数/全勤"等多种表头
                        if ($idxFullDays === null && (mb_strpos($k, '满勤天数') !== false || mb_strpos($k, '满勤') !== false || mb_strpos($k, '应出勤天数') !== false || mb_strpos($k, '应出勤') !== false || mb_strpos($k, '全勤天数') !== false || mb_strpos($k, '全勤') !== false)) $idxFullDays = $idx;
                        // 实际出勤天数（新格式）
                        if ($idxActualDays === null && (mb_strpos($k, '实际出勤') !== false || mb_strpos($k, '实到') !== false || mb_strpos($k, '实际') !== false || mb_strpos($k, '出勤天数') !== false)) $idxActualDays = $idx;
                        // 应出勤小时（旧格式兼容）
                        if ($idxWork === null && (mb_strpos($k, '应出勤小时') !== false || mb_strpos($k, '出勤小时') !== false || mb_strpos($k, '应到') !== false)) $idxWork = $idx;
                        // 请假小时（旧格式兼容）
                        if ($idxAbsent === null && (mb_strpos($k, '请假') !== false || mb_strpos($k, '缺勤') !== false)) $idxAbsent = $idx;
                        if ($idxRemark === null && (mb_strpos($k, '备注') !== false || mb_strpos($k, '说明') !== false || stripos($k, 'remark') !== false)) $idxRemark = $idx;
                    }
                    if ($idxName === null) {
                        $heads = array_keys($colMap);
                        $preview = '';
                        foreach (array_slice($rows, 0, 3) as $ri => $row) {
                            $preview .= '第' . ($ri+1) . '行: ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "<br>";
                        }
                        $error = '表头缺少"姓名"列。识别到的表头：' . implode('、', $heads) . "<br>前3行内容：<br>" . $preview;
                    } else {
                        // 若没有"满勤天数/实际出勤天数"或"应出勤/请假"列，则自动从每日打卡列统计
                        $autoDayMode = ($idxFullDays === null && $idxActualDays === null && $idxWork === null && $idxAbsent === null);
                        if ($autoDayMode) {
                            // 已知表头列：姓名 + 其他命名列，剩下的当每日打卡列
                            $namedCols = array_values($colMap);
                            $maxNamed = $namedCols ? max($namedCols) : $idxName;
                            $dayColStart = $maxNamed + 1;
                            $dayColCount = max(0, count($firstRow) - $dayColStart);
                            if ($dayColCount <= 0) $dayColCount = 22; // 兜底
                            // 识别节假日列：日期行（表头下一行）里值为"六/日/端午节"等的是休息日，不算满勤也不算请假
                            $holidayCols = [];
                            if (isset($rows[$headerRowIdx + 1])) {
                                $dateRow = $rows[$headerRowIdx + 1];
                                for ($ci = $dayColStart; $ci < count($dateRow); $ci++) {
                                    $dv = trim($dateRow[$ci] ?? '');
                                    if (in_array($dv, ['六','日','端午节','春节','国庆','中秋','元旦','清明','劳动','五一','十一'], true)) {
                                        $holidayCols[$ci] = true;
                                    }
                                }
                            }
                            $workDayCount = $dayColCount - count($holidayCols); // 应出勤天数 = 总天数 - 节假日
                        }

                        // 预载员工名单（按名查ID）
                        $empList = get_employees();
                        $empByName = [];
                        foreach ($empList as $e) $empByName[trim($e['name'])] = (int)$e['id'];

                        // 上传前清空该月所有考勤记录，确保上传的数据就是最终数据
                        db()->prepare("DELETE FROM attendances WHERE year=? AND month=?")->execute([$year, $month]);
                        // 同步清空该月的待匹配记录（避免重复堆积）
                        db()->prepare("DELETE FROM attendance_pending WHERE year=? AND month=?")->execute([$year, $month]);

                        $inserted = 0; $skipped = 0; $notFound = [];
                        $ins = db()->prepare("INSERT INTO attendances (employee_id, year, month, work_hours, absent_hours, remark)
                                              VALUES (?, ?, ?, ?, ?, ?)
                                              ON DUPLICATE KEY UPDATE work_hours=VALUES(work_hours), absent_hours=VALUES(absent_hours), remark=VALUES(remark)");
                        $insPending = db()->prepare("INSERT INTO attendance_pending (employee_name, year, month, work_hours, absent_hours, remark)
                                                     VALUES (?, ?, ?, ?, ?, ?)");
                        db()->beginTransaction();
                        // 解析天数/小时值，支持 "25+2.5" 这类简单加减表达式（避免被 preg_replace 误拼成 252.5）
                        $parseNum = function($val) {
                            $cleaned = preg_replace('/[^\d.+\-]/', '', trim($val ?? ''));
                            if ($cleaned === '' || !preg_match('/\d/', $cleaned)) return 0.0;
                            $sum = 0.0;
                            foreach (explode('+', $cleaned) as $part) {
                                $sub = explode('-', $part);
                                $partSum = (float)array_shift($sub);
                                foreach ($sub as $neg) $partSum -= (float)$neg;
                                $sum += $partSum;
                            }
                            return $sum;
                        };
                        foreach ($dataRows as $r) {
                            if (count(array_filter($r, fn($v) => trim($v) !== '')) === 0) continue;
                            $empName = trim($r[$idxName] ?? '');
                            if ($empName === '') continue;

                            // 优先按"天数"格式计算（满勤天数 × 8 = 应出勤小时；请假小时 = (满勤-实际)×8）
                            if ($idxFullDays !== null || $idxActualDays !== null) {
                                $fullDays  = $idxFullDays  !== null ? $parseNum($r[$idxFullDays]  ?? '') : 0;
                                $actDays   = $idxActualDays !== null ? $parseNum($r[$idxActualDays] ?? '') : $fullDays;
                                $wh  = $fullDays * 8;                       // 应出勤小时 = 满勤天数 × 8
                                $ah  = max(0, ($fullDays - $actDays) * 8);  // 请假小时 = (满勤-实际出勤) × 8
                            } elseif ($autoDayMode) {
                                $fullDays = $workDayCount > 0 ? $workDayCount : $dayColCount;
                                $actDays  = 0;
                                for ($di = $dayColStart; $di < $dayColStart + $dayColCount; $di++) {
                                    // 节假日列跳过（不算满勤也不算请假）
                                    if (isset($holidayCols[$di])) continue;
                                    $v = trim($r[$di] ?? '');
                                    // 有打卡时间/标记（非空、非"-"、"无"等）算出勤
                                    if ($v !== '' && $v !== '-' && $v !== '无' && mb_strpos($v, '请假') === false && mb_strpos($v, '缺勤') === false) {
                                        $actDays++;
                                    }
                                }
                                $wh = $fullDays * 8;
                                $ah = max(0, ($fullDays - $actDays) * 8);
                            } else {
                                // 旧格式：直接读小时数
                                $wh = $idxWork !== null ? $parseNum($r[$idxWork] ?? '') : 0;
                                $ah = $idxAbsent !== null ? $parseNum($r[$idxAbsent] ?? '') : 0;
                            }
                            $rm = $idxRemark !== null ? trim($r[$idxRemark] ?? '') : '';

                            $empId = $empByName[$empName] ?? 0;
                            if ($empId <= 0) {
                                // 员工尚未添加：暂存到待匹配表，员工添加后自动补录
                                $notFound[] = $empName;
                                $skipped++;
                                $insPending->execute([$empName, $year, $month, $wh, $ah, $rm]);
                                continue;
                            }
                            $ins->execute([$empId, $year, $month, $wh, $ah, $rm]);
                            $inserted++;
                        }
                        db()->commit();
                        $msg = "导入完成：成功 {$inserted} 条";
                        if ($skipped > 0) $msg .= "，暂存待匹配 {$skipped} 条（员工添加后自动补录）";
                        if (!empty($notFound)) $msg .= "，未匹配员工：" . implode('、', array_slice($notFound, 0, 5)) . (count($notFound) > 5 ? ' 等' : '');
                        // 附加识别信息便于排查
                        $mode = $autoDayMode ? '自动统计(每日打卡列)' : '天数列直读';
                        $msg .= "【模式:{$mode}；姓名列:{$idxName}；满勤列:" . ($idxFullDays ?? '无') . "；实际出勤列:" . ($idxActualDays ?? '无') . "；数据行:" . count($dataRows) . "】";
                        // 预览解析到的表头和首行数据，便于确认文件内容正确
                        $previewHead = json_encode($firstRow, JSON_UNESCAPED_UNICODE);
                        $previewFirst = !empty($dataRows) ? json_encode($dataRows[0], JSON_UNESCAPED_UNICODE) : '(空)';
                        $msg .= "【表头:{$previewHead}；首行:{$previewFirst}】";
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

// 待匹配考勤（上传时员工尚未添加的行，员工添加后自动补录）
$pendingRows = [];
try {
    $ps = db()->prepare("SELECT * FROM attendance_pending WHERE year=? AND month=? ORDER BY employee_name");
    $ps->execute([$year, $month]);
    $pendingRows = $ps->fetchAll();
} catch (\Throwable $e) {}
$pendingCount = count($pendingRows);

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
        <?php if ($totalAbsent > 0): ?><span class="badge badge-warning ml-1">请假 <?php echo number_format($totalAbsent, 2); ?>h</span><?php endif; ?>
        <?php if ($pendingCount > 0): ?><span class="badge badge-secondary ml-1" title="上传考勤时这些员工尚未添加，已暂存；添加员工后自动补录">待匹配 <?php echo $pendingCount; ?> 人</span><?php endif; ?>
    </div>
</div>

<?php if ($pendingCount > 0): ?>
<div class="alert alert-info py-2">
    <a class="d-flex justify-content-between align-items-center text-decoration-none text-info" data-toggle="collapse" href="#pendingCollapse" role="button" aria-expanded="false" aria-controls="pendingCollapse">
        <span><i class="fas fa-info-circle"></i> <strong><?php echo $pendingCount; ?> 人</strong>的考勤已暂存（上传时员工尚未添加）。在<a href="<?php echo BASE_URL; ?>/employees/index.php" onclick="event.stopPropagation();">员工管理</a>中添加对应姓名的员工后，考勤会自动补录。</span>
        <i class="fas fa-chevron-down ml-2"></i>
    </a>
    <div class="collapse" id="pendingCollapse">
        <table class="table table-sm table-bordered mt-2 mb-0" style="max-width:600px">
            <thead><tr><th>姓名</th><th>应出勤(h)</th><th>请假(h)</th><th>备注</th></tr></thead>
            <tbody>
            <?php foreach ($pendingRows as $p): ?>
                <tr><td><?php echo e($p['employee_name']); ?></td><td><?php echo e($p['work_hours']); ?></td><td><?php echo e($p['absent_hours']); ?></td><td><?php echo e($p['remark']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

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
                    <input type="hidden" name="year" value="<?php echo $year; ?>">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
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
                        <textarea name="csv_data" id="csvData" class="form-control" rows="3" placeholder="姓名,满勤天数,实际出勤天数,备注&#10;张三,22,22,&#10;李四,22,20,请假2天"></textarea>
                        <small class="text-muted">用逗号分隔，第一行为表头</small>
                    </div>
                    <button type="submit" class="btn btn-success btn-block"><i class="fas fa-upload"></i> 开始上传</button>
                </form>
                <hr>
                <div class="text-muted small">
                    <b><i class="fas fa-info-circle text-info"></i> 文件格式要求：</b><br>
                    表头需包含：<code>姓名/员工</code> + <code>满勤天数</code> + <code>实际出勤天数</code>，可选 <code>备注</code><br>
                    系统按"满勤天数 × 8小时"计算应出勤，按"(满勤天数 - 实际出勤天数) × 8小时"计算请假<br>
                    也兼容旧格式：<code>应出勤(小时)</code> / <code>请假(小时)</code><br>
                    员工姓名必须与系统员工名一致，否则该行跳过
                </div>
            </div>
        </div>

        <!-- 手动添加单条 -->
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-hand-pointer text-primary"></i> 手动添加单条</h5></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="manual_add">
                    <input type="hidden" name="year" value="<?php echo $year; ?>">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
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
                            <label>满勤天数</label>
                            <input type="number" name="full_days" id="fullDays" class="form-control" step="0.5" min="0" value="22">
                            <small class="text-muted">应出勤 = 满勤天数 × 8小时</small>
                        </div>
                        <div class="form-group col-md-6">
                            <label>实际出勤天数</label>
                            <input type="number" name="actual_days" id="actualDays" class="form-control" step="0.5" min="0" value="22">
                            <small class="text-muted">请假 = (满勤 - 实际出勤) × 8小时</small>
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
                    <input type="hidden" name="year" value="<?php echo $year; ?>">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width:36px"><input type="checkbox" id="chkAll" onclick="document.querySelectorAll('.row-chk').forEach(c=>c.checked=this.checked)"></th>
                                    <th>员工</th>
                                    <th>部门</th>
                                    <th class="text-right">满勤天数</th>
                                    <th class="text-right">实际出勤</th>
                                    <th class="text-right">请假(h)</th>
                                    <th>备注</th>
                                    <th style="width:70px">状态</th>
                                    <th style="width:60px">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($records as $r):
                                $isFull = (float)$r['absent_hours'] == 0;
                                $fullDays  = (float)$r['work_hours'] / 8;
                                $actDays   = ((float)$r['work_hours'] - (float)$r['absent_hours']) / 8;
                            ?>
                                <tr>
                                    <td><input type="checkbox" name="ids[]" value="<?php echo $r['id']; ?>" class="row-chk"></td>
                                    <td><strong><?php echo e($r['name']); ?></strong></td>
                                    <td><span class="badge badge-info"><?php echo e($r['department']); ?></span></td>
                                    <td class="text-right"><?php echo number_format($fullDays, 2); ?>天</td>
                                    <td class="text-right <?php echo $isFull ? 'text-success' : ''; ?>"><?php echo number_format($actDays, 2); ?>天</td>
                                    <td class="text-right <?php echo $isFull ? '' : 'text-warning font-weight-bold'; ?>"><?php echo number_format($r['absent_hours'], 2); ?>h</td>
                                    <td><small class="text-muted"><?php echo e($r['remark']); ?></small></td>
                                    <td>
                                        <?php if ($isFull): ?>
                                            <span class="badge badge-success">满勤</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">请假</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-link p-0 text-danger" onclick="delOne(<?php echo $r['id']; ?>)" title="删除">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
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
    var csvData = document.getElementById('csvData');
    if (!area || !file) return;
    area.addEventListener('click', function(){ file.click(); });
    file.addEventListener('change', function(){
        if (file.files.length) {
            tip.textContent = file.files[0].name;
            // 选择了文件后清空粘贴框，避免旧数据覆盖新文件
            if (csvData) csvData.value = '';
        }
    });
    ['dragover','dragenter'].forEach(function(ev){ area.addEventListener(ev, function(e){ e.preventDefault(); area.style.borderColor='#28a745'; }); });
    ['dragleave','drop'].forEach(function(ev){ area.addEventListener(ev, function(e){ e.preventDefault(); area.style.borderColor=''; }); });
    area.addEventListener('drop', function(e){
        if (e.dataTransfer.files.length){
            file.files = e.dataTransfer.files;
            tip.textContent = file.files[0].name;
            if (csvData) csvData.value = '';
        }
    });
})();
// 提交前校验：若同时有文件和粘贴数据，提示优先使用文件
document.getElementById('uploadForm').addEventListener('submit', function(e){
    var file = document.getElementById('excelFile');
    var csvData = document.getElementById('csvData');
    if (file.files.length && csvData.value.trim() !== '') {
        if (!confirm('检测到同时有上传文件和粘贴数据，将优先使用上传文件。粘贴框的数据将被忽略，是否继续？')) {
            e.preventDefault();
        }
    }
});
function batchDelete(){
    var n = document.querySelectorAll('.row-chk:checked').length;
    if (n === 0) { alert('请先勾选要删除的记录'); return; }
    if (!confirm('确定删除选中的 ' + n + ' 条考勤记录？')) return;
    document.getElementById('delForm').submit();
}
function delOne(id){
    if (!confirm('删除该考勤记录？')) return;
    var f = document.createElement('form');
    f.method = 'post';
    f.style.display = 'none';
    f.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' + id + '"><input type="hidden" name="year" value="<?php echo $year; ?>"><input type="hidden" name="month" value="<?php echo $month; ?>">';
    document.body.appendChild(f);
    f.submit();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
