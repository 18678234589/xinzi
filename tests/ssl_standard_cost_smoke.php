<?php
// SSL 报备成本：金额等于成本中心“域名SSL证书”标准成本时自动带入（待确认）；金额不同、已有证书成本、无金额都不动。
// 只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/ssl_standard_cost_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('BASE_URL', '');
require_once __DIR__ . '/../includes/ProjectIntake.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db();
require_once __DIR__ . '/require_isolated_database.php';
require_isolated_test_database($pdo);
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$actor = ['type' => 'system', 'id' => 0, 'employee_id' => null, 'role' => 'finance'];
$std = $pdo->query("SELECT price FROM project_cost_templates WHERE is_active=1 AND requires_proof=0 AND category='certificate' AND name='域名SSL证书' AND price_mode='fixed' ORDER BY id DESC LIMIT 1")->fetchColumn();
$check($std !== false, '成本中心有域名SSL证书标准成本');
$tag = bin2hex(random_bytes(3));
$mk = function ($n) use ($pdo, $tag) { $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,order_date,delivery_status,settlement_status) VALUES (?,?,?,?,?,CURDATE(),'unfinished','draft')")->execute(["SSLT-$tag-$n", 't', 'AI网站定制', 't', 200]); return (int)$pdo->lastInsertId(); };
$costs = function ($id) use ($pdo) { $q = $pdo->prepare("SELECT category,amount,review_status FROM project_costs WHERE order_id=? AND review_status<>'rejected'"); $q->execute([$id]); return $q->fetchAll(); };
$a = $mk(1); $check(ps_intake_ssl_standard_cost($a, (string)$std, $actor, 'test') === true, '金额等于标准成本：新增');
$c = $costs($a); $check(count($c) === 1 && $c[0]['category'] === 'certificate' && (float)$c[0]['amount'] === (float)$std && $c[0]['review_status'] === 'pending', '成本为证书标准价、待确认');
$check(ps_intake_ssl_standard_cost($a, (string)$std, $actor, 'test') === false && count($costs($a)) === 1, '重复调用不重复新增');
$b = $mk(2); $check(ps_intake_ssl_standard_cost($b, (string)((float)$std + 20), $actor, 'test') === false && !$costs($b), '金额不同不带入');
$d = $mk(3); $check(ps_intake_ssl_standard_cost($d, null, $actor, 'test') === false && ps_intake_ssl_standard_cost($d, '', $actor, 'test') === false && !$costs($d), '没有报备金额不带入');
echo "ssl_standard_cost_smoke 通过\n";
