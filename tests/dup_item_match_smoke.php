<?php
// 没订单号的行金额 = 已有订单某商品金额（同日同昵称）→ 按顺序对应已有订单，超出的才是新订单。仅限本地隔离库。
if (PHP_SAPI !== 'cli') exit(1);
$root = getenv('HEZUOSHANG_APP') ?: dirname(__DIR__);
require_once $root . '/includes/ProjectSettlement.php';
require_once $root . '/includes/ProjectDupAppeal.php';
function di_check($n, $ok) { if (!$ok) throw new RuntimeException($n); echo "PASS $n\n"; }
$p = db();
if (!preg_match('/_(test|ci|sandbox)$/', (string)$p->query('SELECT DATABASE()')->fetchColumn())) exit("refuse: not an isolated database\n");
$tag = 'DI-' . bin2hex(random_bytes(4)); $ids = [];
try {
    $o = $p->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,order_date,delivery_status,settlement_status) VALUES (?,'di','网站模板','di',500,'2099-03-03','finished','draft')");
    $s = $p->prepare("INSERT INTO project_order_sources (order_id,payment_nickname,price_source,nickname_source,status_source) VALUES (?,?,'manual','manual','manual')");
    $i = $p->prepare("INSERT INTO project_order_items (order_id,item_key,item_name,category,sale_amount) VALUES (?,?,?,'program',?)");
    foreach ([0, 1] as $n) { $o->execute([$tag . '-' . $n]); $id = (int)$p->lastInsertId(); $ids[] = $id; $s->execute([$id, 'TyllCty']); $i->execute([$id, 'a', '1年优站', 400]); $i->execute([$id, 'b', '1年纯利润', 100]); }
    $m400 = [pda_find_existing_item('网站模板', '2099-03-03', 400, 'tyllcty', 1), pda_find_existing_item('网站模板', '2099-03-03', 400, 'tyllcty', 2), pda_find_existing_item('网站模板', '2099-03-03', 400, 'tyllcty', 3)];
    di_check('两个 400 的商品按顺序对应两张订单，第三次才是新订单（昵称不分大小写）', (int)$m400[0]['id'] === $ids[0] && (int)$m400[1]['id'] === $ids[1] && $m400[2] === null);
    di_check('不同昵称 / 不同日期 / 没写昵称都不匹配', pda_find_existing_item('网站模板', '2099-03-03', 400, 'other', 1) === null && pda_find_existing_item('网站模板', '2099-03-04', 400, 'tyllcty', 1) === null && pda_find_existing_item('网站模板', '2099-03-03', 400, '', 1) === null);
    di_check('金额对不上不匹配', pda_find_existing_item('网站模板', '2099-03-03', 401, 'tyllcty', 1) === null);
} finally {
    foreach ($ids as $id) { foreach (['project_order_items', 'project_order_sources'] as $t) $p->exec("DELETE FROM $t WHERE order_id=$id"); $p->exec("DELETE FROM project_orders WHERE id=$id"); }
}
