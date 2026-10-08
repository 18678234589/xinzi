<?php /* split: employees/algorithm/view/js_2/content.php */ include __DIR__ . '/js_2/content.php'; ?>function addBaseTierRow(idx) {
    var row = $('<div class="tier-row row"><div class="col-5"><label>订单总额≥</label><input type="number" class="form-control form-control-sm tier-threshold" step="any" required></div>'+
        '<div class="col-5"><label>固定服务费金额</label><div class="input-group input-group-sm"><div class="input-group-prepend"><span class="input-group-text">¥</span></div><input type="number" class="form-control tier-base-amount" step="any" required></div></div>'+
        '<div class="col-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm tier-rm" style="margin-top:22px">×</button></div></div>');
    row.appendTo('#tierContainer-'+idx);
    row.on('click','.tier-rm',function(){$(this).closest('.tier-row').remove();setTimeout(renderAllPreviews,50);});
    row.on('change input','*',function(){setTimeout(renderAllPreviews,50);});
}

function addTierRowForForm(idx) {
    var row = $('<div class="tier-row"><div class="col-4"><label>订单≥</label><input type="number" class="form-control form-control-sm tier-threshold" name="t_threshold['+idx+'][]" step="any"></div>'+
        '<div class="col-4"><label>比例</label><div class="input-group input-group-sm"><input type="number" class="form-control tier-rate" name="t_rate['+idx+'][]" step="0.0001" min="0" max="1"><span class="input-group-append"><span class="input-group-text">%</span></div></div>'+
        '<div class="col-3 pl-0"><button type="button" class="btn btn-outline-danger btn-sm tier-rm-form" style="margin-top:22px">×</button></div></div>');
    row.appendTo('#tierContainer-'+idx);
    row.on('click','.tier-rm-form', function(){$(this).closest('.tier-row').remove();setTimeout(renderAllPreviews,50);});
    row.on('change input','*', function(){setTimeout(renderAllPreviews,50);});
}

function addBaseTierRowForForm(idx) {
    var row = $('<div class="tier-row row"><div class="col-5"><label>订单总额≥</label><input type="number" class="form-control form-control-sm tier-threshold" name="t_threshold['+idx+'][]" step="any"></div>'+
        '<div class="col-5"><label>固定服务费金额</label><div class="input-group input-group-sm"><div class="input-group-prepend"><span class="input-group-text">¥</span></div><input type="number" class="form-control tier-base-amount" name="t_base_amount['+idx+'][]" step="any" min="0"></div></div>'+
        '<div class="col-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm tier-rm-form" style="margin-top:22px">×</button></div></div>');
    row.appendTo('#tierContainer-'+idx);
    row.on('click','.tier-rm-form', function(){$(this).closest('.tier-row').remove();setTimeout(renderAllPreviews,50);});
    row.on('change input','*', function(){setTimeout(renderAllPreviews,50);});
}

$(document).on('click', '.tier-rm', function(){$(this).closest('.tier-row').remove();setTimeout(renderAllPreviews,50);});
$(document).on('click', '.tier-rm-form', function(){$(this).closest('.tier-row').remove();setTimeout(renderAllPreviews,50);});

$(function(){
    // 初始渲染
    renderAllPreviews();
    // 绑定已有卡片的事件
    $('.module-card').each(function(){
        var idx=$(this).data('index'), type=$(this).find("[name='mod_type\\["+idx+"\\]']").val();
        $(this).on('change input', function(){setTimeout(renderAllPreviews,50);});
    });
});

// ==================== 调试：提交时打印到控制台 ====================
$('#moduleForm').on('submit', function() {
    try {
        var fd = new FormData(this);
        var dump = {};
        fd.forEach(function(v, k) {
            if (dump[k] === undefined) dump[k] = v;
            else if (Array.isArray(dump[k])) dump[k].push(v);
            else dump[k] = [dump[k], v];
        });
        console.log('[algorithm] 提交数据 mod_type=', dump['mod_type']);
        console.log('[algorithm] 提交数据 mod_name=', dump['mod_name']);
        console.log('[algorithm] 提交数据 mod_cfg=', dump['mod_cfg']);
        // 单独高亮引流订单模块
        var types = dump['mod_type'] || [];
        types.forEach(function(t, i) {
            if (t === 'referral_order') {
                console.log('[algorithm] 引流订单模块 #' + i + ' 配置=', (dump['mod_cfg'] && dump['mod_cfg']['subsidy']) ? dump['mod_cfg']['subsidy'][i] : 'N/A');
            }
        });
        console.log('[algorithm] 完整表单=', dump);
    } catch (e) {
        console.error('[algorithm] 调试打印异常', e);
    }
});
