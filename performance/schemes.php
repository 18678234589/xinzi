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
        $thr = $_POST['t_threshold'] ?? [];
        $fac = $_POST['t_factor'] ?? [];
        $tiers = [];
        $n = count($thr);
        for ($i = 0; $i < $n; $i++) {
            $t = (float)($thr[$i] ?? 0);
            $f = (float)($fac[$i] ?? 0);
            if ($f > 0) $tiers[] = ['threshold' => $t, 'factor' => $f];
        }
        $p = [
            'name' => trim($_POST['name'] ?? ''),
            'weight_reply'         => $_POST['weight_reply'] ?? 1,
            'target_reply_sec'     => $_POST['target_reply_sec'] ?? 0,
            'weight_incoming'      => $_POST['weight_incoming'] ?? 1,
            'target_incoming'      => $_POST['target_incoming'] ?? 0,
            'weight_conv'          => $_POST['weight_conv'] ?? 1,
            'target_conversion_pct'=> $_POST['target_conversion_pct'] ?? 0,
            'weight_amount'        => $_POST['weight_amount'] ?? 1,
            'amount_tiers'         => json_encode($tiers, JSON_UNESCAPED_UNICODE),
            'floor_pct'            => $_POST['floor_pct'] ?? 0,
            'cap_pct'              => $_POST['cap_pct'] ?? 0,
            'is_default'           => !empty($_POST['is_default']) ? 1 : 0,
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
function fillScheme(obj){
    document.getElementById('s_id').value = obj ? obj.id : 0;
    document.getElementById('s_name').value = obj ? obj.name : '';
    document.getElementById('s_default').checked = !!(obj && obj.is_default == 1);
    document.getElementById('s_weight_reply').value   = obj?obj.weight_reply:1;
    document.getElementById('s_target_reply').value   = obj?obj.target_reply_sec:0;
    document.getElementById('s_weight_incoming').value= obj?obj.weight_incoming:1;
    document.getElementById('s_target_incoming').value= obj?obj.target_incoming:0;
    document.getElementById('s_weight_conv').value    = obj?obj.weight_conv:1;
    document.getElementById('s_target_conv').value    = obj?obj.target_conversion_pct:0;
    document.getElementById('s_weight_amount').value  = obj?obj.weight_amount:1;
    document.getElementById('s_floor').value = obj?obj.floor_pct:0;
    document.getElementById('s_cap').value   = obj?obj.cap_pct:0;
    var box = document.getElementById('s_tiers');
    box.innerHTML = '';
    var tiers = (obj && obj.amount_tiers) ? obj.amount_tiers : [];
    if(!tiers.length) addTier();
    else tiers.forEach(function(t){ addTier(t); });
    document.getElementById('schemeEditor').style.display = '';
    document.getElementById('schemeEditor').scrollIntoView({behavior:'smooth',block:'start'});
}
function addTier(t){
    t = t || {};
    var box = document.getElementById('s_tiers');
    var d = document.createElement('div');
    d.className = 'form-row align-items-center mb-1';
    d.innerHTML =
        '<div class="col"><input type="number" step="any" name="t_threshold[]" class="form-control form-control-sm" value="'+esc2(t.threshold)+'" placeholder="累计接单金额 ≥ (元)"></div>' +
        '<div class="col-auto"><span class="text-muted">→ 达成系数</span></div>' +
        '<div class="col"><input type="number" step="any" name="t_factor[]" class="form-control form-control-sm" value="'+esc2(t.factor)+'" placeholder="系数(如 0.8 / 1.0 / 1.2)"></div>' +
        '<div class="col-auto"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.parentNode.parentNode.remove()"><i class="fas fa-times"></i></button></div>';
    box.appendChild(d);
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
            绩效金额 = 部门绩效基数 × 综合达成率。综合达成率由「回复速度 / 接待人数 / 转化率 / 接单金额」四项中<strong>已启用</strong>的指标（权重&gt;0 且目标/阶梯已配置）按权重加权得出；可设保底/封顶。
        </p>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>名称</th><th>回复速度</th><th>接待人数</th><th>转化率</th><th>接单金额阶梯</th><th>保底/封顶</th><th style="width:140px">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($schemes as $s): ?>
                    <?php
                        $tiers = is_string($s['amount_tiers'] ?? '') ? (json_decode($s['amount_tiers'], true) ?: []) : [];
                        $tierTxt = empty($tiers) ? '-' : implode(' / ', array_map(function($t){ return (int)$t['threshold'] . '→' . (float)$t['factor']; }, $tiers));
                    ?>
                    <tr>
                        <td><?php echo e($s['name']); ?><?php if ($s['is_default']): ?> <span class="badge badge-info">默认</span><?php endif; ?></td>
                        <td>
                            <?php echo (float)$s['weight_reply'] > 0 && (float)$s['target_reply_sec'] > 0 ? 'w' . $s['weight_reply'] . ' 目标' . $s['target_reply_sec'] . 's' : '<span class="text-muted">无</span>'; ?>
                        </td>
                        <td>
                            <?php echo (float)$s['weight_incoming'] > 0 && (int)$s['target_incoming'] > 0 ? 'w' . $s['weight_incoming'] . ' 目标' . (int)$s['target_incoming'] . '人' : '<span class="text-muted">无</span>'; ?>
                        </td>
                        <td>
                            <?php echo (float)$s['weight_conv'] > 0 && (float)$s['target_conversion_pct'] > 0 ? 'w' . $s['weight_conv'] . ' 目标' . $s['target_conversion_pct'] . '%' : '<span class="text-muted">无</span>'; ?>
                        </td>
                        <td>
                            <?php if ((float)$s['weight_amount'] > 0 && !empty($tiers)): ?>w<?php echo $s['weight_amount']; ?>：<?php echo e($tierTxt); ?><?php else: ?><span class="text-muted">无</span><?php endif; ?>
                        </td>
                        <td><?php echo ((float)$s['floor_pct'] > 0 ? '保底' . $s['floor_pct'] . '%' : '-') . ' / ' . ((float)$s['cap_pct'] > 0 ? '封顶' . $s['cap_pct'] . '%' : '-'); ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick='fillScheme(<?php echo json_encode([
                                'id'=>$s['id'],'name'=>$s['name'],'is_default'=>$s['is_default'],
                                'weight_reply'=>$s['weight_reply'],'target_reply_sec'=>$s['target_reply_sec'],
                                'weight_incoming'=>$s['weight_incoming'],'target_incoming'=>$s['target_incoming'],
                                'weight_conv'=>$s['weight_conv'],'target_conversion_pct'=>$s['target_conversion_pct'],
                                'weight_amount'=>$s['weight_amount'],'amount_tiers'=>$tiers,
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
            <div class="form-row">
                <div class="col-md-3">
                    <label class="small text-muted">回复速度 达成权重</label>
                    <input type="number" step="0.1" name="weight_reply" id="s_weight_reply" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="small text-muted">目标回复速度(秒)</label>
                    <input type="number" step="any" name="target_reply_sec" id="s_target_reply" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="small text-muted">接待人数 达成权重</label>
                    <input type="number" step="0.1" name="weight_incoming" id="s_weight_incoming" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="small text-muted">目标接待人数(人)</label>
                    <input type="number" step="1" name="target_incoming" id="s_target_incoming" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="small text-muted">转化率 达成权重</label>
                    <input type="number" step="0.1" name="weight_conv" id="s_weight_conv" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="small text-muted">目标转化率(%)</label>
                    <input type="number" step="any" name="target_conversion_pct" id="s_target_conv" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="small text-muted">接单金额 达成权重</label>
                    <input type="number" step="0.1" name="weight_amount" id="s_weight_amount" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="small text-muted">保底(基数的%)</label>
                    <input type="number" step="any" name="floor_pct" id="s_floor" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="small text-muted">封顶(基数的%)</label>
                    <input type="number" step="any" name="cap_pct" id="s_cap" class="form-control form-control-sm">
                </div>
            </div>
            <div class="mt-2">
                <label class="small text-muted d-block">接单金额阶梯（累计接单金额达到档位 → 达成的系数，金额越高系数越高；系数=0 的行会自动忽略）</label>
                <div id="s_tiers"></div>
                <button type="button" class="btn btn-sm btn-outline-info" onclick="addTier()"><i class="fas fa-plus"></i> 加一档</button>
            </div>
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
