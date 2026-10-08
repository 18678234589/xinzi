
// 文件上传区交互
(function(){
    var area = document.getElementById('uploadArea');
    var file = document.getElementById('excelFile');
    var tip = document.getElementById('uploadTip');
    if (!area || !file) return;
    area.addEventListener('click', function(){ file.click(); });
    file.addEventListener('change', function(){
        if (file.files.length) { tip.textContent = file.files[0].name; }
    });
    ['dragover','dragenter'].forEach(function(ev){
        area.addEventListener(ev, function(e){ e.preventDefault(); area.style.borderColor='#28a745'; });
    });
    ['dragleave','drop'].forEach(function(ev){
        area.addEventListener(ev, function(e){ e.preventDefault(); area.style.borderColor=''; });
    });
    area.addEventListener('drop', function(e){
        if (e.dataTransfer.files.length) { file.files = e.dataTransfer.files; tip.textContent = file.files[0].name; }
    });
})();

// ===== 订单明细交互 =====
// 全选/反选
function toggleAll(el){
    document.querySelectorAll('.row-chk').forEach(function(c){ c.checked = el.checked; });
    updateSelCount();
}
function updateSelCount(){
    var n = document.querySelectorAll('.row-chk:checked').length;
    var box = document.getElementById('selCount');
    if (box) box.textContent = n;
}
// 切换每页条数
function changePageSize(ps){
    var url = new URL(window.location.href);
    url.searchParams.set('page_size', ps);
    url.searchParams.set('page', '1');
    window.location = url;
}
// 单条删除
function deleteOrder(id, date){
    if (!confirm('确定删除订单 #' + id + '（' + date + '）？')) return;
    var f = document.createElement('form');
    f.method = 'post';
    f.innerHTML = '<input type="hidden" name="action" value="delete_order"><input type="hidden" name="order_id" value="' + id + '">';
    document.body.appendChild(f);
    f.submit();
}
// 一键删除整月订单
function deleteMonth(month, count){
    if (!confirm('确定删除 ' + month + ' 的全部 ' + count + ' 条订单？\n\n订单将移入回收站，可恢复。')) return;
    var f = document.createElement('form');
    f.method = 'post';
    f.innerHTML = '<input type="hidden" name="action" value="delete_month"><input type="hidden" name="del_month" value="' + month + '">';
    document.body.appendChild(f);
    f.submit();
}
// 查看订单详情
function showDetail(d){
    var raw = d.raw || {};
    var html = '<div class="mb-2"><b>订单ID：</b>' + d.id + '</div>'
             + '<div class="mb-2"><b>订单号：</b>' + (d.order_no ? '<span class="text-monospace">' + d.order_no + '</span>' : '<span class="text-muted">无</span>') + '</div>'
             + '<div class="mb-2"><b>金额：</b>¥' + d.amount + '</div>'
             + '<div class="mb-2"><b>日期：</b>' + d.date + '</div>'
             + (d.status ? '<div class="mb-2"><b>订单状态：</b>' + d.status + '</div>' : '')
             + (d.reason ? '<div class="mb-2"><b>异常原因：</b><span class="text-warning">' + d.reason + '</span></div>' : '');
    if (Object.keys(raw).length) {
        html += '<hr><h6>原始数据</h6><table class="table table-sm table-bordered"><tbody>';
        Object.keys(raw).forEach(function(k){
            html += '<tr><th style="width:40%">' + k + '</th><td>' + raw[k] + '</td></tr>';
        });
        html += '</tbody></table>';
    } else {
        html += '<hr><p class="text-muted">无原始数据</p>';
    }
    document.getElementById('detailBody').innerHTML = html;
    $('#detailModal').modal('show');
}
