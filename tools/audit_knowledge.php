<?php
// CLI 只读页面核对；不持久化认证会话，静态 QA 导出清除 CSRF 值。
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? dirname(__DIR__);
$page = $argv[2] ?? 'knowledge_links.php';
$mode = $argv[3] ?? 'employee';
if (!in_array($page,['knowledge.php','knowledge_article.php','knowledge_links.php','knowledge_categories.php','knowledge_rules.php','knowledge_integrations.php','knowledge_keywords.php'],true)) throw new RuntimeException('Unsupported page');
$_SERVER['DOCUMENT_ROOT']=$root; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['SCRIPT_NAME']='/project/'.$page;
require_once $root.'/includes/ProjectKnowledge.php';
if($mode==='admin') {
    $admin=db()->query("SELECT id,username FROM admins WHERE username='admin'")->fetch();
    $_SESSION=['admin_id'=>(int)$admin['id'],'admin_username'=>$admin['username']];
}else{
    $q=db()->prepare('SELECT u.id FROM project_users u WHERE u.employee_id=? AND u.is_active=1 LIMIT 1');$q->execute([(int)($argv[4]??70)]);$id=(int)$q->fetchColumn();
    if(!$id)throw new RuntimeException('No active account');
    $_SESSION=['project_user_id'=>$id];
}
$_GET=[];
$auditPage=$page; $auditMode=$mode;
db()->exec('SET TRANSACTION READ ONLY'); db()->beginTransaction();
ob_start(); require $root.'/project/'.$page; $html=ob_get_clean();
if(db()->inTransaction())db()->rollBack(); session_abort();
if($auditPage==='knowledge_keywords.php') {
    $j=json_decode($html,true); if(!is_array($j)||count($j['links']??[])<10)throw new RuntimeException('Keyword feed invalid');
    echo json_encode(['page'=>$auditPage,'keywords'=>count($j['links'])],JSON_UNESCAPED_UNICODE)."\n";exit;
}
if(strpos($html,'共创知识库')===false)throw new RuntimeException('Missing knowledge navigation');
if(strpos($html,'Fatal error')!==false||strpos($html,'Warning:')!==false)throw new RuntimeException('Page emitted errors');
if($auditPage==='knowledge_links.php') {
    if(substr_count($html,'class="kb-glass kb-link-card"')<10)throw new RuntimeException('Missing seeded links');
    if($auditMode==='employee' && strpos($html,'value="delete"')!==false)throw new RuntimeException('Delete exposed to cooperator');
}
if($auditPage==='knowledge_categories.php' && (strpos($html,'共享分类库')===false || strpos($html,'name="name"')===false)) throw new RuntimeException('Category creation UI missing');
if(in_array($auditPage,['knowledge_links.php','knowledge_article.php'],true) && strpos($html,'data-kb-category-field')===false) throw new RuntimeException('Category picker missing');
if(strpos($html,'secret_cipher')!==false||preg_match('/value="v1:[A-Za-z0-9+\/=]+"/',$html))throw new RuntimeException('Cipher exposed');
$export = $argv[5] ?? '';
if($export!=='') {
    $safe=preg_replace('/(name="csrf" value=")[^"]*/','$1',$html);
    $safe=str_replace(['href="/assets/','src="/assets/'],['href="http://127.0.0.1:8080/assets/','src="http://127.0.0.1:8080/assets/'],$safe);
    // 静态预览仅用于布局和本地交互；禁止提交。
    $safe=str_replace('method="post"','method="post" onsubmit="return false"',$safe);
    $safe=str_replace('data-keywords-url="/project/knowledge_keywords.php"','data-skip-keywords="1"',$safe);
    file_put_contents($export,$safe);
}
echo json_encode(['page'=>$auditPage,'mode'=>$auditMode,'bytes'=>strlen($html),'links'=>substr_count($html,'kb-link-main'),'private_credentials_exposed'=>false],JSON_UNESCAPED_UNICODE)."\n";
