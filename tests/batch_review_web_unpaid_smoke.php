<?php
// 财务批量审核列表：网站类订单没收款记录 / 没标完成也能出现（审核时登记收款、记为已完成）；待发货 / 交易关闭的不出现。测试数据结束后清理。
// DB_NAME=xinzi_test DB_HOST=127.0.0.1 DB_PORT=13399 php tests/batch_review_web_unpaid_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectBatchReview.php';
$pdo = db(); $made = [];
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
try {
    $emp = (int)$pdo->query('SELECT id FROM employees ORDER BY id LIMIT 1')->fetchColumn();
    $mk = function ($no, $status) use ($pdo, $emp, &$made) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','AI网站定制','AI定制','美呀美旗舰店',1000,0,'2027-04-05','finished','draft','测试')")->execute([$no]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO project_order_sources (order_id,payment_nickname,trade_status,price_source,nickname_source,status_source) VALUES (?,'测试',?,'manual','manual','shop_upload')")->execute([$id, $status]);
        $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','前端/后端',1)")->execute([$id, $emp]);
        $made[] = $id; return $id;
    };
    $ok1 = $mk('BWU-1', '交易成功'); $ok2 = $mk('BWU-2', ''); $no1 = $mk('BWU-3', '买家已付款,等待卖家发货'); $no2 = $mk('BWU-4', '交易关闭');
    $mini = function ($no, $status) use ($pdo, $emp, &$made) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','小程序开发','定制','微信',500,0,'2027-04-06','unfinished','draft','测试')")->execute([$no]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO project_order_sources (order_id,payment_nickname,trade_status,price_source,nickname_source,status_source) VALUES (?,'测试',?,'manual','manual','missing')")->execute([$id, $status]);
        $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','制作技术',1)")->execute([$id, $emp]);
        $made[] = $id; return $id;
    };
    $mOk = $mini('BWU-M1', ''); $mNo = $mini('BWU-M2', '买家已付款,等待卖家发货');
    $ids = array_column(pbr_orders($emp, '2027-04', ['all' => true]), 'id');
    $check(in_array($ok1, $ids) && in_array($ok2, $ids), '没收款、没标完成的网站单（交易成功 / 无店铺状态）出现在批量审核列表');
    $check(!in_array($no1, $ids) && !in_array($no2, $ids), '待发货 / 交易关闭的不出现');
    $check(in_array($mOk, $ids) && !in_array($mNo, $ids), '小程序定制：微信付款（无店铺状态）出现在批量审核列表，待发货的不出现');
    $finance = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'employee_id' => null, 'role' => 'finance'];
    [$done, $failed] = pbr_approve($finance, 0, [$ok1, $ok2, $mOk], $emp, '2027-04');
    $st = $pdo->query("SELECT settlement_status,delivery_status,receipt_amount FROM project_orders WHERE id=$ok1")->fetch();
    $check($done === 3 && !$failed && $st['settlement_status'] === 'approved' && $st['delivery_status'] === 'finished' && (float)$st['receipt_amount'] === 1000.0, '批量审核通过：自动登记收款 1000、交付记为已完成、订单已审核：' . implode('；', $failed));
    // 财务补的成本类别对不上：SSL 预期成本记在“成本”类 / 商品只剩一条成本没关联 —— 批量通过时自动归位
    $sslOrder = $mk('BWU-S1', '交易成功');
    $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode,ssl_expected_amount) VALUES (?,'manual','none',60)")->execute([$sslOrder]);
    $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status) VALUES (?,'outsourcing','成本',1,'项',60,60,'one_time',1,'补成本','approved')")->execute([$sslOrder]);
    $itemOrder = $mk('BWU-I1', '交易成功');
    $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status) VALUES (?,'outsourcing','成本',1,'项',170,170,'one_time',1,'补成本','approved')")->execute([$itemOrder]);
    $pdo->prepare("INSERT INTO project_order_items (order_id,item_key,item_name,category,sale_amount,resource_hint,reported_cost) VALUES (?,?,'1年补差价','program',1000,'',170)")->execute([$itemOrder, 'bwu' . $itemOrder]);
    [$d2, $f2] = pbr_approve($finance, 0, [$sslOrder, $itemOrder], $emp, '2027-04');
    $check($d2 === 2 && !$f2, 'SSL 成本记错类别 / 商品成本没关联：批量审核自动归位后通过：' . implode('；', $f2));
    $check($pdo->query("SELECT category FROM project_costs WHERE order_id=$sslOrder")->fetchColumn() === 'certificate', 'SSL 成本改记“证书”类别');
    // 没有可审核订单的申请自动结案（点进去是空的那种）
    $pdo->prepare("INSERT INTO project_batch_reviews (employee_id,month,note,order_count,amount,status) VALUES (?,?,'测试',3,2000,'pending')")->execute([$emp, '2027-04']); $reqId = (int)$pdo->lastInsertId();
    $no3 = $mk('BWU-5', '买家已付款,等待卖家发货'); // 只剩待发货的一单
    $closed = pbr_close_stale();
    $row = $pdo->query("SELECT status,handled_note FROM project_batch_reviews WHERE id=$reqId")->fetch();
    $check($closed >= 1 && $row['status'] === 'done' && strpos($row['handled_note'], '待发货') !== false, '只剩待发货订单的申请自动结案并写明原因：' . $row['handled_note']);
    $pdo->exec("DELETE FROM project_batch_reviews WHERE id=$reqId");
    // 网站业务交付没完成：不计提成，即使到账也不进批量审核；技术标完成后才出现
    $unf = $mk('BWU-U1', '交易成功'); $pdo->exec("UPDATE project_orders SET delivery_status='unfinished',receipt_amount=1000 WHERE id=$unf");
    $idsU = array_column(pbr_orders($emp, '2027-04', ['all' => true]), 'id');
    $check(!in_array($unf, $idsU), '网站订单交付没完成（已到账）：不进批量审核列表');
    $pdo->exec("UPDATE project_orders SET delivery_status='finished' WHERE id=$unf");
    $check(in_array($unf, array_column(pbr_orders($emp, '2027-04', ['all' => true]), 'id')), '技术标已完成后：出现在批量审核列表');
    $pdo->exec("UPDATE project_orders SET settlement_status='locked' WHERE id=$unf");
    // 资源待确认：加购 / 证书附加行系统自动确认“无需另计”；定制主订单批量审核列表里提醒域名成本
    $mkc = function ($no, $note) use ($pdo, $emp, &$made) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','AI网站定制','博山定制','美呀美旗舰店',900,0,'2027-04-07','finished','draft',?)")->execute([$no, $note]);
        $id = (int)$pdo->lastInsertId(); $made[] = $id;
        $pdo->prepare("INSERT INTO project_order_sources (order_id,payment_nickname,trade_status,price_source,nickname_source,status_source) VALUES (?,'测试','交易成功','manual','manual','shop_upload')")->execute([$id]);
        $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','pending')")->execute([$id]);
        $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','前端/后端',1)")->execute([$id, $emp]);
        return $id;
    };
    $main = $mkc('BWU-R1', '付款昵称：测试'); $addonId = $mkc('BWU-R2', '付款昵称：测试；业务说明：ssl证书；客户联系方式：ssl证书');
    $why = []; foreach (pbr_orders($emp, '2027-04', ['all' => true]) as $r) $why[(int)$r['id']] = implode('；', $r['why']);
    $check(strpos($why[$main] ?? '', '域名 / 空间还没确认') !== false && strpos($why[$addonId] ?? '', '域名 / 空间还没确认') === false, '定制主订单提醒域名成本，SSL 附加行不提醒');
    // 同一件商品被导入成两条（补差价 / 1年补差价）、只有一条成本：批量通过时去掉重复明细并关联成本
    $dupOrder = $mk('BWU-D1', '交易成功');
    $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','none')")->execute([$dupOrder]);
    $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status) VALUES (?,'outsourcing','成本',1,'项',230,230,'one_time',1,'补成本','approved')")->execute([$dupOrder]);
    foreach (['补差价', '1年补差价'] as $di => $dn) $pdo->prepare("INSERT INTO project_order_items (order_id,item_key,item_name,category,sale_amount,resource_hint,reported_cost) VALUES (?,?,?,'other',1000,'',230)")->execute([$dupOrder, 'dup' . $dupOrder . $di, $dn]);
    [$dd, $fd] = pbr_approve($finance, 0, [$dupOrder], $emp, '2027-04');
    $check($dd === 1 && !$fd && (int)$pdo->query("SELECT COUNT(*) FROM project_order_items WHERE order_id=$dupOrder")->fetchColumn() === 1, '重复的商品明细（补差价 / 1年补差价）：去重并关联成本后通过：' . implode('；', $fd));
    // 多网站付款号：站点售价合计与店铺流水实付对不上时提示（默认不勾选）
    $extNo = 'BWU-SITE' . bin2hex(random_bytes(3));
    $site1 = $mk($extNo, '交易成功'); $site2 = $mk($extNo . '~s2', '交易成功');
    foreach ([[$site1, 'auto-1'], [$site2, 'auto-2']] as [$sid, $sk]) $pdo->prepare('INSERT INTO project_site_projects (order_id,root_order_id,external_order_no,site_key,created_by_type,created_by_id) VALUES (?,?,?,?,?,?)')->execute([$sid, $site1, $extNo, $sk, 'admin', 1]);
    $pdo->prepare("INSERT INTO orders (employee_id,order_amount,order_date,shop,order_no,raw_data,is_abnormal,abnormal_reason,order_scope) VALUES (0,1000,'2027-04-05','美呀美旗舰店',?,?,0,'','department')")->execute([$extNo, json_encode(['买家实际支付金额' => '1000'])]); $flowTest = (int)$pdo->lastInsertId();
    $got = []; foreach (pbr_orders($emp, '2027-04', ['all' => true]) as $r) if (in_array((int)$r['id'], [$site1, $site2], true)) $got[] = implode('；', $r['why']) . ($r['exception'] ? '[异常]' : '');
    $check(count($got) === 2 && strpos($got[0], '对不上') !== false && strpos($got[0], '[异常]') !== false, '两个网站售价合计 2000，店铺实付 1000：提示并默认不勾选');
    $pdo->exec("DELETE FROM orders WHERE id=$flowTest"); $pdo->exec("DELETE FROM project_site_projects WHERE external_order_no='$extNo'");
    // 往月已核算：订单之后才审核，分成自动记入最早未核算的月份
    $prevSettled = ps_setting_get('refund_settled_through', ''); ps_setting_set('refund_settled_through', '2027-03', 1);
    try {
        $late = $mk('BWU-L1', '交易成功'); $pdo->exec("UPDATE project_orders SET order_date='2027-03-05' WHERE id=$late");
        [$dl, $fl] = pbr_approve($finance, 0, [$late], $emp, '2027-03');
        $pm = $pdo->query("SELECT payroll_month FROM project_commission_snapshots WHERE order_id=$late LIMIT 1")->fetchColumn();
        $check($dl === 1 && $pm === '2027-04', '3 月已核算：3 月订单现在审核，分成自动记入 4 月（实际 ' . var_export($pm, true) . '）：' . implode('；', $fl));
    } finally { ps_setting_set('refund_settled_through', (string)$prevSettled, 1); }
    echo "全部通过\n";
} finally {
    foreach ($made as $id) {
        foreach (['project_commission_snapshots', 'project_cash_movements', 'project_costs', 'project_order_resources', 'project_order_sources', 'project_participants', 'project_auto_reviews', 'project_order_items', 'project_order_details'] as $t) { try { $pdo->exec("DELETE FROM $t WHERE order_id=$id"); } catch (Throwable $e) {} }
        $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id=$id"); $pdo->exec("DELETE FROM project_orders WHERE id=$id");
    }
}
