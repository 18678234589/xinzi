<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo = db();

// 确保 is_deleted 字段存在
$hasCol = $pdo->query("SHOW COLUMNS FROM `orders` LIKE 'is_deleted'")->fetchAll();
if (empty($hasCol)) {
    $pdo->exec("ALTER TABLE `orders` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=正常 1=已删除(回收站)' AFTER `order_scope`");
    $pdo->exec("ALTER TABLE `orders` ADD INDEX `idx_deleted` (`is_deleted`)");
}

$page_title = '回收站';
$success = '';
$error = '';

// ========== 处理操作 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'restore') {
        // 恢复选中订单
        $ids = $_POST['ids'] ?? [];
        $ids = array_filter(array_map('intval', $ids), fn($v) => $v > 0);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE orders SET is_deleted=0 WHERE id IN ({$placeholders}) AND is_deleted=1");
            $stmt->execute($ids);
            $cnt = $stmt->rowCount();
            $success = "已恢复 {$cnt} 条订单";
        } else {
            $error = '未选择任何订单';
        }

    } elseif ($action === 'destroy') {
        // 永久删除选中订单
        $ids = $_POST['ids'] ?? [];
        $ids = array_filter(array_map('intval', $ids), fn($v) => $v > 0);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM orders WHERE id IN ({$placeholders}) AND is_deleted=1");
            $stmt->execute($ids);
            $cnt = $stmt->rowCount();
            $success = "已永久删除 {$cnt} 条订单";
        } else {
            $error = '未选择任何订单';
        }

    } elseif ($action === 'restore_all') {
        // 恢复全部（可按月份/部门过滤）
        $month = $_POST['month'] ?? '';
        $dept  = $_POST['department'] ?? '';
        $sql = "UPDATE orders SET is_deleted=0 WHERE is_deleted=1";
        $params = [];
        if ($month) {
            $sql .= " AND DATE_FORMAT(order_date, '%Y-%m') = ?";
            $params[] = $month;
        }
        if ($dept) {
            $sql .= " AND order_scope = 'department' AND employee_id = 0 AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__dept__')) = ?";
            $params[] = $dept;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $cnt = $stmt->rowCount();
        $success = "已恢复 {$cnt} 条订单";

    } elseif ($action === 'empty_all') {
        // 清空回收站（可按月份/部门过滤）
        $month = $_POST['month'] ?? '';
        $dept  = $_POST['department'] ?? '';
        $sql = "DELETE FROM orders WHERE is_deleted=1";
        $params = [];
        if ($month) {
            $sql .= " AND DATE_FORMAT(order_date, '%Y-%m') = ?";
            $params[] = $month;
        }
        if ($dept) {
            $sql .= " AND order_scope = 'department' AND employee_id = 0 AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__dept__')) = ?";
            $params[] = $dept;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $cnt = $stmt->rowCount();
        $success = "已永久删除 {$cnt} 条订单";
    }
}

// ========== 查询参数 ==========
$filter_month = trim($_GET['month'] ?? '');
$filter_dept  = trim($_GET['department'] ?? '');
$filter_scope = trim($_GET['scope'] ?? '');  // personal / department
$page         = max(1, (int)($_GET['page'] ?? 1));
$per_page     = 50;

// ========== 构建查询 ==========
$where = " WHERE o.is_deleted = 1 ";
$params = [];

if ($filter_month) {
    $where .= " AND DATE_FORMAT(o.order_date, '%Y-%m') = ? ";
    $params[] = $filter_month;
}
if ($filter_dept) {
    $where .= " AND o.order_scope = 'department' AND o.employee_id = 0 AND JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__dept__')) = ? ";
    $params[] = $filter_dept;
}
if ($filter_scope === 'personal') {
    $where .= " AND o.order_scope = 'personal' ";
} elseif ($filter_scope === 'department') {
    $where .= " AND o.order_scope = 'department' ";
}

// 总数 & 金额
$countSql = "SELECT COUNT(*) FROM orders o" . $where;
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total_count = (int)$stmt->fetchColumn();

$sumSql = "SELECT COALESCE(SUM(o.order_amount), 0) FROM orders o" . $where;
$stmt = $pdo->prepare($sumSql);
$stmt->execute($params);
$total_amount = (float)$stmt->fetchColumn();

// 分页数据
$offset = ($page - 1) * $per_page;
$total_pages = max(1, (int)ceil($total_count / $per_page));

$listSql = "
    SELECT o.*, e.name AS emp_name, e.department AS emp_dept
    FROM orders o
    LEFT JOIN employees e ON e.id = o.employee_id
    " . $where . "
    ORDER BY o.order_date DESC, o.id DESC
    LIMIT {$per_page} OFFSET {$offset}
";
$stmt = $pdo->prepare($listSql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// 回收站统计：按月份汇总
$monthStats = $pdo->query("
    SELECT DATE_FORMAT(order_date, '%Y-%m') AS ym, COUNT(*) AS cnt, COALESCE(SUM(order_amount), 0) AS amt
    FROM orders WHERE is_deleted = 1
    GROUP BY ym ORDER BY ym DESC
")->fetchAll();

// 部门列表
$departments = get_departments();

// 构建查询参数（用于分页链接）
$baseQ = [];
if ($filter_month) $baseQ['month'] = $filter_month;
if ($filter_dept)  $baseQ['department'] = $filter_dept;
if ($filter_scope) $baseQ['scope'] = $filter_scope;

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-recycle text-warning"></i> 回收站</h4>
    <a href="<?php echo BASE_URL; ?>/orders/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i> 返回订单管理
    </a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<!-- 统计卡片 -->
<div class="row mb-3">
    <div class="col-md-4">
        <div class="card stat-card orange">
            <div class="card-body d-flex align-items-center">
                <i class="fas fa-trash-restore fa-2x text-warning mr-3"></i>
                <div>
                    <div class="text-muted small">已删除订单</div>
                    <div class="h4 mb-0 font-weight-bold"><?php echo $total_count; ?> 条</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card blue">
            <div class="card-body d-flex align-items-center">
                <i class="fas fa-yen-sign fa-2x text-info mr-3"></i>
                <div>
                    <div class="text-muted small">已删除金额</div>
                    <div class="h4 mb-0 font-weight-bold">¥<?php echo money($total_amount); ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small mb-2"><i class="fas fa-calendar"></i> 按月份汇总</div>
                <?php if ($monthStats): ?>
                    <div style="max-height:120px;overflow-y:auto">
                        <?php foreach ($monthStats as $ms): ?>
                            <a href="?month=<?php echo e($ms['ym']); ?>" class="badge <?php echo ($filter_month === $ms['ym']) ? 'badge-warning' : 'badge-light text-dark border'; ?> mr-2 mb-1" style="font-size:.85em">
                                <?php echo e($ms['ym']); ?> · <?php echo $ms['cnt']; ?>条 / ¥<?php echo money($ms['amt']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <span class="text-muted">无数据</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 筛选 -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" class="form-inline flex-wrap">
            <input type="month" name="month" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($filter_month); ?>" placeholder="月份">
            <select name="department" class="form-control form-control-sm mr-2 mb-1">
                <option value="">全部部门</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?php echo e($d); ?>" <?php echo $filter_dept === $d ? 'selected' : ''; ?>><?php echo e($d); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="scope" class="form-control form-control-sm mr-2 mb-1">
                <option value="">全部类型</option>
                <option value="personal" <?php echo $filter_scope === 'personal' ? 'selected' : ''; ?>>个人订单</option>
                <option value="department" <?php echo $filter_scope === 'department' ? 'selected' : ''; ?>>部门订单</option>
            </select>
            <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fas fa-search"></i> 筛选</button>
            <?php if ($filter_month || $filter_dept || $filter_scope): ?>
                <a href="?" class="btn btn-sm btn-outline-secondary mb-1">清除筛选</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- 订单列表 -->
<div class="card">
    <div class="card-header bg-white">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="mb-0"><i class="fas fa-list text-warning"></i> 已删除订单
                <small class="text-muted" style="font-size:.8em">共 <?php echo $total_count; ?> 条 / ¥<?php echo money($total_amount); ?></small>
            </h5>
            <div class="d-flex flex-wrap gap-2">
                <form method="post" id="restoreAllForm" class="d-inline" onsubmit="return confirm('确定恢复当前筛选条件下的全部 <?php echo $total_count; ?> 条订单？')">
                    <input type="hidden" name="action" value="restore_all">
                    <input type="hidden" name="month" value="<?php echo e($filter_month); ?>">
                    <input type="hidden" name="department" value="<?php echo e($filter_dept); ?>">
                    <button type="submit" class="btn btn-sm btn-outline-success" <?php echo $total_count ? '' : 'disabled'; ?>>
                        <i class="fas fa-trash-restore"></i> 全部恢复
                    </button>
                </form>
                <form method="post" id="emptyForm" class="d-inline" onsubmit="return confirm('警告：将永久删除当前筛选条件下的 <?php echo $total_count; ?> 条订单，此操作不可恢复！确定继续？')">
                    <input type="hidden" name="action" value="empty_all">
                    <input type="hidden" name="month" value="<?php echo e($filter_month); ?>">
                    <input type="hidden" name="department" value="<?php echo e($filter_dept); ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" <?php echo $total_count ? '' : 'disabled'; ?>>
                        <i class="fas fa-eraser"></i> 清空回收站
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <form method="post" id="batchForm">
            <input type="hidden" name="action" value="restore" id="batchAction">
            <!-- 批量操作栏 -->
            <div id="batchBar" class="d-flex align-items-center p-2 bg-light border-bottom" style="display:none">
                <span id="selectedCount" class="text-muted small mr-3">已选 0 条</span>
                <button type="submit" class="btn btn-sm btn-success mr-2" onclick="document.getElementById('batchAction').value='restore'">
                    <i class="fas fa-trash-restore"></i> 恢复选中
                </button>
                <button type="submit" class="btn btn-sm btn-danger" onclick="document.getElementById('batchAction').value='destroy';return confirm('永久删除选中的订单，此操作不可恢复！确定？')">
                    <i class="fas fa-times"></i> 永久删除选中
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th style="width:32px"><input type="checkbox" id="checkAll" title="全选" onclick="var cbs=document.querySelectorAll('.row-check');cbs.forEach(function(c){c.checked=this.checked;}.bind(this));var n=this.checked?cbs.length:0;document.getElementById('selectedCount').textContent='已选 '+n+' 条';document.getElementById('batchBar').style.display=n>0?'flex':'none';"></th>
                            <th>ID</th>
                            <th>类型</th>
                            <th>员工/部门</th>
                            <th>项目</th>
                            <th>金额</th>
                            <th>订单日期</th>
                            <th>删除时间</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($orders): foreach ($orders as $o): ?>
                        <?php $rawData = !empty($o['raw_data']) ? (json_decode($o['raw_data'], true) ?: []) : []; ?>
                        <?php $isDeptSummary = ($o['order_scope'] === 'department' && (int)$o['employee_id'] === 0); ?>
                        <?php $isFromDept = isset($rawData['__from_dept__']); ?>
                        <tr>
                            <td><input type="checkbox" class="row-check" name="ids[]" value="<?php echo $o['id']; ?>" onclick="var cbs=document.querySelectorAll('.row-check'),n=0;cbs.forEach(function(c){if(c.checked)n++;});var ca=document.getElementById('checkAll');if(ca){ca.checked=n===cbs.length;ca.indeterminate=n>0&&n<cbs.length;}document.getElementById('selectedCount').textContent='已选 '+n+' 条';document.getElementById('batchBar').style.display=n>0?'flex':'none';"></td>
                            <td><?php echo $o['id']; ?></td>
                            <td>
                                <?php if ($isDeptSummary): ?>
                                    <span class="badge badge-success"><i class="fas fa-building"></i> 部门汇总</span>
                                <?php elseif ($isFromDept): ?>
                                    <span class="badge badge-warning"><i class="fas fa-share-alt"></i> 部门拆分</span>
                                <?php else: ?>
                                    <span class="badge badge-primary"><i class="fas fa-user"></i> 个人</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isDeptSummary): ?>
                                    <?php $dept = $rawData['__dept__'] ?? ''; ?>
                                    <span class="text-success"><?php echo e($dept ?: '部门'); ?></span>
                                <?php else: ?>
                                    <?php echo e($o['emp_name'] ?: '--'); ?>
                                    <?php if ($o['emp_dept']): ?>
                                        <small class="text-muted d-block"><?php echo e($o['emp_dept']); ?></small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e($o['project'] ?? '--'); ?></td>
                            <td class="text-success font-weight-bold">¥<?php echo money($o['order_amount']); ?></td>
                            <td class="text-muted small"><?php echo !empty($o['order_date']) ? e($o['order_date']) : '--'; ?></td>
                            <td class="text-muted small"><?php echo !empty($o['created_at']) ? e(date('Y-m-d H:i', strtotime($o['created_at']))) : '--'; ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="8" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>回收站为空</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <?php if ($total_pages > 1): ?>
        <nav class="p-2">
            <ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($baseQ, ['page' => 1])); ?>">«</a></li>
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($baseQ, ['page' => $page - 1])); ?>">‹</a></li>
                <?php $start = max(1, $page - 2); $end = min($total_pages, $page + 2); if ($start > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; for ($i = $start; $i <= $end; $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($baseQ, ['page' => $i])); ?>"><?php echo $i; ?></a></li>
                <?php endfor; if ($end < $total_pages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; ?>
                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($baseQ, ['page' => $page + 1])); ?>">›</a></li>
                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($baseQ, ['page' => $total_pages])); ?>">»</a></li>
            </ul>
            <p class="text-center text-muted small mt-1 mb-0">第 <?php echo $page; ?> / <?php echo $total_pages; ?> 页，每页 <?php echo $per_page; ?> 条</p>
        </nav>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
