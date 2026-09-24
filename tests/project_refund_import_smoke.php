<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectRefundImport.php';

function refund_check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

refund_check(ps_import_date('260901') === '2026-09-01', 'YYMMDD 日期解析失败');
refund_check(ps_import_date('46263') === '2026-08-29', 'Excel 日期序列号解析失败');
refund_check(ps_import_original_name('C:\\upload\\中文退款表.xls') === '中文退款表.xls', 'Windows 中文文件名未保留');
$map = ps_refund_header_map(['退款日期', '成交时间', '付款账号（旺旺名）', '店铺订单号', '退款金额', '店铺+业务', '退款类型']);
refund_check($map['order_no'] === 3 && $map['amount'] === 4 && $map['business'] === 5 && $map['method'] === 6, '退款部原表表头未识别');
$cashbackMap = ps_refund_header_map(['返现日期', '店铺', '订单编号', '付款昵称', '返现方式', '支付宝', '手机号', '返现金额', '客服', '是否完成', '返款后实际金额', '客服记录']);
refund_check($cashbackMap['refund_date'] === 0 && $cashbackMap['order_no'] === 2 && $cashbackMap['method'] === 4 && $cashbackMap['amount'] === 7 && $cashbackMap['reason'] === 11, '网站返现原表表头未识别');

$pdo = db(); $pdo->beginTransaction();
try {
    $adminId = (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
    $employeeId = (int)$pdo->query('SELECT id FROM employees ORDER BY id LIMIT 1')->fetchColumn();
    refund_check($adminId && $employeeId, '测试需要财务管理员及合作人员');
    $staff = ['type' => 'employee', 'id' => 900000 + $employeeId, 'employee_id' => $employeeId, 'role' => 'customer_service'];
    $finance = ['type' => 'admin', 'id' => $adminId, 'employee_id' => null, 'role' => 'finance'];
    $cashbackPath = __DIR__ . '/../订单模板与成本及算法/综合售后部/8月宋文娜返现（网站）/8月宋文娜返现（网站）.xlsx';
    if (is_file($cashbackPath)) {
        [$cashbackRows] = ps_refund_parse_file(['id' => 0, 'stored_name' => 'cashback.xlsx', 'content' => file_get_contents($cashbackPath)], $finance);
        refund_check(count($cashbackRows) === 7, '网站返现原表应识别七笔明细并跳过合计行');
        refund_check(count(array_filter($cashbackRows, function ($r) { return $r['error'] === ''; })) === 6, '网站返现原表应仅排除非支付宝行');
        refund_check(count(array_filter($cashbackRows, function ($r) { return $r['warning'] !== ''; })) >= 1, '个人微信或垫付备注应进入财务待核');
    }
    $refundPath = __DIR__ . '/../订单模板与成本及算法/综合售后部/2026年8月退款部退款表.xlsx';
    if (is_file($refundPath)) {
        [$refundRows] = ps_refund_parse_file(['id' => 0, 'stored_name' => 'refund.xlsx', 'content' => file_get_contents($refundPath)], $finance);
        refund_check(count($refundRows) === 67, '大体积退款部原表未正确识别明细');
        refund_check(count(array_filter($refundRows, function ($r) { return $r['error'] === ''; })) === 4, '退款部混合业务应只保留网站支付宝四笔待核');
    }
    $no = 'REFUND-TEST-' . bin2hex(random_bytes(6));
    $pdo->prepare("INSERT INTO project_orders (order_no,project_type,contract_amount,order_date,delivery_status) VALUES (?,'网站模板',500,CURDATE(),'finished')")->execute([$no]);
    $orderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$orderId, $employeeId]);
    $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,review_status,submitted_by_type,submitted_by_id) VALUES (?,'receipt',500,'approved','system',0)")->execute([$orderId]);
    ps_recalculate_cash($orderId);
    $input = ['order_no' => $no, 'refund_date' => '2026-08-29', 'amount' => '100', 'reference' => 'SMOKE-' . bin2hex(random_bytes(5)), 'method' => '支付宝', 'business' => '美呀美网站'];
    $fingerprint = hash('sha256', 'test|' . $input['reference']);
    $preview = ps_refund_preview_row($input, $fingerprint, $staff);
    refund_check($preview['error'] === '' && $preview['order_id'] === $orderId, '合作人员退款预览未通过');
    $otherId = (int)$pdo->query('SELECT id FROM employees WHERE id<>' . $employeeId . ' ORDER BY id LIMIT 1')->fetchColumn();
    if ($otherId) {
        $other = ['type' => 'employee', 'id' => 900000 + $otherId, 'employee_id' => $otherId, 'role' => 'customer_service'];
        $otherPreview = ps_refund_preview_row($input, hash('sha256', 'other|' . $no), $other);
        refund_check($otherPreview['error'] === '' && $otherPreview['available'] === '—', '非参与人应能提交待审线索但不能看到原单余额');
    }
    refund_check(ps_refund_commit_rows([$preview], ['0'], $staff, '2099-12') === 1, '合作人员未能提交待审退款');
    $refundId = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    $q = $pdo->prepare('SELECT review_status FROM project_refund_import_rows WHERE fingerprint=?'); $q->execute([$fingerprint]);
    refund_check($q->fetchColumn() === 'pending', '合作人员上传不能直接审核');
    $q = $pdo->prepare('SELECT refund_amount FROM project_orders WHERE id=?'); $q->execute([$orderId]);
    refund_check((float)$q->fetchColumn() === 0.0, '待审退款不应改变订单金额');
    $q = $pdo->prepare('SELECT id FROM project_refund_import_rows WHERE fingerprint=?'); $q->execute([$fingerprint]); $refundId = (int)$q->fetchColumn();
    ps_refund_review($refundId, 'approved', $finance, '2099-12');
    $q->execute([$fingerprint]); refund_check((int)$q->fetchColumn() === $refundId, '审核后退款记录丢失');
    $q = $pdo->prepare('SELECT refund_amount FROM project_orders WHERE id=?'); $q->execute([$orderId]);
    refund_check((float)$q->fetchColumn() === 100.0, '财务审核后退款金额不正确');
    refund_check(ps_refund_preview_row($input, $fingerprint, $staff)['error'] !== '', '相同流水号重复上传未拦截');
    $unmatched = ps_refund_preview_row(['order_no' => 'NOT-YET-' . $no, 'refund_date' => '2026-08-29', 'amount' => '10', 'method' => '支付宝', 'business' => '网站'], hash('sha256', 'unmatched|' . $no), $staff);
    refund_check($unmatched['error'] === '' && $unmatched['status'] === '待财务匹配订单', '暂未匹配订单不应挡住上传留档');
    $noRef = ps_refund_preview_row(['order_no' => $no, 'refund_date' => '2026-08-30', 'amount' => '10', 'method' => '支付宝'], hash('sha256', 'no-ref|' . $no), $finance);
    refund_check($noRef['error'] === '' && $noRef['warning'] !== '', '无流水号须提示财务核实');
    refund_check(ps_refund_commit_rows([$noRef], ['0'], $finance, '2099-12') === 1, '财务应能先留档无流水号退款');
    $q = $pdo->prepare('SELECT review_status FROM project_refund_import_rows WHERE fingerprint=?'); $q->execute([$noRef['fingerprint']]);
    refund_check($q->fetchColumn() === 'pending', '无流水号不能自动通过财务审核');
    $kindNo = 'KIND-TEST-' . bin2hex(random_bytes(6));
    $pdo->prepare("INSERT INTO project_orders (order_no,project_type,order_kind,contract_amount,order_date,delivery_status) VALUES (?,'小程序开发','新订单',888,CURDATE(),'finished')")->execute([$kindNo]);
    $kindOrderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','技术',1)")->execute([$kindOrderId, $employeeId]);
    ps_reclassify_order_kind($kindOrderId, '定制', $finance, '2099-12', true);
    $q = $pdo->prepare('SELECT order_kind FROM project_orders WHERE id=?'); $q->execute([$kindOrderId]);
    refund_check($q->fetchColumn() === '定制', '财务未能一键纠正订单类型');
    refund_check(ps_import_kind_preference($employeeId, '小程序开发', 'test-layout') === '定制', '财务纠正没有成为以后同类上传的默认类型');
    $_SESSION['admin_id'] = $adminId;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/project/refunds.php';
    ob_start(); include __DIR__ . '/../project/refunds.php'; $refundHtml = ob_get_clean();
    refund_check(strpos($refundHtml, '上传已有退款表') !== false && strpos($refundHtml, '核对或修正原订单号') !== false, '财务退款上传与审核页面未正常渲染');
    unset($_SESSION['admin_id']);
    $pdo->rollBack();
    echo "网站退款/返现原表、大体积工作簿、待审与防重、财务分类纠正均通过；测试数据已回滚\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
