<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/ProjectSettlement.php';
$pdo = db(); $n = 0;
function finance_check($actual, $expected, $label) {
    global $n; $n++;
    if ($actual !== $expected) throw new RuntimeException($label . ': ' . json_encode($actual));
}
// Connection-local tables shadow production rows and disappear with the test connection.
$pdo->exec('CREATE TEMPORARY TABLE project_settings (setting_key VARCHAR(80) PRIMARY KEY,setting_value TEXT,updated_by_admin INT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TEMPORARY TABLE project_orders (id INT PRIMARY KEY,project_type VARCHAR(100),contract_amount DECIMAL(14,2))');
$insert = $pdo->prepare('INSERT INTO project_orders VALUES (?,?,?)');
foreach (['小程序开发','标书','软文代写','微信代写','网站模板','小程序','标书业务'] as $i => $business) $insert->execute([$i + 1,$business,100]);
ps_setting_get('', null, true);
finance_check(ps_business_reviewer('小程序'), 'weihuizi', 'Miniapp alias');
finance_check(ps_business_reviewer('标书'), 'weihuizi', 'Bid owner');
finance_check(ps_business_reviewer('软文代写'), 'wangfang', 'Writing owner');
finance_check(ps_business_reviewer('微信代写'), 'weihuizi', 'Existing independent WeChat writing assignment retained');
finance_check(ps_business_reviewer('网站模板'), 'songwenna', 'Website owner retained');
$adminIds = $pdo->query('SELECT username,id FROM admins')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (['weihuizi','wangfang','admin'] as $login) if (empty($adminIds[$login])) throw new RuntimeException('Existing finance account missing');
$wei = ['type'=>'admin','role'=>'finance','id'=>(int)$adminIds['weihuizi']];
$wang = ['type'=>'admin','role'=>'finance','id'=>(int)$adminIds['wangfang']];
$super = ['type'=>'admin','role'=>'finance','id'=>(int)$adminIds['admin']];
$_GET = [];
finance_check(ps_finance_filter($wei), 'weihuizi', 'Login without username defaults to owned work');
finance_check(ps_finance_filter($wang), 'wangfang', 'Writing finance default');
finance_check(ps_finance_filter($super), 'all', 'Super admin keeps company view');
finance_check(ps_finance_filter($wei,'all'), 'all', 'Explicit company view retained');
finance_check(ps_finance_filter(['role'=>'technical'],'wangfang'), 'all', 'Employee role cannot acquire finance filter');
finance_check(ps_actor_can_review_business($wei,'小程序开发'), true, 'Actual admin identity can review own business');
finance_check(ps_actor_can_review_business($wang,'小程序开发'), false, 'Other assigned finance cannot review');
finance_check(ps_actor_can_review_business($super,'小程序开发'), true, 'Super admin review retained');
foreach (['weihuizi'=>[1,2,4,6,7], 'wangfang'=>[3], 'songwenna'=>[5]] as $login=>$ids) {
    $actual=array_map('intval',$pdo->query('SELECT id FROM project_orders o WHERE '.ps_finance_business_condition($login).' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    finance_check($actual,$ids,'SQL filter and historical aliases '.$login);
}
finance_check((int)$pdo->query('SELECT COUNT(*) FROM project_orders o WHERE '.ps_finance_business_condition('all'))->fetchColumn(),7,'Company scope');
$_GET=['import_file'=>123];
finance_check(ps_finance_filter($wei), 'all', 'File detail keeps explicit original scope');
$_GET=[];
ps_setting_set('business_reviewers',['小程序开发'=>'wangfang'],(int)$adminIds['admin']);
finance_check(ps_business_reviewer('小程序'), 'wangfang', 'Explicit saved assignment overrides default');
finance_check(ps_actor_can_review_business($wei,'小程序开发'), false, 'Reassignment removes old review assignment immediately');
ps_setting_set('business_reviewers',['标书'=>'all'],(int)$adminIds['admin']);
finance_check(ps_actor_can_review_business($wang,'标书'), true, 'Shared finance assignment works');
finance_check((float)$pdo->query('SELECT SUM(contract_amount) FROM project_orders')->fetchColumn(),700.0,'Assignment and filtering do not change money');
echo 'PASS finance assignment ' . $n . " checks; temporary tables only\n";
