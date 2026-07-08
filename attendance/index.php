<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$page_title = '考勤表';
$success = '';
$error = '';

ensureAttendanceTable();

// ===== 后端处理：删除某年考勤卡片 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_year') {
    $delYear = (int)($_POST['year'] ?? 0);
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM attendances WHERE year=?");
        $stmt->execute([$delYear]);
        $cnt = (int)$stmt->fetchColumn();
        db()->prepare("DELETE FROM attendances WHERE year=?")->execute([$delYear]);
        hide_attendance_year($delYear);
        $success = "已删除 {$delYear} 年考勤卡片" . ($cnt > 0 ? "（含 {$cnt} 条记录）" : '');
    } catch (PDOException $ex) {
        $error = '删除失败: ' . $ex->getMessage();
    }
}

// ===== 后端处理：添加年份卡片 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_year') {
    $newYear = (int)($_POST['new_year'] ?? 0);
    $cy = (int)date('Y');
    if ($newYear < 2000 || $newYear > 2100) {
        $error = '年份不合法';
    } elseif ($newYear < $cy) {
        $error = '只能添加当前年及以后的年份';
    } else {
        try {
            add_attendance_custom_year($newYear);
            $success = "已添加 {$newYear} 年考勤卡片";
        } catch (PDOException $ex) {
            $error = '添加失败: ' . $ex->getMessage();
        }
    }
}

// 当前年默认填当年
$currentYear = (int)date('Y');
$years = get_attendance_years();

// 合并用户手动添加的年份
$custom = get_attendance_custom_years();
$years = array_values(array_unique(array_merge($years, $custom)));
rsort($years);

// 如果还没有任何年份记录，展示当前年和上一年供录入
if (empty($years)) {
    $years = [$currentYear, $currentYear - 1];
}

// 排除被隐藏（已删除卡片）的年份
$hidden = get_attendance_hidden_years();
$years = array_values(array_filter($years, function ($y) use ($hidden) {
    return !in_array($y, $hidden);
}));

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-calendar-alt text-primary"></i> 考勤表</h4>
    <div class="d-flex align-items-center">
        <span class="text-muted small mr-3 d-none d-sm-inline"><i class="fas fa-info-circle"></i> 点击年份卡片进入对应月份列表</span>
        <button type="button" class="btn btn-outline-primary btn-sm" data-toggle="modal" data-target="#addYearModal">
            <i class="fas fa-plus"></i> 添加年份
        </button>
    </div>
</div>

<!-- 添加年份 弹窗 -->
<div class="modal fade" id="addYearModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="add_year">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-calendar-plus text-primary"></i> 添加年份卡片</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>要添加的年份</label>
                        <input type="number" name="new_year" class="form-control" min="<?php echo (int)date('Y'); ?>" max="2100" value="<?php echo (int)date('Y') + 1; ?>" required>
                        <small class="text-muted">只能添加当前年（<?php echo (int)date('Y'); ?>）及以后的年份</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check"></i> 确定添加</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<div class="row">
    <?php foreach ($years as $y):
        $months = get_attendance_months($y);
        $monthCount = count($months);
        $totalEmp = array_sum(array_column($months, 'emp_count'));
        $totalAbsent = array_sum(array_column($months, 'total_absent'));
        $hasData = $monthCount > 0;
    ?>
    <div class="col-md-4 col-sm-6 mb-4">
        <a href="<?php echo BASE_URL; ?>/attendance/year.php?year=<?php echo $y; ?>" class="text-decoration-none text-dark">
            <div class="card year-card h-100 border-0 shadow-sm <?php echo $hasData ? 'border-left-primary' : ''; ?>" style="border-left:4px solid <?php echo $hasData ? '#007bff' : '#dee2e6'; ?>; transition:transform .15s;">
                <div class="card-body text-center py-4">
                    <div class="mb-2">
                        <i class="fas fa-calendar fa-2x <?php echo $hasData ? 'text-primary' : 'text-muted'; ?>"></i>
                    </div>
                    <h3 class="font-weight-bold mb-1"><?php echo $y; ?></h3>
                    <div class="text-muted small mb-3">年度考勤</div>

                    <div class="d-flex justify-content-around border-top pt-3">
                        <div>
                            <div class="h5 mb-0 font-weight-bold <?php echo $hasData ? 'text-primary' : 'text-muted'; ?>"><?php echo $monthCount; ?></div>
                            <small class="text-muted">已录月份</small>
                        </div>
                        <div>
                            <div class="h5 mb-0 font-weight-bold <?php echo $hasData ? 'text-info' : 'text-muted'; ?>"><?php echo $totalEmp; ?></div>
                            <small class="text-muted">员工记录</small>
                        </div>
                        <div>
                            <div class="h5 mb-0 font-weight-bold <?php echo $totalAbsent > 0 ? 'text-warning' : 'text-muted'; ?>"><?php echo number_format($totalAbsent, 1); ?>h</div>
                            <small class="text-muted">请假合计</small>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-light d-flex justify-content-between align-items-center py-2">
                    <span class="small <?php echo $hasData ? 'text-primary' : 'text-muted'; ?>">
                        <i class="fas fa-<?php echo $hasData ? 'arrow-right' : 'plus'; ?>"></i>
                        <?php echo $hasData ? '查看月份' : '开始录入'; ?>
                    </span>
                    <form method="post" class="mb-0" onclick="event.stopPropagation()" onsubmit="return confirm('确定删除 <?php echo $y; ?> 年的考勤卡片？')">
                        <input type="hidden" name="action" value="delete_year">
                        <input type="hidden" name="year" value="<?php echo $y; ?>">
                        <button type="submit" class="btn btn-sm btn-link text-danger p-0" title="删除<?php echo $y; ?>年卡片">
                            <i class="fas fa-trash-alt"></i> 删除
                        </button>
                    </form>
                </div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<?php if (empty(get_attendance_years())): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle"></i> 暂无考勤记录。点击上方年份卡片进入，选择月份后为员工录入考勤（应出勤小时数、请假小时数）。
    录入后数据会自动供"全勤奖"等薪资模块计算使用。
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
