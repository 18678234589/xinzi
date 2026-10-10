<?php
require_once __DIR__.'/includes/RecoveryWeb.php';
$error=''; $success=''; $sent=!empty($_SESSION['recovery_reset_id']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        recovery_check_csrf();
        $service=recovery_service();
        if (($_POST['action']??'')==='send') {
            $captcha=(string)($_POST['captcha']??'');
            $expected=(string)($_SESSION['captcha']??'');
            unset($_SESSION['captcha']);
            if ($captcha==='' || $expected==='' || strcasecmp($captcha,$expected)!==0) throw new RuntimeException('图形验证码错误，请点击图片刷新后重试');
            $username=trim((string)($_POST['username']??'')); $phone=trim((string)($_POST['phone']??''));
            if ($username==='' || strlen($username)>100) throw new RuntimeException('请输入用户名');
            $_SESSION['recovery_reset_id']=$service->send('reset',$username,$phone,session_id(),(string)($_SERVER['REMOTE_ADDR']??''));
            $sent=true;
            $success='若账号与绑定手机号匹配，将发送验证码。验证码有效期 5 分钟，60 秒内请勿重复获取。';
        } elseif (($_POST['action']??'')==='reset') {
            $service->finish((string)($_SESSION['recovery_reset_id']??''),'reset',trim((string)($_POST['code']??'')),session_id(),(string)($_POST['new_password']??''),(string)($_POST['confirm_password']??''));
            $_SESSION=[]; session_regenerate_id(true);
            $_SESSION['password_reset_success']=true;
            header('Location: '.BASE_URL.'/login.php',true,303);
            exit;
        } else { throw new RuntimeException('请求无效'); }
    } catch (PDOException $e) { $error='短信找回尚未安装完成，请联系管理员'; }
    catch (Throwable $e) { $error=$e->getMessage(); }
}
recovery_head('短信找回密码'); recovery_messages($error,$success);
?>
<p class="text-muted small">使用账号的找回手机号接收验证码。未设置手机号的账号，请先登录绑定或联系管理员设置。短信中的“注册码”就是此处的验证码。</p>
<form method="post">
<input type="hidden" name="csrf" value="<?php echo e(recovery_csrf()); ?>"><input type="hidden" name="action" value="send">
<div class="form-group"><label for="username">用户名 / 已绑定手机号</label><input id="username" name="username" class="form-control" autocomplete="username" maxlength="100" required value="<?php echo e($_POST['username']??''); ?>"></div>
<div class="form-group"><label for="phone">找回手机号</label><input id="phone" name="phone" class="form-control" inputmode="tel" autocomplete="tel" pattern="1[3-9][0-9]{9}" maxlength="11" required value="<?php echo e($_POST['phone']??''); ?>"></div>
<div class="form-group"><label for="captcha">图形验证码</label><div class="input-group"><input id="captcha" name="captcha" class="form-control" autocomplete="off" required maxlength="8"><div class="input-group-append"><img src="<?php echo BASE_URL; ?>/captcha.php" alt="图形验证码，点击刷新" title="点击刷新" id="captchaImg" style="cursor:pointer;width:120px;height:44px" tabindex="0"></div></div></div>
<button class="btn btn-success btn-block" type="submit" id="sendCode">获取短信验证码</button>
</form>
<?php if ($sent): ?>
<hr><form method="post"><input type="hidden" name="csrf" value="<?php echo e(recovery_csrf()); ?>"><input type="hidden" name="action" value="reset">
<div class="form-group"><label for="code">短信验证码</label><input id="code" name="code" class="form-control" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div>
<div class="form-group"><label for="newPassword">新密码（至少 8 位）</label><input id="newPassword" name="new_password" type="password" class="form-control" autocomplete="new-password" minlength="8" maxlength="72" required></div>
<div class="form-group"><label for="confirmPassword">确认新密码</label><input id="confirmPassword" name="confirm_password" type="password" class="form-control" autocomplete="new-password" minlength="8" maxlength="72" required></div>
<button class="btn btn-success btn-block" type="submit">验证并重置密码</button></form>
<?php endif; ?>
<div class="links"><a href="<?php echo BASE_URL; ?>/login.php">返回登录</a> · <a href="<?php echo BASE_URL; ?>/recovery_phone.php">绑定找回手机号</a></div>
<script>
(function(){var img=document.getElementById('captchaImg');function refresh(){img.src='<?php echo BASE_URL; ?>/captcha.php?t='+Date.now();}img.addEventListener('click',refresh);img.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();refresh();}});
var button=document.getElementById('sendCode'),last=<?php echo (int)($_SESSION['recovery_last_send']??0); ?>,remaining=Math.max(0,60-Math.floor(Date.now()/1000-last));
<?php if ($success && $sent): $_SESSION['recovery_last_send']=time(); ?>remaining=60;<?php endif; ?>
if(remaining>0){button.disabled=true;var timer=setInterval(function(){button.textContent=remaining+'秒后可重新获取';remaining--;if(remaining<0){clearInterval(timer);button.disabled=false;button.textContent='获取短信验证码';}},1000);}})();
</script>
<?php recovery_end(); ?>
