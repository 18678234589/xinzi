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
$repairableCount = count(array_filter($preview, function ($row) use ($isSkipRow) { return !$isSkipRow($row) && (empty($row['order_no']) || empty($row['order_date']) || !empty($row['need_technical'])); }));
$siteKeyFixCount = count(array_filter($preview, function ($row) use ($isSkipRow) { return !$isSkipRow($row) && !empty($row['site_external_no']); }));
$suggestedDates = []; $lastDateBySheet = [];
foreach ($preview as $previewRow) {
    $sheetKey = (string)($previewRow['sheet'] ?? '');
    if (!empty($previewRow['order_date'])) $lastDateBySheet[$sheetKey] = $previewRow['order_date'];
    elseif (!empty($previewRow['suggested_date'])) $suggestedDates[(int)$previewRow['line']] = $previewRow['suggested_date'];
    elseif (isset($lastDateBySheet[$sheetKey])) $suggestedDates[(int)$previewRow['line']] = $lastDateBySheet[$sheetKey];
}
$page_title = $departmentMode ? '网站售后部门订单' : '导入项目订单';
