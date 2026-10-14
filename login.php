<?php
require_once __DIR__ . '/includes/auth.php';

$error = '';
$passwordResetSuccess = !empty($_SESSION['password_reset_success']);
unset($_SESSION['password_reset_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $captcha  = trim($_POST['captcha'] ?? '');

    // 验证码一次有效：不论填对填错都立即作废，必须重新获取，不能用同一个验证码连续试密码。
    $expectedCaptcha = (string)($_SESSION['captcha'] ?? '');
    unset($_SESSION['captcha']);

    // 双重验证：第一重 用户名+密码，第二重 图形验证码
    if ($username === '' || $password === '') {
        $error = '请输入用户名和密码';
    } elseif (auth_login_locked($username)) {
        // 锁定期间连正确密码也不接受，避免攻击者借此判断是否猜中。
        $error = '登录尝试过于频繁，请 ' . AUTH_THROTTLE_WINDOW_MINUTES . ' 分钟后再试';
    } elseif ($captcha === '' || $expectedCaptcha === '' || strcasecmp($captcha, $expectedCaptcha) !== 0) {
        auth_login_failed($username, false);
        $error = '验证码错误，请重新输入';
    } else {
        $stmt = db()->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && auth_password_verify($password, $admin['password'])) {
            auth_login_succeeded($username);
            auth_password_upgrade($admin['id'], $password, $admin['password'], $admin['username']);
            unset($_SESSION['project_user_id']);
            $_SESSION['admin_id']       = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['auth_version'] = (int)($admin['auth_version'] ?? 0);
            $_SESSION['vault_pw_ok'] = auth_password_is_strong($password);
            session_regenerate_id(true);
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        } else {
            // 项目结算合作人员账户与旧管理员账户隔离；合作人员只能进入项目模块。
            try {
                // 合作人员可用登录名（姓名拼音）或已绑定的手机号登录。
                $staffQuery = db()->prepare('SELECT * FROM project_users WHERE (username=? OR phone=?) AND is_active=1 LIMIT 1');
                $staffQuery->execute([$username, $username]);
                $staff = $staffQuery->fetch();
                if ($staff && password_verify($password, $staff['password_hash'])) {
                    auth_staff_login_seen($staff['id']);
                    auth_login_succeeded($username);
                    unset($_SESSION['admin_id'], $_SESSION['admin_username']);
                    $_SESSION['project_user_id'] = (int)$staff['id'];
                    $_SESSION['auth_version'] = (int)($staff['auth_version'] ?? 0);
                    $_SESSION['vault_pw_ok'] = auth_password_is_strong($password);
                    unset($_SESSION['rip_shown']); // 每次登录都重新提醒补录续费资料
                    session_regenerate_id(true);
                    $destination = auth_staff_destination($staff);
                    header('Location: ' . BASE_URL . $destination);
                    exit;
                }
            } catch (PDOException $ignored) {
                // 项目迁移尚未执行时，保持现有管理员登录流程。
            }
            auth_login_failed($username);
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
        .pw-eye { display: inline-flex; align-items: center; justify-content: center; width: 44px; border: 1px solid #ced4da; border-left: 0; border-radius: 0 8px 8px 0; background: #fff; color: #5f6f68; cursor: pointer; }
        #loginPassword { border-top-right-radius: 0 !important; border-bottom-right-radius: 0 !important; }
        .pw-eye:hover, .pw-eye:focus { color: #1f6a52; background: #f3f8f5; box-shadow: none; outline: 0; }
        .pw-eye:focus-visible { outline: 2px solid #2f7a67; outline-offset: -2px; }
        .pw-eye[aria-pressed="true"] { color: #1f6a52; }
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
            <?php if ($passwordResetSuccess): ?>
                <div class="alert alert-success" role="status">密码已重置，请使用新密码登录。</div>
            <?php endif; ?>
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
                        <input type="password" name="password" id="loginPassword" class="form-control" placeholder="密码" autocomplete="current-password">
                        <div class="input-group-append"><button type="button" class="btn pw-eye" id="pwEye" aria-label="显示密码" aria-pressed="false" title="显示 / 隐藏密码"><i class="fas fa-eye" aria-hidden="true"></i></button></div>
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
            <div class="text-center mt-3"><a href="<?php echo BASE_URL; ?>/forgot_password.php">忘记密码？短信找回</a></div>
            <div class="text-center mt-3 text-muted small">
                首次使用请用姓名小写拼音账号和初始密码登录，再绑定手机号、修改密码。已改密码的账号使用自己的新密码；验证码点击可刷新。
            </div>
        </div>
    </div>
    <script>
    // 小眼睛：显示 / 隐藏密码
    (function () {
        var input = document.getElementById('loginPassword'), btn = document.getElementById('pwEye');
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? '隐藏密码' : '显示密码');
            btn.firstElementChild.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
            input.focus();
        });
    })();
    // 点击刷新验证码
    document.getElementById('captchaImg').addEventListener('click', function() {
        this.src = 'captcha.php?t=' + Date.now();
    });
    </script>
</body>
</html>
