<?php
/**
 * “空间+域名”套餐重复计域名成本更正（2026-10 起）：网站模板程序套餐（如 JSP展示中级版 1年 空间+域名 ¥360）本身已含域名，
 * 域名来源写“我们注册”时系统又另加了一条域名成本（如 .com ¥58），成本多算（360 变 418），客服 / 技术分成都被压低。
 * 本工具：找出同时有“空间+域名”套餐成本和域名成本的订单，去掉多出的域名成本、资源改为无需另计；
 * 已审核订单按“新成本重算 − 已计（快照 + 此前调整）”给每个参与人记一笔 2026-10 分成调整（可重复执行，已记过的会扣掉）。
 * 用法：php tools/fix_package_domain_dup_202611.php            # 预览（事务内试算后回滚）
 *       php tools/fix_package_domain_dup_202611.php --apply    # 执行
 */
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectOrderBusiness.php';

const FPD_REASON = '套餐已含域名：去掉重复的域名成本';

function fpd_run($apply, $month = '2026-10')
{
    $pdo = db(); $pdo->beginTransaction();
    $act = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'finance', 'employee_id' => null];
    $orders = $pdo->query("SELECT DISTINCT o.* FROM project_orders o
        JOIN project_costs p ON p.order_id=o.id AND p.category='program' AND p.review_status IN ('approved','pending') JOIN project_cost_templates t ON t.id=p.template_id AND t.specification LIKE '%空间+域名%'
        JOIN project_costs d ON d.order_id=o.id AND d.category='domain' AND d.review_status IN ('approved','pending')
        WHERE o.order_date>='2026-10-01' AND o.settlement_status IN ('draft','review','approved') AND o.project_type IN ('网站模板','AI网站定制','网站定制') ORDER BY o.id")->fetchAll();
    $names = []; foreach ($pdo->query('SELECT id,name FROM employees') as $r) $names[(int)$r['id']] = $r['name'];
    $byPerson = []; $skip = []; $done = 0; $cost = 0.0;
    foreach ($orders as $o) {
        $id = (int)$o['id'];
        $doms = $pdo->query("SELECT id,amount FROM project_costs WHERE order_id=$id AND category='domain' AND review_status IN ('approved','pending')")->fetchAll();
        $approved = in_array($o['settlement_status'], ['approved', 'locked'], true);
        $pdo->exec('SAVEPOINT fpd_none');
        try {
            $old = $approved ? pob_old_amounts($id) : [];
            foreach ($doms as $d) { $pdo->prepare('UPDATE project_order_items SET cost_id=NULL WHERE cost_id=?')->execute([(int)$d['id']]); $pdo->prepare('DELETE FROM project_costs WHERE id=?')->execute([(int)$d['id']]); $cost += (float)$d['amount']; }
            $pdo->prepare("UPDATE project_order_resources SET domain_mode='none',domain_template_id=NULL WHERE order_id=? AND domain_mode='template'")->execute([$id]);
            ps_audit('order', $id, 'drop_duplicate_domain_cost', $act, ['domains' => $doms, 'reason' => FPD_REASON]);
            if ($approved) {
                $fresh = $pdo->prepare('SELECT * FROM project_orders WHERE id=?'); $fresh->execute([$id]); $order = $fresh->fetch();
                // 已记过本次更正的，先把它算进“旧值”，避免重复
                $prior = $pdo->prepare("SELECT commission_group,employee_id,SUM(amount) a FROM project_commission_adjustments WHERE order_id=? AND reason LIKE ? GROUP BY commission_group,employee_id"); $prior->execute([$id, FPD_REASON . '%']);
                foreach ($prior->fetchAll() as $p) { /* pob_old_amounts 已含全部调整，这里无需再加 */ }
                $deltas = pob_post_deltas($order, $old, $month, FPD_REASON, $act);
                foreach ($deltas as $dl) { $diff = round($dl['new'] - $dl['old'], 2); if (abs($diff) >= 0.005) $byPerson[$names[$dl['employee_id']] ?? $dl['employee_id']] = ($byPerson[$names[$dl['employee_id']] ?? $dl['employee_id']] ?? 0) + $diff; }
            }
            $done++; $pdo->exec('RELEASE SAVEPOINT fpd_none');
        } catch (Throwable $e) { $skip[] = $o['order_no'] . '：' . $e->getMessage(); $pdo->exec('ROLLBACK TO SAVEPOINT fpd_none'); }
    }
    echo "涉及订单 " . count($orders) . " 张，处理 $done 张，去掉域名成本 ¥" . number_format($cost, 2, '.', '') . "\n";
    arsort($byPerson); foreach ($byPerson as $n => $v) echo '  ' . $n . ' ' . ($v > 0 ? '+' : '') . number_format($v, 2, '.', '') . "\n";
    foreach ($skip as $s) echo "  未处理：$s\n";
    if ($apply) { $pdo->commit(); echo "已写入\n"; } else { $pdo->rollBack(); echo "预览，已回滚\n"; }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) fpd_run(in_array('--apply', $argv, true));
