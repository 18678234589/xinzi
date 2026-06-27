<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$page_title = '部门管理';
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $sort = (int)($_POST['sort'] ?? 0);

        if ($name === '') {
            $error = '部门名称不能为空';
        } else {
            try {
                if ($action === 'create') {
                    $stmt = db()->prepare("INSERT INTO departments (name, sort) VALUES (?, ?)");
                    $stmt->execute([$name, $sort]);
                    $success = '部门添加成功';
                } else {
                    $stmt = db()->prepare("UPDATE departments SET name=?, sort=? WHERE id=?");
                    $stmt->execute([$name, $sort, $id]);
                    $success = '部门更新成功';
                }
            } catch (PDOException $ex) {
                if ($ex->getCode() == 23000) {
                    $error = '部门名称已存在';
                } else {
                    $error = '操作失败: ' . $ex->getMessage();
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // 检查是否有员工属于该部门
        $dept = get_department($id);
        if ($dept) {
            $cnt = db()->prepare("SELECT COUNT(*) FROM employees WHERE department = ?");
            $cnt->execute([$dept['name']]);
            if ((int)$cnt->fetchColumn() > 0) {
                $error = '该部门下仍有员工，无法删除（请先转移或删除相关员工）';
            } else {
                try {
                    $stmt = db()->prepare("DELETE FROM departments WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = '部门已删除';
                } catch (PDOException $ex) {
                    $error = '删除失败: ' . $ex->getMessage();
                }
            }
        } else {
            $error = '部门不存在';
        }
    }
}

// 自动检查/创建 departments 表（兼容未手动执行升级SQL的情况）
try {
    db()->query("SELECT 1 FROM departments LIMIT 1");
} catch (PDOException $e) {
    // 表不存在则自动创建
    $sql = "CREATE TABLE IF NOT EXISTS `departments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL UNIQUE COMMENT '部门名称',
        `sort` INT NOT NULL DEFAULT 0 COMMENT '排序(越小越靠前)',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    db()->exec($sql);
    // 从现有员工数据提取部门初始化
    $emps = get_employees();
    foreach ($emps as $emp) {
        if ($emp['department']) {
            try { db()->prepare("INSERT IGNORE INTO departments (name, sort) VALUES (?, ?)")->execute([$emp['department'], 0]); } catch (Exception $ex) {}
        }
    }
}

$departments = get_department_list();

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-sitemap"></i> 部门管理</h4>
    <button class="btn btn-primary" data-toggle="modal" data-target="#deptModal" onclick="resetForm()">
        <i class="fas fa-plus"></i> 新增部门
    </button>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered">
                <thead class="thead-light">
                    <tr><th style="width:80px">ID</th><th>部门名称</th><th style="width:100px">排序</th><th style="width:120px">员工数</th><th style="width:200px">操作</th></tr>
                </thead>
                <tbody>
                <?php if ($departments): foreach ($departments as $d): ?>
                    <tr>
                        <td><?php echo $d['id']; ?></td>
                        <td><span class="badge badge-info"><i class="fas fa-building"></i> <?php echo e($d['name']); ?></span></td>
                        <td><?php echo $d['sort']; ?></td>
                        <td><span class="badge badge-secondary"><?php echo $d['emp_count']; ?> 人</span></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick='editDept(<?php echo json_encode($d, JSON_UNESCAPED_UNICODE); ?>)'>
                                <i class="fas fa-edit"></i> 编辑
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('确定删除该部门？')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i> 删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">暂无部门数据</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 新增/编辑模态框 -->
<div class="modal fade" id="deptModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="deptForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">新增部门</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="id" value="">
                    <div class="form-group">
                        <label>部门名称 <span class="required">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>排序</label>
                        <input type="number" name="sort" class="form-control" value="0" min="0">
                        <small class="text-muted">数字越小越靠前，默认0</small>
                    </div>
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
    $('#deptForm')[0].reset();
    $('#deptForm input[name="action"]').val('create');
    $('#deptForm input[name="id"]').val('');
    $('#deptForm input[name="sort"]').val(0);
    $('#modalTitle').text('新增部门');
}

function editDept(d) {
    resetForm();
    $('#deptForm input[name="action"]').val('update');
    $('#deptForm input[name="id"]').val(d.id);
    $('#deptForm input[name="name"]').val(d.name);
    $('#deptForm input[name="sort"]').val(d.sort);
    $('#modalTitle').text('编辑部门 - ' + d.name);
    $('#deptModal').modal('show');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
