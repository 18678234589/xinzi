<?php
/**
 * One-time, guarded repair for three historical website payments with two independently
 * priced sites each. Run on the production host via PHP CLI. Default is read-only.
 * Set SITE3_APPLY=1 to commit. All changes are one database transaction.
 */
if (PHP_SAPI !== 'cli' || gethostname() !== 'ai') throw new RuntimeException('Only run on the production CLI host');
require_once '/www/wwwroot/hezuoshang/includes/ProjectIntake.php';
require_once '/www/wwwroot/hezuoshang/includes/ProjectOrderSplit.php';
require_once '/www/wwwroot/hezuoshang/includes/ProjectSiteProjects.php';
require_once '/www/wwwroot/hezuoshang/includes/ProjectAutoReview.php';

$plans = [
    ['id'=>53753, 'no'=>'5127397382074007118', 'before'=>1000, 'paid'=>0,
     'sites'=>[['key'=>'yangc63.anli.zbwdj.com','amount'=>500,'file'=>377,'line'=>21],['key'=>'du05.anli.zbwdj.com','amount'=>500,'file'=>377,'line'=>23]],
     'participants'=>[70,83], 'items'=>[], 'child_item'=>null, 'child_cost'=>null, 'movement'=>null],
    ['id'=>53109, 'no'=>'5127403862051006821', 'before'=>2240, 'paid'=>2240,
     'sites'=>[['key'=>'myyaoyong.com','amount'=>1240,'file'=>463,'line'=>32],['key'=>'firetechchips.com','amount'=>1000,'file'=>463,'line'=>34]],
     'participants'=>[79,84], 'items'=>[54,55], 'child_item'=>55, 'child_cost'=>null, 'movement'=>45717],
    ['id'=>55025, 'no'=>'5127689160360022846', 'before'=>500, 'paid'=>0,
     'sites'=>[['key'=>'donghongzj.com','amount'=>250,'file'=>463,'line'=>164],['key'=>'xingshengty.com','amount'=>250,'file'=>463,'line'=>166]],
     'participants'=>[78,79], 'items'=>[84,85], 'child_item'=>85, 'child_cost'=>20311, 'movement'=>null],
];
$apply = getenv('SITE3_APPLY') === '1';
$pdo = db();
$actor = ['type'=>'system','id'=>0,'role'=>'system'];
$created = [];

function site3_cents($value) { return (int)round((float)$value * 100); }
function site3_assert($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function site3_rows($sql, $params) {
    $q = db()->prepare($sql); $q->execute($params); return $q->fetchAll();
}
function site3_source($rootId) {
    $rows = site3_rows('SELECT * FROM project_order_sources WHERE order_id=?', [$rootId]);
    site3_assert(count($rows) === 1, 'source row missing for order ' . $rootId);
    return $rows[0];
}

try {
    $pdo->beginTransaction();
    foreach ($plans as $plan) {
        $id = $plan['id']; $no = $plan['no']; $first = $plan['sites'][0]; $second = $plan['sites'][1];
        $orders = site3_rows('SELECT * FROM project_orders WHERE id=? FOR UPDATE', [$id]);
        site3_assert(count($orders) === 1, 'root order missing: ' . $id);
        $root = $orders[0];
        site3_assert($root['order_no'] === $no && psp_is_website($root['project_type']), 'order identity changed: ' . $id);
        site3_assert($root['settlement_status'] === 'draft' && site3_cents($root['refund_amount']) === 0, 'order status/refund changed: ' . $id);
        site3_assert(site3_cents($root['contract_amount']) === $plan['before'] * 100 && site3_cents($root['receipt_amount']) === $plan['paid'] * 100, 'amount/receipt changed: ' . $id);
        site3_assert($first['amount'] + $second['amount'] === $plan['before'], 'site amounts do not add up: ' . $id);
        site3_assert(!site3_rows('SELECT order_id FROM project_site_projects WHERE root_order_id=?', [$id]), 'site registry already exists: ' . $id);
        site3_assert(!site3_rows('SELECT child_order_id FROM project_order_splits WHERE parent_order_id=?', [$id]), 'split child already exists: ' . $id);
        site3_assert(!site3_rows('SELECT order_id FROM project_commission_snapshots WHERE order_id=?', [$id]), 'commission snapshot already exists: ' . $id);
        $childNo = psp_child_no($no, psp_key($second['key']));
        site3_assert(!site3_rows('SELECT id FROM project_orders WHERE order_no=?', [$childNo]), 'child number already exists: ' . $childNo);
        $people = site3_rows('SELECT employee_id,commission_group,role_name,group_weight FROM project_participants WHERE order_id=? ORDER BY employee_id', [$id]);
        $peopleIds = array_map(function ($r) { return (int)$r['employee_id']; }, $people);
        sort($peopleIds); $expectedPeople = $plan['participants']; sort($expectedPeople);
        site3_assert($peopleIds === $expectedPeople && count($people) === 2, 'participants changed: ' . $id);
        $items = site3_rows('SELECT id,sale_amount,cost_id,source_file_id,source_line FROM project_order_items WHERE order_id=? ORDER BY id FOR UPDATE', [$id]);
        $itemIds = array_map(function ($r) { return (int)$r['id']; }, $items);
        site3_assert($itemIds === $plan['items'], 'items changed: ' . $id);
        if ($items) {
            site3_assert(count($items) === 2 && site3_cents($items[0]['sale_amount']) === $first['amount'] * 100 && site3_cents($items[1]['sale_amount']) === $second['amount'] * 100, 'item allocations changed: ' . $id);
            site3_assert((int)$items[0]['source_file_id'] === $first['file'] && (int)$items[0]['source_line'] === $first['line'] && (int)$items[1]['source_file_id'] === $second['file'] && (int)$items[1]['source_line'] === $second['line'], 'item provenance changed: ' . $id);
        }
        $costs = site3_rows('SELECT id,amount,review_status FROM project_costs WHERE order_id=? ORDER BY id FOR UPDATE', [$id]);
        if ($plan['child_cost']) {
            site3_assert(count($costs) === 2 && (int)$costs[0]['id'] === 20310 && (int)$costs[1]['id'] === 20311 && $costs[0]['review_status'] === 'pending' && $costs[1]['review_status'] === 'pending' && site3_cents($costs[0]['amount']) === 17000 && site3_cents($costs[1]['amount']) === 17000, 'program costs changed: ' . $id);
            site3_assert((int)$items[1]['cost_id'] === $plan['child_cost'], 'item-cost link changed: ' . $id);
        } else site3_assert(count($costs) === 0, 'unexpected costs: ' . $id);
        $resources = site3_rows('SELECT * FROM project_order_resources WHERE order_id=? FOR UPDATE', [$id]);
        site3_assert(count($resources) === 1 && $resources[0]['domain_mode'] === 'pending', 'resource state changed: ' . $id);
        $ledger = site3_rows('SELECT * FROM project_cash_movements WHERE order_id=? FOR UPDATE', [$id]);
        if ($plan['movement']) {
            site3_assert(count($ledger) === 1 && (int)$ledger[0]['id'] === $plan['movement'] && $ledger[0]['movement_type'] === 'receipt' && $ledger[0]['review_status'] === 'approved' && site3_cents($ledger[0]['amount']) === $plan['paid'] * 100, 'approved payment changed: ' . $id);
            $source = site3_source($id);
            $payment = pa_payment_evidence(pa_sources($root, $source, false), trim($root['shop']), date('Y-m-d H:i:s'));
            site3_assert($payment['paid_cents'] !== null && (int)$payment['paid_cents'] === $plan['paid'] * 100, 'shop payment evidence does not match: ' . $id);
        } else site3_assert(count($ledger) === 0, 'unexpected cash ledger: ' . $id);
        $details = site3_rows('SELECT business_name,details_json FROM project_order_details WHERE order_id=? FOR UPDATE', [$id]);
        site3_assert(count($details) <= 1, 'duplicate business details: ' . $id);
        $detailData = $details ? (json_decode($details[0]['details_json'], true) ?: []) : [];
        site3_assert(empty($detailData['website_url']), 'website URL already assigned: ' . $id);
        $source = site3_source($id);
        echo 'READY ', $id, ' ', $no, ' -> ', $first['key'], ' ', $first['amount'], ' + ', $second['key'], ' ', $second['amount'], ' paid=', $plan['paid'], PHP_EOL;
        if (!$apply) continue;

        $childNote = trim((string)$root['note'] . '；历史共用付款网站项目：' . $second['key'] . '；原订单ID：' . $id);
        $insert = $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,refund_amount,order_date,delivery_status,settlement_status,note,created_by_admin) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $insert->execute([$childNo,$root['customer_name'],$root['project_type'],$root['order_kind'],$root['shop'],$second['amount'],$plan['movement'] ? $second['amount'] : 0,0,$root['order_date'],$root['delivery_status'],'draft',$childNote,$root['created_by_admin']]);
        $childId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE project_orders SET contract_amount=?,receipt_amount=?,row_version=row_version+1,note=CONCAT_WS(?,NULLIF(note,?),?) WHERE id=?')
            ->execute([$first['amount'],$plan['movement'] ? $first['amount'] : 0,'；','','历史共用付款网站项目：' . $first['key'],$id]);
        $pdo->prepare('INSERT INTO project_order_sources (order_id,payment_nickname,payment_reference,trade_status,price_source,nickname_source,status_source,source_order_id,synced_at) SELECT ?,payment_nickname,payment_reference,trade_status,price_source,nickname_source,status_source,source_order_id,synced_at FROM project_order_sources WHERE order_id=?')
            ->execute([$childId,$id]);
        $pdo->prepare('INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) SELECT ?,employee_id,commission_group,role_name,group_weight FROM project_participants WHERE order_id=?')
            ->execute([$childId,$id]);
        $pdo->prepare('INSERT INTO project_order_resources (order_id,source_type,source_line,domain_mode,domain_template_id,server_template_id,program_template_id,ssl_expected_amount) SELECT ?,source_type,?,domain_mode,domain_template_id,server_template_id,program_template_id,ssl_expected_amount FROM project_order_resources WHERE order_id=?')
            ->execute([$childId,$second['line'],$id]);
        $rootDetails = $detailData; $rootDetails['website_url'] = $first['key'];
        $childDetails = $detailData; $childDetails['website_url'] = $second['key'];
        if ($details) $pdo->prepare('UPDATE project_order_details SET details_json=? WHERE order_id=?')->execute([json_encode($rootDetails,JSON_UNESCAPED_UNICODE),$id]);
        else $pdo->prepare('INSERT INTO project_order_details (order_id,business_name,details_json) VALUES (?,?,?)')->execute([$id,$root['project_type'],json_encode($rootDetails,JSON_UNESCAPED_UNICODE)]);
        $pdo->prepare('INSERT INTO project_order_details (order_id,business_name,details_json) VALUES (?,?,?)')->execute([$childId,$details ? $details[0]['business_name'] : $root['project_type'],json_encode($childDetails,JSON_UNESCAPED_UNICODE)]);
        if ($plan['child_item']) {
            $moveItem = $pdo->prepare('UPDATE project_order_items SET order_id=? WHERE id=? AND order_id=?');
            $moveItem->execute([$childId,$plan['child_item'],$id]);
            site3_assert($moveItem->rowCount() === 1, 'item move failed: ' . $id);
        }
        if ($plan['child_cost']) {
            $moveCost = $pdo->prepare('UPDATE project_costs SET order_id=? WHERE id=? AND order_id=?');
            $moveCost->execute([$childId,$plan['child_cost'],$id]);
            site3_assert($moveCost->rowCount() === 1, 'cost move failed: ' . $id);
        }
        if ($plan['movement']) {
            $cashNote = '历史共用付款分配：原已审核流水 #'.$plan['movement'].' 金额 '.$plan['paid'].'；本网站 '.$first['amount'].'；另一网站 '.$second['amount'];
            $cash = $pdo->prepare('UPDATE project_cash_movements SET amount=?,note=? WHERE id=? AND order_id=?');
            $cash->execute([$first['amount'],$cashNote,$plan['movement'],$id]);
            site3_assert($cash->rowCount() === 1, 'approved payment allocation failed: ' . $id);
            $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,effective_month,source,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'receipt',?,?,?,'manual','approved','system',0,NULL,NOW())")
                ->execute([$childId,$second['amount'],'原已审核实付流水 #'.$plan['movement'].' 共 '.$plan['paid'].'；按独立网站 '.$second['key'].' 分配',$ledger[0]['effective_month']]);
        }
        pos_link($id,$childId,$actor);
        psp_register($id,$id,$no,$first['key'],$actor);
        psp_register($childId,$id,$no,$second['key'],$actor);
        ps_audit('order',$id,'historical_site_split',$actor,['child_order_id'=>$childId,'external_order_no'=>$no,'original_contract_amount'=>$plan['before'],'original_receipt_amount'=>$plan['paid'],'first_site'=>$first,'second_site'=>$second,'moved_item_id'=>$plan['child_item'],'moved_cost_id'=>$plan['child_cost'],'original_cash_movement_id'=>$plan['movement'],'finance_verification'=>'pending']);
        ps_audit('order',$childId,'historical_site_split_child',$actor,['root_order_id'=>$id,'external_order_no'=>$no,'site_key'=>$second['key'],'contract_amount'=>$second['amount'],'receipt_amount'=>$plan['movement'] ? $second['amount'] : 0,'finance_verification'=>'pending']);
        $created[$id] = $childId;
    }
    if (!$apply) { $pdo->rollBack(); echo 'DRY_RUN_ONLY',PHP_EOL; }
    else {
        foreach ($plans as $plan) {
            $id=$plan['id']; $childId=$created[$id];
            $members=psp_members($id,true);
            site3_assert(count($members)===2 && (int)$members[0]['order_id']===$id && (int)$members[1]['order_id']===$childId, 'postcondition member mismatch: '.$id);
            site3_assert(site3_cents($members[0]['contract_amount'])===$plan['sites'][0]['amount']*100 && site3_cents($members[1]['contract_amount'])===$plan['sites'][1]['amount']*100, 'postcondition contract mismatch: '.$id);
            $expectedCash=$plan['movement'] ? $plan['before']*100 : 0;
            site3_assert(site3_cents($members[0]['ledger_receipt'])+site3_cents($members[1]['ledger_receipt'])===$expectedCash, 'postcondition cash mismatch: '.$id);
            site3_assert(psp_verification($id)===null, 'unexpected finance verification: '.$id);
        }
        $pdo->commit();
        foreach ($created as $root=>$child) echo 'COMMITTED ', $root, ' -> ', $child, PHP_EOL;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,'ABORTED '.$e->getMessage().PHP_EOL);
    exit(1);
}
