
var baseSalary = <?php echo (float)$employee['base_salary']; ?>;
var empRate = <?php echo (float)$employee['commission_rate']; ?>;
var allTypes = <?php echo json_encode($allTypes, JSON_UNESCAPED_UNICODE); ?>;
var moduleCount = <?php echo count($currentMods); ?>;
var orderTotalDemo = 25000; // 预算用模拟订单总额

function renderAllPreviews() {
    var total = baseSalary;
    var baseLabel = '固定服务费（固定）';
    var baseNote = '从合作人员固定服务费读取';
    var customBaseUsed = false;

    // 先扫一遍：是否有启用的 base_salary（自定义固定服务费）模块，有则覆盖固定服务费基数
    $('.module-card').each(function() {
        var idx = $(this).data('index');
        var enabled = $(this).find('[data-enabled]').val() == '1';
        if (!enabled) return;
        var type = $(this).find("[name='mod_type\\["+idx+"\\]']").val();
        if (type === 'base_salary') {
            var amt = calcPreview(idx);
            if (amt > 0) {
                baseSalary = amt;
                total = amt;
                baseLabel = '固定服务费（自定义）';
                baseNote = '覆盖合作人员表固定服务费 ¥' + (<?php echo (float)$employee['base_salary']; ?>).toFixed(2);
                customBaseUsed = true;
            }
        }
    });

    var html = '<tr><td><i class="fas fa-wallet text-primary"></i> ' + baseLabel + '</td><td class="fw-bold">¥' + total.toFixed(2) + '</td><td class="text-muted">' + baseNote + '</td></tr>';

    $('.module-card').each(function() {
        var idx = $(this).data('index');
        var enabled = $(this).find('[data-enabled]').val() == '1';
        if (!enabled) return;
        var type = $(this).find("[name='mod_type\\["+idx+"\\]']").val();
        // base_salary 已作为固定服务费基数，不重复加入模块合计
        if (type === 'base_salary') return;

        var amt = calcPreview(idx);

        // 获取模块名（strong标签内的文本）
        var modName = $('strong', this).first().text().trim();
        // 获取类型badge
        var typeBadge = $('.badge:not(.badge-primary)', this).first().text().trim();
        // 获取比例badge（如果有的话）
        var rateBadge = $('.badge.badge-primary', this);
        var rateText = rateBadge.length > 0 ? rateBadge.text().trim() : '';

        var detail = $(this).data('preview-detail') || '';
        var cls = amt >= 0 ? 'text-success' : 'text-danger';

        var nameHtml = '<strong>' + modName + '</strong>';
        if (rateText) nameHtml += ' <small class="text-info">(' + rateText + ')</small>';
        if (typeBadge && !rateBadge.length) nameHtml += ' <span class="text-muted small">[' + typeBadge + ']</span>';

        html += '<tr class="' + cls + '"><td>' + nameHtml +
               '</td><td class="fw-bold">' + (amt>=0?'+':'') + '¥' + Math.abs(amt).toFixed(2) +
               '</td><td class="small text-muted">' + (detail || '--') + '</td></tr>';
        total += amt;
    });

    $('#totalBody').html(html);
    $('#totalNetPay').text(total.toFixed(2));
}

function calcPreview(idx) {
    var card = $('#mod-' + idx);
    var type  = card.find("[name='mod_type\\["+idx+"\\]' ]").val() ||
                card.find("[name^='mod_type']").filter(function(){return this.name.indexOf('['+idx+']')>=0;}).val();
    if (!type) type = 'standard';
    var cfg   = {};
    // 收集所有该模块的表单值 — 正确提取 mod_cfg[key][idx] 中的 key
    card.find("input[name^='mod_cfg']").each(function(){
        // 匹配 mod_cfg[xxx][idx] 格式
        var m = this.name.match(/mod_cfg\[([^\]]+)\]\[/);
        if (m && m[1]) {
            var k = m[1];
            var v = this.value.trim();
            cfg[k] = v !== '' ? parseFloat(v) : 0;
        } else {
            // 兜底：匹配最后面的 [xxx]
            var mm = this.name.match(/\[([^\]]+)\]$/);
            if (mm) {
                cfg[mm[1]] = parseFloat(this.value) || 0;
            }
        }
    });
    // 阶梯 tiers
    cfg.tiers = [];
    card.find(".tier-row").each(function() {
        var th = parseFloat($(this).find(".tier-threshold").val()) || 0;
        var rt = parseFloat($(this).find(".tier-rate").val()) || 0;
        if (th > 0) cfg.tiers.push({threshold: th, rate: rt});
    });

    var result = {amount: 0, detail: ''};
    switch(type) {
        case 'base_salary':
            result.amount = cfg.base_amount || 0;
            result.detail = '自定义固定服务费 ¥' + (result.amount).toFixed(2);
            break;
        case 'standard':
            var r = (cfg.rate !== undefined && cfg.rate !== 0) ? cfg.rate : 0.05;
            result.amount = orderTotalDemo * r;
            result.detail = number_format(orderTotalDemo,0) + ' × ' + (r*100).toFixed(2) + '%';
            break;
        case 'tiered':
            var tr = 0, ts = cfg.tiers||[];
            ts.sort(function(a,b){return b.threshold-a.threshold});
            for(var i=0;i<ts.length;i++){if(orderTotalDemo >= ts[i].threshold){tr=ts[i].rate;break;}}
            result.amount = orderTotalDemo * tr;
            result.detail = '阶梯(' + (tr*100).toFixed(2) + '%)';
            break;
        case 'per_order':
            var pcnt = Math.round(orderTotalDemo/10000)||2;
            result.amount = pcnt*(cfg.per_amount||50)+pcnt*(cfg.per_reward||0);
            result.detail = pcnt + '笔';
            break;
        case 'referral_order':
            var demoCount = Math.round(orderTotalDemo/10000)||2;
            result.amount = demoCount*(cfg.subsidy||0);
            result.detail = demoCount + '单×¥' + (cfg.subsidy||0);
            break;
        case 'attendance_full':
            result.amount = cfg.full_amount||200;
            result.detail = '全勤固定';
            break;
        case 'attendance_daily':
            result.amount = (cfg.work_days||22)*(cfg.daily_rate||100);
            result.detail = (cfg.work_days||22) + '天 × ¥' + (cfg.daily_rate||100);
            break;
        case 'attendance_deduct':
            result.amount = -((cfg.absent_days||0)*(cfg.deduct_per_day||100));
            result.detail = '-' + (cfg.absent_days||0) + '天';
            break;
        case 'profit_commission':
            var cr = cfg.commission_rate || 0;
            var sf = cfg.service_fee_rate || 0;
            var demoAmount = orderTotalDemo;
            var demoCost   = orderTotalDemo * 0.5;
            result.amount = ((demoAmount - demoCost) - demoAmount * sf) * cr;
            result.detail = '((订单金额¥' + number_format(demoAmount,0) + ' - 成本¥' + number_format(demoCost,0) + ') - 订单金额¥' + number_format(demoAmount,0) + '×' + (sf*100).toFixed(2) + '%) ×' + (cr*100).toFixed(2) + '%';
            break;
        case 'trademark_commission':
            var cr = cfg.commission_rate || 0;
            var sf = cfg.service_fee_rate || 0;
            var demoAmount = orderTotalDemo;
            var demoCost   = orderTotalDemo * 0.5;
            result.amount = ((demoAmount - demoCost) - demoAmount * sf) * cr;
            result.detail = '((售价¥' + number_format(demoAmount,0) + ' - 成本¥' + number_format(demoCost,0) + ') - 售价¥' + number_format(demoAmount,0) + '×' + (sf*100).toFixed(2) + '%) ×' + (cr*100).toFixed(2) + '%';
            break;
        case 'trademark_cashback':
            var perAmt = cfg.per_amount || 0;
            var demoCount = Math.round(orderTotalDemo/3000)||3;
            result.amount = demoCount * perAmt;
            result.detail = '小额返现' + demoCount + '单×¥' + perAmt;
            break;
        case 'customer_reward':
            var newCnt = Math.round(orderTotalDemo/5000)||2;
            var oldCnt = Math.round(orderTotalDemo/8000)||1;
            var newReward = cfg.new_customer_reward || 50;
            var oldReward = cfg.old_customer_reward || 30;
            result.amount = (newCnt * newReward) + (oldCnt * oldReward);
            result.detail = '新客户'+newCnt+'人 + 老客户'+oldCnt+'人';
            break;
        case 'miniprogram_commission':
            var cr = cfg.commission_rate || 0;
            var sf = cfg.service_fee_rate || 0;
            var demoAmount = orderTotalDemo;
            var demoCost   = orderTotalDemo * 0.5;
            var profitComm = ((demoAmount - demoCost) - demoAmount * sf) * cr;
            var filterCol = cfg.filter_column || '';
            var filterVal = cfg.filter_value || '';
            var custSub = cfg.customer_subsidy || 0;
            var demoCustCnt = Math.round(orderTotalDemo/8000)||2;
            var subsidyAmt = (filterCol && filterVal && custSub > 0) ? demoCustCnt * custSub : 0;
            result.amount = profitComm + subsidyAmt;
            result.detail = '利润项目分成+新客户补助(' + demoCustCnt + '单×¥' + custSub + ')';
            break;
        case 'fixed_subsidy':
            result.amount = cfg.amount || 0;
            result.detail = '固定补助 ¥' + (result.amount).toFixed(2);
            break;
        default: result.amount = 0; result.detail = '--';
    }

    // 存储详细信息供显示用
    card.find('.preview-result').text(
        (result.amount >= 0 ? '+¥' : '-¥') + Math.abs(result.amount).toFixed(2)
        + (result.detail ? ' (' + result.detail + ')' : '')
    );
    card.data('preview-detail', result.detail);

    return result.amount;
}

function number_format(num, decimals) {
    return Number(num).toFixed(decimals || 0);
}

// 切换启用/禁用
function toggleModule(idx) {
    var inp = $("input[name='mod_enabled["+idx+"]']");
    var cur  = parseInt(inp.attr('data-enabled'));
    var next = 1 - cur;
    inp.val(next);
    inp.attr('data-enabled', next);
    if (next === 1) { inp.removeAttr('disabled'); } else { inp.prop('disabled', true); }
    $(inp).siblings('.toggle-switch').toggleClass('active');
    renderAllPreviews();
}

function removeModule(idx) {
    if(!confirm('确定删除此模块？')) return;
    $('#mod-'+idx).remove();
    renderAllPreviews();
}

function collapseMod(idx) {
    var panel = $('#params-'+idx);
    var icon  = $(event.target).find('i');
    panel.collapse('toggle');
    icon.toggleClass('fa-chevron-down fa-chevron-up');
}

function buildFieldsHTML(idx, fields) {
    var html = '';
    if (!fields || fields.length === 0) return html;
    $.each(fields, function(i, f) {
        var val = f.default || '';
        var inputType = (f.type === 'text') ? 'text' : 'number';
        var extraAttrs = ' class="form-control form-control-sm" name="mod_cfg[' + f.key + '][' + idx + ']" ';
        if (inputType === 'number') {
            extraAttrs += 'step="' + (f.step || 'any') + '" ';
        }

        var suffix = '';
        if (inputType === 'text') {
            // text 类型不需要前缀/后缀
        } else if (f.key.indexOf('rate') >= 0) {
            suffix = '<span class="input-group-append"><span class="input-group-text">%</span></span>';
        } else if (f.label.indexOf('金额') >= 0 || f.key.indexOf('amount') >= 0) {
            suffix = '<div class="input-group-prepend"><span class="input-group-text">¥</span></div>';
        }

        html += '<div class="form-group"><label>'+f.label+'</label>';
        if (inputType === 'text') {
            html += '<input type="text" '+extraAttrs+' value="'+val+'" placeholder="'+(f.placeholder||'')+'">';
        } else {
            html += '<div class="input-group">'+suffix;
            html += '<input type="number" '+extraAttrs+' value="'+val+'" placeholder="'+(f.placeholder||'')+'">';
            html += '</div>';
        }
        html += '</div>';
    });
    return html;
}

function showTypePicker() {
    $('#typePicker').modal('show');
}

function addModule(type) {
    $('#typePicker').modal('hide');
    var tv = allTypes[type] || allTypes['standard'];
    var idx = moduleCount++;
    var fieldsHtml = buildFieldsHTML(idx, tv.fields || []);
    // 名称输入框（放在最前面，突出显示）
    var nameHtml = '<div class="form-group"><label><strong>模块名称 <span class="text-danger">*</span></strong>'+
        '<small class="text-muted">（如：续费、新单、线下渠道 — 上传订单时会显示此名称）</small></label>'+
        '<div class="input-group"><span class="input-group-prepend"><span class="input-group-text"><i class="fas fa-tag"></i></span></span>'+
        '<input type="text" class="form-control" name="mod_cfg[_name]['+idx+']" id="modName'+idx+'" value="" placeholder="'+tv.label+'"></div></div>';
    var tierHtml = (type==='tiered'||type==='mixed')
        ? '<div class="mt-2 p-2 bg-light rounded border" id="tierArea-'+idx+'">'+
          '<div class="row mb-2"><div class="col"><strong>阶梯规则：</strong>'+
          '<button type="button" class="btn btn-outline-info btn-xs ml-2" onclick="addTierRow('+idx+')"><i class="fas fa-plus"></i> 新增档次</button></div></div>'+
          '<div id="tierContainer-'+idx+'">'+
          '<div class="tier-row"><div class="col-4"><label>订单≥</label><input type="number" class="form-control form-control-sm tier-threshold" value="10000" step="any" required></div>'+
          '<div class="col-4"><label>比例</label><div class="input-group input-group-sm"><input type="number" class="form-control tier-rate" step="0.0001" max="1" value="0.05" required><span class="input-group-append"><span class="input-group-text">%</span></div></div>'+
          '<div class="col-3 pl-0"><button type="button" class="btn btn-outline-danger btn-sm tier-rm" style="margin-top:22px">×</button></div></div></div></div>'
        : (type==='base_salary_tiered')
        ? '<div class="mt-2 p-2 bg-light rounded border" id="tierArea-'+idx+'">'+
          '<div class="row mb-2"><div class="col"><strong>阶梯规则：</strong>'+
          '<button type="button" class="btn btn-outline-info btn-xs ml-2" onclick="addBaseTierRow('+idx+')"><i class="fas fa-plus"></i> 新增档次</button></div></div>'+
          '<div id="tierContainer-'+idx+'">'+
          '<div class="tier-row row"><div class="col-5"><label>订单总额≥</label><input type="number" class="form-control form-control-sm tier-threshold" value="0" step="any" required></div>'+
          '<div class="col-5"><label>固定服务费金额</label><div class="input-group input-group-sm"><div class="input-group-prepend"><span class="input-group-text">¥</span></div><input type="number" class="form-control tier-base-amount" step="any" value="2300" required></div></div>'+
          '<div class="col-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm tier-rm" style="margin-top:22px">×</button></div></div></div></div>'
        : '';

    var card = $('<div class="card module-card mb-3" data-index="'+idx+'" id="mod-'+idx+'">');
    card.html(
        '<div class="card-header py-2 px-3 d-flex justify-content-between align-items-center" style="background:#e8f4fd;cursor:pointer;" data-toggle="collapse" data-target="#params-'+idx+'">'+
        '<div class="d-flex align-items-center"><span style="cursor:move;padding:0 10px;color:#999;" onclick="event.stopPropagation();"><i class="fas fa-grip-vertical"></i></span>'+
        '<label class="d-flex align-items-center mb-0 cursor-pointer" onclick="event.stopPropagation();toggleModule('+idx+')">'+
        '<input type="hidden" name="mod_enabled['+idx+']" value="1" data-enabled="1">'+
        '<span class="toggle-switch active mr-2"></span>'+
        '<strong><i class="fas fa-'+tv.icon+' mr-1 mod-icon"></i><span class="mod-name-display">'+tv.label+'</span></strong> '+
        '<small class="badge badge-'+(tv.color||'secondary')+' ml-1 mod-type-badge">'+tv.label+'</small></label></div>'+
        '<div class="btn-group btn-group-sm" onclick="event.stopPropagation();"><button type="button" class="btn btn-outline-secondary btn-sm" onclick="collapseMod('+idx+')"><i class="fas fa-chevron-down"></i>'+
        '<button type="button" class="btn btn-outline-danger btn-sm" onclick="removeModule('+idx+')"><i class="fas fa-trash"></i></button></div></div>'+
        '<div class="mod-params collapse" id="params-'+idx+'"><div class="card-body pt-0 pb-3"><input type="hidden" name="mod_name['+idx+']" value="'+tv.label+'">'+
        '<input type="hidden" name="mod_type['+idx+']" value="'+type+'">'+nameHtml+fieldsHtml+tierHtml+
        '<div class="mt-2 p-2 bg-light rounded border preview-box" data-mod-idx="'+idx+'"><small class="text-muted"><i class="fas fa-calculator"></i> 预算：<strong class="preview-result text-primary">--</strong></div>'+
        '</div></div></div>'
    );

    card.appendTo('#moduleList');

    // 模块名称变化时实时更新卡片标题
    $('#modName'+idx).on('input', function() {
        var newName = $(this).val().trim() || tv.label;
        $(this).closest('.module-card').find('.mod-name-display').text(newName);
        // 同步更新隐藏的 mod_name 字段
        $(this).closest('.module-card').find("[name^='mod_name']").val(newName);
        renderAllPreviews();
    });

    // 绑事件并更新预览
    bindCardEvents(idx, type);
    setTimeout(renderAllPreviews, 100);
}

function bindCardEvents(idx, type) {
    var card = $('#mod-'+idx);
    card.on('change input', function(){setTimeout(renderAllPreviews,50);});
}

function addTierRow(idx) {
    var row = $('<div class="tier-row"><div class="col-4"><label>订单≥</label><input type="number" class="form-control form-control-sm tier-threshold" step="any" required></div>'+
        '<div class="col-4"><label>比例</label><div class="input-group input-group-sm"><input type="number" class="form-control tier-rate" step="0.0001" max="1" required><span class="input-group-append"><span class="input-group-text">%</span></div></div>'+
        '<div class="col-3 pl-0"><button type="button" class="btn btn-outline-danger btn-sm tier-rm" style="margin-top:22px">×</button></div></div>');
    row.appendTo('#tierContainer-'+idx);
    row.on('click','.tier-rm',function(){$(this).closest('.tier-row').remove();setTimeout(renderAllPreviews,50);});
    row.on('change input','*',function(){setTimeout(renderAllPreviews,50);});
}

