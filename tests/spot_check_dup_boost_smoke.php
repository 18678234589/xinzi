<?php
// 财务抽查：同一人、同业务、同日同金额的疑似重复订单被抽中的几率明显更高（仍是随机，不是必抽）。
// 会写入测试数据，只允许专用测试库：DB_NAME=xinzi_sandbox DB_HOST=127.0.0.1 DB_PORT=13399 php tests/spot_check_dup_boost_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/require_isolated_database.php';
require_once __DIR__ . '/../includes/ProjectSpotCheck.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); require_isolated_test_database($pdo); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$emp = (int)$pdo->query("SELECT id FROM employees WHERE name='阎泸琪'")->fetchColumn();
$month = '2031-01'; $ids = []; $dups = [];
$mk = function ($suffix, $day, $amount) use ($pdo, $tag, $emp, $month, &$ids) {
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','设计','图片','内部',?,?,?,'finished','approved','spot-dup-test')")->execute(["SD$tag$suffix", $amount, $amount, sprintf('%s-%02d', $month, $day)]);
    $id = (int)$pdo->lastInsertId(); $ids[] = $id;
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','设计客服',1)")->execute([$id, $emp]);
    $pdo->prepare("INSERT INTO project_auto_reviews (order_id,state,policy_version,checked_row_version,fingerprint,reasons_json,evidence_json,checked_at) VALUES (?,'spot_check','t',0,'t','[]','{}',NOW())")->execute([$id]);
    return $id;
};
try {
    $check($emp > 0, '测试员工存在');
    for ($i = 1; $i <= 25; $i++) $mk("N$i", $i, 10 + $i); // 每单金额都不同：不是重复
    $dups = [$mk('D1', 26, 50), $mk('D2', 26, 50)];      // 同一人同日同金额
    $hit = 0; $normalHit = 0; $trials = 150;
    for ($t = 0; $t < $trials; $t++) {
        $pdo->exec("DELETE FROM project_spot_checks WHERE month='$month'");
        psc_assign($month);
        $picked = $pdo->query("SELECT order_id FROM project_spot_checks WHERE month='$month' AND kind='order'")->fetchAll(PDO::FETCH_COLUMN);
        $check(count($picked) === 5, '') ;
        foreach ($picked as $p) { if (in_array((int)$p, $dups, true)) $hit++; else $normalHit++; }
    }
    $dupRate = $hit / ($trials * 2); $normalRate = $normalHit / ($trials * 25);
    echo sprintf("  疑似重复单每单被抽中 %.0f%%，普通单 %.0f%%\n", $dupRate * 100, $normalRate * 100);
    $check($dupRate > $normalRate * 2.5, '疑似重复单被抽中的几率明显更高');
    $check($dupRate < 1, '仍是随机，不是每次都抽');
    echo "全部通过\n";
} finally {
    try { $pdo->exec("DELETE FROM project_spot_checks WHERE month='$month'"); } catch (Throwable $e) {}
    foreach ($ids as $id) { foreach (['project_auto_reviews', 'project_participants'] as $t) $pdo->exec("DELETE FROM $t WHERE order_id=$id"); $pdo->exec("DELETE FROM project_orders WHERE id=$id"); }
}
