<?php
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectAiFallback.php';
$actor = ps_require_finance();
$error = '';
$success = '';
$testReply = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'ai_solution') {
            // AI 托底记录：停用后同类问题不再套用（会重新请 AI 或按系统规则处理）；删除即清除该条
            $solutionId = (int)($_POST['solution_id'] ?? 0);
            $op = (string)($_POST['op'] ?? '');
            if ($op === 'delete') db()->prepare('DELETE FROM project_ai_solutions WHERE id=?')->execute([$solutionId]);
            else db()->prepare("UPDATE project_ai_solutions SET status=IF(status='active','disabled','active') WHERE id=?")->execute([$solutionId]);
            ps_audit('setting', $solutionId, 'ai_solution_' . ($op === 'delete' ? 'delete' : 'toggle'), $actor, []);
            $success = $op === 'delete' ? 'AI 托底记录已删除' : 'AI 托底记录状态已切换';
        } elseif ($action === 'my_password') {
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
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 管理员</div><h2>系统设置</h2><p>管理客户联系方式的查看权限、接入 AI 助手，以及合作人员账户。只有财务 / 管理员能进入这里。</p></div><div class="project-hero-actions"><a class="btn btn-light" href="#contact">联系方式权限</a><a class="btn btn-outline-light" href="#ai">AI 接入</a><a class="btn btn-outline-light" href="<?php echo BASE_URL; ?>/project/settings.php#accounts">合作人员账户</a><a class="btn btn-outline-light" href="#ai-log">AI 托底记录</a><a class="btn btn-outline-light" href="#my-password">我的登录密码</a></div></div>
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
<?php
$aiLogFilter = (string)($_GET['ai_cat'] ?? '');
$aiLogWhere = $aiLogFilter !== '' ? ' WHERE category=' . db()->quote($aiLogFilter) : '';
try { $aiLogs = db()->query('SELECT * FROM project_ai_solutions' . $aiLogWhere . ' ORDER BY id DESC LIMIT 200')->fetchAll(); } catch (PDOException $e) { $aiLogs = []; }
$aiLabels = ps_ai_labels() + ['import_columns_error' => '表头识别失败', 'import_status_error' => '状态识别失败', 'import_kind_error' => '类型建议失败'];
?>
<div id="ai-log" class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px"><span>AI 托底记录（系统日志）</span><span class="small text-muted">系统规则处理不了时由 AI 给出方案并存档；同类问题下次直接套用，不再调用 AI</span></div><div class="card-body">
<form method="get" class="form-inline mb-2"><select name="ai_cat" class="form-control form-control-sm mr-2" onchange="this.form.submit()"><option value="">全部类别</option><?php foreach ($aiLabels as $key => $label): ?><option value="<?php echo e($key); ?>" <?php echo $aiLogFilter === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select><noscript><button class="btn btn-sm btn-outline-primary">筛选</button></noscript></form>
<div class="table-responsive"><table class="table table-sm mb-0 project-stack-table"><thead><tr><th>时间</th><th>类别</th><th>业务</th><th>遇到的问题</th><th>方案</th><th>已复用</th><th></th></tr></thead><tbody>
<?php foreach ($aiLogs as $log): $solution = json_decode($log['solution_json'], true) ?: []; ?><tr class="<?php echo $log['status'] !== 'active' || $log['source'] === 'error' ? 'text-muted' : ''; ?>">
  <td data-label="时间" class="text-nowrap small"><?php echo e(substr($log['created_at'], 0, 16)); ?></td>
  <td data-label="类别"><?php echo e($aiLabels[$log['category']] ?? $log['category']); ?><?php echo $log['source'] === 'error' ? ' <span class="badge badge-danger">失败</span>' : ($log['status'] !== 'active' ? ' <span class="badge badge-secondary">已停用</span>' : ''); ?></td>
  <td data-label="业务" class="small"><?php echo e($log['business_name'] ?: '—'); ?></td>
  <td data-label="遇到的问题" class="small" style="max-width:280px"><?php echo e(mb_strimwidth($log['problem'], 0, 160, '…')); ?></td>
  <td data-label="方案" class="small" style="max-width:320px"><?php
    if (isset($solution['mapping'])) { $parts = []; foreach ($solution['mapping'] as $key => $header) $parts[] = $key . ' ← ' . $header; echo e(implode('；', $parts)); }
    elseif (isset($solution['answer'])) echo e($solution['answer'] === 'finished' ? '已完成' : ($solution['answer'] === 'unfinished' ? '未完成' : $solution['answer']));
    elseif (isset($solution['error'])) echo e('AI 调用失败：' . $solution['error']);
    ?><?php if ($log['explanation'] !== '' && $log['source'] !== 'error'): ?><div class="text-muted"><?php echo e($log['explanation']); ?></div><?php endif; ?></td>
  <td data-label="已复用" class="small"><?php echo (int)$log['uses']; ?> 次</td>
  <td class="text-nowrap"><?php if ($log['source'] !== 'error'): ?><form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="ai_solution"><input type="hidden" name="solution_id" value="<?php echo (int)$log['id']; ?>"><input type="hidden" name="op" value="toggle"><button class="btn btn-sm btn-outline-secondary"><?php echo $log['status'] === 'active' ? '停用' : '启用'; ?></button></form> <?php endif; ?><form method="post" class="d-inline" onsubmit="return confirm('删除这条 AI 托底记录？')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="ai_solution"><input type="hidden" name="solution_id" value="<?php echo (int)$log['id']; ?>"><input type="hidden" name="op" value="delete"><button class="btn btn-sm btn-outline-danger">删除</button></form></td>
</tr><?php endforeach; ?>
<?php if (!$aiLogs): ?><tr><td colspan="7" class="text-center text-muted py-3">还没有 AI 托底记录。上传的表格系统识别不了时，会由 AI 给出方案并记在这里。</td></tr><?php endif; ?>
</tbody></table></div></div></div>
<div id="my-password" class="card mb-3"><div class="card-header">我的登录密码</div><div class="card-body">
<p class="small text-muted">财务 / 管理员账号能看到所有人的订单与报酬，默认密码是登录名（姓名拼音），请尽快改成只有自己知道的密码。</p>
<form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="my_password">
<div class="form-group col-md-3"><label for="myCurrent">当前密码</label><input class="form-control" id="myCurrent" type="password" name="current_password" required autocomplete="current-password"></div>
<div class="form-group col-md-3"><label for="myNew">新密码（至少 8 位）</label><input class="form-control" id="myNew" type="password" name="new_password" minlength="8" required autocomplete="new-password"></div>
<div class="form-group col-md-3"><label for="myConfirm">再输一次</label><input class="form-control" id="myConfirm" type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></div>
<div class="form-group col-md-3"><button class="btn btn-primary btn-block">修改密码</button></div>
</form></div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
