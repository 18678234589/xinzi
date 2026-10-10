<?php
require_once __DIR__.'/includes/RecoveryWeb.php';
$q=db()->prepare('SELECT username FROM admins WHERE id=?'); $q->execute([(int)($_SESSION['admin_id']??0)]);
if ($q->fetchColumn()!=='admin') { http_response_code(403); exit('仅超级管理员可配置找回短信'); }
$error=''; $success=''; $config=null; $logs=[];
try {
    $config=db()->query('SELECT * FROM account_recovery_config WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if (!$config) throw new RuntimeException('请先执行找回密码数据库迁移');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        recovery_check_csrf();
        $sign=trim((string)($_POST['sign_name']??'')); $template=trim((string)($_POST['template_code']??''));
        $key=trim((string)($_POST['access_key_id']??'')); $secret=trim((string)($_POST['access_key_secret']??''));
        if (strlen($key)>120 || strlen($secret)>200 || preg_match('/[\r\n]/',$key.$secret)) throw new RuntimeException('短信凭据格式无效');
        if ($key!=='' && $key!==$config['access_key_id'] && $secret==='') throw new RuntimeException('修改 AccessKey ID 时需要同时填写 Secret');
        $key=$key!==''?$key:$config['access_key_id']; $cipher=$secret!==''?pv_encrypt($secret):$config['secret_cipher'];
        if ($sign==='' || strlen($sign)>80 || !preg_match('/^SMS_[A-Za-z0-9]+$/D',$template) || strlen($template)>80) throw new RuntimeException('请填写有效签名和验证码模板 CODE');
        $enabled=isset($_POST['enabled'])?1:0;
        if ($enabled && (!$key || !$cipher || !isset($_POST['approved']))) throw new RuntimeException('启用前须配置凭据并确认验证码模板已审核，变量名为 code');
        db()->prepare('UPDATE account_recovery_config SET enabled=?,access_key_id=?,secret_cipher=?,sign_name=?,template_code=? WHERE id=1')->execute([$enabled,$key,$cipher,$sign,$template]);
        $config=db()->query('SELECT * FROM account_recovery_config WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $success='找回密码短信配置已保存';
    }
    $logs=db()->query('SELECT account_type,account_id,purpose,state,provider_code,created_at FROM account_recovery_challenges ORDER BY created_at DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $error='请先运行 migrations/apply_password_recovery.php 安装短信找回功能'; }
catch (Throwable $e) { $error=$e->getMessage(); }
recovery_head('找回密码短信配置'); recovery_messages($error,$success);
if ($config):
?>
<p class="small text-muted">阿里云短信。验证码模板与续费通知独立。凭据留空会保留当前值；验证码和密钥不会写入日志。</p>
<form method="post"><input type="hidden" name="csrf" value="<?php echo e(recovery_csrf()); ?>">
<div class="form-group"><label for="ak">AccessKey ID（<?php echo $config['access_key_id']?'已配置':'未配置'; ?>）</label><input id="ak" name="access_key_id" class="form-control" autocomplete="off" maxlength="120"></div>
<div class="form-group"><label for="secret">AccessKey Secret（<?php echo $config['secret_cipher']?'已配置':'未配置'; ?>）</label><input id="secret" name="access_key_secret" class="form-control" type="password" autocomplete="new-password" maxlength="200"></div>
<div class="form-group"><label for="sign">短信签名</label><input id="sign" name="sign_name" class="form-control" value="<?php echo e($config['sign_name']); ?>" required maxlength="80"></div>
<div class="form-group"><label for="template">验证码模板 CODE（变量 code）</label><input id="template" name="template_code" class="form-control" value="<?php echo e($config['template_code']); ?>" required maxlength="80"></div>
<label class="d-block"><input type="checkbox" name="approved" value="1"> 已确认此签名及验证码模板在阿里云审核通过</label>
<label class="d-block"><input type="checkbox" name="enabled" value="1" <?php echo $config['enabled']?'checked':''; ?>> 启用短信找回密码</label>
<button class="btn btn-success btn-block">保存配置</button></form>
<hr><h2 style="font-size:18px">最近发送记录</h2><p class="small text-muted">sent 代表接口受理，手机实际收到还需确认；unknown 不会自动重发。</p>
<div class="log"><table class="table table-sm"><thead><tr><th>账号</th><th>用途</th><th>状态</th><th>接口结果</th></tr></thead><tbody>
<?php foreach ($logs as $log): ?><tr><td><?php echo e($log['account_type'].':'.$log['account_id']); ?></td><td><?php echo e($log['purpose']); ?></td><td><?php echo e($log['state']); ?></td><td><?php echo e($log['provider_code']); ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<div class="links"><a href="<?php echo BASE_URL; ?>/recovery_phone.php">绑定找回手机号</a> · <a href="<?php echo BASE_URL; ?>/project/system.php">返回系统设置</a></div>
<?php recovery_end(); ?>
