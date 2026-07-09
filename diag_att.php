<?php
require_once __DIR__ . '/classes/SimpleXLSX.php';
$file = 'C:\\Users\\Administrator\\Desktop\\2026.6月考勤表.xlsx';
$rows = SimpleXLSX::parse($file);
if (!$rows) { die('解析失败'); }

// 模拟表头定位
$headerRowIdx = 0;
$idxName = null;
foreach ($rows as $ri => $row) {
    $rowText = implode(' ', array_filter($row, function($v){ return trim($v) !== ''; }));
    if (mb_strpos($rowText, '姓名') !== false) {
        $headerRowIdx = $ri;
        $firstRow = $row;
        foreach ($row as $ci => $cv) {
            $cv = trim($cv);
            if ($cv === '') continue;
            if (mb_strpos($cv, '姓名') !== false) $idxName = $ci;
        }
        break;
    }
}
echo "表头行: 第" . ($headerRowIdx+1) . "行, 姓名列: $idxName\n";

// 日期行
$dateRow = $rows[$headerRowIdx + 1];
$namedCols = [];
foreach ($firstRow as $ci => $cv) { if (trim($cv) !== '') $namedCols[] = $ci; }
$maxNamed = max($namedCols);
$dayColStart = $maxNamed + 1;
$dayColCount = count($firstRow) - $dayColStart;
echo "打卡列: 第$dayColStart 至 " . ($dayColStart+$dayColCount-1) . " 共$dayColCount 列\n";

// 节假日
$holidayCols = [];
for ($ci = $dayColStart; $ci < count($dateRow); $ci++) {
    $dv = trim($dateRow[$ci] ?? '');
    if (in_array($dv, ['六','日','端午节','春节','国庆','中秋','元旦','清明','劳动','五一','十一'], true)) {
        $holidayCols[$ci] = true;
    }
}
$workDayCount = $dayColCount - count($holidayCols);
echo "节假日列: " . implode(',', array_keys($holidayCols)) . " 共" . count($holidayCols) . "天\n";
echo "应出勤工作日: $workDayCount 天 = " . ($workDayCount*8) . " 小时\n\n";

// 跳过日期行，取数据
$dataRows = array_slice($rows, $headerRowIdx + 1);
// 检测并跳过日期行
if (!empty($dataRows)) {
    $firstData = $dataRows[0];
    $nameCell = trim($firstData[$idxName] ?? '');
    $numCount = 0; $nonEmptyCount = 0;
    for ($ci = 0; $ci < count($firstData); $ci++) {
        $v = trim($firstData[$ci] ?? '');
        if ($v === '') continue;
        $nonEmptyCount++;
        if (preg_match('/^\d{1,2}$/', $v) || in_array($v, ['六','日','端午节'], true)) $numCount++;
    }
    if ($nameCell === '' && $nonEmptyCount > 0 && $numCount / $nonEmptyCount > 0.5) {
        array_shift($dataRows);
        echo "已跳过日期行\n\n";
    }
}
// 过滤空行
$dataRows = array_values(array_filter($dataRows, function($r) {
    return count(array_filter($r, function($v){ return trim($v) !== ''; })) > 0;
}));

echo "数据行数: " . count($dataRows) . "\n\n";
echo "员工 | 满勤天数 | 实际出勤 | 请假h | work_hours | absent_hours\n";
echo str_repeat('-', 70) . "\n";
foreach (array_slice($dataRows, 0, 10) as $r) {
    $empName = trim($r[$idxName] ?? '');
    if ($empName === '') continue;
    $actDays = 0;
    for ($di = $dayColStart; $di < $dayColStart + $dayColCount; $di++) {
        if (isset($holidayCols[$di])) continue;
        $v = trim($r[$di] ?? '');
        if ($v !== '' && $v !== '-' && $v !== '无' && mb_strpos($v, '请假') === false && mb_strpos($v, '缺勤') === false) {
            $actDays++;
        }
    }
    $fullDays = $workDayCount;
    $wh = $fullDays * 8;
    $ah = max(0, ($fullDays - $actDays) * 8);
    // 反算显示
    $showFull = $wh / 8;
    $showAct = ($wh - $ah) / 8;
    echo "$empName | $fullDays | $actDays | $ah | $wh | $ah (显示: 满{$showFull} 实{$showAct})\n";
}
