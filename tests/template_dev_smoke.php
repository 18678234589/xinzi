<?php
// 优站 / WP 模板开发补助：登记 → 订单完成并审核后自动按月计入（15 / 50），未完成 / 未审核不给，技术只能登记自己。仅限本地隔离库。
if (PHP_SAPI !== 'cli') exit(1);
$root = getenv('HEZUOSHANG_APP') ?: dirname(__DIR__);
require_once $root . '/includes/ProjectTemplateDev.php';
require_once $root . '/includes/ProjectMonthly.php';
function td_check($name, $ok) { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
function td_rejects($fn) { try { $fn(); } catch (RuntimeException $e) { return $e->getMessage(); } return ''; }
$p = db();
if (!preg_match('/_(test|ci|sandbox)$/', (string)$p->query('SELECT DATABASE()')->fetchColumn())) exit("refuse: not an isolated database\n");
$finance = ['type' => 'admin', 'id' => 1, 'role' => 'finance'];
$emps = $p->query('SELECT id FROM employees ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
if (count($emps) < 3) exit("need 3 employees\n");
[$devA, $devB, $other] = array_map('intval', $emps);
$techA = ['type' => 'employee', 'id' => 900, 'employee_id' => $devA, 'role' => 'technical'];
$tag = 'TD-' . bin2hex(random_bytes(4)); $ids = []; $ruleIds = []; $month = '2099-02';
try {
    ptmd_ensure();
    $ins = $p->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,order_date,delivery_status,settlement_status) VALUES (?,'td','网站模板','td',500,'2099-02-05',?,?)");
    $item = $p->prepare("INSERT INTO project_order_items (order_id,item_key,item_name,category,sale_amount) VALUES (?,?,?,'program',500)");
    $snap = "INSERT INTO project_commission_snapshots (order_id,employee_id,commission_group,role_name,income_amount,direct_cost,contribution_profit,rule_id,rate,group_weight,commission_amount,calc_note,payroll_month) VALUES (%d,%d,'technical','模板技术',500,0,500,1,0.13,1,65,'t','$month')";
    // 0: 优站完成已审核；1: WP 完成已审核；2: 优站未完成；3: 优站完成未审核
    foreach ([['finished', 'approved', '1年优站'], ['finished', 'approved', '1年优站WP'], ['unfinished', 'draft', '1年优站'], ['finished', 'draft', '1年优站']] as $i => $r) {
        $ins->execute([$tag . '-' . $i, $r[0], $r[1]]); $ids[$i] = (int)$p->lastInsertId(); $item->execute([$ids[$i], 'k' . $i, $r[2]]);
        if ($r[1] === 'approved') $p->exec(sprintf($snap, $ids[$i], $other));
    }
    foreach ([['TD优站', 15, 'youzhan'], ['TDWP', 50, 'wp']] as [$n, $amt, $kind]) {
        $p->prepare("INSERT INTO project_monthly_rules (name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from,note) VALUES (?,'per_unit','*','*','*',NULL,'profit',?,'2099-01','t')")
            ->execute([$n . $tag, json_encode(['amount' => $amt, 'auto' => 'template_dev', 'kind' => $kind])]); $ruleIds[] = (int)$p->lastInsertId();
    }
    $list = ptmd_orders($month);
    td_check('优站订单自动列出，WP 自动识别', isset($list[$ids[0]]) && $list[$ids[0]]['kind_auto'] === 'youzhan' && $list[$ids[1]]['kind_auto'] === 'wp');
    ptmd_set($ids[0], $devA, 'youzhan', $techA); ptmd_set($ids[1], $devB, 'wp', $finance); ptmd_set($ids[2], $devA, 'youzhan', $techA); ptmd_set($ids[3], $devA, 'youzhan', $techA);
    td_check('技术只能登记自己 / 不能改别人的', td_rejects(function () use ($ids, $techA, $devB) { ptmd_set($ids[0], $devB, 'youzhan', $techA); }) !== '' && td_rejects(function () use ($ids, $techA) { ptmd_set($ids[1], $techA['employee_id'], 'youzhan', $techA); }) !== '');
    $mine = array_filter(ps_monthly_results($month, true), function ($r) use ($ruleIds) { return in_array((int)$r['rule_id'], $ruleIds, true); });
    $by = []; foreach ($mine as $r) $by[(int)$r['employee_id']][$r['rule_type'] . $r['rule_id']] = (float)$r['amount'];
    td_check('优站完成并审核 → 开发技术 +15（未完成、未审核的不给）', ($by[$devA][array_key_first($by[$devA] ?? [])] ?? 0) == 15 && count($by[$devA] ?? []) === 1);
    td_check('WP 完成并审核 → 开发技术 +50', array_sum($by[$devB] ?? []) == 50);
    td_check('订单技术（非开发技术）不因此多得', empty($by[$other]));
    $p->exec("UPDATE project_orders SET settlement_status='draft' WHERE id={$ids[0]}"); $p->exec("DELETE FROM project_commission_snapshots WHERE order_id={$ids[0]}");
    $mine2 = array_filter(ps_monthly_results($month, true), function ($r) use ($ruleIds) { return in_array((int)$r['rule_id'], $ruleIds, true) && (int)$r['employee_id'] === $GLOBALS['devA']; });
    td_check('订单被撤销审核后补助同步消失', count($mine2) === 0);
    ptmd_set($ids[3], 0, 'youzhan', $techA);
    td_check('可清除登记', !$p->query("SELECT COUNT(*) FROM project_template_dev WHERE order_id={$ids[3]}")->fetchColumn());
    // 订单 Excel 导入：模板开发技术列 → 登记；客服上传不登记；WordPress 模板程序按 WP 计
    $item->execute([$ids[3], 'kwp', '1年WordPress模板']);
    $cs = ['type' => 'employee', 'id' => 901, 'employee_id' => $other, 'role' => 'customer_service'];
    $nm = $p->prepare('SELECT name FROM employees WHERE id=?'); $nm->execute([$devB]); $devBName = $nm->fetchColumn();
    ptmd_import_row($ids[3], ['yz' => $devBName, 'wp' => ''], $cs);
    td_check('客服上传的订单不登记开发技术', !$p->query("SELECT COUNT(*) FROM project_template_dev WHERE order_id={$ids[3]}")->fetchColumn());
    ptmd_import_row($ids[3], ['yz' => $devBName, 'wp' => ''], $techA);
    $row = $p->query("SELECT dev_employee_id,kind FROM project_template_dev WHERE order_id={$ids[3]}")->fetch();
    td_check('技术上传：模板开发技术登记，WordPress 模板自动按 WP', $row && (int)$row['dev_employee_id'] === $devB && $row['kind'] === 'wp');
} finally {
    foreach ($ruleIds as $r) { $p->exec('DELETE FROM project_monthly_inputs WHERE rule_id=' . $r); $p->exec('DELETE FROM project_monthly_rules WHERE id=' . $r); }
    foreach ($ids as $id) {
        foreach (['project_template_dev', 'project_commission_snapshots', 'project_order_items'] as $t) $p->exec("DELETE FROM $t WHERE order_id=$id");
        $p->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id=$id"); $p->exec("DELETE FROM project_orders WHERE id=$id");
    }
}
