<?php if (!isset($ns_target,$ns_group)) { http_response_code(404); exit; } ?>
<fieldset class="domain-dns-choices" data-ns-target="<?php echo htmlspecialchars($ns_target,ENT_QUOTES,'UTF-8'); ?>">
  <legend>DNS 方式 · 选择一项</legend>
  <label><input type="radio" name="<?php echo htmlspecialchars($ns_group,ENT_QUOTES,'UTF-8'); ?>" value="default"><span><strong>默认 DNS</strong><small>ns7.cnmsn.net<br>ns8.cnmsn.net</small></span></label>
  <label><input type="radio" name="<?php echo htmlspecialchars($ns_group,ENT_QUOTES,'UTF-8'); ?>" value="smart"><span><strong>智能 DNS</strong><small>bizcn1.dnspod.net<br>bizcn2.dnspod.net</small></span></label>
  <label><input type="radio" name="<?php echo htmlspecialchars($ns_group,ENT_QUOTES,'UTF-8'); ?>" value="custom" checked><span><strong>自定义 DNS</strong><small>填写自己的 NS 服务器</small></span></label>
  <p class="domain-help" data-smart-warning hidden>此项是商务中国的智能 DNS 预设，要求域名未在 DNSPod 注册或添加过解析记录；此选项不会自动核验该条件。如需使用我们账号下的 DNSPod API 管理，请进入本站“解析管理”，添加域名并获取实际分配的 NS，再使用“自定义 DNS”。全程无需登录腾讯云。</p>
</fieldset>
