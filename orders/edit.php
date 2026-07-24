<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';

$order_id = (int)($_GET['id'] ?? 0);
if ($order_id <= 0) {
    header('Location: ' . BASE_URL . '/orders/index.php');
    exit;
}

// 查询订单
$stmt = db()->prepare("SELECT * FROM orders WHERE id = ? AND COALESCE(is_deleted, 0) = 0");
$stmt->execute([$order_id]);
$order = $stmt->fetch();
$stmt->closeCursor();

if (!$order) {
    header('Location: ' . BASE_URL . '/orders/index.php');
    exit;
}

$rawData = json_decode($order['raw_data'], true) ?: [];
$error = '';
$success = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update') {
        $newAmount = (float)($_POST['order_amount'] ?? 0);
        $newOrderNo = trim($_POST['order_no'] ?? '');
        $newStatus = trim($_POST['order_status'] ?? '');
        
        // 更新订单金额
        $updateFields = ['order_amount' => $newAmount];
        
        // 更新订单号
        if ($newOrderNo !== '') {
            $updateFields['order_no'] = $newOrderNo;
        }
        
        // 更新订单状态
        if ($newStatus !== '') {
            $rawData['__order_status__'] = $newStatus;
            $updateFields['raw_data'] = json_encode($rawData, JSON_UNESCAPED_UNICODE);
            
            // 如果状态变为正常（交易成功/已到账/已发货），清除异常标记
            if (in_array($newStatus, ['交易成功', '已到账', '已发货'])) {
                $updateFields['is_abnormal'] = 0;
                $updateFields['abnormal_reason'] = '';
            }
        }
        
        // 构建更新SQL
        $setClauses = [];
        $params = [];
        foreach ($updateFields as $field => $value) {
            $setClauses[] = "`{$field}` = ?";
            $params[] = $value;
        }
        $params[] = $order_id;
        
        $sql = "UPDATE orders SET " . implode(', ', $setClauses) . " WHERE id = ?";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        
        $success = '订单更新成功！';
        
        // 刷新订单数据
        $stmt = db()->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
        $stmt->closeCursor();
        $rawData = json_decode($order['raw_data'], true) ?: [];
    }
}

$page_title = '编辑订单';
define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4><i class="fas fa-edit text-primary"></i> 编辑订单 #<?php echo $order_id; ?></h4>
    <a href="<?php echo BASE_URL; ?>/orders/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i> 返回列表
    </a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0">订单信息</h5></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="update">
                    
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>订单ID</label>
                            <input type="text" class="form-control" value="<?php echo $order['id']; ?>" disabled>
                        </div>
                        <div class="form-group col-md-6">
                            <label>员工</label>
                            <?php
                            $empStmt = db()->prepare("SELECT name FROM employees WHERE id = ?");
                            $empStmt->execute([$order['employee_id']]);
                            $empName = $empStmt->fetchColumn();
                            $empStmt->closeCursor();
                            ?>
                            <input type="text" class="form-control" value="<?php echo e($empName ?: '未知'); ?>" disabled>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>订单金额 <span class="text-danger">*</span></label>
                            <input type="number" name="order_amount" class="form-control" value="<?php echo $order['order_amount']; ?>" step="0.01" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>订单编号</label>
                            <input type="text" name="order_no" class="form-control" value="<?php echo e($order['order_no']); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>订单日期</label>
                            <input type="text" class="form-control" value="<?php echo e($order['order_date']); ?>" disabled>
                        </div>
                        <div class="form-group col-md-6">
                            <label>模块</label>
                            <input type="text" class="form-control" value="<?php echo e($order['project'] ?: '未分类'); ?>" disabled>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>订单状态</label>
                        <select name="order_status" class="form-control">
                            <option value="未核验" <?php echo ($rawData['__order_status__'] ?? '') === '未核验' ? 'selected' : ''; ?>>未核验</option>
                            <option value="交易成功" <?php echo ($rawData['__order_status__'] ?? '') === '交易成功' ? 'selected' : ''; ?>>交易成功</option>
                            <option value="已发货" <?php echo ($rawData['__order_status__'] ?? '') === '已发货' ? 'selected' : ''; ?>>已发货</option>
                            <option value="已取消" <?php echo ($rawData['__order_status__'] ?? '') === '已取消' ? 'selected' : ''; ?>>已取消</option>
                            <option value="退款" <?php echo ($rawData['__order_status__'] ?? '') === '退款' ? 'selected' : ''; ?>>退款</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>异常状态</label>
                        <input type="text" class="form-control" value="<?php echo $order['is_abnormal'] ? '异常 - ' . e($order['abnormal_reason']) : '正常'; ?>" disabled>
                    </div>
                    
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> 保存修改</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0">原始数据</h5></div>
            <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                <?php if (!empty($rawData)): ?>
                    <table class="table table-sm table-bordered" style="font-size: 12px;">
                        <thead class="thead-light"><tr><th>字段</th><th>值</th></tr></thead>
                        <tbody>
                        <?php foreach ($rawData as $k => $v): ?>
                            <tr>
                                <td><small><?php echo e($k); ?></small></td>
                                <td><small><?php echo is_array($v) ? e(json_encode($v, JSON_UNESCAPED_UNICODE)) : e((string)$v); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="text-muted">无原始数据</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
