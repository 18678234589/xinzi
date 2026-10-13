<?php if (!isset($ns_target,$ns_group)) { http_response_code(404); exit; } ?>
<fieldset class="domain-dns-choices" data-ns-target="<?php echo htmlspecialchars($ns_target,ENT_QUOTES,'UTF-8'); ?>">
  <legend>DNS 方式 · 选择一项</legend>
  <label><input type="radio" name="<?php echo htmlspecialchars($ns_group,ENT_QUOTES,'UTF-8'); ?>" value="default"><span><strong>默认 DNS</strong><small>ns7.cnmsn.net<br>ns8.cnmsn.net</small></span></label>
  <label><input type="radio" name="<?php echo htmlspecialchars($ns_group,ENT_QUOTES,'UTF-8'); ?>" value="smart"><span><strong>智能 DNS</strong><small>bizcn1.dnspod.net<br>bizcn2.dnspod.net</small></span></label>
  <label><input type="radio" name="<?php echo htmlspecialchars($ns_group,ENT_QUOTES,'UTF-8'); ?>" value="custom" checked><span><strong>自定义 DNS</strong><small>填写自己的 NS 服务器</small></span></label>
  <p class="domain-help" data-smart-warning hidden>智能 DNS 使用条件：该域名必须未在 DNSPod 注册或添加过解析记录。本系统无法核实外部 DNSPod 记录，请先确认，避免切换后无法正常解析。</p>
</fieldset>
