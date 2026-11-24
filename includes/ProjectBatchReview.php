<?php
/**
 * 批量审核申请：合作人员某月还有没审核通过的订单时，在“订单审核申请”页看到每单卡在哪、怎么解决，并向财务提交批量审核申请；
 * 财务在同一页看到申请（侧栏 / 订单页有待办提示），抽查后一键“全部通过”：对所选订单登记收款（按售价）、标记交付完成并审核生成分成。
 * 财务的这个动作等同于在订单页手动登记收款 + 审核，全部记审计；“异常”订单默认不勾选，需要单独处理。
 */
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectAutoReview.php';

function pbr_ensure()
{
    static $done = false;
    if ($done) return;
    try { db()->query('SELECT 1 FROM project_batch_reviews LIMIT 0'); $done = true; return; }
    catch (PDOException $e) { if ((string)$e->getCode() !== '42S02') throw $e; }
    db()->exec("CREATE TABLE IF NOT EXISTS project_batch_reviews (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      employee_id INT NOT NULL, month CHAR(7) NOT NULL,
      note VARCHAR(500) NOT NULL DEFAULT '',
      order_count INT NOT NULL DEFAULT 0, amount DECIMAL(14,2) NOT NULL DEFAULT 0,
      status ENUM('pending','done','rejected') NOT NULL DEFAULT 'pending',
      handled_by VARCHAR(80) NULL, handled_note VARCHAR(500) NULL, handled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_status (status, created_at), KEY idx_emp_month (employee_id, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** 某人某月参与的、还没审核通过的订单，附自动核对状态和卡点说明。 */
/**
 * 同一付款号拆成多个网站项目（site-xxx）：财务批量通过即视为核对了实收分配——
 * 各项目按各自售价登记收款（还没有收款的），合计 = 客户付款总额，再记一条“共用付款分配已核对”。
 * 只对财务生效；店铺实付流水与合计不一致、项目没有售价、或已有对不上的收款时抛错，由财务逐单处理。
 */
function pbr_prepare_site_group($oid, array $actor, $adminId, $who)
{
    if (($actor['role'] ?? '') !== 'finance') return;
    require_once __DIR__ . '/ProjectSiteProjects.php';
    $site = psp_order((int)$oid);
    if (!$site) return;
    $root = (int)$site['root_order_id'];
    $members = psp_members($root, true);
    if (count($members) < 2) return;
    $v = psp_verification($root);
    if ($v && hash_equals((string)$v['allocation_hash'], psp_allocation_hash($members))) return;
    $pdo = db(); $paid = 0;
    foreach ($members as $m) {
        $sale = (int)round((float)$m['contract_amount'] * 100);
        if ($sale <= 0) throw new RuntimeException('同一付款号下有网站项目没有售价，请先补售价');
        $paid += $sale;
        $cash = (int)round((float)$m['receipt_amount'] * 100);
        if ($cash === $sale) continue;
        if ($cash !== 0 || (float)$m['refund_amount'] > 0) throw new RuntimeException('同一付款号下有网站项目的收款或退款与售价对不上，请财务逐单核对');
        $st = $pdo->prepare('SELECT settlement_status FROM project_orders WHERE id=? FOR UPDATE'); $st->execute([(int)$m['order_id']]);
        if (in_array($st->fetchColumn(), ['approved', 'locked'], true)) throw new RuntimeException('同一付款号下有已审核的网站项目，实收与售价对不上，请财务核对');
        $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'receipt',?,?,'approved',?,?,?,NOW())")
            ->execute([(int)$m['order_id'], $sale / 100, $who . '批量审核：同一付款号分项目按售价登记收款', $actor['type'], $actor['id'], $adminId]);
        ps_recalculate_cash((int)$m['order_id']);
    }
    psp_verify($root, number_format($paid / 100, 2, '.', ''), $who . '批量审核：同一付款号 ' . count($members) . ' 个网站项目按各自售价分配，合计 ¥' . number_format($paid / 100, 2, '.', ''), $actor);
}

/**
 * 批量审核前的准备（调用方持有事务）：财务/授权人员勾选通过即视为认可——
 * 未确认的资源按“无需另计”确认并同步商品成本、待审核成本一并通过。收款/退款仍须单独审核；SSL 凭证等仍由 ps_approve_order 把关。
 */
/** 财务批量通过时：订单有售价、还没有实收，按售价登记收款（与订单审核页一致，记审计）；没有售价的订单不处理。 */
function pbr_register_receipt($oid, array $actor, $adminId, $who)
{
    if (($actor['role'] ?? '') !== 'finance') return false;
    $pdo = db();
    $q = $pdo->prepare('SELECT contract_amount,receipt_amount,refund_amount FROM project_orders WHERE id=? FOR UPDATE'); $q->execute([(int)$oid]); $o = $q->fetch();
    if (!$o || (float)$o['receipt_amount'] - (float)$o['refund_amount'] > 0 || (float)$o['contract_amount'] <= 0) return false;
    $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'receipt',?,?,'approved',?,?,?,NOW())")
        ->execute([(int)$oid, round((float)$o['contract_amount'], 2), $who . '批量审核：抽查后按售价确认收款', $actor['type'], $actor['id'], $adminId]);
    ps_recalculate_cash((int)$oid);
    ps_audit('order', (int)$oid, 'batch_review_receipt', $actor, ['amount' => round((float)$o['contract_amount'], 2)]);
    return true;
}

function pbr_prepare_order($oid, array $actor, $adminId, $who)
{
    pbr_prepare_site_group($oid, $actor, $adminId, $who);
    $pdo = db();
    $row = $pdo->prepare('SELECT project_type FROM project_orders WHERE id=?'); $row->execute([$oid]);
    if (!empty(ps_business_catalog()[ps_business_normalize((string)$row->fetchColumn())]['resources'])) {
        $res = $pdo->prepare('SELECT domain_mode FROM project_order_resources WHERE order_id=?'); $res->execute([$oid]);
        $mode = $res->fetchColumn();
        if ($mode === false || $mode === 'pending') {
            require_once __DIR__ . '/ProjectIntake.php'; poi_ensure();
            ps_intake_confirm_resources($oid, 'none', 0, 0, $actor);
            ps_audit('order', $oid, 'confirm_resources', $actor, ['domain_mode' => 'none', 'via' => 'batch_review']);
        }
    }
    // 补款 / 小类加项单：记下主订单，并按“同客户”算（不再给单量补助）；官费和件数在主订单上。
    require_once __DIR__ . '/ProjectTrademarkCost.php';
    if (($main = ptc_addon_main_order($oid)) > 0) {
        [$ad] = ptc_order_pricing_data($oid);
        if ((int)($ad['trademark_addon_of'] ?? 0) !== $main) {
            $ad['trademark_addon_of'] = $main;
            $pdo->prepare("INSERT INTO project_order_details (order_id,business_name,details_json) VALUES (?,'商标',?) ON DUPLICATE KEY UPDATE details_json=VALUES(details_json)")->execute([$oid, json_encode($ad, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        }
        $pdo->prepare("UPDATE project_orders SET order_kind='同客户',row_version=row_version+1 WHERE id=? AND order_kind IN ('普通订单','新客户')")->execute([$oid]);
        ps_audit('order', $oid, 'trademark_addon', $actor, ['main_order' => $main, 'via' => 'batch_review']);
    }
    // 商品明细还没关联成本、但订单上已有同类（程序 / 证书等）未被占用的成本：财务批量通过即认可它们对应，自动关联（只关联，不新增成本）
    require_once __DIR__ . '/ProjectIntake.php'; poi_ensure();
    $claimed = [];
    foreach (poi_items($oid) as $it) if ($it['cost_id']) $claimed[(int)$it['cost_id']] = true;
    foreach (poi_items($oid) as $it) {
        if ($it['cost_id']) continue;
        $cq = $pdo->prepare("SELECT id FROM project_costs WHERE order_id=? AND category=? AND review_status IN ('approved','pending') ORDER BY id");
        $cq->execute([$oid, $it['category']]);
        foreach ($cq->fetchAll(PDO::FETCH_COLUMN) as $cid) if (empty($claimed[(int)$cid])) {
            $pdo->prepare('UPDATE project_order_items SET cost_id=? WHERE id=? AND cost_id IS NULL')->execute([(int)$cid, (int)$it['id']]);
            $claimed[(int)$cid] = true;
            ps_audit('order', $oid, 'batch_review_link_item_cost', $actor, ['item' => $it['item_name'], 'cost_id' => (int)$cid]);
            break;
        }
    }
    // 关联完后仍没成本的商品明细，如果有完全相同（名称 / 类别 / 售价 / 模板）且已关联成本的明细：是重复导入产生的重复行，删掉重复明细行（不动任何成本记录，原行备份进审计）
    $rest = poi_items($oid);
    foreach ($rest as $it) {
        if ($it['cost_id']) continue;
        foreach ($rest as $twin) {
            if ((int)$twin['id'] === (int)$it['id'] || !$twin['cost_id']) continue;
            if ($twin['item_name'] === $it['item_name'] && $twin['category'] === $it['category'] && (float)$twin['sale_amount'] === (float)$it['sale_amount'] && (int)$twin['template_id'] === (int)$it['template_id']) {
                $pdo->prepare('DELETE FROM project_order_items WHERE id=? AND cost_id IS NULL')->execute([(int)$it['id']]);
                ps_audit('order', $oid, 'batch_review_drop_duplicate_item', $actor, ['item' => $it]);
                break;
            }
        }
    }
    // 同一件商品被导入成两条（如“补差价”和“1年补差价”：只差年限前缀，类别 / 售价 / 原表成本 / 模板都一样，都没关联成本）：留一条，删掉重复的明细行（不动任何成本记录，原行备份进审计）
    $dupSeen = [];
    foreach (poi_items($oid) as $it) {
        if ($it['cost_id']) continue;
        $key = preg_replace('/^\d+\s*年\s*/u', '', trim((string)$it['item_name'])) . '|' . $it['category'] . '|' . (float)$it['sale_amount'] . '|' . (float)($it['reported_cost'] ?? 0) . '|' . (int)$it['template_id'];
        if (isset($dupSeen[$key])) { $pdo->prepare('DELETE FROM project_order_items WHERE id=? AND cost_id IS NULL')->execute([(int)$it['id']]); ps_audit('order', $oid, 'batch_review_drop_duplicate_item', $actor, ['item' => $it, 'kept' => $dupSeen[$key]]); }
        else $dupSeen[$key] = (int)$it['id'];
    }
    // 只剩一个商品没关联成本、订单上也只剩一条没被占用的成本（财务在订单页“补成本”填的那一条，类别可能对不上）：财务批量通过即认可它们对应，关联上
    $restItems = array_values(array_filter(poi_items($oid), function ($it) { return !$it['cost_id'] && (!empty($it['template_id']) || (float)($it['reported_cost'] ?? 0) > 0); }));
    if (count($restItems) === 1) {
        $used = array_filter(array_column(poi_items($oid), 'cost_id'));
        $cq = $pdo->prepare("SELECT id FROM project_costs WHERE order_id=? AND review_status IN ('approved','pending') ORDER BY id"); $cq->execute([$oid]);
        $free = array_values(array_filter($cq->fetchAll(PDO::FETCH_COLUMN), function ($cid) use ($used) { return !in_array($cid, $used); }));
        if (count($free) === 1) {
            $pdo->prepare('UPDATE project_order_items SET cost_id=? WHERE id=? AND cost_id IS NULL')->execute([(int)$free[0], (int)$restItems[0]['id']]);
            ps_audit('order', $oid, 'batch_review_link_item_cost', $actor, ['item' => $restItems[0]['item_name'], 'cost_id' => (int)$free[0], 'by' => 'only_free_cost']);
        }
    }
    // SSL 报备成本：资源登记了预期 SSL 成本，但财务补的成本记在了别的类别（如“成本”类）、金额正好等于预期：按“证书”类别记，审核才不会被“SSL 报备成本尚未按凭证补录”拦住
    $sslQ = $pdo->prepare('SELECT ssl_expected_amount FROM project_order_resources WHERE order_id=?'); $sslQ->execute([$oid]); $sslExpected = (float)$sslQ->fetchColumn();
    if ($sslExpected > 0) {
        $have = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM project_costs WHERE order_id=? AND category='certificate' AND review_status IN ('approved','pending')"); $have->execute([$oid]);
        if ((float)$have->fetchColumn() + 0.004 < $sslExpected) {
            $other = $pdo->prepare("SELECT id FROM project_costs WHERE order_id=? AND category<>'certificate' AND review_status IN ('approved','pending') AND ABS(amount-?)<0.005 ORDER BY id LIMIT 1"); $other->execute([$oid, $sslExpected]);
            if ($cid = $other->fetchColumn()) {
                $pdo->prepare("UPDATE project_costs SET category='certificate' WHERE id=?")->execute([(int)$cid]);
                ps_audit('cost', (int)$cid, 'batch_review_recategorize', $actor, ['to' => 'certificate', 'amount' => $sslExpected, 'reason' => '金额等于资源登记的预期 SSL 成本']);
            }
        }
    }
    $pc = $pdo->prepare("SELECT id FROM project_costs WHERE order_id=? AND review_status='pending'"); $pc->execute([$oid]);
    foreach ($pc->fetchAll(PDO::FETCH_COLUMN) as $costId) {
        $pdo->prepare("UPDATE project_costs SET review_status='approved',reviewed_by_admin=?,review_note=? WHERE id=? AND review_status='pending'")->execute([$adminId, $who . '批量审核通过', (int)$costId]);
        ps_audit('cost', (int)$costId, 'review', $actor, ['decision' => 'approved', 'via' => 'batch_review']);
    }
}

/**
 * 没有订单号的加购行（客服表格里 SSL 证书 / 加语言等附加项，订单编号格是合并单元格、导入时丢了）：找同买家、同日、同店铺的主订单，
 * 用店铺流水价核对“主订单 + 本单”，让财务一眼看出这单只是主订单的附加项，不是漏单。
 */
function pbr_addon_hint(array $o)
{
    try {
        $q = db()->prepare("SELECT o.id,o.order_no,o.contract_amount FROM project_orders o JOIN project_order_sources s ON s.order_id=o.id WHERE s.payment_nickname=? AND o.order_date=? AND o.shop=(SELECT shop FROM project_orders WHERE id=?) AND o.order_no NOT LIKE 'WX-%' AND o.id<>? AND o.project_type=? ORDER BY o.id LIMIT 5");
        $q->execute([$o['nick'], $o['order_date'], (int)$o['id'], (int)$o['id'], $o['project_type']]);
        $mains = $q->fetchAll();
        if (!$mains) return '没有订单号，也没找到同买家同日的主订单（请核对是否漏填订单号）';
        require_once __DIR__ . '/ProjectRefundMatch.php';
        $main = $mains[0]; $flow = prm_flow_entry($main['order_no']); $fp = $flow ? (float)$flow['price'] : null;
        $text = '没有订单号：像是主订单 ' . $main['order_no'] . '（¥' . number_format((float)$main['contract_amount'], 2) . '）的附加项（同买家同日）';
        if ($fp === null) return $text . '；主订单没有店铺流水，无法核对金额';
        $sum = (float)$main['contract_amount'] + (float)$o['contract_amount'];
        return $text . (abs($fp - $sum) < 0.01 ? '；店铺流水 ¥' . number_format($fp, 2) . ' = 主订单 + 本单，金额对得上，可以通过' : '；店铺流水 ¥' . number_format($fp, 2) . '，与“主订单 + 本单” ¥' . number_format($sum, 2) . ' 不一致，请核对');
    } catch (Throwable $e) { return ''; }
}

/**
 * 审核范围：财务不受限；授权审核人员（如网站人事）只能审核指定业务；部门主管（project_dept_heads）可审核本部门成员的订单。
 * 都不含本人参与的订单。返回 ['businesses'=>[], 'departments'=>[], 'self'=>员工id]；没有审核资格返回 null。
 */
function pbr_scope($actor)
{
    if (($actor['role'] ?? '') === 'finance') return ['all' => true];
    $emp = (int)($actor['employee_id'] ?? 0);
    if ($emp <= 0) return null;
    $biz = [];
    try {
        $q = db()->prepare('SELECT business_name FROM project_batch_review_delegates WHERE employee_id=?'); $q->execute([$emp]);
        $biz = $q->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { /* 授权表还没建 */ }
    require_once __DIR__ . '/ProjectDeptHead.php';
    $deps = pdh_departments($actor);
    return ($biz || $deps) ? ['businesses' => $biz, 'departments' => $deps, 'self' => $emp] : null;
}

/** 财务，或被授权审核该业务、且本人没参与此订单的人员（如网站人事冯超）：可审核成本、确认资源。 */
function pbr_can_review_order(array $actor, array $order)
{
    if (($actor['role'] ?? '') === 'finance') return true;
    $scope = pbr_scope($actor);
    if (!$scope || !empty($scope['all']) || empty($order['id'])) return false;
    $byBusiness = in_array(ps_business_normalize((string)($order['project_type'] ?? '')), array_map('ps_business_normalize', $scope['businesses'] ?? []), true);
    $byDepartment = !$byBusiness && !empty($scope['departments']) && pdh_can_view_order((int)$order['id'], $actor);
    if (!$byBusiness && !$byDepartment) return false;
    $q = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
    $q->execute([(int)$order['id'], (int)$scope['self']]);
    return !$q->fetchColumn();
}

function pbr_scope_sql($scope, &$params)
{
    $params = [];
    if (!$scope || !empty($scope['all'])) return '';
    $biz = $scope['businesses'] ?? []; $deps = $scope['departments'] ?? []; $or = [];
    if ($biz) { $or[] = 'o.project_type IN (' . implode(',', array_fill(0, count($biz), '?')) . ')'; $params = array_merge($params, array_values($biz)); }
    // 部门主管：被审核的人（查询里的 p.employee_id）须属于所管部门
    if ($deps) { $or[] = 'p.employee_id IN (SELECT id FROM employees WHERE department IN (' . implode(',', array_fill(0, count($deps), '?')) . '))'; $params = array_merge($params, array_values($deps)); }
    if (!$or) return ' AND 1=0';
    $params[] = (int)$scope['self'];
    return ' AND (' . implode(' OR ', $or) . ') AND NOT EXISTS (SELECT 1 FROM project_participants sp WHERE sp.order_id=o.id AND sp.employee_id=?)';
}

function pbr_orders($employeeId, $month, $scope = null)
{
    require_once __DIR__ . '/ProjectMiniappDelivery.php';
    $q = db()->prepare("SELECT o.id,o.order_no,o.order_date,o.project_type,o.order_kind,o.note,o.contract_amount,o.receipt_amount,o.refund_amount,o.delivery_status,o.settlement_status,
        COALESCE(s.payment_nickname,'') nick, COALESCE(s.trade_status,'') trade_status, a.state review_state, a.reasons_json
        FROM project_orders o JOIN project_participants p ON p.order_id=o.id LEFT JOIN project_order_sources s ON s.order_id=o.id LEFT JOIN project_auto_reviews a ON a.order_id=o.id
        WHERE p.employee_id=? AND o.order_date>=? AND o.order_date<? AND o.settlement_status NOT IN ('approved','locked')" . pbr_scope_sql($scope, $extra) . " GROUP BY o.id ORDER BY o.order_date,o.id");
    $q->execute(array_merge([(int)$employeeId, $month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month'))], $extra));
    $rows = $q->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $isUnshipped = ps_deferred_order_unshipped($r, ['trade_status' => $r['trade_status'] ?? '']);
        // 网站类（非小程序定制）订单：没收款记录 / 没标完成不算“未发货”——批量审核会按售价登记收款、并把交付记为已完成（财务抽查后确认）；
        // 只有店铺状态明确是待发货 / 未付款 / 交易关闭的才排除。
        if ($isUnshipped && ps_deferred_delivery_order($r) && !ps_miniapp_custom_order($r)) {
            // 网站业务交付没完成：不计提成，即使已到账也不进批量审核（等技术标已完成）；完成了的，只排除待发货 / 未付款 / 交易关闭
            $isUnshipped = ps_website_unfinished($r) || preg_match('/未发货|待发货|等待卖家发货|等待发货|未付款|待付款|交易关闭|已关闭|取消/u', (string)($r['trade_status'] ?? '')) === 1;
        }
        // 小程序定制没有任何店铺状态（微信 / 对公付款，没有店铺流水）：由财务按到账 / 交付情况确认，批量审核时登记收款并记为已完成
        if ($isUnshipped && ps_miniapp_custom_order($r) && trim((string)($r['trade_status'] ?? '')) === '') $isUnshipped = false;
        // 财务/主管审核时：未发货未到账的业务单不推给财务审核
        if ($scope !== null && $isUnshipped) {
            continue;
        }
        $why = [];
        if ($isUnshipped) {
            $why[] = ps_website_unfinished($r) ? '网站业务还没做完，不计提成（即使已到账）：在“网站订单完成同步”登记完成情况（或订单里标“已完成”）后才结算' : '尚未发货或未到账，待客户收货后结算（不推给财务审核）';
        }
        if ((float)$r['receipt_amount'] - (float)$r['refund_amount'] <= 0) $why[] = (float)$r['contract_amount'] > 0 ? '还没有确认的收款' : ($r['order_kind'] === '编辑对接' ? '' : '没有售价，需补售价（或确认这是只按每单补助结算的单）');
        if (ps_website_unfinished($r)) $why[] = '还没确认业务已完成';
        $exception = $r['review_state'] === 'exception' || $isUnshipped;
        foreach (json_decode((string)$r['reasons_json'], true) ?: [] as $reason) if (($reason['kind'] ?? '') === 'exception') $why[] = (string)$reason['text'];
        // 定制订单（博山 / AI / 华梦）的域名、空间还没确认：通过会按“无需另计”处理，不计域名成本——提醒财务核对是不是我们注册的域名
        if (in_array($r['order_kind'], ['博山定制', 'AI定制', '华梦定制'], true) && $r['project_type'] !== '小程序开发') {
            $rs = db()->prepare("SELECT domain_mode FROM project_order_resources WHERE order_id=?"); $rs->execute([(int)$r['id']]); $mode = $rs->fetchColumn();
            if (($mode === false || $mode === 'pending') && !preg_match('/ssl|证书|加语言|加中文|补差价/iu', (string)($r['note'] ?? ''))) $why[] = '域名 / 空间还没确认：通过会按“无需另计”（不计域名成本）；如果域名是我们注册的，请先点“打开”选域名成本';
        }
        require_once __DIR__ . '/ProjectSiteProjects.php';
        if (($siteWarn = psp_payment_mismatch((int)$r['id'])) !== '') { $why[] = $siteWarn; $r['site_mismatch'] = true; }
        // 商品明细要求有成本、却没关联上，而订单上没有足够的空闲成本可关联：写明，别让财务点了通过才看到失败
        try {
            require_once __DIR__ . '/ProjectOrderItems.php';
            $itemsNeed = array_filter(poi_items((int)$r['id']), function ($it) { return !$it['cost_id'] && (!empty($it['template_id']) || (float)($it['reported_cost'] ?? 0) > 0); });
            if ($itemsNeed) {
                $norm = []; foreach ($itemsNeed as $it) $norm[preg_replace('/^\d+\s*年\s*/u', '', trim((string)$it['item_name'])) . '|' . $it['category'] . '|' . (float)$it['sale_amount'] . '|' . (float)($it['reported_cost'] ?? 0) . '|' . (int)$it['template_id']] = $it['item_name'];
                $used = array_filter(array_column(poi_items((int)$r['id']), 'cost_id'));
                $fq = db()->prepare("SELECT id FROM project_costs WHERE order_id=? AND review_status IN ('approved','pending')"); $fq->execute([(int)$r['id']]);
                $free = count(array_diff($fq->fetchAll(PDO::FETCH_COLUMN), $used));
                if (count($norm) > $free) { $why[] = '商品“' . implode('”、“', array_values($norm)) . '”还没有对应的成本（订单上没有可关联的成本），通过前请点“打开”补成本'; $exception = true; } // 缺成本：批量通过会失败，不能标成“可直接审核”
            }
        } catch (Throwable $e) { /* 不影响列表 */ }
        // 商标：成本项目和办理事项不一致，审核一定失败——提前标异常
        if (ps_business_normalize($r['project_type']) === '商标') {
            try { require_once __DIR__ . '/ProjectTrademarkCost.php'; ptc_approval_guard((int)$r['id'], ps_costs((int)$r['id'])); }
            catch (RuntimeException $e) { $why[] = $e->getMessage(); $exception = true; } catch (Throwable $e) { /* 不影响列表 */ }
        }
        if (strpos((string)$r['order_no'], 'WX-') === 0 && trim((string)$r['nick']) !== '') { $hint = pbr_addon_hint($r); if ($hint !== '') $why[] = $hint; }
        if (!empty($r['site_mismatch'])) $exception = true; // 付款号合计对不上：默认不勾选，先核对
        $r['why'] = array_values(array_unique(array_filter($why, 'strlen')));
        $r['exception'] = $exception;
        $r['unshipped'] = $isUnshipped;
        $out[] = $r;
    }
    return $out;
}

function pbr_submit(array $actor, $month, $note)
{
    pbr_ensure();
    $eid = (int)($actor['employee_id'] ?? 0);
    if ($eid <= 0 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) throw new RuntimeException('请选择要申请的月份');
    $orders = pbr_orders($eid, $month);
    // 过滤掉未发货/未到账的订单，不推给财务
    $orders = array_values(array_filter($orders, function($o) { return empty($o['unshipped']); }));
    if (!$orders) throw new RuntimeException('这个月没有可审核的订单（未发货或未到账的订单需待实际交付发货后结算）');
    $sum = round(array_sum(array_column($orders, 'contract_amount')), 2);
    $note = mb_substr(trim((string)$note), 0, 500);
    $q = db()->prepare("SELECT id FROM project_batch_reviews WHERE employee_id=? AND month=? AND status='pending'"); $q->execute([$eid, $month]); $id = (int)$q->fetchColumn();
    if ($id) db()->prepare('UPDATE project_batch_reviews SET note=?,order_count=?,amount=? WHERE id=?')->execute([$note, count($orders), $sum, $id]);
    else { db()->prepare('INSERT INTO project_batch_reviews (employee_id,month,note,order_count,amount) VALUES (?,?,?,?,?)')->execute([$eid, $month, $note, count($orders), $sum]); $id = (int)db()->lastInsertId(); }
    ps_audit('batch_review', $id, 'submit', $actor, ['month' => $month, 'orders' => count($orders), 'amount' => $sum]);
    // 通知本人所在部门的主管（不含本人），他们有资格审核本部门成员的订单
    try {
        require_once __DIR__ . '/ProjectDeptHead.php'; require_once __DIR__ . '/dup_feedback.php';
        $nameQ = db()->prepare('SELECT name,department FROM employees WHERE id=?'); $nameQ->execute([$eid]); $me = $nameQ->fetch();
        if ($me && $me['department'] !== '') {
            $hq = db()->prepare('SELECT DISTINCT employee_id FROM project_dept_heads WHERE department=? AND employee_id<>?'); $hq->execute([$me['department'], $eid]);
            $heads = $hq->fetchAll(PDO::FETCH_COLUMN); $notified = array_map('intval', $heads);
            $types = array_values(array_unique(array_column($orders, 'project_type')));
            foreach (pbr_delegates_for($types, array_merge([$eid], $notified)) as $delegateId => $biz) pbr_message($delegateId, $me['name'] . ' 申请审核 ' . $month . ' 的订单', $me['name'] . ' 提交了 ' . count($orders) . ' 单的审核申请（涉及 ' . implode('、', $types) . '），你被授权审核这些业务，可以直接处理。', '/project/batch_review.php?id=' . $id, 'pbr-submit:' . $id . ':' . $delegateId);
            foreach ($heads as $headId) pd_message((int)$headId, $me['name'] . ' 申请审核 ' . $month . ' 的订单', $me['name'] . ' 提交了 ' . count($orders) . ' 单的审核申请，你作为部门主管可以抽查审核。', '/project/batch_review.php?month=' . $month . '&emp=' . $eid, 'batch_review_head:' . $id . ':' . (int)$headId . ':' . count($orders));
        }
    } catch (Throwable $e) { /* 通知失败不影响申请 */ }
    return $id;
}

/** 审核提醒范围：上月和上上月（整月）下单、还没审核通过的订单。当月订单还在进行中，不算待审。 */
function pbr_review_window()
{
    return [date('Y-m-01', strtotime('first day of -2 month')), date('Y-m-01')];
}

/** [from, to) 之间的各个月份（YYYY-MM）。 */
function pbr_months($from, $to)
{
    $out = [];
    for ($t = strtotime($from); $t < strtotime($to); $t = strtotime('+1 month', $t)) $out[] = date('Y-m', $t);
    return $out;
}

/** 当前人能审核的“没通过审核”订单数（财务 = 全部；授权审核人 / 部门主管 = 自己范围内、不含本人参与的）。会话里缓存 5 分钟，侧栏每页都会用。 */
function pbr_reviewable_count(array $actor)
{
    $scope = pbr_scope($actor);
    if (!$scope) return 0;
    $key = 'pbr_cnt_' . (int)($actor['id'] ?? 0) . '_' . ($actor['type'] ?? '');
    if (isset($_SESSION[$key]) && time() - $_SESSION[$key][0] < 300) return (int)$_SESSION[$key][1];
    try {
        [$from, $to] = pbr_review_window();
        $q = db()->prepare("SELECT DISTINCT o.id, o.project_type, o.order_kind, o.delivery_status, o.contract_amount, o.receipt_amount, o.refund_amount, COALESCE(s.trade_status,'') trade_status FROM project_orders o JOIN project_participants p ON p.order_id=o.id LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.order_date>=? AND o.order_date<? AND o.settlement_status NOT IN ('approved','locked')" . pbr_scope_sql($scope, $extra));
        $q->execute(array_merge([$from, $to], $extra));
        require_once __DIR__ . '/ProjectMiniappDelivery.php';
        $n = 0;
        foreach ($q->fetchAll() as $row) {
            if (!ps_deferred_order_unshipped($row, ['trade_status' => $row['trade_status']])) {
                $n++;
            }
        }
    } catch (Throwable $e) { $n = 0; }
    $_SESSION[$key] = [time(), $n];
    return $n;
}

/** 同业务的授权审核人（不含 $exceptIds）：提交申请 / 汇总提醒时通知他们。返回 [employee_id => [授权业务...]]。 */
function pbr_delegates_for(array $businesses, array $exceptIds = [])
{
    $want = array_map('ps_business_normalize', $businesses);
    $out = [];
    try {
        foreach (db()->query('SELECT employee_id,business_name FROM project_batch_review_delegates')->fetchAll() as $d) {
            if (in_array((int)$d['employee_id'], $exceptIds, true)) continue;
            if (in_array(ps_business_normalize((string)$d['business_name']), $want, true)) $out[(int)$d['employee_id']][] = $d['business_name'];
        }
    } catch (Throwable $e) { /* 授权表还没建 */ }
    return $out;
}

function pbr_message($employeeId, $title, $body, $link, $key)
{
    try {
        db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$employeeId, 'batch_review', mb_substr($title, 0, 160), $body, $link, $key]);
    } catch (Throwable $e) { /* 站内信异常不影响审核流程 */ }
}

function pbr_pending_count()
{
    try { pbr_ensure(); return (int)db()->query("SELECT COUNT(*) FROM project_batch_reviews WHERE status='pending'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/**
 * 财务已经没有可审核订单的申请自动结案：订单都已审核通过（经别的入口审核的），或剩下的都是待发货 / 未到账（不推给财务，发货后再申请）。
 * 否则申请会一直显示“待财务处理”，点进去却是空的。返回结案数量。
 */
function pbr_close_stale()
{
    try { pbr_ensure(); $rows = db()->query("SELECT id,employee_id,month FROM project_batch_reviews WHERE status='pending'")->fetchAll(); }
    catch (Throwable $e) { return 0; }
    $n = 0;
    foreach ($rows as $r) {
        if (pbr_orders((int)$r['employee_id'], $r['month'], ['all' => true])) continue;
        $left = count(pbr_orders((int)$r['employee_id'], $r['month']));
        $note = $left > 0 ? '可审核的订单已处理完；另有 ' . $left . ' 单待发货 / 未到账，发货后可重新申请' : '订单已全部审核通过';
        $u = db()->prepare("UPDATE project_batch_reviews SET status='done',handled_by='系统',handled_at=NOW(),handled_note=? WHERE id=? AND status='pending'");
        $u->execute([$note, (int)$r['id']]);
        $n += $u->rowCount();
    }
    return $n;
}

/** 有没通过审核订单的员工（某月），供财务直接选人审核。 */
function pbr_employees($month, $scope = null)
{
    $q = db()->prepare("SELECT e.id,e.name FROM project_orders o JOIN project_participants p ON p.order_id=o.id JOIN employees e ON e.id=p.employee_id
        WHERE o.order_date>=? AND o.order_date<? AND o.settlement_status NOT IN ('approved','locked')" . pbr_scope_sql($scope, $extra) . " GROUP BY e.id,e.name ORDER BY e.name");
    $q->execute(array_merge([$month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month'))], $extra));
    $list = $q->fetchAll();
    $out = [];
    foreach ($list as $em) {
        $orders = pbr_orders((int)$em['id'], $month, $scope);
        if ($orders) {
            $em['n'] = count($orders);
            $em['amount'] = array_sum(array_column($orders, 'contract_amount'));
            $em['exceptions'] = count(array_filter($orders, function ($o) { return !empty($o['exception']); }));
            $out[] = $em;
        }
    }
    return $out;
}

/** 财务：对所选订单登记收款（按售价）、标记交付完成、审核生成分成。返回 [通过数, [失败说明...]]。 */
function pbr_approve(array $actor, $requestId, array $orderIds, $employeeId = 0, $month = '')
{
    $scope = pbr_scope($actor);
    if (!$scope) throw new RuntimeException('只有财务或被授权人员可以审核');
    // 授权审核人员（如冯超）支持按申请或按员工月份审核，且自动按其业务范围 $scope 过滤
    $adminId = ($actor['type'] ?? '') === 'admin' ? (int)$actor['id'] : null; // 授权人员不是管理员账号，审核人记在审计里
    $who = !empty($scope['all']) ? '财务' : (empty($scope['businesses']) ? '部门主管' : '授权审核人员');
    pbr_ensure();
    if ((int)$requestId > 0) {
        $q = db()->prepare("SELECT * FROM project_batch_reviews WHERE id=? AND status='pending'"); $q->execute([(int)$requestId]); $req = $q->fetch();
        if (!$req) throw new RuntimeException('申请不存在或已处理');
    } else { // 财务直接按“员工 + 月份”审核，不需要对方先提交申请
        if ((int)$employeeId <= 0 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) throw new RuntimeException('请选择员工和月份');
        $req = ['id' => 0, 'employee_id' => (int)$employeeId, 'month' => (string)$month];
    }
    $allowed = array_column(pbr_orders((int)$req['employee_id'], $req['month'], $scope), null, 'id');
    $done = 0; $failed = [];
    foreach (array_slice(array_values(array_unique(array_map('intval', $orderIds))), 0, 300) as $oid) {
        if (!isset($allowed[$oid])) continue;
        $o = $allowed[$oid];
        try {
            $pdo = db(); $pdo->beginTransaction();
            $row = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE'); $row->execute([$oid]); $order = $row->fetch();
            if (!$order || in_array($order['settlement_status'], ['approved', 'locked'], true)) throw new RuntimeException('已审核');
            $net = (float)$order['receipt_amount'] - (float)$order['refund_amount'];
            // 没有售价的单（如微信代写“编辑对接”：不记销售额，只按每单固定补助结算）不需要登记收款；能不能审核由 ps_approve_order 按规则判断
            if ($net <= 0 && (float)$order['contract_amount'] > 0) {
                $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'receipt',?,?,'approved',?,?,?,NOW())")
                    ->execute([$oid, round((float)$order['contract_amount'], 2), $who . '批量审核：抽查后按售价确认收款（申请 #' . (int)$req['id'] . '）', $actor['type'], $actor['id'], $adminId]);
                ps_recalculate_cash($oid);
            }
            // 批量审核通过：网站业务必须交付已完成；其他业务若未完成则自动置为已完成
            if ($order['delivery_status'] !== 'finished') {
                if (ps_is_website_order($order['project_type'])) {
                    throw new RuntimeException('网站业务交付尚未完成，不能批量审核通过');
                }
                if (!ps_can_mark_delivery_finished($actor)) {
                    throw new RuntimeException('项目尚未完成：需由技术、客服、售后或管理层先标注为“已完成”');
                }
                $pdo->prepare("UPDATE project_orders SET delivery_status='finished',row_version=row_version+1 WHERE id=?")->execute([$oid]);
                $pdo->prepare("UPDATE project_order_requests SET status='approved', reviewer_id=?, reviewed_at=NOW(), review_note='批量审核时自动通过' WHERE order_id=? AND request_type='delivery_completion' AND status='pending'")->execute([$adminId, $oid]);
            }
            pbr_prepare_order($oid, $actor, $adminId, $who);
            ps_approve_order($oid, $actor, (string)$req['month']);
            ps_audit('order', $oid, 'batch_review_approve', $actor, ['request' => (int)$req['id'], 'receipt_added' => $net <= 0, 'delegate' => empty($scope['all'])]);
            $pdo->commit(); $done++;
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $failed[] = $o['order_no'] . '：' . ($e instanceof PDOException ? '保存失败' : $e->getMessage());
        }
    }
    if ((int)$req['id'] > 0 && !pbr_orders((int)$req['employee_id'], $req['month'], ['all' => true])) {
        db()->prepare("UPDATE project_batch_reviews SET status='done',handled_by=?,handled_at=NOW(),handled_note=? WHERE id=?")->execute([pbr_name($actor), '已全部审核通过', (int)$req['id']]);
    }
    ps_audit('batch_review', (int)$req['id'], 'approve', $actor, ['done' => $done, 'failed' => count($failed), 'employee_id' => (int)$req['employee_id'], 'month' => $req['month']]);
    try {
        require_once __DIR__ . '/dup_feedback.php';
        pd_message((int)$req['employee_id'], $req['month'] . ' 的订单审核有进展', '财务已审核通过 ' . $done . ' 单' . ($failed ? '，另有 ' . count($failed) . ' 单需要单独处理' : '') . '。', '/project/batch_review.php?month=' . $req['month'], 'batch_review:' . (int)$req['id'] . ':' . $done . ((int)$req['id'] ? '' : ':' . date('YmdHis')));
    } catch (Throwable $e) {}
    return [$done, $failed];
}

/** 财务批量作废当前员工、月份下未审核的订单；完整备份到回收站后移出业务列表。 */
function pbr_void(array $actor, $requestId, array $orderIds, $employeeId = 0, $month = '', $reason = '')
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅财务可以批量作废订单');
    $reason = trim((string)$reason);
    if (mb_strlen($reason) < 4 || mb_strlen($reason) > 500) throw new RuntimeException('请填写 4–500 字作废原因');
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn($id) => $id > 0)));
    if (!$ids) throw new RuntimeException('请先勾选需要作废的订单');
    if (count($ids) > 300) throw new RuntimeException('每次最多作废 300 单，请分批处理');
    pbr_ensure();
    if ((int)$requestId > 0) {
        $q = db()->prepare("SELECT * FROM project_batch_reviews WHERE id=? AND status='pending'");
        $q->execute([(int)$requestId]); $req = $q->fetch();
        if (!$req) throw new RuntimeException('申请不存在或已处理');
    } else {
        if ((int)$employeeId <= 0 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) throw new RuntimeException('请选择员工和月份');
        $req = ['id'=>0, 'employee_id'=>(int)$employeeId, 'month'=>(string)$month];
    }
    $allowed = array_column(pbr_orders((int)$req['employee_id'], $req['month']), null, 'id');
    require_once __DIR__ . '/ProjectOrderDelete.php';
    require_once __DIR__ . '/ProjectOrderTrash.php';
    pot_ensure();
    $from = $req['month'] . '-01'; $until = date('Y-m-d', strtotime($from . ' +1 month'));
    $done = 0; $failed = [];
    foreach ($ids as $oid) {
        if (!isset($allowed[$oid])) { $failed[] = '#' . $oid . '：不在当前员工、月份的待审核订单内'; continue; }
        try {
            $pdo = db(); $pdo->beginTransaction();
            $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE'); $q->execute([$oid]); $row = $q->fetch();
            if (!$row || in_array($row['settlement_status'], ['approved','locked'], true)) throw new RuntimeException('订单已审核、锁定或已不存在');
            $q = $pdo->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
            $q->execute([$oid,(int)$req['employee_id']]);
            if (!$q->fetchColumn() || $row['order_date'] < $from || $row['order_date'] >= $until) throw new RuntimeException('订单所属人员或月份已变化，请刷新');
            if ((float)$row['receipt_amount'] != 0 || (float)$row['refund_amount'] != 0) throw new RuntimeException('已有确认收退款，请在订单详情单独核对处理');
            $q = $pdo->prepare("SELECT 1 FROM project_cash_movements WHERE order_id=? AND review_status='approved' LIMIT 1"); $q->execute([$oid]);
            if ($q->fetchColumn()) throw new RuntimeException('已有确认收退款，请在订单详情单独核对处理');
            $q = $pdo->prepare('SELECT 1 FROM project_order_splits WHERE parent_order_id=? OR child_order_id=? LIMIT 1'); $q->execute([$oid,$oid]);
            if ($q->fetchColumn()) throw new RuntimeException('存在分单关联，请在订单详情单独处理');
            if (($blocked = pod_blocker($row, $actor)) !== '') throw new RuntimeException($blocked);
            $backup = pod_backup($oid, $row['order_no'], $actor);
            pod_delete_rows($oid);
            ps_audit('order',$oid,'batch_review_void',$actor,['request'=>(int)$req['id'],'order_no'=>$row['order_no'],'reason'=>$reason,'backup'=>$backup]);
            $pdo->commit(); $done++;
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $failed[] = $allowed[$oid]['order_no'] . '：' . ($e instanceof PDOException ? '保存失败，订单未作废' : $e->getMessage());
        }
    }
    if ((int)$req['id'] > 0 && !pbr_orders((int)$req['employee_id'], $req['month'], ['all' => true])) {
        db()->prepare("UPDATE project_batch_reviews SET status='done',handled_by=?,handled_at=NOW(),handled_note=? WHERE id=?")
            ->execute([pbr_name($actor),'待审核订单已处理，最后批次作废 ' . $done . ' 单',(int)$req['id']]);
    }
    ps_audit('batch_review',(int)$req['id'],'void',$actor,['done'=>$done,'failed'=>count($failed),'employee_id'=>(int)$req['employee_id'],'month'=>$req['month'],'reason'=>$reason]);
    return [$done,$failed];
}

function pbr_reject(array $actor, $requestId, $note)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('只有财务可以处理');
    pbr_ensure();
    $note = trim((string)$note);
    if (mb_strlen($note) < 3) throw new RuntimeException('请写明需要对方补充什么');
    $q = db()->prepare("SELECT * FROM project_batch_reviews WHERE id=? AND status='pending'"); $q->execute([(int)$requestId]); $req = $q->fetch();
    if (!$req) throw new RuntimeException('申请不存在或已处理');
    db()->prepare("UPDATE project_batch_reviews SET status='rejected',handled_by=?,handled_at=NOW(),handled_note=? WHERE id=?")->execute([pbr_name($actor), mb_substr($note, 0, 500), (int)$requestId]);
    ps_audit('batch_review', (int)$requestId, 'reject', $actor, ['note' => $note]);
    try { require_once __DIR__ . '/dup_feedback.php'; pd_message((int)$req['employee_id'], $req['month'] . ' 的订单审核申请被退回', '财务说明：' . $note, '/project/batch_review.php?month=' . $req['month'], 'batch_review_rej:' . (int)$requestId); } catch (Throwable $e) {}
}

function pbr_name(array $actor)
{
    return ($actor['type'] ?? '') === 'admin' ? (string)db()->query('SELECT username FROM admins WHERE id=' . (int)$actor['id'])->fetchColumn() : 'employee#' . (int)($actor['id'] ?? 0);
}
