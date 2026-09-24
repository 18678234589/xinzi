<?php
// 合作人员“我的账号”：首次登录绑定手机号（之后可用手机号登录）、修改密码。
require_once __DIR__ . '/../includes/ProjectSettlement.php';
$actor = ps_require_actor();
if ($actor['type'] !== 'employee') { header('Location: ' . BASE_URL . '/project/system.php'); exit; }
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        $account = db()->prepare('SELECT * FROM project_users WHERE id=? AND is_active=1');
        $account->execute([$actor['id']]);
        $user = $account->fetch();
        if (!$user) throw new RuntimeException('账号已停用，请联系财务');
        if ($action === 'phone') {
            $phone = preg_replace('/\s+/', '', (string)($_POST['phone'] ?? ''));
            if (!preg_match('/^1[3-9]\d{9}$/', $phone)) throw new RuntimeException('请填写 11 位手机号');
            if (!password_verify((string)($_POST['current_password'] ?? ''), $user['password_hash'])) throw new RuntimeException('当前密码不正确');
            $taken = db()->prepare('SELECT (SELECT COUNT(*) FROM project_users WHERE (phone=? OR username=?) AND id<>?) + (SELECT COUNT(*) FROM admins WHERE username=?)');
            $taken->execute([$phone, $phone, $actor['id'], $phone]);
            if ((int)$taken->fetchColumn() > 0) throw new RuntimeException('该手机号已被其他账号使用，请联系财务核对');
            db()->prepare('UPDATE project_users SET phone=? WHERE id=?')->execute([$phone, $actor['id']]);
            ps_audit('account', $actor['id'], 'bind_phone', $actor, ['phone' => substr($phone, 0, 3) . '****' . substr($phone, -4)]);
            if (isset($_GET['first'])) { header('Location: ' . BASE_URL . '/project/profile.php?bound=1'); exit; }
            $success = '手机号已保存，下次可以用手机号登录';
        } elseif ($action === 'password') {
            $new = (string)($_POST['new_password'] ?? '');
            if (!password_verify((string)($_POST['current_password'] ?? ''), $user['password_hash'])) throw new RuntimeException('当前密码不正确');
            if (strlen($new) < 8 || in_array($new, ['123456', '12345678', '123456789', '88888888', '11111111'], true)) throw new RuntimeException('新密码至少 8 位，且不能是 123456 这类简单密码');
            if (strtolower($new) === strtolower((string)$user['username'])) throw new RuntimeException('新密码不能与登录名（默认密码）相同');
            if ($new !== (string)($_POST['confirm_password'] ?? '')) throw new RuntimeException('两次输入的新密码不一致');
            db()->prepare('UPDATE project_users SET password_hash=?,password_changed_at=NOW() WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $actor['id']]);
            ps_audit('account', $actor['id'], 'change_password', $actor, []);
            $success = '密码已修改，请记住新密码';
        } else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { $error = $e instanceof PDOException ? '保存失败，请稍后再试' : $e->getMessage(); }
}
$account = db()->prepare('SELECT u.*,e.name,e.department FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE u.id=?');
$account->execute([$actor['id']]);
$me = $account->fetch();
$needPhone = empty($me['phone']);
$page_title = '我的账号';
include __DIR__ . '/../includes/header.php';
$csrf = e(ps_csrf_token());
?>
<div class="project-intake-page" style="max-width:760px">
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 我的账号</div><h2><?php echo $needPhone ? '欢迎，' . e($me['name']) : '我的账号'; ?></h2><p><?php echo $needPhone ? '第一次登录，请先绑定手机号。绑定后可以用手机号或登录名 ' . e($me['username']) . ' 登录。' : '登录名 ' . e($me['username']) . ' · 手机号 ' . e(substr($me['phone'], 0, 3) . '****' . substr($me['phone'], -4)) . '，两者都可以登录。'; ?></p></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
<?php if (isset($_GET['bound'])): ?><div class="alert alert-success">手机号绑定成功。<?php if ($me['role'] === 'governance'): ?>请先修改初始密码，再进入管理层工作台。<?php else: ?><a href="<?php echo BASE_URL; ?>/project/index.php">进入我的项目订单 →</a><?php endif; ?></div><?php endif; ?>
<?php if (!$needPhone && empty($me['password_changed_at'])): ?><div class="alert alert-warning"><?php echo $me['role'] === 'governance' ? '首次使用管理层工作台，请先把初始密码改为仅自己知道的新密码。' : '你还在使用默认密码，建议现在修改。'; ?></div><?php endif; ?>
<?php if ($me['role'] === 'governance' && !empty($me['password_changed_at'])): ?><a class="btn btn-success mb-3" href="<?php echo BASE_URL; ?>/project/governance_ideas.php">进入管理层工作台 →</a><?php endif; ?>

<div class="card mb-3"><div class="card-header"><?php echo $needPhone ? '① 绑定手机号（必填）' : '修改手机号'; ?></div><div class="card-body">
<form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="phone">
<div class="form-group col-md-5"><label for="phone">手机号</label><input class="form-control" id="phone" name="phone" inputmode="numeric" maxlength="11" pattern="1[3-9][0-9]{9}" required value="<?php echo e($_POST['phone'] ?? ($me['phone'] ?? '')); ?>" placeholder="11 位手机号"></div>
<div class="form-group col-md-4"><label for="phoneCurrent">当前密码</label><input class="form-control" id="phoneCurrent" type="password" name="current_password" required autocomplete="current-password" placeholder="<?php echo empty($me['password_changed_at']) ? ($me['role'] === 'governance' ? '首次发放的初始密码' : '默认为登录名（姓名拼音）') : ''; ?>"></div>
<div class="form-group col-md-3"><button class="btn btn-primary btn-block"><?php echo $needPhone ? '绑定并开始使用' : '保存手机号'; ?></button></div>
</form></div></div>

<?php if (!$needPhone): ?>
<div class="card mb-3"><div class="card-header">修改密码</div><div class="card-body">
<form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="password">
<div class="form-group col-md-4"><label for="pwCurrent">当前密码</label><input class="form-control" id="pwCurrent" type="password" name="current_password" required autocomplete="current-password"></div>
<div class="form-group col-md-4"><label for="pwNew">新密码（至少 8 位）</label><input class="form-control" id="pwNew" type="password" name="new_password" minlength="8" required autocomplete="new-password"></div>
<div class="form-group col-md-4"><label for="pwConfirm">再输一次</label><input class="form-control" id="pwConfirm" type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></div>
<div class="col-12"><button class="btn btn-outline-primary">修改密码</button></div>
</form></div></div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
