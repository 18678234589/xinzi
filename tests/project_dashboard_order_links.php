<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/ProjectPartnerDashboard.php';
$checks = 0;
function dashboard_link_check($label, $actual, $expected) {
    global $checks; $checks++;
    if ($actual !== $expected) throw new RuntimeException($label . ': ' . json_encode($actual));
    echo "PASS $label\n";
}
$url = ps_partner_orders_url(70, '2026-09');
parse_str(parse_url($url, PHP_URL_QUERY), $query);
dashboard_link_check('target is project orders', parse_url($url, PHP_URL_PATH), BASE_URL . '/project/index.php');
dashboard_link_check('selected employee retained', $query['employee_id'], '70');
dashboard_link_check('selected month retained', $query['month'], '2026-09');
dashboard_link_check('month uses order date not upload date', $query['date_basis'], 'order_date');
dashboard_link_check('participating orders only', $query['participating'], '1');
dashboard_link_check('all order link has no stale business filter', isset($query['filter_business']), false);
parse_str(parse_url(ps_partner_orders_url(70, '2026-09', '微信代写'), PHP_URL_QUERY), $businessQuery);
dashboard_link_check('business link survives url encoding', $businessQuery['filter_business'], '微信代写');
dashboard_link_check('finance may select partner', ps_partner_list_employee_id(['role'=>'finance'],70),70);
dashboard_link_check('finance all partners has no employee filter', ps_partner_list_employee_id(['role'=>'finance'],0),0);
dashboard_link_check('customer service cannot select another employee', ps_partner_list_employee_id(['role'=>'customer_service','employee_id'=>70],84),70);
dashboard_link_check('technical cannot select another employee', ps_partner_list_employee_id(['role'=>'technical','employee_id'=>53],70),53);
dashboard_link_check('historical business alias matches dashboard bucket', ps_partner_business_bucket('网站定制'),'AI网站定制');
dashboard_link_check('unknown historical business matches other bucket', ps_partner_business_bucket('历史未知业务'),'其他业务');
$source=file_get_contents(__DIR__.'/../project/index.php');
dashboard_link_check('participation filter survives form submits', strpos($source,'name="participating" value="1"')!==false,true);
dashboard_link_check('employee and participation retained after bulk action', strpos($source,"'employee_id','participating'")!==false,true);
echo "All $checks checks passed; no database writes.\n";
session_abort();
