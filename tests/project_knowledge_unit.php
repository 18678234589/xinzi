<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/ProjectKnowledgeConnectors.php';
$checks = 0;
function kb_check($name,$actual,$expected) {
    global $checks; $checks++;
    if ($actual !== $expected) throw new RuntimeException($name . ': ' . json_encode($actual));
}
function kb_reject($name,$callback) {
    $rejected = false; try { $callback(); } catch (RuntimeException $e) { $rejected = true; }
    kb_check($name,$rejected,true);
}
kb_check('canonical https host',pk_url('https://SKILLHUB.cn/'),'https://skillhub.cn/');
foreach(['javascript:alert(1)','data:text/html,a','http://example.com','https://user:pass@example.com/','https://127.0.0.1/','https://example.local/','https://example.com:8443/','https://example.com\\@evil.com/'] as $url) kb_reject('reject unsafe URL',function() use($url) { pk_url($url); });
kb_reject('no shared access token URLs',function(){pk_url('https://example.com/?access_token=do-not-share');});
kb_check('case insensitive keyword dedupe',pk_keywords('腾讯云，DNSPod,dnspod'),['腾讯云','dnspod']);
kb_reject('invalid keyword HTML',function() { pk_keywords('<script>'); });
kb_check('keyword collision',pk_keyword_conflicts(['SKILLHUB'],[['id'=>1,'keywords'=>'skillhub','deleted_at'=>null]]),'skillhub');
kb_check('same row exempt',pk_keyword_conflicts(['skillhub'],[['id'=>1,'keywords'=>'skillhub','deleted_at'=>null]],1),'');
$user = ['actor'=>['type'=>'employee','id'=>1,'employee_id'=>70],'super'=>false,'managed'=>[],'department'=>'网站客服'];
$manager = $user; $manager['actor']['id']=2; $manager['managed']=['网站客服'];
$editor = $manager; $editor['managed']=['*'];
$super = ['actor'=>['type'=>'admin','id'=>1],'super'=>true,'managed'=>['*'],'department'=>''];
$finance = ['actor'=>['type'=>'admin','id'=>5],'super'=>false,'managed'=>[],'department'=>''];
$row = ['owner_type'=>'employee','owner_id'=>9,'visibility'=>'all','status'=>'published','department'=>'','deleted_at'=>null];
kb_check('all read public',pk_readable($row,$user),true);
kb_check('ordinary cannot edit others',pk_editable($row,$user),false);
kb_check('finance is not automatically knowledge manager',pk_editable($row,$finance),false);
kb_check('supervisor may edit public',pk_editable($row,$manager),true);
$row['visibility']='private'; kb_check('supervisor cannot read private',pk_readable($row,$manager),false); kb_check('editor cannot read private',pk_readable($row,$editor),false); kb_check('super may read private',pk_readable($row,$super),true);
$row['owner_id']=1; kb_check('own private visible',pk_readable($row,$user),true);
$row['owner_id']=9; $row['visibility']='department'; $row['department']='网站客服'; kb_check('same department visible',pk_readable($row,$user),true);
$row['department']='网站技术'; kb_check('other department hidden',pk_readable($row,$user),false);
$row['department']='网站客服'; $row['status']='draft'; kb_check('ordinary cannot read draft',pk_readable($row,$user),false); kb_check('supervisor can review department draft',pk_readable($row,$manager),true);
$row['deleted_at']='2026-10-02'; kb_check('deleted hidden',pk_readable($row,$manager),false); kb_check('super recycle visible',pk_readable($row,$super),true);
kb_check('feishu URL reference',pk_document_reference('feishu','https://team.feishu.cn/wiki/ABC123?from=copy'),['id'=>'ABC123','kind'=>'wiki']);
kb_check('dingtalk URL reference',pk_document_reference('dingtalk','https://alidocs.dingtalk.com/i/nodes/abc123'),['id'=>'abc123','kind'=>'node']);
kb_reject('no lookalike cloud hosts',function() { pk_document_reference('feishu','https://feishu.cn.evil.com/wiki/abc'); });
kb_reject('no unknown providers',function() { pk_provider('evil'); });
kb_reject('no path traversal IDs',function() { pk_external_id('../secrets'); });
kb_check('block text only',pk_block_text([['id'=>'secretid','text'=>'标题','children'=>[['content'=>'正文']]]]),"标题\n正文");
kb_reject('ordinary cannot publish',function() use($user) { pk_article_input(['title'=>'测试','body'=>'正文','status'=>'published'],$user); });
kb_reject('ordinary cannot target unrelated department',function() use($user) { pk_article_input(['title'=>'测试','body'=>'正文','visibility'=>'department','department'=>'其他部门'],$user); });
$data = pk_article_input(['title'=>'测试','body'=>'<script>alert(1)</script>'],$user); kb_check('body stored as inert data',$data['body'],'<script>alert(1)</script>');
kb_check('body escaped for render',strpos(e($data['body']),'<script>')===false,true);
foreach([$user,$manager,$editor,$super,$finance] as $ctx) { $params=[]; $sql=pk_access_sql($ctx,$params); kb_check('ACL placeholder count',substr_count($sql,'?'),count($params)); }
kb_check('UTF8 content transport',pk_content_post(['csrf'=>'keep','kb_content'=>base64_encode(json_encode(['body'=>'正文：腾讯云']))],['body'])['body'],'正文：腾讯云');
kb_reject('transport cannot overwrite CSRF',function(){pk_content_post(['kb_content'=>base64_encode('{"csrf":"evil"}')],['body']);});
kb_reject('invalid transport rejected',function(){pk_content_post(['kb_content'=>'%%%'],['body']);});
$packed=base64_encode(json_encode(['body'=>str_repeat('文字',2000)],JSON_UNESCAPED_UNICODE));$parts=['kb_parts_count'=>(string)ceil(strlen($packed)/768),'kb_content_sha256'=>hash('sha256',$packed)];foreach(str_split($packed,768) as $i=>$part)$parts['kb_part_'.$i]=$part;
kb_check('chunked UTF8 transport complete',pk_content_post($parts,['body'])['body'],str_repeat('文字',2000));
kb_reject('missing chunk rejected',function()use($parts){unset($parts['kb_part_0']);pk_content_post($parts,['body']);});
kb_reject('changed chunk rejected',function()use($parts){$parts['kb_part_0']='AAAA';pk_content_post($parts,['body']);});
echo "All $checks knowledge unit checks passed; no database writes.\n";
session_abort();
