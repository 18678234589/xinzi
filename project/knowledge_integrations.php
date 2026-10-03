<?php
require_once __DIR__ . '/../includes/ProjectKnowledgeConnectors.php';
$actor = ps_require_actor(); $ctx = pk_context($actor);
if (!$ctx['super']) { http_response_code(403); exit('此页仅向知识库超级管理员开放。'); }
$error = ''; $success = ''; $listing = null;
$provider = (string)($_POST['provider'] ?? $_GET['provider'] ?? 'feishu');
if (!in_array($provider,['feishu','dingtalk'],true)) $provider = 'feishu';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'configure') { pk_save_integration($_POST,$ctx); $success = '应用配置已加密保存。密钥不会回显。'; }
        elseif ($action === 'test') {
            pk_access_token(pk_integration($provider));
            ps_audit('knowledge_integration',0,'connection_ok',$actor,['provider'=>$provider]);
            $success = '已取得应用令牌。下一步请读取指定知识库；令牌有效不代表已获得文档授权。';
        } elseif ($action === 'list') {
            $listing = pk_external_list($provider,(string)($_POST['space'] ?? ''),(string)($_POST['parent'] ?? ''),(string)($_POST['cursor'] ?? ''));
            ps_audit('knowledge_integration',0,'list',$actor,['provider'=>$provider,'count'=>count($listing['items'])]);
        } elseif ($action === 'sync' || $action === 'refresh') {
            if ($action === 'refresh') {
                $a = pk_article((int)($_POST['article_id'] ?? 0),$ctx);
                $provider = pk_provider($a['source_provider']);
                $ref = explode(':',$a['source_key'],2);
                if (count($ref) !== 2) throw new RuntimeException('来源标识无效。');
                $kind = $ref[0]; $reference = $ref[1];
            } else { $kind = (string)($_POST['kind'] ?? 'docx'); $reference = (string)($_POST['reference'] ?? ''); }
            $doc = pk_fetch_document($provider,$reference,$kind);
            $result = pk_stage_document($provider,$doc['key'],$doc['document'],$ctx);
            header('Location: ' . BASE_URL . '/project/knowledge_article.php?id=' . $result['id'] . ($result['changed'] ? '&edit=1&incoming=1' : '')); exit;
        } elseif ($action === 'grant_editor' || $action === 'revoke_editor') {
            $id = (int)($_POST['employee_id'] ?? 0);
            $q = db()->prepare('SELECT 1 FROM project_users WHERE employee_id=? AND is_active=1'); $q->execute([$id]);
            if (!$q->fetchColumn()) throw new RuntimeException('请选择有有效登录账号的合作人员。');
            db()->beginTransaction();
            if ($action === 'grant_editor') db()->prepare('INSERT IGNORE INTO project_kb_editors (employee_id,assigned_by) VALUES (?,?)')->execute([$id,$actor['id']]);
            else db()->prepare('DELETE FROM project_kb_editors WHERE employee_id=?')->execute([$id]);
            ps_audit('knowledge_permission',$id,$action,$actor,['employee_id'=>$id]); db()->commit();
            $success = '知识库编辑授权已更新。已有部门主管身份仍按原部门授权生效。';
        } elseif ($action === 'disconnect') {
            db()->beginTransaction();
            db()->prepare('DELETE FROM project_kb_integrations WHERE provider=?')->execute([$provider]);
            ps_audit('knowledge_integration',0,'disconnect',$actor,['provider'=>$provider]); db()->commit();
            $success = '已移除应用凭据；已导入的知识和版本仍保留。';
        } else throw new RuntimeException('操作无效。');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = $e instanceof RuntimeException ? $e->getMessage() : '操作未完成，请稍后重试。';
        try { ps_audit('knowledge_integration',0,'failed',$actor,['provider'=>$provider,'action'=>(string)($_POST['action'] ?? ''),'reason'=>$error]); } catch (Throwable $ignored) {}
    }
}
$configs = [];
foreach(db()->query('SELECT provider,app_id,operator_id,updated_at FROM project_kb_integrations')->fetchAll() as $c) $configs[$c['provider']] = $c;
$staff = db()->query('SELECT DISTINCT e.id,e.name,e.department FROM employees e JOIN project_users u ON u.employee_id=e.id AND u.is_active=1 ORDER BY e.department,e.name')->fetchAll();
$editors = db()->query('SELECT e.id,e.name,e.department FROM project_kb_editors k JOIN employees e ON e.id=k.employee_id ORDER BY e.department,e.name')->fetchAll();
$sources = db()->query('SELECT id,title,source_provider,source_key,visibility,incoming_hash,synced_at FROM project_kb_articles WHERE source_provider IS NOT NULL AND deleted_at IS NULL ORDER BY synced_at DESC LIMIT 100')->fetchAll();
$deleted = db()->query('SELECT id,title FROM project_kb_articles WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 50')->fetchAll();
$page_title = '同步与权限 · 知识库';
function pk_integration_hidden($provider,$action) { echo '<input type="hidden" name="csrf" value="' . ps_csrf_token() . '"><input type="hidden" name="provider" value="' . e($provider) . '"><input type="hidden" name="action" value="' . e($action) . '">'; }
include __DIR__ . '/../includes/header.php';
?>
<div class="kb-page"><?php pk_hero('CONNECTED, NOT EXPOSED / 安全连接','让知识流动，让权限有边界','只读取你指定的文档。外部更新先核对，再发布；不会向钉钉或飞书传送订单、报酬和平台账号密码。'); pk_tabs('integrations'); ?>
<?php if ($error): ?><div class="kb-notice kb-notice-error" role="alert"><?php echo e($error); ?></div><?php endif; ?><?php if ($success): ?><div class="kb-notice" role="status"><?php echo e($success); ?></div><?php endif; ?>
<div class="kb-integration-grid"><?php foreach(['feishu'=>'飞书','dingtalk'=>'钉钉'] as $p=>$name): $c = $configs[$p] ?? []; ?>
<section class="kb-glass kb-editor" data-no-keywords><div class="kb-card-top"><h2><?php echo $name; ?>知识连接</h2><span class="kb-pill"><?php echo $c ? '已配置' : '待配置'; ?></span></div><p class="kb-muted"><?php echo $p === 'feishu' ? '企业自建应用：需文字文档读取权限、知识空间读取权限，并把应用加入目标文档或知识空间。' : '企业内部应用：需知识库读权限、文档内容读权限；操作人 UnionId 需对目标知识库有访问权。'; ?></p>
<form method="post" autocomplete="off"><?php pk_integration_hidden($p,'configure'); ?><label><?php echo $p === 'feishu' ? 'App ID' : 'AppKey'; ?><input name="app_id" maxlength="160" required value="<?php echo e($c['app_id'] ?? ''); ?>"></label><label><?php echo $p === 'feishu' ? 'App Secret' : 'AppSecret'; ?><input type="password" name="app_secret" autocomplete="new-password" maxlength="512" <?php echo !$c ? 'required' : ''; ?> placeholder="<?php echo $c ? '已保存；留空保留原密钥' : '密钥仅加密存储，不回显'; ?>"></label><?php if ($p === 'dingtalk'): ?><label>操作人 UnionId<input name="operator_id" required maxlength="160" value="<?php echo e($c['operator_id'] ?? ''); ?>"></label><?php endif; ?><button class="kb-button">加密保存配置</button></form>
<?php if ($c): ?><div class="kb-actions"><form method="post"><?php pk_integration_hidden($p,'test'); ?><button class="kb-button kb-button-soft">测试连接</button></form><form method="post" data-kb-confirm="确认移除这组应用凭据？已经导入的文章不会删除。"><input type="hidden" name="csrf" value="<?php echo ps_csrf_token(); ?>"><input type="hidden" name="provider" value="<?php echo $p; ?>"><button class="kb-text-button" name="action" value="disconnect">移除连接</button></form></div><small class="kb-muted">更新于 <?php echo e($c['updated_at']); ?></small><?php endif; ?>
</section><?php endforeach; ?></div>
<section class="kb-glass kb-editor"><h2>读取指定文档</h2><p class="kb-muted">粘贴文字文档链接即可。首次同步默认为私有草稿；重复同步相同内容不会重复建文章，外部变化只进入待核对区。图片、表格及附件暂不搬运，请通过原文链接查看。</p><form method="post" class="kb-form-grid"><?php pk_integration_hidden($provider,'sync'); ?><label>来源<select name="provider"><option value="feishu" <?php echo $provider === 'feishu' ? 'selected' : ''; ?>>飞书</option><option value="dingtalk" <?php echo $provider === 'dingtalk' ? 'selected' : ''; ?>>钉钉</option></select></label><label>文档链接 / 文档 ID<input name="reference" required maxlength="1000" placeholder="粘贴 wiki、docx 或钉钉 nodes 链接"></label><label>飞书 ID 类型（链接会自动识别）<select name="kind"><option value="docx">新版文字文档 docx</option><option value="wiki">知识库节点 wiki</option><option value="docs">旧版文字文档 docs</option></select></label><div class="kb-form-bottom"><button class="kb-button">读取并核对 →</button></div></form></section>
<details class="kb-glass kb-editor" <?php echo $listing ? 'open' : ''; ?>><summary>浏览我授权的知识空间与目录</summary><p class="kb-muted">这里展示应用可访问的目录，不会自动把整个知识库公开。留空空间 ID 可列出空间；填入空间 ID 可列出文档。</p><form method="post" class="kb-form-grid"><?php pk_integration_hidden($provider,'list'); ?><label>来源<select name="provider"><option value="feishu" <?php echo $provider === 'feishu' ? 'selected' : ''; ?>>飞书</option><option value="dingtalk" <?php echo $provider === 'dingtalk' ? 'selected' : ''; ?>>钉钉</option></select></label><label>空间 ID<input name="space" value="<?php echo e($_POST['space'] ?? ''); ?>"></label><label>父节点 ID（可选）<input name="parent" value="<?php echo e($_POST['parent'] ?? ''); ?>"></label><div class="kb-form-bottom"><button class="kb-button kb-button-soft">读取目录</button></div></form>
<?php if ($listing): ?><div class="kb-directory"><?php foreach($listing['items'] as $item): $sid = $item['space_id'] ?? $item['workspaceId'] ?? ''; $nid = $item['node_token'] ?? $item['nodeId'] ?? ''; ?><section><div><strong><?php echo e($item['name'] ?? $item['title'] ?? '未命名'); ?></strong><small><?php echo e($listing['space_list'] ? $sid : $nid); ?></small></div><div class="kb-actions"><?php if ($listing['space_list'] || !empty($item['has_child']) || !empty($item['hasChildren']) || ($item['type'] ?? '') === 'FOLDER'): ?><form method="post"><?php pk_integration_hidden($provider,'list'); ?><input type="hidden" name="space" value="<?php echo e($sid ?: ($_POST['space'] ?? '')); ?>"><input type="hidden" name="parent" value="<?php echo e($listing['space_list'] ? ($item['rootNodeId'] ?? '') : $nid); ?>"><button class="kb-button kb-button-soft">打开目录</button></form><?php endif; ?><?php if (!$listing['space_list'] && ($item['type'] ?? '') !== 'FOLDER'): ?><form method="post"><?php pk_integration_hidden($provider,'sync'); ?><input type="hidden" name="reference" value="<?php echo e($nid); ?>"><input type="hidden" name="kind" value="wiki"><button class="kb-button kb-button-soft">读取正文</button></form><?php endif; ?></div></section><?php endforeach; ?></div><?php if (!$listing['items']): ?><p class="kb-muted">没有返回目录，请核对文档授权与应用权限。</p><?php endif; ?><?php if ($listing['next']): ?><form method="post"><?php pk_integration_hidden($provider,'list'); ?><input type="hidden" name="space" value="<?php echo e($_POST['space'] ?? ''); ?>"><input type="hidden" name="parent" value="<?php echo e($_POST['parent'] ?? ''); ?>"><input type="hidden" name="cursor" value="<?php echo e($listing['next']); ?>"><button class="kb-button kb-button-soft">下一页目录 →</button></form><?php endif; ?><?php endif; ?></details>
<section class="kb-glass kb-editor"><h2>已指定的同步文档</h2><p class="kb-muted">手动点“检查更新”即可重新读取。没有定时全量同步，也不会覆盖人工整理的正文。</p><div class="kb-directory"><?php foreach($sources as $a): ?><section><div><a href="<?php echo BASE_URL; ?>/project/knowledge_article.php?id=<?php echo (int)$a['id']; ?>"><?php echo e($a['title']); ?></a><small><?php echo $a['source_provider'] === 'feishu' ? '飞书' : '钉钉'; ?> · <?php echo e($a['synced_at']); ?><?php echo $a['incoming_hash'] ? ' · 待核对' : ''; ?></small></div><form method="post"><?php pk_integration_hidden($a['source_provider'],'refresh'); ?><input type="hidden" name="article_id" value="<?php echo (int)$a['id']; ?>"><button class="kb-button kb-button-soft">检查更新</button></form></section><?php endforeach; ?><?php if (!$sources): ?><p class="kb-muted">尚未指定文档。</p><?php endif; ?></div></section>
<details class="kb-glass kb-editor"><summary>主管 / 知识维护授权</summary><p class="kb-muted">系统自动识别已登记的部门主管。这里可额外授予知识维护权限：可核对发布非私有知识、编辑网址，但不能删除或查看 API 密钥。不会改变财务和订单权限。</p><form method="post" class="kb-form-grid"><input type="hidden" name="csrf" value="<?php echo ps_csrf_token(); ?>"><label>合作人员<select name="employee_id" required><option value="">请选择</option><?php foreach($staff as $person): ?><option value="<?php echo (int)$person['id']; ?>"><?php echo e($person['name'] . ' · ' . $person['department']); ?></option><?php endforeach; ?></select></label><div class="kb-form-bottom"><button class="kb-button kb-button-soft" name="action" value="grant_editor">授予知识维护权限</button></div></form><div class="kb-directory"><?php foreach($editors as $person): ?><section><strong><?php echo e($person['name'] . ' · ' . $person['department']); ?></strong><form method="post"><input type="hidden" name="csrf" value="<?php echo ps_csrf_token(); ?>"><input type="hidden" name="employee_id" value="<?php echo (int)$person['id']; ?>"><button class="kb-text-button" name="action" value="revoke_editor">撤销额外授权</button></form></section><?php endforeach; ?></div></details>
<?php if ($deleted): ?><details class="kb-glass kb-editor"><summary>知识回收站（可恢复）</summary><div class="kb-directory"><?php foreach($deleted as $a): ?><section><a href="<?php echo BASE_URL; ?>/project/knowledge_article.php?id=<?php echo (int)$a['id']; ?>"><?php echo e($a['title']); ?></a></section><?php endforeach; ?></div></details><?php endif; ?>
</div><?php include __DIR__ . '/../includes/footer.php'; ?>
