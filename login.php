<?php
require_once __DIR__ . '/includes/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $captcha  = trim($_POST['captcha'] ?? '');

    // 双重验证：第一重 用户名+密码，第二重 图形验证码
    if ($username === '' || $password === '') {
        $error = '请输入用户名和密码';
    } elseif ($captcha === '' || strcasecmp($captcha, $_SESSION['captcha'] ?? '') !== 0) {
        $error = '验证码错误，请重新输入';
    } else {
        $stmt = db()->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && $admin['password'] === md5($password)) {
            unset($_SESSION['captcha']); // 验证通过后清除验证码
            unset($_SESSION['project_user_id']);
            $_SESSION['admin_id']       = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            session_regenerate_id(true);
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        } else {
            // 项目结算合作人员账户与旧管理员账户隔离；合作人员只能进入项目模块。
            try {
                // 合作人员可用登录名（姓名拼音）或已绑定的手机号登录。
                $staffQuery = db()->prepare('SELECT id,password_hash,phone FROM project_users WHERE (username=? OR phone=?) AND is_active=1 LIMIT 1');
                $staffQuery->execute([$username, $username]);
                $staff = $staffQuery->fetch();
                if ($staff && password_verify($password, $staff['password_hash'])) {
                    unset($_SESSION['captcha']);
                    unset($_SESSION['admin_id'], $_SESSION['admin_username']);
                    $_SESSION['project_user_id'] = (int)$staff['id'];
                    session_regenerate_id(true);
                    header('Location: ' . BASE_URL . ($staff['phone'] ? '/project/index.php' : '/project/profile.php?first=1'));
                    exit;
                }
            } catch (PDOException $ignored) {
                // 项目迁移尚未执行时，保持现有管理员登录流程。
            }
            $error = '用户名或密码错误';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - 项目合作结算中心</title>
    <link href="<?php echo BASE_URL; ?>/assets/lib/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/lib/font-awesome/css/all.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/theme.css" rel="stylesheet">
    <style>
        body.app-warm { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { width: 100%; max-width: 400px; margin: 0 1rem; animation: mac-rise .5s cubic-bezier(.22,1,.36,1) both; }
        .app-warm .login-card { border-radius: 18px; background: rgba(255,255,255,.66); box-shadow: 0 0 0 .5px rgba(31,45,39,.1), 0 30px 70px -20px rgba(31,60,48,.35), 0 1px 0 rgba(255,255,255,.9) inset; }
        .login-card .card-body { padding: 36px 36px 30px; }
        .login-logo { display: inline-grid; place-items: center; width: 64px; height: 64px; border-radius: 16px; font-size: 28px; color: #fff; background: linear-gradient(145deg,#4c9a78,#2f6a52 55%,#245443); box-shadow: 0 1px 0 rgba(255,255,255,.45) inset, 0 10px 24px -6px rgba(47,106,82,.55); }
        .captcha-img { height: 38px; cursor: pointer; border-radius: 0 8px 8px 0; }
        .badge-2fa { font-size: .7em; vertical-align: middle; }
    </style>
</head>
<body class="app-warm">
    <div class="card login-card">
        <div class="card-body">
            <div class="text-center mb-4">
                <i class="fas fa-coins login-logo"></i>
                <h4 class="mt-3 font-weight-bold">项目合作结算中心</h4>
                <p class="text-muted">双重验证登录 <span class="badge badge-success badge-2fa">2FA</span></p>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="form-group">
                    <label class="small text-muted mb-1"><i class="fas fa-shield-alt text-primary"></i> 第一重：账号密码</label>
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-user"></i></span></div>
                        <input type="text" name="username" class="form-control" placeholder="用户名 / 手机号" value="<?php echo e($_POST['username'] ?? ''); ?>" autofocus>
                    </div>
                </div>
                <div class="form-group">
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-lock"></i></span></div>
                        <input type="password" name="password" class="form-control" placeholder="密码">
                    </div>
                </div>
                <div class="form-group">
                    <label class="small text-muted mb-1"><i class="fas fa-mobile-alt text-success"></i> 第二重：图形验证码</label>
                    <div class="input-group">
                        <input type="text" name="captcha" class="form-control" placeholder="请输入验证码" required style="text-transform:uppercase">
                        <div class="input-group-append">
                            <img src="captcha.php" id="captchaImg" class="captcha-img" title="点击刷新验证码" alt="验证码">
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-lg"><i class="fas fa-sign-in-alt"></i> 登 录</button>
            </form>
            <div class="text-center mt-3 text-muted small">
                管理员或项目合作人员均可使用分配的账户登录；验证码点击可刷新
            </div>
        </div>
    </div>
    <script>
    // 点击刷新验证码
    document.getElementById('captchaImg').addEventListener('click', function() {
        this.src = 'captcha.php?t=' + Date.now();
    });
    </script>
</body>
</html>
