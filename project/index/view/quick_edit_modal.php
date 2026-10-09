<?php
/**
 * 项目订单列表页快捷修改弹窗组件：改类型、改成本、改到期时间、加客户联系方式
 */
?>
<div class="modal fade" id="quickEditOrderModal" tabindex="-1" role="dialog" aria-labelledby="quickModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content shadow-lg border-0" style="border-radius:14px;overflow:hidden">
      <div class="modal-header py-3" style="background:#f4f7f6;border-bottom:1px solid #e1e9e5">
        <h6 class="modal-title font-weight-bold text-dark mb-0" id="quickModalTitle">
          <i class="fas fa-edit text-primary mr-1"></i> 快捷修改订单
        </h6>
        <button type="button" class="close" data-dismiss="modal" aria-label="关闭">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body p-3">
        <form id="quickEditOrderForm" autocomplete="off" onsubmit="return false;">
          <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
          <input type="hidden" name="action" value="quick_update_order">
          <input type="hidden" name="order_id" id="quickModalOrderId" value="">
          <input type="hidden" name="ajax" value="1">

          <div class="alert alert-light border py-2 px-3 mb-3 small" style="background:#f8faf9;border-radius:8px">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
              <span>单号：<strong id="quickModalOrderNo" class="text-dark">—</strong></span>
              <span>业务：<strong id="quickModalBusiness" class="text-primary">—</strong></span>
            </div>
            <div class="text-muted mt-1" id="quickModalCustomer">—</div>
          </div>

          <!-- 1. 改类型 -->
          <div class="form-group mb-3 pb-2 border-bottom" id="quickSecKind">
            <label class="font-weight-bold text-dark mb-1 small" for="quickInputKind">
              <i class="fas fa-tags text-primary mr-1"></i> 订单类型
            </label>
            <select id="quickInputKind" name="order_kind" class="form-control form-control-sm">
              <option value="">— 请选择订单类型 —</option>
            </select>
            <div class="small text-muted mt-1" id="quickKindHint">
              可选业务类型，如开发定制、新订单、定制、续费、技术服务等。
            </div>
          </div>

          <!-- 2. 改成本 -->
          <div class="form-group mb-3 pb-2 border-bottom" id="quickSecCost">
            <label class="font-weight-bold text-dark mb-1 small" for="quickInputCost">
              <i class="fas fa-coins text-warning mr-1"></i> 直接成本
            </label>
            <div class="input-group input-group-sm mb-1">
              <div class="input-group-prepend"><span class="input-group-text">¥</span></div>
              <input type="number" step="0.01" min="0" id="quickInputCost" name="direct_cost" class="form-control" placeholder="0.00">
            </div>
            <input type="text" id="quickInputCostReason" name="cost_reason" class="form-control form-control-sm" placeholder="成本说明（如写手稿费、外包技术、成本备注）">
            <div class="small text-muted mt-1">¥500 元以内保存后自动生效，超过由财务审核。</div>
          </div>

          <!-- 3. 改到期时间 -->
          <div class="form-group mb-3 pb-2 border-bottom" id="quickSecExpiry">
            <label class="font-weight-bold text-dark mb-1 small" for="quickInputExpiry">
              <i class="fas fa-calendar-check text-info mr-1"></i> 服务器 / 业务到期时间
            </label>
            <input type="date" id="quickInputExpiry" name="server_expiry" class="form-control form-control-sm">
            <div class="small text-muted mt-1">设置后自动同步到续费管理，用于客户续费提醒与到期核对。</div>
          </div>

          <!-- 4. 加客户联系方式 -->
          <div class="form-group mb-0" id="quickSecContact">
            <label class="font-weight-bold text-dark mb-1 small">
              <i class="fas fa-address-book text-success mr-1"></i> 客户联系方式
            </label>
            <div class="form-row">
              <div class="col-6">
                <input type="tel" id="quickInputPhone" name="customer_phone" class="form-control form-control-sm" placeholder="客户手机号">
              </div>
              <div class="col-6">
                <input type="text" id="quickInputWechat" name="customer_wechat" class="form-control form-control-sm" placeholder="微信号 / 海外微信">
              </div>
            </div>
            <input type="text" id="quickInputContactNote" name="contact_note" class="form-control form-control-sm mt-1" placeholder="追加联系说明或备注">
            <div class="small text-muted mt-1">填写手机号或微信号后，系统自动关联客户资料并解除待补联系方式提醒。</div>
          </div>

          <div id="quickModalAlert" class="alert alert-danger py-1 px-2 small mt-2 d-none"></div>
        </form>
      </div>
      <div class="modal-footer py-2" style="background:#f9fbfb;border-top:1px solid #e7eeea">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">取消</button>
        <button type="button" id="quickModalSaveBtn" class="btn btn-success btn-sm px-4 font-weight-bold">
          <i class="fas fa-check mr-1"></i> 保存修改
        </button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var modalEl = document.getElementById('quickEditOrderModal');
  if (!modalEl) return;
  document.body.appendChild(modalEl); // 避免遮罩和毛玻璃层级冲突

  var form = document.getElementById('quickEditOrderForm');
  var orderIdInput = document.getElementById('quickModalOrderId');
  var orderNoEl = document.getElementById('quickModalOrderNo');
  var businessEl = document.getElementById('quickModalBusiness');
  var customerEl = document.getElementById('quickModalCustomer');
  var kindSelect = document.getElementById('quickInputKind');
  var costInput = document.getElementById('quickInputCost');
  var costReasonInput = document.getElementById('quickInputCostReason');
  var expiryInput = document.getElementById('quickInputExpiry');
  var phoneInput = document.getElementById('quickInputPhone');
  var wechatInput = document.getElementById('quickInputWechat');
  var noteInput = document.getElementById('quickInputContactNote');
  var alertEl = document.getElementById('quickModalAlert');
  var saveBtn = document.getElementById('quickModalSaveBtn');

  // 绑定全局点击触发
  document.addEventListener('click', function (ev) {
    var trigger = ev.target.closest ? ev.target.closest('.js-quick-trigger') : null;
    if (!trigger) return;
    ev.preventDefault();

    var orderId = trigger.getAttribute('data-id');
    var tab = trigger.getAttribute('data-tab') || 'all';
    var data = window.quickOrdersMap && window.quickOrdersMap[orderId];
    if (!data) return;

    alertEl.classList.add('d-none');
    alertEl.textContent = '';
    saveBtn.disabled = false;
    saveBtn.innerHTML = '<i class="fas fa-check mr-1"></i> 保存修改';

    orderIdInput.value = data.id;
    orderNoEl.textContent = data.order_no;
    businessEl.textContent = data.business;
    customerEl.textContent = data.customer ? ('客户：' + data.customer) : '客户：待补充';

    // 填充订单类型下拉框
    kindSelect.innerHTML = '<option value="">— 选择订单类型 —</option>';
    var kinds = data.kinds || [];
    // 若当前已有类型不在预设列表中，也加入选项以保证回显
    if (data.order_kind && kinds.indexOf(data.order_kind) === -1) {
      kinds = kinds.concat([data.order_kind]);
    }
    kinds.forEach(function (k) {
      var opt = document.createElement('option');
      opt.value = k;
      opt.textContent = k;
      if (k === data.order_kind) opt.selected = true;
      kindSelect.appendChild(opt);
    });

    // 填充成本
    costInput.value = (data.cost_amount !== '' && Number(data.cost_amount) > 0) ? data.cost_amount : '';
    costReasonInput.value = data.cost_reason || '';

    // 填充到期时间
    expiryInput.value = data.server_expiry || '';

    // 填充联系方式
    phoneInput.value = data.phone || '';
    wechatInput.value = data.wechat || '';
    noteInput.value = '';

    // 显示弹窗
    window.jQuery(modalEl).modal('show');

    // 根据触发入口高亮聚焦对应区域
    setTimeout(function () {
      if (tab === 'kind') {
        kindSelect.focus();
        document.getElementById('quickSecKind').scrollIntoView({behavior:'smooth', block:'center'});
      } else if (tab === 'cost') {
        costInput.focus();
        document.getElementById('quickSecCost').scrollIntoView({behavior:'smooth', block:'center'});
      } else if (tab === 'expiry') {
        expiryInput.focus();
        document.getElementById('quickSecExpiry').scrollIntoView({behavior:'smooth', block:'center'});
      } else if (tab === 'contact') {
        phoneInput.focus();
        document.getElementById('quickSecContact').scrollIntoView({behavior:'smooth', block:'center'});
      }
    }, 250);
  });

  // 保存提交
  saveBtn.addEventListener('click', function () {
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> 正在保存…';
    alertEl.classList.add('d-none');

    var formData = new FormData(form);
    fetch('<?php echo BASE_URL; ?>/project/index.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    }).then(function (res) {
      return res.json().then(function (data) {
        if (res.ok && data.ok) {
          saveBtn.className = 'btn btn-success btn-sm px-4';
          saveBtn.innerHTML = '<i class="fas fa-check-circle mr-1"></i> ' + (data.msg || '保存成功');
          setTimeout(function () {
            window.location.reload();
          }, 400);
        } else {
          saveBtn.disabled = false;
          saveBtn.className = 'btn btn-primary btn-sm px-4';
          saveBtn.innerHTML = '<i class="fas fa-check mr-1"></i> 保存修改';
          alertEl.textContent = data.error || '保存失败，请重试';
          alertEl.classList.remove('d-none');
        }
      });
    }).catch(function (err) {
      saveBtn.disabled = false;
      saveBtn.className = 'btn btn-primary btn-sm px-4';
      saveBtn.innerHTML = '<i class="fas fa-check mr-1"></i> 保存修改';
      alertEl.textContent = '网络请求异常，请刷新页面重试';
      alertEl.classList.remove('d-none');
    });
  });
});
</script>
