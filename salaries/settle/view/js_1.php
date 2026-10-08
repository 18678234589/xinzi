
// 合作人员名→id 映射（原生JS，不依赖jQuery）
var empMap = {};
<?php foreach ($employees as $emp): ?>
empMap[<?php echo json_encode($emp['name'] . '（' . ($emp['department'] ?? '') . '）', JSON_UNESCAPED_UNICODE); ?>] = <?php echo $emp['id']; ?>;
empMap[<?php echo json_encode($emp['name'], JSON_UNESCAPED_UNICODE); ?>] = <?php echo $emp['id']; ?>;
<?php endforeach; ?>

// datalist 选中/输入后同步 employee_id
function syncEmpId() {
    var text = document.getElementById('empSearch').value.trim();
    var hidden = document.getElementById('empId');
    hidden.value = empMap[text] || '';
}

// ===== 自定义额外金额：动态多行 =====
function addExtraRow(amount, remark) {
    var container = document.getElementById('extraItems');
    if (!container) return;
    var row = document.createElement('div');
    row.className = 'input-group input-group-sm mb-1';
    row.innerHTML =
        '<div class="input-group-prepend"><span class="input-group-text">¥</span></div>' +
        '<input type="number" name="extra_amounts[]" class="form-control" step="0.01" placeholder="金额，如 -50 或 100" value="' + (amount || '') + '">' +
        '<input type="text" name="extra_remarks[]" class="form-control" placeholder="备注（可选）" value="' + (remark || '').replace(/"/g, '&quot;') + '">' +
        '<div class="input-group-append">' +
            '<button type="button" class="btn btn-outline-danger" onclick="removeExtraRow(this)" title="删除"><i class="fas fa-times"></i></button>' +
        '</div>';
    container.appendChild(row);
}
function removeExtraRow(btn) {
    var row = btn.closest('.input-group');
    if (row) row.remove();
}

// 选部门后筛选 datalist 候选
function loadEmp() {
    var dept = document.getElementById('deptSel').value;
    var list = document.getElementById('empList');
    var search = document.getElementById('empSearch');
    search.value = '';
    document.getElementById('empId').value = '';
    list.innerHTML = '';
    var emps = <?php echo json_encode($employees, JSON_UNESCAPED_UNICODE); ?>;
    emps.forEach(function(emp) {
        if (!dept || emp.department === dept) {
            var opt = document.createElement('option');
            opt.value = emp.name + '（' + (emp.department || '') + '）';
            opt.setAttribute('data-id', emp.id);
            list.appendChild(opt);
        }
    });
}

// 表单提交前确保 employee_id 有值
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('settleForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            syncEmpId();
            if (!document.getElementById('empId').value) {
                e.preventDefault();
                alert('请从列表中选择一名合作人员。');
                document.getElementById('empSearch').focus();
            }
        });
    }
    // 输入时也实时同步
    var search = document.getElementById('empSearch');
    if (search) search.addEventListener('input', syncEmpId);

    // 从 POST 数据回填已输入的行
    var postedAmounts = <?php echo json_encode($_POST['extra_amounts'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
    var postedRemarks = <?php echo json_encode($_POST['extra_remarks'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
    if (postedAmounts.length > 0) {
        postedAmounts.forEach(function(amt, i) {
            addExtraRow(amt, postedRemarks[i] || '');
        });
    } else {
        addExtraRow(0, '');  // 默认一行
    }
});
