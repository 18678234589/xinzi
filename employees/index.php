<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/SalaryCalculator.php';
require_login();

$page_title = '员工管理';
$success = '';
$error = '';

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id              = (int)($_POST['id'] ?? 0);
        $name            = trim($_POST['name'] ?? '');
        $department      = trim($_POST['department'] ?? '');
        $base_salary     = (float)($_POST['base_salary'] ?? 0);
        $commission_rate = (float)($_POST['commission_rate'] ?? 0);
        $password        = trim($_POST['password'] ?? '');

        if ($name === '' || $department === '') {
            $error = '姓名和部门不能为空';
        } else {
            try {
                if ($action === 'create') {
                    if ($password === '') $password = '123456'; // 默认密码
                    $stmt = db()->prepare("INSERT INTO employees (name, department, base_salary, commission_rate, password) VALUES (?, ?, ?, ?, MD5(?))");
                    $stmt->execute([$name, $department, $base_salary, $commission_rate, $password]);
                    $newId = (int)db()->lastInsertId();
                    // 自动补录之前因员工不存在而暂存的考勤记录
                    $backfilled = backfill_pending_attendance($newId, $name);
                    $success = '员工添加成功，默认密码: ' . ($password === '123456' ? '123456' : '已设置');
                    if ($backfilled > 0) $success .= "，已自动补录 {$backfilled} 条考勤记录";
                } else {
                    if ($password !== '') {
                        $stmt = db()->prepare("UPDATE employees SET name=?, department=?, base_salary=?, commission_rate=?, password=MD5(?) WHERE id=?");
                        $stmt->execute([$name, $department, $base_salary, $commission_rate, $password, $id]);
                    } else {
                        $stmt = db()->prepare("UPDATE employees SET name=?, department=?, base_salary=?, commission_rate=? WHERE id=?");
                        $stmt->execute([$name, $department, $base_salary, $commission_rate, $id]);
                    }
                    // 改名后尝试补录新姓名对应的暂存考勤
                    backfill_pending_attendance($id, $name);
                    $success = '员工信息更新成功';
                }
            } catch (PDOException $ex) {
                $error = '操作失败: ' . $ex->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            // orders 表的员工外键已被移除（部门订单需存 employee_id=0，见 orders/index.php 的 ensureProjectColumn），
            // ON DELETE CASCADE 已失效，这里手动清理关联数据，避免留下指向不存在员工的孤儿订单/薪资记录。
            db()->beginTransaction();
            // 订单软删除（移入回收站，可恢复），薪资和员工记录物理删除
            db()->prepare("UPDATE orders SET is_deleted=1 WHERE employee_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM salaries WHERE employee_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);
            db()->commit();
            $success = '员工已删除';
        } catch (PDOException $ex) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $error = '删除失败: ' . $ex->getMessage();
        }
    }
}

// 筛选
$filter_dept = $_GET['department'] ?? '';
$keyword     = trim($_GET['keyword'] ?? '');

$sql = "SELECT * FROM employees WHERE 1=1";
$params = [];
if ($filter_dept) { $sql .= " AND department = ?"; $params[] = $filter_dept; }
if ($keyword) { $sql .= " AND name LIKE ?"; $params[] = "%{$keyword}%"; }
$sql .= " ORDER BY department, name";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

$departments = get_departments();

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-users"></i> 员工管理</h4>
    <button class="btn btn-primary" data-toggle="modal" data-target="#empModal" onclick="resetForm()">
        <i class="fas fa-plus"></i> 新增员工
    </button>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<!-- 筛选栏 -->
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="form-inline">
            <div class="form-group mr-2">
                <label class="mr-1">部门:</label>
                <select name="department" class="form-control form-control-sm">
                    <option value="">全部部门</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?php echo e($d); ?>" <?php echo $filter_dept === $d ? 'selected' : ''; ?>><?php echo e($d); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mr-2">
                <input type="text" name="keyword" class="form-control form-control-sm" placeholder="搜索姓名" value="<?php echo e($keyword); ?>">
            </div>
            <button type="submit" class="btn btn-sm btn-info mr-1"><i class="fas fa-search"></i> 筛选</button>
            <a href="index.php" class="btn btn-sm btn-secondary"><i class="fas fa-redo"></i> 重置</a>
        </form>
    </div>
</div>

<!-- 员工列表 -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered">
                <thead class="thead-light">
                    <tr>
                        <th>ID</th><th>姓名</th><th>部门</th><th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($employees): foreach ($employees as $emp): ?>
                    <tr>
                        <td><?php echo $emp['id']; ?></td>
                        <td><?php echo e($emp['name']); ?></td>
                        <td><span class="badge badge-info"><?php echo e($emp['department']); ?></span></td>
                        <td>
                            <a href="<?php echo BASE_URL; ?>/orders/index.php?employee_id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-outline-success" title="为该员工上传订单">
                                <i class="fas fa-file-upload"></i> 上传订单
                            </a>
                            <a href="<?php echo BASE_URL; ?>/employees/algorithm.php?employee_id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-outline-<?php echo SalaryCalculator::hasCustomAlgorithm($emp['id']) ? 'warning' : 'secondary'; ?>" title="薪资算法设置">
                                <i class="fas fa-code"></i> <?php echo SalaryCalculator::hasCustomAlgorithm($emp['id']) ? '专属算法' : '算法设置'; ?>
                            </a>
                            <button class="btn btn-sm btn-outline-primary" onclick='editEmp(<?php echo json_encode($emp, JSON_UNESCAPED_UNICODE); ?>)'>
                                <i class="fas fa-edit"></i> 编辑
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('确定删除该员工？相关订单和薪资记录也会被删除。')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i> 删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">暂无员工数据</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 新增/编辑模态框 -->
<div class="modal fade" id="empModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="empForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">新增员工</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="id" value="">
                    <div class="form-group">
                        <label>姓名 <span class="required">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>部门 <span class="required">*</span></label>
                        <input type="text" name="department" class="form-control" list="deptList" required>
                        <datalist id="deptList">
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo e($d); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label>登录密码</label>
                        <input type="text" name="password" class="form-control" placeholder="新增时留空默认123456，编辑时留空不修改">
                        <small class="text-muted">密码使用MD5加密存储</small>
                    </div>
                    <input type="hidden" name="base_salary" value="0">
                    <input type="hidden" name="commission_rate" value="0">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> 保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetForm() {
    $('#empForm')[0].reset();
    $('#empForm input[name="action"]').val('create');
    $('#empForm input[name="id"]').val('');
    $('#modalTitle').text('新增员工');
}

function editEmp(emp) {
    resetForm();
    $('#empForm input[name="action"]').val('update');
    $('#empForm input[name="id"]').val(emp.id);
    $('#empForm input[name="name"]').val(emp.name);
    $('#empForm input[name="department"]').val(emp.department);
    $('#empForm input[name="password"]').val('');
    $('#modalTitle').text('编辑员工 - ' + emp.name);
    $('#empModal').modal('show');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
