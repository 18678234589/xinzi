<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
require_once __DIR__ . '/../classes/SimpleXLSX.php';
$actor = ps_require_actor();
$allowedBusinesses = ps_actor_businesses($actor);
$requestedBusiness = (string)($_POST['business'] ?? $_GET['business'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestedBusiness !== '' && !in_array(ps_business_normalize($requestedBusiness), $allowedBusinesses, true)) { http_response_code(403); exit('当前账户未分配此业务'); }
$selectedBusiness = ps_business_choice($actor, $requestedBusiness);
if (!$selectedBusiness && $_SERVER['REQUEST_METHOD'] === 'POST') { http_response_code(403); exit('当前账户未分配业务类型'); }
$businessDefinition = $selectedBusiness ? ps_business_catalog()[$selectedBusiness] : null;
if (isset($_GET['download']) && $selectedBusiness && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="project-order-template.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, ps_business_import_headers($selectedBusiness));
    fclose($output);
    exit;
}
$error = '';
$imported = 0;
$skipped = 0;
$preview = $_SESSION['project_import_preview'] ?? [];
$previewOwner = $_SESSION['project_import_actor'] ?? '';
$previewBusiness = $_SESSION['project_import_business'] ?? '';
$actorKey = $actor['type'] . ':' . $actor['id'];
if ($previewOwner !== $actorKey || $previewBusiness !== $selectedBusiness) $preview = [];
$domainTemplates = ps_intake_templates('domain');
$serverTemplates = ps_intake_templates('server');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $businessDefinition = ps_require_business($actor, $selectedBusiness);
        $peopleLabels = ps_business_people_labels($selectedBusiness);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'preview') {
            if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK || $_FILES['file']['size'] > 5 * 1024 * 1024) throw new RuntimeException('请选择不超过 5MB 的 XLSX 或 CSV 文件');
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx','csv'], true)) throw new RuntimeException('文件仅支持 XLSX 或 CSV');
            if ($ext === 'xlsx') $raw = SimpleXLSX::parse($_FILES['file']['tmp_name']);
            else {
                $raw = []; $handle = fopen($_FILES['file']['tmp_name'], 'rb');
                while (($line = fgetcsv($handle)) !== false && count($raw) <= 501) $raw[] = array_map(function ($v) { return mb_convert_encoding($v, 'UTF-8', 'UTF-8,GBK,GB2312'); }, $line);
                fclose($handle);
            }
            if (count($raw) < 2 || count($raw) > 502) throw new RuntimeException('文件须包含表头与数据，且一次最多 500 行');
            $head = array_map(function ($v) { return trim((string)$v); }, array_shift($raw));
            $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0] ?? '');
            $lookup = function ($row, $name) use ($head) { $i = array_search($name, $head, true); return $i === false ? '' : trim((string)($row[$i] ?? '')); };
            foreach (ps_business_import_headers($selectedBusiness) as $required) if (!in_array($required, $head, true)) throw new RuntimeException('当前选中“' . $selectedBusiness . '”，缺少对应表头：' . $required);
            $employeesByName = [];
            foreach (db()->query('SELECT id,name,department FROM employees')->fetchAll() as $emp) $employeesByName[$emp['name']][] = $emp;
            $knownShops = db()->query('SELECT name FROM shops')->fetchAll(PDO::FETCH_COLUMN);
            $seen = [];
            $preview = [];
            $exists = db()->prepare('SELECT 1 FROM project_orders WHERE order_no=? LIMIT 1');
            foreach ($raw as $index => $row) {
                if (!array_filter($row, function ($v) { return trim((string)$v) !== ''; })) continue;
                $record = ['line' => $index + 2, 'status' => '可导入', 'error' => '', 'warning' => '', 'base_valid' => true, 'people' => ['technical' => [], 'customer_service' => []], 'domain_mode' => '', 'domain_template_id' => 0];
                try {
                    $record['order_no'] = $lookup($row, '订单编号');
                    $record['order_date'] = ps_import_date($lookup($row, '日期'));
                    $record['contract_amount'] = str_replace([',','¥','￥'], '', $lookup($row, '售价'));
                    $status = $lookup($row, '状态(填已完成/未完成)');
                    $record['delivery_status'] = $status === '已完成' ? 'finished' : 'unfinished';
                    $sheetBusiness = $lookup($row, '业务');
                    if ($sheetBusiness !== '' && ps_business_normalize($sheetBusiness) !== $selectedBusiness) throw new RuntimeException('表格业务与当前选中业务不一致');
                    $record['project_type'] = $selectedBusiness;
                    $record['shop'] = $lookup($row, '店铺');
                    if (!in_array($record['shop'], $knownShops, true)) throw new RuntimeException('店铺不在店铺管理列表中，请先核对');
                    $record['payment_nickname'] = $lookup($row, '付款昵称');
                    $record['contact_note'] = $lookup($row, '备注（写客户电话或者微信）');
                    $record['domain_used'] = $businessDefinition['resources'] ? $lookup($row, '域名使用（写是/否）') : '否';
                    $record['ssl_used'] = $businessDefinition['resources'] ? $lookup($row, 'SSL证书使用（写真实成本）') : '';
                    $record['resource_note'] = $businessDefinition['resources'] ? $lookup($row, '填一下域名或者空间') : '';
                    $rawDetails = [];
                    foreach ($businessDefinition['fields'] as $key => $label) $rawDetails[$key] = $lookup($row, $label);
                    $record['details'] = ps_business_details($selectedBusiness, $rawDetails);
                    if ($record['ssl_used'] !== '' && !in_array($record['ssl_used'], ['无','否'], true) && (!preg_match('/^\d+(?:\.\d{1,2})?$/', $record['ssl_used']) || (float)$record['ssl_used'] > 999999999999.99)) throw new RuntimeException('SSL 真实成本无效，请填写金额、0 或无');
                    if ($record['order_no'] === '' || strlen($record['order_no']) > 100 || !$record['order_date'] || !preg_match('/^\d+(?:\.\d{1,2})?$/', $record['contract_amount']) || (float)$record['contract_amount'] > 999999999999.99 || !in_array($status, ['已完成','未完成'], true)) throw new RuntimeException('订单号、日期、售价或状态无效');
                    $cs = ps_import_names($lookup($row, '客服'), $employeesByName);
                    $front = ps_import_names($lookup($row, $peopleLabels['frontend']), $employeesByName);
                    $back = ps_import_names($lookup($row, $peopleLabels['backend']), $employeesByName);
                    foreach ($cs as $id => $name) $record['people']['customer_service'][$id] = ['id' => $id, 'role' => '客服', 'name' => $name];
                    foreach ($front as $id => $name) $record['people']['technical'][$id] = ['id' => $id, 'role' => $peopleLabels['frontend'], 'name' => $name];
                    foreach ($back as $id => $name) {
                        if (isset($record['people']['technical'][$id])) $record['people']['technical'][$id]['role'] .= '/' . $peopleLabels['backend'];
                        else $record['people']['technical'][$id] = ['id' => $id, 'role' => $peopleLabels['backend'], 'name' => $name];
                    }
                    if (!$cs && !$front && !$back) throw new RuntimeException('至少需要匹配一名客服或技术参与人');
                    if ($actor['role'] !== 'finance') {
                        $group = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
                        if (!isset($record['people'][$group][(int)$actor['employee_id']])) throw new RuntimeException('此行未写本人为' . ($group === 'technical' ? '技术' : '客服') . '，不可导入他人订单');
                    }
                    if (isset($seen[$record['order_no']])) throw new RuntimeException('文件内订单号重复');
                    $seen[$record['order_no']] = true;
                    $exists->execute([$record['order_no']]);
                    if ($exists->fetchColumn()) throw new RuntimeException('系统已有该订单号');
                    $record['domain_mode'] = ps_import_domain_mode($record['domain_used']);
                    if ($businessDefinition['resources'] && $record['domain_mode'] === 'template') {
                        $suggestion = ps_intake_domain_suggestion($record['resource_note'], $domainTemplates);
                        if ($suggestion) $record['domain_template_id'] = (int)$suggestion['id'];
                        else { $record['status'] = '需选择域名模板'; $record['warning'] = '表格只写“是”，未能唯一确认域名规格与周期'; }
                    } elseif ($record['domain_mode'] === '') {
                        $record['status'] = '需确认域名';
                        $record['warning'] = '域名使用未明确写是/否，请在下方选择';
                    }
                    if (is_numeric($record['ssl_used']) && (float)$record['ssl_used'] > 0) $record['warning'] .= ($record['warning'] ? '；' : '') . 'SSL 实际成本需创建后补凭证';
                } catch (RuntimeException $e) { $record['base_valid'] = false; $record['status'] = '需处理'; $record['error'] = $e->getMessage(); }
                $preview[] = $record;
            }
            $_SESSION['project_import_preview'] = $preview;
            $_SESSION['project_import_actor'] = $actorKey;
            $_SESSION['project_import_business'] = $selectedBusiness;
        } elseif ($action === 'commit') {
            if (!$preview || $previewOwner !== $actorKey || $previewBusiness !== $selectedBusiness) throw new RuntimeException('预览已失效，请重新上传');
            $choices = $_POST['domain_choice'] ?? [];
            $serverChoices = $_POST['server_template_id'] ?? [];
            $ready = [];
            foreach ($preview as $row) {
                if (empty($row['base_valid'])) { $skipped++; continue; }
                $line = (int)$row['line'];
                if ($actor['role'] !== 'finance') {
                    $group = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
                    if (!isset($row['people'][$group][(int)$actor['employee_id']])) throw new RuntimeException('第 ' . $line . ' 行不属于当前登录人员，请重新上传核对');
                }
                $choice = (string)($choices[$line] ?? ($row['domain_mode'] === 'none' ? 'none' : ($row['domain_template_id'] ?: '')));
                if ($choice !== 'none' && !ctype_digit($choice)) { $skipped++; continue; }
                $domainTemplate = $choice === 'none' ? null : ps_intake_template((int)$choice, 'domain');
                if ($row['domain_mode'] === 'template' && $choice === 'none') throw new RuntimeException('第 ' . $line . ' 行写了使用域名，不能选无需域名；请修正表格或选择模板');
                if ($row['domain_mode'] === 'none' && $choice !== 'none') throw new RuntimeException('第 ' . $line . ' 行写了无需域名，不能选择域名模板；请修正表格');
                $serverId = $businessDefinition['resources'] ? (int)($serverChoices[$line] ?? 0) : 0;
                $serverTemplate = $serverId > 0 ? ps_intake_template($serverId, 'server') : null;
                $ready[] = [$row, $domainTemplate, $serverTemplate];
            }
            if (!$ready) throw new RuntimeException('没有已核对可导入的订单；请先补齐域名选项');
            $pdo = db();
            $nested = $pdo->inTransaction();
            if ($nested) $pdo->exec('SAVEPOINT project_order_import');
            else $pdo->beginTransaction();
            try {
                $insertOrder = $pdo->prepare('INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,receipt_amount,order_date,delivery_status,note,created_by_admin) VALUES (?,?,?,?,?,0,?,?,?,?)');
                foreach ($ready as [$row, $domainTemplate, $serverTemplate]) {
                    $noteParts = [];
                    if ($row['payment_nickname'] !== '') $noteParts[] = '付款昵称：' . $row['payment_nickname'];
                    if ($row['contact_note'] !== '') $noteParts[] = '客户联系方式：' . $row['contact_note'];
                    if ($businessDefinition['resources']) $noteParts[] = $domainTemplate ? '域名：' . $domainTemplate['name'] . ' ' . $domainTemplate['specification'] : '域名：无需域名';
                    if ($row['resource_note'] !== '') $noteParts[] = '域名/空间说明：' . $row['resource_note'];
                    if (is_numeric($row['ssl_used']) && (float)$row['ssl_used'] > 0) $noteParts[] = 'SSL 实际成本报备：¥' . $row['ssl_used'] . '（待技术补充成本凭证）';
                    $insertOrder->execute([$row['order_no'], $row['payment_nickname'], $row['project_type'], $row['shop'], $row['contract_amount'], $row['order_date'], $row['delivery_status'], implode('；', $noteParts), $actor['role'] === 'finance' ? $actor['id'] : null]);
                    $orderId = (int)$pdo->lastInsertId();
                    ps_source_record($orderId, 'manual', $row['payment_nickname'], '');
                    ps_save_business_details($orderId, $selectedBusiness, $row['details']);
                    ps_intake_participants($orderId, $row['people']);
                    if ($businessDefinition['resources']) ps_intake_save_resources($orderId, 'excel', (int)$row['line'], $domainTemplate, $serverTemplate, is_numeric($row['ssl_used']) ? $row['ssl_used'] : null);
                    if ($domainTemplate) ps_intake_add_template_cost($orderId, $domainTemplate, $actor, 'Excel 第' . $row['line'] . '行：域名');
                    if ($serverTemplate) ps_intake_add_template_cost($orderId, $serverTemplate, $actor, 'Excel 第' . $row['line'] . '行：服务器');
                    ps_audit('order', $orderId, 'import', $actor, ['line' => $row['line'], 'order_no' => $row['order_no'], 'domain_template_id' => $domainTemplate['id'] ?? null]);
                    $imported++;
                }
                if ($nested) $pdo->exec('RELEASE SAVEPOINT project_order_import');
                else $pdo->commit();
                unset($_SESSION['project_import_preview'], $_SESSION['project_import_actor'], $_SESSION['project_import_business']);
                $preview = [];
            } catch (Throwable $e) { if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_order_import'); else $pdo->rollBack(); throw $e; }
        } else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { $error = $e instanceof PDOException ? '导入发生订单号冲突或保存失败，请重新上传预览' : $e->getMessage(); }
}
$baseValidCount = count(array_filter($preview, function ($row) { return !empty($row['base_valid']); }));
$page_title = '导入项目订单';
include __DIR__ . '/../includes/header.php';
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 批量录入</div><h2>导入<?php echo e($selectedBusiness ?: '项目'); ?>订单</h2><p>先选业务模板，再拖入 Excel 逐行核对。标准成本按该业务的资源规则生成，售价不直接作为实收。</p></div><div class="project-hero-actions"><a class="btn btn-light" href="<?php echo BASE_URL; ?>/project/index.php?business=<?php echo rawurlencode($selectedBusiness ?: ''); ?>">返回订单录入</a></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($imported): ?><div class="alert alert-success">已导入 <?php echo $imported; ?> 个订单<?php echo $skipped ? '；另有 ' . $skipped . ' 行未通过核对，未写入' : ''; ?>。<?php echo $businessDefinition['resources'] ? '已选择的标准域名/服务器成本按模板价生成；' : ''; ?>实收仍须财务确认。</div><?php endif; ?>
<?php if (!$selectedBusiness): ?><div class="alert alert-warning">当前账户尚未分配业务，请联系财务配置。</div><?php else: ?>
<div class="card project-form-card mb-3"><div class="card-body"><div class="project-section-title"><span class="project-step">01</span><div><h5>上传订单表</h5><p>支持 .xlsx / .csv，最多 500 行、5 MB。技术和客服只能导入写有本人参与的订单。</p></div></div>
<form method="get" class="form-inline mb-3"><label class="mr-2" for="importBusiness">业务模板</label><select id="importBusiness" name="business" class="form-control mr-2" onchange="this.form.submit()"><?php foreach ($allowedBusinesses as $businessName): ?><option value="<?php echo e($businessName); ?>" <?php echo $selectedBusiness === $businessName ? 'selected' : ''; ?>><?php echo e($businessName); ?></option><?php endforeach; ?></select><a class="btn btn-outline-success" href="?business=<?php echo rawurlencode($selectedBusiness); ?>&download=1">下载此业务 CSV 表头</a></form>
<form method="post" enctype="multipart/form-data" id="projectUploadForm"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="preview"><input type="hidden" name="business" value="<?php echo e($selectedBusiness); ?>"><label for="projectImportFile" id="projectDropZone" class="project-drop-zone"><i class="fas fa-cloud-upload-alt"></i><strong>拖拽 Excel 到这里，或点击选择文件</strong><span id="projectFileName">尚未选择文件</span><input type="file" id="projectImportFile" name="file" accept=".xlsx,.csv" required></label><button class="btn btn-success btn-lg mt-3" type="submit">上传并核对每一行</button></form></div></div>
<?php endif; ?>
<?php if ($preview): ?>
<form method="post" class="card project-form-card mb-3"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="commit"><input type="hidden" name="business" value="<?php echo e($selectedBusiness); ?>"><div class="card-body pb-2"><div class="project-section-title"><span class="project-step">02</span><div><h5>核对预览</h5><p><?php echo $baseValidCount; ?> 行基础资料通过<?php echo $businessDefinition['resources'] ? '；缺失域名规格的行请选标准模板，服务器成本可选填' : '；请确认参与人和业务专属信息'; ?>。</p></div></div></div><div class="table-responsive"><table class="table project-preview-table mb-0"><thead><tr><th>行 / 订单</th><th>日期 / 售价</th><th>参与人</th><?php if ($businessDefinition['fields']): ?><th>业务信息</th><?php endif; ?><?php if ($businessDefinition['resources']): ?><th>域名选择与标准成本</th><th>服务器成本</th><?php endif; ?><th>核对结果</th></tr></thead><tbody>
<?php foreach ($preview as $row): ?><tr class="<?php echo empty($row['base_valid']) ? 'table-danger' : ($row['status'] === '可导入' ? '' : 'table-warning'); ?>">
<td><small>第 <?php echo (int)$row['line']; ?> 行</small><br><strong><?php echo e($row['order_no'] ?? '—'); ?></strong><br><small><?php echo e($row['project_type'] ?? ''); ?></small></td>
<td><?php echo e($row['order_date'] ?? '—'); ?><br><strong>¥<?php echo e($row['contract_amount'] ?? '—'); ?></strong></td>
<td><small>客服：<?php echo e(implode('、', array_column($row['people']['customer_service'], 'name')) ?: '—'); ?><br>技术：<?php echo e(implode('、', array_column($row['people']['technical'], 'name')) ?: '—'); ?></small></td>
<?php if ($businessDefinition['fields']): ?><td><small><?php foreach ($businessDefinition['fields'] as $key => $label): ?><?php echo e($label . '：' . (($row['details'][$key] ?? '') ?: '—')); ?><br><?php endforeach; ?></small></td><?php endif; ?>
<?php if ($businessDefinition['resources']): ?>
<td><?php if (!empty($row['base_valid'])): ?><select class="form-control form-control-sm" name="domain_choice[<?php echo (int)$row['line']; ?>]" aria-label="第<?php echo (int)$row['line']; ?>行域名"><option value="">请选择域名方式</option><option value="none" <?php echo $row['domain_mode'] === 'none' ? 'selected' : ($row['domain_mode'] === 'template' ? 'disabled' : ''); ?>>无需域名</option><?php foreach ($domainTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" <?php echo (int)$row['domain_template_id'] === (int)$t['id'] ? 'selected' : ($row['domain_mode'] === 'none' ? 'disabled' : ''); ?>><?php echo e(trim($t['name'] . ' ' . $t['specification']) . ' · ¥' . money($t['price'])); ?></option><?php endforeach; ?></select><small class="text-muted">原表：<?php echo e(($row['domain_used'] ?: '空白') . ' · ' . ($row['resource_note'] ?: '未写域名/空间')); ?></small><?php else: ?>—<?php endif; ?></td>
<td><?php if (!empty($row['base_valid'])): ?><select class="form-control form-control-sm" name="server_template_id[<?php echo (int)$row['line']; ?>]"><option value="0">不自动计服务器</option><?php foreach ($serverTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>"><?php echo e(trim($t['name'] . ' ' . $t['specification']) . ' · ¥' . money($t['price'])); ?></option><?php endforeach; ?></select><?php else: ?>—<?php endif; ?></td>
<?php endif; ?>
<td><span class="badge badge-<?php echo empty($row['base_valid']) ? 'danger' : ($row['status'] === '可导入' ? 'success' : 'warning'); ?>"><?php echo e($row['status']); ?></span><?php if ($row['error']): ?><div class="small text-danger mt-1"><?php echo e($row['error']); ?></div><?php endif; ?><?php if ($row['warning']): ?><div class="small text-warning mt-1"><?php echo e($row['warning']); ?></div><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><div class="card-body border-top d-flex flex-wrap justify-content-between align-items-center"><small class="text-muted">只导入已核对的行；需要修正原表的行重新上传。售价不会直接变成实收<?php echo $businessDefinition['resources'] ? '，SSL 报备价不会直接入成本' : ''; ?>。</small><button class="btn btn-success btn-lg mt-2" type="submit">确认导入已核对订单</button></div></form>
<?php endif; ?>
</div>
<script>
(function () {
  var zone = document.getElementById('projectDropZone');
  var input = document.getElementById('projectImportFile');
  var label = document.getElementById('projectFileName');
  if (!zone || !input) return;
  input.addEventListener('change', function () { label.textContent = input.files.length ? input.files[0].name : '尚未选择文件'; });
  ['dragenter','dragover'].forEach(function (eventName) { zone.addEventListener(eventName, function (event) { event.preventDefault(); zone.classList.add('is-dragging'); }); });
  ['dragleave','drop'].forEach(function (eventName) { zone.addEventListener(eventName, function (event) { event.preventDefault(); zone.classList.remove('is-dragging'); }); });
  zone.addEventListener('drop', function (event) { if (!event.dataTransfer.files.length) return; input.files = event.dataTransfer.files; label.textContent = input.files[0].name; });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
