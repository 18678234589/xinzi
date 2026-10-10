<?php
require_once __DIR__.'/includes/RecoveryWeb.php';
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['project_user_id'])) { header('Location: '.BASE_URL.'/login.php'); exit; }
$actor=isset($_SESSION['admin_id'])?['type'=>'admin','id'=>(int)$_SESSION['admin_id']]:['type'=>'employee','id'=>(int)$_SESSION['project_user_id']];
$error=''; $success='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        recovery_check_csrf(); $service=recovery_service();
        if (($_POST['action']??'')==='send') {
            $_SESSION['recovery_bind_id']=$service->send('bind','',trim((string)($_POST['phone']??'')),session_id(),(string)($_SERVER['REMOTE_ADDR']??''),$actor,(string)($_POST['current_password']??''));
            $success='验证码已请求发送，请查看手机短信。有效期 5 分钟，60 秒内请勿重复获取。';
        } elseif (($_POST['action']??'')==='bind') {
            $_SESSION['auth_version']=$service->finish((string)($_SESSION['recovery_bind_id']??''),'bind',trim((string)($_POST['code']??'')),session_id());
            unset($_SESSION['recovery_bind_id']); session_regenerate_id(true);
            $success='找回手机号已验证并绑定，其他旧会话已退出。';
        } else { throw new RuntimeException('请求无效'); }
    } catch (PDOException $e) { $error='短信找回尚未安装完成，或手机号已被使用，请联系管理员'; }
    catch (Throwable $e) { $error=$e->getMessage(); }
}
$masked='尚未绑定'; $isSuper=false;
try {
    $q=db()->prepare('SELECT phone_cipher FROM account_recovery_contacts WHERE account_type=? AND account_id=?'); $q->execute([$actor['type'],$actor['id']]); $cipher=$q->fetchColumn();
    if ($cipher) { $phone=pv_decrypt($cipher); $masked=substr($phone,0,3).'****'.substr($phone,-4); }
    if ($actor['type']==='admin') { $q=db()->prepare('SELECT username FROM admins WHERE id=?');$q->execute([$actor['id']]);$isSuper=$q->fetchColumn()==='admin'; }
} catch (PDOException $e) {}
recovery_head('绑定找回手机号'); recovery_messages($error,$success);
?>
<p class="small text-muted">当前找回手机号：<?php echo e($masked); ?>。输入当前密码并验证手机短信后，才能绑定新的找回手机号。</p>
<form method="post"><input type="hidden" name="csrf" value="<?php echo e(recovery_csrf()); ?>"><input type="hidden" name="action" value="send">
<div class="form-group"><label for="phone">找回手机号</label><input id="phone" name="phone" class="form-control" inputmode="tel" autocomplete="tel" pattern="1[3-9][0-9]{9}" maxlength="11" required value="<?php echo e($_POST['phone']??''); ?>"></div>
<div class="form-group"><label for="currentPassword">当前登录密码</label><input id="currentPassword" name="current_password" class="form-control" type="password" autocomplete="current-password" required></div>
<button class="btn btn-success btn-block">获取绑定验证码</button></form>
<?php if (!empty($_SESSION['recovery_bind_id'])): ?>
<hr><form method="post"><input type="hidden" name="csrf" value="<?php echo e(recovery_csrf()); ?>"><input type="hidden" name="action" value="bind">
<div class="form-group"><label for="code">短信验证码</label><input id="code" name="code" class="form-control" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div>
<button class="btn btn-success btn-block">验证并绑定</button></form>
<?php endif; ?>
<div class="links"><a href="<?php echo BASE_URL; ?>/forgot_password.php">短信找回密码</a> · <a href="<?php echo BASE_URL; ?>/project/<?php echo $actor['type']==='admin'?'system':'profile'; ?>.php">返回账号设置</a><?php if ($isSuper): ?> · <a href="<?php echo BASE_URL; ?>/recovery_sms.php">短信配置</a><?php endif; ?></div>
<?php recovery_end(); ?>
