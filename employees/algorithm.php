<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/SalaryCalculator.php';
require_login();

$employee_id = (int)($_GET['employee_id'] ?? 0);
$employee = get_employee($employee_id);
if (!$employee) { header('Location: ' . BASE_URL . '/employees/index.php'); exit; }

$page_title = '薪资算法设置';
$success = '';
$error = '';

// 处理保存（AJAX或POST）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        // 读取旧配置，防止表单字段被浏览器清空时数据丢失
        $oldConfig = SalaryCalculator::readModulesConfig($employee_id);
        $oldModules = [];
        if ($oldConfig && !empty($oldConfig['modules'])) {
            foreach ($oldConfig['modules'] as $om) {
                $oldModules[$om['name']] = $om['config'] ?? [];
            }
        }
        // 收集模块列表
        $modules = [];
        if (!empty($_POST['mod_name'])) {
            foreach ($_POST['mod_name'] as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $type   = $_POST['mod_type'][$i] ?? 'standard';
                $enabled = isset($_POST['mod_enabled'][$i]);
                // 模块名称：优先使用表单中的 _name，否则使用默认的 mod_name
                $customName = trim(($_POST['mod_cfg']['_name'][$i] ?? '') ?: '');
                $modName = $customName ?: $name;
                // 根据类型收集配置字段
                $typeInfo = SalaryCalculator::getAvailableTypes()[$type] ?? [];
                $cfg     = [];
                foreach ($typeInfo['fields'] ?? [] as $f) {
                    $val = $_POST['mod_cfg'][$f['key']][$i] ?? ($f['default'] ?? 0);
                    // 阶梯类型特殊处理：tiers 数组
                    if ($type === 'tiered' || $type === 'mixed') {
                        $cfg[$f['key']] = $val;  // tiered 的 fields 为空， tiers 在下面单独处理
                    } else {
                        $cleanVal = is_numeric($val) ? (float)$val : trim($val);
                        // 防止数据丢失：表单值为空时保留旧配置的值
                        if ($cleanVal === '' && isset($oldModules[$modName][$f['key']]) && $oldModules[$modName][$f['key']] !== '') {
                            $cleanVal = $oldModules[$modName][$f['key']];
                        }
                        // 只有当值不为空字符串时才保存（避免保存空的 min_amount 和 max_amount）
                        if ($cleanVal !== '') {
                            $cfg[$f['key']] = $cleanVal;
                        }
                    }
                }
                // 阶梯类型的 tiers 数据
                if (($type === 'tiered' || $type === 'mixed') && !empty($_POST['t_threshold'][$i])) {
                    $cfg['tiers'] = [];
                    foreach ($_POST['t_threshold'][$i] as $ti => $th) {
                        $rt = (float)($_POST['t_rate'][$i][$ti] ?? 0);
                        $subsidy = (float)($_POST['t_subsidy'][$i][$ti] ?? 0);
                        $cfg['tiers'][] = ['threshold' => (float)$th, 'rate' => $rt, 'subsidy' => $subsidy];
                    }
                    usort($cfg['tiers'], fn($a,$b)=>$a['threshold']<=>$b['threshold']);
                }
                // 阶梯底薪类型的 tiers 数据
                if ($type === 'base_salary_tiered' && !empty($_POST['t_threshold'][$i])) {
                    $cfg['tiers'] = [];
                    foreach ($_POST['t_threshold'][$i] as $ti => $th) {
                        $baseAmount = (float)($_POST['t_base_amount'][$i][$ti] ?? 0);
                        $cfg['tiers'][] = ['threshold' => (float)$th, 'base_amount' => $baseAmount];
                    }
                    usort($cfg['tiers'], fn($a,$b)=>$a['threshold']<=>$b['threshold']);
                }

                $modules[] = [
                    'name'    => $modName,
                    'type'    => $type,
                    'enabled' => $enabled,
                    'config'  => $cfg,
                ];
            }
        }

        if (!empty($modules)) {
            if (SalaryCalculator::saveModulesConfig($employee_id, $modules)) {
                $count = count($modules);
                $success = "已保存 {$count} 个薪资模块";
            } else {
                $error = '保存失败';
            }
        } else {
            $error = '请至少添加一个模块';
        }
    } elseif ($action === 'reset') {
        if (SalaryCalculator::deleteCustomConfig($employee_id)) {
            $success = '已恢复默认算法（底薪 + 默认提成比例）';
        } else { $error = '恢复失败'; }
    }
}

// 读取当前配置
$savedConfig  = SalaryCalculator::readModulesConfig($employee_id);
$isCodeMode  = !SalaryCalculator::hasCustomConfig($employee_id) && SalaryCalculator::hasCustomAlgorithm($employee_id);
$allTypes    = SalaryCalculator::getAvailableTypes();
$currentMods = $savedConfig['modules'] ?? [];

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="font-weight-bold mb-0 d-inline-block"><i class="fas fa-puzzle-piece"></i> 薪资算法设置</h4>
        <span class="badge badge-info ml-2"><?php echo e($employee['name']); ?> · <?php echo e($employee['department']); ?></span>
    </div>
    <a href="<?php echo BASE_URL; ?>/employees/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> 返回</a>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<!-- ====== 底薪信息(固定) ====== -->
<div class="card mb-3 border-left-4 border-left-primary">
    <div class="card-body d-flex align-items-center">
        <div class="mr-auto">
            <h6 class="mb-1 font-weight-bold text-dark"><i class="fas fa-wallet mr-1"></i> 底薪（固定，不参与模块计算）</h6>
            <p class="text-muted mb-0 small">该员工的基本工资为：<strong class="text-primary">¥<?php echo money($employee['base_salary']); ?></strong>，
            提成比例：<strong><?php echo ((float)$employee['commission_rate']*100); ?>%</strong>（仅作参考，实际以模块配置为准）</p>
        </div>
        <span class="badge badge-primary h4 p-2">¥<?php echo money($employee['base_salary']); ?></span>
    </div>
</div>

<!-- ====== 模块管理区域 ====== -->
<form method="post" id="moduleForm">
<input type="hidden" name="action" value="save">
<div id="moduleList">

    <?php if (empty($currentMods)): ?>

    <!-- 无模块时的提示 -->
    <div class="card border-dashed bg-light">
        <div class="card-body text-center py-5">
            <i class="fas fa-plus-circle fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">尚未添加任何薪资模块</h5>
            <p class="text-muted small">点击下方按钮添加模块，实发工资 = 底薪 + 各模块合计</p>
            <button type="button" class="btn btn-primary btn-lg mt-2" onclick="showTypePicker()">
                <i class="fas fa-plus"></i> 添加第一个模块
            </button>
        </div>
    </div>

    <?php else: ?>
    <?php foreach ($currentMods as $idx => $mod):
        $bgMap = ['primary'=>'#e8f4fd','warning'=>'#fff7e6','info'=>'#e8f4fe','success'=>'#e8f8f8','teal'=>'#d1ecf1','danger'=>'#fce4ec','purple'=>'#f3e8ff','secondary'=>'#f1f3f5'];
        $modBg = $bgMap[$allTypes[$mod['type']]['color']] ?? '#f8f9fa';
    ?>
    <!-- 单个模块卡片 -->
    <div class="card module-card mb-3" data-index="<?php echo $idx; ?>" id="mod-<?php echo $idx; ?>">
        <div class="card-header py-2 px-3 d-flex justify-content-between align-items-center" style="background:<?php echo $modBg; ?>;cursor:pointer;" data-toggle="collapse" data-target="#params-<?php echo $idx; ?>">
            <div class="d-flex align-items-center" style="flex:1;min-width:0">
                <span style="cursor:move;padding:0 10px;color:#999;" onclick="event.stopPropagation();"><i class="fas fa-grip-vertical"></i></span>
                <label class="d-flex align-items-center mb-0 cursor-pointer mr-2" onclick="event.stopPropagation();toggleModule(<?php echo $idx; ?>)">
                    <input type="hidden" name="mod_enabled[<?php echo $idx; ?>]" value="1"
                        <?php echo $mod['enabled'] ? '' : 'disabled'; ?> data-enabled="<?php echo $mod['enabled']?1:0; ?>">
                    <span class="toggle-switch mr-2 <?php echo $mod['enabled'] ? 'active' : ''; ?>"></span>
                </label>
                <i class="fas fa-<?php echo $allTypes[$mod['type']]['icon']; ?> mr-2 text-muted"></i>
                <input type="text" name="mod_name[<?php echo $idx; ?>]" value="<?php echo e($mod['name']); ?>"
                    class="form-control form-control-sm font-weight-bold mr-2" style="max-width:180px" placeholder="模块名称" onclick="event.stopPropagation();">
                <input type="hidden" name="mod_type[<?php echo $idx; ?>]" value="<?php echo $mod['type']; ?>">
                <small class="badge badge-<?php echo $allTypes[$mod['type']]['color']; ?>"><?php echo $allTypes[$mod['type']]['label']; ?></small>
            </div>
            <div class="btn-group btn-group-sm" onclick="event.stopPropagation();">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="collapseMod(<?php echo $idx; ?>)" title="展开/收起参数">
                    <i class="fas fa-chevron-down mod-toggle-icon"></i>
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeModule(<?php echo $idx; ?>)" title="删除此模块"><i class="fas fa-trash"></i></button>
            </div>
        </div>

        <!-- 模块参数面板 -->
        <div class="mod-params collapse" id="params-<?php echo $idx; ?>">
            <div class="card-body pt-2 pb-3">

                <?php echo renderModuleForm($idx, $mod['type'], $mod['config']); ?>

                <!-- 实时预览 -->
                <div class="mt-2 p-2 bg-light rounded border preview-box" data-mod-idx="<?php echo $idx; ?>">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted mb-0"><i class="fas fa-calculator mr-1"></i>预算：</strong></small>
                        <strong class="text-primary preview-result">--</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- 有模块时也显示"添加更多"按钮 -->
    <div class="card border-dashed bg-light mt-3">
        <div class="card-body text-center py-3">
            <button type="button" class="btn btn-outline-primary" onclick="showTypePicker()">
                <i class="fas fa-plus"></i> 添加更多模块
            </button>
        </div>
    </div>

    <?php endif; ?>

</div><!-- /#moduleList -->

<!-- 操作栏 -->
<div class="d-flex justify-content-between align-items-center mt-3 flex-wrap">
    <div>
        <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> 保存全部配置</button>
        <a href="<?php echo BASE_URL; ?>/salaries/settle.php?employee_id=<?php echo $employee_id; ?>" class="btn btn-outline-info btn-lg ml-2"><i class="fas fa-calculator"></i> 去结算测试</a>
    </div>
    <button type="button" class="btn btn-outline-warning" onclick="if(confirm('⚠️ 确定要删除所有自定义模块吗？\\n\\n删除后将使用默认算法（底薪 × 提成比例）计算薪资，此操作不可恢复！')){document.getElementById('resetForm').submit();}"><i class="fas fa-undo"></i> 恢复默认</button>
</div>
</form>

<!-- 隐藏的重置表单 -->
<form method="post" id="resetForm" style="display:none;">
    <input type="hidden" name="action" value="reset">
</form>

<!-- 总计预览 -->
<div class="card mt-3" id="totalPreview">
    <div class="card-body">
        <table class="table table-sm table-bordered mb-0">
            <thead><tr><th width="40%">项目</th><th>金额</th><th>说明</th></tr></thead>
            <tbody id="totalBody">
                <tr><td><i class="fas fa-wallet text-primary"></i> 底薪</td><td class="font-weight-bold"><?php echo money($employee['base_salary']); ?></td><td class="text-muted">员工表底薪（可被"底薪（自定义）"模块覆盖）</td></tr>
            </tbody>
            <tfoot><tr class="bg-success text-white font-weight-bold">
                <td colspan="2" class="text-right">实发工资 = </td>
                <td id="totalNetPay"><?php echo money($employee['base_salary']); ?></td>
            </tr></tfoot>
        </table>
    </div>
</div>

<?php if ($isCodeMode): ?>
<hr>
<div class="card mt-3">
    <div class="card-header bg-warning text-white"><i class="fas fa-code"></i> 兼容模式提示</div>
    <div class="card-body">
        <p>该员工有旧版 PHP 算法文件，建议先删除后使用新版多模块配置。</p>
        <pre class="bg-dark text-light p-2 rounded" style="max-height:150px;font-size:11px;"><?php echo htmlspecialchars(substr(SalaryCalculator::readAlgorithm($employee_id),0,500)); ?></pre>
    </div>
</div>
<?php endif; ?>

<!-- 模块类型选择器（Modal） -->
<div class="modal fade" id="typePicker" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle"></i> 选择模块类型</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="row">
                <?php $ti = -1; foreach ($allTypes as $tk => $tv): $ti++; ?>
                    <div class="col-md-6 mb-3">
                        <div class="card type-picker-card cursor-pointer border-2" onclick="addModule('<?php echo $tk; ?>')"
                             style="border-color:#dee2e6;" data-type="<?php echo $tk; ?>" id="pick-<?php echo $tk; ?>">
                            <div class="card-body p-3 text-center">
                                <i class="fas fa-<?php echo $tv['icon']; ?> fa-2x text-<?php echo $tv['color']; ?> mb-2"></i>
                                <h6 class="mb-1"><?php echo $tv['label']; ?></h6>
                                <small class="text-muted"><?php echo $tv['desc']; ?></small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.module-card { transition: all .2s; }
.module-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,.08); }
.toggle-switch {
    display:inline-block;width:36px;height:20px;border-radius:10px;background:#ccc;position:relative;transition:.2s;vertical-align:middle;cursor:pointer;
}
.toggle-switch.active { background:#28a745; }
.toggle-switch::after{ content:'';position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;transition:.2s;}
.toggle-switch.active::after{ left:18px; }
.type-picker-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,.15); border-color:#28a745!important; }
.tier-row { background:#f8f9fa; padding:8px 12px; border-radius:4px; margin-bottom:6px; border:1px solid #e9ecef; }
.preview-box { min-height:32px; }
.border-left-primary { border-left: 4px solid #007bff !important; }
</style>

<?php
/**
 * 辅助函数：渲染单个模块的表单字段（必须在HTML输出前定义，供上方第164行调用）
 */
function renderModuleForm($idx, $type, $cfg)
{
    global $allTypes;
    $info = $allTypes[$type] ?? $allTypes['standard'];
    $html = '';

    foreach ($info['fields'] as $f) {
        $val = $cfg[$f['key']] ?? ($f['default'] ?? '');
        $step = $f['step'] ?? 'any';
        $ph   = $f['placeholder'] ?? '';
        $extraAttrs = "class=\"form-control form-control-sm\" name=\"mod_cfg[{$f['key']}][{$idx}]\" step=\"{$step}\"";

        // 模块名称字段特殊处理：放在最前面，突出显示
        if (($f['key'] === '_name')) {
            $displayName = is_numeric($val) ? rtrim(rtrim(number_format((float)$val,4,'.',''),'0'),'.') : e($val);
            $html .= "<div class=\"form-group bg-warning bg-light p-2 rounded border-left-4 border-left-warning\">";
            $html .= "<label><strong>{$f['label']}</strong> ";
            $html .= "<small class=\"text-muted\">上传订单时下拉框会显示此名称</small></label>";
            $html .= "<div class=\"input-group\"><div class=\"input-group-prepend\"><span class=\"input-group-text bg-warning text-dark\"><i class=\"fas fa-tag\"></i></span></div>";
            $html .= "<input type=\"text\" {$extraAttrs} value=\"" . $displayName . "\" placeholder=\"{$ph}\"></div>";
            $html .= "</div>";
        } elseif ($f['type'] === 'number') {
            $html .= "<div class=\"form-group\"><label>{$f['label']}</label>";
            $suffix = strpos(strtolower($f['key']), 'rate') !== false ? '<span class="input-group-append"><span class="input-group-text">%</span></span>' :
                     (strpos(strtolower($f['label']), '金额')!==false ? '<div class="input-group-prepend"><span class="input-group-text">¥</span></div>' :'');
            $valDisplay = is_numeric($val) ? rtrim(rtrim(number_format((float)$val,4,'.',''),'0'),'.') : $val;
            $html .= "<div class=\"input-group\">{$suffix}<input type=\"{$f['type']}\" {$extraAttrs} value=\"" . e($valDisplay) . "\" placeholder=\"{$ph}\"></div>";
            $desc = $f['desc'] ?? '';
            $html .= "<small class=\"text-muted\">{$desc}</small></div>";
        } elseif ($f['type'] === 'select') {
            $html .= "<div class=\"form-group\"><label>{$f['label']}</label>";
            $html .= "<select {$extraAttrs}>";
            $options = $f['options'] ?? [];
            foreach ($options as $ov => $ol) {
                $sel = ((string)$ov === (string)$val) ? ' selected' : '';
                $html .= "<option value=\"" . e($ov) . "\"{$sel}>" . e($ol) . "</option>";
            }
            $html .= "</select>";
            $desc = $f['desc'] ?? '';
            $html .= "<small class=\"text-muted\">{$desc}</small></div>";
        } else {
            // 其他类型（text等）
            $html .= "<div class=\"form-group\"><label>{$f['label']}</label>";
            $html .= "<input type=\"{$f['type']}\" {$extraAttrs} value=\"" . e($val) . "\" placeholder=\"{$ph}\"></div>";
        }
    }

    // 阶梯类型特殊处理：显示已有的 tiers
    if ($type === 'tiered' || $type === 'mixed' || $type === 'base_salary_tiered') {
        $tiers = $cfg['tiers'] ?? [];
        if (empty($tiers)) {
            $tiers = [['threshold'=>10000,'rate'=>0.05,'subsidy'=>0],['threshold'=>20000,'rate'=>0.08,'subsidy'=>0],['threshold'=>30000,'rate'=>0.12,'subsidy'=>0]];
        }
        
        // 根据类型显示不同的字段
        if ($type === 'base_salary_tiered') {
            $html .= '<div class="mt-2" id="tierArea-'.$idx.'"><strong>阶梯规则：</strong>';
            $html .= '<button type="button" class="btn btn-outline-info btn-xs" onclick="addBaseTierRowForForm('.$idx.')">+ 新增档次</button>';
            $html .= '<div id="tierContainer-'.$idx.'" class="mt-2">';
            foreach ($tiers as $ti => $t) {
                $html .= '<div class="tier-row row">'.
                    '<div class="col-5"><label>订单总额≥</label>'.
                    '<input type="number" class="form-control form-control-sm tier-threshold" name="t_threshold['.$idx.']['.$ti.']" value="'.$t['threshold'].'" step="any"></div>'.
                    '<div class="col-5"><label>底薪金额</label><div class="input-group input-group-sm">'.
                    '<div class="input-group-prepend"><span class="input-group-text">¥</span></div>'.
                    '<input type="number" class="form-control tier-base-amount" name="t_base_amount['.$idx.']['.$ti.']" value="'.($t['base_amount']??0).'" step="any" min="0"></div></div>'.
                    '<div class="col-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm tier-rm-form">×</button></div></div>';
            }
            $html .= '</div></div>';
        } else {
            $html .= '<div class="mt-2" id="tierArea-'.$idx.'"><strong>阶梯规则：</strong>';
            $html .= '<button type="button" class="btn btn-outline-info btn-xs" onclick="addTierRowForForm('.$idx.')">+ 新增档次</button>';
            $html .= '<div id="tierContainer-'.$idx.'" class="mt-2">';
            foreach ($tiers as $ti => $t) {
                $html .= '<div class="tier-row row">'.
                    '<div class="col-3"><label>订单≥</label>'.
                    '<input type="number" class="form-control form-control-sm tier-threshold" name="t_threshold['.$idx.']['.$ti.']" value="'.$t['threshold'].'" step="any"></div>'.
                    '<div class="col-3"><label>比例</label><div class="input-group input-group-sm">'.
                    '<input type="number" class="form-control tier-rate" name="t_rate['.$idx.']['.$ti.']" value="'.$t['rate'].'" step="0.0001" min="0" max="1">'.
                    '<span class="input-group-append"><span class="input-group-text">%</span></span></div></div>'.
                    '<div class="col-3"><label>单量补贴</label><div class="input-group input-group-sm">'.
                    '<div class="input-group-prepend"><span class="input-group-text">¥</span></div>'.
                    '<input type="number" class="form-control tier-subsidy" name="t_subsidy['.$idx.']['.$ti.']" value="'.($t['subsidy']??0).'" step="0.1" min="0"></div></div>'.
                    '<div class="col-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm tier-rm-form">×</button></div></div>';
            }
            $html .= '</div></div>';
        }
    }

    return $html;
}
?>

</div><!-- /.main-content -->

<!-- 必须在JS之前加载jQuery和Bootstrap -->
<script src="<?php echo BASE_URL; ?>/assets/lib/jquery/jquery.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/lib/bootstrap/js/bootstrap.bundle.min.js"></script>

<script>
var baseSalary = <?php echo (float)$employee['base_salary']; ?>;
var empRate = <?php echo (float)$employee['commission_rate']; ?>;
var allTypes = <?php echo json_encode($allTypes, JSON_UNESCAPED_UNICODE); ?>;
var moduleCount = <?php echo count($currentMods); ?>;
var orderTotalDemo = 25000; // 预算用模拟订单总额

function renderAllPreviews() {
    var total = baseSalary;
    var baseLabel = '底薪（固定）';
    var baseNote = '从员工基本工资读取';
    var customBaseUsed = false;

    // 先扫一遍：是否有启用的 base_salary（自定义底薪）模块，有则覆盖底薪基数
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
                baseLabel = '底薪（自定义）';
                baseNote = '覆盖员工表底薪 ¥' + (<?php echo (float)$employee['base_salary']; ?>).toFixed(2);
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
        // base_salary 已作为底薪基数，不重复加入模块合计
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
            result.detail = '自定义底薪 ¥' + (result.amount).toFixed(2);
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
            var demoProfit = orderTotalDemo;
            var demoPrice  = orderTotalDemo * 1.2;
            result.amount = (demoProfit - demoPrice * sf) * cr;
            result.detail = '(利润¥' + number_format(demoProfit,0) + ' - 售价¥' + number_format(demoPrice,0) + '×' + (sf*100).toFixed(2) + '%) ×' + (cr*100).toFixed(2) + '%';
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
        var extraAttrs = ' class="form-control form-control-sm" name="mod_cfg[' + f.key + '][' + idx + ']" step="' + (f.step || 'any') + '" ';

        var suffix = '';
        if (f.key.indexOf('rate') >= 0) {
            suffix = '<span class="input-group-append"><span class="input-group-text">%</span></span>';
        } else if (f.label.indexOf('金额') >= 0 || f.key.indexOf('amount') >= 0) {
            suffix = '<div class="input-group-prepend"><span class="input-group-text">¥</span></div>';
        }

        html += '<div class="form-group"><label>'+f.label+'</label>';
        html += '<div class="input-group">'+suffix;
        html += '<input type="number" '+extraAttrs+' value="'+val+'" placeholder="'+(f.placeholder||'')+'">';
        html += '</div></div>';
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
          '<div class="col-5"><label>底薪金额</label><div class="input-group input-group-sm"><div class="input-group-prepend"><span class="input-group-text">¥</span></div><input type="number" class="form-control tier-base-amount" step="any" value="2300" required></div></div>'+
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

function addBaseTierRow(idx) {
    var row = $('<div class="tier-row row"><div class="col-5"><label>订单总额≥</label><input type="number" class="form-control form-control-sm tier-threshold" step="any" required></div>'+
        '<div class="col-5"><label>底薪金额</label><div class="input-group input-group-sm"><div class="input-group-prepend"><span class="input-group-text">¥</span></div><input type="number" class="form-control tier-base-amount" step="any" required></div></div>'+
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
        '<div class="col-5"><label>底薪金额</label><div class="input-group input-group-sm"><div class="input-group-prepend"><span class="input-group-text">¥</span></div><input type="number" class="form-control tier-base-amount" name="t_base_amount['+idx+'][]" step="any" min="0"></div></div>'+
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
</script>

</body>
</html>
