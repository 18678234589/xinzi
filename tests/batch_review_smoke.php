<?php
// 订单审核申请：合作人员提交 → 财务抽查后一键通过（登记收款 + 交付完成 + 审核生成分成）；异常订单默认不会被带上；非财务不能审核。
// 会写入测试数据，只允许专用测试库：DB_NAME=xinzi_test DB_HOST=127.0.0.1 DB_PORT=13399 php tests/batch_review_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/require_isolated_database.php';
require_once __DIR__ . '/../includes/ProjectBatchReview.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); require_isolated_test_database($pdo); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$emp = (int)$pdo->query("SELECT id FROM employees WHERE name='阎泸琪'")->fetchColumn();
$user = $pdo->query("SELECT id FROM project_users WHERE employee_id=$emp LIMIT 1")->fetchColumn();
$mine = ['type' => 'employee', 'id' => (int)$user, 'employee_id' => $emp, 'role' => 'technical'];
$finance = ['type' => 'admin', 'id' => 1, 'role' => 'finance', 'employee_id' => 0];
$ids = []; $month = '2026-09';
$mk = function ($suffix, $amount) use ($pdo, $tag, $emp, &$ids) {
    $no = "BR$tag$suffix";
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','平面设计','新订单','内部',?,'2026-09-12','unfinished','draft','测试')")->execute([$no, $amount]);
    $id = (int)$pdo->lastInsertId(); $ids[] = $id;
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','设计',1)")->execute([$id, $emp]);
    return $id;
};
try {
    $check($emp > 0 && $user, '阎泸琪账号存在');
    $a = $mk('A', 100); $b = $mk('B', 60); $c = $mk('C', 30);
    $rows = pbr_orders($emp, $month);
    $mineRows = array_values(array_filter($rows, function ($r) use ($ids) { return in_array((int)$r['id'], $ids, true); }));
    $check(count($mineRows) === 3 && in_array('还没有确认的收款', $mineRows[0]['why'], true) && in_array('还没确认业务已完成', $mineRows[0]['why'], true), '申请人看得到 3 单，并写明卡在“没确认收款 / 没确认交付”');
    $rid = pbr_submit($mine, $month, '都是已到账的');
    $check($rid > 0 && pbr_pending_count() >= 1, '提交申请成功，财务待办 +1');
    $check(pbr_submit($mine, $month, '再提交一次') === $rid, '同月重复提交只更新同一条申请');
    $threw = false; try { pbr_approve($mine, $rid, [$a]); } catch (RuntimeException $e) { $threw = true; }
    $check($threw, '合作人员自己不能审核');
    [$done, $failed] = pbr_approve($finance, $rid, [$a, $b]);
    $st = function ($id) use ($pdo) { return $pdo->query("SELECT settlement_status,delivery_status,receipt_amount FROM project_orders WHERE id=$id")->fetch(); };
    $check($done === 2 && !$failed, '财务勾选 2 单一键通过：' . $done . ' ' . implode('；', $failed));
    $sa = $st($a); $sc = $st($c);
    $check($sa['settlement_status'] === 'approved' && $sa['delivery_status'] === 'finished' && (float)$sa['receipt_amount'] === 100.0, '通过的订单：已登记收款 100、交付完成、已审核');
    $check($sc['settlement_status'] === 'draft' && (float)$sc['receipt_amount'] === 0.0, '没勾选的订单保持原样');
    $check((int)$pdo->query("SELECT status='pending' FROM project_batch_reviews WHERE id=$rid")->fetchColumn() === 1, '还有没通过的订单：申请仍是待处理');
    [$done2] = pbr_approve($finance, $rid, [$c]);
    $left = count(pbr_orders($emp, $month));
    $check($done2 === 1 && ($left > 0 || $pdo->query("SELECT status FROM project_batch_reviews WHERE id=$rid")->fetchColumn() === 'done'), '剩下的也通过；该月没有未审核订单时申请自动变为已处理（该月还剩 ' . $left . ' 单）');
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id=$a")->fetchColumn() === 1, '审核生成了分成快照');
    $d = $mk('D', 20);
    $check(in_array($emp, array_map('intval', array_column(pbr_employees($month), 'id')), true), '财务选人列表里有这位员工');
    $threw = false; try { pbr_approve($finance, 0, [$d]); } catch (RuntimeException $e) { $threw = true; }
    $check($threw, '直接审核必须选员工和月份');
    $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status) VALUES (?,'outsourcing','外包',1,'项',5,5,'one_time',1,'测试','pending')")->execute([$d]);
    [$done3, $failed3] = pbr_approve($finance, 0, [$d], $emp, $month);
    $check($done3 === 1 && !$failed3 && $st($d)['settlement_status'] === 'approved', '财务不用申请，直接选员工 + 月份审核通过');
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_costs WHERE order_id=$d AND review_status='approved'")->fetchColumn() === 1, '批量审核时待审核成本一并通过');
    // 已到账的网站模板单：资源未确认、成本待审、交付未完成，批量审核前自动准备后一次通过
    $tag2 = 'BRW' . $tag;
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','网站模板','新订单','内部',300,300,'2026-09-12','unfinished','draft','测试')")->execute([$tag2]);
    $w = (int)$pdo->lastInsertId(); $ids[] = $w;
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','模板技术',1)")->execute([$w, $emp]);
    $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','pending')")->execute([$w]);
    $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status) VALUES (?,'outsourcing','外包',1,'项',5,5,'one_time',1,'测试','pending')")->execute([$w]);
    $pdo->beginTransaction();
    try { $pdo->prepare("UPDATE project_orders SET delivery_status='finished' WHERE id=?")->execute([$w]); pbr_prepare_order($w, $finance, 1, '财务'); ps_approve_order($w, $finance, $month); $pdo->commit(); $okW = true; } catch (Throwable $ex) { $pdo->rollBack(); $okW = $ex->getMessage(); }
    $check($okW === true && $st($w)['settlement_status'] === 'approved', '资源未确认+成本待审的已到账网站模板单，准备后可审核通过：' . (is_string($okW) ? $okW : 'ok'));
    $check($pdo->query("SELECT domain_mode FROM project_order_resources WHERE order_id=$w")->fetchColumn() === 'none', '资源按“无需另计”确认');
    // 商品明细没关联成本、但订单上已有未占用的同类已审核成本：财务批量通过时自动关联后审核
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','网站模板','新订单','内部',300,300,'2026-09-15','finished','draft','测试')")->execute(['BRI' . $tag]);
    $it = (int)$pdo->lastInsertId(); $ids[] = $it;
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','模板技术',1)")->execute([$it, $emp]);
    $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','none')")->execute([$it]);
    $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status) VALUES (?,'program','JSP展示中级版 · 1年',1,'项',100,100,'one_time',1,'测试','approved')")->execute([$it]);
    $pdo->prepare("INSERT INTO project_order_items (order_id,item_key,item_name,category,sale_amount,resource_hint) VALUES (?,?,?,'program',300,'')")->execute([$it, 'k' . $tag, 'JSP展示中级版']);
    [$dI, $fI] = pbr_approve($finance, 0, [$it], $emp, $month);
    $check($dI === 1 && !$fI, '商品成本没关联但已有同类已审核成本：批量审核自动关联后通过：' . implode('；', $fI));

    // 重复导入的商品明细（同名同价各 2 份、只有 1 条成本）+ 没有实收：订单列表批量审核的准备动作后可通过
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','网站模板','新订单','内部',260,0,'2026-09-16','finished','draft','测试')")->execute(['BRD' . $tag]);
    $dp = (int)$pdo->lastInsertId(); $ids[] = $dp;
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','模板技术',1)")->execute([$dp, $emp]);
    $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','none')")->execute([$dp]);
    $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status) VALUES (?,'program','PHP · 1年 空间+域名',1,'项',170,170,'one_time',1,'测试','approved')")->execute([$dp]);
    foreach ([1, 2] as $n) $pdo->prepare("INSERT INTO project_order_items (order_id,item_key,item_name,category,sale_amount,resource_hint) VALUES (?,?,'PHP','program',260,'')")->execute([$dp, 'd' . $n . $tag]);
    $pdo->beginTransaction();
    try { pbr_register_receipt($dp, $finance, 1, '财务'); pbr_prepare_order($dp, $finance, 1, '财务'); ps_approve_order($dp, $finance, $month); $pdo->commit(); $okD = true; } catch (Throwable $ex) { $pdo->rollBack(); $okD = $ex->getMessage(); }
    $check($okD === true && $st($dp)['settlement_status'] === 'approved', '没有实收 + 重复商品明细：登记收款、去掉重复明细后可审核通过：' . (is_string($okD) ? $okD : 'ok'));
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_order_items WHERE order_id=$dp")->fetchColumn() === 1 && (int)$pdo->query("SELECT COUNT(*) FROM project_costs WHERE order_id=$dp")->fetchColumn() === 1, '只删重复的明细行，成本记录原样保留');
    // 订单列表批量审核：勾选里已审核的订单跳过，不再报“当前订单不可审核”
    $bulkSrc = file_get_contents(__DIR__ . '/../project/index/actions/bulk.php');
    $check(strpos($bulkSrc, '$alreadyApproved++') !== false && strpos($bulkSrc, "'already' => $" . 'alreadyApproved') !== false, '批量审核对已审核订单计入“已跳过”，不当作失败');
    // 同一付款号的两个网站项目：财务批量通过时自动按售价登记收款并记共用付款分配核对
    require_once __DIR__ . '/../includes/ProjectSiteProjects.php';
    $site = [];
    foreach ([['SG' . $tag . 'A', 1240], ['SG' . $tag . 'B', 1000]] as [$no, $amt]) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','网站模板','新订单','内部',?,0,'2026-09-14','finished','draft','测试')")->execute([$no, $amt]);
        $sid = (int)$pdo->lastInsertId(); $ids[] = $sid; $site[] = $sid;
        $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','模板技术',1)")->execute([$sid, $emp]);
        $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','none')")->execute([$sid]);
    }
    psp_register($site[0], $site[0], 'SG' . $tag . 'A', 'site-a', $finance); psp_register($site[1], $site[0], 'SG' . $tag . 'A', 'site-b', $finance);
    [$dS, $fS] = pbr_approve($finance, 0, [$site[0]], $emp, $month);
    $check($dS === 1 && !$fS && $st($site[0])['settlement_status'] === 'approved', '同一付款号的网站项目：财务批量通过不再被“分配未核对”拦住：' . implode('；', $fS));
    $check((float)$st($site[1])['receipt_amount'] === 1000.0 && (int)$pdo->query('SELECT COUNT(*) FROM project_site_verifications WHERE root_order_id=' . $site[0])->fetchColumn() === 1, '同组另一个项目按售价登记收款，并记下分配核对');
    [$dS2, $fS2] = pbr_approve($finance, 0, [$site[1]], $emp, $month);
    $check($dS2 === 1 && !$fS2, '同组第二个项目也能通过：' . implode('；', $fS2));
    // 开发类业务：技术没标“已完成”的订单不能由审核人代标，批量审核跳过并说明
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','网站模板','新订单','内部',300,300,'2026-09-13','unfinished','draft','测试')")->execute(['BRU' . $tag]);
    $u = (int)$pdo->lastInsertId(); $ids[] = $u;
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','模板技术',1)")->execute([$u, $emp]);
    [$dU, $fU] = pbr_approve($finance, 0, [$u], $emp, $month);
    $check($dU === 1 && !$fU && $st($u)['delivery_status'] === 'finished', '财务批量审核：开发类未完成订单按现行规则由财务标为已完成后通过（非财务 / 授权人员无此权限时会提示先由技术标已完成）');
    // 授权审核人员（如网站人事）：只能审指定业务，不含本人参与的订单，不能处理申请、不是管理员账号
    $pdo->exec('CREATE TABLE IF NOT EXISTS project_batch_review_delegates (employee_id INT NOT NULL, business_name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (employee_id, business_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute(['br_delegate_' . $tag, '网站人事', bin2hex(random_bytes(8))]);
    $dele = (int)$pdo->lastInsertId();
    $deleActor = ['type' => 'employee', 'id' => 999999, 'employee_id' => $dele, 'role' => 'governance'];
    $check(pbr_scope($deleActor) === null && pbr_scope($mine) === null, '没授权的人没有审核资格');
    $pdo->prepare('INSERT INTO project_batch_review_delegates (employee_id,business_name) VALUES (?,?)')->execute([$dele, '设计']);
    $e = $mk('E', 40); $f = $mk('F', 50);
    $check(count(pbr_orders($emp, $month, pbr_scope($deleActor))) === 0, '授权业务不含这些订单时看不到');
    $pdo->prepare('INSERT INTO project_batch_review_delegates (employee_id,business_name) VALUES (?,?)')->execute([$dele, '平面设计']);
    $sc = pbr_scope($deleActor);
    $check(count(pbr_orders($emp, $month, $sc)) >= 2 && in_array($emp, array_map('intval', array_column(pbr_employees($month, $sc), 'id')), true), '授权业务内的订单和员工可见');
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','设计',1)")->execute([$f, $dele]);
    $check(!in_array($f, array_map('intval', array_column(pbr_orders($emp, $month, pbr_scope($deleActor)), 'id')), true), '本人参与的订单不能自己审');
    [$d4, $fl4] = pbr_approve($deleActor, 0, [$e, $f], $emp, $month);
    $check($d4 === 1 && $st($e)['settlement_status'] === 'approved' && $st($f)['settlement_status'] === 'draft', '授权人员直接审核：能过 1 单，本人参与那单被拒绝');
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_cash_movements WHERE order_id=$e AND reviewed_by_admin IS NULL AND note LIKE '授权审核人员%'")->fetchColumn() === 1, '收款记录不冒用管理员 ID，注明授权审核人员');
    $threw = false; try { pbr_reject($deleActor, $rid, '不通过的原因'); } catch (RuntimeException $ex) { $threw = true; }
    $check($threw, '授权人员不能退回申请');
    $pdo->exec("DELETE FROM project_participants WHERE employee_id=$dele"); $pdo->exec("DELETE FROM project_batch_review_delegates WHERE employee_id=$dele"); $pdo->exec("DELETE FROM employees WHERE id=$dele");
    echo "全部通过\n";
} finally {
    require_once __DIR__ . '/../includes/ProjectOrderTrash.php';
    foreach ($ids as $id) { foreach (POT_TABLES as $t) { try { $pdo->exec("DELETE FROM $t WHERE order_id=$id"); } catch (Throwable $e) {} } try { $pdo->exec("DELETE FROM project_auto_reviews WHERE order_id=$id"); } catch (Throwable $e) {} $pdo->exec("DELETE FROM project_orders WHERE id=$id"); }
    $pdo->exec("DELETE FROM project_batch_reviews WHERE employee_id=$emp AND month='$month' AND note LIKE '%已到账%' OR note='再提交一次'");
}
