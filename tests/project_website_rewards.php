<?php
// 必须显式使用连接级临时表：本测试可以连到生产库，但绝不修改真实表。
if (PHP_SAPI !== 'cli' || !in_array('--isolated-temporary-tables', $argv, true)) exit("Use --isolated-temporary-tables\n");
$wr_app_root = in_array('--production-private-run', $argv, true) ? ($argv[1] ?? '') : dirname(__DIR__);
if (!is_file($wr_app_root . '/includes/ProjectSettlement.php')) throw new RuntimeException('App root unavailable');
require_once $wr_app_root . '/includes/ProjectWebsiteRewards.php';
require_once $wr_app_root . '/includes/ProjectMonthly.php';
require_once $wr_app_root . '/includes/ProjectPresets.php';
$pdo = db();
$checks = 0;
function wr_check($condition, $label) {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
}
function wr_insert($table, $row) {
    $keys = array_keys($row);
    db()->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $keys) . '`) VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')')->execute(array_values($row));
}
// 原行仅作为 schema-compatible 种子留在内存，不输出凭据或任何账号信息。
$employeeSeed = $pdo->query('SELECT * FROM employees LIMIT 1')->fetch();
$userSeed = $pdo->query("SELECT * FROM project_users WHERE role='customer_service' LIMIT 1")->fetch();
$ruleSeed = $pdo->query("SELECT * FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='AI网站定制' AND effective_from='2026-09-01' AND is_active=1 ORDER BY id DESC LIMIT 1")->fetch();
$rankSeed = $pdo->query("SELECT * FROM project_monthly_rules WHERE name='定制客服月度第一名奖' AND effective_from='2026-10' AND is_active=1 LIMIT 1")->fetch();
if (!$employeeSeed || !$userSeed || !$ruleSeed || !$rankSeed) throw new RuntimeException('Missing compatible fixture seed');
foreach (['employees','project_users','project_commission_rules','project_monthly_rules','project_messages','project_audit_logs','project_payroll_periods'] as $table) {
    $ddl = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
    $ddl = preg_replace('/^CREATE TABLE /', 'CREATE TEMPORARY TABLE ', $ddl);
    $ddl = preg_replace('/^\s*CONSTRAINT .*FOREIGN KEY.*\n/m', '', $ddl);
    $ddl = preg_replace('/,\n\) ENGINE/', "\n) ENGINE", $ddl);
    if (strpos($ddl, 'CREATE TEMPORARY TABLE ') !== 0) throw new RuntimeException('Temporary table isolation failed');
    $pdo->exec($ddl);
}
foreach ([11,12,13,14,15,16,17] as $eid) {
    $e = $employeeSeed; $e['id'] = $eid; $e['name'] = '测试人员' . $eid;
    $e['department'] = $eid === 17 ? '小程序客服' : '网站客服';
    wr_insert('employees', $e);
    $u = $userSeed; $u['id'] = $eid; $u['employee_id'] = $eid; $u['username'] = 'wr_fixture_' . $eid;
    $u['phone'] = null;
    $u['role'] = $eid === 16 ? 'technical' : 'customer_service'; $u['is_active'] = $eid === 15 ? 0 : 1;
    wr_insert('project_users', $u);
}
$ruleSeed['id'] = 1; $ruleSeed['effective_from'] = '2026-09-01'; $ruleSeed['per_order_subsidy'] = 10; $ruleSeed['is_active'] = 1;
wr_insert('project_commission_rules', $ruleSeed);
$technical = $ruleSeed; $technical['id'] = 2; $technical['commission_group'] = 'technical'; $technical['per_order_subsidy'] = 7;
wr_insert('project_commission_rules', $technical);
$rankSeed['id'] = 100; $rankSeed['metric'] = 'profit'; $rankSeed['params_json'] = json_encode(['awards'=>[999]]);
wr_insert('project_monthly_rules', $rankSeed);
$older = $rankSeed; $older['id'] = 99; $older['name'] = '其他旧排名规则'; $older['effective_from'] = '2026-07';
wr_insert('project_monthly_rules', $older);
$actor = ['type' => 'admin', 'id' => 1, 'role' => 'finance'];
$published = pwr_publish_october_notice($actor, [14,12,11,13]);
wr_check($published['new_messages'] === 4, 'only active website CS receives announcement');
wr_check(array_column($published['recipients'], 'employee_id') == [11,12,13,14], 'exclude technical, inactive and other departments');
$repeat = pwr_publish_october_notice($actor, [11,12,13,14]);
wr_check($repeat['new_messages'] === 0, 'repeat does not re-send');
wr_check($repeat['commission_rule_id'] === $published['commission_rule_id'], 'repeat does not duplicate rule');
wr_check((int)$pdo->query('SELECT COUNT(*) FROM project_commission_rules')->fetchColumn() === 3, 'only one new version');
wr_check((float)$pdo->query('SELECT per_order_subsidy FROM project_commission_rules WHERE id=1')->fetchColumn() === 10.0, 'historical row untouched');
foreach (['2026-09-01'=>10, '2026-09-30'=>10, '2026-10-01'=>20, '2026-10-31'=>20, '2027-01-01'=>20] as $date=>$expected) {
    $r = ps_rule_for('customer_service','AI网站定制',$date,'客服','定制');
    wr_check((float)$r['per_order_subsidy'] === (float)$expected, 'date boundary ' . $date);
    wr_check((float)$r['rate'] === .1 && (float)$r['service_fee_rate'] === .03 && (float)$r['min_cost_rate'] === .65, 'profit algorithm unchanged ' . $date);
    $single = ps_calc_person($r,1000,100,1000,1,.03);
    $people = [];
    foreach ([.5,.5] as $weight) $people[] = ['rule'=>$r,'group_weight'=>$weight,'calc'=>ps_calc_person($r,1000,100,1000,$weight,.03)];
    $subsidies = ps_group_subsidy_cents($people);
    wr_check(array_sum($subsidies) === (int)($expected*100), 'joint subsidy pool not doubled ' . $date);
    wr_check($subsidies === [(int)($expected*50),(int)($expected*50)], 'joint equal shares ' . $date);
    wr_check(array_sum(ps_group_share_cents($people)) === (int)round($single['share']*100), 'joint proportional pool unchanged ' . $date);
}
wr_check((float)ps_rule_for('customer_service','网站定制','2026-10-09','客服','定制')['per_order_subsidy'] === 20.0, 'legacy website alias');
wr_check((float)ps_rule_for('technical','AI网站定制','2026-10-09')['per_order_subsidy'] === 7.0, 'technical unaffected');
$monthly = ps_monthly_rules_for('2026-10');
$rank = array_values(array_filter($monthly, function($r){return (int)$r['id']===100;}))[0];
wr_check($rank['metric'] === 'manual' && $rank['params']['awards'] === [500], 'comprehensive ranking and valid 500 JSON');
wr_check($rank['params']['assessment_dimensions'] === ['单量','收入','利润'], 'three dimensions recorded');
wr_check(json_decode($pdo->query('SELECT params_json FROM project_monthly_rules WHERE id=99')->fetchColumn(), true) === json_decode($older['params_json'], true), 'unrelated historic ranking untouched');
function wr_ranking($rule, $byEmployee, $people) {
    global $wr_app_root;
    $p = $rule['params']; $inputs = [(int)$rule['id']=>$byEmployee]; $out=[];
    $add=function($eid,$r,$amount,$detail,$keepZero=false)use(&$out){$out[]=['eid'=>$eid,'amount'=>$amount,'detail'=>$detail];};
    include $wr_app_root.'/includes/monthly/types/ranking_manual.php';
    return $out;
}
$ppl = [11=>['orders'=>1],12=>['orders'=>1],17=>['orders'=>1]];
$first = ['value'=>1,'note'=>'综合单量、收入、利润确认'];
$result = wr_ranking($rank,[11=>$first],$ppl);
wr_check(count($result)===1 && $result[0]['amount']===500.0, 'confirmed first gets exactly 500');
wr_check(wr_ranking($rank,[],$ppl)===[], 'unconfirmed winner not prepaid');
wr_check(array_sum(array_column(wr_ranking($rank,[11=>$first,12=>$first],$ppl),'amount'))===0, 'duplicate first not double paid');
wr_check(wr_ranking($rank,[17=>$first],$ppl)===[], 'wrong department cannot win');
wr_check(wr_ranking($rank,[11=>$first],[])===[], 'no eligible order cannot win');
wr_check(wr_ranking($rank,[11=>['value'=>1.5,'note'=>'']],$ppl)===[], 'fractional position not converted to first');
$staff = ['type'=>'employee','id'=>11,'employee_id'=>11];
$m = pna_pending($staff);
wr_check($m && strpos($m['body'],'20 元 / 单')!==false, 'unread policy popup text');
wr_check(pna_pending($actor)===null, 'admin preview does not consume announcement');
try {pna_acknowledge(['type'=>'employee','employee_id'=>12],$m['id'],'read');wr_check(false,'cross-user ack');}catch(RuntimeException $e){wr_check(true,'cross-user denied');}
pna_acknowledge($staff,$m['id'],'defer');
wr_check(pna_pending($staff)===null, 'defer does not nag same session');
wr_check($pdo->query('SELECT read_at FROM project_messages WHERE id='.(int)$m['id'])->fetchColumn()===null, 'defer remains unread');
unset($_SESSION['announcement_deferred']);
wr_check(pna_pending($staff)!==null, 'next login reminds unread notice');
pna_acknowledge($staff,$m['id'],'read');
$readAt=$pdo->query('SELECT read_at FROM project_messages WHERE id='.(int)$m['id'])->fetchColumn();
pna_acknowledge($staff,$m['id'],'read');
wr_check($readAt && $readAt===$pdo->query('SELECT read_at FROM project_messages WHERE id='.(int)$m['id'])->fetchColumn(), 'ack is idempotent');
wr_check(pna_pending($staff)===null, 'read notice not reopened');
$noticesAfterRead=(int)$pdo->query('SELECT COUNT(*) FROM project_messages')->fetchColumn();
pwr_publish_october_notice($actor,[11,12,13,14]);
wr_check((int)$pdo->query('SELECT COUNT(*) FROM project_messages')->fetchColumn()===$noticesAfterRead && pna_pending($staff)===null, 'publishing again does not reopen read notice');
$totalMessages=(int)$pdo->query('SELECT COUNT(*) FROM project_messages')->fetchColumn();
try {pwr_publish_october_notice($actor,[11]);wr_check(false,'wrong scope publish');}catch(RuntimeException $e){wr_check(true,'recipient change stops publish');}
wr_check((int)$pdo->query('SELECT COUNT(*) FROM project_messages')->fetchColumn()===$totalMessages, 'failed publish rolls back');
$octPreset = array_values(array_filter(ps_preset_rules(),function($r){return $r['project_type']==='AI网站定制' && $r['commission_group']==='customer_service' && ($r['min_from']??'')==='2026-10-01';}));
wr_check(count($octPreset)===1 && $octPreset[0]['per_order_subsidy']===20, 'preset cannot restore old October amount');
echo json_encode(['ok'=>true,'checks'=>$checks,'isolation'=>'connection-local temporary tables only'],JSON_UNESCAPED_UNICODE)."\n";
