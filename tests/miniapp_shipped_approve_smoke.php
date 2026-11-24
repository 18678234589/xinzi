<?php
// 小程序定制：店铺状态已发货但交付没标完成时，财务 / 批量审核也能通过（并自动记为已完成）；没发货的仍不能通过。事务内执行，结束回滚。
// DB_NAME=xinzi_test DB_HOST=127.0.0.1 DB_PORT=13399 php tests/miniapp_shipped_approve_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectMonthly.php';
$pdo = db(); $pdo->beginTransaction();
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
try {
    $finance = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'employee_id' => null, 'role' => 'finance'];
    $emp = (int)$pdo->query("SELECT id FROM employees ORDER BY id LIMIT 1")->fetchColumn();
    $mk = function ($no, $status) use ($pdo, $emp) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','小程序开发','定制','美呀美旗舰店',800,800,'2027-03-05','unfinished','draft','测试')")->execute([$no]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO project_order_sources (order_id,payment_nickname,trade_status,price_source,nickname_source,status_source) VALUES (?,'测试',?,'manual','manual','shop_upload')")->execute([$id, $status]);
        $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','制作技术',1)")->execute([$id, $emp]);
        return $id;
    };
    $a = $mk('MSA-1', '卖家已发货，等待买家确认');
    try { ps_approve_order($a, $finance, '2027-03'); $ok = true; } catch (Throwable $e) { $ok = $e->getMessage(); }
    $check($ok === true, '已发货的小程序定制（交付没标完成）可以审核通过：' . (is_string($ok) ? $ok : 'ok'));
    $check($pdo->query("SELECT delivery_status FROM project_orders WHERE id=$a")->fetchColumn() === 'finished', '审核时交付状态自动记为已完成');
    $b = $mk('MSA-2', '买家已付款,等待卖家发货');
    $msg = ''; try { ps_approve_order($b, $finance, '2027-03'); } catch (Throwable $e) { $msg = $e->getMessage(); }
    $check($msg !== '' && $pdo->query("SELECT delivery_status FROM project_orders WHERE id=$b")->fetchColumn() === 'unfinished', '待发货的仍不能审核：' . $msg);
    echo "全部通过\n";
} finally { $pdo->rollBack(); }
