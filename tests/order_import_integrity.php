<?php
// SQLite fixtures only; no production database writes or SMS.
require_once __DIR__ . '/../includes/ProjectRenewalImportFields.php';
require_once __DIR__ . '/../includes/ProjectSiteProjects.php';
require_once __DIR__ . '/../includes/ProjectPayrollOrderSummary.php';
require_once __DIR__ . '/../includes/intake/participants_orders.php';
require_once __DIR__ . '/../includes/ProjectImportParse.php';
require_once __DIR__ . '/../includes/intake/parse_rows.php';
$n = 0;
function oi_check($name, $ok) { global $n; if (!$ok) throw new RuntimeException($name); $n++; }
function oi_reject($name, $fn) { try { $fn(); } catch (RuntimeException $e) { oi_check($name, true); return; } throw new RuntimeException($name); }
oi_check('Excel text quote normalizes the same payment number', ps_order_no_canonical("'3312345678901234567")==='3312345678901234567');
oi_check('label and Excel quote do not create a second sale', ps_order_no_canonical("订单号：'3312345678901234567")==='3312345678901234567');
$r = pr_import_fields(['','',''], ['3312345678901234567','1200','13800000000'], ['order_no'=>0,'contract_amount'=>1,'contact_note'=>2]);
oi_check('headerless customer phone retained', $r['phone'] === '13800000000');
$r = pr_import_fields(['','',''], ['3312345678901234567','1200','138****0000'], ['contact_note'=>2]);
oi_check('masked phone stays missing', $r['phone'] === '' && $r['wechat'] === '');
$r = pr_import_fields([''], ['482390983'], ['contact_note'=>0]);
oi_check('unlabelled QQ account is not a telephone', $r['phone'] === '' && $r['wechat'] === '');
oi_check('labelled mobile in the inferred phone column retained', pr_import_phone_value('客户 13800000000（联系人）',true)==='13800000000');
oi_check('annotated mainland country prefix retained', pr_import_phone_value('+8613800000000（联系人）',true)==='13800000000');
oi_check('long order ID is not mined for a phone substring', pr_import_phone_value('3316380000000023456',true)==='');
oi_check('two mobile numbers remain ambiguous', pr_import_phone_value('13800000000 / 13900000000',true)==='');
oi_check('scientific notation preserves an exact mobile', pr_import_phone_value('1.38E+10',true)==='13800000000');
oi_check('unambiguous international phone retained', pr_import_phone_value('+886912345678',true)==='+886912345678');
$headerless=[['2026-09-01','3312345678901234567','1200','清风易','13800000000（联系人）'],['2026-09-02','3312345678901234568','1500','清风易','+8613900000000（客户）']];
$map=ps_import_headerless_map($headerless,['清风易'],[]);
oi_check('headerless inference recognises annotated phone columns', $map['contact_note']===4 && $map['contract_amount']===2 && $map['order_no']===1);
$r = pr_import_fields(['','',''], ['13800000000','13800000000','buyer_name'], ['order_no'=>0,'contract_amount'=>1,'payment_nickname'=>2]);
oi_check('order number and amount never become contact', $r['phone'] === '' && $r['wechat'] === '');
$r = pr_import_fields(['备注'], ['13800000000'], ['contact_note'=>0]);
oi_check('generic notes are not inferred customer contact', $r['phone'] === '');
foreach (['联系方式','客户联系方式','续费联系方式','手机号码'] as $label) {
    $r = pr_import_fields([$label], ['13800000000']); oi_check('named customer contact '.$label, $r['phone'] === '13800000000');
}
$r = pr_import_fields(['客服手机号','客户微信'], ['13800000000','customer_wx']);
oi_check('staff phone excluded and customer WeChat retained', $r['phone'] === '' && $r['wechat'] === 'customer_wx');
$a = ['phone'=>'','wechat'=>'existing_wx','resources'=>['domain'=>['resource_name'=>'example.com']]];
$b = ['phone'=>'13800000000','wechat'=>'other_wx','resources'=>['domain'=>['expires_on'=>'2027-09-30']]];
$r = pr_import_merge_fields($a,$b);
oi_check('addon row contact retained without overwriting', $r['phone']==='13800000000' && $r['wechat']==='existing_wx' && $r['resources']['domain']['resource_name']==='example.com' && $r['resources']['domain']['expires_on']==='2027-09-30');
$members = [['order_id'=>1,'root_order_id'=>1,'site_key'=>'first.example','contract_amount'=>'1240.00'],['order_id'=>2,'root_order_id'=>1,'site_key'=>'second.example','contract_amount'=>'1000.00']];
oi_check('unlabelled reupload reuses existing website', psp_import_auto_key($members,2,'1,000.00')==='second.example');
$members[]=['order_id'=>3,'root_order_id'=>1,'site_key'=>'third.example','contract_amount'=>'1000.00'];
oi_reject('ambiguous existing websites block duplicate creation', function() use($members){psp_import_auto_key($members,2,'1000');});
oi_reject('changed price cannot silently create a new website', function() use($members){psp_import_auto_key($members,2,'950');});
oi_check('pure profit line is an addon', psp_is_addon_program('纯利润'));
$p = new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec('CREATE TABLE project_orders (id INTEGER PRIMARY KEY,project_type TEXT,contract_amount TEXT,receipt_amount TEXT,refund_amount TEXT,settlement_status TEXT,order_date TEXT,created_at TEXT); CREATE TABLE project_participants (order_id INTEGER,employee_id INTEGER); CREATE TABLE project_auto_reviews (order_id INTEGER PRIMARY KEY,state TEXT,reasons_json TEXT)');
$insert=$p->prepare('INSERT INTO project_orders VALUES(?,?,?,?,?,?,?,?)');
foreach ([
 [1,'网站模板','100.10','100.10','0','approved','2026-09-01','2026-10-08'],
 [2,'网站模板','200.20','0','0','draft','2026-09-30','2026-10-08'],
 [3,'AI网站定制','300','0','0','draft','2026-08-28','2026-09-02'],
 [4,'AI网站定制','9000','0','0','draft','2026-08-28','2026-10-08'],
 [5,'网站模板','8000','0','0','draft','2026-10-01','2026-10-08'],
 [6,'网站模板','7000','0','0','draft','2026-09-15','2026-09-15']
] as $row) $insert->execute($row);
$p->exec('INSERT INTO project_participants VALUES(1,84),(1,84),(2,84),(3,84),(4,84),(5,84),(6,79)');
$p->prepare('INSERT INTO project_auto_reviews VALUES(?,?,?)')->execute([2,'wait_data',json_encode([['code'=>'resource','text'=>'资源待确认'],['code'=>'resource','text'=>'资源待确认']])]);
$s=ps_payroll_order_summary(84,'2026-09',$p);
oi_check('period excludes future imports and other staff', $s['count']===3 && $s['pending']===2);
oi_check('multiple participants do not double count amounts', abs($s['contract']-600.30)<0.001 && abs($s['received']-100.10)<0.001);
oi_check('actual data reason appears once per order', $s['businesses']['网站模板']['reasons']['resource']['count']===1 && strpos(ps_payroll_pending_reason_text($s,'网站模板'),'资源待确认')!==false);
oi_check('missing financial evidence is not fabricated', strpos(ps_payroll_pending_reason_text($s,'网站模板'),'收款流水未匹配')===false);
echo json_encode(['passed'=>$n,'production_writes'=>false],JSON_UNESCAPED_UNICODE).PHP_EOL;
