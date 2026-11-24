<?php
// 自动审核：已选程序套餐、已交付的网站模板订单，资源停在“待技术确认”时自动确认为“无需另选”并继续结算；没选套餐 / 没交付的不动。只允许本地隔离库，事务回滚。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectAutoReview.php';
require_once __DIR__ . '/require_isolated_database.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$p = db(); require_isolated_test_database($p); poi_ensure();
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$tech = (int)$p->query("SELECT employee_id FROM project_users WHERE role='technical' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
$cs = (int)$p->query("SELECT employee_id FROM project_users WHERE role='customer_service' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
$tpl = $p->query("SELECT id,name,specification FROM project_cost_templates WHERE category='program' AND business_scope='网站模板' AND name='森动中级版' AND specification='1年 空间+域名' AND is_active=1 ORDER BY version DESC LIMIT 1")->fetch();
$check($tech && $cs && $tpl, '测试账号和程序套餐存在');
$p->beginTransaction();
try {
    $p->exec("REPLACE INTO project_settings (name,value) VALUES ('auto_review_enabled','1')");
} catch (Throwable $e) { /* 设置表结构不同时用 ps_setting_set */ }
try {
    ps_setting_set('auto_review_enabled', true, null);
    $tag = bin2hex(random_bytes(3));
    $make = function ($suffix, $delivery, $withProgram) use ($p, $tag, $tech, $cs, $tpl) {
        $p->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status) VALUES (?,'测试','网站模板','新订单','美呀美旗舰店',998,0,?,?,'draft')")->execute(["AR$tag$suffix", date('Y-m-d', strtotime('-20 days')), $delivery]);
        $id = (int)$p->lastInsertId();
        foreach ([[$tech, 'technical', '模板技术'], [$cs, 'customer_service', '客服']] as [$e, $g, $r]) $p->prepare('INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,?,?,1)')->execute([$id, $e, $g, $r]);
        $p->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','pending')")->execute([$id]);
        if ($withProgram) $p->prepare("INSERT INTO project_order_items (order_id,item_key,item_name,category,template_id,sale_amount,resource_hint) VALUES (?,?,?,'program',?,998,'')")->execute([$id, 'k' . $tag . $suffix, '森动中级版', (int)$tpl['id']]);
        return $id;
    };
    $mode = function ($id) use ($p) { return $p->query("SELECT domain_mode FROM project_order_resources WHERE order_id=$id")->fetchColumn(); };
    $a = $make('A', 'finished', true); $b = $make('B', 'finished', false); $c = $make('C', 'unfinished', true);
    pa_check_order($a, false, false); $check($mode($a) === 'pending', '只检查（不自动结算）时不改资源');
    $r = pa_check_order($a, true, true);
    $check($mode($a) === 'none', '已选套餐 + 已交付：资源自动确认为“无需另选”');
    $check($r['applied'] === true && $p->query("SELECT settlement_status FROM project_orders WHERE id=$a")->fetchColumn() === 'approved', '确认资源后同一次检查继续自动结算（待财务抽查）');
    $codes = array_column($r['reasons'], 'code');
    $check(!in_array('resource', $codes, true) && !in_array('item_resource_pending', $codes, true), '“资源待技术确认”的卡点消失：' . implode(',', $codes));
    $costs = (int)$p->query("SELECT COUNT(*) FROM project_costs WHERE order_id=$a")->fetchColumn();
    $check($costs >= 1, '套餐成本已带入订单：' . $costs . ' 条');
    $check((int)$p->query("SELECT COUNT(*) FROM project_audit_logs WHERE entity_type='order' AND entity_id=$a AND action='confirm_resources' AND details_json LIKE '%auto_review%'")->fetchColumn() === 1, '审计日志记录了自动确认');
    pa_check_order($b, true, true); $check($mode($b) === 'pending', '没选套餐的订单：不动，仍待技术确认');
    pa_check_order($c, true, true); $check($mode($c) === 'pending', '业务没完成的订单：不动');
    $again = pa_check_order($a, true, true); $check($mode($a) === 'none', '重复检查不报错、不重复确认');
    echo "\n=== 自动确认资源测试通过 ===\n";
} finally { $p->rollBack(); }
