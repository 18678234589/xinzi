
function escHtml(s){ if(s===null||s===undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function pad2(n){ n = Number(n); if (isNaN(n)) return ''; return String(n).length < 2 ? '0'+n : String(n); }
function renderUploadView(d){
    var html = '', i;
    if(!d || !d.found){ $('#uvBody').html('<div class="alert alert-warning mb-0">未找到该上传记录</div>'); return; }
    var log = d.log;
    if(log){
        html += '<div class="alert alert-light border mb-3 py-2">导入时间 <b>'+escHtml(log.created_at)+'</b>；匹配 <b>'+escHtml(log.matched)+'</b> 人，未匹配 <b>'+escHtml(log.pending)+'</b> 条，错误 <b>'+escHtml(log.errors)+'</b> 条</div>';
    }
    if(log && log.errors > 0 && log.detail && log.detail.length){
        html += '<div class="alert alert-danger py-2"><strong>错误明细：</strong><ul class="mb-0">';
        for(i=0;i<log.detail.length;i++){ html += '<li>'+escHtml(log.detail[i])+'</li>'; }
        html += '</ul></div>';
    }
    if(d.matched && d.matched.length){
        html += '<h6 class="font-weight-bold text-primary mt-3"><i class="fas fa-user-check"></i> 已匹配数据（'+d.matched.length+'）</h6>';
        html += '<div class="table-responsive"><table class="table table-sm table-bordered mb-3"><thead class="thead-light"><tr><th>合作人员</th><th>旺旺</th><th>部门</th><th>月份</th><th>净销售额</th><th>转化率%</th><th>下单人数</th><th>回复率%</th><th>响应(秒)</th></tr></thead><tbody>';
        for(i=0;i<d.matched.length;i++){
            var r = d.matched[i];
            var deriv = (r.order_count>0 && Number(r.incoming_count)>0) ? '（转化率=下单'+r.order_count+'÷询单'+r.incoming_count+'）' : '';
            html += '<tr><td>'+escHtml(r.emp_name||'')+'</td><td>'+escHtml(r.emp_wang||'')+'</td><td>'+escHtml(r.department||'')+'</td>'
                  + '<td>'+escHtml(r.year)+'-'+pad2(r.month)+'</td><td>'+escHtml(r.net_sales)+'</td>'
                  + '<td title="'+escHtml(deriv)+'">'+escHtml(r.inquiry_conv)+(deriv?' <span style="font-size:11px">'+escHtml('('+r.order_count+'÷'+r.incoming_count+')')+'</span>':'')+'</td>'
                  + '<td>'+escHtml(r.order_count||0)+'</td>'
                  + '<td>'+escHtml(r.wangwang_reply)+'</td><td>'+escHtml(r.reply_speed)+'</td></tr>';
        }
        html += '</tbody></table></div>';
    } else {
        html += '<div class="alert alert-warning py-2"><i class="fas fa-exclamation-triangle"></i> 该次上传未匹配到任何合作人员数据（matched=0）。</div>';
    }
    if(d.pending && d.pending.length){
        html += '<h6 class="font-weight-bold text-warning mt-3"><i class="fas fa-user-clock"></i> 未匹配暂存（'+d.pending.length+'）</h6>';
        html += '<div class="table-responsive"><table class="table table-sm table-bordered mb-3"><thead class="thead-light"><tr><th>旺旺</th><th>姓名</th><th>月份</th><th>进线</th><th>回复总秒</th><th>净销售额</th><th>转化率%</th><th>下单人数</th><th>回复率%</th></tr></thead><tbody>';
        for(i=0;i<d.pending.length;i++){
            var p = d.pending[i];
            var deriv = (p.order_count>0 && Number(p.incoming_count)>0) ? '（转化率=下单'+p.order_count+'÷询单'+p.incoming_count+'）' : '';
            html += '<tr><td>'+escHtml(p.wangwang||'')+'</td><td>'+escHtml(p.name||'')+'</td><td>'+escHtml(p.year)+'-'+pad2(p.month)+'</td>'
                  + '<td>'+escHtml(p.incoming_count)+'</td><td>'+escHtml(p.total_reply_seconds)+'</td><td>'+escHtml(p.net_sales)+'</td>'
                  + '<td title="'+escHtml(deriv)+'">'+escHtml(p.inquiry_conv)+(deriv?' <span style="font-size:11px">'+escHtml('('+p.order_count+'÷'+p.incoming_count+')')+'</span>':'')+'</td>'
                  + '<td>'+escHtml(p.order_count||0)+'</td>'
                  + '<td>'+escHtml(p.wangwang_reply)+'</td></tr>';
        }
        html += '</tbody></table></div>';
    } else {
        html += '<div class="alert alert-info py-2"><i class="fas fa-check"></i> 无未匹配暂存。</div>';
    }
    $('#uvBody').html(html);
}
$(document).ready(function(){
    $(document).on('click', '.view-upload-btn', function(){
        var src  = $(this).attr('data-source');
        var name = $(this).attr('data-name');
        $('#uvSource').text(name);
        $('#uvBody').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> 加载中...</div>');
        $('#uploadViewModal').modal('show');
        fetch('index.php?action=upload_view&source='+encodeURIComponent(src))
            .then(function(r){ return r.json(); })
            .then(function(d){ renderUploadView(d); })
            .catch(function(){ $('#uvBody').html('<div class="alert alert-danger mb-0">加载失败，请重试</div>'); });
    });
});
