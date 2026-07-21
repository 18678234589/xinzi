<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$page_title = '店铺管理';
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $sort = (int)($_POST['sort'] ?? 0);

        if ($name === '') {
            $error = '店铺名称不能为空';
        } else {
            try {
                if ($action === 'create') {
                    $stmt = db()->prepare("INSERT INTO shops (name, sort) VALUES (?, ?)");
                    $stmt->execute([$name, $sort]);
                    $success = '店铺添加成功';
                } else {
                    $stmt = db()->prepare("UPDATE shops SET name=?, sort=? WHERE id=?");
                    $stmt->execute([$name, $sort, $id]);
                    $success = '店铺更新成功';
                }
            } catch (PDOException $ex) {
                if ($ex->getCode() == 23000) {
                    $error = '店铺名称已存在';
                } else {
                    $error = '操作失败: ' . $ex->getMessage();
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // 检查是否有订单归属该店铺
        $shop = get_shop($id);
        if ($shop) {
            $cnt = db()->prepare("SELECT COUNT(*) FROM orders WHERE shop = ?");
            $cnt->execute([$shop['name']]);
            if ((int)$cnt->fetchColumn() > 0) {
                $error = '该店铺下仍有订单，无法删除（请先转移或删除相关订单）';
            } else {
                try {
                    $stmt = db()->prepare("DELETE FROM shops WHERE id = ?");
                    $stmt->execute([$id]);
                    $success = '店铺已删除';
                } catch (PDOException $ex) {
                    $error = '删除失败: ' . $ex->getMessage();
                }
            }
        } else {
            $error = '店铺不存在';
        }
    }
}

// 自动检查/创建 shops 表（兼容未手动执行升级SQL的情况）
try {
    db()->query("SELECT 1 FROM shops LIMIT 1");
} catch (PDOException $e) {
    // 表不存在则自动创建
    $sql = "CREATE TABLE IF NOT EXISTS `shops` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL UNIQUE COMMENT '店铺名称',
        `sort` INT NOT NULL DEFAULT 0 COMMENT '排序(越小越靠前)',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    db()->exec($sql);
}

// 确保 orders 表有 shop 字段（用于店铺关联订单统计）
try {
    $shopCols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'shop'")->fetchAll();
    if (empty($shopCols)) {
        db()->exec("ALTER TABLE `orders` ADD COLUMN `shop` VARCHAR(100) DEFAULT '' COMMENT '店铺' AFTER `project`");
    }
} catch (PDOException $e) {}

// 搜索参数
$filter_month   = trim($_GET['month'] ?? date('Y-m', strtotime('-1 month')));
$filter_shop    = trim($_GET['shop'] ?? '');
$filter_order_no = trim($_GET['order_no'] ?? '');
$hasSearch      = ($filter_month !== '' || $filter_shop !== '' || $filter_order_no !== '');

// 构建带筛选的店铺列表
if ($hasSearch) {
    $where = "COALESCE(o.is_deleted, 0) = 0";
    $params = [];
    if ($filter_month !== '') {
        $where .= " AND DATE_FORMAT(o.order_date, '%Y-%m') = ?";
        $params[] = $filter_month;
    }
    if ($filter_shop !== '') {
        $where .= " AND o.shop = ?";
        $params[] = $filter_shop;
    }
    if ($filter_order_no !== '') {
        $where .= " AND o.order_no LIKE ?";
        $params[] = '%' . $filter_order_no . '%';
    }
    try {
        $sql = "SELECT s.*, (SELECT COUNT(*) FROM orders o WHERE o.shop = s.name AND {$where}) AS order_count FROM shops s ORDER BY s.sort ASC, s.id ASC";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $shops = $stmt->fetchAll();
    } catch (PDOException $e) {
        $shops = [];
    }
} else {
    $shops = get_shop_list();
}

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-store"></i> 店铺管理</h4>
    <button class="btn btn-primary" data-toggle="modal" data-target="#shopModal" onclick="resetForm()">
        <i class="fas fa-plus"></i> 新增店铺
    </button>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<!-- 搜索栏 -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" class="form-row align-items-end">
            <div class="col-md-3">
                <label class="mb-0 small">月份</label>
                <input type="month" name="month" class="form-control form-control-sm" value="<?php echo e($filter_month); ?>">
            </div>
            <div class="col-md-3">
                <label class="mb-0 small">店铺</label>
                <select name="shop" class="form-control form-control-sm">
                    <option value="">全部店铺</option>
                    <?php foreach (get_shop_list() as $sl): ?>
                    <option value="<?php echo e($sl['name']); ?>" <?php echo $filter_shop === $sl['name'] ? 'selected' : ''; ?>><?php echo e($sl['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="mb-0 small">订单号</label>
                <input type="text" name="order_no" class="form-control form-control-sm" placeholder="输入订单号关键词" value="<?php echo e($filter_order_no); ?>">
            </div>
            <div class="col-md-3 d-flex">
                <button type="submit" class="btn btn-primary btn-sm mr-1"><i class="fas fa-search"></i> 搜索</button>
                <?php if ($hasSearch): ?>
                <a href="<?php echo BASE_URL; ?>/shops/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i> 清除</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered">
                <thead class="thead-light">
                    <tr><th style="width:80px">ID</th><th>店铺名称</th><th style="width:100px">排序</th><th style="width:120px"><?php echo $hasSearch ? '筛选订单数' : '订单数'; ?></th><th style="width:200px">操作</th></tr>
                </thead>
                <tbody>
                <?php if ($shops): foreach ($shops as $s): ?>
                    <tr>
                        <td><?php echo $s['id']; ?></td>
                        <td><span class="badge badge-info"><i class="fas fa-store"></i> <?php echo e($s['name']); ?></span></td>
                        <td><?php echo $s['sort']; ?></td>
                        <td><span class="badge badge-secondary"><?php echo $s['order_count']; ?> 笔</span></td>
                        <td>
                            <a href="<?php echo BASE_URL; ?>/shops/upload.php?shop_id=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-success" title="为该店铺上传订单">
                                <i class="fas fa-file-upload"></i> 上传订单
                            </a>
                            <button class="btn btn-sm btn-outline-primary" onclick='editShop(<?php echo json_encode($s, JSON_UNESCAPED_UNICODE); ?>)'>
                                <i class="fas fa-edit"></i> 编辑
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('确定删除该店铺？')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i> 删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">暂无店铺数据</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 新增/编辑模态框 -->
<div class="modal fade" id="shopModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="shopForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">新增店铺</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="id" value="">
                    <div class="form-group">
                        <label>店铺名称 <span class="required">*</span></label>
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
    $('#shopForm')[0].reset();
    $('#shopForm input[name="action"]').val('create');
    $('#shopForm input[name="id"]').val('');
    $('#shopForm input[name="sort"]').val(0);
    $('#modalTitle').text('新增店铺');
}

function editShop(s) {
    resetForm();
    $('#shopForm input[name="action"]').val('update');
    $('#shopForm input[name="id"]').val(s.id);
    $('#shopForm input[name="name"]').val(s.name);
    $('#shopForm input[name="sort"]').val(s.sort);
    $('#modalTitle').text('编辑店铺 - ' + s.name);
    $('#shopModal').modal('show');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
