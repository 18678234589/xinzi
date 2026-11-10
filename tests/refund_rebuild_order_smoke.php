<?php
// tests/refund_rebuild_order_smoke.php
// Smoke test for prm_source_cs and prm_rebuild_order

$baseDir = file_exists(__DIR__ . '/../includes/ProjectRefundImport.php') ? __DIR__ . '/..' : '/myprograms/hezuoshang';
require_once $baseDir . '/includes/ProjectRefundImport.php';
require_once $baseDir . '/includes/ProjectRefundMatch.php';

function run_test($name, callable $fn) {
    try {
        $fn();
        echo "[PASS] $name\n";
    } catch (Throwable $e) {
        echo "[FAIL] $name: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }
}

$pdo = db();

run_test('prm_source_cs resolves Su Ting and Dong Xu', function() use ($pdo) {
    $r321 = $pdo->query("SELECT * FROM project_refund_import_rows WHERE id=321")->fetch(PDO::FETCH_ASSOC);
    if ($r321) {
        [$id, $name] = prm_source_cs($r321);
        if ($name !== '苏婷' || $id !== 83) {
            throw new RuntimeException("Expected [83, '苏婷'], got [$id, '$name']");
        }
    }

    $r293 = $pdo->query("SELECT * FROM project_refund_import_rows WHERE id=293")->fetch(PDO::FETCH_ASSOC);
    if ($r293) {
        [$id, $name] = prm_source_cs($r293);
        if ($name !== '董旭' || $id !== 84) {
            throw new RuntimeException("Expected [84, '董旭'], got [$id, '$name']");
        }
    }
});

run_test('prm_rebuild_order permission checks and validation', function() use ($pdo) {
    $nonFinance = ['type' => 'employee', 'id' => 83, 'role' => 'cs'];
    $finance = ['type' => 'admin', 'id' => 1, 'role' => 'finance'];

    try {
        prm_rebuild_order(321, '网站模板', 83, '店铺', $nonFinance);
        throw new RuntimeException("Expected exception for non-finance actor");
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), '仅财务可补建订单') === false) throw $e;
    }

    try {
        prm_rebuild_order(321, '未知业务', 83, '店铺', $finance);
        throw new RuntimeException("Expected exception for invalid business");
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), '请选择业务类型') === false) throw $e;
    }

    try {
        prm_rebuild_order(321, '网站模板', 999999, '店铺', $finance);
        throw new RuntimeException("Expected exception for invalid employee");
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), '请选择接单客服') === false) throw $e;
    }

    try {
        prm_rebuild_order(321, '网站模板', 83, '待核渠道', $finance);
        throw new RuntimeException("Expected exception for 待核渠道");
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), '请先确认退款渠道') === false) throw $e;
    }
});

run_test('prm_rebuild_order full transactional execution and rollback', function() use ($pdo) {
    $finance = ['type' => 'admin', 'id' => 1, 'role' => 'finance'];
    $pdo->beginTransaction();
    try {
        $res = prm_rebuild_order(321, '网站模板', 83, '店铺', $finance);
        if (empty($res['order_id'])) throw new RuntimeException("Rebuilt order_id is empty");
        if ($res['employee'] !== '苏婷') throw new RuntimeException("Expected employee 苏婷, got " . $res['employee']);
        if ($res['month'] !== '2026-10') throw new RuntimeException("Expected month 2026-10, got " . $res['month']);
        if ($res['adjustment'] >= 0) throw new RuntimeException("Expected negative adjustment (deduction), got " . $res['adjustment']);

        // Check project_orders row
        $o = $pdo->query("SELECT * FROM project_orders WHERE id=" . (int)$res['order_id'])->fetch(PDO::FETCH_ASSOC);
        if (!$o) throw new RuntimeException("Order not found in DB");
        if ($o['settlement_status'] !== 'locked') throw new RuntimeException("Expected order settlement_status locked, got " . $o['settlement_status']);
        if ($o['order_no'] !== '5127194055790000448') throw new RuntimeException("Expected order_no 5127194055790000448, got " . $o['order_no']);
        if ($o['customer_name'] !== 'abby李颖儿') throw new RuntimeException("Expected customer_name abby李颖儿, got " . $o['customer_name']);

        // Check refund row
        $r = $pdo->query("SELECT * FROM project_refund_import_rows WHERE id=321")->fetch(PDO::FETCH_ASSOC);
        if ($r['review_status'] !== 'approved') throw new RuntimeException("Expected refund review_status approved, got " . $r['review_status']);
        if ((int)$r['order_id'] !== (int)$res['order_id']) throw new RuntimeException("Expected refund order_id " . $res['order_id'] . ", got " . $r['order_id']);

        // Check commission adjustment
        $adj = $pdo->query("SELECT * FROM project_commission_adjustments WHERE order_id=" . (int)$res['order_id'])->fetch(PDO::FETCH_ASSOC);
        if (!$adj) throw new RuntimeException("Expected commission adjustment row");
        if ((int)$adj['employee_id'] !== 83) throw new RuntimeException("Expected adjustment employee_id 83, got " . $adj['employee_id']);
        if ((float)$adj['amount'] !== -213.40) throw new RuntimeException("Expected adjustment amount -213.40, got " . $adj['amount']);
        if ($adj['payroll_month'] !== '2026-10') throw new RuntimeException("Expected payroll_month 2026-10, got " . $adj['payroll_month']);

        echo "  [INFO] Rebuild verified: Order #{$res['order_id']}, Adjustment: ¥{$res['adjustment']} in {$res['month']}\n";
    } finally {
        $pdo->rollBack();
    }
});

echo "All refund rebuild smoke tests completed successfully.\n";
