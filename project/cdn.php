<?php
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/QiniuCdn.php';
$actor = ps_require_actor();
if (!bd_can_access($actor)) { http_response_code(403); exit('当前账户未分配 CDN 管理权限'); }
require_once __DIR__ . '/../includes/ProjectApiSettings.php';
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex');
$actorKey = $actor['type'] . ':' . $actor['id'];
$csrf = ps_csrf_token();
$error = ''; $preview = null; $previewToken = ''; $detail = null; $list = ['rows'=>[], 'marker'=>''];
$certificates = []; $certError = ''; $input = [];
$flash = $_SESSION['cdn_flash'] ?? null; unset($_SESSION['cdn_flash']);
$configured = pas_is_set('qiniu', 'access_key') && pas_is_set('qiniu', 'secret_key');
if (!isset($_SESSION['cdn_previews'])) $_SESSION['cdn_previews'] = [];
foreach ($_SESSION['cdn_previews'] as $token=>$ticket) if ($ticket['expires'] < time()) unset($_SESSION['cdn_previews'][$token]);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    ps_check_csrf();
    $input = $_POST;
    try {
        if (!$configured) throw new RuntimeException('尚未配置七牛接口，请联系管理员');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'preview') {
            $preview = qc_payload($_POST);
            $previewToken = bin2hex(random_bytes(24));
            if (count($_SESSION['cdn_previews']) >= 5) array_shift($_SESSION['cdn_previews']);
            $_SESSION['cdn_previews'][$previewToken] = ['actor'=>$actorKey, 'expires'=>time()+600, 'payload'=>$preview];
        } elseif ($action === 'create') {
            $payload = qc_ticket_take($_SESSION['cdn_previews'], (string)($_POST['token'] ?? ''), $actorKey, (string)($_POST['confirm'] ?? ''), (string)($_POST['ack'] ?? ''), time());
            ps_audit('cdn', 0, 'domain_create_requested', $actor, $payload);
            qc_create($payload);
            try { ps_audit('cdn', 0, 'domain_create_accepted', $actor, ['domain'=>$payload['name']]); }
            catch (Throwable $auditError) { error_log('CDN creation accepted; result audit failed'); }
            $_SESSION['cdn_flash'] = ['ok'=>'七牛已接受添加请求。请等待配置完成，核对源站和 CNAME 后再修改解析。'];
            header('Location: ' . BASE_URL . '/project/cdn.php?d=' . rawurlencode($payload['name']));
            exit;
        } else throw new RuntimeException('不支持此操作');
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : '操作暂未完成，请刷新域名列表查询结果';
        if (($_POST['action'] ?? '') === 'create') {
            $_SESSION['cdn_flash'] = ['error'=>$error . '。添加请求不会自动重试；请先查询域名状态，避免重复提交。'];
            header('Location: ' . BASE_URL . '/project/cdn.php');
            exit;
        }
    }
}
$name = trim((string)($_GET['d'] ?? ''));
if ($configured) {
    try {
        if ($name !== '') { $name = qc_domain($name); $detail = qc_detail($name); }
        else $list = qc_list((string)($_GET['marker'] ?? ''));
    } catch (Throwable $e) { $error = $error ?: ($e instanceof RuntimeException ? $e->getMessage() : '暂时读取失败，请稍后刷新'); }
    if ($name === '') {
        try { $certificates = qc_certificates(); }
        catch (Throwable $e) { $certError = '暂时无法读取证书目录，HTTP 添加仍可用。'; }
    }
}
$page_title = 'CDN 管理';
include __DIR__ . '/../includes/header.php';
$value = function ($key, $default='') use ($input) { return e((string)($input[$key] ?? $default)); };
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/dns.css?v=20261013-contrast">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/cdn.css?v=20261013">
<main class="dns-console cdn-console">
  <section class="dns-hero">
    <div><div class="dns-kicker">QINIU · CONTENT DELIVERY</div><h1>CDN 管理</h1><p>直接在本站添加七牛 CDN 加速域名、查看状态和 CNAME，再到本站解析管理完成接入。</p></div>
    <div class="cdn-hero-links"><a class="dns-btn ghost" href="<?php echo BASE_URL; ?>/project/domains.php">域名管理</a><a class="dns-btn ghost" href="<?php echo BASE_URL; ?>/project/dns.php">解析管理</a></div>
  </section>
  <?php if ($flash && !empty($flash['ok'])): ?><div class="dns-notice ok" role="status"><?php echo e($flash['ok']); ?></div><?php endif; ?>
  <?php if ($flash && !empty($flash['error'])): ?><div class="dns-notice error" role="alert"><?php echo e($flash['error']); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="dns-notice error" role="alert"><?php echo e($error); ?></div><?php endif; ?>
  <?php if (!$configured): ?>
  <section class="dns-card"><h2>尚未连接七牛云</h2><p class="dns-help">请管理员在“接口设置”保存七牛 AccessKey / SecretKey，然后刷新本页。普通账号不需要接触密钥。</p></section>
  <?php elseif ($detail): ?>
  <section class="dns-card"><div class="dns-card-head"><h2><?php echo e($detail['name']); ?></h2><a class="dns-btn ghost" href="cdn.php">返回列表</a></div>
    <dl class="cdn-summary"><div><dt>七牛配置状态</dt><dd><?php echo e(qc_state_label($detail['operatingState'])); ?></dd></div><div><dt>加速区域 / 协议</dt><dd><?php echo e(QC_REGIONS[$detail['geoCover']] ?? $detail['geoCover']); ?> / <?php echo e(strtoupper($detail['protocol'])); ?></dd></div><div><dt>源站</dt><dd><?php echo e($detail['source_value']); ?></dd></div><div><dt>源站检测路径</dt><dd><?php echo e($detail['test_path']); ?></dd></div></dl>
    <?php if ($detail['operatingStateDesc'] || $detail['freezeType']): ?><div class="dns-notice warn"><?php echo e($detail['operatingStateDesc'] ?: $detail['freezeType']); ?></div><?php endif; ?>
    <p class="dns-help">“配置成功”表示七牛配置完成，是否已接入还取决于 DNS 的 CNAME。处理中请稍后刷新，不要重复添加。</p>
    <a class="dns-btn ghost" href="?d=<?php echo rawurlencode($detail['name']); ?>">刷新状态</a>
  </section>
  <section class="dns-card"><h2>接入解析：将加速域名指向七牛 CNAME</h2>
    <?php if ($detail['cname']): ?>
    <div class="cdn-cname"><code><?php echo e($detail['cname']); ?></code><button type="button" class="dns-btn ghost" data-copy="<?php echo e($detail['cname']); ?>">复制 CNAME</button></div>
    <p class="dns-help">为 <?php echo e($detail['name']); ?> 添加 CNAME 记录，记录值填写上面地址。已有 A / AAAA / CNAME 请先核对，不能直接覆盖正在使用的记录；不要把回源域名也改成此 CNAME。Cloudflare 建议使用“仅 DNS”。</p>
    <?php foreach (DNS_PROVIDERS as $provider=>$label): ?><a class="dns-btn ghost" href="<?php echo BASE_URL; ?>/project/dns.php?<?php echo e(http_build_query(['cdn_domain'=>$detail['name'], 'cdn_cname'=>$detail['cname'], 'p'=>$provider], '', '&', PHP_QUERY_RFC3986)); ?>">在本站配置 <?php echo e($label); ?></a><?php endforeach; ?>
    <?php else: ?><p class="dns-help">七牛暂未返回 CNAME。请等待配置完成后刷新，暂不要修改 DNS。</p><?php endif; ?>
  </section>
  <?php elseif ($name === ''): ?>
  <?php if ($preview): $body=$preview['body']; ?>
  <section class="dns-card cdn-preview" id="cdnPreview"><h2>确认添加配置</h2>
    <dl class="cdn-summary"><div><dt>加速域名</dt><dd><?php echo e($preview['name']); ?></dd></div><div><dt>区域 / 业务 / 协议</dt><dd><?php echo e(QC_REGIONS[$body['geoCover']] . ' / ' . QC_PLATFORMS[$body['platform']] . ' / ' . strtoupper($body['protocol'])); ?></dd></div><div><dt>源站</dt><dd><?php echo e(implode(', ', $body['source']['sourceIPs'] ?? [$body['source']['sourceDomain'] ?? $body['source']['sourceQiniuBucket'] ?? ''])); ?></dd></div><div><dt>检测路径</dt><dd><?php echo e($body['source']['testURLPath']); ?></dd></div><div><dt>缓存方式</dt><dd><?php echo $body['cache']['cacheControls'][0]['type']==='follow'?'遵循源站，不忽略网址参数':'全部内容缓存 1 小时，不忽略网址参数'; ?></dd></div><?php if ($body['protocol']==='https'): ?><div><dt>HTTPS 证书</dt><dd><?php echo e($body['https']['certId']); ?></dd></div><?php endif; ?></dl>
    <p class="dns-help">此预览尚未创建域名、未修改解析。提交后七牛将检查源站和备案，启用后按七牛账号的流量计费规则计费。</p>
    <form method="post" class="cdn-confirm"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="create"><input type="hidden" name="token" value="<?php echo e($previewToken); ?>">
      <label>输入加速域名确认<input name="confirm" required autocomplete="off" placeholder="<?php echo e($preview['name']); ?>"></label>
      <label class="cdn-check"><input type="checkbox" name="ack" value="1" required>已核对源站、缓存和协议配置，确认添加</label><button class="dns-btn" type="submit">确认添加到七牛</button><a class="dns-btn ghost" href="cdn.php#cdnCreate">重新填写</a>
    </form>
  </section>
  <?php endif; ?>
  <section class="dns-card" id="cdnCreate"><h2>添加七牛 CDN 域名</h2><p class="dns-help">先添加并等待配置完成，再设置 CNAME。源站是原网站或存储空间；加速域名是给访客访问的域名，二者请分清。</p>
    <?php if ($certError): ?><div class="dns-notice warn"><?php echo e($certError); ?></div><?php endif; ?>
    <form method="post" class="cdn-form" id="cdnCreateForm"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="preview">
      <label>加速域名<input name="domain" required maxlength="253" placeholder="cdn.example.com" value="<?php echo $value('domain'); ?>" autocomplete="off"><small>填写完整域名，不含 https:// 或路径。</small></label>
      <label>业务类型<select name="platform"><?php foreach (QC_PLATFORMS as $key=>$label): ?><option value="<?php echo e($key); ?>" <?php echo ($input['platform']??'web')===$key?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></label>
      <label>加速区域<select name="geo_cover" id="cdnRegion"><?php foreach (QC_REGIONS as $key=>$label): ?><option value="<?php echo e($key); ?>" <?php echo ($input['geo_cover']??'china')===$key?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></label>
      <label id="cdnIcp">ICP 备案号<input name="register_no" maxlength="100" placeholder="该域名已备案的真实备案号" value="<?php echo $value('register_no'); ?>"><small>中国大陆 / 全球需要备案；本页不会自动办理备案。</small></label>
      <label>源站类型<select name="source_type" id="cdnSourceType"><?php foreach (['ip'=>'服务器 IP','domain'=>'源站域名','qiniuBucket'=>'七牛存储空间'] as $key=>$label): ?><option value="<?php echo e($key); ?>" <?php echo ($input['source_type']??'ip')===$key?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></label>
      <label>源站地址<input name="source_value" id="cdnSourceValue" required maxlength="500" placeholder="例如 1.2.3.4" value="<?php echo $value('source_value'); ?>"><small id="cdnSourceHelp">服务器公网 IPv4；多个地址用逗号分隔。</small></label>
      <label>源站检测路径<input name="test_path" required maxlength="1000" placeholder="/index.html" value="<?php echo $value('test_path'); ?>"><small>源站真实存在并返回 200 的小文件，添加后也要保留。</small></label>
      <label data-origin-field>回源 Host（可选）<input name="source_host" maxlength="253" placeholder="原网站绑定的域名，留空使用加速域名" value="<?php echo $value('source_host'); ?>"></label>
      <label data-origin-field>回源协议<select name="source_scheme"><option value="http" <?php echo ($input['source_scheme']??'http')==='http'?'selected':''; ?>>HTTP</option><option value="https" <?php echo ($input['source_scheme']??'http')==='https'?'selected':''; ?>>HTTPS</option></select></label>
      <label>缓存方式<select name="cache_mode"><option value="follow" <?php echo ($input['cache_mode']??'follow')==='follow'?'selected':''; ?>>遵循源站（推荐）</option><option value="static" <?php echo ($input['cache_mode']??'follow')==='static'?'selected':''; ?>>全部内容缓存 1 小时（仅纯静态内容）</option></select><small>含登录、订单或动态内容时，请由技术核对源站缓存头。</small></label>
      <label>访问协议<select name="protocol" id="cdnProtocol"><option value="http" <?php echo ($input['protocol']??'http')==='http'?'selected':''; ?>>HTTP</option><option value="https" <?php echo ($input['protocol']??'http')==='https'?'selected':''; ?>>HTTPS（需有效证书）</option></select></label>
      <label id="cdnCert">HTTPS 证书<select name="cert_id"><option value="">选择七牛账号中已有证书</option><?php foreach ($certificates as $cert): if ((int)($cert['not_after']??0)<=time()) continue; ?><option value="<?php echo e($cert['certid']??''); ?>" <?php echo ($input['cert_id']??'')===($cert['certid']??'')?'selected':''; ?>><?php echo e(($cert['name']??'').' · '.($cert['common_name']??'').' · '.date('Y-m-d',(int)$cert['not_after'])); ?></option><?php endforeach; ?></select><small>提交前会再次核对有效期及覆盖的域名。</small></label>
      <label id="cdnForceHttps" class="cdn-check"><input type="checkbox" name="force_https" value="1" <?php echo !empty($input['force_https'])?'checked':''; ?>>强制跳转 HTTPS</label>
      <div class="cdn-form-actions"><button class="dns-btn" type="submit">预览添加配置</button><span class="dns-help">预览不会创建域名或修改解析。</span></div>
    </form>
  </section>
  <section class="dns-card"><div class="dns-card-head"><h2>七牛账号下的 CDN 域名</h2><a href="cdn.php" class="dns-btn ghost">刷新 / 返回第一页</a></div>
    <form method="get" class="cdn-lookup"><label for="cdnLookup">查询完整域名</label><input id="cdnLookup" name="d" required placeholder="cdn.example.com" maxlength="253"><button class="dns-btn ghost" type="submit">查看详情</button></form>
    <div class="dns-table-wrap"><table class="dns-table"><thead><tr><th>加速域名</th><th>配置状态</th><th>区域 / 协议</th><th>CNAME</th><th></th></tr></thead><tbody>
      <?php if (!$list['rows']): ?><tr><td colspan="5" class="dns-empty"><?php echo $error?'暂未读取到列表，请稍后重试':'账号下暂未读取到 CDN 域名'; ?></td></tr><?php endif; ?>
      <?php foreach ($list['rows'] as $row): ?><tr><td><strong><?php echo e($row['name']); ?></strong></td><td><?php echo e(qc_state_label($row['operatingState'])); ?></td><td><?php echo e(QC_REGIONS[$row['geoCover']]??$row['geoCover']); ?><small><?php echo e(strtoupper($row['protocol'])); ?></small></td><td><code class="dns-value"><?php echo e($row['cname']); ?></code></td><td><a class="dns-btn ghost" href="?d=<?php echo rawurlencode($row['name']); ?>">查看 / 接入解析</a></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($list['marker']): ?><a class="dns-btn ghost" href="?marker=<?php echo rawurlencode($list['marker']); ?>">下一页</a><?php endif; ?>
  </section>
  <?php endif; ?>
</main>
<script src="<?php echo BASE_URL; ?>/assets/js/cdn.js?v=20261013" defer></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
