<?php
// 只读端到端核对实际页面链接、实际列表与看板统计；不会保存登录会话或修改订单。
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? dirname(__DIR__);
$_SERVER['DOCUMENT_ROOT']=$root; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['SCRIPT_NAME']='/project/index.php';
require_once $root.'/includes/ProjectPartnerDashboard.php';
$auditAdminId=(int)db()->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
db()->exec('SET TRANSACTION READ ONLY');
db()->beginTransaction();
$GLOBALS['project_order_list_cli']=true;
$results=[];
function audit_order_link_case($root,$adminId,$auditMode,$auditEmployee,$auditQuery) {
    if ($auditMode==='finance') $_SESSION=['admin_id'=>$adminId,'ps_last_auto_finish_time'=>time()];
    else {
        $q=db()->prepare('SELECT id FROM project_users WHERE employee_id=? AND is_active=1 ORDER BY id LIMIT 1'); $q->execute([$auditEmployee]);
        $uid=(int)$q->fetchColumn(); if (!$uid) throw new RuntimeException('缺少测试合作人员账号');
        $_SESSION=['project_user_id'=>$uid,'ps_last_auto_finish_time'=>time()];
    }
    $_GET=$auditQuery;
    require $root.'/project/index.php';
    [$start,$end]=ps_partner_month_bounds($auditQuery['month']);
    $expected=ps_partner_orders($auditEmployee,$start,$end);
    if (!empty($auditQuery['filter_business'])) $expected=array_values(array_filter($expected,function($r)use($auditQuery){return ps_partner_business_bucket($r['project_type'])===$auditQuery['filter_business'];}));
    $expectedIds=array_map('intval',array_column($expected,'id')); sort($expectedIds);
    $actualIds=array_map('intval',array_column($orders,'id')); sort($actualIds);
    if ($expectedIds!==$actualIds || $totalOrders!==count($expected)) throw new RuntimeException('看板与列表不一致：'.$auditMode.':'.$auditEmployee.':'.json_encode($auditQuery));
    parse_str(ltrim($pageQuery(2),'?'),$next);
    foreach (['employee_id','month','date_basis','participating','filter_business'] as $key) if (isset($auditQuery[$key]) && ($next[$key]??null)!==(string)$auditQuery[$key]) throw new RuntimeException('分页丢失筛选：'.$key);
    return ['mode'=>$auditMode,'employee'=>$auditEmployee,'month'=>$auditQuery['month'],'business'=>$auditQuery['filter_business']??'all','dashboard_count'=>count($expected),'list_count'=>$totalOrders,'pages'=>$totalPages];
}
try {
    foreach ([70,84,53] as $employee) {
        parse_str(parse_url(ps_partner_orders_url($employee,'2026-09'),PHP_URL_QUERY),$query);
        $results[]=audit_order_link_case($root,$auditAdminId,'finance',$employee,$query);
        [$from,$until]=ps_partner_month_bounds('2026-09');
        foreach (ps_partner_summary(ps_partner_orders($employee,$from,$until))['businesses'] as $business=>$stats) {
            parse_str(parse_url(ps_partner_orders_url($employee,'2026-09',$business),PHP_URL_QUERY),$query);
            $results[]=audit_order_link_case($root,$auditAdminId,'finance',$employee,$query);
        }
    }
    parse_str(parse_url(ps_partner_orders_url(84,'2026-09'),PHP_URL_QUERY),$query);
    $results[]=audit_order_link_case($root,$auditAdminId,'partner',70,$query); // 请求别人编号仍仅显示本人。
    parse_str(parse_url(ps_partner_orders_url(70,'2025-01'),PHP_URL_QUERY),$query);
    $results[]=audit_order_link_case($root,$auditAdminId,'finance',70,$query);
    unset($GLOBALS['project_order_list_cli']);
    $_SESSION=['admin_id'=>$auditAdminId]; $_GET=['employee_id'=>70,'month'=>'2026-09']; $_SERVER['SCRIPT_NAME']='/project/dashboard.php';
    ob_start();
    try { require $root.'/project/dashboard.php'; $html=ob_get_clean(); } catch(Throwable $e) { ob_end_clean(); throw $e; }
    $expectedHref=htmlspecialchars(ps_partner_orders_url(70,'2026-09'),ENT_QUOTES,'UTF-8');
    if (substr_count($html,'href="'.$expectedHref.'"')<2) throw new RuntimeException('标题和参与数量缺少对应链接');
    foreach ($summary['businesses'] as $business=>$stats) {
        $href=htmlspecialchars(ps_partner_orders_url(70,'2026-09',$business),ENT_QUOTES,'UTF-8');
        if (strpos($html,'href="'.$href.'"')===false) throw new RuntimeException('业务数量缺少对应链接');
    }
    echo json_encode(['cases'=>$results,'dashboard_links_rendered'=>true,'database_writes'=>false,'session_saved'=>false],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
} finally {
    if(db()->inTransaction()) db()->rollBack();
    $_SESSION=[]; session_abort();
}
