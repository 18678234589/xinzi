<?php
/** 使用连接级临时表，完全隔离于持久数据；连接关闭后 MySQL 自动回收。 */
if (PHP_SAPI !== 'cli' || !in_array('--isolated-temporary-tables',$argv,true)) { http_response_code(404); exit("Use --isolated-temporary-tables\n"); }
require_once __DIR__ . '/../includes/ProjectKnowledgeConnectors.php';
$db = db();
foreach(['project_kb_articles','project_kb_links','project_kb_revisions','project_kb_bookmarks','project_kb_integrations','project_kb_editors','project_kb_super_admins','project_audit_logs'] as $table) {
    $definition = $db->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM);
    $db->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$definition[1],1));
}
// 所有后续写操作只作用于以上临时表。
$categorySql = file_get_contents(__DIR__ . '/../migrations/20261002_knowledge_categories.sql');
preg_match('/CREATE TABLE IF NOT EXISTS project_kb_categories[\s\S]+?;/',$categorySql,$categoryDefinition);
$db->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',$categoryDefinition[0]));
$checks = 0;
function kbs_check($name,$actual,$expected) { global $checks; $checks++; if ($actual !== $expected) throw new RuntimeException($name . ': ' . json_encode($actual)); }
function kbs_reject($name,$callback) { $rejected=false; try{$callback();}catch(RuntimeException $e){$rejected=true;} kbs_check($name,$rejected,true); }
$super = ['actor'=>['type'=>'admin','id'=>1],'super'=>true,'managed'=>['*'],'department'=>''];
$user = ['actor'=>['type'=>'employee','id'=>12345,'employee_id'=>12345],'super'=>false,'managed'=>[],'department'=>'测试甲部门'];
$manager = ['actor'=>['type'=>'employee','id'=>22222,'employee_id'=>22222],'super'=>false,'managed'=>['测试甲部门'],'department'=>'测试甲部门'];
kbs_check('ordinary actor can create category',pk_create_category('设计灵感',$user),'设计灵感');
kbs_check('trimmed duplicate reuses category',pk_create_category(' 设计灵感 ',$manager),'设计灵感');
kbs_check('duplicate remains one row',(int)$db->query('SELECT COUNT(*) FROM project_kb_categories')->fetchColumn(),1);
pk_create_category('AI Studio',$user);
kbs_check('case insensitive duplicate uses canonical name',pk_create_category('ai studio',$manager),'AI Studio');
$private = pk_save_article(['title'=>'分类测试','body'=>'私有内容','category'=>'__new__','new_category'=>'新建文章分类','visibility'=>'private','status'=>'draft'],$user);
$privateRow = pk_article($private,$user);
kbs_check('inline article category persisted',$privateRow['category'],'新建文章分类');
kbs_check('new category preserves private scope',$privateRow['visibility'],'private');
kbs_check('new category preserves draft status',$privateRow['status'],'draft');
kbs_check('new category is reusable',in_array('新建文章分类',pk_categories(),true),true);
$privateRow['revision']=0; $privateRow['category']='__new__'; $privateRow['new_category']='冲突不应残留';
kbs_reject('stale article revision rejects new category',function()use($privateRow,$user){pk_save_article($privateRow,$user);});
kbs_check('stale save leaves no category',in_array('冲突不应残留',pk_categories(),true),false);
// 清空的都是临时表，避免影响原有版本数断言。
$db->exec('DELETE FROM project_kb_articles'); $db->exec('DELETE FROM project_kb_revisions');
$draft = ['title'=>'测试知识','body'=>'正文 腾讯云 <script>bad</script>','visibility'=>'all','status'=>'draft'];
$id=pk_save_article($draft,$user); $row=pk_article($id,$user);
kbs_check('draft owns author',pk_owner($row,$user),true);
kbs_check('draft visible to manager',pk_readable($row,$manager),true);
$row['status']='published'; $row['visibility']='all'; pk_save_article($row,$manager);
$published=pk_article($id,$user); kbs_check('manager published',$published['status'],'published');
kbs_reject('optimistic lock protects newer version',function()use($row,$manager){pk_save_article($row,$manager);});
kbs_check('both saved versions retained',(int)$db->query('SELECT COUNT(*) FROM project_kb_revisions')->fetchColumn(),2);
$doc=['title'=>'外部文档','body'=>'原始版本','url'=>'https://docs.feishu.cn/docx/abc'];
$first=pk_stage_document('feishu','docx:abc',$doc,$super);
$repeat=pk_stage_document('feishu','docx:abc',$doc,$super);
kbs_check('source repeat uses same ID',$repeat['id'],$first['id']); kbs_check('same source unchanged',$repeat['changed'],false);
$external=pk_article($first['id'],$super); kbs_check('external defaults private',$external['visibility'],'private'); kbs_check('external defaults draft',$external['status'],'draft'); kbs_check('new source never auto published body',$external['body'],'');
$external['body']=$external['incoming_body']; $external['title']=$external['incoming_title']; $external['accept_incoming']=1; $external['status']='published'; $external['visibility']='all'; pk_save_article($external,$super);
$doc['body']='外部新版本'; pk_stage_document('feishu','docx:abc',$doc,$super);
$external=pk_article($first['id'],$super); kbs_check('published body preserved',$external['body'],'原始版本'); kbs_check('incoming staged',$external['incoming_body'],'外部新版本'); kbs_check('publication scope preserved',$external['visibility'],'all');
$link=pk_save_link(['title'=>'测试工具','url'=>'https://example.com/','keywords'=>'测试工具'],$user);
kbs_reject('duplicate URL rejected',function()use($user){pk_save_link(['title'=>'重复','url'=>'https://example.com/'],$user);});
kbs_reject('keyword collision rejected',function()use($user){pk_save_link(['title'=>'冲突','url'=>'https://other.example.com/','keywords'=>'测试工具'],$user);});
kbs_reject('ordinary cannot edit link',function()use($user,$link){pk_save_link(['id'=>$link,'title'=>'修改','url'=>'https://example.com/','revision'=>1],$user);});
pk_save_link(['id'=>$link,'title'=>'主管修改','url'=>'https://example.com/','keywords'=>'测试工具','revision'=>1],$manager);
kbs_reject('manager cannot delete',function()use($manager,$link){pk_link_state($link,false,$manager);});
pk_link_state($link,false,$super); kbs_check('soft deletion removes keywords',count(pk_links()),0);
pk_link_state($link,true,$super); kbs_check('restored navigation',count(pk_links()),1);
$inlineLink=pk_save_link(['title'=>'分类工具','url'=>'https://category.example.com/','category'=>'__new__','new_category'=>'新建网址分类'],$user);
$q=$db->prepare('SELECT category FROM project_kb_links WHERE id=?'); $q->execute([$inlineLink]);
kbs_check('inline link gets category',$q->fetchColumn(),'新建网址分类');
kbs_check('link category shared with article picker',in_array('新建网址分类',pk_categories(),true),true);
kbs_reject('duplicate URL prevents category creation',function()use($user){pk_save_link(['title'=>'重复','url'=>'https://category.example.com/','category'=>'__new__','new_category'=>'重复不应残留'],$user);});
kbs_check('failed link leaves no category',in_array('重复不应残留',pk_categories(),true),false);
pk_save_integration(['provider'=>'feishu','app_id'=>'cli_fixture','app_secret'=>'fixture-not-a-real-secret'],$super);
$integration=pk_integration('feishu');kbs_check('secret encrypted, not plaintext',$integration['secret_cipher']!=='fixture-not-a-real-secret',true);kbs_check('encrypted secret decrypts',pv_decrypt($integration['secret_cipher']),'fixture-not-a-real-secret');
kbs_reject('ordinary cannot configure integration',function()use($user){pk_save_integration(['provider'=>'feishu','app_id'=>'bad'],$user);});
kbs_reject('app change requires fresh secret',function()use($super){pk_save_integration(['provider'=>'feishu','app_id'=>'changed'],$super);});
// SQL 权限表达式与纯策略核对，包含私有 / 草稿 / 跨部门。
foreach(['all','department','private'] as $visibility)foreach(['draft','published'] as $status)foreach(['测试甲部门','测试乙部门'] as $dept){
    $db->prepare('INSERT INTO project_kb_articles (title,body,category,visibility,department,status,owner_type,owner_id) VALUES (?,?,?,?,?,?,?,?)')->execute(['权限样本','正文','测试',$visibility,$dept,$status,'employee',99999]);
}
foreach([$user,$manager,$super] as $ctx){ $params=[];$sql=pk_access_sql($ctx,$params);$q=$db->prepare('SELECT id FROM project_kb_articles WHERE deleted_at IS NULL AND '.$sql.' ORDER BY id');$q->execute($params);$actual=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));$expected=[];foreach($db->query('SELECT * FROM project_kb_articles ORDER BY id')->fetchAll() as $a)if(pk_readable($a,$ctx))$expected[]=(int)$a['id'];kbs_check('SQL ACL agrees with policy',$actual,$expected); }
echo "All $checks isolated storage checks passed; only connection-local TEMPORARY tables were mutated.\n";
session_abort();
