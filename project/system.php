<?php
require_once __DIR__ . '/../includes/ProjectBusiness.php';
$actor = ps_require_finance();
$error = '';
$success = '';
$testReply = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'my_password') {
            // 财务 / 管理员修改自己的登录密码（原系统 admins 表沿用 MD5 校验）
            $me = db()->prepare('SELECT username,password FROM admins WHERE id=?');
            $me->execute([$actor['id']]);
            $admin = $me->fetch();
            $new = (string)($_POST['new_password'] ?? '');
            if (!$admin || $admin['password'] !== md5((string)($_POST['current_password'] ?? ''))) throw new RuntimeException('当前密码不正确');
            if (strlen($new) < 8 || in_array($new, ['123456', '12345678', '123456789', '88888888', '11111111'], true)) throw new RuntimeException('新密码至少 8 位，且不能是 123456 这类简单密码');
            if (strtolower($new) === strtolower((string)$admin['username'])) throw new RuntimeException('新密码不能与登录名（默认密码）相同');
            if ($new !== (string)($_POST['confirm_password'] ?? '')) throw new RuntimeException('两次输入的新密码不一致');
            db()->prepare('UPDATE admins SET password=? WHERE id=?')->execute([md5($new), $actor['id']]);
            ps_audit('admin', $actor['id'], 'change_password', $actor, []);
            $success = '登录密码已修改，请记住新密码';
        } elseif ($action === 'contact') {
            $policy = [];
            foreach (array_keys(ps_contact_roles()) as $role) {
                $value = (string)($_POST['contact'][$role] ?? '');
                if (!in_array($value, ['participant', 'masked'], true)) throw new RuntimeException('联系方式权限选项无效');
                $policy[$role] = $value;
            }
            ps_setting_set('contact_visibility', $policy, $actor['id']);
            ps_audit('setting', 0, 'contact_visibility', $actor, $policy);
            $success = '客户联系方式权限已保存，立即生效';
        } elseif (in_array($action, ['ai_save', 'ai_test'], true)) {
            $current = ps_ai_config();
            $baseUrl = trim((string)($_POST['base_url'] ?? ''));
            $model = trim((string)($_POST['model'] ?? ''));
            $key = trim((string)($_POST['api_key'] ?? ''));
            if ($key === '') $key = $current['api_key']; // 留空 = 保留原密钥
            if ($baseUrl !== '' && !preg_match('~^https?://[^\s]+$~i', $baseUrl)) throw new RuntimeException('接口地址须以 http:// 或 https:// 开头');
            if (mb_strlen($baseUrl) > 300 || mb_strlen($model) > 100 || strlen($key) > 500) throw new RuntimeException('配置内容过长');
            $config = ['enabled' => isset($_POST['enabled']), 'base_url' => $baseUrl, 'api_key' => $key, 'model' => $model];
            if ($action === 'ai_test') {
                $started = microtime(true);
                $reply = ps_ai_chat([['role' => 'user', 'content' => '这是连接测试，请只回复：连接成功']], 200, $config);
                $testReply = ['reply' => mb_substr($reply, 0, 200), 'ms' => (int)round((microtime(true) - $started) * 1000)];
                $success = '连接成功（配置尚未保存，确认无误后点“保存 AI 设置”）';
            } else {
                if ($config['enabled'] && ($baseUrl === '' || $key === '' || $model === '')) throw new RuntimeException('启用 AI 前请填写接口地址、密钥和模型名称');
                ps_setting_set('ai', $config, $actor['id']);
                ps_audit('setting', 0, 'ai', $actor, ['enabled' => $config['enabled'], 'base_url' => $baseUrl, 'model' => $model, 'key_changed' => $key !== $current['api_key']]);
                $success = 'AI 设置已保存' . ($config['enabled'] ? '，录单页已出现“AI 智能识别”' : '（当前未启用）');
            }
        } else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { $error = $e instanceof PDOException ? '保存失败，请确认已执行数据库迁移（php migrations/apply_project.php）' : $e->getMessage(); }
}
$policy = ps_contact_policy();
$ai = ps_ai_config();
$formAi = $_SERVER['REQUEST_METHOD'] === 'POST' && ($error !== '' || $testReply) ? ['enabled' => isset($_POST['enabled']), 'base_url' => (string)($_POST['base_url'] ?? ''), 'model' => (string)($_POST['model'] ?? '')] : $ai;
$page_title = '系统设置';
include __DIR__ . '/../includes/header.php';
$csrf = e(ps_csrf_token());
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 管理员</div><h2>系统设置</h2><p>管理客户联系方式的查看权限、接入 AI 助手，以及合作人员账户。只有财务 / 管理员能进入这里。</p></div><div class="project-hero-actions"><a class="btn btn-light" href="#contact">联系方式权限</a><a class="btn btn-outline-light" href="#ai">AI 接入</a><a class="btn btn-outline-light" href="<?php echo BASE_URL; ?>/project/settings.php#accounts">合作人员账户</a><a class="btn btn-outline-light" href="#my-password">我的登录密码</a></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

<div id="contact" class="card mb-3"><div class="card-header">客户联系方式权限</div><div class="card-body">
<p class="text-muted small">手机号、微信号、邮箱在页面上打码显示为 <code>155***3252</code>、<code>ab***23</code>，数据库保存原文。财务 / 管理员始终看完整信息；技术与客服只能打开自己参与的订单。</p>
<form method="post"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="contact">
<div class="table-responsive"><table class="table mb-3"><thead><tr><th>角色</th><th>本单参与人看完整，其他人打码</th><th>始终打码</th></tr></thead><tbody>
<tr><td><strong>财务 / 管理员</strong></td><td colspan="2" class="text-muted">始终查看完整联系方式</td></tr>
<?php foreach (ps_contact_roles() as $role => $label): ?><tr><td><strong><?php echo e($label); ?></strong></td><?php foreach (['participant', 'masked'] as $option): ?><td><label class="mb-0"><input type="radio" name="contact[<?php echo e($role); ?>]" value="<?php echo $option; ?>" <?php echo $policy[$role] === $option ? 'checked' : ''; ?>> <?php echo $option === 'participant' ? '可以联系客户' : '需要时找客服 / 财务要号码'; ?></label></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
<button class="btn btn-primary">保存权限</button></form></div></div>

<div id="ai" class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span>AI 接入（OpenAI 兼容接口）</span><span class="badge badge-<?php echo ps_ai_ready() ? 'success' : 'secondary'; ?>"><?php echo ps_ai_ready() ? '已启用' : '未启用'; ?></span></div><div class="card-body">
<p class="text-muted small">支持 OpenAI、New API / One API 中转等兼容 <code>/v1/chat/completions</code> 的服务。启用后，合作人员在录单页可以粘贴淘宝 / 微信订单或聊天记录，由 AI 自动填写订单号、售价、买家、业务等字段（只填空白栏，保存前可修改）。粘贴内容会发送到这里配置的 AI 服务。</p>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
<div class="form-row">
<div class="form-group col-md-5"><label for="aiBase">接口地址</label><input class="form-control" id="aiBase" name="base_url" value="<?php echo e($formAi['base_url']); ?>" placeholder="https://token.example.com（自动补 /v1）"></div>
<div class="form-group col-md-4"><label for="aiKey">API Key</label><input class="form-control" id="aiKey" type="password" name="api_key" value="" placeholder="<?php echo $ai['api_key'] !== '' ? '已保存 ' . e(ps_ai_mask_key($ai['api_key'])) . '，留空不修改' : 'sk-...'; ?>" autocomplete="new-password"></div>
<div class="form-group col-md-3"><label for="aiModel">模型名称</label><input class="form-control" id="aiModel" name="model" value="<?php echo e($formAi['model']); ?>" placeholder="如 codemodel"></div>
</div>
<div class="d-flex flex-wrap align-items-center" style="gap:12px"><label class="mb-0"><input type="checkbox" name="enabled" value="1" <?php echo $formAi['enabled'] ? 'checked' : ''; ?>> 启用 AI 助手</label><button class="btn btn-outline-primary" name="action" value="ai_test">测试连接</button><button class="btn btn-primary" name="action" value="ai_save">保存 AI 设置</button></div>
<?php if ($testReply): ?><div class="alert alert-success mt-3 mb-0 small"><strong>模型回复（<?php echo (int)$testReply['ms']; ?> ms）：</strong><?php echo e($testReply['reply']); ?></div><?php endif; ?>
</form></div></div>

<div class="dash-actions mb-3">
    <a class="dash-action" href="<?php echo BASE_URL; ?>/project/settings.php#accounts"><i class="fas fa-user-lock"></i><span>合作人员账户<small>开通技术 / 客服登录</small></span></a>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/project/settings.php#roles"><i class="fas fa-id-badge"></i><span>岗位对照<small>外包前端、售后等</small></span></a>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/project/rules.php"><i class="fas fa-percent"></i><span>规则中心<small>逐单分成与月度奖励</small></span></a>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/project/settings.php#cost-center"><i class="fas fa-layer-group"></i><span>成本中心<small>程序套餐与标准价</small></span></a>
</div>
</div>
<div id="my-password" class="card mb-3"><div class="card-header">我的登录密码</div><div class="card-body">
<p class="small text-muted">财务 / 管理员账号能看到所有人的订单与报酬，默认密码是登录名（姓名拼音），请尽快改成只有自己知道的密码。</p>
<form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="my_password">
<div class="form-group col-md-3"><label for="myCurrent">当前密码</label><input class="form-control" id="myCurrent" type="password" name="current_password" required autocomplete="current-password"></div>
<div class="form-group col-md-3"><label for="myNew">新密码（至少 8 位）</label><input class="form-control" id="myNew" type="password" name="new_password" minlength="8" required autocomplete="new-password"></div>
<div class="form-group col-md-3"><label for="myConfirm">再输一次</label><input class="form-control" id="myConfirm" type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></div>
<div class="form-group col-md-3"><button class="btn btn-primary btn-block">修改密码</button></div>
</form></div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
