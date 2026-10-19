<?php
if (PHP_SAPI !== 'cli') exit(1);
$root = getenv('HEZUOSHANG_APP') ?: dirname(__DIR__);
require_once $root . '/includes/ProjectCompletionSync.php';
function website_sync_check($name, $ok) {
    if (!$ok) throw new RuntimeException($name);
    echo "PASS $name\n";
}
$p = db();
$p->beginTransaction();
try {
    $tag = 'WEBSITE-SCOPE-' . bin2hex(random_bytes(6));
    $insert = $p->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,order_date,delivery_status,settlement_status) VALUES (?,'scope test',?,'scope test',100,'2026-09-01','unfinished','draft')");
    $map = []; $ids = [];
    foreach (['网站模板','AI网站定制','网站定制','小程序开发','设计'] as $i => $business) {
        $no = $tag . '-' . $i;
        $insert->execute([$no,$business]);
        $ids[] = (int)$p->lastInsertId();
        $map[$no] = 'fin';
    }
    $plan = pcs_plan($map);
    website_sync_check('all three website names are supported', count($plan['mark_finished']) === 3 && array_column($plan['mark_finished'],'project_type') === ['网站模板','AI网站定制','网站定制']);
    website_sync_check('miniapp and other business are skipped in preview', $plan['not_website'] === 2 && !$plan['missing']);
    $mini = $p->query('SELECT id,order_no,project_type,delivery_status FROM project_orders WHERE id=' . $ids[3])->fetch(PDO::FETCH_ASSOC);
    $fake = ['mark_finished'=>[$mini],'mark_unfinished'=>[$mini],'approved_unfinished'=>[$mini]];
    $result = pcs_apply($fake,true,['type'=>'admin','id'=>1,'role'=>'finance']);
    website_sync_check('all apply paths reject non-website orders', $result['finished'] === 0 && $result['unfinished'] === 0 && $result['reopened'] === 0 && count($result['skipped']) === 3);
    $after = $p->query('SELECT id,order_no,project_type,delivery_status FROM project_orders WHERE id=' . $ids[3])->fetch(PDO::FETCH_ASSOC);
    website_sync_check('skipped miniapp stays unchanged', $mini === $after);
    website_sync_check('website status interpretation is retained', pcs_classify('到账') === 'fin' && pcs_classify('未完成') === 'unf');
} finally {
    if ($p->inTransaction()) $p->rollBack();
}
