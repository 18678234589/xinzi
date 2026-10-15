<?php
// 财务抽查任务：免审业务（设计 / 网站模板 / AI网站定制）自动结算的订单，每个业务每月补足 5 单待抽查；财务标记无误 / 有问题；页面顶部提示和站内信。
// 会写入测试数据，只允许专用测试库：DB_NAME=xinzi_test DB_HOST=127.0.0.1 DB_PORT=13399 php tests/spot_check_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/require_isolated_database.php';
require_once __DIR__ . '/../includes/ProjectSpotCheck.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); require_isolated_test_database($pdo); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$finance = ['type' => 'admin', 'id' => 1, 'role' => 'finance', 'employee_id' => 0];
$month = '2026-09'; $ids = []; $refundIds = [];
$mk = function ($suffix, $type) use ($pdo, $tag, $month, &$ids) {
    $no = "SC$tag$suffix";
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试',?,'新订单','美呀美',100,?,'finished','approved','测试')")->execute([$no, $type, $month . '-10']);
    $id = (int)$pdo->lastInsertId(); $ids[] = $id;
    $pdo->prepare("INSERT INTO project_auto_reviews (order_id,state,policy_version,checked_row_version,fingerprint,reasons_json,evidence_json,checked_at,applied_at) VALUES (?,'spot_check','t',0,'x','[]','{}',NOW(),NOW())")->execute([$id]);
    return $id;
};
try {
    $pdo->exec('DROP TABLE IF EXISTS project_spot_checks'); // 测试库：表结构以代码为准
    psc_ensure();
    for ($i = 1; $i <= 7; $i++) $mk("D$i", '设计');
    for ($i = 1; $i <= 3; $i++) $mk("W$i", '网站模板');
    require_once __DIR__ . '/../includes/ProjectRefundMatch.php';
    for ($i = 1; $i <= 7; $i++) {
        $pdo->prepare("INSERT INTO project_refund_import_rows (fingerprint,order_id,order_no,refund_date,amount,payment_method,source_payment_reference,payment_reference,reason,review_status,reviewed_at,submitted_by_type,submitted_by_id) VALUES (?,NULL,?,?,10,'待核渠道','','',?,'approved',?,'admin',1)")
            ->execute([sha1("sc$tag$i"), "51279$tag$i", $month . '-05', '；' . PRM_AUTO_TAG . '测试', $month . '-20 10:00:00']);
        $refundIds[] = (int)$pdo->lastInsertId();
    }
    $added = psc_assign($month);
    $by = psc_pending_by_business();
    $check(($by[PSC_REFUND_BUSINESS] ?? 0) === 5, '自动结案的退款 7 条 → 抽 5 条');
    $check(($by['设计'] ?? 0) === 5 && ($by['网站模板'] ?? 0) === 3, '设计 7 单自动结算 → 抽 5 单；网站模板只有 3 单 → 全部抽（' . json_encode($by, JSON_UNESCAPED_UNICODE) . '）');
    $check($added === 13 && psc_pending_count() >= 13, '新增 13 条（设计 5 + 网站模板 3 + 退款 5），待抽查总数 ≥ 13');
    $check(psc_assign($month) === 0, '再次分配不会重复（已满 5 单 / 没有可抽的）');
    $row = $pdo->query("SELECT id FROM project_spot_checks WHERE month='$month' AND business='设计' LIMIT 1")->fetch();
    $threw = false; try { psc_handle($finance, $row['id'], 'problem', ''); } catch (RuntimeException $e) { $threw = true; }
    $check($threw, '有问题必须写明情况');
    $threw = false; try { psc_handle(['role' => 'customer_service'], $row['id'], 'ok'); } catch (RuntimeException $e) { $threw = true; }
    $check($threw, '非财务不能抽查');
    psc_handle($finance, $row['id'], 'ok');
    $check($pdo->query("SELECT status FROM project_spot_checks WHERE id=" . (int)$row['id'])->fetchColumn() === 'ok', '标记无误成功');
    $check(psc_assign($month) === 0, '已处理的仍算在 5 单内，不会再补抽');
    ob_start(); $current_admin = ['id' => 1]; $_rel = 'index.php'; include __DIR__ . '/../includes/spot_check_banner.php'; $html = ob_get_clean();
    $check(strpos($html, '待财务抽查') !== false && strpos($html, '设计') !== false, '财务页面顶部有醒目提示，写明各业务条数');
    echo "全部通过\n";
} finally {
    $pdo->exec("DELETE FROM project_spot_checks WHERE month='$month'");
    foreach ($ids as $id) { $pdo->exec("DELETE FROM project_auto_reviews WHERE order_id=$id"); $pdo->exec("DELETE FROM project_orders WHERE id=$id"); }
    foreach ($refundIds as $rid) $pdo->exec('DELETE FROM project_refund_import_rows WHERE id=' . (int)$rid);
    $pdo->exec("DELETE FROM project_messages WHERE category='spot_check'");
}
