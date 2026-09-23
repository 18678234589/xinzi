<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
require_once __DIR__ . '/../includes/ProjectIntake.php';

$pdo = db();
$pdo->beginTransaction();
try {
    $employeeIds = array_map('intval', $pdo->query('SELECT id FROM employees ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
    if (count($employeeIds) < 2) throw new RuntimeException('测试至少需要两位合作人员');
    $no = 'SOURCE-' . bin2hex(random_bytes(7));
    $pdo->prepare("INSERT INTO project_orders (order_no,project_type,order_date) VALUES (?,'AI网站定制',CURDATE())")->execute([$no]);
    $id = (int)$pdo->lastInsertId();
    ps_source_record($id, 'missing', '', '');
    ps_intake_save_resources($id, 'manual', null, null, null, null, 'pending');
    ps_intake_participants($id, [
        'technical' => [$employeeIds[0] => ['id' => $employeeIds[0], 'role' => '前端']],
        'customer_service' => [$employeeIds[1] => ['id' => $employeeIds[1], 'role' => '客服']],
    ]);
    foreach ($employeeIds as $employeeId) {
        if ((int)ps_order($id, ['role' => 'technical', 'employee_id' => $employeeId])['id'] !== $id) throw new RuntimeException('参与人看不到同一订单');
    }
    $raw = ['买家付款昵称' => '测试买家', '__order_status__' => '交易成功', '__original_price__' => 6800];
    if (!ps_sync_project_from_shop_order(123, $no, '测试店铺', $raw, 6800)) throw new RuntimeException('店铺上传没有补空字段');
    $q = $pdo->prepare('SELECT o.shop,o.contract_amount,o.receipt_amount,s.payment_nickname,s.trade_status,s.price_source FROM project_orders o JOIN project_order_sources s ON s.order_id=o.id WHERE o.id=?');
    $q->execute([$id]);
    $row = $q->fetch();
    if ($row['shop'] !== '测试店铺' || (float)$row['contract_amount'] !== 6800.0 || (float)$row['receipt_amount'] !== 0.0 || $row['payment_nickname'] !== '测试买家' || $row['trade_status'] !== '交易成功' || $row['price_source'] !== 'shop_upload') throw new RuntimeException('上传补全结果或实收隔离不正确');
    if (ps_sync_project_from_shop_order(124, $no, '别的店铺', $raw, 9000)) throw new RuntimeException('跨店铺同号不应覆盖');
    if (ps_sync_project_from_shop_order(125, $no, '测试店铺', ['__is_refund__' => '1'], -6800)) throw new RuntimeException('退款行不应当作售价');
    ps_sync_project_from_shop_order(126, $no, '测试店铺', ['付款昵称' => '另一个人', '__order_status__' => '交易关闭'], 9000);
    $q->execute([$id]);
    $row = $q->fetch();
    if ((float)$row['contract_amount'] !== 6800.0 || $row['payment_nickname'] !== '测试买家' || $row['trade_status'] !== '交易成功') throw new RuntimeException('二次上传不应覆盖已补全信息');
    $pdo->prepare("INSERT INTO project_orders (order_no,project_type,contract_amount,order_date) VALUES (?,'AI网站定制',5000,CURDATE())")->execute([$no . '-MANUAL']);
    $manualId = (int)$pdo->lastInsertId();
    ps_source_record($manualId, 'manual', '人工昵称', '');
    ps_sync_project_from_shop_order(127, $no . '-MANUAL', '测试店铺', $raw, 9000);
    $manual = $pdo->query('SELECT contract_amount FROM project_orders WHERE id=' . $manualId)->fetchColumn();
    if ((float)$manual !== 5000.0) throw new RuntimeException('上传覆盖了人工确认的售价');
    $templateNo = $no . '-TEMPLATE';
    $pdo->prepare("INSERT INTO project_orders (order_no,project_type,order_date) VALUES (?,'网站模板',CURDATE())")->execute([$templateNo]);
    $templateOrderId = (int)$pdo->lastInsertId();
    ps_source_record($templateOrderId, 'missing', '', '');
    ps_intake_save_resources($templateOrderId, 'manual', null, null, null, null, 'pending');
    ps_intake_participants($templateOrderId, [
        'technical' => [$employeeIds[0] => ['id' => $employeeIds[0], 'role' => '模板技术']],
        'customer_service' => [$employeeIds[1] => ['id' => $employeeIds[1], 'role' => '网站客服']],
    ]);
    $shop = (string)$pdo->query('SELECT name FROM shops ORDER BY sort,id LIMIT 1')->fetchColumn();
    if ($shop === '') throw new RuntimeException('测试需要店铺');
    $csActor = ['type' => 'employee', 'id' => 1, 'employee_id' => $employeeIds[1], 'role' => 'customer_service'];
    $changed = ps_save_customer_intake($templateOrderId, ['shop' => $shop, 'contract_amount' => '6800', 'payment_nickname' => '模板买家', 'trade_status' => '交易成功'], $csActor);
    if (count($changed) !== 4) throw new RuntimeException('客服未能在共享订单补齐买家资料');
    try {
        ps_save_customer_intake($templateOrderId, ['contract_amount' => '9900'], $csActor);
        throw new RuntimeException('客服覆盖了已填写售价');
    } catch (RuntimeException $expected) {
        if (strpos($expected->getMessage(), '没有可补充') === false) throw $expected;
    }
    $adminId = (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
    $_SESSION['admin_id'] = $adminId;
    $_GET = ['id' => $templateOrderId];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/project/order.php';
    ob_start(); include __DIR__ . '/../project/order.php'; $templateHtml = ob_get_clean();
    if (strpos($templateHtml, '客服提交 · 买家与交易信息') === false || strpos($templateHtml, '模板技术提交 · 交付资料') === false || strpos($templateHtml, 'name="server_template_id"') === false) throw new RuntimeException('网站模板同单提交页面未完整渲染');
    unset($_SESSION['admin_id']);
    $pdo->prepare("INSERT INTO project_cost_templates (category,name,specification,unit,cost_kind,price,requires_proof,auto_approve) VALUES ('domain','.com域名','1年','年','annual',75,0,1),('server','基础VPS','1年','年','annual',480,0,1)")->execute();
    $domainId = (int)$pdo->query("SELECT id FROM project_cost_templates WHERE name='.com域名' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $serverId = (int)$pdo->query("SELECT id FROM project_cost_templates WHERE name='基础VPS' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $techActor = ['type' => 'employee', 'id' => 2, 'employee_id' => $employeeIds[0], 'role' => 'technical'];
    ps_intake_confirm_resources($templateOrderId, 'template', $domainId, $serverId, $techActor);
    $costQuery = $pdo->prepare('SELECT category,amount FROM project_costs WHERE order_id=? ORDER BY id');
    $costQuery->execute([$templateOrderId]);
    $templateCosts = $costQuery->fetchAll();
    if (count($templateCosts) !== 2 || $templateCosts[0]['category'] !== 'domain' || (float)$templateCosts[0]['amount'] !== 75.0 || $templateCosts[1]['category'] !== 'server' || (float)$templateCosts[1]['amount'] !== 480.0) throw new RuntimeException('模板技术的域名/服务器成本未正确带入');
    try {
        ps_intake_confirm_resources($templateOrderId, 'template', $domainId, $serverId, $techActor);
        throw new RuntimeException('重复确认生成了第二份成本');
    } catch (RuntimeException $expected) {
        if (strpos($expected->getMessage(), '资源已确认') === false) throw $expected;
    }
    $pdo->rollBack();
    echo "订单号草稿、客服技术共享、上传补空、财务实收隔离与防覆盖验证通过；数据已回滚\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
