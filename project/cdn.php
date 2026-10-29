<?php
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/QiniuCdn.php';
require_once __DIR__ . '/../includes/QiniuCdnTraffic.php';
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
// 流量只读本地表（后台任务同步），表还没建或没有数据时页面照常显示。
$traffic = []; $series = []; $overview = null; $trafficNote = ''; $dnsInfo = [];
if ($configured) {
    try {
        if ($detail) { $series = qt_series(db(), $detail['name'], 30); $dnsInfo = qt_dns_map(db(), [$detail['name']]); }
        else {
            $dnsInfo = qt_dns_map(db(), array_column($list['rows'], 'name'));
            $traffic = qt_summary(db(), array_column($list['rows'], 'name'));
            $overview = qt_overview(db());
            if (!$overview['updated_at']) { $overview = null; $trafficNote = '流量数据正在首次同步，稍后刷新即可看到。'; }
        }
    } catch (Throwable $e) { $trafficNote = '流量数据暂时无法读取。'; }
}
$page_title = 'CDN 管理';
include __DIR__ . '/../includes/header.php';
$value = function ($key, $default='') use ($input) { return e((string)($input[$key] ?? $default)); };
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/dns.css?v=20261013-contrast">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/cdn.css?v=20261029c">
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
  <?php
    $known = array_filter($series, function ($d) { return $d['known']; });
    $maxFlux = max(1, max(array_column($series, 'flux')));
    $sum7 = array_sum(array_column(array_slice($series, -7), 'flux')); $sum30 = array_sum(array_column($series, 'flux'));
  ?>
  <section class="dns-card cdn-traffic"><div class="dns-card-head"><h2>流量（近 30 天）</h2></div>
    <?php if (!$known): ?><p class="dns-help"><?php echo e($trafficNote ?: '暂无流量数据。新域名接入后一般几十分钟内出现；七牛的统计本身有延迟。'); ?></p>
    <?php else: ?>
    <dl class="cdn-summary"><div><dt>近 7 天流量</dt><dd><?php echo e(qt_bytes($sum7)); ?></dd></div><div><dt>近 30 天流量</dt><dd><?php echo e(qt_bytes($sum30)); ?></dd></div></dl>
    <div class="cdn-bars" role="img" aria-label="近 30 天每日流量柱状图">
      <?php foreach ($series as $d): ?><span class="cdn-bar" title="<?php echo e($d['day'] . '：' . qt_bytes($d['flux']) . '，峰值带宽 ' . qt_bps($d['bps'])); ?>"><i style="height:<?php echo max(2, (int)round($d['flux'] / $maxFlux * 100)); ?>%"></i></span><?php endforeach; ?>
    </div>
    <div class="dns-table-wrap"><table class="dns-table"><thead><tr><th>日期</th><th>流量</th><th>峰值带宽</th></tr></thead><tbody>
      <?php foreach (array_reverse(array_slice($series, -14)) as $d): ?><tr><td><?php echo e($d['day']); ?></td><td><?php echo $d['known'] ? e(qt_bytes($d['flux'])) : '<span class="dns-help">—</span>'; ?></td><td><?php echo $d['known'] ? e(qt_bps($d['bps'])) : '<span class="dns-help">—</span>'; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="dns-help">数据来自七牛用量统计，后台每 30 分钟同步，当天数字未走完会偏小；仅供参考，计费以七牛账单为准。</p>
    <?php endif; ?>
  </section>
  <section class="dns-card"><h2>接入解析：将加速域名指向七牛 CNAME</h2>
    <?php $di = $dnsInfo[strtolower($detail['name'])] ?? null; ?>
    <p class="dns-help"><strong>当前公网解析：</strong><?php echo $di ? '<span class="cdn-dns ' . e($di['state']) . '">' . e(qt_dns_label($di['state'])) . '</span> ' . e($di['resolved']) . '（' . e(date('m-d H:i', (int)$di['checked_at'])) . ' 检查，每 30 分钟自动更新）' : '尚未检查，稍后刷新。'; ?></p>
    <?php if ($detail['cname']): ?>
    <p class="dns-help"><strong>应解析到：</strong>为 <?php echo e($detail['name']); ?> 添加一条 <strong>CNAME</strong> 记录，记录值填下面这个七牛地址：</p>
    <div class="cdn-cname"><code><?php echo e($detail['cname']); ?></code><button type="button" class="dns-btn ghost" data-copy="<?php echo e($detail['cname']); ?>">复制 CNAME</button></div>
    <p class="dns-help">为 <?php echo e($detail['name']); ?> 添加 CNAME 记录，记录值填写上面地址。已有 A / AAAA / CNAME 请先核对，不能直接覆盖正在使用的记录；不要把回源域名也改成此 CNAME。Cloudflare 建议使用“仅 DNS”。</p>
    <?php foreach (DNS_PROVIDERS as $provider=>$label): ?><a class="dns-btn ghost" href="<?php echo BASE_URL; ?>/project/dns.php?<?php echo e(http_build_query(['cdn_domain'=>$detail['name'], 'cdn_cname'=>$detail['cname'], 'p'=>$provider], '', '&', PHP_QUERY_RFC3986)); ?>">在本站配置 <?php echo e($label); ?></a><?php endforeach; ?>
    <?php else: ?><p class="dns-help">七牛暂未返回 CNAME。请等待配置完成后刷新，暂不要修改 DNS。</p><?php endif; ?>
  </section>
  <?php elseif ($name === ''): ?>
  <details class="dns-card cdn-guide" <?php echo $preview ? '' : 'open'; ?>><summary><h2 style="display:inline">操作流程：从解析域名到接入 CDN（共 7 步）</h2></summary>
    <ol class="dns-help">
      <li><strong>准备</strong>：分清两个域名——<em>加速域名</em>是给访客访问的（如 cdn.example.com），<em>源站</em>是原网站所在的服务器 IP、源站域名或七牛存储空间。加速域名需要已有解析：先到<a href="<?php echo BASE_URL; ?>/project/dns.php">解析管理</a>确认它所在的域名已经能管理解析，没有就先添加；还没有域名的，到<a href="<?php echo BASE_URL; ?>/project/domains.php">域名管理</a>注册。中国大陆 / 全球加速必须已备案，备案号在第 2 步填写；本页不办理备案。</li>
      <li><strong>确认源站可用</strong>：源站要能从公网打开，并准备一个确实存在、返回 200 的小文件（如 /index.html）作为“检测路径”，添加后也要保留。源站只能 HTTPS 访问时，回源协议选 HTTPS。</li>
      <li><strong>添加加速域名</strong>：在下面“添加七牛 CDN 域名”填写加速域名、区域、源站、检测路径，选 HTTP 或 HTTPS（HTTPS 需先选七牛账号里未过期、覆盖该域名的证书），点“预览添加配置”，核对后输入加速域名并勾选确认提交。预览阶段不会创建域名，也不会改解析。</li>
      <li><strong>等待配置完成</strong>：提交后进入详情页，状态变为“配置成功”后才能接入，一般几分钟；处理中请刷新，不要重复添加。如果提示备案、源站检测失败或额度问题，按提示处理后再提交。</li>
      <li><strong>设置解析（解析到哪）</strong>：七牛会给每个加速域名分配一个专属 CNAME 地址，形如 <code>cdn-example-com-xxxxxxx.qiniudns.com</code>——<em>加速域名要 CNAME 解析到这个地址</em>，不是解析到源站 IP。这个地址在本页列表的「应解析到（七牛 CNAME）」列和域名详情页里都能看到（详情页可一键复制），添加成功后一般几分钟内就会出现。拿到后：在详情页点“在本站配置 DNSPod”（或 Cloudflare），把<em>加速域名</em>这条记录的类型设为 <strong>CNAME</strong>、记录值填上面的七牛地址；Cloudflare 选“仅 DNS”（不要开代理）。已有 A / AAAA / CNAME 记录要先核对，不能直接覆盖正在使用的记录；回源域名不要改成该 CNAME。改之前先记下原记录值，出问题可以改回。</li>
      <li><strong>验证</strong>：解析生效需要几分钟，最长观察约 6 分钟。本页列表的「当前公网解析」列会显示这个域名现在实际解析到哪（每 30 分钟自动检查一次），显示“已指向七牛”说明接入完成；显示“指向其他地址”或“未解析”说明解析还没改对，对照上面的七牛地址再检查。然后访问加速域名，核对内容和跳转（301 也要看跳转地址）与源站一致；不一致或打不开，把解析恢复为原记录，再检查源站和 CDN 配置。</li>
      <li><strong>日常查看</strong>：列表里的「近 7 天流量 / 今日」可点击排序，点进域名详情能看近 30 天流量和峰值带宽；今日流量明显高于平时会标红“异常增长”，请核对是否正常活动或被盗刷。</li>
    </ol>
    <p class="dns-help">在“智能体共和国”里，也可以直接让智能体按这个流程操作：它会先查询实时状态，改解析和添加 CDN 都先预览、由你确认后才执行。</p>
  </details>
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
  <?php if ($overview): ?>
  <section class="dns-card cdn-traffic"><div class="dns-card-head"><h2>流量概览</h2></div>
    <dl class="cdn-summary"><div><dt>全部域名近 7 天</dt><dd><?php echo e(qt_bytes($overview['week'])); ?></dd></div><div><dt>全部域名近 30 天</dt><dd><?php echo e(qt_bytes($overview['month'])); ?></dd></div></dl>
    <?php if ($overview['top']): ?><p class="dns-help">近 30 天流量前 <?php echo count($overview['top']); ?>：<?php echo implode('；', array_map(function ($t) { return '<a href="cdn.php?d=' . rawurlencode($t['domain']) . '">' . e($t['domain']) . '</a> ' . e(qt_bytes($t['month'])); }, $overview['top'])); ?>。最近同步 <?php echo e(date('m-d H:i', $overview['updated_at'])); ?>。</p><?php endif; ?>
  </section>
  <?php elseif ($trafficNote): ?><div class="dns-notice warn"><?php echo e($trafficNote); ?></div><?php endif; ?>
  <section class="dns-card"><div class="dns-card-head"><h2>七牛账号下的 CDN 域名</h2><a href="cdn.php" class="dns-btn ghost">刷新 / 返回第一页</a></div>
    <form method="get" class="cdn-lookup"><label for="cdnLookup">查询完整域名</label><input id="cdnLookup" name="d" required placeholder="cdn.example.com" maxlength="253"><button class="dns-btn ghost" type="submit">查看详情</button></form>
    <div class="dns-table-wrap"><table class="dns-table"><thead><tr><th>加速域名</th><th>配置状态</th><th>区域 / 协议</th><th><button type="button" class="cdn-sort" data-sort="week" title="点击按近 7 天流量排序">近 7 天流量 <span aria-hidden="true">↕</span></button></th><th><button type="button" class="cdn-sort" data-sort="today" title="点击按今日流量排序">今日 <span aria-hidden="true">↕</span></button></th><th>应解析到（七牛 CNAME）</th><th>当前公网解析</th><th></th></tr></thead><tbody>
      <?php if (!$list['rows']): ?><tr><td colspan="8" class="dns-empty"><?php echo $error?'暂未读取到列表，请稍后重试':'账号下暂未读取到 CDN 域名'; ?></td></tr><?php endif; ?>
      <?php foreach ($list['rows'] as $row): ?><tr data-name="<?php echo e($row['name']); ?>"><td><strong><?php echo e($row['name']); ?></strong></td><td><?php echo e(qc_state_label($row['operatingState'])); ?></td><td><?php echo e(QC_REGIONS[$row['geoCover']]??$row['geoCover']); ?><small><?php echo e(strtoupper($row['protocol'])); ?></small></td><?php $tr = $traffic[strtolower($row['name'])] ?? null; ?><td data-sort-week="<?php echo $tr ? (int)$tr['week'] : -1; ?>"><?php echo ($tr && $tr['has_data']) ? e(qt_bytes($tr['week'])) : '<span class="dns-help">—</span>'; ?></td><td data-sort-today="<?php echo $tr ? (int)$tr['today'] : -1; ?>"><?php echo ($tr && $tr['has_data']) ? e(qt_bytes($tr['today'])) . ($tr['spike'] ? ' <span class="cdn-spike" title="今日流量明显高于前 7 天平均，请核对是否被盗刷或正常活动">异常增长</span>' : '') : '<span class="dns-help">—</span>'; ?></td><td><code class="dns-value"><?php echo e($row['cname'] ?: '七牛暂未返回'); ?></code></td><?php $di = $dnsInfo[strtolower($row['name'])] ?? null; ?><td><?php if ($di): ?><span class="cdn-dns <?php echo e($di['state']); ?>"><?php echo e(qt_dns_label($di['state'])); ?></span><small><?php echo e($di['resolved']); ?></small><?php else: ?><span class="dns-help">待检查</span><?php endif; ?></td><td><a class="dns-btn ghost" href="?d=<?php echo rawurlencode($row['name']); ?>">查看 / 接入解析</a></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($list['marker']): ?><a class="dns-btn ghost" href="?marker=<?php echo rawurlencode($list['marker']); ?>">下一页</a><?php endif; ?>
  </section>
  <?php endif; ?>
</main>
<script src="<?php echo BASE_URL; ?>/assets/js/cdn.js?v=20261029" defer></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
