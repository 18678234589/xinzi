<?php
require_once (dirname(__DIR__, 1)) . '/../includes/ProjectIntake.php';
poi_ensure();
require_once (dirname(__DIR__, 1)) . '/../includes/ProjectOrderSplit.php';
require_once (dirname(__DIR__, 1)) . '/../includes/ProjectSiteProjects.php';
require_once (dirname(__DIR__, 1)) . '/../includes/ProjectTrademarkCost.php';
require_once (dirname(__DIR__, 1)) . '/../includes/ProjectBusiness.php';
require_once (dirname(__DIR__, 1)) . '/../includes/ProjectOrderSource.php';
require_once (dirname(__DIR__, 1)) . '/../includes/ProjectAiFallback.php';
require_once (dirname(__DIR__, 1)) . '/../includes/ProjectDepartmentImport.php';
require_once (dirname(__DIR__, 1)) . '/../includes/ProjectImportResult.php';
require_once (dirname(__DIR__, 1)) . '/../includes/ProjectImportClassification.php';
require_once (dirname(__DIR__, 1)) . '/../classes/SimpleXLSX.php';
pos_ensure();
$actor = ps_require_actor();
$operator = $actor;
$resumeFileId = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'preview') ? 0 : (int)($_GET['resume_file'] ?? ($_POST['resume_file'] ?? 0));
if (!$resumeFileId && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') !== 'preview' && ($_SESSION['project_import_delegate_operator'] ?? '') === $operator['type'] . ':' . $operator['id']) $resumeFileId = (int)($_SESSION['project_import_delegate_file'] ?? 0);
$resumeFile = null;
if ($resumeFileId > 0) {
    try {
        $resumeFile = ps_import_file_get($resumeFileId, $operator);
        $actor = ps_import_upload_actor($resumeFile, $operator);
        $_SESSION['project_import_delegate_file'] = $resumeFileId;
        $_SESSION['project_import_delegate_operator'] = $operator['type'] . ':' . $operator['id'];
        if (empty($_POST['business'])) $_GET['business'] = $resumeFile['business_name'];
    } catch (RuntimeException $e) { http_response_code(403); exit(e($e->getMessage())); }
} else unset($_SESSION['project_import_delegate_file'], $_SESSION['project_import_delegate_operator']);
$allowedBusinesses = ps_actor_businesses($actor);
$scope = (string)($_POST['scope'] ?? $_GET['scope'] ?? ($resumeFile && ps_department_import_allowed($actor, $resumeFile['business_name']) && $actor['role'] !== 'finance' ? 'department' : (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? ($_SESSION['project_import_scope'] ?? 'personal') : 'personal')));
$scope = $scope === 'department' ? 'department' : 'personal';
$departmentMode = $scope === 'department';
$departmentBusinesses = array_values(array_filter($allowedBusinesses, function ($name) use ($actor) { return ps_department_import_allowed($actor, $name); }));
$requestedBusiness = (string)($_POST['business'] ?? $_GET['business'] ?? '');
if ($departmentMode && $requestedBusiness === '') {
    foreach (['网站续费', '网站修改'] as $candidate) if (in_array($candidate, $allowedBusinesses, true)) { $requestedBusiness = $candidate; break; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestedBusiness !== '' && !in_array(ps_business_normalize($requestedBusiness), $allowedBusinesses, true)) { http_response_code(403); exit('当前账户未分配此业务'); }
$selectedBusiness = ps_business_choice($actor, $requestedBusiness);
if (!$selectedBusiness && $_SERVER['REQUEST_METHOD'] === 'POST') { http_response_code(403); exit('当前账户未分配业务类型'); }
if ($departmentMode && !ps_department_import_allowed($actor, $selectedBusiness)) { http_response_code(403); exit('当前账户没有此部门业务的代录权限'); }
$departmentChoices = $departmentMode ? db()->query("SELECT id,name FROM employees WHERE department='网站售后部' ORDER BY name,id")->fetchAll() : [];
$ruleMonth = (string)($_POST['rule_month'] ?? $_GET['rule_month'] ?? $_SESSION['project_import_rule_month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ruleMonth)) $ruleMonth = date('Y-m');
$renewalRates = $departmentMode ? ps_department_renewal_rates($ruleMonth) : [];
$businessDefinition = $selectedBusiness ? ps_business_catalog()[$selectedBusiness] : null;
if (isset($_GET['download']) && $selectedBusiness && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="project-order-template.csv"; filename*=UTF-8' . chr(39) . chr(39) . rawurlencode($selectedBusiness . '-订单模板.csv'));
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    $templateHeaders = ps_business_import_headers($selectedBusiness, $actor);
    fputcsv($output, $templateHeaders);
    // 附一行示例（订单号以“示例”开头，上传时自动跳过），照着填即可
    fputcsv($output, ps_business_import_example_row($selectedBusiness, $templateHeaders));
    fclose($output);
    exit;
}
$error = '';
$businessDetectionNote = '';
$retryFileId = 0;
$imported = 0;
$skipped = 0;
$importReport = [];
$resultFileId = 0;
$preview = $_SESSION['project_import_preview'] ?? [];
$previewOwner = $_SESSION['project_import_actor'] ?? '';
$previewBusiness = $_SESSION['project_import_business'] ?? '';
$previewScope = $_SESSION['project_import_scope'] ?? 'personal';
$departmentDefaults = $departmentMode ? ($_SESSION['project_import_people'] ?? []) : [];
$actorKey = $actor['type'] . ':' . $actor['id'];
if ($previewOwner !== $actorKey || $previewBusiness !== $selectedBusiness || $previewScope !== $scope) $preview = [];
$domainTemplates = ps_intake_templates('domain');
$serverTemplates = ps_intake_templates('server');
$programTemplates = $selectedBusiness ? ps_intake_templates('program', $selectedBusiness) : [];
$usesProgram = $businessDefinition && !empty($businessDefinition['program']);
$resourceSelection = $businessDefinition && $businessDefinition['resources'] && $actor['role'] !== 'customer_service';

