<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/etmll_sync.php';

function etmll_check($value, $message)
{
    if (!$value) throw new RuntimeException($message);
}

$pdo = db();
// Connection-local tables shadow the real tables. No test record reaches production data.
foreach (['orders','shops','etmll_sync_state','project_orders','project_order_sources'] as $table) {
    $definition = $pdo->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1];
    $definition = preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$definition);
    $definition = preg_replace('/^\s*CONSTRAINT .*FOREIGN KEY.*\n/m','',$definition);
    $definition = preg_replace('/,\n\) ENGINE/',"\n) ENGINE",$definition);
    $pdo->exec($definition);
}
$base = ['total_amount'=>100,'refund_amount'=>0,'raw_status'=>'交易成功','order_pay_time'=>'2026-09-23 10:20:30.000',
    'created_at'=>'2026-09-23 10:00:00.000','shop_name'=>'ETMLL测试店A','merchant_name'=>'',
    'product_title'=>'同步回归测试','shipping_time'=>'','merchant_order_no'=>'','commission'=>0,'proxy_amount'=>0,'partner_name'=>''];
$source = [];
foreach ([1=>'ETM-NEW',2=>'ETM-PERSONAL',3=>'ETM-MANUAL',4=>'ETM-UPDATE',5=>'ETM-DELETED',6=>'ETM-MISSING',
    7=>'ETM-UNPAID',8=>'ETM-NEW',9=>'ETM-NEW',10=>'ETM-NOSHOP',11=>'ETM-PROJECT'] as $id=>$no) {
    $source[$id] = ['id'=>$id,'order_no'=>$no] + $base;
}
$source[4]['raw_status'] = '交易关闭';
$source[4]['refund_amount'] = 100;
$source[7]['raw_status'] = '等待买家付款';
$source[9]['shop_name'] = 'ETMLL测试店B';
$source[10]['shop_name'] = '';
$pdo->exec("INSERT INTO shops (name,sort) VALUES ('ETMLL测试店A',1)");
$insert = $pdo->prepare('INSERT INTO orders (employee_id,order_scope,order_no,shop,order_amount,order_date,raw_data,is_deleted) VALUES (?,?,?,?,?,?,?,?)');
$insert->execute([777,'personal','ETM-PERSONAL','ETMLL测试店A',900,'2026-09-23','{}',0]);
$insert->execute([0,'department','ETM-MANUAL','ETMLL测试店A',500,'2026-09-23',json_encode(['人工备注'=>'保留','付款昵称'=>'人工昵称'],JSON_UNESCAPED_UNICODE),0]);
$old = etmll_map_order(['id'=>4,'order_no'=>'ETM-UPDATE'] + $base);
$oldRaw = json_decode($old['raw_json'],true);
$oldRaw['人工备注'] = 'ETMLL更新也保留';
$insert->execute([0,'department','ETM-UPDATE','ETMLL测试店A',100,'2026-09-23',json_encode($oldRaw,JSON_UNESCAPED_UNICODE),0]);
$updatedId = (int)$pdo->lastInsertId();
$insert->execute([0,'department','ETM-DELETED','ETMLL测试店A',100,'2026-09-23','{}',1]);
$insert->execute([0,'department','ETM-PROJECT','ETMLL测试店A',100,'2026-09-23','{}',0]);
$pdo->exec("INSERT INTO etmll_sync_state (etmll_order_id,order_no,shop,order_amount) VALUES (4,'ETM-UPDATE','ETMLL测试店A',100),(6,'ETM-MISSING','ETMLL测试店A',100)");
$pdo->exec("INSERT INTO project_orders (order_no,project_type,order_date) VALUES ('ETM-PROJECT','AI网站定制','2026-09-23')");
$projectId = (int)$pdo->lastInsertId();
ps_source_record($projectId,'missing','','');
$pdo->prepare("UPDATE project_order_sources SET trade_status=?,status_source='shop_upload' WHERE order_id=?")
    ->execute(['买家已付款,等待卖家发货', $projectId]);
$pdo->exec("INSERT INTO project_orders (order_no,project_type,order_date,contract_amount,settlement_status) VALUES ('ETM-PERSONAL','AI网站定制','2026-09-23',900,'locked')");

$before = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$preview = etmll_sync_orders($pdo,$source,true);
etmll_check($preview['inserted']===3 && $preview['updated']===1 && $preview['linked_existing']===3,'preview must include missing shop flows and source updates');
etmll_check((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===$before,'preview must not write');
$result = etmll_sync_orders($pdo,$source,false);
etmll_check($result['inserted']===$preview['inserted'] && $result['updated']===$preview['updated'],'preview and real sync must agree');
etmll_check($result['project_filled']===1,'existing shop flow must fill later-created project');
etmll_check($result['project_status_updated']===1,'existing pending project status must advance to success');
etmll_check($result['linked_status_updated']===2,'linked manual flow statuses must advance without replacing money');
etmll_check($result['skipped_deleted']===1 && $result['skipped_missing_local']===1 && $result['skipped_unpaid']===1 && $result['skipped_no_shop']===1,'skips must be explained');
etmll_check((int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_no='ETM-PERSONAL' AND order_scope='department'")->fetchColumn()===1,'personal order cannot block shop flow');
etmll_check((int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_no='ETM-NEW' AND shop='ETMLL测试店A'")->fetchColumn()===1,'duplicate source numbers must not create duplicate flows');
etmll_check((int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_no='ETM-NEW' AND shop='ETMLL测试店B'")->fetchColumn()===1,'same number in another shop is a separate flow');
$updated = $pdo->query('SELECT * FROM orders WHERE id=' . $updatedId)->fetch();
$raw = json_decode($updated['raw_data'],true);
etmll_check((float)$updated['order_amount']===-100.0 && !empty($raw['__is_refund__']) && $raw['人工备注']==='ETMLL更新也保留','source refund must update while preserving extra fields');
$manual = $pdo->query("SELECT order_amount,raw_data FROM orders WHERE order_no='ETM-MANUAL'")->fetch();
etmll_check((float)$manual['order_amount']===500.0 && json_decode($manual['raw_data'],true)['付款昵称']==='人工昵称','manual flow must remain unchanged');
$project = $pdo->query('SELECT o.contract_amount,o.receipt_amount,s.trade_status FROM project_orders o JOIN project_order_sources s ON s.order_id=o.id WHERE o.id=' . $projectId)->fetch();
etmll_check((float)$project['contract_amount']===100.0 && (float)$project['receipt_amount']===0.0 && $project['trade_status']==='交易成功','project fill must not fabricate receipts');
etmll_check((float)$pdo->query("SELECT contract_amount FROM project_orders WHERE order_no='ETM-PERSONAL'")->fetchColumn()===900.0,'locked project must stay unchanged');
$repeat = etmll_sync_orders($pdo,$source,false);
etmll_check($repeat['inserted']===0 && $repeat['updated']===0 && $repeat['linked_existing']===0 && $repeat['project_filled']===0,'repeat sync must be idempotent');
etmll_check($repeat['project_status_updated']===0 && $repeat['linked_status_updated']===0,'repeat status sync must be idempotent');
$source[11]['raw_status'] = '买家已付款,等待卖家发货';
etmll_sync_orders($pdo,$source,false);
etmll_check($pdo->query('SELECT trade_status FROM project_order_sources WHERE order_id='.$projectId)->fetchColumn()==='交易成功','older source export cannot downgrade successful project');
$source[11]['raw_status'] = '交易关闭';
$source[11]['refund_amount'] = 100;
$closedProject = etmll_sync_orders($pdo,$source,false);
etmll_check($closedProject['project_status_updated']===1 && $pdo->query('SELECT trade_status FROM project_order_sources WHERE order_id='.$projectId)->fetchColumn()==='交易关闭','negative refund source must also propagate closure');
$source[11]['raw_status'] = '交易成功'; $source[11]['refund_amount'] = 0;
etmll_sync_orders($pdo,$source,false);
$source[7]['raw_status'] = '交易成功';
$paidLater = etmll_sync_orders($pdo,$source,false);
etmll_check($paidLater['inserted']===1,'unpaid order must sync when later paid');
$source[4]['raw_status'] = '交易成功';
$source[4]['refund_amount'] = 0;
$restored = etmll_sync_orders($pdo,$source,false);
etmll_check($restored['updated']===1,'source correction must update again');
$raw = json_decode($pdo->query('SELECT raw_data FROM orders WHERE id=' . $updatedId)->fetchColumn(),true);
etmll_check(empty($raw['__is_refund__']),'cleared refund must not retain old refund flag');
$sql = etmll_source_select_sql('2025-10-08');
etmll_check(strpos($sql,'o.status IN (0,1)')!==false,'verified source orders must remain synchronizable');
etmll_check(strpos($sql,'o.order_create_time')!==false && strpos($sql,'COALESCE(o.order_pay_time')===false,'old payment timestamp cannot mask newer creation timestamp');
etmll_check(strpos($sql,'o.buyer_paid_amount,o.alipay_no')!==false,'payment evidence must remain in scheduled source selection');
try { etmll_source_select_sql("2025-10-08' OR 1=1"); throw new RuntimeException('invalid boundary accepted'); }
catch (InvalidArgumentException $e) { }
$pdo->exec("CREATE TEMPORARY TABLE merchant (id INT PRIMARY KEY,name VARCHAR(50))");
$pdo->exec("CREATE TEMPORARY TABLE partner (id INT PRIMARY KEY,name VARCHAR(50))");
$pdo->exec("CREATE TEMPORARY TABLE `order` (id INT PRIMARY KEY,order_no VARCHAR(50),total_amount DECIMAL(10,2),refund_amount DECIMAL(10,2),raw_status VARCHAR(50),
    product_title VARCHAR(50),order_pay_time DATETIME,shipping_time DATETIME,created_at DATETIME,order_create_time DATETIME,shop_name VARCHAR(50),buyer_paid_amount DECIMAL(10,2),alipay_no VARCHAR(50),
    merchant_order_no VARCHAR(50),commission DECIMAL(10,2),proxy_amount DECIMAL(10,2),merchant_id INT,partner_id INT,status INT)");
$pdo->exec("INSERT INTO `order` (id,order_no,status,created_at,order_pay_time,buyer_paid_amount,alipay_no) VALUES
    (1,'CURRENT',0,'2026-09-01','2026-09-01',88,'PAY-1'),
    (2,'VERIFIED',1,'2026-09-01','2026-09-01',99,'PAY-2'),
    (3,'DELETED',2,'2026-09-01','2026-09-01',100,'PAY-3'),
    (4,'OLD-PAY-NEW-CREATE',0,'2026-09-01','2020-01-01',120,'PAY-4'),
    (5,'TOO-OLD',0,'2020-01-01','2020-01-01',10,'PAY-5')");
$selected = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
etmll_check(array_column($selected,'order_no')===['CURRENT','VERIFIED','OLD-PAY-NEW-CREATE'],'actual SQL must include verified/new creation and exclude deleted/old orders');
etmll_check((float)$selected[1]['buyer_paid_amount']===99.0 && $selected[1]['alipay_no']==='PAY-2','actual SQL must return payment evidence fields');
echo "ETMLL sync smoke OK: insert/update/link, scoped dedupe, refunds, pending-to-success/closed status, manual/locked protections, preview, repeat sync and query coverage; temporary tables only\n";
