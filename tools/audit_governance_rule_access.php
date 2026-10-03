<?php
/** CLI 只读核对真实角色的规则页面；不写入或保存认证会话。 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=$argv[1]??dirname(__DIR__); $mode=$argv[2]??'employee'; $employeeId=(int)($argv[3]??70);
$route=$argv[4]??'alias';
$method=$argv[5]??'GET';
if (!in_array($mode,['admin','employee'],true) || !in_array($route,['alias','direct'],true) || !in_array($method,['GET','POST'],true)) throw new RuntimeException('Invalid audit mode');
$_SERVER['DOCUMENT_ROOT']=$root; $_SERVER['REQUEST_METHOD']=$method; $_SERVER['SCRIPT_NAME']='/project/'.($route==='alias'?'rules.php':'governance_rules.php');
$_GET=$route==='alias'?['domain'=>'governance']:[];
require_once $root.'/includes/ProjectKnowledge.php';
require_once $root.'/includes/ProjectGovernance.php';
require_once $root.'/includes/ProjectGovernanceRuleAccess.php';
if ($mode==='admin') {
    $admin=db()->query("SELECT id,username FROM admins WHERE username='admin'")->fetch();
    $_SESSION=['admin_id'=>(int)$admin['id'],'admin_username'=>$admin['username']];
} else {
    $q=db()->prepare('SELECT id FROM project_users WHERE employee_id=? AND is_active=1 LIMIT 1');$q->execute([$employeeId]);$userId=(int)$q->fetchColumn();
    if (!$userId) throw new RuntimeException('No active actor');
    $_SESSION=['project_user_id'=>$userId];
}
$auditActor=ps_actor(); $auditAccess=pgr_access($auditActor,pg_member($auditActor),pk_is_super($auditActor));
if($method==='POST' && $auditAccess['edit'])throw new RuntimeException('POST audit only permits actors without write access');
$expected=(int)db()->query('SELECT COUNT(*) FROM project_governance_rules'.($auditAccess['drafts']?'':" WHERE rule_state='confirmed'"))->fetchColumn();
db()->exec('SET TRANSACTION READ ONLY');db()->beginTransaction();
ob_start();
// rules.php 的领域分派会 exit；通过 shutdown 收尾仍能只输出核对摘要。
register_shutdown_function(function()use($auditAccess,$expected,$mode,$route,$method){
    $html=ob_get_clean();if(db()->inTransaction())db()->rollBack();session_abort();
    if($method==='POST') {
        if(http_response_code()!==403)throw new RuntimeException('Unauthorized rule POST was not rejected');
        echo json_encode(['mode'=>$mode,'route'=>$route,'post_denied'=>true])."\n";return;
    }
    if(strpos($html,'管理层考核规则')===false || strpos($html,'Fatal error')!==false || strpos($html,'Warning:')!==false) throw new RuntimeException('Rule rendering failed');
    $count=substr_count($html,'class="governance-card governance-rule"');
    if($count!==$expected)throw new RuntimeException('Incorrect rule visibility');
    if(!$auditAccess['edit'] && (strpos($html,'value="save_governance_rule"')!==false||strpos($html,'value="create_governance_rule"')!==false)) throw new RuntimeException('Write controls exposed');
    if(!$auditAccess['member'] && (strpos($html,'href="'.BASE_URL.'/project/governance_ideas.php"')!==false||strpos($html,'href="'.BASE_URL.'/project/governance_election.php"')!==false)) throw new RuntimeException('Private management action exposed');
    echo json_encode(['mode'=>$mode,'route'=>$route,'rules'=>$count,'can_edit'=>$auditAccess['edit'],'drafts'=>$auditAccess['drafts'],'private_actions_exposed'=>false],JSON_UNESCAPED_UNICODE)."\n";
});
require $root.'/project/'.($route==='alias'?'rules.php':'governance_rules.php');
