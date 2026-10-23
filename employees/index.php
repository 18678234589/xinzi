<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/SalaryCalculator.php';
require_once __DIR__ . '/../includes/ProjectPartnerProfile.php';
require_login();
// 列表只依赖旺旺字段，保存时再检查完整绩效结构。
if ($_SERVER['REQUEST_METHOD'] === 'POST' || !db()->query("SHOW COLUMNS FROM employees LIKE 'wangwang'")->fetch()) {
    ensureCsPerfSchema();
}

$page_title = '合作人员管理';
$success = '';
$error = '';

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id              = (int)($_POST['id'] ?? 0);
        $name            = trim($_POST['name'] ?? '');
        $department      = trim($_POST['department'] ?? '');
        $wangwang        = trim($_POST['wangwang'] ?? '');
        $base_salary     = (float)($_POST['base_salary'] ?? 0);
        $commission_rate = (float)($_POST['commission_rate'] ?? 0);
        $password        = trim($_POST['password'] ?? '');

        if ($name === '' || $department === '') {
            $error = '姓名和部门不能为空';
        } else {
            try {
                if ($action === 'create') {
                    if ($password === '') $password = '123456'; // 默认密码
                    $stmt = db()->prepare("INSERT INTO employees (name, department, wangwang, base_salary, commission_rate, password) VALUES (?, ?, ?, ?, ?, MD5(?))");
                    $stmt->execute([$name, $department, $wangwang, $base_salary, $commission_rate, $password]);
                    $newId = (int)db()->lastInsertId();
                    // 自动补录之前因合作人员不存在而暂存的考勤记录
                    $backfilled = backfill_pending_attendance($newId, $name);
                    if (isset($_POST['profile_present'])) ppp_save($newId, $_POST, ps_actor()); // 结算资料（仅财务 / 管理员）
                    $success = '合作人员添加成功，默认密码: ' . ($password === '123456' ? '123456' : '已设置');
                    if ($backfilled > 0) $success .= "，已自动补录 {$backfilled} 条考勤记录";
                } else {
                    if ($password !== '') {
                        $stmt = db()->prepare("UPDATE employees SET name=?, department=?, wangwang=?, base_salary=?, commission_rate=?, password=MD5(?) WHERE id=?");
                        $stmt->execute([$name, $department, $wangwang, $base_salary, $commission_rate, $password, $id]);
                    } else {
                        $stmt = db()->prepare("UPDATE employees SET name=?, department=?, wangwang=?, base_salary=?, commission_rate=? WHERE id=?");
                        $stmt->execute([$name, $department, $wangwang, $base_salary, $commission_rate, $id]);
                    }
                    // 改名后尝试补录新姓名对应的暂存考勤
                    backfill_pending_attendance($id, $name);
                    if (isset($_POST['profile_present'])) ppp_save($id, $_POST, ps_actor());
                    $success = '合作人员信息更新成功';
                }
            } catch (PDOException $ex) {
                $error = '操作失败: ' . $ex->getMessage();
            } catch (RuntimeException $ex) {
                $error = '结算资料未保存：' . $ex->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            // orders 表的合作人员外键已被移除（部门订单需存 employee_id=0，见 orders/index.php 的 ensureProjectColumn），
            // ON DELETE CASCADE 已失效，这里手动清理关联数据，避免留下指向不存在合作人员的孤儿订单/项目报酬记录。
            db()->beginTransaction();
            // 订单软删除（移入回收站，可恢复），项目报酬和合作人员记录物理删除
            db()->prepare("UPDATE orders SET is_deleted=1 WHERE employee_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM salaries WHERE employee_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);
            db()->commit();
            $success = '合作人员已删除';
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

$where = " FROM employees WHERE 1=1";
$params = [];
if ($filter_dept) { $where .= " AND department = ?"; $params[] = $filter_dept; }
if ($keyword) { $where .= " AND name LIKE ?"; $params[] = "%{$keyword}%"; }
$stmt = db()->prepare('SELECT COUNT(*)' . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$page_size = 25;
$page_count = max(1, (int)ceil($total / $page_size));
$page = max(1, min($page_count, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $page_size;
$sql = 'SELECT id,name,department,wangwang,base_salary,commission_rate' . $where
    . " ORDER BY department, name, id LIMIT $page_size OFFSET $offset";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();
$profiles = ppp_all(); // 结算资料：本页仅管理员（财务）登录可进入

$departments = get_departments();

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-users"></i> 合作人员管理</h4>
    <button class="btn btn-primary" data-toggle="modal" data-target="#empModal" onclick="resetForm()">
        <i class="fas fa-plus"></i> 新增合作人员
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

<!-- 合作人员列表 -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered">
                <thead class="thead-light">
                    <tr>
                        <th>ID</th><th>姓名</th><th>部门</th><th>旺旺账号</th><th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($employees): foreach ($employees as $emp): $has_algorithm = SalaryCalculator::hasCustomAlgorithm($emp['id']); ?>
                    <tr>
                        <td><?php echo $emp['id']; ?></td>
                        <td><?php echo e($emp['name']); ?></td>
                        <td><span class="badge badge-info"><?php echo e($emp['department']); ?></span></td>
                        <td><?php echo e($emp['wangwang'] ?? ''); ?></td>
                        <td>
                            <a href="<?php echo BASE_URL; ?>/orders/index.php?employee_id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-outline-success" title="为该合作人员上传订单">
                                <i class="fas fa-file-upload"></i> 上传订单
                            </a>
                            <a href="<?php echo BASE_URL; ?>/employees/algorithm.php?employee_id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-outline-<?php echo $has_algorithm ? 'warning' : 'secondary'; ?>" title="项目报酬算法设置">
                                <i class="fas fa-code"></i> <?php echo $has_algorithm ? '专属算法' : '算法设置'; ?>
                            </a>
                            <button class="btn btn-sm btn-outline-primary" onclick='editEmp(<?php echo json_encode($emp + ['profile' => $profiles[(int)$emp['id']] ?? null], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                <i class="fas fa-edit"></i> 编辑
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('确定删除该合作人员？相关订单和项目报酬记录也会被删除。')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i> 删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">暂无合作人员数据</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <nav class="d-flex justify-content-between align-items-center mt-3" aria-label="合作人员分页">
            <span class="text-muted">共 <?php echo $total; ?> 人，第 <?php echo $page; ?> / <?php echo $page_count; ?> 页</span>
            <div>
                <?php foreach ([$page - 1 => '上一页', $page + 1 => '下一页'] as $target_page => $label): ?>
                    <?php if ($target_page >= 1 && $target_page <= $page_count): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="?<?php echo e(http_build_query(['department' => $filter_dept, 'keyword' => $keyword, 'page' => $target_page])); ?>"><?php echo $label; ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </nav>
    </div>
</div>

<!-- 新增/编辑模态框 -->
<div class="modal fade" id="empModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="empForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">新增合作人员</h5>
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
                    <div class="form-group">
                        <label>旺旺账号</label>
                        <input type="text" name="wangwang" class="form-control" placeholder="客服旺旺账号，用于客服绩效数据匹配">
                        <small class="text-muted">客服绩效导入时按此账号/姓名自动匹配到合作人员，一人多账号用逗号/空格分隔</small>
                    </div>
                    <hr><div class="small text-muted mb-2"><i class="fas fa-lock mr-1"></i>结算资料：仅财务和管理员可见、可改，合作人员本人看不到</div>
                    <input type="hidden" name="profile_present" value="1">
                    <div class="form-row">
                        <div class="form-group col-6"><label>主管</label><input type="text" name="supervisor" class="form-control" maxlength="40"></div>
                        <div class="form-group col-6"><label>买保险公司</label><input type="text" name="insurance_company" class="form-control" maxlength="60"></div>
                    </div>
                    <div class="form-group"><label>工行卡号</label><input type="text" name="bank_card" class="form-control" inputmode="numeric" maxlength="32" placeholder="12–25 位数字"></div>
                    <div class="form-row">
                        <div class="form-group col-6"><label>已扣除预留金（元）</label><input type="text" name="reserve_deducted" class="form-control" inputmode="decimal" placeholder="留空表示无"></div>
                        <div class="form-group col-6 d-flex align-items-end"><label class="mb-2"><input type="checkbox" name="signed" value="1"> 已签名确认</label></div>
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
    $('#modalTitle').text('新增合作人员');
}

function editEmp(emp) {
    resetForm();
    $('#empForm input[name="action"]').val('update');
    $('#empForm input[name="id"]').val(emp.id);
    $('#empForm input[name="name"]').val(emp.name);
    $('#empForm input[name="department"]').val(emp.department);
    $('#empForm input[name="wangwang"]').val(emp.wangwang || '');
    $('#empForm input[name="password"]').val('');
    var pf = emp.profile || {};
    $('#empForm input[name="supervisor"]').val(pf.supervisor || '');
    $('#empForm input[name="insurance_company"]').val(pf.insurance_company || '');
    $('#empForm input[name="bank_card"]').val(pf.bank_card || '');
    $('#empForm input[name="reserve_deducted"]').val(pf.reserve_deducted === null || pf.reserve_deducted === undefined ? '' : pf.reserve_deducted);
    $('#empForm input[name="signed"]').prop('checked', String(pf.signed) === '1');
    $('#modalTitle').text('编辑合作人员 - ' + emp.name);
    $('#empModal').modal('show');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
