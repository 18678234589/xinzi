<?php
// 平台与服务器信息 + 项目账号密码：加密存取、发 AI 前隐藏密码、规则识别、可见名单、页面不出现明文、订单内账号密码。
// 需要 config/vault_key.php。事务内执行，结束回滚。php tests/vault_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectVault.php';

$pdo = db();
$pdo->beginTransaction();
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$userFor = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name=? AND u.is_active=1 LIMIT 1'); $q->execute([$name]); $u = $q->fetch(); return $u ? ['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role']] : null; };
try {
    ps_setting_set('ai', ['enabled' => false] + (array)ps_setting_get('ai', []), null);

    $cipher = pv_encrypt('Pa$$w0rd!中文');
    $check(strpos($cipher, 'v1:') === 0 && strpos($cipher, 'Pa$$w0rd') === false && pv_decrypt($cipher) === 'Pa$$w0rd!中文', '密码 AES-256-GCM 加密存储，可正确解密');
    $check(pv_encrypt('Pa$$w0rd!中文') !== $cipher, '同一密码每次密文不同（随机 IV）');
    $tampered = substr($cipher, 0, -4) . (substr($cipher, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
    $check(pv_decrypt($tampered) === '', '密文被篡改时解密失败，不返回错误内容');

    $sample = "腾讯云主账号 https://cloud.tencent.com/login 账号：13800000000 密码：Tx#2026abc\n\n官网服务器 宝塔 http://1.2.3.4:8888/abcd 用户名 admin 密码是Bt!8888xyz 安全入口 /abcd\n\nMySQL 1.2.3.4:3306 root password=My@Sql2026";
    [$masked, $secrets] = pv_mask_secrets($sample);
    $check(count($secrets) === 3 && strpos($masked, 'Tx#2026abc') === false && strpos($masked, 'Bt!8888xyz') === false && strpos($masked, 'My@Sql2026') === false && strpos($masked, '⟦P1⟧') !== false, '发给 AI 前：带“密码 / password”标签的 3 个密码已替换为占位符');
    $check(pv_unmask($masked, $secrets) === $sample, '识别结果中的占位符可还原为原密码');

    $local = pv_parse_paste($sample, PV_CATEGORIES, ['type' => 'admin', 'id' => 1]);
    $byCat = []; foreach ($local['items'] as $it) $byCat[$it['category']] = $it;
    $check($local['source'] === 'local' && count($local['items']) === 3, 'AI 未启用时按规则识别出 3 条');
    $check(($byCat['腾讯云']['password'] ?? '') === 'Tx#2026abc' && ($byCat['腾讯云']['account'] ?? '') === '13800000000', '腾讯云：账号、密码正确还原');
    $check(($byCat['宝塔面板']['url'] ?? '') === 'http://1.2.3.4:8888/abcd' && ($byCat['宝塔面板']['password'] ?? '') === 'Bt!8888xyz', '宝塔：网址、密码（“密码是…”写法）正确');
    $check(($byCat['MySQL 数据库']['password'] ?? '') === 'My@Sql2026', 'MySQL：password= 写法的密码正确');

    // 可见名单
    $check(pv_can_access(['type' => 'admin', 'id' => (int)$pdo->query("SELECT id FROM admins WHERE username='admin'")->fetchColumn(), 'employee_id' => null, 'role' => 'finance']), 'admin 可见');
    $finance = (int)$pdo->query("SELECT id FROM admins WHERE username='yaolin'")->fetchColumn();
    if ($finance) $check(!pv_can_access(['type' => 'admin', 'id' => $finance, 'employee_id' => null, 'role' => 'finance']), '其他财务管理员账号（yaolin）不可见');
    foreach (['孙杰', '于洋', '栾鑫'] as $name) $check(pv_can_access($userFor($name)), $name . '（服务器维护）可见');
    $check(!pv_can_access($userFor('翟建跃')) && !pv_can_access($userFor('曹双双')), '普通技术 / 客服不可见');

    // 页面：孙杰新增一条，列表显示但不出现明文密码
    $sunjie = $userFor('孙杰');
    $itemId = pv_item_save(['category' => '宝塔面板', 'name' => '测试宝塔 VAULT-TEST', 'url' => 'http://9.9.9.9:8888/t', 'host' => '9.9.9.9', 'account' => 'admin', 'password' => 'Secret#VaultTest1', 'notes' => '安全入口 /t'], $sunjie);
    $stored = $pdo->query("SELECT password_enc,notes_enc FROM project_vault_items WHERE id=$itemId")->fetch();
    $check(strpos($stored['password_enc'], 'Secret#VaultTest1') === false && pv_decrypt($stored['password_enc']) === 'Secret#VaultTest1' && pv_decrypt($stored['notes_enc']) === '安全入口 /t', '数据库里只有密文（密码、备注）');
    $_SESSION = ['project_user_id' => $sunjie['id'], 'project_csrf' => 'test-csrf'];
    $_SERVER['SCRIPT_NAME'] = '/project/vault.php'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = [];
    ob_start(); include __DIR__ . '/../project/vault.php'; $html = ob_get_clean();
    $check(mb_strpos($html, '测试宝塔 VAULT-TEST') !== false && strpos($html, 'Secret#VaultTest1') === false && mb_strpos($html, '安全入口 /t') === false && mb_strpos($html, 'AI 智能导入') !== false, '孙杰打开栏目：看到条目与 AI 导入，页面源码里没有明文密码和备注');
    $check(mb_strpos($html, '平台与服务器信息</a>') !== false || mb_strpos($html, "fa-key\"></i> 平台与服务器信息") !== false, '侧栏显示“平台与服务器信息”入口');

    // 订单内项目账号密码
    $zhai = $userFor('翟建跃');
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,note) VALUES ('VAULT-TEST-ORDER','客户','小程序开发','定制','美呀美',100,'2026-09-29','unfinished','测试')")->execute();
    $orderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','制作技术',1)")->execute([$orderId, $zhai['employee_id']]);
    $credId = pv_order_credential_save($orderId, ['kind' => '宝塔面板', 'label' => '客户服务器', 'url' => 'http://8.8.8.8:8888/x', 'account' => 'admin', 'password' => 'Cust#Pwd2026', 'notes' => ''], $zhai);
    $check($credId > 0 && pv_decrypt($pdo->query("SELECT password_enc FROM project_order_credentials WHERE id=$credId")->fetchColumn()) === 'Cust#Pwd2026', '订单内记录客户宝塔账号密码（加密）');
    $order = ps_order($orderId, $zhai); $actor = $zhai;
    $_SESSION = ['project_user_id' => $zhai['id'], 'project_csrf' => 'test-csrf'];
    ob_start(); include __DIR__ . '/../includes/order_credentials_card.php'; $card = ob_get_clean();
    $check(mb_strpos($card, '项目账号密码') !== false && mb_strpos($card, '客户服务器') !== false && strpos($card, 'Cust#Pwd2026') === false && mb_strpos($card, '智能识别') !== false, '订单页显示“项目账号密码”卡片，密码不以明文出现，可粘贴智能识别');
    $order = ['id' => $orderId, 'project_type' => '设计']; ob_start(); include __DIR__ . '/../includes/order_credentials_card.php'; $card = ob_get_clean();
    $check(trim($card) === '', '设计等不涉及服务器的业务不显示此卡片');

    $pdo->rollBack();
    ps_setting_get('ai', null, true);
    echo "\n=== 平台信息与项目账号密码全部通过，测试数据已回滚 ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
