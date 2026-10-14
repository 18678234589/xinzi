<?php if (!isset($actor,$csrf)) { http_response_code(404); exit; } ?>
<section class="domain-registration" id="domainRegistration">
  <div class="domain-section-heading"><div><span class="domain-kicker">CREATE YOUR NEXT CONNECTION</span><h2>注册一个新的开始</h2><p id="registrationQuota" class="domain-help">正在读取今日额度…</p></div><div class="registration-head-actions"><button class="domain-btn" id="registrationOpenCreator" type="button"><i class="fas fa-magic" aria-hidden="true"></i> 智能识别实名 + 注册</button><button class="domain-btn ghost" id="registrationToggle" type="button" aria-expanded="false">＋ 注册域名</button></div></div>
  <div id="registrationNotice" class="domain-notice" role="status" aria-live="polite" hidden></div>
  <div id="registrationWorkspace" hidden>
    <section class="registration-ai" id="templateCreator" data-regions="<?php echo htmlspecialchars(json_encode(array_map('array_keys', bt_regions()), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>" data-cert-types="<?php echo htmlspecialchars(json_encode(['company' => bt_company_cert_types(), 'person' => bt_person_cert_types()], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
      <h3><i class="fas fa-magic" aria-hidden="true"></i> 智能识别 · 一键预检</h3>
      <p class="domain-help">把客户发来的<strong>域名、公司名、电话、邮箱、地址</strong>整段粘贴进来，再上传营业执照（或身份证）照片：系统自动识别，并查询<strong>域名能否注册、价格</strong>和<strong>该公司是否已有可用的实名主体</strong>，结果直接显示在下面，不扣费。老客户实名已通过时不用再传图片，只粘贴文字即可。证件图片只用于本次识别和提交，不保存在本系统。</p>
      <form id="tcAiForm" class="domain-form-grid">
        <label class="domain-form-wide">客户信息<textarea id="tcText" rows="5" maxlength="4000" placeholder="例如：&#10;example.com 济南某某科技有限公司&#10;电话：138xxxxxxxx&#10;邮箱：xxx@qq.com&#10;地址：山东省济南市历下区…"></textarea></label>
        <label class="domain-form-wide">营业执照 / 身份证图片（JPG、PNG，最多 4 张；新实名必传）<input id="tcFiles" type="file" accept="image/jpeg,image/png,image/*" multiple></label>
        <div class="domain-form-wide"><span id="tcFileInfo" class="domain-help"></span><button class="domain-btn" id="tcRecognize" type="submit">智能识别并预检</button></div>
      </form>
      <div id="tcNotice" class="domain-notice" role="status" aria-live="polite" hidden></div>
      <div id="tcPrecheck" class="registration-precheck" hidden>
        <div class="registration-subject-row"><label for="tcDomain">要注册的域名</label><input id="tcDomain" maxlength="253" placeholder="example.com" autocomplete="off"><button class="domain-btn ghost" id="tcRecheck" type="button">重新预检</button></div>
        <div id="tcChecks" class="registration-checks"></div>
      </div>
      <form id="tcForm" class="domain-form-grid" hidden>
        <label>主体类型<select id="tcKind"><option value="company">单位（企业）</option><option value="person">个人</option></select></label>
        <label>证件类型<select id="tcCertType"></select></label>
        <label>模板名称<input id="tcTemplateName" maxlength="50" required></label>
        <label>域名所有者（<span id="tcOwnerHint">单位全称</span>，与证件一致）<input id="tcOwnerName" maxlength="100" required></label>
        <label>证件号码 / 统一社会信用代码<input id="tcCertNo" maxlength="40" required autocomplete="off"></label>
        <label>姓（单位填法定代表人）<input id="tcSurname" maxlength="10" required></label>
        <label>名<input id="tcGivenName" maxlength="20" required></label>
        <label>省份<select id="tcProvince" required></select></label>
        <label>城市<select id="tcCity" required></select></label>
        <label class="domain-form-wide">详细地址（省市之后）<input id="tcAddress" maxlength="200" required></label>
        <label>邮编<input id="tcPostcode" maxlength="6" inputmode="numeric" required></label>
        <label>电话（须是商务中国账户已验证）<input id="tcPhone" maxlength="30" required placeholder="13xxxxxxxxx"></label>
        <label>邮箱（须是商务中国账户已验证）<input id="tcEmail" type="email" maxlength="100" required></label>
        <label>所有者英文<input id="tcOrgE" maxlength="120" required></label>
        <label>姓（英文）<input id="tcSurnameE" maxlength="40" required></label>
        <label>名（英文）<input id="tcGivenNameE" maxlength="40" required></label>
        <label>省份（英文）<input id="tcProvinceE" maxlength="40" required></label>
        <label>城市（英文）<input id="tcCityE" maxlength="40" required></label>
        <label class="domain-form-wide">地址（英文）<input id="tcAddressE" maxlength="200" required></label>
        <div class="domain-form-wide">
          <button class="domain-btn" id="tcSubmit" type="submit">核对无误，提交实名审核</button>
        </div>
      </form>
      <div id="tcResult" class="domain-notice" role="status" aria-live="polite" hidden></div>
    </section>
    <form id="registrationSearch" class="domain-form-grid">
      <label>想注册的完整域名<input id="registrationDomain" placeholder="your-next-idea.com" maxlength="253" required autocomplete="off"></label>
      <label>注册年限<select id="registrationTerm"><option value="1">1 年</option><option value="2">2 年</option><option value="5">5 年</option><option value="10">10 年</option></select></label>
      <div class="domain-form-wide registration-subject">
        <label for="registrationTemplateQuery">客户实名主体（输入主体名称、模板名称或模板编号查找）</label>
        <div class="registration-subject-row"><input id="registrationTemplateQuery" placeholder="例如：某某科技有限公司 / biz…编号" maxlength="120" autocomplete="off"><button class="domain-btn ghost" id="registrationFindTemplate" type="button">查找实名主体</button><button class="domain-btn ghost" id="registrationRefreshTemplates" type="button" title="先同步商务中国最新审核通过的实名，再查找">刷新最新实名并查找</button></div>
        <p class="domain-help">手动方式：老客户在这里查找并选择实名主体。新客户建议用上方「智能识别 · 一键预检」；也可点下面按钮到商务中国页面手工创建（审核 2–5 个工作日，通过后点「刷新最新实名并查找」）。</p>
        <button class="domain-btn ghost" id="registrationNewSubject" type="button">改在商务中国官方页面手工创建 ↗</button>
      </div>
      <label>关联订单号（可选，注册成功后自动出现在该订单的「我的订单域名」）<input id="registrationOrderNo" maxlength="100" placeholder="订单编号" autocomplete="off"></label>
      <label>域名实名主体<select id="registrationTemplate" required><option value="">请先查找实名主体</option></select></label>
      <div class="domain-form-wide"><button class="domain-btn" type="submit" id="registrationCheck" disabled>查询可注册性与实时价格</button><p class="domain-help">先查询，不扣费。可选 1、2、5、10 年，多年总价按「单年价 × 年数」估算、以商务中国实际扣款为准；高价域名仅支持 1 年且由管理员操作；续费另行确认。证件资料不在这里收集。</p></div>
    </form>
    <form id="registrationConfirm" hidden>
      <div id="registrationQuote" class="domain-detail-grid"></div>
      <p class="domain-help">价格为商务中国接口返回的单年注册价乘以所选年数（人民币），从商务中国账户余额扣除。请核对实名主体与 NS；注册成功后通常不能取消，避免误购。</p>
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
      <label>新客户实名默认手机（商务中国已验证）<input id="registrationContactPhone" maxlength="30" placeholder="客户没提供时自动填入"></label><label>新客户实名默认邮箱（商务中国已验证）<input id="registrationContactEmail" type="email" maxlength="100"></label>
      <div class="domain-form-wide"><button class="domain-btn ghost" id="registrationSaveContact" type="button">保存默认联系方式</button></div>
      <div class="domain-form-wide"><button class="domain-btn ghost" id="registrationLoadTemplates" type="button">查询商务中国实名模板</button><button class="domain-btn ghost" id="registrationSyncTemplates" type="button">同步可搜索模板目录</button><button class="domain-btn ghost" id="registrationTemplatePrev" type="button" disabled>上一页</button><button class="domain-btn ghost" id="registrationTemplateNext" type="button" disabled>下一页</button><span id="registrationTemplatePage"></span><p class="domain-help">首次同步后可按名称、主体或编号搜索。仅缓存模板编号、名称和主体，不保存证件、地址、电话；注册前仍实时核验实名状态。</p></div>
      <div class="domain-form-wide" id="registrationCandidates"></div>
      <div class="domain-form-wide"><h3>已批准模板</h3><div id="registrationApproved"></div><button class="domain-btn" type="submit">保存注册配置</button></div>
    </form>
  </details>
  <?php endif; ?>
</section>
