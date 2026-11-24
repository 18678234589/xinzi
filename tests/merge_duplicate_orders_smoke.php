<?php
// 重复草稿订单清理：同业务 / 日期 / 售价 / 昵称 / 参与人的 WX- 订单只留较早的一张（订单类型补过去），另一张进回收站；已审核的重复不动。
// 会写入测试数据，只允许本地隔离库：DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php tests/merge_duplicate_orders_smoke.php
if (PHP_SAPI !== 'cli' || !preg_match('/_(test|ci|sandbox)$/', (string)getenv('DB_NAME')) || getenv('DB_PORT') !== '13399') { fwrite(STDERR, "只能在本地隔离库运行\n"); exit(1); }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectDuplicateCleanup.php';
$pdo = db(); $tag = strtoupper(bin2hex(random_bytes(4)));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$E = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=? ORDER BY id LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); };
$cs = $E('宋倩倩'); $tech = $E('李子晖');
$ids = [];
$mk = function ($suffix, $kind, $status, $delivery, $nick, $created) use ($pdo, $tag, $cs, $tech, &$ids) {
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note,created_at) VALUES (?,?,'AI网站定制',?,'',200,0,'2026-10-13',?,?,'测试',?)")->execute(["WX-{$tag}{$suffix}", $nick, $kind, $delivery, $status, $created]);
    $id = (int)$pdo->lastInsertId(); $ids[] = $id;
    $pdo->prepare("INSERT INTO project_order_sources (order_id,payment_nickname,nickname_source) VALUES (?,?,'manual')")->execute([$id, $nick]);
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$id, $cs]);
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','前端',1)")->execute([$id, $tech]);
    return $id;
};
$exists = function ($id) use ($pdo) { $q = $pdo->prepare('SELECT COUNT(*) FROM project_orders WHERE id=?'); $q->execute([$id]); return (bool)$q->fetchColumn(); };
try {
    $a = $mk('A', '', 'draft', 'finished', "dup{$tag}", '2026-11-02 03:07:54');
    $b = $mk('B', '博山定制', 'draft', 'unfinished', "dup{$tag}", '2026-11-02 06:54:58');
    $c1 = $mk('C', '', 'approved', 'finished', "ap{$tag}", '2026-11-02 03:00:00');
    $c2 = $mk('D', '博山定制', 'approved', 'finished', "ap{$tag}", '2026-11-02 04:00:00');
    $other = $mk('E', '', 'draft', 'finished', "other{$tag}", '2026-11-02 03:00:00');
    // 同一次上传里的相同两行（创建时间相同）：各算一单，自动清理（间隔 10 分钟）不动
    $f1 = $mk('F', '', 'draft', 'finished', "same{$tag}", '2026-11-02 03:00:00'); $f2 = $mk('G', '', 'draft', 'finished', "same{$tag}", '2026-11-02 03:00:01');
    [$preview] = mdd_run(false, "2026-09-01", 10);
    $mine = array_values(array_filter($preview, function ($d) use ($tag) { return strpos($d['drop'], "WX-{$tag}") === 0; }));
    $check(count($mine) === 1 && $mine[0]['keep'] === "WX-{$tag}A" && $mine[0]['drop'] === "WX-{$tag}B" && $exists($b), '预览：保留较早的 A，去掉 B（还没动）');
    [$done, $skipped] = mdd_run(true, '2026-09-01', 10);
    $check($exists($f1) && $exists($f2), '自动清理（间隔 10 分钟）：同一次上传的相同两行不合并');
    $check(!$exists($b) && $exists($a) && $exists($other), '执行后：B 进回收站，A 和不相关的订单还在');
    $kind = $pdo->query("SELECT order_kind FROM project_orders WHERE id=$a")->fetchColumn();
    $check($kind === '博山定制', '保留的订单补上了订单类型：' . $kind);
    $check($exists($c1) && $exists($c2), '已审核的重复不动');
    $skip = array_filter($skipped, function ($s) use ($tag) { foreach ($s['orders'] as $n) if ($n === "WX-{$tag}C" || $n === "WX-{$tag}D") return true; return false; });
    $check(count($skip) === 1, '已审核的重复只列出来、交财务判断');
    echo "\n=== 重复订单清理测试全部通过 ===\n";
} finally {
    if ($ids) {
        $in = implode(',', $ids);
        foreach (['project_participants', 'project_order_sources', 'project_cash_movements', 'project_costs'] as $t) { try { $pdo->exec("DELETE FROM $t WHERE order_id IN ($in)"); } catch (Throwable $e) {} }
        try { $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id IN ($in)"); } catch (Throwable $e) {}
        $pdo->exec("DELETE FROM project_orders WHERE id IN ($in)");
    }
}
