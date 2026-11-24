<?php
/**
 * 无订单号的附加行（客服表格里 SSL 证书 / 加语言等，订单编号格是合并单元格、导入时丢了）合并回主订单（2026-10 起、网站类、未审核的 WX- 内部号订单）。
 * 只处理金额对得上的：
 *  - 主订单已审核、且主订单实收 = 主订单售价 + 全部附加行（附加行的钱已在主订单实收里，再审核附加行会重复计收）→ 售价补成实收，附加行的成本转到主订单，附加行放进回收站；
 *  - 主订单还是草稿（没有实收，或实收已等于合计）、且 买家实际支付金额（没有就用订单金额）= 主订单售价 + 全部附加行 → 售价相加，附加行放进回收站；
 *  - 附加行已有收款、参与人不在主订单里、同买家有多个主订单、金额对不上的都不动，列出来由财务核对。
 * 用法：php tools/merge_blank_no_addons_202611.php            # 预览，不写库
 *       php tools/merge_blank_no_addons_202611.php --apply    # 执行（每张订单一个事务，附加行 30 天内可在回收站恢复）
 */
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectOrderDelete.php';

function mba_paid($orderNo)
{
    $q = db()->prepare("SELECT order_amount,raw_data FROM orders WHERE order_no=? AND employee_id=0 AND order_scope='department' AND COALESCE(is_deleted,0)=0 ORDER BY id DESC LIMIT 1");
    $q->execute([$orderNo]); $r = $q->fetch();
    if (!$r) return null;
    $raw = json_decode((string)$r['raw_data'], true) ?: [];
    foreach (['买家实际支付金额', '买家实付金额'] as $k) if (isset($raw[$k]) && is_numeric(str_replace(',', '', (string)$raw[$k]))) return (float)str_replace(',', '', (string)$raw[$k]);
    return (float)$r['order_amount'];
}

function mba_run($apply)
{
    $pdo = db();
    $act = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'finance', 'employee_id' => null];
    $ws = $pdo->query("SELECT o.*,COALESCE(s.payment_nickname,'') nick FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.order_no LIKE 'WX-%' AND o.order_date>='2026-10-01' AND o.settlement_status IN ('draft','review') AND o.project_type IN ('网站模板','AI网站定制','网站定制') AND COALESCE(s.payment_nickname,'')<>'' ORDER BY o.id")->fetchAll();
    $byMain = []; $skip = [];
    $mainQ = $pdo->prepare("SELECT o.* FROM project_orders o JOIN project_order_sources s ON s.order_id=o.id WHERE s.payment_nickname=? AND o.order_date=? AND o.shop=? AND o.order_no NOT LIKE 'WX-%' AND o.project_type=? AND o.settlement_status IN ('draft','review','approved') ORDER BY o.id");
    foreach ($ws as $w) {
        $mainQ->execute([$w['nick'], $w['order_date'], $w['shop'], $w['project_type']]); $mains = $mainQ->fetchAll();
        if (count($mains) !== 1) { $skip[] = [$w['order_no'], $w['nick'], count($mains) ? '同买家同日有多个主订单' : '没有找到主订单']; continue; }
        $cash = (int)$pdo->query('SELECT COUNT(*) FROM project_cash_movements WHERE order_id=' . (int)$w['id'])->fetchColumn();
        if ($cash > 0) { $skip[] = [$w['order_no'], $w['nick'], '附加行已有收款记录']; continue; }
        $byMain[(int)$mains[0]['id']]['main'] = $mains[0]; $byMain[(int)$mains[0]['id']]['ws'][] = $w;
    }
    $partQ = $pdo->prepare('SELECT employee_id,commission_group FROM project_participants WHERE order_id=?');
    $done = 0;
    foreach ($byMain as $mid => $g) {
        $main = $g['main']; $sumAdd = array_sum(array_map(function ($w) { return (float)$w['contract_amount']; }, $g['ws'])); $total = round((float)$main['contract_amount'] + $sumAdd, 2);
        $partQ->execute([$mid]); $mp = array_map(function ($r) { return $r['employee_id'] . ':' . $r['commission_group']; }, $partQ->fetchAll());
        $okPeople = true;
        foreach ($g['ws'] as $w) { $partQ->execute([(int)$w['id']]); foreach ($partQ->fetchAll() as $r) if (!in_array($r['employee_id'] . ':' . $r['commission_group'], $mp, true)) $okPeople = false; }
        $label = $main['order_no'] . '（售价 ' . $main['contract_amount'] . ' + 附加 ' . $sumAdd . ' = ' . $total . '，主订单' . $main['settlement_status'] . '，实收 ' . $main['receipt_amount'] . '）';
        if (!$okPeople) { $skip[] = [$main['order_no'], $label, '附加行的参与人不在主订单里']; continue; }
        $approved = in_array($main['settlement_status'], ['approved', 'locked'], true);
        if ($approved) { if (abs((float)$main['receipt_amount'] - $total) > 0.01) { $skip[] = [$main['order_no'], $label, '主订单实收与“售价 + 附加行”不一致']; continue; } }
        else { if ((float)$main['receipt_amount'] > 0.004 && abs((float)$main['receipt_amount'] - $total) > 0.01) { $skip[] = [$main['order_no'], $label, '主订单已登记部分收款，与“售价 + 附加行”不一致']; continue; }
            $paid = mba_paid($main['order_no']); if ($paid === null || abs($paid - $total) > 0.01) { $skip[] = [$main['order_no'], $label, '买家实付 ' . var_export($paid, true) . ' 与“售价 + 附加行”不一致']; continue; } }
        echo ($apply ? '合并 ' : '可合并 ') . $label . ' ← ' . implode('、', array_map(function ($w) { return $w['order_no']; }, $g['ws'])) . "\n";
        if (!$apply) { $done++; continue; }
        $pdo->beginTransaction();
        try {
            foreach ($g['ws'] as $w) {
                $pdo->prepare('UPDATE project_costs SET order_id=? WHERE order_id=?')->execute([$mid, (int)$w['id']]);
                pod_backup((int)$w['id'], $w['order_no'], $act); pod_delete_rows((int)$w['id']);
            }
            $pdo->prepare('UPDATE project_orders SET contract_amount=?,row_version=row_version+1 WHERE id=?')->execute([$total, $mid]);
            ps_audit('order', $mid, 'merge_blank_no_addon', $act, ['merged' => array_column($g['ws'], 'order_no'), 'contract_amount' => $total, 'reason' => '表格合并单元格导致附加行丢了订单号，并回主订单']);
            $pdo->commit(); $done++;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo '  失败：' . $e->getMessage() . "\n"; }
    }
    echo "\n" . ($apply ? '已合并 ' : '可合并 ') . $done . " 张主订单；不动的 " . count($skip) . " 条：\n";
    foreach ($skip as $s) echo '  ' . $s[0] . ' ' . $s[1] . ' — ' . $s[2] . "\n";
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) mba_run(in_array('--apply', $argv, true));
