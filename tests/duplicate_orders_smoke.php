<?php
// 重复订单：同一订单号只是写法不同（交易号：/ 订单编号：前缀）→ 财务页识别、建议保留、一键删除进回收站。只允许本地隔离库。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectDuplicateOrders.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; };
pot_ensure(); // DDL 放在事务之前
$finance = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'role' => 'finance'];
$pdo->beginTransaction();
try {
    $emp = [];
    foreach (['A', 'B', 'C'] as $n) { $pdo->prepare("INSERT INTO employees (name,department,password) VALUES (?,'商标部','x')")->execute(['重复测试' . $n]); $emp[$n] = (int)$pdo->lastInsertId(); }
    $make = function ($no, $customer, $amount, $date, array $who) use ($pdo, $emp) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status) VALUES (?,?,'商标','普通订单','测试',?,?,?,'finished')")->execute([$no, $customer, $amount, $amount, $date]);
        $id = (int)$pdo->lastInsertId();
        foreach ($who as $w) $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','提交专员',0.5)")->execute([$id, $emp[$w]]);
        return $id;
    };
    $mine = function ($tag) { return array_values(array_filter(pdo_duplicate_groups(), function ($g) use ($tag) { return strpos($g['keep']['order_no'], $tag) !== false || strpos($g['dups'][0]['order_no'], $tag) !== false; })); };

    // 1) 两个写法不同的同号订单：带前缀、无昵称、参与人少的那张是多余的
    $keep = $make('RD2026090201', '微信：重复测试客户', 550, '2026-09-02', ['A', 'B', 'C']);
    $dup = $make('订单编号：RD2026090201', '', 550, '2026-09-02', ['A', 'B']);
    $g = $mine('RD2026090201');
    $check(count($g) === 1 && (int)$g[0]['keep']['id'] === $keep && (int)$g[0]['dups'][0]['id'] === $dup, '带前缀写法的同号订单被识别为重复，建议保留有昵称、参与人更多的那张');
    $check(!empty($g[0]['deletable'][$dup]) && $g[0]['same_amount'] && $g[0]['same_date'], '日期售价相同且参与人是子集，可一键删除');
    $pdo->prepare('UPDATE project_orders SET contract_amount=551 WHERE id=?')->execute([$dup]);
    $failed = false; try { pdo_delete_duplicate($g[0]['key'], $dup, $finance); } catch (RuntimeException $e) { $failed = true; }
    $check($failed && $pdo->query("SELECT COUNT(*) FROM project_orders WHERE id=$dup")->fetchColumn() == 1, '页面打开后售价变化，旧确认不得删除订单');
    $pdo->prepare('UPDATE project_orders SET contract_amount=550 WHERE id=?')->execute([$dup]);
    $g = $mine('RD2026090201');
    $failed = false; try { pdo_delete_duplicate($g[0]['key'], $dup, ['type' => 'employee', 'id' => 1, 'role' => 'customer_service', 'employee_id' => $emp['A']]); } catch (RuntimeException $e) { $failed = true; }
    $check($failed, '非财务不能删除重复订单');
    $msg = pdo_delete_duplicate($g[0]['key'], $dup, $finance);
    $left = (int)$pdo->query("SELECT COUNT(*) FROM project_orders WHERE id IN ($keep,$dup)")->fetchColumn();
    $trash = (int)$pdo->query("SELECT COUNT(*) FROM project_order_trash WHERE order_no='订单编号：RD2026090201'")->fetchColumn();
    $check($left === 1 && $pdo->query("SELECT COUNT(*) FROM project_orders WHERE id=$keep")->fetchColumn() == 1 && $trash === 1 && strpos($msg, '已删除重复订单') !== false, '删除多余的一张，保留订单还在，多余的进回收站');
    $check(!$mine('RD2026090201'), '处理后从重复列表消失');
    $check($pdo->inTransaction(), '删除后保留调用方事务，测试数据可以完整回滚');

    // 2) 不能自动删的情形
    $a = $make('交易号：RD7770001', 'x', 100, '2026-09-03', ['A']);
    $b = $make('RD7770001', 'x', 120, '2026-09-03', ['A']);
    $g = $mine('RD7770001');
    $check(count($g) === 1 && !$g[0]['same_amount'] && !$g[0]['deletable'], '售价不同的重复订单不一键删除，提示人工核对');
    $c = $make('RD8880001', 'y', 100, '2026-09-04', ['A', 'C']);
    $d = $make('订单编号：RD8880001', '', 100, '2026-09-04', ['A', 'B']);
    $g = $mine('RD8880001');
    $failed = false; try { pdo_delete_duplicate($g[0]['key'], (int)$g[0]['dups'][0]['id'], $finance); } catch (RuntimeException $e) { $failed = strpos($e->getMessage(), '参与人') !== false; }
    $check($failed, '多余订单有保留订单没有的参与人时拒绝删除，避免有人少拿分成');

    // 3) 一个订单下的多个站点（~site-）不是重复
    $s1 = $make('RD9990001', 'z', 1000, '2026-09-05', ['A']);
    $s2 = $make('RD9990001~site-abcdef012345', 'z', 1000, '2026-09-05', ['A']);
    $check(!$mine('RD9990001'), '带 ~site- 的多站点订单不算重复');
    // 老订单带交易号等前缀时，后来上传的裸号也必须沿用原单。
    foreach (['交易号', '交易单号', '付款流水号', '支付单号', '商户订单号'] as $i => $label) {
        $no = 'RD66000' . $i;
        $stored = $label . '： ' . $no;
        $make($stored, '前缀测试', 100, '2026-09-05', ['A']);
        $check(ps_order_no_resolve($no) === $stored && ps_order_no_resolve('订单编号：' . $no) === $stored, $label . '旧单与不同写法的新上传识别为同一单');
    }
    $roleKeep = $make('RD5550001', '岗位测试', 100, '2026-09-05', ['A']);
    $roleDup = $make('订单编号：RD5550001', '', 100, '2026-09-05', ['A']);
    $pdo->prepare("UPDATE project_participants SET role_name='资料专员' WHERE order_id=?")->execute([$roleDup]);
    $g = $mine('RD5550001');
    $check(empty($g[0]['deletable'][$roleDup]), '同一员工承担不同岗位时也不能直接删除');
    $pdo->prepare("UPDATE project_orders SET settlement_status='locked' WHERE id=?")->execute([$roleDup]);
    $check(pod_blocker(['id' => $roleDup, 'settlement_status' => 'locked', 'order_date' => '2026-09-05', 'project_type' => '商标'], $finance) !== '', '锁定订单不能删除');
    $approvedKeep = $make('RD4440001', '分成回收测试', 100, '2099-01-01', ['A']);
    $approvedDup = $make('交易号：RD4440001', '', 100, '2099-01-01', ['A']);
    $pdo->prepare("UPDATE project_orders SET settlement_status='approved' WHERE id IN (?,?)")->execute([$approvedKeep, $approvedDup]);
    $pdo->prepare("INSERT INTO project_commission_snapshots (order_id,employee_id,commission_group,income_amount,direct_cost,contribution_profit,rule_id,rate,group_weight,commission_amount,payroll_month) VALUES (?,?,'technical',100,0,100,0,0.1,1,10,'2099-01')")->execute([$approvedDup, $emp['A']]);
    $g = $mine('RD4440001'); pdo_delete_duplicate($g[0]['key'], $approvedDup, $finance);
    $saved = json_decode($pdo->query("SELECT snapshot_json FROM project_order_trash WHERE order_id=$approvedDup ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id=$approvedDup")->fetchColumn() === 0 && count($saved['project_commission_snapshots']) === 1 && (float)$saved['project_commission_snapshots'][0]['commission_amount'] === 10.0 && count($saved['project_participants']) === 1, '财务可删除未核算月份的已审核重复单，分成撤回且原订单、参与人和分成完整入回收站');
    $pdo->prepare("INSERT INTO project_order_splits (parent_order_id,child_order_id,created_by_type,created_by_id) VALUES (?,?,'admin',?)")->execute([$c, $d, $finance['id']]);
    $g = $mine('RD8880001');
    $check(!$g[0]['deletable'], '有关联拆单的订单不能一键删除');
    echo "ALL PASS\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
