<?php
$baseValidCount = count(array_filter($preview, function ($row) { return !empty($row['base_valid']); }));
// 已导入并审核过的、写的是他人的行：自动跳过，灰色显示，不算“需处理”
$isSkipRow = function ($row) { return empty($row['base_valid']) && in_array($row['status'] ?? '', ['已导入过', '他人订单'], true); };
$skipCounts = array_count_values(array_map(function ($row) { return $row['status']; }, array_filter($preview, $isSkipRow)));
$invalidCount = count($preview) - $baseValidCount - array_sum($skipCounts);
// 因填写问题无法识别的：按问题归类，弹窗告诉上传人怎么填、给示例和模板下载
$fixGuides = [];
if ($error !== '' && ($guide = ps_import_fix_guide($error, $selectedBusiness))) $fixGuides[$guide['key']] = $guide + ['lines' => []];
foreach ($preview as $previewRow) {
    if (!empty($previewRow['base_valid']) || $isSkipRow($previewRow)) continue;
    foreach (array_filter(explode('；', (string)$previewRow['error'])) as $part) {
        $guide = ps_import_fix_guide(preg_replace('/^同号第 \d+ 行：/u', '', $part), $selectedBusiness);
        if (!$guide) continue;
        $fixGuides[$guide['key']] = $fixGuides[$guide['key']] ?? $guide + ['lines' => []];
        $fixGuides[$guide['key']]['lines'][] = (int)$previewRow['line'] % 10000;
    }
}
$templateUrl = $selectedBusiness ? '?business=' . rawurlencode($selectedBusiness) . '&scope=' . ($departmentMode ? 'department' : 'personal') . '&download=1' : '';
$repairableCount = count(array_filter($preview, function ($row) use ($isSkipRow) { return !$isSkipRow($row) && (empty($row['order_no']) || empty($row['order_date']) || !empty($row['need_technical']) || !empty($row['can_join'])); }));
$siteKeyFixCount = count(array_filter($preview, function ($row) use ($isSkipRow) { return !$isSkipRow($row) && !empty($row['site_external_no']); }));
$suggestedDates = []; $lastDateBySheet = [];
foreach ($preview as $previewRow) {
    $sheetKey = (string)($previewRow['sheet'] ?? '');
    if (!empty($previewRow['order_date'])) $lastDateBySheet[$sheetKey] = $previewRow['order_date'];
    elseif (!empty($previewRow['suggested_date'])) $suggestedDates[(int)$previewRow['line']] = $previewRow['suggested_date'];
    elseif (isset($lastDateBySheet[$sheetKey])) $suggestedDates[(int)$previewRow['line']] = $lastDateBySheet[$sheetKey];
}
$page_title = $departmentMode ? '网站售后部门订单' : '导入项目订单';
// 备案原表一个文件两张工作表：当前业务导入完成后，自动接着导入同一文件里属于另一个备案业务的工作表（失败时保留页面上的“继续核对”按钮）
$chainedNote = '';
$chainSheets = (array)($_SESSION['project_import_other_sheets'] ?? []); // 核对那一遍记下的“同文件里属于另一个备案业务的工作表”
if (!empty($importReport) && $chainSheets && empty($GLOBALS['project_import_chain']) && empty($GLOBALS['project_import_api']) && ($actor['type'] ?? '') === 'employee' && (int)($resultFileId ?? 0) > 0) {
    require_once __DIR__ . '/../../includes/ProjectImportChain.php';
    $chainBusiness = reset($chainSheets);
    $GLOBALS['project_import_chain'] = true;
    try { [$chainImported, $chainError] = pic_chain_other_sheets((int)$resultFileId, $chainBusiness, $actor); } catch (Throwable $chainEx) { $chainImported = 0; $chainError = $chainEx->getMessage(); }
    unset($GLOBALS['project_import_chain']);
    if ($chainError === '') { $chainedNote = '同一个文件里的“' . implode('、', array_keys($chainSheets)) . '”表已自动按“' . $chainBusiness . '”导入 ' . (int)$chainImported . ' 单。'; $_SESSION['project_import_other_sheets'] = []; }
    else $chainedNote = '同一个文件里的“' . implode('、', array_keys($chainSheets)) . '”表没能自动导入（' . $chainError . '），请点下面的按钮继续核对“' . $chainBusiness . '”。';
}
