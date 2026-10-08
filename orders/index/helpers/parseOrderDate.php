<?php
function parseOrderDate($raw) {
    $raw = trim($raw);
    if ($raw === '') return [false, '日期为空'];
    // 纯数字：可能是 Excel 日期序列号（1900/1904 基准）
    if (ctype_digit($raw)) {
        $num = (int)$raw;
        if ($num >= 1 && $num <= 2958465) {
            $ts = ($num - 25569) * 86400;
            $date = gmdate('Y-m-d', $ts);
            if ($date && $date > '1900-01-01') return [$date, ''];
        }
        return [false, '日期格式错误(' . $raw . ')'];
    }
    $raw = str_replace('/', '-', $raw);
    // 处理 "2026-06-05 00:00:00" 这类带时间的字符串
    $raw = preg_replace('/\s+\d{2}:\d{2}(:\d{2})?$/', '', $raw);
    $raw = trim($raw);

    // 补全年份：处理 "5.25" / "5-25" / "05-25" 这类只有月日的格式
    if (preg_match('/^(\d{1,2})[.\-](\d{1,2})$/', $raw, $m)) {
        $raw = date('Y') . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
    }
    // 处理 "24.2.28" / "2024.2.28" 这类点分格式
    if (preg_match('/^(\d{2,4})[.](\d{1,2})[.](\d{1,2})$/', $raw, $m)) {
        $year = strlen($m[1]) === 2 ? '20' . $m[1] : $m[1];
        $raw = $year . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[3], 2, '0', STR_PAD_LEFT);
    }

    $ts = strtotime($raw);
    if ($ts === false) return [false, '日期格式错误(' . $raw . ')'];

    return [date('Y-m-d', $ts), ''];
}
