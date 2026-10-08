
function showVerifyStatusModal() {
    var project = '<?php echo addslashes($expand_project); ?>';
    var month = '<?php echo addslashes($expand_month); ?>';
    var empName = '<?php echo addslashes($locked_employee ? ($locked_employee['name'] ?? '') : ''); ?>';
    var dept = '<?php echo addslashes($filter_dept); ?>';
    if (!project) {
        alert('请先选择模块');
        return;
    }
    $('#verifyProject').text(project);
    var desc = month ? month.substring(0, 4) + '年' + month.substring(5) + '月' : '全部月份';
    if (empName) desc += ' / ' + empName;
    else if (dept) desc += ' / ' + dept;
    $('#verifyMonth').text(desc);
    $('#verifyStatusModal').modal('show');
}

function doVerify(verifyType) {
    var project = '<?php echo addslashes($expand_project); ?>';
    var month = '<?php echo addslashes($expand_month); ?>';
    var employeeId = '<?php echo (int)($filter_employee); ?>';
    var department = '<?php echo addslashes($filter_dept); ?>';
    var deptOrders = '<?php echo $filter_dept_orders ? '1' : ''; ?>';
    var abnormal = '<?php echo $filter_abnormal ? '1' : ''; ?>';
    var refund = '<?php echo $filter_refund ? '1' : ''; ?>';
    var searchNo = '<?php echo addslashes($filter_search); ?>';
    var label = verifyType === 'shipped' ? '已发货' : '交易成功';
    var monthDesc = month ? month.substring(0, 4) + '年' + month.substring(5) + '月' : '全部月份';
    if (!confirm('将以「' + label + '」为标准，核验订单状态和金额，不符合的将标记为异常，确定继续？')) return;
    var btn = event.target.closest('button');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 核验中...'; }
    $.ajax({
        url: '<?php echo BASE_URL; ?>/orders/index.php',
        type: 'POST',
        data: {
            action: 'verify_status',
            project: project,
            month: month,
            verify_type: verifyType,
            employee_id: employeeId,
            department: department,
            dept_orders: deptOrders,
            abnormal: abnormal,
            refund: refund,
            search_no: searchNo
        },
        dataType: 'json',
        success: function(res) {
            if (btn) { btn.disabled = false; btn.innerHTML = label; }
            if (res.ok) {
                var msg = '核验完成！共 ' + res.total + ' 条订单';
                if (res.updated > 0) msg += '，更新 ' + res.updated + ' 条状态';
                msg += '\n正常: ' + res.normal + ' 条\n异常: ' + res.abnormal + ' 条';
                alert(msg);
                location.reload();
            } else {
                alert('核验失败: ' + (res.msg || '未知错误'));
            }
        },
        error: function() {
            if (btn) { btn.disabled = false; btn.innerHTML = label; }
            alert('请求失败，请重试');
        }
    });
}
