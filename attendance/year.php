<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$page_title = '考勤表 - 年度';
$success = '';
$error = '';

ensureAttendanceTable();

$year = (int)($_GET['year'] ?? 0);
if ($year <= 0) {
    header('Location: ' . BASE_URL . '/attendance/index.php');
    exit;
}

$months = get_attendance_months($year);
// 构造 1-12 月完整列表（未录入的月份也显示空卡片）
$monthMap = [];
foreach ($months as $m) { $monthMap[$m['month']] = $m; }
$allEmployees = get_employees();
$empCount = count($allEmployees);

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="<?php echo BASE_URL; ?>/attendance/index.php" class="btn btn-outline-secondary btn-sm mr-2">
            <i class="fas fa-arrow-left"></i> 返回年份
        </a>
        <h4 class="font-weight-bold mb-0 d-inline-block">
            <i class="fas fa-calendar-alt text-primary"></i> <?php echo $year; ?> 年考勤
        </h4>
    </div>
    <span class="text-muted small">共 <?php echo $empCount; ?> 名员工</span>
</div>

<div class="alert alert-info py-2">
    <i class="fas fa-info-circle"></i>
    点击月份卡片进入录入该月考勤。<b>应出勤小时数</b>建议填 22天×8小时=176；<b>请假小时数</b>支持小数（如请半天填4）。
</div>

<div class="row">
    <?php for ($m = 1; $m <= 12; $m++):
        $info = $monthMap[$m] ?? null;
        $hasData = $info !== null;
        $empN = $hasData ? (int)$info['emp_count'] : 0;
        $absent = $hasData ? (float)$info['total_absent'] : 0;
        $work = $hasData ? (float)$info['total_work'] : 0;
    ?>
    <div class="col-md-3 col-sm-6 mb-4">
        <a href="<?php echo BASE_URL; ?>/attendance/month.php?year=<?php echo $year; ?>&month=<?php echo $m; ?>" class="text-decoration-none text-dark">
            <div class="card month-card h-100 shadow-sm" style="border-left:4px solid <?php echo $hasData ? '#28a745' : '#dee2e6'; ?>; transition:transform .15s;">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="mb-0 font-weight-bold"><?php echo $m; ?>月</h5>
                        <?php if ($hasData): ?>
                            <span class="badge badge-success"><i class="fas fa-check"></i> 已录</span>
                        <?php else: ?>
                            <span class="badge badge-light text-muted"><i class="fas fa-pen"></i> 待录</span>
                        <?php endif; ?>
                    </div>
                    <div class="row text-center small mt-3">
                        <div class="col-6 border-right">
                            <div class="font-weight-bold <?php echo $hasData ? 'text-info' : 'text-muted'; ?>"><?php echo $empN; ?></div>
                            <div class="text-muted">已录员工</div>
                        </div>
                        <div class="col-6">
                            <div class="font-weight-bold <?php echo $absent > 0 ? 'text-warning' : 'text-muted'; ?>"><?php echo number_format($absent, 1); ?>h</div>
                            <div class="text-muted">请假合计</div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-light text-center py-1">
                    <span class="small <?php echo $hasData ? 'text-success' : 'text-primary'; ?>">
                        <i class="fas fa-<?php echo $hasData ? 'eye' : 'pen'; ?>"></i>
                        <?php echo $hasData ? '查看/编辑' : '录入考勤'; ?>
                    </span>
                </div>
            </div>
        </a>
    </div>
    <?php endfor; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
