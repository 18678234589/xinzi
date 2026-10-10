<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/ProjectVault.php';
require_once __DIR__.'/PasswordRecovery.php';
require_once __DIR__.'/RecoverySms.php';
function recovery_csrf()
{
    if (empty($_SESSION['recovery_csrf'])) $_SESSION['recovery_csrf']=bin2hex(random_bytes(32));
    return $_SESSION['recovery_csrf'];
}
function recovery_check_csrf()
{
    if (!hash_equals(recovery_csrf(),(string)($_POST['csrf']??''))) throw new RuntimeException('页面已过期，请刷新重试');
}
function recovery_service()
{
    $config=db()->query('SELECT * FROM account_recovery_config WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if (!$config || ($config['enabled'] && (!$config['access_key_id'] || !$config['secret_cipher'] || !$config['sign_name'] || !$config['template_code']))) throw new RuntimeException('短信找回尚未配置完成，请联系管理员');
    return new PasswordRecovery(db(),pv_key(),function ($phone,$code,$id) {
        $fresh=db()->query('SELECT * FROM account_recovery_config WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (!$fresh || !$fresh['enabled']) return ['state'=>'failed','code'=>'DISABLED'];
        return recovery_sms_send($fresh,$phone,$code,'recovery-'.$id);
    });
}
function recovery_head($title)
{
    header('Cache-Control: no-store');
    header('Referrer-Policy: same-origin');
    ?><!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo e($title); ?> - 项目合作结算中心</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/lib/bootstrap/css/bootstrap.min.css">
    <style>body{background:#f4f6f3;color:#214d3d}.recovery-card{max-width:440px;margin:5vh auto;padding:28px;background:white;border-radius:18px;box-shadow:0 10px 40px #214d3d18}.btn-success{background:#257254;border-color:#257254}.form-control{height:44px}label{font-size:14px}.links{margin-top:18px;text-align:center}.log{overflow:auto;font-size:12px}h1{font-size:25px}</style></head><body><main class="recovery-card"><h1 class="text-center mb-4"><?php echo e($title); ?></h1><?php
}
function recovery_messages($error,$success)
{
    if ($error) echo '<div class="alert alert-danger" role="alert">'.e($error).'</div>';
    if ($success) echo '<div class="alert alert-success" role="status">'.e($success).'</div>';
}
function recovery_end() { echo '</main></body></html>'; }
