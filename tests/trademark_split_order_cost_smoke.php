<?php
// 同一件商标被拆成多张订单（如 400 + 100）时，官费只计一次。只允许本地隔离库。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
require_once __DIR__ . '/../includes/ProjectCostDisplay.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; };
$actor = ['type' => 'system', 'id' => 0, 'role' => 'finance'];
$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE project_cost_templates SET is_active=0 WHERE business_scope='商标'");
    $pdo->prepare("INSERT INTO project_cost_templates (category,business_scope,name,specification,unit,price_mode,cost_kind,price,requires_proof,auto_approve,version) VALUES ('other','商标','商标续展','测试标准','件','fixed','one_time',450,0,1,1)")->execute();
    $makeOrder = function ($customer, $amount, $date, $name, $service = '续展') use ($pdo) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status) VALUES (?,?,'商标','普通订单','测试',?,?,?,'finished')")->execute(['TM-SPLIT-' . bin2hex(random_bytes(5)), $customer, $amount, $amount, $date]);
        $id = (int)$pdo->lastInsertId();
        ps_save_business_details($id, '商标', ['service_type' => $service, 'trademark_name' => $name, 'trademark_count' => '1']);
        return $id;
    };
    $live = function ($id) { return array_values(array_filter(ps_costs($id), function ($c) { return $c['review_status'] !== 'rejected'; })); };
    $name = '17660613 农耕味道 31类';

    // 1) 400 主单先同步，100 同客户单后同步：官费只在 400 上。
    $big = $makeOrder('split-a', 400, '2026-09-15', $name);
    $small = $makeOrder('split-a（微信群聊）', 100, '2026-09-15', $name);
    $check(ptc_sync_order_cost($big, $actor, '测试') === 'added' && count($live($big)) === 1 && (float)$live($big)[0]['amount'] === 450.0, '主单带入官费 450');
    $check(ptc_sync_order_cost($small, $actor, '测试') === 'ok' && !$live($small), '同客户拆单不重复带入官费');
    $check(ptc_cost_carried_by($small) === $big && ptc_cost_carried_by($big) === 0, '识别承担订单');
    ptc_approval_guard($small, $live($small));
    $d = ps_cost_display_state(['id' => $small, 'project_type' => '商标', 'settlement_status' => 'draft'], $live($small));
    $check($d['show_amount'] && !$d['block_estimate'] && $d['label'] === '同商标已计', '不重复计成本的订单不被拦截、显示说明');
    $check(ptc_sync_order_cost($small, $actor, '重复上传') === 'ok' && !$live($small), '重复上传仍不带入');

    // 2) 先有两张都带了成本的历史重复，承担订单重新同步后另一张的自动成本作废。
    $a = $makeOrder('split-b', 100, '2026-09-16', $name);
    $b = $makeOrder('split-b', 400, '2026-09-16', $name);
    $pdo->prepare('UPDATE project_orders SET order_kind=order_kind WHERE id=?')->execute([$a]);
    foreach ([$a, $b] as $id) ps_intake_add_template_cost($id, ptc_find_service(ptc_templates(), '续展'), $actor, '成本修复：续展 × 1 件', 1, 'approved');
    $check(count($live($a)) === 1 && count($live($b)) === 1, '构造历史重复：两张都带了 450');
    $check(in_array(ptc_sync_order_cost($b, $actor, '成本修复'), ['ok', 'corrected'], true) && count($live($b)) === 1 && !$live($a), '承担订单同步后，另一张的重复成本作废');
    $rej = $pdo->prepare("SELECT review_note FROM project_costs WHERE order_id=? AND review_status='rejected'"); $rej->execute([$a]);
    $check(strpos((string)$rej->fetchColumn(), '#' . $b) !== false, '作废原因写明承担订单');

    // 3) 不该合并的情形各自保留成本。
    $d1 = $makeOrder('split-c', 400, '2026-09-17', $name);
    $d2 = $makeOrder('split-c', 400, '2026-09-18', $name);
    ptc_sync_order_cost($d1, $actor, '测试'); ptc_sync_order_cost($d2, $actor, '测试');
    $check(count($live($d1)) === 1 && count($live($d2)) === 1, '不同日期的订单各自计官费');
    $n1 = $makeOrder('split-d', 400, '2026-09-19', '农耕味道 31类');
    $n2 = $makeOrder('split-d', 400, '2026-09-19', '农耕味道 31类');
    ptc_sync_order_cost($n1, $actor, '测试'); ptc_sync_order_cost($n2, $actor, '测试');
    $check(count($live($n1)) === 1 && count($live($n2)) === 1, '商标名没有注册号无法确认同一商标，不合并');
    $o1 = $makeOrder('split-e', 400, '2026-09-20', $name);
    $o2 = $makeOrder('split-f', 400, '2026-09-20', $name);
    ptc_sync_order_cost($o1, $actor, '测试'); ptc_sync_order_cost($o2, $actor, '测试');
    $check(count($live($o1)) === 1 && count($live($o2)) === 1, '不同客户的订单各自计官费');

    // 4) 已审核结算的订单优先作为承担订单，不被改写。
    $f1 = $makeOrder('split-g', 100, '2026-09-21', $name);
    $f2 = $makeOrder('split-g', 400, '2026-09-21', $name);
    ps_intake_add_template_cost($f1, ptc_find_service(ptc_templates(), '续展'), $actor, '成本修复：续展 × 1 件', 1, 'approved'); // 已结算的旧订单承担了官费
    $pdo->prepare("UPDATE project_orders SET settlement_status='approved' WHERE id=?")->execute([$f1]);
    $check(ptc_sync_order_cost($f2, $actor, '测试') === 'ok' && !$live($f2) && count($live($f1)) === 1, '已审核订单已承担官费，另一张不再带入');

    // 5) 补带成本（ptc_apply）同样遵守。
    $g1 = $makeOrder('split-h', 400, '2026-09-22', $name);
    $g2 = $makeOrder('split-h', 100, '2026-09-22', $name);
    ptc_sync_order_cost($g1, $actor, '测试');
    $check(ptc_apply($g2, '1', $actor, '补带', '续展') === 0 && !$live($g2), '补带成本也不重复');
    // 6) 财务核对页：系统无法确定的疑似重复交财务一键确认。
    require_once __DIR__ . '/../includes/ProjectTrademarkDupCost.php';
    $mine = function ($prefix) { return array_values(array_filter(ptd_candidates(), function ($g) use ($prefix) { return strpos($g['customer'], $prefix) === 0; })); };
    $h1 = $makeOrder('tmc-same', 350, '2026-09-22', '寒洲丰物朔风本味29类'); $h2 = $makeOrder('tmc-same', 350, '2026-09-22', '寒洲丰物朔风本味29类');
    ptc_sync_order_cost($h1, $actor, '测试'); ptc_sync_order_cost($h2, $actor, '测试');
    $c = $mine('tmc-same');
    $check(count($c) === 1 && count($c[0]['orders']) === 2 && $c[0]['dupe'] === 450.0 && in_array('同一天下单（2026-09-22）', $c[0]['same'], true), '无注册号的同天同商标名列入财务核对，并写明相同点和多计金额');
    $pdo->prepare('UPDATE project_orders SET contract_amount=351 WHERE id=?')->execute([$h2]);
    $failed = false;
    try { ptd_decide($c[0]['key'], 'same', $actor); } catch (RuntimeException $e) { $failed = true; }
    $check($failed && count($live($h1)) === 1 && count($live($h2)) === 1, '页面打开后金额变化，旧确认不能修改成本');
    $c = $mine('tmc-same');
    $before = count(ptd_candidates());
    $msg = ptd_decide($c[0]['key'], 'same', $actor);
    $check(strpos($msg, '已合并') !== false && count($live($h1)) + count($live($h2)) === 1, '财务确认同一件后官费只计一次');
    $check(!$mine('tmc-same') && count(ptd_candidates()) === $before - 1, '处理后从核对列表消失、待办数减一');
    $check(ptc_cost_carried_by($h1) !== ptc_cost_carried_by($h2), '确认后重复同步不会把成本加回');
    ptc_sync_order_cost($h1, $actor, '重复上传'); ptc_sync_order_cost($h2, $actor, '重复上传');
    $check(count($live($h1)) + count($live($h2)) === 1, '确认后再次同步仍只计一次（跨日期也认确认结果）');
    $s1 = $makeOrder('tmc-sep', 350, '2026-09-05', $name); $s2 = $makeOrder('tmc-sep', 350, '2026-09-10', $name);
    ptc_sync_order_cost($s1, $actor, '测试'); ptc_sync_order_cost($s2, $actor, '测试');
    $c = $mine('tmc-sep');
    $check(count($c) === 1 && in_array('下单日期不同（2026-09-05、2026-09-10）', $c[0]['diff'], true), '同商标不同日期列入核对并标出不同点');
    ptd_decide($c[0]['key'], 'separate', $actor);
    $check(!$mine('tmc-sep') && count($live($s1)) === 1 && count($live($s2)) === 1, '确认各自独立：成本不动、不再提醒');
    $pdo->prepare('UPDATE project_orders SET order_date=? WHERE id=?')->execute(['2026-09-05', $s2]);
    ptc_sync_order_cost($s1, $actor, '重复上传'); ptc_sync_order_cost($s2, $actor, '重复上传');
    $check(count($live($s1)) === 1 && count($live($s2)) === 1 && !ptc_cost_carried_by($s1) && !ptc_cost_carried_by($s2), '确认独立后即使日期改为相同，重复同步仍各自保留成本');
    $k1 = $makeOrder('tmc-frozen', 350, '2026-09-23', '寒洲丰物朔风本味29类'); $k2 = $makeOrder('tmc-frozen', 350, '2026-09-23', '寒洲丰物朔风本味29类');
    ptc_sync_order_cost($k1, $actor, '测试'); ptc_sync_order_cost($k2, $actor, '测试');
    $pdo->prepare("UPDATE project_orders SET settlement_status='approved' WHERE id IN (?,?)")->execute([$k1, $k2]);
    $c = $mine('tmc-frozen'); $failed = false;
    try { ptd_decide($c[0]['key'], 'same', $actor); } catch (RuntimeException $e) { $failed = true; }
    $check($c[0]['blocked'] && $failed && count($live($k1)) === 1 && count($live($k2)) === 1, '含已审核订单时不能自动合并，成本保持不变');
    echo "ALL PASS\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
