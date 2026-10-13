<?php if (!isset($actor,$csrf)) { http_response_code(404); exit; } ?>
<section class="domain-registration" id="domainRegistration">
  <div class="domain-section-heading"><div><span class="domain-kicker">CREATE YOUR NEXT CONNECTION</span><h2>注册一个新的开始</h2><p id="registrationQuota" class="domain-help">正在读取今日额度…</p></div><button class="domain-btn ghost" id="registrationToggle" type="button" aria-expanded="false">＋ 注册域名</button></div>
  <div id="registrationNotice" class="domain-notice" role="status" aria-live="polite" hidden></div>
  <div id="registrationWorkspace" hidden>
    <form id="registrationSearch" class="domain-form-grid">
      <label>想注册的完整域名<input id="registrationDomain" placeholder="your-next-idea.com" maxlength="253" required autocomplete="off"></label>
      <label>域名实名主体 / 已批准模板<select id="registrationTemplate" required><option value="">等待管理员配置</option></select></label>
      <div class="domain-form-wide"><button class="domain-btn" type="submit" id="registrationCheck" disabled>查询可注册性与实时价格</button><p class="domain-help">先查询，不扣费。首期支持一年注册，续费另行确认；高价域名由管理员操作。证件资料不在这里收集。</p></div>
    </form>
    <form id="registrationConfirm" hidden>
      <div id="registrationQuote" class="domain-detail-grid"></div>
      <p class="domain-help">价格为商务中国接口返回的一年注册价（人民币），从商务中国账户余额扣除。请核对实名主体与 NS；注册成功后通常不能取消，避免误购。</p>
      <label class="registration-ack"><input type="checkbox" id="registrationAck" required>我已核对域名、实名主体、NS 和金额，确认真实注册扣费</label>
      <button class="domain-btn" type="submit" id="registrationBuy">确认注册并扣费</button><button class="domain-btn ghost" type="button" id="registrationCancel">取消本次报价</button>
    </form>
    <details class="registration-history" open><summary>注册申请记录 <small>最近50条；处理中只核对，不重复购买</small></summary><button class="domain-btn ghost" id="registrationHistoryRefresh" type="button">刷新记录</button><div id="registrationHistory"></div></details>
  </div>
  <?php if (br_admin($actor)): ?>
  <details id="registrationSettings"><summary>管理员 · 每日上限、实名模板与默认 NS</summary>
    <p class="domain-help">全平台共享每日额度，按北京时间自然日计算，默认12个；0 可暂停注册。未确认申请保留额度，避免超额和重复扣费。请选择已审核模板，填写明确的真实主体，仅这些模板会提供给合作人员。</p>
    <form id="registrationSettingsForm" class="domain-form-grid">
      <label>每日注册上限<input id="registrationLimit" type="number" min="0" max="1000" value="12" required></label>
      <div class="domain-form-wide"><?php $ns_target='registrationDefaultNs';$ns_group='registration_ns_mode';include __DIR__.'/domain_dns_choices.php'; ?></div>
      <label class="domain-form-wide">注册默认 NS（两个不同服务器，一行一个）<textarea id="registrationDefaultNs" rows="3" placeholder="开启实名模板时必填；也可以先只设置每日额度"></textarea></label>
      <label>按模板名称查询<input id="registrationTemplateSearch" maxlength="120" placeholder="输入商务中国中的模板名称"></label><label>按模板编号查询（可选）<input id="registrationTemplateIdSearch" maxlength="100" placeholder="可粘贴已有的模板编号"></label>
      <div class="domain-form-wide"><button class="domain-btn ghost" id="registrationLoadTemplates" type="button">查询商务中国实名模板</button><button class="domain-btn ghost" id="registrationSyncTemplates" type="button">同步可搜索模板目录</button><button class="domain-btn ghost" id="registrationTemplatePrev" type="button" disabled>上一页</button><button class="domain-btn ghost" id="registrationTemplateNext" type="button" disabled>下一页</button><span id="registrationTemplatePage"></span><p class="domain-help">首次同步后可按名称、主体或编号搜索。仅缓存模板编号、名称和主体，不保存证件、地址、电话；注册前仍实时核验实名状态。</p></div>
      <div class="domain-form-wide" id="registrationCandidates"></div>
      <div class="domain-form-wide"><h3>已批准模板</h3><div id="registrationApproved"></div><button class="domain-btn" type="submit">保存注册配置</button></div>
    </form>
  </details>
  <?php endif; ?>
</section>
