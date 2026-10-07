<?php
require_once __DIR__ . '/../includes/ProjectApiSettings.php';
// 接口设置：域名解析 / 域名到期 / CDN 等第三方 API 凭据。仅系统管理员（admin 账号）可见。
$actor = ps_require_finance();
if (($actor['type'] ?? '') !== 'admin') { http_response_code(403); exit('仅系统管理员可查看接口设置'); }
$error = ''; $success = ''; $testResult = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $provider = (string)($_POST['provider'] ?? '');
        if (!isset(pas_providers()[$provider])) throw new RuntimeException('未知的接口类型');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save') {
            $changed = pas_save($provider, $_POST['f'] ?? [], (int)$actor['id']);
            ps_audit('setting', 0, 'api_integrations_save', $actor, ['provider' => $provider, 'fields' => $changed]); // 只记字段名，不记密钥
            $success = $changed ? pas_providers()[$provider]['title'] . ' 设置已保存（修改了 ' . count($changed) . ' 项）' : '没有修改：留空的密钥字段会保持原值';
        } elseif ($action === 'test') {
            $testResult[$provider] = pas_test($provider);
            ps_audit('setting', 0, 'api_integrations_test', $actor, ['provider' => $provider, 'ok' => $testResult[$provider][0]]);
        }
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : '操作失败，请重试'; }
}
$page_title = '接口设置';
include __DIR__ . '/../includes/header.php';
$csrf = e(ps_csrf_token());
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 管理员</div><h2>接口设置</h2><p>保存域名注册商 / 解析 / CDN 的 API 凭据，后续用于自动获取域名到期日、在系统内做域名解析和添加 CDN 域名。密钥加密保存，页面只显示末 4 位；留空表示保持原值。</p></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
<div class="alert alert-light border small mb-3"><i class="fas fa-shield-alt text-success mr-1"></i>仅系统管理员可见。密钥用系统保险库密钥加密（AES-256-GCM）后保存在数据库，不会写进日志或代码仓库。“测试连接”只读取信息，不会修改任何域名或解析记录。</div>
<?php foreach (pas_providers() as $key => $p): $tr = $testResult[$key] ?? null; $ready = true; foreach ($p['fields'] as $f => $d) if ($d[1] && !pas_is_set($key, $f) && $f !== 'tunnel_token') $ready = false; ?>
<div class="card mb-3" id="api-<?php echo e($key); ?>">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px"><span><strong><?php echo e($p['title']); ?></strong></span><span class="badge badge-<?php echo $ready ? 'success' : 'secondary'; ?>"><?php echo $ready ? '已配置' : '未配置完整'; ?></span></div>
  <div class="card-body">
    <p class="small text-muted mb-3"><?php echo e($p['desc']); ?></p>
    <?php if ($tr): ?><div class="alert alert-<?php echo $tr[0] ? 'success' : 'danger'; ?> py-2"><?php echo e($tr[1]); ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="provider" value="<?php echo e($key); ?>">
      <div class="form-row">
        <?php foreach ($p['fields'] as $f => [$label, $secret]): ?>
        <div class="form-group col-md-6">
          <label for="<?php echo e($key . '_' . $f); ?>"><?php echo e($label); ?><?php echo $secret ? ' <span class="badge badge-light border">密钥</span>' : ''; ?></label>
          <input class="form-control" id="<?php echo e($key . '_' . $f); ?>" type="<?php echo $secret ? 'password' : 'text'; ?>" name="f[<?php echo e($f); ?>]" value="<?php echo $secret ? '' : e(pas_mask($key, $f)); ?>" placeholder="<?php echo $secret ? (pas_is_set($key, $f) ? e(pas_mask($key, $f)) . '（留空保持不变）' : '尚未设置') : ''; ?>" autocomplete="new-password">
        </div>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-primary" name="action" value="save">保存</button>
      <button class="btn btn-outline-secondary ml-2" name="action" value="test" formnovalidate>测试连接（只读）</button>
    </form>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
