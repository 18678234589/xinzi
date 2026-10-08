
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="font-weight-bold mb-0 d-inline-block"><i class="fas fa-file-upload"></i> 平台订单导入</h4>
        <?php if ($locked_employee): ?>
            <span class="badge badge-success ml-2" style="font-size:.9em">
                <i class="fas fa-user-lock"></i> 已锁定合作人员：<?php echo e($locked_employee['name']); ?>（<?php echo e($locked_employee['department']); ?>）
            </span>
        <?php endif; ?>
    </div>
    <?php if ($locked_employee): ?>
        <div class="d-flex align-items-center">
            <a href="<?php echo BASE_URL; ?>/orders/pending.php" class="btn btn-outline-warning btn-sm mr-2" title="跨月全部待核验订单">
                <i class="fas fa-hourglass-half"></i> 待核验
            </a>
            <a href="<?php echo BASE_URL; ?>/employees/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> 返回合作人员管理
            </a>
        </div>
    <?php else: ?>
        <div class="d-flex align-items-center">
            <a href="<?php echo BASE_URL; ?>/orders/pending.php" class="btn btn-outline-warning btn-sm mr-2" title="跨月全部待核验订单">
                <i class="fas fa-hourglass-half"></i> 待核验
            </a>
            <a href="<?php echo BASE_URL; ?>/orders/recycle.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-recycle"></i> 回收站
            </a>
        </div>
    <?php endif; ?>
</div>
<div class="alert alert-info py-2">此处供财务核对平台历史 / 原始订单，不作为新版项目分成入口。网站售后部代录和参与人员分配请使用 <a href="<?php echo BASE_URL; ?>/project/import.php?scope=department&amp;business=<?php echo rawurlencode('网站续费'); ?>">项目订单 · 网站售后部门订单</a>。</div>

<?php if ($success || isset($_GET['upload_ok'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success ?: urldecode($_GET['msg'] ?? '导入完成')); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
    <script><?php /* split: orders/index/view/js_1.php */ include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_1.php'; ?></script>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<div class="row">
    <!-- 左侧：上传 & 手动添加 -->
    <div class="col-md-5">
        <!-- 批量上传 -->
        <div class="card mb-3">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-file-excel text-success"></i> 批量上传订单Excel</h5></div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="action" value="upload">
                    <?php if ($locked_employee): ?>
                        <!-- 锁定合作人员模式：固定为个人订单 -->
                        <input type="hidden" name="employee_id" value="<?php echo $locked_employee['id']; ?>">
                        <input type="hidden" name="order_scope" value="personal">
                        <div class="form-group">
                            <label>归属合作人员</label>
                            <input type="text" class="form-control" value="<?php echo e($locked_employee['name']); ?>（<?php echo e($locked_employee['department']); ?>）" disabled>
                            <small class="text-muted"><i class="fas fa-info-circle"></i> 已从合作人员管理锁定，本批订单将归属该合作人员</small>
                        </div>
                    <?php else: ?>
                        <!-- 归属类型选择 -->
                        <div class="form-group">
                            <label>归属类型 <span class="required">*</span></label>
                            <div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="order_scope" id="scopePersonal" value="personal" checked onchange="toggleScopeFields()">
                                    <label class="form-check-label" for="scopePersonal"><i class="fas fa-user text-primary"></i> 个人订单</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="order_scope" id="scopeDept" value="department" onchange="toggleScopeFields()">
                                    <label class="form-check-label" for="scopeDept"><i class="fas fa-users text-success"></i> 部门订单</label>
                                </div>
                            </div>
                        </div>
                        <!-- 个人订单：选部门+合作人员 -->
                        <div id="personalFields">
                            <div class="form-group">
                                <label>选择部门</label>
                                <select name="department" id="uploadDept" class="form-control" onchange="loadEmployees('upload')">
                                    <option value="">-- 选择部门 --</option>
                                    <?php foreach ($departments as $d): if ($d === '网站售后部') continue; ?>
                                        <option value="<?php echo e($d); ?>"><?php echo e($d); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>选择合作人员 <span class="required">*</span></label>
                                <select name="employee_id" id="uploadEmp" class="form-control">
                                    <option value="">-- 请先选择部门 --</option>
                                </select>
                            </div>
                        </div>
                        <!-- 部门订单：只选部门 -->
                        <div id="deptFields" style="display:none">
                            <div class="form-group">
                                <label>选择部门 <span class="required">*</span></label>
                                <select name="dept_name" id="uploadDeptName" class="form-control" onchange="loadDeptEmployees(this.value)">
                                    <option value="">-- 选择部门 --</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?php echo e($d); ?>"><?php echo e($d); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">部门订单不归属于具体合作人员，仅记录部门整体业绩</small>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-users text-primary"></i> 项目分成归属合作人员 <small class="text-muted">（可添加多个，每人选各自的项目分成模块）</small></label>
                                <div id="deptEmpRows">
                                    <!-- 动态行由JS生成 -->
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="addDeptEmpRow()"><i class="fas fa-plus"></i> 添加合作人员</button>
                                <input type="hidden" name="dept_emp_modules" id="deptEmpModules">
                            </div>
                            <div class="form-group" id="ownershipFieldsGroup" style="display:none">
                                <label><i class="fas fa-link text-info"></i> 归属匹配字段 <small class="text-muted">（指定Excel中用于匹配合作人员姓名的列名，逗号分隔）</small></label>
                                <input type="text" class="form-control form-control-sm" id="ownershipFields" placeholder="如：客服,制作技术">
                                <small class="text-muted">系统只在这些列中查找合作人员姓名，匹配成功则将订单归属到该合作人员</small>
                                <input type="hidden" name="ownership_fields" id="ownershipFieldsHidden">
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- 订单归属月份选择（必填） -->
                    <div class="form-group">
                        <label><i class="fas fa-calendar text-warning"></i> 订单归属月份 <span class="required">*</span></label>
                        <input type="month" name="upload_month" class="form-control" value="<?php echo date('Y-m', strtotime('-1 month')); ?>" min="2020-01" max="2030-12" required>
                        <small class="text-muted"><i class="fas fa-info-circle"></i> 该批订单将统一归属到所选月份，用于项目结算（不受Excel中日期列影响）</small>
                    </div>
                    
                    <div class="form-group">
                        <label>选择文件 <span class="required">*</span></label>
                        <div class="upload-area" id="uploadArea">
                            <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-2"></i>
                            <p class="mb-1" id="uploadTip">点击或拖拽文件到此处</p>
                            <p class="text-muted small mb-0">支持 .xls / .xlsx / .csv 格式</p>
                            <input type="file" name="excel_file" id="excelFile" class="d-none" accept=".xls,.xlsx,.csv">
                        </div>
                        <a href="<?php echo BASE_URL; ?>/orders/template_safehou.csv" class="btn btn-sm btn-link text-warning" download><i class="fas fa-download"></i> 下载模板</a>
                    </div>
                    <div class="form-group" id="uploadProjectGroup">
                        <label><i class="fas fa-percentage text-warning"></i> 对应项目分成模块 <small class="text-muted">（勾选要关联的模块，订单会按勾选模块分别结算）</small></label>
                        <div id="uploadProject" class="project-checkbox-list">
                            <?php
                            if ($locked_employee):
                                $modCfg = SalaryCalculator::readModulesConfig($locked_employee['id']);
                                if ($modCfg && !empty($modCfg['modules'])):
                                    foreach ($modCfg['modules'] as $m):
                                        if (in_array($m['type'], ['standard','tiered','per_order','profit_commission','trademark_commission','trademark_cashback','referral_order','fixed_subsidy','miniprogram_commission','customer_reward']) && ($m['enabled'] ?? true)):
                                            $modName = $m['name'];
                                            $extra = '';
                                            if ($m['type'] === 'standard' && isset($m['config']['rate']) && $m['config']['rate'] !== '') {
                                                $extra = ' (' . rtrim(rtrim(number_format((float)$m['config']['rate']*100, 4, '.', ''), '0'), '.') . '%)';
                                            } elseif ($m['type'] === 'profit_commission' && isset($m['config']['commission_rate']) && $m['config']['commission_rate'] !== '') {
                                                $extra = ' (成本项目分成' . rtrim(rtrim(number_format((float)$m['config']['commission_rate']*100, 4, '.', ''), '0'), '.') . '%)';
                                            } elseif ($m['type'] === 'trademark_commission' && isset($m['config']['commission_rate']) && $m['config']['commission_rate'] !== '') {
                                                $extra = ' (商标部项目分成' . rtrim(rtrim(number_format((float)$m['config']['commission_rate']*100, 4, '.', ''), '0'), '.') . '%)';
                                            } elseif ($m['type'] === 'trademark_cashback' && isset($m['config']['per_amount'])) {
                                                $extra = ' (小额返现¥' . ($m['config']['per_amount'] ?? 0) . '/单)';
                                            } elseif ($m['type'] === 'tiered') {
                                                $extra = ' (阶梯)';
                                            } elseif ($m['type'] === 'per_order') {
                                                $extra = ' (¥' . ($m['config']['per_amount'] ?? 0) . '/笔)';
                                            } elseif ($m['type'] === 'referral_order') {
                                                $extra = ' (每单补助¥' . ($m['config']['subsidy'] ?? 0) . ')';
                                            } elseif ($m['type'] === 'fixed_subsidy') {
                                                $extra = ' (固定¥' . ($m['config']['amount'] ?? 0) . '/月)';
                                            } elseif ($m['type'] === 'miniprogram_commission' && isset($m['config']['commission_rate']) && $m['config']['commission_rate'] !== '') {
                                                $extra = ' (小程序' . rtrim(rtrim(number_format((float)$m['config']['commission_rate']*100, 4, '.', ''), '0'), '.') . '%)';
                                            } elseif ($m['type'] === 'customer_reward') {
                                                $extra = ' (新客奖¥' . ($m['config']['new_customer_reward'] ?? 0) . '/老客¥' . ($m['config']['old_customer_reward'] ?? 0) . ')';
                                            }
                                            echo '<div class="custom-control custom-checkbox mb-1">'
                                               . '<input type="checkbox" name="upload_project[]" value="' . e($modName) . '" class="custom-control-input" id="proj_' . htmlspecialchars($modName, ENT_QUOTES) . '">'
                                               . '<label class="custom-control-label" for="proj_' . htmlspecialchars($modName, ENT_QUOTES) . '">' . e($modName) . $extra . '</label>'
                                               . '</div>';
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            ?>
                        </div>
                        <small class="text-muted">不勾选则按默认全部订单总额计算</small>
                    </div>
                    <button type="submit" class="btn btn-success btn-block"><i class="fas fa-upload"></i> 开始上传</button>
                </form>
            </div>
        </div>

        <!-- 手动添加 -->
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-hand-pointer text-primary"></i> 手动添加单笔订单</h5></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="manual_add">
                    <?php if ($locked_employee): ?>
                        <input type="hidden" name="employee_id" value="<?php echo $locked_employee['id']; ?>">
                        <div class="form-group">
                            <label>归属合作人员</label>
                            <input type="text" class="form-control" value="<?php echo e($locked_employee['name']); ?>（<?php echo e($locked_employee['department']); ?>）" disabled>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label>选择部门</label>
                            <select name="department" id="manualDept" class="form-control" onchange="loadEmployees('manual')">
                                <option value="">-- 选择部门 --</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?php echo e($d); ?>"><?php echo e($d); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>选择合作人员 <span class="required">*</span></label>
                            <select name="employee_id" id="manualEmp" class="form-control" required>
                                <option value="">-- 请先选择部门 --</option>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>订单金额 <span class="required">*</span></label>
                            <input type="number" name="order_amount" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>订单日期 <span class="required">*</span></label>
                            <input type="date" name="order_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-percentage text-warning"></i> 对应项目分成模块 <small class="text-muted">（选择该合作人员已配置的项目分成模块，订单会按对应比例结算）</small></label>
                        <select name="project" id="manualProject" class="form-control">
                            <option value="">-- 不指定 --</option>
                            <?php
                            if ($locked_employee):
                                $modCfg2 = SalaryCalculator::readModulesConfig($locked_employee['id']);
                                if ($modCfg2 && !empty($modCfg2['modules'])):
                                    foreach ($modCfg2['modules'] as $m):
                                        if (in_array($m['type'], ['standard','tiered','per_order','profit_commission','trademark_commission','trademark_cashback','referral_order','fixed_subsidy','miniprogram_commission','customer_reward']) && ($m['enabled'] ?? true)):
                                            $modName = $m['name'];
                                            $extra = '';
                                            if ($m['type'] === 'standard' && isset($m['config']['rate']) && $m['config']['rate'] !== '') {
                                                $extra = ' (' . rtrim(rtrim(number_format((float)$m['config']['rate']*100, 4, '.', ''), '0'), '.') . '%)';
                                            } elseif ($m['type'] === 'profit_commission' && isset($m['config']['commission_rate']) && $m['config']['commission_rate'] !== '') {
                                                $extra = ' (成本项目分成' . rtrim(rtrim(number_format((float)$m['config']['commission_rate']*100, 4, '.', ''), '0'), '.') . '%)';
                                            } elseif ($m['type'] === 'trademark_commission' && isset($m['config']['commission_rate']) && $m['config']['commission_rate'] !== '') {
                                                $extra = ' (商标部项目分成' . rtrim(rtrim(number_format((float)$m['config']['commission_rate']*100, 4, '.', ''), '0'), '.') . '%)';
                                            } elseif ($m['type'] === 'trademark_cashback' && isset($m['config']['per_amount'])) {
                                                $extra = ' (小额返现¥' . ($m['config']['per_amount'] ?? 0) . '/单)';
                                            } elseif ($m['type'] === 'tiered') {
                                                $extra = ' (阶梯)';
                                            } elseif ($m['type'] === 'per_order') {
                                                $extra = ' (¥' . ($m['config']['per_amount'] ?? 0) . '/笔)';
                                            } elseif ($m['type'] === 'referral_order') {
                                                $extra = ' (每单补助¥' . ($m['config']['subsidy'] ?? 0) . ')';
                                            } elseif ($m['type'] === 'fixed_subsidy') {
                                                $extra = ' (固定¥' . ($m['config']['amount'] ?? 0) . '/月)';
                                            } elseif ($m['type'] === 'miniprogram_commission' && isset($m['config']['commission_rate']) && $m['config']['commission_rate'] !== '') {
                                                $extra = ' (小程序' . rtrim(rtrim(number_format((float)$m['config']['commission_rate']*100, 4, '.', ''), '0'), '.') . '%)';
                                            } elseif ($m['type'] === 'customer_reward') {
                                                $extra = ' (新客奖¥' . ($m['config']['new_customer_reward'] ?? 0) . '/老客¥' . ($m['config']['old_customer_reward'] ?? 0) . ')';
                                            }
                                            echo '<option value="' . e($modName) . '">' . e($modName) . $extra . '</option>';
                                        endif;
                                    endforeach;
                                endif;
                            endif;
                            ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-plus"></i> 添加订单</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 右侧：订单记录 -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <h5 class="mb-0"><i class="fas fa-list text-info"></i> 订单记录
