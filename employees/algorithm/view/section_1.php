
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="font-weight-bold mb-0 d-inline-block"><i class="fas fa-puzzle-piece"></i> 项目报酬算法设置</h4>
        <span class="badge badge-info ml-2"><?php echo e($employee['name']); ?> · <?php echo e($employee['department']); ?></span>
    </div>
    <a href="<?php echo BASE_URL; ?>/employees/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> 返回</a>
</div>

<?php if ($deptFeeRate > 0): ?>
<div class="alert alert-info alert-dismissible fade show">
    <i class="fas fa-info-circle"></i>
    <strong>部门手续费提示</strong>：该合作人员所属部门「<?php echo e($employee['department']); ?>」在 <code>dept_fee.php</code> 中配置了 <strong><?php echo ($deptFeeRate * 100); ?>%</strong> 手续费率。
    <?php
        // 检查算法配置中是否有模块的 service_fee_rate 为 0
        $hasZeroFee = false;
        foreach ($currentMods as $mod) {
            if (in_array($mod['type'], ['standard', 'tiered', 'per_order']) &&
                (float)($mod['config']['service_fee_rate'] ?? 0) === 0) {
                $hasZeroFee = true; break;
            }
        }
    ?>
    <?php if ($hasZeroFee): ?>
    <br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> 下方有模块的手续费扣除比例为 0，上传该模块的订单时不会扣除手续费。如需扣除，请在模块参数中填写。（部门订单未匹配到模块时会自动使用部门费率 <?php echo ($deptFeeRate * 100); ?>%）</small>
    <?php endif; ?>
    <button type="button" class="close" data-dismiss="alert">&times;</button>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<!-- ====== 固定服务费信息(固定) ====== -->
<div class="card mb-3 border-left-4 border-left-primary">
    <div class="card-body d-flex align-items-center">
        <div class="mr-auto">
            <h6 class="mb-1 font-weight-bold text-dark"><i class="fas fa-wallet mr-1"></i> 固定服务费（固定，不参与模块计算）</h6>
            <p class="text-muted mb-0 small">该合作人员的固定服务费为：<strong class="text-primary">¥<?php echo money($employee['base_salary']); ?></strong>，
            项目分成比例：<strong><?php echo ((float)$employee['commission_rate']*100); ?>%</strong>（仅作参考，实际以模块配置为准）</p>
        </div>
        <span class="badge badge-primary h4 p-2">¥<?php echo money($employee['base_salary']); ?></span>
    </div>
</div>

<!-- ====== 模块管理区域 ====== -->
<form method="post" id="moduleForm">
<input type="hidden" name="action" value="save">

<!-- 部门订单项目分成开关 -->
<div class="card mb-3 border-left-4 border-left-info">
    <div class="card-body py-2 d-flex align-items-center">
        <div class="custom-control custom-checkbox">
            <input type="checkbox" name="dept_share" value="1" class="custom-control-input" id="deptShare"
                <?php echo (($savedConfig['dept_share'] ?? 1) == 1) ? 'checked' : ''; ?>>
            <label class="custom-control-label" for="deptShare">
                <i class="fas fa-sitemap text-info"></i> 参与部门订单项目分成
            </label>
        </div>
        <small class="text-muted ml-3">勾选后，上传部门订单时自动为该合作人员生成项目分成拆分行；取消勾选则不参与部门订单项目分成（如仅有单量补贴的合作人员）</small>
    </div>
</div>

<div id="moduleList">

    <?php if (empty($currentMods)): ?>

    <!-- 无模块时的提示 -->
    <div class="card border-dashed bg-light">
        <div class="card-body text-center py-5">
            <i class="fas fa-plus-circle fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">尚未添加任何项目报酬模块</h5>
            <p class="text-muted small">点击下方按钮添加模块，应结算金额 = 固定服务费 + 各模块合计</p>
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
    <button type="button" class="btn btn-outline-warning" onclick="if(confirm('⚠️ 确定要删除所有自定义模块吗？\\n\\n删除后将使用默认算法（固定服务费 + 订单总额 × 项目分成比例）计算项目报酬，此操作不可恢复！')){document.getElementById('resetForm').submit();}"><i class="fas fa-undo"></i> 恢复默认</button>
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
                <tr><td><i class="fas fa-wallet text-primary"></i> 固定服务费</td><td class="font-weight-bold"><?php echo money($employee['base_salary']); ?></td><td class="text-muted">合作人员表固定服务费（可被"固定服务费（自定义）"模块覆盖）</td></tr>
            </tbody>
            <tfoot><tr class="bg-success text-white font-weight-bold">
                <td colspan="2" class="text-right">应结算金额 = </td>
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
        <p>该合作人员有旧版 PHP 算法文件，建议先删除后使用新版多模块配置。</p>
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

<style><?php /* split: assets/css/employees_algorithm_1.css */ include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/css/employees_algorithm_1.css'; ?></style>

<?php
/**
 * 辅助函数：渲染单个模块的表单字段（必须在HTML输出前定义，供上方第164行调用）
 */
/* split: employees/algorithm/helpers/renderModuleForm.php */ require_once (dirname((dirname(__DIR__, 1)), 1)) . '/algorithm/helpers/renderModuleForm.php';
?>

</div><!-- /.main-content -->

<!-- 必须在JS之前加载jQuery和Bootstrap -->
<script src="<?php echo BASE_URL; ?>/assets/lib/jquery/jquery.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/lib/bootstrap/js/bootstrap.bundle.min.js"></script>

<script><?php /* split: employees/algorithm/view/js_2.php */ include (dirname((dirname(__DIR__, 1)), 1)) . '/algorithm/view/js_2.php'; ?></script>

</body>
</html>
