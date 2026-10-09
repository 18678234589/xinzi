<?php
// 续费资料就地补录：弹窗 / 待补资料页 / 订单页共用的保存逻辑（域名、手机号 / 微信号自动区分、小程序服务器到期日可写“永久”）。
// 会写入测试数据，只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/renewal_fill_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectRenewalFill.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会写入数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db();
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$tag = bin2hex(random_bytes(3));
$u = $pdo->query("SELECT u.id,u.employee_id,u.role FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE e.name='宋倩倩' AND u.is_active=1")->fetch();
$actor = ['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role']];
$mk = function ($type, $suffix) use ($pdo, $actor, $tag) {
    $no = "RF$tag$suffix";
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,note) VALUES (?,?,?,'新订单','美呀美',500,?,'unfinished','测试')")->execute([$no, "客户$suffix", $type, date('Y-m-d', strtotime('-5 day'))]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$id, $actor['employee_id']]);
    return $id;
};
try {
    pr_seed();
    $web = $mk('AI网站定制', 'W1'); $web2 = $mk('网站模板', 'W2'); $mini = $mk('小程序开发', 'M1');
    $miss = function ($id) use ($actor) { $r = prf_missing($actor, 5, 120, '', '', $id); return $r ? $r[0] : null; };
    $r = $miss($web);
    $check($r && $r['need_domain'] && $r['need_phone'], '新订单缺域名和联系方式');
    $html = prf_card($r);
    $check(strpos($html, 'data-k="domain"') !== false && strpos($html, 'data-k="contact"') !== false && strpos($html, 'data-k="server"') === false, '卡片只显示缺的输入框（域名、联系方式，没有服务器到期日）');

    echo "=== 一、一次填域名 + 手机号 ===\n";
    $msg = prf_save_order($actor, $web, ['domain' => "a$tag.com", 'contact' => '138 0013 8000']);
    $check(strpos($msg, '手机号') !== false && strpos($msg, '域名') !== false && $miss($web) === null, '保存成功且订单不再缺项：' . $msg);

    echo "=== 二、只填微信号（联系方式框自动识别）；域名已有时不再卡在“已登记相同资源” ===\n";
    prf_save_order($actor, $web2, ['domain' => "b$tag.com"]);
    $r2 = $miss($web2); $check($r2 && !$r2['need_domain'] && $r2['need_phone'], '只补了域名：还缺联系方式');
    $msg = prf_save_order($actor, $web2, ['contact' => "wx_$tag"]);
    $check(strpos($msg, '微信号') !== false && $miss($web2) === null, '再补微信号：' . $msg);
    $again = prf_save_order($actor, $web2, ['domain' => "b$tag.com"]);
    $check(is_string($again), '重复保存同一个域名不报错');

    echo "=== 三、客户自备域名 ===\n";
    $web3 = $mk('AI网站定制', 'W3');
    $msg = prf_save_order($actor, $web3, ['owner' => 'customer']);
    $check($miss($web3) === null, '客户自有域名：不再缺域名和联系方式：' . $msg);

    echo "=== 四、小程序服务器到期日（可写永久） ===\n";
    $rm = $miss($mini); $check($rm && $rm['need_server'] && strpos(prf_card($rm), 'data-k="perm"') !== false, '小程序缺服务器到期日，卡片带“永久”勾选');
    prf_save_order($actor, $mini, ['server' => '永久']);
    $e = $pdo->prepare("SELECT expires_on FROM project_renewal_items WHERE order_id=? AND resource_type='server' ORDER BY id DESC LIMIT 1"); $e->execute([$mini]);
    $check($e->fetchColumn() === '2099-01-01' && $miss($mini) === null, '写“永久”记为 2099-01-01，订单补齐');
    $mini2 = $mk('小程序开发', 'M2'); prf_save_order($actor, $mini2, ['server' => '2027-09-30', 'contact' => '13900139000']);
    $e->execute([$mini2]); $check($e->fetchColumn() === '2027-09-30', '填具体日期：按日期保存');

    echo "=== 五、校验与空提交 ===\n";
    $check(prf_save_order($actor, $web, []) === false, '什么都没填：返回 false，不报错');
    $bad = false; try { prf_save_order($actor, $mk('AI网站定制', 'W4'), ['contact' => '!!!']); } catch (RuntimeException $x) { $bad = true; }
    $check($bad, '格式不对的联系方式被拒绝并给出提示');
    $bad = false; try { prf_save_order($actor, $mini, ['server' => '不是日期']); } catch (RuntimeException $x) { $bad = true; }
    $check($bad, '格式不对的到期日被拒绝');
    echo "\n=== 续费资料就地补录测试全部通过 ===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
