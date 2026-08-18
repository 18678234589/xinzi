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
        // 档位区间：从表单数组 name_from[]/to[]/rate[] 收集（t_net_sales_from[] 等），全空行由保存侧丢弃
        $tiersFromPost = function($k) {
            $from = isset($_POST[$k . '_from']) ? (array)$_POST[$k . '_from'] : [];
            $to   = isset($_POST[$k . '_to'])   ? (array)$_POST[$k . '_to']   : [];
            $rate = isset($_POST[$k . '_rate']) ? (array)$_POST[$k . '_rate'] : [];
            $n = max(count($from), count($to), count($rate));
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $out[] = [
                    'from' => trim((string)($from[$i] ?? '')),
                    'to'   => trim((string)($to[$i] ?? '')),
                    'rate' => trim((string)($rate[$i] ?? '')),
                ];
            }
            return $out;
        };
        $p = [
            'name'             => trim($_POST['name'] ?? ''),
            'w_net_sales'      => $w('en_net_sales', 'w_net_sales', 43),
            'w_inquiry_conv'   => $w('en_inquiry_conv', 'w_inquiry_conv', 30),
            'w_wangwang_reply' => $w('en_wangwang_reply', 'w_wangwang_reply', 17),
            'w_avg_response'   => $w('en_avg_response', 'w_avg_response', 15),
            'tiers_net_sales'      => $tiersFromPost('t_net_sales'),
            'tiers_inquiry_conv'   => $tiersFromPost('t_inquiry_conv'),
            'tiers_wangwang_reply' => $tiersFromPost('t_wangwang_reply'),
            'tiers_avg_response'   => $tiersFromPost('t_avg_response'),
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
var TIER_INDS = ['net_sales','inquiry_conv','wangwang_reply','avg_response'];
var TIER_ENID = {net_sales:'s_en_net_sales', inquiry_conv:'s_en_inquiry_conv', wangwang_reply:'s_en_wangwang_reply', avg_response:'s_en_avg_response'};
// 启用勾选框控制该指标的权重/档位输入（未启用的指标权重按0提交，PHP 侧兜底）
function refreshEnableState(){
    TIER_INDS.forEach(function(ind){
        var cb = document.getElementById(TIER_ENID[ind]); if (!cb) return;
        var on = cb.checked;
        var w = document.querySelector('input[name="w_'+ind+'"]');
        if (w) w.disabled = !on;
        var tierInputs = document.querySelectorAll('input[name^="t_'+ind+'_"]');
        for (var i=0;i<tierInputs.length;i++) tierInputs[i].disabled = !on;
    });
}
document.addEventListener('DOMContentLoaded', function(){
    TIER_INDS.forEach(function(ind){
        var cb = document.getElementById(TIER_ENID[ind]);
        if (cb) cb.addEventListener('change', refreshEnableState);
    });
    // 初始给每个指标至少一档
    TIER_INDS.forEach(function(ind){
        var body = document.getElementById('tiers_body_'+ind);
        if (body && body.querySelectorAll('tr').length === 0) addTier(ind,'0','','100');
    });
});
function bindTierDel(tr){
    var btn = tr.querySelector('.tier-del');
    if (btn) btn.onclick = function(){ tr.remove(); };
}
function addTier(ind, from, to, rate){
    var body = document.getElementById('tiers_body_'+ind);
    if (!body) return;
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td><input type="number" step="any" name="t_'+ind+'_from[]" class="form-control form-control-sm" value="'+esc2(from==null?'':from)+'"></td>'
        + '<td><input type="number" step="any" name="t_'+ind+'_to[]" class="form-control form-control-sm" value="'+esc2(to==null?'':to)+'"></td>'
        + '<td><input type="number" step="any" name="t_'+ind+'_rate[]" class="form-control form-control-sm" value="'+esc2(rate==null?'100':rate)+'"></td>'
        + '<td><button type="button" class="btn btn-sm btn-outline-danger tier-del" title="删除该档"><i class="fas fa-trash-alt"></i></button></td>';
    body.appendChild(tr);
    bindTierDel(tr);
}
function fillTiers(ind, tiers){
    var body = document.getElementById('tiers_body_'+ind);
    if (!body) return;
    body.innerHTML = '';
    tiers = tiers || [];
    if (tiers.length === 0) { addTier(ind,'0','','100'); return; }
    tiers.forEach(function(t){
        addTier(ind, t.from, (t.to===null||t.to===''?'':t.to), t.rate);
    });
}
function fillScheme(obj){
    document.getElementById('s_id').value = obj ? obj.id : 0;
    document.getElementById('s_name').value = obj ? obj.name : '';
    document.getElementById('s_default').checked = !!(obj && obj.is_default == 1);
    document.getElementById('s_en_net_sales').checked = !!(obj && parseFloat(obj.w_net_sales)>0);
    document.getElementById('s_w_net_sales').value = obj?obj.w_net_sales:43;
    document.getElementById('s_en_inquiry_conv').checked = !!(obj && parseFloat(obj.w_inquiry_conv)>0);
    document.getElementById('s_w_inquiry_conv').value = obj?obj.w_inquiry_conv:30;
    document.getElementById('s_en_wangwang_reply').checked = !!(obj && parseFloat(obj.w_wangwang_reply)>0);
    document.getElementById('s_w_wangwang_reply').value = obj?obj.w_wangwang_reply:17;
    document.getElementById('s_en_avg_response').checked = !!(obj && parseFloat(obj.w_avg_response)>0);
    document.getElementById('s_w_avg_response').value = obj?obj.w_avg_response:15;
    fillTiers('net_sales', obj?obj.tiers_net_sales:null);
    fillTiers('inquiry_conv', obj?obj.tiers_inquiry_conv:null);
    fillTiers('wangwang_reply', obj?obj.tiers_wangwang_reply:null);
    fillTiers('avg_response', obj?obj.tiers_avg_response:null);
    document.getElementById('s_floor').value = obj?obj.floor_pct:0;
    document.getElementById('s_cap').value   = obj?obj.cap_pct:0;
    refreshEnableState();
    document.getElementById('schemeEditor').style.display = '';
    document.getElementById('schemeEditor').scrollIntoView({behavior:'smooth',block:'start'});
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
            绩效金额 = 部门绩效基数 × 综合达成率。综合达成率由「净销售额 / 询单最终下单转化率 / 旺旺回复率 / 平均响应时长」四项中<strong>已启用</strong>的指标（权重&gt;0 且已配置档位区间）按权重加权得出；每个指标可自定义多个「区间档位」，实际值落入档位区间即取该档固定达成率，可设保底/封顶。
            默认权重参考：净销售额43%、询单转化率30%、旺旺回复率17%、平均响应时长15%。
        </p>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>名称</th><th>净销售额</th><th>询单转化率</th><th>旺旺回复率</th><th>平均响应</th><th>保底/封顶</th><th style="width:140px">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php $dispSchemeCell = function($s, $tKey, $wKey) {
                    $ts = cs_perf_tiers_parse($s[$tKey] ?? '');
                    $w = (float)$s[$wKey];
                    if ($w <= 0 || !$ts) return '<span class="text-muted">无</span>';
                    $labels = [];
                    foreach ($ts as $t) $labels[] = cs_perf_fmt_range($t) . '→' . (float)$t['rate'] . '%';
                    return 'w' . (float)$w . ' · ' . count($ts) . '档 <span class="badge badge-light" title="' . e(implode('；', $labels)) . '">' . e($labels[0]) . ' …</span>';
                }; ?>
                <?php foreach ($schemes as $s): ?>
                    <tr>
                        <td><?php echo e($s['name']); ?><?php if ($s['is_default']): ?> <span class="badge badge-info">默认</span><?php endif; ?></td>
                        <td><?php echo $dispSchemeCell($s, 'tiers_net_sales', 'w_net_sales'); ?></td>
                        <td><?php echo $dispSchemeCell($s, 'tiers_inquiry_conv', 'w_inquiry_conv'); ?></td>
                        <td><?php echo $dispSchemeCell($s, 'tiers_wangwang_reply', 'w_wangwang_reply'); ?></td>
                        <td><?php echo $dispSchemeCell($s, 'tiers_avg_response', 'w_avg_response'); ?></td>
                        <td><?php echo ((float)$s['floor_pct'] > 0 ? '保底' . $s['floor_pct'] . '%' : '-') . ' / ' . ((float)$s['cap_pct'] > 0 ? '封顶' . $s['cap_pct'] . '%' : '-'); ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick='fillScheme(<?php echo json_encode([
                                'id'=>$s['id'],'name'=>$s['name'],'is_default'=>$s['is_default'],
                                'w_net_sales'=>$s['w_net_sales'],
                                'w_inquiry_conv'=>$s['w_inquiry_conv'],
                                'w_wangwang_reply'=>$s['w_wangwang_reply'],
                                'w_avg_response'=>$s['w_avg_response'],
                                'tiers_net_sales'=>cs_perf_tiers_parse($s['tiers_net_sales'] ?? ''),
                                'tiers_inquiry_conv'=>cs_perf_tiers_parse($s['tiers_inquiry_conv'] ?? ''),
                                'tiers_wangwang_reply'=>cs_perf_tiers_parse($s['tiers_wangwang_reply'] ?? ''),
                                'tiers_avg_response'=>cs_perf_tiers_parse($s['tiers_avg_response'] ?? ''),
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
        <small class="text-muted d-block mb-2"><i class="fas fa-info-circle"></i> 每个指标可添加多个「区间档位」：实际值落入某档的「区间下限~上限」即取该档固定达成率（%）；留空上限 = 无上限（更高值命中该档）；低于首档下限取首档。未勾选启用或权重=0 的指标不参与加权；已启用但未配置档位的指标同样不计入。</small>
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

            <!-- 指标1：净销售额 -->
            <div class="border rounded p-2 mb-2">
                <div class="d-flex flex-wrap align-items-center">
                    <span class="mr-2"><input type="checkbox" name="en_net_sales" value="1" id="s_en_net_sales" class="form-check-input"></span>
                    <span class="font-weight-bold mr-3">净销售额</span>
                    <span class="small text-muted mr-1">权重(%)</span>
                    <input type="number" step="0.1" name="w_net_sales" id="s_w_net_sales" class="form-control form-control-sm" style="width:90px" placeholder="43">
                    <span class="small text-muted ml-3 align-middle">达成率算法：实际净销售额落档 → 取该档固定达成率</span>
                    <button type="button" class="btn btn-sm btn-outline-success ml-auto" onclick="addTier('net_sales','0','','100')"><i class="fas fa-plus"></i> 添加档位</button>
                </div>
                <div class="table-responsive mt-1">
                    <table class="table table-sm table-bordered mb-0" style="max-width:760px">
                        <thead class="thead-light">
                            <tr><th style="width:150px">区间下限</th><th style="width:180px">区间上限(留空=无上限)</th><th style="width:130px">达成率(%)</th><th style="width:90px">操作</th></tr>
                        </thead>
                        <tbody id="tiers_body_net_sales"></tbody>
                    </table>
                </div>
            </div>

            <!-- 指标2：询单最终下单转化率 -->
            <div class="border rounded p-2 mb-2">
                <div class="d-flex flex-wrap align-items-center">
                    <span class="mr-2"><input type="checkbox" name="en_inquiry_conv" value="1" id="s_en_inquiry_conv" class="form-check-input"></span>
                    <span class="font-weight-bold mr-3">询单最终下单转化率</span>
                    <span class="small text-muted mr-1">权重(%)</span>
                    <input type="number" step="0.1" name="w_inquiry_conv" id="s_w_inquiry_conv" class="form-control form-control-sm" style="width:90px" placeholder="30">
                    <span class="small text-muted ml-3 align-middle">达成率算法：实际转化率(%)落档 → 取该档固定达成率</span>
                    <button type="button" class="btn btn-sm btn-outline-success ml-auto" onclick="addTier('inquiry_conv','0','','100')"><i class="fas fa-plus"></i> 添加档位</button>
                </div>
                <div class="table-responsive mt-1">
                    <table class="table table-sm table-bordered mb-0" style="max-width:760px">
                        <thead class="thead-light">
                            <tr><th style="width:150px">区间下限(%)</th><th style="width:180px">区间上限(%)留空=无上限</th><th style="width:130px">达成率(%)</th><th style="width:90px">操作</th></tr>
                        </thead>
                        <tbody id="tiers_body_inquiry_conv"></tbody>
                    </table>
                </div>
            </div>

            <!-- 指标3：旺旺回复率 -->
            <div class="border rounded p-2 mb-2">
                <div class="d-flex flex-wrap align-items-center">
                    <span class="mr-2"><input type="checkbox" name="en_wangwang_reply" value="1" id="s_en_wangwang_reply" class="form-check-input"></span>
                    <span class="font-weight-bold mr-3">旺旺回复率</span>
                    <span class="small text-muted mr-1">权重(%)</span>
                    <input type="number" step="0.1" name="w_wangwang_reply" id="s_w_wangwang_reply" class="form-control form-control-sm" style="width:90px" placeholder="17">
                    <span class="small text-muted ml-3 align-middle">达成率算法：实际回复率(%)落档 → 取该档固定达成率</span>
                    <button type="button" class="btn btn-sm btn-outline-success ml-auto" onclick="addTier('wangwang_reply','0','','100')"><i class="fas fa-plus"></i> 添加档位</button>
                </div>
                <div class="table-responsive mt-1">
                    <table class="table table-sm table-bordered mb-0" style="max-width:760px">
                        <thead class="thead-light">
                            <tr><th style="width:150px">区间下限(%)</th><th style="width:180px">区间上限(%)留空=无上限</th><th style="width:130px">达成率(%)</th><th style="width:90px">操作</th></tr>
                        </thead>
                        <tbody id="tiers_body_wangwang_reply"></tbody>
                    </table>
                </div>
            </div>

            <!-- 指标4：平均响应时长（越低越好：把最好档位的下限设小） -->
            <div class="border rounded p-2 mb-2">
                <div class="d-flex flex-wrap align-items-center">
                    <span class="mr-2"><input type="checkbox" name="en_avg_response" value="1" id="s_en_avg_response" class="form-check-input"></span>
                    <span class="font-weight-bold mr-3">平均响应时长</span>
                    <span class="small text-muted mr-1">权重(%)</span>
                    <input type="number" step="0.1" name="w_avg_response" id="s_w_avg_response" class="form-control form-control-sm" style="width:90px" placeholder="15">
                    <span class="small text-muted ml-3 align-middle">达成率算法：实际秒数落档 → 取该档固定达成率（越低越快越好，把最好档位的下限设小，如 ≤30秒→120%）</span>
                    <button type="button" class="btn btn-sm btn-outline-success ml-auto" onclick="addTier('avg_response','0','','100')"><i class="fas fa-plus"></i> 添加档位</button>
                </div>
                <div class="table-responsive mt-1">
                    <table class="table table-sm table-bordered mb-0" style="max-width:760px">
                        <thead class="thead-light">
                            <tr><th style="width:150px">区间下限(秒)</th><th style="width:180px">区间上限(秒)留空=无上限</th><th style="width:130px">达成率(%)</th><th style="width:90px">操作</th></tr>
                        </thead>
                        <tbody id="tiers_body_avg_response"></tbody>
                    </table>
                </div>
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
            <small class="text-muted d-block mt-1"><i class="fas fa-info-circle"></i> 权重即占比（参考 43/30/17/15）；未勾选或权重=0 的指标不参与加权；已启用但没配任何档位的指标也不计入。</small>
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
