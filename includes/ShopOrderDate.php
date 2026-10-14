<?php
/**
 * 店铺订单 / 部门订单表格里的“时间”单元格各种写法 → 日期（+ 时间）。
 * 常见写法：Excel 序列号（46237.61）、2026-08-03 14:42:40、2026-8-7 10:00（月日不补零）、2026/7/1 934（时间写成 934 = 9:34）、
 * 2026年8月7日、2026.8.7、紧凑 20260703093827 / 20260810 / 2026081012:21:30（日期和时间连在一起）。
 * 认不出返回 null——调用方不能悄悄改用“上传时选的月份”，必须提示（否则订单会记错月份，月度合计对不上）。
 */
function sod_parse($value)
{
    $v = trim(str_replace(["\xC2\xA0", '　', "\xEF\xBB\xBF"], ' ', (string)$value));
    if ($v === '') return null;
    $make = function ($y, $mo, $d, $h = 0, $mi = 0, $s = 0) {
        $y = (int)$y; $mo = (int)$mo; $d = (int)$d;
        if ($y < 2000 || $y > 2100 || !checkdate($mo, $d, $y)) return null;
        $h = (int)$h; $mi = (int)$mi; $s = (int)$s;
        if ($h > 23 || $mi > 59 || $s > 59) { $h = $mi = $s = 0; }
        return ['date' => sprintf('%04d-%02d-%02d', $y, $mo, $d), 'time' => sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $s)];
    };
    // 纯数字紧凑写法：YYYYMMDD / YYYYMMDDHHMM / YYYYMMDDHHMMSS
    if (preg_match('/^(20\d{2})(\d{2})(\d{2})(?:(\d{2})(\d{2})(\d{2})?)?$/', $v, $m)) return $make($m[1], $m[2], $m[3], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0);
    // Excel 序列号（含时间小数）
    if (is_numeric($v) && (float)$v > 30000 && (float)$v < 80000) {
        $ts = ((int)floor((float)$v) - 25569) * 86400 + (int)round(((float)$v - floor((float)$v)) * 86400);
        return $make(gmdate('Y', $ts), gmdate('n', $ts), gmdate('j', $ts), gmdate('G', $ts), gmdate('i', $ts), gmdate('s', $ts));
    }
    // 日期和时间连在一起：2026081012:21:30
    if (preg_match('/^(20\d{2})(\d{2})(\d{2})(\d{1,2})[:：](\d{2})(?:[:：](\d{2}))?$/', $v, $m)) return $make($m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? 0);
    // 带分隔符：月 / 日可不补零；时间可写 14:42:40、14:42、934（= 9:34）、1534
    if (preg_match('/^(\d{4})\s*[-\/.年]\s*(\d{1,2})\s*[-\/.月]\s*(\d{1,2})日?(?:[\sT]+(\d{1,2})[:：]?(\d{2})(?:[:：](\d{2}))?)?/u', $v, $m)) return $make($m[1], $m[2], $m[3], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0);
    return null;
}
