<?php
/**
 * 月度上传后自检：把这几个月反复出现的“数据录得不对导致分成算错”的情况提前列出来，财务在审核 / 结算前一眼看到，不用等员工发现。
 * 每项返回 [标题, 说明, 行[订单号, 说明]]；没有问题的项不返回。
 */
require_once __DIR__ . '/ProjectSettlement.php';

function pmc_run($month)
{
    $from = $month . '-01'; $until = date('Y-m-d', strtotime($from . ' +1 month')); $pdo = db(); $out = [];
    $orders = function ($where, $params = []) use ($pdo, $from, $until) {
        $q = $pdo->prepare("SELECT o.* FROM project_orders o WHERE o.order_date>=? AND o.order_date<? AND o.settlement_status NOT IN ('locked') AND " . $where . ' ORDER BY o.id LIMIT 200');
        $q->execute(array_merge([$from, $until], $params)); return $q->fetchAll();
    };
    // 1. AI定制 / 博山定制 与技术人数对不上
    $rows = [];
    foreach ($orders("o.project_type='AI网站定制' AND o.order_kind IN ('AI定制','博山定制')") as $o) {
        $n = 0; foreach (ps_participants((int)$o['id']) as $p) if ($p['commission_group'] === 'technical' && mb_strpos((string)$p['role_name'], '售后') === false && mb_strpos((string)$p['role_name'], '测试') === false) $n++;
        if ($o['order_kind'] === 'AI定制' && $n >= 2) $rows[] = [$o['order_no'], 'AI定制（一个技术做）却有 ' . $n . ' 个技术，会按前后端分开重复计提成'];
        elseif ($o['order_kind'] === '博山定制' && $n === 1) $rows[] = [$o['order_no'], '博山定制（前后端两个技术）只有 1 个技术，确认是不是 AI定制'];
    }
    if ($rows) $out[] = ['订单类型和技术人数对不上', 'AI定制 = 一个技术做前后端；博山定制 = 前端、后端两个技术。', $rows];
    // 2. 空间+域名套餐又另计了域名成本
    $rows = [];
    foreach ($orders("EXISTS (SELECT 1 FROM project_costs p JOIN project_cost_templates t ON t.id=p.template_id WHERE p.order_id=o.id AND p.category='program' AND t.specification LIKE '%空间+域名%' AND p.review_status<>'rejected') AND EXISTS (SELECT 1 FROM project_costs d WHERE d.order_id=o.id AND d.category='domain' AND d.review_status<>'rejected')") as $o) $rows[] = [$o['order_no'], '程序套餐已含域名，又另计了域名成本，成本多算'];
    if ($rows) $out[] = ['套餐已含域名却重复计域名成本', '“空间+域名”套餐的成本里已有域名。', $rows];
    // 3. 同一付款号多个网站，售价合计和店铺实付对不上
    require_once __DIR__ . '/ProjectSiteProjects.php'; $rows = []; $seen = [];
    foreach ($orders('EXISTS (SELECT 1 FROM project_site_projects s WHERE s.order_id=o.id)') as $o) { $w = psp_payment_mismatch((int)$o['id']); $s = psp_order((int)$o['id']); if ($w !== '' && !isset($seen[$s['external_order_no']])) { $seen[$s['external_order_no']] = 1; $rows[] = [$s['external_order_no'], $w]; } }
    if ($rows) $out[] = ['同一付款号多个网站，金额对不上', '可能重复建了订单，或 SSL / 纯利润附加行没并进来。', $rows];
    // 4. 没有订单号的附加行（WX- 内部号）还没处理
    $rows = [];
    foreach ($orders("o.order_no LIKE 'WX-%' AND o.project_type IN ('网站模板','AI网站定制','网站定制') AND o.settlement_status IN ('draft','review') AND o.contract_amount>0 AND o.contract_amount<=700") as $o) $rows[] = [$o['order_no'], '无订单号的小额单 ¥' . number_format((float)$o['contract_amount'], 2) . '，像是表格合并单元格丢了订单号的附加项（SSL / 加语言等），请核对是否该并入主订单'];
    if ($rows) $out[] = ['无订单号的小额订单', '客服表格订单编号格合并单元格，导入时附加行丢了订单号。', $rows];
    // 5. 参与人没匹配到分成规则
    $rows = [];
    foreach ($orders("o.settlement_status IN ('draft','review') AND o.contract_amount>0") as $o) foreach (ps_participants((int)$o['id']) as $p) if (!ps_rule_for($p['commission_group'], $o['project_type'], $o['order_date'], $p['role_name'], $o['order_kind'])) $rows[] = [$o['order_no'], '“' . $p['role_name'] . '”（' . ($p['commission_group'] === 'technical' ? '技术' : '客服') . '）没有匹配到分成规则'];
    if ($rows) $out[] = ['参与人没有匹配到分成规则', '岗位名 / 订单类型和规则中心对不上，订单算不出分成。', $rows];
    // 6. 预期 SSL 成本没补
    $rows = [];
    foreach ($orders("o.settlement_status IN ('draft','review') AND EXISTS (SELECT 1 FROM project_order_resources r WHERE r.order_id=o.id AND r.ssl_expected_amount>0) AND (SELECT COALESCE(SUM(c.amount),0) FROM project_costs c WHERE c.order_id=o.id AND c.category='certificate' AND c.review_status='approved') < (SELECT r2.ssl_expected_amount FROM project_order_resources r2 WHERE r2.order_id=o.id)") as $o) $rows[] = [$o['order_no'], '登记了 SSL 报备成本，但还没有按“证书”类别补录并审核成本，审核会失败'];
    if ($rows) $out[] = ['SSL 成本没补录', '', $rows];
    // 7. 没有任何分成规则也没有收款的未发货之外的“已完成”订单：成本为 0 的网站定制（可能漏填成本）
    $rows = [];
    foreach ($orders("o.project_type IN ('AI网站定制','网站定制') AND o.order_kind='博山定制' AND o.settlement_status IN ('draft','review') AND o.contract_amount>=1000 AND NOT EXISTS (SELECT 1 FROM project_costs c WHERE c.order_id=o.id AND c.review_status<>'rejected')") as $o) $rows[] = [$o['order_no'], '售价 ¥' . number_format((float)$o['contract_amount'], 2) . '，一条成本都没有（域名 / 证书是否漏填？）'];
    if ($rows) $out[] = ['大额定制单没有任何成本', '没有成本时按“无需另计”处理，提成会偏高。', $rows];
    // 8. 店铺状态和审核状态矛盾：已审核却是交易关闭 / 待发货；客服可能填错了订单号（填成了别的订单）
    $rows = [];
    foreach ($orders("o.project_type IN ('网站模板','AI网站定制','网站定制','小程序开发') AND o.settlement_status IN ('approved','locked') AND EXISTS (SELECT 1 FROM project_order_sources s WHERE s.order_id=o.id AND (s.trade_status LIKE '%交易关闭%' OR s.trade_status LIKE '%待发货%' OR s.trade_status LIKE '%等待卖家发货%')) AND EXISTS (SELECT 1 FROM project_commission_snapshots c WHERE c.order_id=o.id AND c.commission_amount<>0)") as $o) {
        $ts = $pdo->prepare('SELECT trade_status FROM project_order_sources WHERE order_id=?'); $ts->execute([(int)$o['id']]);
        $rows[] = [$o['order_no'], '已审核有分成，但店铺状态是“' . $ts->fetchColumn() . '”：订单号可能填错，或订单其实还没完成 / 发货'];
    }
    if ($rows) $out[] = ['已审核但店铺状态是交易关闭 / 待发货', '可能上传时填错了订单号（填成了别的订单）。', $rows];
    // 11. 疑似重复订单：同买家、同一天、同金额、同一批参与人，却有两张（常见：一张是系统生成的 WX- 内部号，另一张有真实 / 对公编号），分成会算两遍
    $rows = [];
    $dq = $pdo->prepare("SELECT o.id,o.order_no,o.contract_amount,COALESCE(s.payment_nickname,'') nick,o.order_date,(SELECT GROUP_CONCAT(CONCAT(p.employee_id,':',p.commission_group) ORDER BY p.employee_id SEPARATOR ',') FROM project_participants p WHERE p.order_id=o.id) ppl FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.order_date>=? AND o.order_date<? AND o.contract_amount>0 AND o.project_type IN ('网站模板','AI网站定制','网站定制') AND COALESCE(s.payment_nickname,'')<>'' ORDER BY o.id");
    $dq->execute([$from, $until]); $groups = [];
    foreach ($dq->fetchAll() as $o) $groups[$o['nick'] . '|' . $o['order_date'] . '|' . (float)$o['contract_amount']][] = $o;
    foreach ($groups as $g) { if (count($g) < 2) continue; $hasWx = false; foreach ($g as $x) if (strpos($x['order_no'], 'WX-') === 0) $hasWx = true; if (!$hasWx) continue; $ppl = []; foreach ($g as $x) $ppl[$x['ppl']] = true; $share = false; foreach ($g as $x) foreach ($g as $y) if ($x['id'] !== $y['id']) { $a = array_intersect(explode(',', (string)$x['ppl']), explode(',', (string)$y['ppl'])); if ($a) $share = true; } if (!$share) continue; $rows[] = [implode(' / ', array_map(function ($x) { return $x['order_no']; }, $g)), '买家 ' . $g[0]['nick'] . '，' . $g[0]['order_date'] . '，都是 ¥' . number_format((float)$g[0]['contract_amount'], 2) . '，有相同的参与人：疑似同一笔录了两遍，核对后删除重复的']; }
    if ($rows) $out[] = ['疑似重复订单', '同买家、同日、同金额、有相同参与人，且其中一张是无订单号（WX-）的内部号订单。', $rows];
    // 9. 有退款线索的订单其店铺状态交易关闭、且订单还有未完成：退款可能挂错订单
    $rows = [];
    foreach ($orders("o.refund_amount>0 AND o.delivery_status='unfinished' AND o.settlement_status IN ('draft','review')") as $o) $rows[] = [$o['order_no'], '未完成订单上记了退款 ¥' . number_format((float)$o['refund_amount'], 2) . '：核对是不是订单号填错，退款属于另一张订单'];
    if ($rows) $out[] = ['未完成订单上有退款', '', $rows];
    // 12. 完成情况汇总表登记未完成，系统里却已审核 / 标了完成：已核算月份撤不了的、或汇总表上传之后又被改动的
    $rows = [];
    try {
        foreach ($orders("o.project_type IN ('网站模板','AI网站定制','网站定制') AND o.settlement_status IN ('approved','locked') AND EXISTS (SELECT 1 FROM project_completion_sheet w WHERE w.order_no=o.order_no AND w.sheet_status='unf')") as $o) $rows[] = [$o['order_no'], '汇总表登记为未完成，但已审核有分成：重新上传汇总表自动撤回，已核算月份的用“售后调整”冲回'];
    } catch (PDOException $e) { /* 从未上传过汇总表 */ }
    if ($rows) $out[] = ['汇总表登记未完成却已审核', '以“网站订单完成同步”上传的汇总表为准：未完成的网站订单不计提成。', $rows];
    // 13. 员工在每日待完善弹窗里选了“无法更正，交给财务”的订单
    $rows = [];
    try {
        $sk = $pdo->query("SELECT s.order_id,s.issue,s.reason,e.name,o.order_no FROM project_daily_fix_skip s JOIN project_orders o ON o.id=s.order_id JOIN employees e ON e.id=s.employee_id WHERE o.settlement_status IN ('draft','review') ORDER BY s.created_at DESC LIMIT 100");
        $lab = ['kind' => '订单类型没选', 'cost' => '商品成本没填', 'domain' => '域名使用没确认'];
        foreach ($sk->fetchAll() as $s) $rows[] = [$s['order_no'], $s['name'] . ' 说无法更正：' . ($lab[$s['issue']] ?? $s['issue']) . ($s['reason'] !== '' ? '（' . $s['reason'] . '）' : '') . '，请财务处理'];
    } catch (PDOException $e) { /* 还没有人用过弹窗 */ }
    if ($rows) $out[] = ['员工标记“无法更正”的订单', '每日待完善弹窗里选了交给财务的，员工不会再被提醒。', $rows];
    return $out;
}
