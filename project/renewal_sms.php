<?php
require_once __DIR__.'/../includes/ProjectRenewalSms.php';
$actor=ps_require_actor();
if (!pr_is_super($actor)) { http_response_code(403); exit('只有超级管理员可以配置短信服务'); }
pr_require($actor); $error=''; $success='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    ps_check_csrf();
    try { pr_sms_save_config($_POST,$actor); $success='阿里云短信配置已保存。只会对已开启通知的客户发送续费提醒。'; }
    catch (Throwable $e) { $error=$e instanceof RuntimeException?$e->getMessage():'保存失败，请稍后重试'; }
}
$config=pr_sms_config(); $logs=db()->query('SELECT s.*,r.resource_name,o.order_no FROM project_renewal_sms s JOIN project_renewal_items r ON r.id=s.item_id JOIN project_orders o ON o.id=r.order_id ORDER BY s.id DESC LIMIT 60')->fetchAll();
$last=db()->query('SELECT created_at,summary_json FROM project_renewal_job_runs ORDER BY id DESC LIMIT 1')->fetch(); $page_title='阿里云续费短信';
include __DIR__.'/../includes/header.php';
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/renewals.css?v=20261002.2">
<main class="pr-page"><section class="pr-hero"><div><span class="pr-eyebrow">RENEWAL / 温柔提醒</span><h1>阿里云续费短信</h1><p>用审核通过的通知模板提醒客户。凭据加密保存；手机号不写入日志；只有开启通知的资源才进入自动任务。</p></div></section>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo e($error); ?></div><?php endif; ?><?php if ($success): ?><div class="alert alert-success" role="status"><?php echo e($success); ?></div><?php endif; ?>
<section class="pr-panel"><div class="pr-panel-title"><h2>服务配置</h2><a href="<?php echo BASE_URL; ?>/project/renewals.php">返回续费工作台 →</a></div>
<form method="post" class="pr-form" autocomplete="off"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="revision" value="<?php echo (int)$config['revision']; ?>">
<label>AccessKey ID<input name="access_key_id" maxlength="120" value="<?php echo e($config['access_key_id']); ?>"><small>建议创建仅有短信发送权限的 RAM 账号。</small></label>
<label>AccessKey Secret<input type="password" name="access_key_secret" maxlength="200" autocomplete="new-password" placeholder="<?php echo $config['secret_cipher']?'已加密保存，留空不更改':'填写阿里云 Secret'; ?>"><small>不会回显；更换 ID 时必须同时更新 Secret。</small></label>
<label>审核通过的短信签名<input name="sign_name" maxlength="80" value="<?php echo e($config['sign_name']); ?>"></label>
<label>审核通过的模板编号<input name="template_code" maxlength="60" placeholder="SMS_123456789" value="<?php echo e($config['template_code']); ?>"></label>
<label>每日开始时间（北京时间）<select name="send_hour"><?php for($h=9;$h<=17;$h++): ?><option value="<?php echo $h; ?>" <?php echo (int)$config['send_hour']===$h?'selected':''; ?>><?php echo $h; ?>:00</option><?php endfor; ?></select><small>18:00 后不发送；小时任务会自动检查。</small></label>
<label>每日短信上限<input name="daily_limit" type="number" min="1" max="10000" value="<?php echo (int)$config['daily_limit']; ?>"></label>
<label style="grid-column:1/-1">模板变量映射<input name="param_map" maxlength="500" value="<?php echo e($config['param_map']); ?>"><small>左侧填写模板变量名，右侧选择 resource（资源类型）、date（到期日）、days（剩余天数）。只需要日期的模板可填 {"date":"date"}。</small></label>
<label class="pr-check"><input type="checkbox" name="confirm_approved" value="1"> 我已确认签名、通知模板已审核通过，并已核对变量映射</label>
<label class="pr-check"><input type="checkbox" name="enabled" value="1" <?php echo $config['enabled']?'checked':''; ?>> 启用自动续费短信（发送会产生阿里云短信费用）</label>
<div class="pr-form-actions"><button class="pr-button">保存配置</button><span>不开启时绝不发送；请勿把账号密码、订单金额放入短信。</span></div></form></section>
<section class="pr-panel"><h2>自动提醒规则</h2><p>到期前 10、3、1 个自然日分别提醒；已续费、暂停或结束维护的资源停止旧周期提醒。日期未核实会醒目提示，默认到期日为下单日一年后。</p><p>同一客户每天最多一条，优先提醒最先到期的资源。发送明确失败最多尝试 3 次；超时、网络中断或结果不明时保留待核对记录，不盲目重发。错过的历史节点不会集中补发。</p><p>“服务商已受理”不等于客户收到，请在阿里云回执中核对实际送达结果。</p><p><a href="https://help.aliyun.com/zh/sms/getting-started/use-sms-api/" target="_blank" rel="noopener noreferrer">阿里云官方接入说明</a> · 推荐通知模板：您使用的${resource}将于${date}到期，还剩${days}天。需要续费请联系您的服务客服。</p></section>
<section class="pr-panel"><div class="pr-panel-title"><h2>最近发送记录</h2><small><?php echo $last?'最近运行：'.e($last['created_at']):'尚未执行发送（默认关闭）'; ?></small></div><div class="pr-table-wrap"><table class="pr-table"><thead><tr><th>订单 / 资源</th><th>到期 / 节点</th><th>结果</th><th>服务商代码</th><th>更新时间</th></tr></thead><tbody><?php foreach($logs as $row): ?><tr><td><strong><?php echo e($row['order_no']); ?></strong><small><?php echo e($row['resource_name']); ?></small></td><td><?php echo e($row['expires_on']); ?><small>到期前 <?php echo (int)$row['remind_days']; ?> 天</small></td><td><?php echo e(['sent'=>'服务商已受理','failed'=>'发送失败','unknown'=>'结果待核对，未自动重发','pending'=>'等待发送','sending'=>'处理中','cancelled'=>'已停止'][$row['state']]??$row['state']); ?><small>已尝试 <?php echo (int)$row['attempts']; ?> 次</small></td><td><?php echo e($row['provider_code']); ?><small><?php echo e($row['provider_request_id']); ?></small></td><td><?php echo e($row['updated_at']); ?></td></tr><?php endforeach; ?><?php if (!$logs): ?><tr><td colspan="5" class="pr-empty">暂时没有发送记录。配置并启用后，系统会在相应到期节点自动处理。</td></tr><?php endif; ?></tbody></table></div></section></main>
<?php include __DIR__.'/../includes/footer.php'; ?>
