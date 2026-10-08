
var allEmployees = <?php echo json_encode($employees, JSON_UNESCAPED_UNICODE); ?>;

// 切换个人/部门订单显示字段
function toggleScopeFields() {
    var isDept = document.getElementById('scopeDept') && document.getElementById('scopeDept').checked;
    var pf = document.getElementById('personalFields');
    var df = document.getElementById('deptFields');
    if (!pf || !df) return;
    pf.style.display = isDept ? 'none' : '';
    df.style.display = isDept ? '' : 'none';
    // 部门订单模式下隐藏统一的"对应项目分成模块"（每个合作人员各自配置）
    var pg = document.getElementById('uploadProjectGroup');
    if (pg) pg.style.display = isDept ? 'none' : '';
    // 个人字段的 required
    var empSel = document.getElementById('uploadEmp');
    if (empSel) empSel.required = !isDept;
}

function loadEmployees(prefix) {
    var dept = $('#' + prefix + 'Dept').val();
    var $sel = $('#' + prefix + 'Emp');
    $sel.empty();
    if (!dept) {
        $sel.append('<option value="">-- 请先选择部门 --</option>');
        return;
    }
    $sel.append('<option value="">-- 选择合作人员 --</option>');
    allEmployees.forEach(function(emp) {
        if (emp.department === dept) {
            $sel.append('<option value="' + emp.id + '">' + emp.name + '</option>');
        }
    });
    // 只有一个合作人员时自动选中并触发模块加载
    if ($sel.find('option[value!=""]').length === 1) {
        $sel.find('option[value!=""]').prop('selected', true);
        $sel.trigger('change');
    }
}

// 选择合作人员后，加载该合作人员的项目分成模块为勾选框列表
function loadEmployeeModules(empId, prefix) {
    var $proj = $('#' + prefix + 'Project');
    $proj.empty();
    if (!empId) return;
    $.get('<?php echo BASE_URL; ?>/orders/index.php?employee_id=' + empId + '&ajax=modules', function(data) {
        if (data && data.length) {
            data.forEach(function(m, i) {
                var cid = prefix + 'Proj_' + i;
                $proj.append(
                    '<div class="custom-control custom-checkbox mb-1">' +
                        '<input type="checkbox" name="upload_project[]" value="' + m.name + '" class="custom-control-input" id="' + cid + '">' +
                        '<label class="custom-control-label" for="' + cid + '">' + m.label + '</label>' +
                    '</div>'
                );
            });
        } else {
            $proj.append('<p class="text-muted small mb-0">（该合作人员未配置项目分成模块，请先去算法设置）</p>');
        }
    }, 'json').fail(function() {
        $proj.append('<p class="text-muted small mb-0">加载失败</p>');
    });
}

// 部门订单：选部门后重置合作人员行
function loadDeptEmployees(dept) {
    $('#deptEmpRows').empty();
    $('#ownershipFieldsGroup').toggle(!!dept);
    if (dept) addDeptEmpRow();
}

// 合作人员名→id 映射（按当前所选部门过滤后重建）
var deptEmpMap = {};
function rebuildDeptEmpMap(dept) {
    deptEmpMap = {};
    allEmployees.forEach(function(emp) {
        if (!dept || emp.department === dept) {
            deptEmpMap[emp.name + '（' + (emp.department || '') + '）'] = emp.id;
            deptEmpMap[emp.name] = emp.id;
        }
    });
}

var deptEmpSeq = 0;
// 添加一行合作人员+模块选择（datalist 搜索下拉，可输入匹配也可直接选）
function addDeptEmpRow() {
    var dept = $('#uploadDeptName').val();
    rebuildDeptEmpMap(dept);
    var seq = ++deptEmpSeq;
    var datalistId = 'deptEmpList_' + seq;
    // 构建 datalist 候选项
    var opts = '';
    allEmployees.forEach(function(emp) {
        if (!dept || emp.department === dept) {
            var label = emp.name + '（' + (emp.department || '') + '）';
            opts += '<option value="' + label.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '">';
        }
    });
    var row = $(
        '<div class="dept-emp-row d-flex align-items-center mb-1" style="gap:6px">' +
            '<div style="flex:1;min-width:0">' +
                '<input type="text" class="form-control form-control-sm dept-emp-search" list="' + datalistId + '" placeholder="输入姓名搜索选择…" autocomplete="off">' +
                '<datalist id="' + datalistId + '">' + opts + '</datalist>' +
                '<input type="hidden" class="dept-emp-id">' +
            '</div>' +
            '<select class="form-control form-control-sm dept-mod-sel" style="flex:1"><option value="">-- 不指定 --</option></select>' +
            '<button type="button" class="btn btn-sm btn-outline-danger" onclick="$(this).closest(\'.dept-emp-row\').remove()"><i class="fas fa-times"></i></button>' +
        '</div>'
    );
    $('#deptEmpRows').append(row);

    // 输入/选中后同步 employee_id 并加载该合作人员的项目分成模块
    var $search = row.find('.dept-emp-search');
    var $id = row.find('.dept-emp-id');
    var lastEmpId = undefined; // 防止 input+change 重复触发
    $search.on('input change', function() {
        var text = $(this).val().trim();
        var empId = deptEmpMap[text] || '';
        if (empId === lastEmpId) return; // 同一合作人员不重复加载
        lastEmpId = empId;
        $id.val(empId);
        var $modSel = row.find('.dept-mod-sel');
        $modSel.empty().append('<option value="">-- 不指定 --</option>');
        if (empId) {
            $.get('<?php echo BASE_URL; ?>/orders/index.php?employee_id=' + empId + '&ajax=modules', function(data) {
                if (data && data.length) {
                    data.forEach(function(m) {
                        $modSel.append('<option value="' + m.name + '">' + m.label + '</option>');
                    });
                }
            }, 'json');
        }
    });
}

// 提交前把所有行序列化到隐藏字段
$('#uploadForm').on('submit', function() {
    var scope = $('input[name="order_scope"]:checked').val();
    if (scope === 'department') {
        var rows = [];
        $('#deptEmpRows .dept-emp-row').each(function() {
            var empId = $(this).find('.dept-emp-id').val();
            var mod   = $(this).find('.dept-mod-sel').val();
            if (empId) rows.push({employee_id: empId, module: mod});
        });
        $('#deptEmpModules').val(JSON.stringify(rows));
        $('#ownershipFieldsHidden').val($('#ownershipFields').val().trim());
    }
});

<?php if (!$locked_employee): ?>
// 非锁定模式：合作人员选择变化时动态加载模块
$(document).ready(function() {
    $('#uploadEmp').on('change', function() { loadEmployeeModules($(this).val(), 'upload'); });
    $('#manualEmp').on('change', function() { loadEmployeeModules($(this).val(), 'manual'); });
});
<?php endif; ?>

// 上传区域点击 & 拖拽
(function() {
    var area = document.getElementById('uploadArea');
    var input = document.getElementById('excelFile');
    var tip = document.getElementById('uploadTip');
    var dragCount = 0;

    // 点击区域打开文件选择
    area.addEventListener('click', function() {
        input.click();
    });

    area.addEventListener('dragenter', function(e) {
        e.preventDefault();
        dragCount++;
        area.classList.add('dragover');
    });

    area.addEventListener('dragover', function(e) {
        e.preventDefault();
    });

    area.addEventListener('dragleave', function(e) {
        dragCount--;
        if (dragCount === 0) area.classList.remove('dragover');
    });

    area.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        dragCount = 0;
        area.classList.remove('dragover');
        var files = e.dataTransfer.files;
        if (!files || !files.length) return;
        handleFile(files[0]);
    });

    input.addEventListener('change', function() {
        if (input.files[0]) handleFile(input.files[0]);
    });

    function handleFile(file) {
        var ext = file.name.split('.').pop().toLowerCase();
        if (['xls','xlsx','csv'].indexOf(ext) === -1) {
            tip.textContent = '格式不支持，请上传 .xls / .xlsx / .csv';
            tip.style.color = '#dc3545';
            return;
        }
        tip.textContent = '解析中：' + file.name + ' ...';
        tip.style.color = '#6c757d';

        var reader = new FileReader();
        reader.onload = function(e) {
            try {
                var data = new Uint8Array(e.target.result);
                // raw:true 完全不做任何转换，所有值保持原始
                var wb = XLSX.read(data, {type: 'array', raw: true});
                var ws = wb.Sheets[wb.SheetNames[0]];
                var range = XLSX.utils.decode_range(ws['!ref'] || 'A1');

                // 逐行逐列读取，完全原样，不做任何判断或转换
                var rows = [];
                for (var r = range.s.r; r <= range.e.r; r++) {
                    var row = [];
                    var hasVal = false;
                    for (var c = range.s.c; c <= range.e.c; c++) {
                        var cell = ws[XLSX.utils.encode_cell({r: r, c: c})];
                        var val = '';
                        if (cell && cell.v !== undefined && cell.v !== null) {
                            val = String(cell.v);
                            hasVal = true;
                        }
                        row.push(val);
                    }
                    if (hasVal) rows.push(row);
                }

                // 拼 JSON 传输（避免 CSV 逗号/换行导致列错位）
                var hidden = document.getElementById('csvData');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'csv_data';
                    hidden.id = 'csvData';
                    input.parentNode.appendChild(hidden);
                }
                hidden.value = JSON.stringify(rows);
                // 清空 file input，防止服务端走旧的文件解析
                input.value = '';
                tip.textContent = file.name + ' ✓（已解析 ' + (rows.length - 1) + ' 行数据）';
                tip.style.color = '#28a745';
            } catch(err) {
                tip.textContent = '解析失败：' + err.message;
                tip.style.color = '#dc3545';
            }
        };
        reader.readAsArrayBuffer(file);
    }
})();

<?php if ($expand_project !== ''): ?>
window.addEventListener('load', function() {
    if (window.jQuery && $('#orderDetailModal').modal) {
        $('#orderDetailModal').modal('show');
    }
});
<?php endif; ?>

// 月份批量删除：更新已选月份计数
$(function() {
    if (typeof $ === 'function' && $('.month-check').length) {
        $('.month-check').on('change', function() {
            var n = $('.month-check:checked').length;
            var sc = document.getElementById('monthSelCount');
            if (sc) sc.textContent = n;
        });
    }
});

// 删除模块全部订单
var jsEscape = function(s) { return s.replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"'); };
function deleteProject(project, count, empId, dept, deptOrders) {
    if (!confirm('确定删除模块「' + project + '」的全部 ' + count + ' 条订单？此操作不可恢复！')) return;
    var f = document.createElement('form');
    f.method = 'post';
    var html = '<input type="hidden" name="action" value="delete_project">'
        + '<input type="hidden" name="project" value="' + jsEscape(project) + '">';
    if (empId > 0) html += '<input type="hidden" name="employee_id" value="' + empId + '">';
    if (dept) html += '<input type="hidden" name="department" value="' + jsEscape(dept) + '">';
    if (deptOrders) html += '<input type="hidden" name="dept_orders" value="1">';
    f.innerHTML = html;
    document.body.appendChild(f);
    f.submit();
}

// 取消选择 & 单条删除
(function() {
    window.clearSelection = function() {
        document.querySelectorAll('.row-check').forEach(function(cb) { cb.checked = false; });
        var ca = document.getElementById('checkAll');
        if (ca) { ca.checked = false; ca.indeterminate = false; }
        var bb = document.getElementById('batchBar');
        if (bb) bb.style.display = 'none';
        var st = document.getElementById('selectedCount');
        if (st) st.textContent = '已选 0 条';
    };

    window.deleteSingle = function(id) {
        if (!confirm('确定删除该订单？')) return;
        document.getElementById('singleDeleteId').value = id;
        document.getElementById('singleDeleteForm').submit();
    };

    window.editOrder = function(id) {
        // back 带上当前页面URL（模块明细/筛选），让编辑页“返回上一层”能回到本页，而不是总列表
        window.location.href = '<?php echo BASE_URL; ?>/orders/edit.php?id=' + id
            + '&back=' + encodeURIComponent(location.href);
    };
})();
