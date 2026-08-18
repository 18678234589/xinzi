<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
ensureCsPerfSchema();

$page_title = '客服绩效 - 绩效方案/算法';

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'scheme_save') {
        $id = (int)($_POST['id'] ?? 0);
        // 每个指标的「启用」勾选框：未勾选则权重按 0 处理（该指标不参与加权）
        $w = function($enName, $wName, $def) {
            return !empty($_POST[$enName]) ? (float)($_POST[$wName] ?? $def) : 0.0;
        };
        $p = [
            'name'             => trim($_POST['name'] ?? ''),
            'w_net_sales'      => $w('en_net_sales', 'w_net_sales', 43),
            't_net_sales'      => (float)($_POST['t_net_sales'] ?? 0),
            'w_inquiry_conv'   => $w('en_inquiry_conv', 'w_inquiry_conv', 30),
            't_inquiry_conv'   => (float)($_POST['t_inquiry_conv'] ?? 0),
            'w_wangwang_reply' => $w('en_wangwang_reply', 'w_wangwang_reply', 17),
            't_wangwang_reply' => (float)($_POST['t_wangwang_reply'] ?? 0),
            'w_avg_response'   => $w('en_avg_response', 'w_avg_response', 15),
            't_avg_response'   => (float)($_POST['t_avg_response'] ?? 0),
            'floor_pct'        => $_POST['floor_pct'] ?? 0,
            'cap_pct'          => $_POST['cap_pct'] ?? 0,
            'is_default'       => !empty($_POST['is_default']) ? 1 : 0,
        ];
        if (save_cs_perf_scheme($id, $p)) {
            $msg = '方案已保存';
            // 若未设默认方案，把新保存的设为默认
            $anyDef = (int)db()->query("SELECT COUNT(*) FROM cs_perf_schemes WHERE is_default=1")->fetchColumn();
            if ($anyDef === 0) {
                $minId = (int)db()->query("SELECT MIN(id) FROM cs_perf_schemes")->fetchColumn();
                if ($minId > 0) db()->exec("UPDATE cs_perf_schemes SET is_default=1 WHERE id=$minId");
            }
        } else {
            $err = '方案名不能为空，保存失败';
        }
    } elseif ($action === 'scheme_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (delete_cs_perf_scheme($id)) {
            $msg = '方案已删除';
        } else {
            $err = '删除失败';
        }
    } elseif ($action === 'dept_save') {
        $dept = trim($_POST['department'] ?? '');
        $old  = trim($_POST['old_department'] ?? '');
        // 该 action 下的「删除」按钮通过 submit_btn=delete 触发
        if (($_POST['submit_btn'] ?? '') === 'delete') {
            delete_cs_perf_dept_config($old !== '' ? $old : $dept);
            $msg = '已删除该部门绩效配置';
        } else {
            $sid  = (int)($_POST['scheme_id'] ?? 0);
            $base = (float)($_POST['base'] ?? 0);
            if (save_cs_perf_dept_config($dept, $sid, $base, $old)) {
                $msg = '部门绩效配置已保存';
            } else {
                $err = '部门名不能为空，保存失败';
            }
        }
    } elseif ($action === 'dept_delete') {
        $dept = trim($_POST['department'] ?? '');
        if (delete_cs_perf_dept_config($dept)) {
            $msg = '已删除该部门绩效配置';
        } else {
            $err = '删除失败';
        }
    }
}

$schemes = get_cs_perf_schemes();
$deptCfg = get_cs_perf_dept_configs();

// 自动抓取目标基准值：返回某月绩效实际平均值 JSON（默认上个月，供编辑器按钮填充目标）
if (($_GET['action'] ?? '') === 'suggest_targets' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $s = get_cs_perf_target_suggestions((int)($_GET['year'] ?? 0), (int)($_GET['month'] ?? 0));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($s === null ? ['found' => false] : array_merge(['found' => true], $s), JSON_UNESCAPED_UNICODE);
    exit;
}

// 编辑器「自动抓取」默认月份 = 上个月
$prevY = (int)date('Y', strtotime('-1 month'));
$prevM = (int)date('n', strtotime('-1 month'));

// 供前端加载已知员工的部门下拉（在“添加部门”时可选已有部门）
$deptOptions = [];
try {
    $deptRows = db()->query("SELECT DISTINCT department FROM employees WHERE department<>'' ORDER BY department")->fetchAll();
    foreach ($deptRows as $d) $deptOptions[] = (string)$d['department'];
} catch (\Throwable $e) {}

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>
<script>
function esc2(s){ if(s===null||s===undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }
// 启用勾选框控制该指标的权重/目标输入框（未启用的指标权重按0提交，PHP 侧兜底）
function refreshEnableState(){
    [['s_en_net_sales','s_w_net_sales','s_t_net_sales'],
     ['s_en_inquiry_conv','s_w_inquiry_conv','s_t_inquiry_conv'],
     ['s_en_wangwang_reply','s_w_wangwang_reply','s_t_wangwang_reply'],
     ['s_en_avg_response','s_w_avg_response','s_t_avg_response']].forEach(function(p){
        var cb = document.getElementById(p[0]);
        if (!cb) return;
        var on = cb.checked;
        document.getElementById(p[1]).disabled = !on;
        document.getElementById(p[2]).disabled = !on;
    });
}
document.addEventListener('DOMContentLoaded', function(){
    [['s_en_net_sales','s_w_net_sales','s_t_net_sales'],
     ['s_en_inquiry_conv','s_w_inquiry_conv','s_t_inquiry_conv'],
     ['s_en_wangwang_reply','s_w_wangwang_reply','s_t_wangwang_reply'],
     ['s_en_avg_response','s_w_avg_response','s_t_avg_response']].forEach(function(p){
        var cb = document.getElementById(p[0]);
        if (!cb) return;
        cb.addEventListener('change', refreshEnableState);
    });
});
function fillScheme(obj){
    document.getElementById('s_id').value = obj ? obj.id : 0;
    document.getElementById('s_name').value = obj ? obj.name : '';
    document.getElementById('s_default').checked = !!(obj && obj.is_default == 1);
    document.getElementById('s_en_net_sales').checked = !!(obj && parseFloat(obj.w_net_sales)>0);
    document.getElementById('s_w_net_sales').value = obj?obj.w_net_sales:43;
    document.getElementById('s_t_net_sales').value = obj?obj.t_net_sales:0;
    document.getElementById('s_en_inquiry_conv').checked = !!(obj && parseFloat(obj.w_inquiry_conv)>0);
    document.getElementById('s_w_inquiry_conv').value = obj?obj.w_inquiry_conv:30;
    document.getElementById('s_t_inquiry_conv').value = obj?obj.t_inquiry_conv:0;
    document.getElementById('s_en_wangwang_reply').checked = !!(obj && parseFloat(obj.w_wangwang_reply)>0);
    document.getElementById('s_w_wangwang_reply').value = obj?obj.w_wangwang_reply:17;
    document.getElementById('s_t_wangwang_reply').value = obj?obj.t_wangwang_reply:0;
    document.getElementById('s_en_avg_response').checked = !!(obj && parseFloat(obj.w_avg_response)>0);
    document.getElementById('s_w_avg_response').value = obj?obj.w_avg_response:15;
    document.getElementById('s_t_avg_response').value = obj?obj.t_avg_response:0;
    document.getElementById('s_floor').value = obj?obj.floor_pct:0;
    document.getElementById('s_cap').value   = obj?obj.cap_pct:0;
    refreshEnableState();
    document.getElementById('schemeEditor').style.display = '';
    document.getElementById('schemeEditor').scrollIntoView({behavior:'smooth',block:'start'});
}
// 自动抓取目标基准值：按所选月份所有绩效员工实际平均值填入目标（默认上个月）
function autoFetchTargets(){
    var y = document.getElementById('s_fetch_year').value;
    var m = document.getElementById('s_fetch_month').value;
    var info = document.getElementById('s_fetch_info');
    info.className = 'small ml-3 text-muted';
    info.textContent = '抓取中…';
    fetch('schemes.php?action=suggest_targets&year='+encodeURIComponent(y)+'&month='+encodeURIComponent(m))
        .then(function(r){ return r.json(); })
        .then(function(d){
            if(!d.found){
                info.className = 'small ml-3 text-warning';
                info.textContent = '该月没有绩效数据，目标保持原值（可换月份重试）';
                return;
            }
            var mm = ('0'+d.month).slice(-2);
            document.getElementById('s_en_net_sales').checked = true;
            document.getElementById('s_t_net_sales').value = d.avg_sales;
            document.getElementById('s_en_inquiry_conv').checked = true;
            document.getElementById('s_t_inquiry_conv').value = d.avg_conv;
            document.getElementById('s_en_wangwang_reply').checked = true;
            document.getElementById('s_t_wangwang_reply').value = d.avg_reply;
            document.getElementById('s_en_avg_response').checked = true;
            document.getElementById('s_t_avg_response').value = d.avg_resp;
            refreshEnableState();
            info.className = 'small ml-3 text-success';
            info.textContent = '已按 ' + d.year + '-' + mm + ' 月 ' + d.emp_count + ' 人平均值填入：净销售额 ' + d.avg_sales
                + ' 元 / 转化率 ' + d.avg_conv + '% / 回复率 ' + d.avg_reply + '% / 响应 ' + d.avg_resp + ' 秒，请核对后保存';
        })
        .catch(function(e){
            info.className = 'small ml-3 text-danger';
            info.textContent = '抓取失败：' + e;
        });
}
</script>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-tasks"></i> 客服绩效 - 绩效方案/算法</h4>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left"></i> 返回总览</a>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($msg); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($err); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<!-- 绩效方案列表 -->
<div class="card mb-3">
    <div class="card-header"><i class="fas fa-cubes"></i> 绩效方案（算法）列表</div>
    <div class="card-body">
        <p class="text-muted small mb-2">
            绩效金额 = 部门绩效基数 × 综合达成率。综合达成率由「净销售额 / 询单最终下单转化率 / 旺旺回复率 / 平均响应时长」四项中<strong>已启用</strong>的指标（权重&gt;0 且目标已配置）按权重加权得出；可设保底/封顶。
            默认权重建议：净销售额43%、询单转化率30%、旺旺回复率17%、平均响应时长15%。
        </p>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>名称</th><th>净销售额</th><th>询单转化率</th><th>旺旺回复率</th><th>平均响应</th><th>保底/封顶</th><th style="width:140px">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($schemes as $s): ?>
                    <tr>
                        <td><?php echo e($s['name']); ?><?php if ($s['is_default']): ?> <span class="badge badge-info">默认</span><?php endif; ?></td>
                        <td>
                            <?php echo (float)$s['w_net_sales'] > 0 && (float)$s['t_net_sales'] > 0 ? 'w' . $s['w_net_sales'] . ' 目标' . number_format((float)$s['t_net_sales'], 2) . '元' : '<span class="text-muted">无</span>'; ?>
                        </td>
                        <td>
                            <?php echo (float)$s['w_inquiry_conv'] > 0 && (float)$s['t_inquiry_conv'] > 0 ? 'w' . $s['w_inquiry_conv'] . ' 目标' . $s['t_inquiry_conv'] . '%' : '<span class="text-muted">无</span>'; ?>
                        </td>
                        <td>
                            <?php echo (float)$s['w_wangwang_reply'] > 0 && (float)$s['t_wangwang_reply'] > 0 ? 'w' . $s['w_wangwang_reply'] . ' 目标' . $s['t_wangwang_reply'] . '%' : '<span class="text-muted">无</span>'; ?>
                        </td>
                        <td>
                            <?php echo (float)$s['w_avg_response'] > 0 && (float)$s['t_avg_response'] > 0 ? 'w' . $s['w_avg_response'] . ' 目标' . $s['t_avg_response'] . 's' : '<span class="text-muted">无</span>'; ?>
                        </td>
                        <td><?php echo ((float)$s['floor_pct'] > 0 ? '保底' . $s['floor_pct'] . '%' : '-') . ' / ' . ((float)$s['cap_pct'] > 0 ? '封顶' . $s['cap_pct'] . '%' : '-'); ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick='fillScheme(<?php echo json_encode([
                                'id'=>$s['id'],'name'=>$s['name'],'is_default'=>$s['is_default'],
                                'w_net_sales'=>$s['w_net_sales'],'t_net_sales'=>$s['t_net_sales'],
                                'w_inquiry_conv'=>$s['w_inquiry_conv'],'t_inquiry_conv'=>$s['t_inquiry_conv'],
                                'w_wangwang_reply'=>$s['w_wangwang_reply'],'t_wangwang_reply'=>$s['t_wangwang_reply'],
                                'w_avg_response'=>$s['w_avg_response'],'t_avg_response'=>$s['t_avg_response'],
                                'floor_pct'=>$s['floor_pct'],'cap_pct'=>$s['cap_pct'],
                            ], JSON_UNESCAPED_UNICODE); ?>);'><i class="fas fa-edit"></i> 编辑</button>
                            <form method="post" class="d-inline" onsubmit="return confirm('删除该方案？被引用的部门将回退到默认方案。')">
                                <input type="hidden" name="action" value="scheme_delete">
                                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$schemes): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">暂无方案，点击下方「新增方案」创建</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <button type="button" class="btn btn-sm btn-success mt-2" onclick='fillScheme(null)'><i class="fas fa-plus"></i> 新增方案</button>
    </div>
</div>

<!-- 方案编辑 -->
<div class="card mb-3 border-primary" id="schemeEditor" style="display:none">
    <div class="card-header bg-primary text-white" id="schemeEditorTitle">新增方案</div>
    <div class="card-body">
        <!-- 自动抓取目标基准值（按所选月份实际平均值填目标） -->
        <div class="d-flex flex-wrap align-items-center mb-2 p-2 bg-light rounded">
            <span class="small text-muted mr-2"><i class="fas fa-magic"></i> 自动抓取目标基准值：按所选月份所有绩效员工的实际平均值换算，填入下方「目标基准值」</span>
            <select id="s_fetch_year" class="form-control form-control-sm" style="width:92px">
                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $prevY === $y ? 'selected' : ''; ?>><?php echo $y; ?>年</option>
                <?php endfor; ?>
            </select>
            <select id="s_fetch_month" class="form-control form-control-sm ml-1" style="width:78px">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $prevM === $m ? 'selected' : ''; ?>><?php echo $m; ?>月</option>
                <?php endfor; ?>
            </select>
            <button type="button" class="btn btn-sm btn-outline-success ml-2" onclick="autoFetchTargets()"><i class="fas fa-download"></i> 自动抓取</button>
            <span id="s_fetch_info" class="small ml-3 text-muted">默认抓取上个月（<?php echo $prevY; ?>年<?php echo $prevM; ?>月）；该月无数据时自动取最近有数据的月份。</span>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="scheme_save">
            <input type="hidden" name="id" id="s_id" value="0">
            <div class="form-row align-items-end mb-2">
                <div class="col">
                    <label class="small text-muted">方案名称</label>
                    <input type="text" name="name" id="s_name" class="form-control" required>
                </div>
                <div class="col-auto">
                    <label class="form-check mb-0 mt-3"><input type="checkbox" name="is_default" value="1" id="s_default" class="form-check-input"><span class="form-check-label">设为默认</span></label>
                </div>
            </div>
            <div class="table-responsive mt-2">
                <table class="table table-sm table-bordered mb-2" style="max-width:980px">
                    <thead class="thead-light">
                        <tr><th style="width:80px">启用</th><th style="width:140px">指标</th><th style="width:150px">权重(%)</th><th>目标基准值</th><th>达成率算法</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><input type="checkbox" name="en_net_sales" value="1" id="s_en_net_sales" class="form-check-input"></td>
                            <td class="align-middle">净销售额</td>
                            <td><input type="number" step="0.1" name="w_net_sales" id="s_w_net_sales" class="form-control form-control-sm" placeholder="43"></td>
                            <td><div class="input-group input-group-sm"><input type="number" step="any" name="t_net_sales" id="s_t_net_sales" class="form-control" placeholder="目标净销售额"><div class="input-group-append"><span class="input-group-text">元</span></div></div></td>
                            <td class="text-muted small align-middle">实际净销售额 ÷ 目标净销售额</td>
                        </tr>
                        <tr>
                            <td><input type="checkbox" name="en_inquiry_conv" value="1" id="s_en_inquiry_conv" class="form-check-input"></td>
                            <td class="align-middle">询单最终下单转化率</td>
                            <td><input type="number" step="0.1" name="w_inquiry_conv" id="s_w_inquiry_conv" class="form-control form-control-sm" placeholder="30"></td>
                            <td><div class="input-group input-group-sm"><input type="number" step="any" name="t_inquiry_conv" id="s_t_inquiry_conv" class="form-control" placeholder="目标转化率"><div class="input-group-append"><span class="input-group-text">%</span></div></div></td>
                            <td class="text-muted small align-middle">实际询单转化率% ÷ 目标转化率%</td>
                        </tr>
                        <tr>
                            <td><input type="checkbox" name="en_wangwang_reply" value="1" id="s_en_wangwang_reply" class="form-check-input"></td>
                            <td class="align-middle">旺旺回复率</td>
                            <td><input type="number" step="0.1" name="w_wangwang_reply" id="s_w_wangwang_reply" class="form-control form-control-sm" placeholder="17"></td>
                            <td><div class="input-group input-group-sm"><input type="number" step="any" name="t_wangwang_reply" id="s_t_wangwang_reply" class="form-control" placeholder="目标回复率"><div class="input-group-append"><span class="input-group-text">%</span></div></div></td>
                            <td class="text-muted small align-middle">实际旺旺回复率% ÷ 目标回复率%</td>
                        </tr>
                        <tr>
                            <td><input type="checkbox" name="en_avg_response" value="1" id="s_en_avg_response" class="form-check-input"></td>
                            <td class="align-middle">平均响应时长</td>
                            <td><input type="number" step="0.1" name="w_avg_response" id="s_w_avg_response" class="form-control form-control-sm" placeholder="15"></td>
                            <td><div class="input-group input-group-sm"><input type="number" step="any" name="t_avg_response" id="s_t_avg_response" class="form-control" placeholder="目标响应秒数"><div class="input-group-append"><span class="input-group-text">秒</span></div></div></td>
                            <td class="text-muted small align-middle">目标秒 ÷ 实际秒（越低越快越好）</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="form-row">
                <div class="col-md-3">
                    <label class="small text-muted">保底(基数的%)</label>
                    <input type="number" step="any" name="floor_pct" id="s_floor" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="small text-muted">封顶(基数的%)</label>
                    <input type="number" step="any" name="cap_pct" id="s_cap" class="form-control form-control-sm">
                </div>
            </div>
            <small class="text-muted d-block mt-1"><i class="fas fa-info-circle"></i> 权重即占比（默认 43/30/17/15）；未勾选或权重=0 的指标不参与加权；目标留空则该指标不计入加权。</small>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> 保存方案</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('schemeEditor').style.display='none'">取消</button>
            </div>
        </form>
    </div>
</div>

<!-- 部门基数配置 -->
<div class="card">
    <div class="card-header"><i class="fas fa-building"></i> 部门绩效基数/方案配置（基数按部门设置，部门可自定义添加）</div>
    <div class="card-body">
        <form method="post" class="form-row align-items-end mb-2">
            <input type="hidden" name="action" value="dept_save">
            <input type="hidden" name="old_department" value="">
            <div class="col-auto">
                <label class="small text-muted">部门</label>
                <input type="text" name="department" list="deptList" class="form-control form-control-sm" style="width:180px" required placeholder="输入部门名">
                <datalist id="deptList">
                    <?php foreach ($deptOptions as $d): ?><option value="<?php echo e($d); ?>"></option><?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-auto">
                <label class="small text-muted">绩效基数(元)</label>
                <input type="number" step="0.01" name="base" class="form-control form-control-sm" style="width:140px" value="0" required>
            </div>
            <div class="col-auto">
                <label class="small text-muted">绩效方案</label>
                <select name="scheme_id" class="form-control form-control-sm" style="width:160px">
                    <?php foreach ($schemes as $s): ?>
                        <option value="<?php echo (int)$s['id']; ?>" <?php echo $s['is_default'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> 新增部门配置</button>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="thead-light">
                    <tr><th>部门</th><th>绩效基数(元)</th><th>绩效方案</th><th style="width:160px">操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($deptCfg as $row): ?>
                    <tr>
                        <td colspan="4" class="p-1">
                            <form method="post" class="form-row align-items-center mb-0">
                                <input type="hidden" name="action" value="dept_save">
                                <input type="hidden" name="target_delete" value="">
                                <input type="hidden" name="old_department" value="<?php echo e($row['department']); ?>">
                                <div class="col-3">
                                    <input type="text" name="department" value="<?php echo e($row['department']); ?>" class="form-control form-control-sm">
                                </div>
                                <div class="col-2">
                                    <input type="number" step="0.01" name="base" value="<?php echo e($row['base']); ?>" class="form-control form-control-sm">
                                </div>
                                <div class="col-3">
                                    <select name="scheme_id" class="form-control form-control-sm">
                                        <?php foreach ($schemes as $s): ?>
                                            <option value="<?php echo (int)$s['id']; ?>" <?php echo (int)$s['id'] === (int)$row['scheme_id'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-4">
                                    <button type="submit" name="submit_btn" value="save" class="btn btn-sm btn-outline-primary"><i class="fas fa-save"></i> 保存</button>
                                    <button type="submit" name="submit_btn" value="delete" class="btn btn-sm btn-outline-danger" onclick="return confirm('删除该部门绩效配置？')"><i class="fas fa-trash"></i> 删除</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$deptCfg): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">还没有部门配置。请为参与绩效的部门（如：网站客服、设计客服）设置基数与方案。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> 名单内员工会按所在部门自动套用上方基数与方案计算绩效金额；未配置部门的名单员工不会计入。</small>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
