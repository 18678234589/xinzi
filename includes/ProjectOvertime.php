<?php
/**
 * 术语（仅限代码注释，不要出现在任何用户可见的页面、提示、规则说明里）：“延时服务”即通常所说的加班，“超时补贴”即加班费；overtime_* 命名沿用英文原意。
 *
 * 超时补贴：固定服务费 ÷ 30 × 延时服务天数 × 倍率。
 * 倍率：节假日当天延时服务 1.5 倍（元旦、除夕、春节初一 / 初二、清明、劳动节 5.1、端午、中秋、国庆 10.1 这几天），其余日期 1 倍。
 * 考勤表写法：实际出勤 / 满勤列写“26+2”= 出勤 26 天 + 延时服务 2 天（按 1 倍）；节假日延时服务在加号后用括号写日期，如“26+1(10.1)”“26+2(5.1,5.2)”，
 * 括号里是节假日的天数按 1.5 倍，其余天数按 1 倍；括号里也可写节日名（国庆、五一、春节、中秋……）。
 */

/** 默认节假日表（2026–2030）：春节取除夕、初一、初二（除夕即初一的前一天，腊月三十或廿九），其余为当天。 */
function ot_default_holidays()
{
    $moving = [
        2026 => ['春节除夕' => '02-16', '春节初一' => '02-17', '春节初二' => '02-18', '清明' => '04-05', '端午' => '06-19', '中秋' => '09-25'],
        2027 => ['春节除夕' => '02-05', '春节初一' => '02-06', '春节初二' => '02-07', '清明' => '04-05', '端午' => '06-09', '中秋' => '09-15'],
        2028 => ['春节除夕' => '01-25', '春节初一' => '01-26', '春节初二' => '01-27', '清明' => '04-04', '端午' => '05-28', '中秋' => '10-03'],
        2029 => ['春节除夕' => '02-12', '春节初一' => '02-13', '春节初二' => '02-14', '清明' => '04-04', '端午' => '06-16', '中秋' => '09-22'],
        2030 => ['春节除夕' => '02-02', '春节初一' => '02-03', '春节初二' => '02-04', '清明' => '04-05', '端午' => '06-05', '中秋' => '09-12'],
    ];
    $out = [];
    foreach ($moving as $year => $days) {
        $out[$year . '-01-01'] = '元旦';
        $out[$year . '-05-01'] = '劳动节';
        $out[$year . '-10-01'] = '国庆节';
        foreach ($days as $name => $md) $out[$year . '-' . $md] = $name;
    }
    return $out;
}

/** 某年的 1.5 倍节假日：['Y-m-d' => 名称]。默认表之外的年份可在系统设置 overtime_holidays 里补充（{"2031-02-22":"春节初一"}）。 */
function ot_holidays($year)
{
    $all = ot_default_holidays();
    if (function_exists('ps_setting_get')) {
        try {
            $extra = ps_setting_get('overtime_holidays', []);
            if (is_array($extra)) foreach ($extra as $date => $name) if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) $all[(string)$date] = (string)$name;
        } catch (Throwable $e) {
            // 未启用设置表时只用默认表
        }
    }
    return array_filter($all, function ($date) use ($year) { return strpos($date, sprintf('%04d-', $year)) === 0; }, ARRAY_FILTER_USE_KEY);
}

/** 节日名关键字 → 默认表里的名称前缀（括号里直接写节日名时用）。 */
function ot_holiday_keywords()
{
    return ['元旦' => '元旦', '除夕' => '春节除夕', '初一' => '春节初一', '初二' => '春节初二', '春节' => '春节', '清明' => '清明', '五一' => '劳动节', '劳动' => '劳动节',
        '端午' => '端午', '中秋' => '中秋', '国庆' => '国庆节', '十一' => '国庆节'];
}

/**
 * 解析考勤单元格里加号后面的部分（如 "2"、"1(10.1)"、"2(5.1,5.2)"、"(国庆)"）。
 * 返回 ['normal' => 1 倍天数, 'holiday' => 1.5 倍天数, 'dates' => [括号里识别到的节假日名称]]。
 */
function ot_parse_extra($text, $year, $month)
{
    $text = strtr(trim((string)$text), ['（' => '(', '）' => ')', '【' => '(', '】' => ')', '[' => '(', ']' => ')', '，' => ',', '、' => ',', '；' => ',', ';' => ',', '＋' => '+', '．' => '.', '。' => '.']);
    $tokens = [];
    $rest = preg_replace_callback('/\(([^)]*)\)/u', function ($m) use (&$tokens) {
        foreach (preg_split('/[,\s]+/u', $m[1], -1, PREG_SPLIT_NO_EMPTY) as $t) $tokens[] = $t;
        return '';
    }, $text);
    $days = 0.0;
    foreach (explode('+', $rest) as $part) {
        $part = preg_replace('/[^\d.]/', '', $part);
        if ($part !== '' && is_numeric($part)) $days += (float)$part;
    }
    $holidays = ot_holidays($year);
    $keywords = ot_holiday_keywords();
    $hit = [];      // 已识别的节假日（同一天只算一次）
    $listed = 0;    // 括号里列出的天数
    $seen = [];
    foreach ($tokens as $token) {
        $key = null; $name = null;
        if (preg_match('/^(\d{1,2})[.\-\/月](\d{1,2})日?$/u', $token, $m)) {
            $key = sprintf('%04d-%02d-%02d', $year, $m[1], $m[2]);
            $name = $holidays[$key] ?? null;
        } elseif (preg_match('/^(\d{1,2})日$/u', $token, $m)) {
            $key = sprintf('%04d-%02d-%02d', $year, $month, $m[1]);
            $name = $holidays[$key] ?? null;
        } else {
            foreach ($keywords as $word => $prefix) {
                if (mb_strpos($token, $word) === false) continue;
                $key = 'name:' . $prefix;
                foreach ($holidays as $date => $holidayName) if (strpos($holidayName, $prefix) === 0) { $name = $holidayName; break; }
                $name = $name ?? $prefix;
                break;
            }
        }
        if ($key === null || isset($seen[$key])) continue;
        $seen[$key] = true;
        $listed++;
        if ($name !== null) $hit[] = $name;
    }
    $days = max($days, (float)$listed); // 括号里列了几天，至少算几天
    $holidayDays = min($days, (float)count($hit));
    return ['normal' => round($days - $holidayDays, 2), 'holiday' => round($holidayDays, 2), 'dates' => $hit];
}

/** 拆分考勤单元格：“26+2(10.1)” → [出勤天数文本 “26”, 延时服务部分 “2(10.1)”]；没有加号时延时服务部分为空。 */
function ot_split_cell($cell)
{
    $cell = (string)$cell;
    $pos = strpos(strtr($cell, ['＋' => '+']), '+');
    if ($pos === false) return [$cell, ''];
    $cell = strtr($cell, ['＋' => '+']);
    return [substr($cell, 0, $pos), substr($cell, $pos + 1)];
}

/** 超时补贴：固定服务费 ÷ 30 × (1 倍天数 × 1 + 节假日天数 × 1.5)。返回 [金额, 说明]。 */
function ot_pay($base, $normalDays, $holidayDays, $normalRate = 1.0, $holidayRate = 1.5)
{
    $fmt = function ($n) { return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); };
    $parts = [];
    if ($holidayDays > 0) $parts[] = '节假日 ' . $fmt($holidayDays) . ' 天 × ' . $fmt($holidayRate);
    if ($normalDays > 0) $parts[] = '其他日期 ' . $fmt($normalDays) . ' 天 × ' . $fmt($normalRate);
    $amount = round((float)$base / 30 * ($normalDays * $normalRate + $holidayDays * $holidayRate), 2);
    return [$amount, '固定服务费 ¥' . $fmt($base) . ' ÷ 30 × (' . implode(' + ', $parts) . ')'];
}
